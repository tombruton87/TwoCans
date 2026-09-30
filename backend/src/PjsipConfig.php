<?php
declare(strict_types=1);

/**
 * Generates the Asterisk config for registered devices.
 *
 * The whole file is rewritten from the database on every change rather than
 * patched per device — regenerating cannot leave an orphaned endpoint behind
 * when a phone is deleted, and the database stays the single source of truth.
 *
 * Writes into ASTERISK_GENERATED_DIR, which is bind-mounted into both this
 * container and Asterisk's /etc/asterisk.
 */
final class PjsipConfig
{
    private string $dir;

    public function __construct(private DeviceRepository $devices = new DeviceRepository())
    {
        $this->dir = rtrim(getenv('ASTERISK_GENERATED_DIR') ?: '/etc/asterisk/generated', '/');
    }

    /** SIP domain a phone should register to — the host's LAN address. */
    public static function domain(): string
    {
        return getenv('SIP_DOMAIN') ?: '127.0.0.1';
    }

    /**
     * The port handsets register to.
     *
     * Configurable because the trunk and the handsets cannot share one, and
     * which of them gets 5060 depends on the house: a router that will only
     * forward the standard port wants the trunk there, with the handsets moved
     * aside onto a port that never leaves the LAN.
     */
    public static function handsetPort(string $transport = 'udp'): int
    {
        return $transport === 'tls'
            ? self::configuredPort('SIP_TLS_PORT', 'sip_tls_port', 5061)
            : self::configuredPort('SIP_PORT', 'sip_port', 5060);
    }

    /**
     * A port from the environment, else the database, else the default.
     *
     * The environment wins because that is what compose publishes from, but it
     * only reaches containers compose started — the stored value is what keeps
     * a regeneration from anywhere else writing the wrong port.
     */
    private static function configuredPort(string $env, string $setting, int $default): int
    {
        return self::choosePort([getenv($env) ?: '', (new SettingsRepository())->all()[$setting] ?? ''], $default);
    }

    /**
     * The first usable port among the candidates, in order, else the default.
     * Pure, so the rule can be tested without the environment or database.
     *
     * @param array<int,string|int> $candidates
     */
    public static function choosePort(array $candidates, int $default): int
    {
        foreach ($candidates as $candidate) {
            $port = (int) $candidate;
            if ($port > 0 && $port < 65536) {
                return $port;
            }
        }

        return $default;
    }

    public static function port(string $transport): int
    {
        return self::handsetPort($transport);
    }

    /** What the parent types into Linphone's "Registrar URI" / proxy fields. */
    public static function registrarUri(string $transport): string
    {
        $scheme = $transport === 'tls' ? 'sips' : 'sip';

        return $scheme . ':' . self::domain() . ':' . self::port($transport)
             . ';transport=' . $transport;
    }

    /**
     * Regenerate config from the database and ask Asterisk to load it.
     *
     * @return array{written:int,reloaded:bool,error:?string}
     */
    /**
     * The generated config, file name => contents, without writing anything
     * or touching Asterisk — what apply() writes, and what the dialplan tests
     * read (tests/DialplanStructureTest.php).
     *
     * @return array<string,string>
     */
    public function render(): array
    {
        $rows = $this->devices->all();
        // Read first: the transports need to know whether a trunk is connected,
        // because that adds a second, public-facing one.
        $trunk = (new TrunkRepository())->get();

        $files = [
            'pjsip-transports.conf' => $this->renderTransports($trunk),
            'pjsip-devices.conf' => $this->renderEndpoints($rows),
            'dialplan-devices.conf' => $this->renderDialplan($rows),
            // The household's own hold music, if any — see HoldMusic.
            'musiconhold-custom.conf' => (new HoldMusic())->render(),
        ];
        // The SIP trunk is optional: only written once a line is connected.
        if ($trunk['connected'] && $trunk['sipHost'] !== '') {
            $files['pjsip-trunk.conf'] = $this->renderTrunkEndpoint($trunk);
            $files['dialplan-trunk.conf'] = $this->renderTrunkDialplan($trunk);
        }

        return $files;
    }

    public function apply(): array
    {
        $rows = $this->devices->all();
        $files = $this->render();

        $written = $this->writeVoicemailConf($rows);
        foreach ($files as $name => $contents) {
            $written += $this->write($name, $contents);
        }

        if (!isset($files['pjsip-trunk.conf'])) {
            // Drop any stale trunk config; the placeholder files keep the
            // wildcard #include happy (see pjsip.conf / extensions.conf).
            @unlink($this->dir . '/pjsip-trunk.conf');
            @unlink($this->dir . '/dialplan-trunk.conf');
        }

        try {
            $ami = new Ami();
            $ami->connect();
            $reloaded = $ami->reloadPjsip() && $ami->reloadDialplan();
            // Mailboxes live in voicemail.conf, which is its own module.
            $ami->send('Reload', ['Module' => 'app_voicemail']);
            // Hold music is its own module too.
            $ami->send('Command', ['Command' => 'moh reload']);
            $ami->disconnect();

            return ['written' => $written, 'reloaded' => $reloaded, 'error' => null];
        } catch (Throwable $e) {
            // Config is on disk either way; it will load on the next restart.
            return ['written' => $written, 'reloaded' => false, 'error' => $e->getMessage()];
        }
    }

    /** Live registration state, pushed back into the database. */
    public function syncRegistrations(): bool
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $this->devices->syncRegistration($ami->registeredEndpoints());
            $ami->disconnect();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function write(string $filename, string $contents): int
    {
        $path = $this->dir . '/' . $filename;

        // Write-then-rename so Asterisk never reads a half-written file.
        $temp = $path . '.tmp';
        if (@file_put_contents($temp, $contents) === false) {
            return 0;
        }
        @chmod($temp, 0644);

        return @rename($temp, $path) ? 1 : 0;
    }

    /**
     * SIP transports.
     *
     * Generated rather than checked in, because they carry this machine's LAN
     * address. Asterisk runs on a Docker bridge but phones reach it on the host
     * address, so without external_*_address it advertises its container IP in
     * SDP and Contact headers and audio goes nowhere.
     *
     * Changing these needs an Asterisk restart — transports are not reloadable.
     */
    private function renderTransports(array $trunk): string
    {
        $lan = self::domain();

        // Home phones reach the box directly, so the transports advertise the
        // LAN address.
        $transports = [
            ['transport-udp', 'udp', self::handsetPort('udp'), $lan],
            ['transport-tcp', 'tcp', self::handsetPort('udp'), $lan],
        ];

        // The provider is on the far side of the router, so its transport
        // advertises the public address instead. Only added once a line is
        // connected and we know what that address is.
        $public = self::trunkPublicHost();
        if ($trunk['connected'] && $public !== '') {
            $transports[] = [self::TRUNK_TRANSPORT, 'udp', self::trunkPort(), $public];
        }

        $out = $this->header('SIP transports');
        foreach ($transports as [$name, $protocol, $port, $advertised]) {
            $out .= "\n[{$name}]\n";
            $out .= "type = transport\n";
            $out .= "protocol = {$protocol}\n";
            $out .= "bind = 0.0.0.0:{$port}\n";
            $out .= "local_net = 172.16.0.0/12\n";
            $out .= "external_media_address = {$advertised}\n";
            $out .= "external_signaling_address = {$advertised}\n";
        }

        return $out;
    }

    /**
     * The address the provider should reach this box on.
     *
     * Prefers whatever the household set by hand, then the dynamic DNS name
     * (which follows the line when the ISP changes its address), and finally
     * the address dynamic DNS last recorded. Deliberately reads the stored
     * address rather than asking the internet, because config is regenerated
     * every time a phone changes and that must not depend on a working WAN.
     */
    public static function trunkPublicHost(): string
    {
        $set = trim((new SettingsRepository())->all()['trunk_public_host'] ?? '');
        if ($set !== '') {
            return $set;
        }

        $dns = (new DynamicDnsRepository())->get();
        if ($dns['configured'] && $dns['hostname'] !== '') {
            return (string) $dns['hostname'];
        }

        return (string) ($dns['ip'] ?? '');
    }

    private function renderEndpoints(array $rows): string
    {
        $out = $this->header('PJSIP endpoints');

        $hasOwnMusic = (new HoldMusic())->hasOwn();
        foreach ($rows as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;                       // ATAs are not wired up yet
            }

            $name = $d['sipUsername'];
            $transport = 'transport-' . $d['transport'];
            $vmContext = self::VOICEMAIL_CONTEXT;   // heredocs can't call self::
            $callerid = $this->quote($d['name']) . ' <' . $d['extension'] . '>';

            // A phone with one of the line's numbers of its own calls out from
            // it — see callerIdNumber(). Nothing set means the main number.
            $outNumber = (new TrunkRepository())->outgoingNumberFor((int) $d['id']);
            $outVar = $outNumber === null ? '' : "\n; Calls out from its own number on the line.\nset_var = " . self::OUT_NUMBER_VAR . "={$outNumber}";
            // Who it is, and its call limits in seconds — see renderLimitsContext().
            $outVar .= "\nset_var = TC_DEV={$d['id']}";
            if ($d['announceCaller']) {
                // Says who's calling when picked up — see ANSWERED_CONTEXT.
                $outVar .= "\nset_var = TC_ANNOUNCE=1";
            }
            if ($d['adult']) {
                // Adult mode: no limits, no switches, no rules — see migration 041.
                $outVar .= "\n; ADULT MODE — no restrictions apply to this phone.\nset_var = TC_ADULT=1";
            } else {
                if ($d['maxCallMinutes'] !== null) {
                    $outVar .= "\nset_var = TC_MAXCALL=" . ($d['maxCallMinutes'] * 60);
                }
                if ($d['dailyMinutes'] !== null) {
                    $outVar .= "\nset_var = TC_DAILY=" . ($d['dailyMinutes'] * 60);
                }
                if (!$d['allowOut']) {
                    $outVar .= "\nset_var = TC_NOOUT=1";
                }
            }

            // A Grandstream challenges the NOTIFY that tells it to fetch its
            // settings or restart (see Ami::resync) with its own account, so
            // answer with the same one. An app is never sent one.
            // Someone this phone puts on hold hears the household's own music,
            // once there is some — see HoldMusic.
            if ($hasOwnMusic) {
                $outVar .= "\nmoh_suggest = " . HoldMusic::CLASS_NAME;
            }
            if ($d['family'] !== 'app') {
                $outVar .= "\n; Answers the phone's challenge when told to fetch its settings.\noutbound_auth = {$name}-auth";
            }

            $out .= <<<CONF

            ;--- {$d['name']} (extension {$d['extension']})
            [{$name}]
            type = endpoint
            context = twocans-devices
            transport = {$transport}
            aors = {$name}
            auth = {$name}-auth
            callerid = {$callerid}{$outVar}
            ; Message-waiting indication: Asterisk NOTIFYs the phone when a
            ; message arrives, so Linphone shows it without the app polling.
            mailboxes = {$d['extension']}@{$vmContext}
            disallow = all
            allow = opus
            allow = ulaw
            allow = alaw
            ; Media flows through Asterisk so calls can be recorded later.
            direct_media = no
            ; A phone on wifi behind NAT: trust where packets actually came from.
            rtp_symmetric = yes
            force_rport = yes
            rewrite_contact = yes

            [{$name}-auth]
            type = auth
            auth_type = userpass
            username = {$name}
            password = {$d['sipSecret']}

            [{$name}]
            type = aor
            ; Two contacts, not one: a phone that re-registers on a new source
            ; port (which mobiles do constantly) can land the new registration
            ; before the old one expires, instead of leaving a gap where the
            ; device looks offline.
            max_contacts = 2
            remove_existing = yes
            ; A phone dozing on wifi is slow to answer, not gone. The default
            ; 3s timeout marks it Unavailable after one missed probe — we have
            ; measured round trips near 1s on a healthy handset, so be patient
            ; and probe less often to save its battery.
            qualify_frequency = 60
            qualify_timeout = 10

            CONF;
        }

        return $this->dedent($out);
    }

    /**
     * Test calls placed from the web interface arrive from this number, so a
     * child (and the call log) can tell them apart from a real caller.
     */
    /**
     * Twilio's Elastic SIP Trunking signalling gateways, per
     * https://www.twilio.com/docs/sip-trunking/ip-addresses
     *
     * Inbound calls arrive from these addresses. Twilio does not support
     * REGISTER and sends no credentials, so trusting the source IP is the only
     * way to recognise the trunk: without a matching `identify` section PJSIP
     * cannot tell which endpoint an inbound INVITE belongs to and rejects it
     * before the dialplan is ever reached.
     */
    public const TWILIO_SIGNALLING_NETS = [
        '54.172.60.0/30',     // North America — Virginia
        '54.244.51.0/30',     // North America — Oregon
        '54.171.127.192/30',  // Europe — Ireland
        '35.156.191.128/30',  // Europe — Frankfurt
        '54.65.63.192/30',    // Asia-Pacific — Tokyo
        '54.169.127.128/30',  // Asia-Pacific — Singapore
        '54.252.254.64/30',   // Asia-Pacific — Sydney
        '177.71.206.192/30',  // South America — São Paulo
    ];

    /**
     * The trunk gets its own transport on its own port.
     *
     * A transport advertises exactly one address, and the two sides of this box
     * need different ones: handsets must be told the LAN address, the provider
     * must be told the public one. Two transports cannot share 0.0.0.0:5060, so
     * the trunk listens on its own port — which also keeps 5060 off the public
     * internet, where it would be scanned around the clock.
     */
    public const TRUNK_TRANSPORT = 'transport-trunk';
    public const TRUNK_PORT_DEFAULT = 5062;

    /** Two transports cannot share a port, and silence is the worst way to find out. */
    public static function portClash(): bool
    {
        return self::trunkPort() === self::handsetPort('udp')
            || self::trunkPort() === self::handsetPort('tls');
    }

    /**
     * The port the provider reaches us on.
     *
     * Configurable because it has to be a port the household's router will
     * actually forward, and some will not forward an arbitrary one. Asterisk
     * puts this number in its Via and Contact headers, so the router must map
     * it straight through — same port outside and in — and the provider's
     * origination URI has to name it too.
     */
    public static function trunkPort(): int
    {
        return self::configuredPort('TRUNK_SIP_PORT', 'trunk_sip_port', self::TRUNK_PORT_DEFAULT);
    }

    public const TEST_CALLER_NUMBER = '929';
    public const TEST_CALLER_NAME = 'twocans test';

    /** Where recordings made from a handset are kept. */
    public const GREETING = '/var/lib/asterisk/sounds/twocans/greeting';

    /**
     * Recording filename is the call's Asterisk uniqueid, which is also the key
     * the call log is imported under — so a record and its audio can be matched
     * up without storing a path anywhere in between.
     */
    public const RECORDING_FORMAT = 'wav';

    /**
     * Played when a child dials a number that isn't allowed, just before the
     * phone asks who they were trying to reach.
     *
     * A stock Asterisk prompt, and the only refusal left in the stock voice: it
     * is the child who hears it, so a parent's recording — the one RefusalStore
     * holds, which is for callers who aren't on the list — would be the wrong
     * person talking.
     */
    public const BLOCKED_MESSAGE = 'invalid';

    /**
     * "Who were you trying to call?" — a stock Asterisk prompt until there is a
     * text-to-speech voice to say something warmer.
     */
    public const ASK_PROMPT = 'vm-rec-name';

    /** Where those recordings land, shared with the php container. */
    public const ASK_SPOOL = '/var/spool/asterisk/asks';
    public const WINDOW_MESSAGE = 'vm-nobodyavail';
    public const NO_LINE_MESSAGE = 'vm-nobodyavail';

    /**
     * The key a caller presses to duck out of a "we can't take your call"
     * message and hear a joke instead.
     *
     * 5 because it sits under a thumb, nothing else on the line waits for a
     * digit, and the joke line has no menu of its own to collide with — it
     * answers, tells a joke and rings off.
     */
    public const JOKE_KEY = '5';

    /**
     * Seconds the line keeps listening after such a message has finished.
     *
     * Long enough to react to "press 5 for a joke", short enough that a caller
     * who is not going to press anything is not left on a silent line
     * wondering whether the call connected.
     */
    private const JOKE_KEY_SECONDS = 5;

    /** Asterisk voicemail context, and the number for picking messages up. */
    public const VOICEMAIL_CONTEXT = 'twocans';
    public const VOICEMAIL_NUMBER = '700';

    /**
     * The context the service numbers answer in — 600 echo, 500 record, the
     * joke line. The endpoints' own `context =` line spells it out because a
     * heredoc cannot call self::; this is the same context.
     */
    public const DEVICES_CONTEXT = 'twocans-devices';

    /** The context the jokes are indexed in. The number itself is a setting. */
    public const JOKE_CONTEXT = 'twocans-jokes';

    /** Where the joke line answers — chosen by the household, 258 by default. */
    public static function jokeNumber(): string
    {
        return (new SettingsRepository())->jokeNumber();
    }

    /** Group calls: where members are originated to, and the room they meet in. */
    public const CONF_CONTEXT = 'twocans-conf';
    public const CONF_ROOM_PREFIX = 'twocans-grp-';

    /** Where a phone with outgoing calls switched off is turned away. */
    public const NO_OUT_CONTEXT = 'twocans-noout';

    /**
     * The first steps of every outgoing call to someone outside the house.
     *
     * A phone with its Outgoing switch off (TC_NOOUT on its endpoint) is turned
     * away. A phone in adult mode (TC_ADULT) jumps straight to $label, past
     * bedtime and the contact's hours — see migration 041.
     */
    public static function renderPhoneGates(string $label): string
    {
        $out = " same => n,GotoIf(\$[\"\${TC_NOOUT}\" = \"1\"]?" . self::NO_OUT_CONTEXT . ",s,1)\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_ADULT}\" = \"1\"]?{$label})\n";

        return $out;
    }

    /**
     * Start recording — unless this phone is in adult mode, whose calls are
     * a grown-up's own and are never recorded or transcribed (migration 041).
     */
    public static function renderRecord(string $options = 'b'): string
    {
        $file = '${UNIQUEID}.' . self::RECORDING_FORMAT . ($options !== '' ? ',' . $options : '');

        return " same => n,ExecIf(\$[\"\${TC_ADULT}\" != \"1\"]?MixMonitor({$file}))\n";
    }

    /** Outgoing calls switched off on this phone: say so, and stop. */
    private function renderNoOutContext(): string
    {
        $out = "\n[" . self::NO_OUT_CONTEXT . "]\n";
        $out .= "; The phone's own Outgoing calls switch is off. Emergency numbers, the\n";
        $out .= "; house's own phones and the service numbers never come through here.\n";
        $out .= "exten => s,1,NoOp(twocans: outgoing calls are off on this phone)\n";
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(" . (new Greetings())->prompt('not_allowed') . ")\n";
        $out .= " same => n,Hangup()\n";

        return $out;
    }

    /** Announcements page the phones through here — see AnnouncementRepository. */
    public const PAGE_CONTEXT = 'twocans-page';

    /**
     * One extension per phone and way of paging it: a<id> asks the phone to
     * answer by itself, r<id> just rings it. The announcement's recording is
     * played by the other side of the Local channel once the phone picks up.
     *
     * Auto-answer is a request, not a command. Desk phones (Grandstream,
     * Yealink, Polycom) honour Call-Info answer-after=0 or Alert-Info
     * auto-answer when intercom is allowed on them; a phone app that
     * ignores both simply rings, and the message plays when it's answered.
     *
     * Paging goes around bedtime, the phone's hours and its call limits on
     * purpose: it is a grown-up talking to the house, not a call.
     */
    private function renderPageContext(array $rows): string
    {
        $out = "\n[" . self::PAGE_CONTEXT . "]\n";
        $any = false;
        foreach ($rows as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;
            }
            $any = true;
            $out .= "exten => a{$d['id']},1,Dial(PJSIP/{$d['sipUsername']},30,b(" . self::PAGE_CONTEXT . "^intercom^1))\n";
            $out .= " same => n,Hangup()\n";
            $out .= "exten => r{$d['id']},1,Dial(PJSIP/{$d['sipUsername']},30)\n";
            $out .= " same => n,Hangup()\n";
        }
        if (!$any) {
            $out .= "; (no phones to page yet)\n";
        }

        // Run on the phone's leg before it rings (Dial's b()).
        $out .= "exten => intercom,1,Set(PJSIP_HEADER(add,Call-Info)=<sip:twocans>\\;answer-after=0)\n";
        $out .= " same => n,Set(PJSIP_HEADER(add,Alert-Info)=<http://twocans>\\;info=alert-autoanswer\\;delay=0)\n";
        $out .= " same => n,Return()\n";

        return $out;
    }

    /** AstDB family where emergency calls are noted for the notifier. */
    public const EMERGENCY_FAMILY = 'tc_emergency';

    /** Where each leg of a group call is tagged before it rings. */
    public const MEMBER_CONTEXT = 'twocans-member';

    /**
     * Tag a group call's leg: tcmember:<contact>:<child's uniqueid>. Run on
     * the new leg before it rings (Originate's b()), so even a leg nobody
     * answers writes a CDR that says whose it was.
     */
    private function renderMemberContext(): string
    {
        $out = "\n[" . self::MEMBER_CONTEXT . "]\n";
        $out .= "exten => s,1,Set(TC_MEMBERTAG=tcmember:\${ARG1}:\${ARG2})\n";
        $out .= " same => n,Set(CDR(userfield)=\${TC_MEMBERTAG})\n";
        $out .= " same => n,Return()\n";

        return $out;
    }

    /** Call limits: time allowances, and the running count of today's talk. */
    public const LIMITS_CONTEXT = 'twocans-limits';

    /** Run on a phone the moment it answers an incoming call (Dial's U()). */
    public const ANSWERED_CONTEXT = 'twocans-answered';

    /**
     * Before an outside call from a phone: work out its allowance, or stop the
     * call if today's is spent. Leaves TC_DIALOPTS for Dial and TC_TIMEOUT for
     * anything that isn't a Dial — see renderLimitsContext().
     */
    public static function renderLimitCheck(): string
    {
        $out = " same => n,Gosub(" . self::LIMITS_CONTEXT . ",out,1)\n";
        $out .= " same => n,GotoIf(\$[\"\${GOSUB_RETVAL}\" = \"spent\"]?spent)\n";

        return $out;
    }

    /** Where renderLimitCheck() sends a phone whose day is used up. */
    public static function renderSpentBranch(string $prompt): string
    {
        $out = " same => n(spent),NoOp(today's phone time is used up)\n";
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback({$prompt})\n";
        $out .= " same => n,Hangup()\n";

        return $out;
    }

    /**
     * The limits themselves, kept by Asterisk so they hold with the app down.
     *
     * Each phone's endpoint carries TC_DEV (its id), TC_MAXCALL and TC_DAILY
     * (seconds; unset is no limit). Today's talk is counted in AstDB under
     * tc_used/<phone>/<date>, added to by a hangup handler at the end of every
     * call the phone makes or answers.
     *
     *   out  — on a phone calling out. Returns "spent" when today is used up;
     *          otherwise sets TC_DIALOPTS (Dial's L() option: cut off at the
     *          allowance, beep a minute before) and TC_TIMEOUT (seconds).
     *   in   — on a phone that has just answered (Dial's U() option). Caps the
     *          call with an absolute timeout, unless the caller is SOS or
     *          always put through (ARG1 is those two flags).
     *   used — the hangup handler that adds the call's talk time to today.
     */
    private function renderLimitsContext(): string
    {
        $today = '${STRFTIME(${EPOCH},,%Y%m%d)}';
        $key = 'tc_used/${TC_DEV}/' . $today;

        $out = "\n[" . self::LIMITS_CONTEXT . "]\n";
        $out .= "exten => out,1,Set(TC_DIALOPTS=)\n";
        $out .= " same => n,Set(TC_TIMEOUT=)\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_DEV}\" = \"\"]?done)\n";
        $out .= " same => n,Set(CHANNEL(hangup_handler_push)=" . self::LIMITS_CONTEXT . ",used,1)\n";
        $out .= " same => n,Gosub(cap,1)\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_CAP}\" = \"spent\"]?spent)\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_CAP}\" = \"\"]?done)\n";
        $out .= " same => n,Set(TC_TIMEOUT=\${TC_CAP})\n";
        $out .= " same => n,Set(LIMIT_WARNING_FILE=beep)\n";
        $out .= " same => n,Set(TC_DIALOPTS=L(\$[\${TC_CAP} * 1000]:60000))\n";
        $out .= " same => n(done),Return(ok)\n";
        $out .= " same => n(spent),Return(spent)\n";

        // Counted from here, the moment it was answered: the answering side's
        // own CDR doesn't reliably carry the talk time.

        // The allowance for this call: the per-call limit, or what is left of
        // today if that is less. "spent" when today is gone; empty for none.
        $out .= "exten => cap,1,Set(TC_CAP=\${TC_MAXCALL})\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_DAILY}\" = \"\"]?done)\n";
        $out .= " same => n,Set(TC_LEFT=\$[\${TC_DAILY} - 0\${DB({$key})}])\n";
        $out .= " same => n,GotoIf(\$[\${TC_LEFT} <= 0]?spent)\n";
        $out .= " same => n,ExecIf(\$[\"\${TC_CAP}\" = \"\" | \${TC_LEFT} < 0\${TC_CAP}]?Set(TC_CAP=\${TC_LEFT}))\n";
        $out .= " same => n(done),Return()\n";
        $out .= " same => n(spent),Set(TC_CAP=spent)\n";
        $out .= " same => n,Return()\n";

        $out .= "exten => used,1,GotoIf(\$[\"\${TC_DEV}\" = \"\"]?end)\n";
        $out .= " same => n,Set(TC_SECS=\${IF(\$[\"\${TC_START}\" != \"\"]?\$[\${EPOCH} - \${TC_START}]:0\${CDR(billsec)})})\n";
        $out .= " same => n,GotoIf(\$[0\${TC_SECS} <= 0]?end)\n";
        $out .= " same => n,Set(DB({$key})=\$[0\${DB({$key})} + \${TC_SECS}])\n";
        // Yesterday's count is no use to anyone; don't let them pile up.
        $out .= " same => n,Set(TC_OLD=\${DB_DELETE(tc_used/\${TC_DEV}/\${STRFTIME(\$[\${EPOCH} - 86400],,%Y%m%d)})})\n";
        $out .= " same => n(end),Return()\n";

        // ARG2 is the incoming call's own id: the recording is named after it,
        // the way the call log finds it, though it's started on this phone.
        // Its own context, at s: Dial's U() takes a context name and nothing
        // more — "context^exten^priority" is read as a context called that.
        //
        // ARG3 is the caller's name spoken. A phone with TC_ANNOUNCE — one with
        // no screen — plays it to whoever picked up before the call connects,
        // while the caller still hears ringing. Before the recording starts,
        // so it isn't in it. The short wait lets the handset's audio open, or
        // the first syllable is lost.
        $out .= "\n[" . self::ANSWERED_CONTEXT . "]\n";
        $out .= "exten => s,1,GotoIf(\$[\"\${TC_ANNOUNCE}\" != \"1\" | \"\${ARG3}\" = \"\"]?record)\n";
        $out .= " same => n,Wait(0.4)\n";
        $out .= " same => n,Playback(\${ARG3})\n";
        $out .= " same => n(record),ExecIf(\$[\"\${TC_ADULT}\" != \"1\" & \"\${ARG2}\" != \"\"]?MixMonitor(\${ARG2}." . self::RECORDING_FORMAT . "))\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_DEV}\" = \"\"]?done)\n";
        $out .= " same => n,Set(TC_START=\${EPOCH})\n";
        $out .= " same => n,Set(CHANNEL(hangup_handler_push)=" . self::LIMITS_CONTEXT . ",used,1)\n";
        $out .= " same => n,GotoIf(\$[\"\${ARG1}\" != \"00\"]?done)\n";
        $out .= " same => n,Gosub(" . self::LIMITS_CONTEXT . ",cap,1)\n";
        $out .= " same => n,ExecIf(\$[\"\${TC_CAP}\" = \"spent\"]?Set(TC_CAP=1))\n";
        $out .= " same => n,ExecIf(\$[\"\${TC_CAP}\" != \"\"]?Set(TIMEOUT(absolute)=\${TC_CAP}))\n";
        $out .= " same => n(done),Return()\n";

        return $out;
    }

    /**
     * Channel variable carrying a phone's own outgoing number, set on its
     * endpoint when one of the line's numbers is pointed at it.
     */
    public const OUT_NUMBER_VAR = 'TC_OUTNUM';

    /**
     * What an outgoing call presents as its number: the calling phone's own
     * line number when it has one, else the line's main number. Worked out on
     * the channel, since the same dialplan serves every phone.
     */
    public static function callerIdNumber(string $mainNumber): string
    {
        $var = self::OUT_NUMBER_VAR;

        return '${IF($["${' . $var . '}" != ""]?${' . $var . '}:' . $mainNumber . ')}';
    }

    /** Stock "press 1 to accept this call, or 2 to reject" — see renderConfContext(). */
    public const CONF_ACCEPT_PROMPT = 'followme/options';
    public const CONF_ACCEPT_KEY = '1';

    /** Dial-plan rules: normalise and dial out, or refuse the call. */
    public const DIALOUT_CONTEXT = 'twocans-dialout';
    public const BLOCKED_CONTEXT = 'twocans-blocked';

    /** Where MixMonitor and ConfBridge write recordings, inside Asterisk. */
    public const RECORDINGS_DIR = '/var/spool/asterisk/monitor';

    /** Numbers that are always dialable, whatever devices exist. */
    /**
     * The service numbers, keyed by the digits a child would dial.
     *
     * A method rather than a constant because the joke line moves: this list is
     * what stops a speed dial, or an automatically allocated extension, landing
     * on top of one of these.
     *
     * @return array<string,array{label:string,sub:string}>
     */
    public static function testNumbers(): array
    {
        return [
            self::VOICEMAIL_NUMBER => ['label' => 'Your messages', 'sub' => 'Listen to voicemail left on this phone'],
            self::jokeNumber() => ['label' => 'The joke line', 'sub' => 'Rings up a joke, picked at random from the ones you have added'],
            '600' => ['label' => 'Echo test', 'sub' => 'Hear your own voice back — checks the microphone and speaker'],
            '601' => ['label' => 'Test message', 'sub' => 'Plays a welcome message, like a real incoming call — your own greeting if you record one'],
            '500' => ['label' => 'Record the greeting', 'sub' => 'Speak after the beep, then hang up'],
        ];
    }

    /** The ones that never move — everything except the joke line. */
    public const FIXED_SERVICE_NUMBERS = ['700', '600', '601', '500'];

    /** twocans' built-in sounds, committed in storage/defaults — as Asterisk sees them. */
    public const DEFAULTS_DIR = '/var/lib/twocans/defaults';


    /**
     * Time conditions for a contact's call window, as GotoIfTime arguments:
     * the window is open when any one of them matches.
     *
     * Returns null for "anytime", meaning no check is emitted at all. Custom
     * hours are the contact's own weekly schedule — see Schedule.
     *
     * @return array<int,string>|null
     */
    public static function windowCondition(array $contact): ?array
    {
        switch ((string) $contact['call_window']) {
            case 'anytime':
                return null;
            case 'afterschool':
                return ['15:00-19:00,mon-fri,*,*'];
            case 'weekends':
                return ['09:00-19:00,sat-sun,*,*'];
            case 'custom':
                return Schedule::conditions(Schedule::fromJson(
                    $contact['window_schedule'] ?? null,
                    substr((string) ($contact['window_from'] ?? '09:00'), 0, 5) ?: '09:00',
                    substr((string) ($contact['window_to'] ?? '19:00'), 0, 5) ?: '19:00'
                ));
            default:
                return ['15:00-19:00,mon-fri,*,*'];
        }
    }

    /**
     * "Jump to $label if any of these times is now" — one GotoIfTime each.
     *
     * @param array<int,string> $conditions
     */
    public static function renderTimeJumps(array $conditions, string $label): string
    {
        $out = '';
        foreach ($conditions as $condition) {
            $out .= " same => n,GotoIfTime(" . self::timeCondition($condition) . "?{$label})\n";
        }

        return $out;
    }

    /**
     * The allowlist, as dialplan.
     *
     * Order matters and is deliberate:
     *   1. emergency numbers, before anything that could block them
     *   2. speed dials, so a child types 247 rather than a full number
     *   3. allowlisted numbers dialled in full
     *   4. everything else refused, with the phone's own spoken message
     *
     * An SOS contact skips the call-window check; that is the whole point of
     * marking someone SOS.
     */
    private function renderContactRules(): string
    {
        $contacts = (new ContactRepository())->all();
        $trunk = (new TrunkRepository())->get();
        $trunkConnected = (bool) $trunk['connected'];
        $trunkNumber = $trunkConnected ? (string) $trunk['number'] : '';
        $settings = new SettingsRepository();
        $quietHours = $settings->quietHours();
        $quietRange = $settings->quietTimeRange();
        $greetings = new Greetings($settings);
        $noLine = $greetings->prompt('no_line');
        $notNow = $greetings->prompt('outside_hours');
        $limitPrompt = $greetings->prompt('limit_reached');

        $out = "\n; --- emergency ------------------------------------------------------\n";
        $out .= "; Always reachable. No allowlist, no call window, no quiet hours, and\n";
        $out .= "; deliberately listed first so nothing below can ever shadow them.\n";
        foreach (ContactRepository::EMERGENCY_NUMBERS as $number) {
            $out .= "exten => {$number},1,NoOp(twocans: EMERGENCY \${EXTEN})\n";
            // Leave a note for the notifier, which emails a parent within the
            // minute — while the call is still going, not after the CDR lands.
            $out .= " same => n,Set(DB(" . self::EMERGENCY_FAMILY . "/\${UNIQUEID})=\${CHANNEL(endpoint)}|\${EXTEN}|\${EPOCH})\n";
            $out .= " same => n,Set(CDR(userfield)=emergency)\n";
            if ($trunkConnected) {
                $out .= " same => n,Goto(twocans-outbound,\${EXTEN},1)\n";
            } else {
                // Say so out loud rather than failing silently — a parent must
                // not believe 999 works from this phone when it cannot.
                $out .= " same => n,Answer()\n";
                $out .= " same => n,Playback(invalid)\n";
                $out .= " same => n,Hangup()\n";
            }
        }

        $out .= "\n; --- speed dials ----------------------------------------------------\n";
        $anyCode = false;
        foreach ($contacts as $c) {
            $code = (string) ($c['speed_dial'] ?? '');
            if ($code === '' || !$c['allow_out']) {
                continue;
            }

            // A group has no number of its own — its members supply those.
            if ((int) ($c['is_group'] ?? 0) === 1) {
                $rule = $this->renderGroupRule($code, $c, $trunkNumber);
                if ($rule !== '') {
                    $anyCode = true;
                    $out .= $rule;
                }
                continue;
            }

            if ((string) ($c['number_e164'] ?? '') === '') {
                continue;
            }
            $anyCode = true;
            $out .= self::renderReachRule($code, $c, $trunkNumber, $quietHours, $quietRange, $noLine, $notNow, $limitPrompt);
        }
        if (!$anyCode) {
            $out .= "; (no speed dials set)\n";
        }

        $out .= "\n; --- allowlisted numbers dialled in full -----------------------------\n";
        $anyNumber = false;
        foreach ($contacts as $c) {
            $number = (string) ($c['number_e164'] ?? '');
            // A group may still carry the number it had before it became one;
            // it is reached by its speed dial, never by dialling that number.
            if ($number === '' || !$c['allow_out'] || (int) ($c['is_group'] ?? 0) === 1) {
                continue;
            }
            $anyNumber = true;
            // Match the number with or without its country code, so a child
            // copying it off a fridge magnet still gets through.
            $national = '0' . substr(ContactRepository::digits($number), strlen(ContactRepository::countryCode()));
            foreach (array_unique([$number, $national]) as $pattern) {
                $out .= self::renderReachRule($pattern, $c, $trunkNumber, $quietHours, $quietRange, $noLine, $notNow, $limitPrompt);
            }
        }
        if (!$anyNumber) {
            $out .= "; (nobody on the allowlist yet)\n";
        }

        $out .= $this->renderDialplanRules($trunkNumber);

        $out .= "\n; --- everything else -------------------------------------------------\n";
        $out .= "; Not on the allowlist and no rule matches: block it, and invite the\n";
        $out .= "; child to say who they were trying to reach. A blocked call is usually\n";
        $out .= "; a child asking for something, not an attack — the recording turns a\n";
        $out .= "; dead end into a request a grown-up can say yes to. The recording is\n";
        $out .= "; named after the call and the app matches it up later, so a blocked\n";
        $out .= "; call behaves the same whether or not the app is running.\n";
        $out .= "exten => _X.,1,NoOp(twocans: \${EXTEN} is not on the call list)\n";
        // A phone in adult mode may call anyone: out it goes, as dialled.
        if ($trunkNumber !== '') {
            $out .= " same => n,GotoIf(\$[\"\${TC_ADULT}\" = \"1\"]?" . self::DIALOUT_CONTEXT . ",\${EXTEN},1)\n";
        }
        $out .= " same => n,Goto(" . self::BLOCKED_CONTEXT . ",\${EXTEN},1)\n";
        // Dialled with a leading +. Out for a phone in adult mode; for anyone
        // else the same refusal as any number that isn't on the list.
        $out .= "exten => _+X.,1,NoOp(twocans: \${EXTEN} dialled in full)\n";
        if ($trunkNumber !== '') {
            $out .= " same => n,GotoIf(\$[\"\${TC_ADULT}\" = \"1\"]?" . self::DIALOUT_CONTEXT . ",\${EXTEN},1)\n";
        }
        $out .= " same => n,Goto(" . self::BLOCKED_CONTEXT . ",\${EXTEN:1},1)\n";

        return $out;
    }

    /** One "you may reach this person" rule, with its window and recording. */
    /**
     * A group speed dial: ring everybody, put them all in one conversation.
     *
     * The child's side of this is identical to calling one person — they dial a
     * speed dial and start talking. Everything below happens before they hear
     * anything.
     *
     * Each member is rung with Originate rather than Dial, because Dial with
     * several targets connects whoever answers first and hangs up on the rest.
     * That is ring-any; this is a conference. The child goes into the bridge
     * immediately and hears people arrive as they pick up.
     */
    private function renderGroupRule(string $pattern, array $group, string $trunkNumber): string
    {
        $name = str_replace(["\n", "\r", ')'], '', (string) $group['name']);
        $members = (new ContactRepository())->members((int) $group['id']);

        if ($members === []) {
            return '';                      // nobody in it: not a dialable thing
        }

        $room = self::CONF_ROOM_PREFIX . $pattern;
        $settings = new SettingsRepository();
        $greetings = new Greetings($settings);
        $window = $group['sos'] ? null : $this->windowCondition($group);

        $out = "exten => {$pattern},1,NoOp(twocans: group call {$name}, "
             . count($members) . " people)\n";
        $out .= self::renderPhoneGates('go');

        if (!$group['sos'] && $settings->quietHours()) {
            $out .= self::renderTimeJumps($settings->quietTimeRange(), 'shut');
        }
        if ($window !== null) {
            $out .= self::renderTimeJumps($window, 'open');
            $out .= " same => n,Goto(shut)\n";
            $out .= " same => n(open),NoOp(within {$name}'s call window)\n";
        }

        // A group call counts against the phone's time like any other: stop it
        // before anyone is rung if today is used up.
        $out .= " same => n(go),NoOp(may call {$name} now)\n";
        if ($trunkNumber !== '') {
            $out .= self::renderLimitCheck();
        }
        $out .= " same => n,Set(CDR(userfield)=allowed)\n";
        $out .= " same => n,Answer()\n";

        if ($trunkNumber === '') {
            // Same honesty as a one-to-one call with no line: say so rather
            // than dropping everyone into an empty room.
            $out .= " same => n,Playback(" . $greetings->prompt('no_line') . ")\n";
            $out .= " same => n,Hangup()\n";

            return $out . $this->renderShutBranch($name, $window !== null
                || (!$group['sos'] && $settings->quietHours()), $greetings->prompt('outside_hours'));
        }

        // Record the conference as one file, named the way every other
        // recording is, so the call log picks it up without special casing.
        // Not when the phone starting it is in adult mode.
        //
        // These go on a profile of this call's own, built on twocans_bridge,
        // and ConfBridge below is given no bridge profile so that it uses it:
        // named there, the profile wins and these are ignored — which once
        // left every group call recorded under Asterisk's own file name,
        // never transcribed, and recorded in adult mode too (record_conference
        // is on in twocans_bridge). record_file_timestamp stops Asterisk
        // adding the time to the name we give.
        $out .= " same => n,Set(CONFBRIDGE(bridge,template)=twocans_bridge)\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_ADULT}\" = \"1\"]?norec)\n";
        $out .= " same => n,Set(CONFBRIDGE(bridge,record_file)="
              . self::RECORDINGS_DIR . "/\${UNIQUEID}." . self::RECORDING_FORMAT . ")\n";
        $out .= " same => n,Set(CONFBRIDGE(bridge,record_file_timestamp)=no)\n";
        $out .= " same => n,Goto(members)\n";
        $out .= " same => n(norec),Set(CONFBRIDGE(bridge,record_conference)=no)\n";
        $out .= " same => n(members),NoOp(members next)\n";

        foreach ($members as $member) {
            $memberName = str_replace(["\n", "\r", ')', ','], '', (string) $member['name']);
            $number = (string) $member['number_e164'];
            $out .= " same => n,NoOp(ringing {$memberName})\n";
            // a = ring asynchronously, so the next member is called straight
            // away instead of waiting out this one's 45 seconds.
            // c = present the line's own number, as a one-to-one call does.
            // Left out, the leg inherits the handset's extension (e.g. 203),
            // which the provider rejects before anyone's phone rings.
            // b = before ringing, tag the leg with who it is and which call
            // it belongs to, so the log can say who joined — see
            // renderMemberContext() and call_participants.
            $out .= " same => n,Originate(PJSIP/{$number}@twocans-trunk,exten,"
                  . self::CONF_CONTEXT . ",{$pattern},1,45,ac(" . self::callerIdNumber($trunkNumber) . ")"
                  . "b(" . self::MEMBER_CONTEXT . "^s^1(" . (int) $member['id'] . "^\${UNIQUEID})))\n";
        }

        // A conference has no Dial to take L(), so the allowance is a timeout.
        $out .= " same => n,ExecIf(\$[\"\${TC_TIMEOUT}\" != \"\"]?Set(TIMEOUT(absolute)=\${TC_TIMEOUT}))\n";
        // No bridge profile named: this call's own, set above, is the one used.
        $out .= " same => n,ConfBridge({$room},,twocans_user)\n";
        $out .= " same => n,Hangup()\n";
        $out .= self::renderSpentBranch($greetings->prompt('limit_reached'));

        return $out . $this->renderShutBranch($name, $window !== null
            || (!$group['sos'] && $settings->quietHours()), $greetings->prompt('outside_hours'));
    }

    /**
     * The "not right now" branch shared by one-to-one and group rules.
     *
     * $prompt is the household's recording when there is one — see Greetings;
     * without it the stock prompt plays.
     */
    public static function renderShutBranch(string $name, bool $needed, ?string $prompt = null): string
    {
        if (!$needed) {
            return '';
        }

        $out = " same => n(shut),NoOp(not allowed to call {$name} right now)\n";
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(" . ($prompt ?? self::WINDOW_MESSAGE) . ")\n";
        $out .= " same => n,Hangup()\n";

        return $out;
    }

    /**
     * Where an originated group member lands.
     *
     * One extension per group, named after its speed dial, so the room name is
     * fixed and no pattern matching is needed. Two children ringing the same
     * group at once therefore end up in the same conversation, which for one
     * household is the right answer rather than a limitation.
     */
    private function renderConfContext(): string
    {
        $groups = [];
        $contacts = new ContactRepository();
        $prompts = new GroupPromptStore();

        // The group's own greeting wins, then the house's, then Asterisk's
        // stock one. Paths come from the store, never from the row itself.
        $housePrompt = (new SettingsRepository())->groupPrompt();
        $housePrompt = $housePrompt === null ? null : $prompts->playbackPath($housePrompt);

        foreach ($contacts->groups() as $group) {
            $code = (string) ($group['speed_dial'] ?? '');
            if ($code === '' || !$group['allow_out'] || $contacts->members((int) $group['id']) === []) {
                continue;
            }
            $own = (string) ($group['group_prompt'] ?? '');
            $groups[$code] = [
                'name' => str_replace(["\n", "\r", ')'], '', (string) $group['name']),
                'prompt' => ($own !== '' ? $prompts->playbackPath($own) : null)
                    ?? $housePrompt ?? self::CONF_ACCEPT_PROMPT,
            ];
        }

        if ($groups === []) {
            return '';
        }

        $out = "\n[" . self::CONF_CONTEXT . "]\n";
        $out .= "; Group members are originated into here, never dialled — the numbers\n";
        $out .= "; are the group's own speed dial, matching the room it joins.\n";
        $out .= "; Whoever answers presses " . self::CONF_ACCEPT_KEY . " to join. A mobile that isn't picked up\n";
        $out .= "; is answered by its voicemail, which the network reports as a real\n";
        $out .= "; answer; without this its greeting plays into everyone's call.\n";
        foreach ($groups as $code => ['name' => $name, 'prompt' => $prompt]) {
            $out .= "exten => {$code},1,NoOp(answered for {$name})\n";
            $out .= " same => n,Read(TC_ACCEPT,{$prompt},1,,2,5)\n";
            $out .= " same => n,GotoIf(\$[\"\${TC_ACCEPT}\" = \"" . self::CONF_ACCEPT_KEY . "\"]?join)\n";
            $out .= " same => n,Hangup()\n";
            $out .= " same => n(join),NoOp(joining {$name})\n";
            // They pressed 1: this leg was in the conversation.
            $out .= " same => n,Set(CDR(userfield)=\${TC_MEMBERTAG}:joined)\n";
            $out .= " same => n,ConfBridge(" . self::CONF_ROOM_PREFIX . "{$code},twocans_bridge,twocans_user)\n";
            $out .= " same => n,Hangup()\n";
        }

        return $out;
    }

    public static function renderReachRule(
        string $pattern,
        array $contact,
        string $trunkNumber,
        bool $quietHours,
        string|array $quietTimeRange,
        ?string $noLinePrompt = null,
        ?string $notNowPrompt = null,
        ?string $limitPrompt = null,
    ): string {
        $name = str_replace(["\n", "\r", ')'], '', (string) $contact['name']);
        $number = (string) $contact['number_e164'];
        $window = $contact['sos'] ? null : self::windowCondition($contact);

        $out = "exten => {$pattern},1,NoOp(twocans: calling {$name})\n";
        $out .= self::renderPhoneGates('go');

        // Bedtime stops outgoing calls as well as incoming ones — but never to
        // an SOS contact, which is the whole point of the flag.
        if (!$contact['sos'] && $quietHours) {
            $out .= self::renderTimeJumps((array) $quietTimeRange, 'shut');
        }

        if ($window !== null) {
            // Outside the window this falls through to the shut label below.
            $out .= self::renderTimeJumps($window, 'open');
            $out .= " same => n,Goto(shut)\n";
            $out .= " same => n(open),NoOp(within {$name}'s call window)\n";
        }

        $out .= " same => n(go),Set(CDR(userfield)=allowed)\n";
        $out .= self::renderRecord();

        // Call limits, unless this is somebody who must always get through.
        $limited = $limitPrompt !== null && $trunkNumber !== ''
            && !$contact['sos'] && empty($contact['always_ring']);

        if ($trunkNumber !== '') {
            $out .= " same => n,Set(CALLERID(num)=" . self::callerIdNumber($trunkNumber) . ")\n";
            if ($limited) {
                $out .= self::renderLimitCheck();
                $out .= " same => n,Dial(PJSIP/{$number}@twocans-trunk,60,\${TC_DIALOPTS})\n";
            } else {
                $out .= " same => n,Dial(PJSIP/{$number}@twocans-trunk,60)\n";
            }
        } else {
            $out .= " same => n,Answer()\n";
            $out .= " same => n,Playback(" . ($noLinePrompt ?? self::NO_LINE_MESSAGE) . ")\n";
        }
        $out .= " same => n,Hangup()\n";
        if ($limited) {
            $out .= self::renderSpentBranch($limitPrompt);
        }

        // The shut branch is needed whenever anything can jump to it — a call
        // window, bedtime, or both.
        $out .= self::renderShutBranch($name, $window !== null
            || (!$contact['sos'] && $quietHours), $notNowPrompt);

        return $out;
    }

    /**
     * Dial-plan rules: prefix matches that sit between the allowlist and the
     * catch-all. An allow rule hands the dialled number to the dial-out context;
     * a block rule hands it to the blocked context.
     */
    private function renderDialplanRules(string $trunkNumber): string
    {
        $rules = (new DialplanRuleRepository())->all();
        if ($rules === []) {
            return '';
        }

        $out = "\n; --- dial-plan rules ------------------------------------------------\n";
        $out .= "; Prefix rules added by a grown-up. Longest matching prefix wins, so\n";
        $out .= "; a \"09\" block beats a broad \"0\" allow without any priority column.\n";

        foreach ($rules as $row) {
            $rule = DialplanRuleRepository::toView($row);
            $prefix = $rule['prefix'];
            if ($prefix === '') {
                continue;
            }
            $pattern = DialplanRuleRepository::pattern($prefix);

            if ($rule['action'] === 'block') {
                $out .= "exten => {$pattern},1,NoOp(twocans: {$prefix} blocked by rule)\n";
                // Not for a phone in adult mode: nothing is blocked for it.
                if ($trunkNumber !== '') {
                    $out .= " same => n,GotoIf(\$[\"\${TC_ADULT}\" = \"1\"]?" . self::DIALOUT_CONTEXT . ",\${EXTEN},1)\n";
                }
                $out .= " same => n,Goto(" . self::BLOCKED_CONTEXT . ",\${EXTEN},1)\n";
                continue;
            }

            $out .= "exten => {$pattern},1,NoOp(twocans: {$prefix} allowed by rule)\n";
            if ($trunkNumber !== '') {
                $out .= " same => n,Goto(" . self::DIALOUT_CONTEXT . ",\${EXTEN},1)\n";
            } else {
                $out .= " same => n,Answer()\n";
                $out .= " same => n,Wait(1)\n";
                $out .= " same => n,Playback(" . (new Greetings())->prompt('no_line') . ")\n";
                $out .= " same => n,Hangup()\n";
            }
        }

        return $out;
    }

    /**
     * Normalises a dialled number to E.164 and sends it to the trunk.
     *
     * Reached by allow rules. The number arrives as a child dials it — national
     * "07700…", or international "0044…" — and the trunk wants +447700…, so the
     * translation happens here in the dialplan rather than in PHP, keeping the
     * line usable when the app is down.
     */
    private function renderDialoutContext(): string
    {
        $trunk = (new TrunkRepository())->get();
        $number = (string) $trunk['number'];
        $cc = ContactRepository::countryCode();

        $out = "\n[" . self::DIALOUT_CONTEXT . "]\n";
        $out .= "; Reached by an allow rule. Normalises \${EXTEN} to E.164 and dials out.\n";
        $out .= "exten => _X.,1,NoOp(twocans: dialling \${EXTEN} out)\n";
        $out .= " same => n,Set(CDR(userfield)=allowed)\n";
        $out .= self::renderRecord();
        $out .= " same => n,Set(CALLERID(num)=" . self::callerIdNumber($number) . ")\n";
        $out .= " same => n,Set(NUM=\${EXTEN})\n";
        $out .= " same => n,GotoIf(\$[\"\${NUM:0:1}\" = \"+\"]?dial)\n";
        $out .= " same => n,GotoIf(\$[\"\${NUM:0:2}\" = \"00\"]?intl)\n";
        $out .= " same => n,GotoIf(\$[\"\${NUM:0:1}\" = \"0\"]?national)\n";
        $out .= " same => n,Set(NUM=+{$cc}\${NUM})\n";
        $out .= " same => n,Goto(dial)\n";
        $out .= " same => n(intl),Set(NUM=+\${NUM:2})\n";
        $out .= " same => n,Goto(dial)\n";
        $out .= " same => n(national),Set(NUM=+{$cc}\${NUM:1})\n";
        $out .= " same => n(dial),NoOp(dialling \${NUM})\n";
        $out .= " same => n,GotoIf(\$[\"\${TC_NOOUT}\" = \"1\"]?" . self::NO_OUT_CONTEXT . ",s,1)\n";
        $out .= self::renderLimitCheck();
        $out .= " same => n,Dial(PJSIP/\${NUM}@twocans-trunk,60,\${TC_DIALOPTS})\n";
        $out .= " same => n,Hangup()\n";
        $out .= self::renderSpentBranch((new Greetings())->prompt('limit_reached'));

        return $out;
    }

    /**
     * The "not allowed" ending, shared by the catch-all and block rules.
     *
     * Plays the refusal, then invites the child to say who they were trying to
     * reach. The recording lands in the asks spool, named after the call, and
     * the app turns it into a request a grown-up can approve.
     */
    private function renderBlockedContext(): string
    {
        $out = "\n[" . self::BLOCKED_CONTEXT . "]\n";
        $out .= "; Reached by the catch-all and by block rules.\n";
        $out .= "exten => _X.,1,NoOp(twocans: \${EXTEN} blocked)\n";
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $greetings = new Greetings();
        $out .= " same => n,Playback(" . $greetings->prompt('not_allowed') . ")\n";
        $out .= " same => n,Playback(" . $greetings->prompt('ask') . ")\n";
        $out .= " same => n,Playback(beep)\n";
        // k keeps whatever was said if they hang up mid-sentence, which small
        // children reliably do. 10s is long enough for "my friend Sam".
        $out .= " same => n,Record(" . self::ASK_SPOOL . "/\${UNIQUEID}."
              . self::RECORDING_FORMAT . ",2,10,k)\n";
        $out .= " same => n,Playback(auth-thankyou)\n";
        $out .= " same => n,Hangup()\n";

        return $out;
    }


    /**
     * Mailboxes, one per phone.
     *
     * Written to /etc/asterisk/voicemail.conf rather than the generated folder,
     * because app_voicemail does not support a wildcard include the way
     * pjsip.conf and extensions.conf do.
     */
    private function writeVoicemailConf(array $rows): int
    {
        $context = self::VOICEMAIL_CONTEXT;

        $out = $this->header('Voicemail boxes');
        $out .= "\n[general]\n";
        // wav so the browser can play a message back without transcoding.
        $out .= "format = wav\n";
        $out .= "attach = no\n";
        $out .= "maxmsg = 100\n";
        $out .= "maxsecs = 120\n";
        /*
         * maxsilence must be BELOW minsecs, or a caller who says nothing still
         * leaves a message as long as the silence timeout — Asterisk warns
         * about exactly this. With 3 and 4, silence stops the recording after
         * three seconds and is then too short to keep, while a real message
         * survives a normal pause for thought.
         */
        $out .= "minsecs = 4\n";
        $out .= "maxsilence = 3\n";
        $out .= "silencethreshold = 128\n";
        $out .= "review = yes\n";
        $out .= "operator = no\n";
        $out .= "sendvoicemail = no\n";
        // No email: this box is the only place a child's message should live.
        $out .= "serveremail = twocans@localhost\n";
        $out .= "\n[zonemessages]\n";
        $out .= "local = Europe/London|'vm-received' Q 'digits/at' HM\n";
        $out .= "\n[{$context}]\n";

        // Calls from outside that nobody answers land here rather than in one
        // particular child's mailbox.
        $out .= self::HOUSE_MAILBOX . " => 0000,The house,,,tz=local|attach=no\n";

        $any = false;
        foreach ($rows as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['extension'] === '' || !$d['available']) {
                continue;
            }
            $any = true;
            $pin = (string) ($row['voicemail_pin'] ?? '0000');
            $name = str_replace([',', '|', "\n", "\r"], ' ', $d['name']);
            $out .= "{$d['extension']} => {$pin},{$name},,,tz=local|attach=no\n";
        }

        $path = '/etc/asterisk/voicemail.conf';
        $temp = $path . '.tmp';
        if (@file_put_contents($temp, $out) === false) {
            return 0;
        }
        @chmod($temp, 0644);

        return @rename($temp, $path) ? 1 : 0;
    }


    /** Mailbox that takes messages for calls arriving from outside. */
    public const HOUSE_MAILBOX = '100';

    /**
     * Timezone every time-based rule is evaluated in.
     *
     * Stated explicitly in each GotoIfTime rather than relying on the
     * container's clock: the Asterisk image ships /etc/localtime pointing at
     * UTC even when TZ says otherwise, which silently put bedtime, call windows
     * and per-phone hours an hour out through British Summer Time. A rule about
     * when a child may be called has to be right, so it says so itself.
     */
    public static function timezone(): string
    {
        return getenv('TZ') ?: 'Europe/London';
    }

    /** A GotoIfTime argument list with the timezone pinned on. */
    public static function timeCondition(string $spec): string
    {
        return $spec . ',' . self::timezone();
    }

    /**
     * Caller lookup, as a Gosub-able context.
     *
     * Inbound routing needs to answer "who is this, and are they allowed?" from
     * a caller ID. Asterisk matches extensions against the *dialled* number, so
     * the allowlist is emitted a second time here keyed by caller instead, and
     * the incoming context Gosubs into it.
     */
    private function renderCallerLookup(array $contacts): string
    {
        $out = "\n[twocans-callers]\n";
        $out .= "; Looked up by caller ID. Sets CALLER_* and returns.\n";

        $any = false;
        $names = new CallerNameStore();
        foreach ($contacts as $c) {
            $number = (string) ($c['number_e164'] ?? '');
            // A group is not a caller. It may still be holding the number it
            // had before it became one, and letting that answer for an inbound
            // call would label the call with the group's name and grant it the
            // group's inbound permission.
            if ($number === '' || (int) ($c['is_group'] ?? 0) === 1) {
                continue;
            }
            $any = true;

            $name = str_replace(['"', "\n", "\r", ')'], '', (string) $c['name']);
            $digits = ContactRepository::digits($number);
            $national = '0' . substr($digits, strlen(ContactRepository::countryCode()));
            $window = $c['sos'] ? null : $this->windowCondition($c);
            // Their name spoken, for phones that say who's calling.
            $clip = (string) ($c['announce_clip'] ?? '');
            $announce = $clip !== '' ? (string) $names->playbackPath($clip) : '';

            // Trunks present the caller in various shapes; accept all of them.
            foreach (array_unique([$number, $digits, $national]) as $pattern) {
                $out .= "exten => {$pattern},1,Set(CALLER_NAME={$name})\n";
                $out .= " same => n,Set(CALLER_ANNOUNCE={$announce})\n";
                $out .= " same => n,Set(CALLER_ALLOWED=" . ($c['allow_in'] ? '1' : '0') . ")\n";
                $out .= " same => n,Set(CALLER_SOS=" . ($c['sos'] ? '1' : '0') . ")\n";
                // !empty rather than a cast: the column arrives with migration
                // 030, and a box that hasn't run it yet should read as "no".
                $out .= " same => n,Set(CALLER_ALWAYS=" . (!empty($c['always_ring']) ? '1' : '0') . ")\n";
                // Whether they may call right now, worked out as the call
                // arrives: a window can be several times a week, which one
                // variable holding one time range could not carry.
                if ($window === null) {
                    $out .= " same => n,Set(CALLER_OPEN=1)\n";
                } else {
                    $out .= " same => n,Set(CALLER_OPEN=0)\n";
                    $out .= self::renderTimeJumps($window, 'open');
                    $out .= " same => n,Return()\n";
                    $out .= " same => n(open),Set(CALLER_OPEN=1)\n";
                }
                $out .= " same => n,Return()\n";
            }
        }

        if (!$any) {
            $out .= "; (nobody on the allowlist yet)\n";
        }

        // Anyone not listed above.
        // A caller not on the list still arrives as +44…, and a Gosub to an
        // extension that matches nothing is a dialplan error, not a miss.
        $out .= "exten => _[+]X.,1,Goto(\${EXTEN:1},1)\n";
        $out .= "exten => _X.,1,Set(CALLER_NAME=Unknown number)\n";
        $out .= " same => n,Set(CALLER_ANNOUNCE=)\n";
        $out .= " same => n,Set(CALLER_ALLOWED=0)\n";
        $out .= " same => n,Set(CALLER_SOS=0)\n";
        $out .= " same => n,Set(CALLER_ALWAYS=0)\n";
        $out .= " same => n,Set(CALLER_OPEN=1)\n";
        $out .= " same => n,Return()\n";

        return $out;
    }

    /**
     * Offer one phone's recording as the message to refuse a caller with.
     *
     * Emitted once per phone while the ring list is built. The first offer wins
     * — ExecIf only writes when the variable is still empty — so the phone that
     * would ring first is the one that speaks, the same order the phones ring
     * in. Nothing is emitted for a phone with no recording, which is how a
     * household that has never recorded one keeps the stock prompt.
     */
    public static function renderRefusalPick(string $variable, ?string $sound): string
    {
        if ($sound === null || $sound === '') {
            return '';
        }

        return ' same => n,ExecIf($["${' . $variable . '}" = ""]?Set(' . $variable . '=' . $sound . "))\n";
    }

    /**
     * Is anybody on the list flagged to ignore the clock?
     *
     * Only real people with a number count: a group can never be looked up as a
     * caller, so a flag on one is inert and must not cost every phone its hours
     * check. This is what decides whether the bypasses below are emitted at all,
     * so a household that never uses the flag gets the dialplan it always had.
     */
    public static function anyAlwaysRing(array $contacts): bool
    {
        foreach ($contacts as $c) {
            if (!empty($c['always_ring'])
                && (int) ($c['is_group'] ?? 0) === 0
                && (string) ($c['number_e164'] ?? '') !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gate that lets an "always put through" caller past the clock entirely.
     *
     * Bedtime and the caller's own hours are both skipped, exactly as they are
     * for an SOS contact. It sits beside that skip rather than replacing it: SOS
     * is the emergency contact, exempt from the *household's* hours, and this is
     * the person whose call must reach a phone whatever hour it is.
     */
    public static function renderAlwaysThrough(bool $anyAlways): string
    {
        if (!$anyAlways) {
            return '';
        }

        $out = "\n; Somebody on the list is never subject to the clock: their call skips\n";
        $out .= "; bedtime and their own hours, and rings phones that are off for the night.\n";
        $out .= " same => n,GotoIf(\$[\"\${CALLER_ALWAYS}\" = \"1\"]?ring)\n";

        return $out;
    }

    /**
     * One phone's opening hours, as a dialplan test.
     *
     * Emitted with a bypass in front of it when somebody on the list is always
     * put through — that call has to ring the handset whose own hours have ended,
     * which is the one thing an SOS contact does not do.
     */
    /** @param array<int,string> $hours the phone's hours, as Schedule::conditions() */
    public static function renderDeviceHours(string $label, array $hours, bool $anyAlways): string
    {
        $out = '';
        if ($anyAlways) {
            $out .= " same => n,GotoIf(\$[\"\${CALLER_ALWAYS}\" = \"1\"]?on{$label})\n";
        }

        $out .= self::renderTimeJumps($hours, 'on' . $label);
        $out .= " same => n,Goto(off{$label})\n";

        return $out;
    }

    /**
     * What a caller who isn't on the list hears, and what happens next.
     *
     * ${REFUSAL} is the recording belonging to the phone that would have rung,
     * set while the ring list was built; ${REFUSAL_ANY} is the first phone that
     * has a recording at all, for when nothing on the line could ring. With
     * neither — nobody has recorded anything yet — the stock prompt stands in,
     * so the refusal itself is exactly what it was before this existed.
     *
     * How the call ends is the household's choice, passed in by the caller. A
     * number nobody recognises has two innocent explanations — a wrong number,
     * or somebody new trying to reach the house — and they look identical on
     * the way in, so with $takeMessage the caller is handed to the house
     * mailbox and can say who they are; the dashboard lists what they left
     * until a grown-up deals with it. Without it the line hangs up.
     *
     * Either way the caller is offered the joke line on the way out, and either
     * way the call is logged as blocked: an unknown number still never rings a
     * phone. A refused call is usually a wrong number, and a wrong number that
     * gets a joke is a better neighbour than one that gets a click.
     */
    public static function renderInboundRefusal(bool $takeMessage, ?string $fallback = null, bool $adults = false): string
    {
        $out = "\n; Not on the allowlist: say so kindly and log it. The words are the\n";
        $out .= "; phone's own recording, so each phone refuses in its own voice.\n";
        $out .= " same => n,GotoIf(\$[\"\${CALLER_ALLOWED}\" = \"1\"]?allowed)\n";
        if ($adults) {
            // A phone in adult mode takes anyone's call: ring just those.
            $out .= " same => n,GotoIf(\$[\"\${ADULTS}\" != \"\"]?adultsonly)\n";
        }
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,GotoIf(\$[\"\${REFUSAL}\" != \"\"]?havesound)\n";
        $out .= " same => n,Set(REFUSAL=\${REFUSAL_ANY})\n";
        $out .= " same => n(havesound),GotoIf(\$[\"\${REFUSAL}\" = \"\"]?nosound)\n";
        // Read, not Playback: the key press has to be picked up while the
        // recording is still playing, or a caller who wants the joke line sits
        // through the whole refusal first.
        $out .= " same => n,Read(JOKE_KEY,\${REFUSAL},1,,," . self::JOKE_KEY_SECONDS . ")\n";
        $out .= " same => n,GotoIf(\$[\"\${JOKE_KEY}\" = \"" . self::JOKE_KEY . "\"]?jokeline)\n";
        $out .= " same => n,Goto(refused)\n";
        // No phone has a recording: the house default, else the stock prompt.
        $out .= " same => n(nosound),Playback(" . ($fallback ?? self::BLOCKED_MESSAGE) . ")\n";
        $out .= " same => n,Read(JOKE_KEY,,1,,," . self::JOKE_KEY_SECONDS . ")\n";
        $out .= " same => n,GotoIf(\$[\"\${JOKE_KEY}\" = \"" . self::JOKE_KEY . "\"]?jokeline)\n";
        // The house mailbox, not a handset: nobody recognised this number, so
        // nobody agreed to it ringing a child's phone. quiet is the same
        // landing place as bedtime and a contact's own hours — the one that
        // knows how to take a message.
        $out .= $takeMessage
            ? " same => n(refused),Goto(quiet)\n"
            : " same => n(refused),Hangup()\n";

        return $out;
    }

    /**
     * The "not right now" branch, shared by bedtime and a contact's own hours.
     *
     * Different reasons, same answer: the phones are not going to ring. With a
     * recording from the household the caller hears why — a voice from this
     * house rather than Asterisk's — and can press 5 to be sent to the joke
     * line, which is a better end to a call than a beep. Without one this is
     * exactly what it always was: straight to the house mailbox.
     *
     * Read() rather than Playback() so the key is heard while the recording
     * plays, and either way the call carries on to the mailbox afterwards: a
     * caller with something to say can still say it.
     */
    public static function renderNotNowBranch(?string $message, bool $adults = false): string
    {
        $out = "\n; The line has gone quiet: bedtime, or outside the hours set on the\n";
        $out .= "; person being called. Say so in the household's own voice, and let the\n";
        $out .= "; caller escape to the joke line. A message can still be left either way.\n";

        // Not for a phone in adult mode, which rings whatever the hour.
        $first = $adults
            ? " same => n(notnow),GotoIf(\$[\"\${ADULTS}\" != \"\"]?adultsonly)\n same => n"
            : " same => n(notnow)";

        if ($message === null || $message === '') {
            $out .= $first . ",Goto(quiet)\n";

            return $out;
        }

        $out .= $first . ",Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Read(JOKE_KEY,{$message},1,,," . self::JOKE_KEY_SECONDS . ")\n";
        $out .= " same => n,GotoIf(\$[\"\${JOKE_KEY}\" = \"" . self::JOKE_KEY . "\"]?jokeline)\n";
        $out .= " same => n,Goto(quiet)\n";

        return $out;
    }

    /**
     * Where pressing the joke key lands, written once per incoming context.
     *
     * A label rather than a Goto spelled out in each branch, because the
     * refusal and the bedtime message offer the same escape and there should be
     * exactly one place that decides where the joke line is.
     */
    public static function renderJokeFallback(?string $jokeNumber = null): string
    {
        $number = $jokeNumber ?? self::jokeNumber();

        $out = "\n; Pressing " . self::JOKE_KEY . " from a message the caller did not want to\n";
        $out .= "; hear rings the joke line: it answers, tells one joke, and rings off.\n";
        $out .= " same => n(jokeline),Goto(" . self::DEVICES_CONTEXT . ",{$number},1)\n";

        return $out;
    }

    /**
     * Calls arriving from the outside world.
     *
     * The order is the product in miniature: work out who is calling, work out
     * which phones could take the call, refuse anyone not on the list in the
     * voice of the phone that would have rung, let an SOS contact through
     * regardless, respect bedtime and their call window — and ring the phones
     * that are allowed to receive at this hour. Somebody flagged "always put
     * through" skips the clock in all three places: which phones count, bedtime,
     * and their own hours.
     *
     * The ring list is built before the refusal because the refusal needs it:
     * a caller who isn't on the list hears a parent's recording, and which
     * recording that is depends on the phone they would have reached.
     */
    private function renderIncomingDialplan(array $devices, array $contacts): string
    {
        $settings = new SettingsRepository();
        $house = self::HOUSE_MAILBOX;
        $vm = self::VOICEMAIL_CONTEXT;
        $refusals = new RefusalStore();

        // Somebody flagged "always put through" is exempt from every hour in
        // this context: bedtime, their own opening hours, and each phone's. Only
        // emitted when such a person is on the list, so a household that never
        // uses the flag gets no extra gates in its dialplan.
        $anyAlways = self::anyAlwaysRing($contacts);

        // What a caller hears while the line is quiet: the household's own
        // recording, resolved here so a row whose file has gone missing — a
        // restore that didn't bring it back, a volume that didn't mount — reads
        // as "no recording" and falls back to the stock greeting rather than
        // playing silence.
        $quietFile = $settings->quietMessage();
        $quietMessage = $quietFile === null ? null : (new QuietMessageStore())->playbackPath($quietFile);

        $out = "\n[twocans-incoming]\n";
        $out .= "; A call from the trunk. \${EXTEN} is our own number; the person\n";
        $out .= "; calling is \${CALLERID(num)}.\n";
        // The provider dials us in full E.164, leading + and all. X matches
        // digits only, so _X. never sees a call from the trunk — strip the +
        // and fall into the pattern below.
        $out .= "exten => _[+]X.,1,Goto(\${EXTEN:1},1)\n";
        $out .= "exten => _X.,1,NoOp(twocans: inbound from \${CALLERID(num)})\n";
        // Which of the line's numbers was called — it decides which phones
        // ring, and the call log shows it.
        $out .= " same => n,Set(DID=\${EXTEN})\n";
        $out .= " same => n,Set(CDR(userfield)=inbound)\n";
        $out .= " same => n,Gosub(twocans-callers,\${CALLERID(num)},1)\n";
        $out .= " same => n,Set(CALLERID(name)=\${CALLER_NAME})\n";

        $out .= "\n; Which phones may receive right now: a phone is skipped if incoming\n";
        $out .= "; calls are off for it, or the clock is outside the hours set on its own\n";
        $out .= "; page. Built before anything is said, because a caller who isn't on the\n";
        $out .= "; list is refused in the voice of the phone that would have rung.\n";
        $out .= "; REFUSAL is that phone's recording; REFUSAL_ANY is the first phone\n";
        $out .= "; with any recording at all, which speaks when nothing here can ring.\n";
        $out .= " same => n,Set(TARGETS=)\n";
        // Phones in adult mode ring for everyone, whenever — see migration 041.
        $out .= " same => n,Set(ADULTS=)\n";
        $out .= " same => n,Set(REFUSAL=)\n";
        $out .= " same => n,Set(REFUSAL_ANY=)\n";

        // Each of the line's numbers can be pointed at a single handset: ONLY
        // is that phone's id for the number this call came in on, or empty to
        // ring them all. Matched on the last nine digits, the way contacts
        // are, so it holds however the provider spells the number it sends.
        $rings = (new TrunkRepository())->get()['rings'];
        $out .= " same => n,Set(ONLY=)\n";
        foreach ($rings as $number => $deviceId) {
            $tail = substr(preg_replace('/\D/', '', (string) $number) ?? '', -9);
            if ($tail !== '') {
                $out .= " same => n,ExecIf(\$[\"\${DID:-9}\" = \"{$tail}\"]?Set(ONLY={$deviceId}))\n";
            }
        }

        $ringable = 0;
        $adults = 0;
        foreach ($devices as $row) {
            $d = DeviceRepository::toView($row);
            $sound = $d['refusalAudio'] === ''
                ? null
                : $refusals->playbackPath($d['refusalAudio']);

            // Even a phone that may not ring right now can lend its words: at
            // bedtime the line is silent, but the caller still deserves the
            // household's own message rather than a stock prompt.
            $out .= self::renderRefusalPick('REFUSAL_ANY', $sound);

            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;
            }
            $label = 'dev' . $d['id'];

            // Adult mode: rings for any caller at any hour, whatever its own
            // switches, hours and limits say. Only a number pointed at another
            // phone passes it by — that's routing, not a restriction.
            if ($d['adult']) {
                $adults++;
                if ($rings !== []) {
                    $out .= " same => n,GotoIf(\$[\"\${ONLY}\" != \"\" & \"\${ONLY}\" != \"{$d['id']}\"]?off{$label})\n";
                }
                $out .= " same => n,Set(ADULTS=\${ADULTS}&PJSIP/{$d['sipUsername']})\n";
                $out .= " same => n(off{$label}),NoOp({$d['name']} is in adult mode)\n";
                continue;
            }

            if (!$d['allowIn']) {
                continue;
            }
            $ringable++;

            // A phone whose day of talking is used up doesn't ring, unless the
            // caller is SOS or always put through.
            if ($d['dailyMinutes'] !== null) {
                $used = "\${DB(tc_used/{$d['id']}/\${STRFTIME(\${EPOCH},,%Y%m%d)})}";
                $out .= " same => n,GotoIf(\$[\"\${CALLER_SOS}\${CALLER_ALWAYS}\" != \"00\"]?lim{$label})\n";
                $out .= " same => n,GotoIf(\$[0{$used} >= " . ($d['dailyMinutes'] * 60) . "]?off{$label})\n";
                $out .= " same => n(lim{$label}),NoOp({$d['name']} has time left today)\n";
            }

            // Skipped when this call's number belongs to another phone.
            if ($rings !== []) {
                $out .= " same => n,GotoIf(\$[\"\${ONLY}\" != \"\" & \"\${ONLY}\" != \"{$d['id']}\"]?off{$label})\n";
            }

            $out .= self::renderDeviceHours($label, Schedule::conditions($d['hours']), $anyAlways);
            $out .= " same => n(on{$label}),Set(TARGETS=\${TARGETS}&PJSIP/{$d['sipUsername']})\n";
            $out .= self::renderRefusalPick('REFUSAL', $sound);
            $out .= " same => n(off{$label}),NoOp({$d['name']} considered)\n";
        }

        if ($ringable === 0) {
            $out .= " same => n,NoOp(no phone can receive calls)\n";
        }

        // Nobody on the list: refuse, and then either take a message or hang up,
        // depending on what the household chose.
        $greetings = new Greetings($settings);
        $out .= self::renderInboundRefusal($settings->takesUnknownMessages(), $greetings->custom('unknown_caller'), $adults > 0);

        $out .= "\n; An SOS contact skips bedtime and their call window entirely.\n";
        $out .= " same => n(allowed),GotoIf(\$[\"\${CALLER_SOS}\" = \"1\"]?ring)\n";
        $out .= self::renderAlwaysThrough($anyAlways);

        if ($settings->quietHours()) {
            $out .= "\n; Bedtime mode: the line sleeps, so take a message instead.\n";
            $out .= self::renderTimeJumps($settings->quietTimeRange(), 'notnow');
        }

        $out .= "\n; Their own call window, if they have one — worked out by the lookup.\n";
        $out .= " same => n,GotoIf(\$[\"\${CALLER_OPEN}\" = \"1\"]?ring)\n";
        $out .= " same => n,Goto(notnow)\n";

        // Both gates above land here, and both end up ringing the joke line if
        // the caller asks for one, so the branch is written before the ring.
        $out .= self::renderNotNowBranch($quietMessage, $adults > 0);

        $out .= "\n; Ring the phones gathered above. Nothing on that list means\n";
        $out .= "; straight to the house mailbox.\n";
        if ($adults > 0) {
            // Refused, or not right now, for the others: only the adult phones.
            $out .= " same => n(adultsonly),Set(TARGETS=)\n";
            $out .= " same => n(ring),Set(TARGETS=\${TARGETS}\${ADULTS})\n";
            $out .= " same => n,GotoIf(\$[\"\${TARGETS}\" = \"\"]?quiet)\n";
        } else {
            $out .= " same => n(ring),GotoIf(\$[\"\${TARGETS}\" = \"\"]?quiet)\n";
        }
        // Recording starts when a phone answers, in the U() step below, so it
        // is the phone that picked up that decides: not one in adult mode.
        // TARGETS is built with a leading &, so trim it off.
        // U(): the phone that answers applies its own limits — see
        // renderLimitsContext(). The flags let SOS and always-through callers
        // past them. The last one is the caller's name spoken, for a phone
        // that says who's calling.
        $out .= " same => n,Dial(\${TARGETS:1},30,U(" . self::ANSWERED_CONTEXT . "^\${CALLER_SOS}\${CALLER_ALWAYS}^\${UNIQUEID}^\${CALLER_ANNOUNCE}))\n";
        $out .= " same => n,Goto(quiet)\n";

        $out .= "\n; Nobody answered. Straight to the house mailbox: the phones rang and\n";
        $out .= "; nobody picked up, which needs no explaining.\n";
        // The household's own greeting when there is one. s skips Asterisk's
        // "leave your message after the tone" — the recording has said it —
        // but still beeps before recording.
        $vmGreeting = $greetings->custom('voicemail');
        if ($vmGreeting !== null) {
            $out .= " same => n(quiet),Playback({$vmGreeting})\n";
            $out .= " same => n,VoiceMail({$house}@{$vm},s)\n";
        } else {
            $out .= " same => n(quiet),VoiceMail({$house}@{$vm},u)\n";
        }
        $out .= " same => n,Hangup()\n";
        $out .= self::renderJokeFallback();

        return $out;
    }

    private function renderDialplan(array $rows): string
    {
        $greeting = self::GREETING;

        $out = $this->header('Device dialplan');
        $out .= "\n[" . self::DEVICES_CONTEXT . "]\n\n";

        $out .= "; 600 — echo test. Everything you say comes straight back, which\n";
        $out .= "; proves the microphone, the speaker and RTP in both directions.\n";
        $out .= "exten => 600,1,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(demo-echotest)\n";
        $out .= " same => n,Echo()\n";
        $out .= " same => n,Playback(demo-echodone)\n";
        $out .= " same => n,Hangup()\n\n";

        $out .= "; 601 — test number. Answers and plays the household greeting, so a\n";
        $out .= "; child can hear what an incoming call sounds like.\n";
        $out .= "exten => 601,1,Answer()\n";
        $out .= " same => n,MixMonitor(\${UNIQUEID}." . self::RECORDING_FORMAT . ")\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback({$greeting})\n";
        // Playback does not jump on failure, it just sets PLAYBACKSTATUS and
        // carries on — so without this check a missing or empty greeting means
        // the call answers and hangs up in silence, looking like a dead line.
        $out .= " same => n,GotoIf(\$[\"\${PLAYBACKSTATUS}\" = \"SUCCESS\"]?bye)\n";
        // No greeting of the household's own: twocans' built-in welcome (see
        // storage/defaults), then Asterisk's stock one if even that is missing.
        $out .= " same => n,Playback(" . self::DEFAULTS_DIR . "/test-call)\n";
        $out .= " same => n,GotoIf(\$[\"\${PLAYBACKSTATUS}\" = \"SUCCESS\"]?bye)\n";
        $out .= " same => n,Playback(demo-congrats)\n";
        $out .= " same => n(bye),Wait(1)\n";
        $out .= " same => n,Hangup()\n\n";

        $out .= "; 500 — record that greeting from any handset. Speak after the beep,\n";
        $out .= "; press # or hang up when done, then it plays back to you.\n";
        $out .= "exten => 500,1,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(vm-rec-name)\n";
        $out .= " same => n,Playback(beep)\n";
        // Record to a scratch file, not over the live greeting: hanging up
        // without speaking leaves a zero-length file, and writing that straight
        // to the greeting would silently break the 601 test message.
        $out .= " same => n,Record({$greeting}-new.ulaw,3,120,k)\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback({$greeting}-new)\n";
        $out .= " same => n,GotoIf(\$[\"\${PLAYBACKSTATUS}\" = \"SUCCESS\"]?keep)\n";
        // Nothing usable was recorded — say so and leave the greeting alone.
        $out .= " same => n,Playback(beeperr)\n";
        $out .= " same => n,Hangup()\n";
        $out .= " same => n(keep),System(mv {$greeting}-new.ulaw {$greeting}.ulaw)\n";
        $out .= " same => n,Playback(auth-thankyou)\n";
        $out .= " same => n,Hangup()\n\n";

        $out .= $this->renderJokeLine();

        $vmContext = self::VOICEMAIL_CONTEXT;
        $vmNumber = self::VOICEMAIL_NUMBER;
        $out .= "; {$vmNumber} — listen to messages left on this phone.\n";
        $out .= "; No PIN: the caller ID is the phone's own extension, so it can only\n";
        $out .= "; ever open its own mailbox, and a child should not need a password to\n";
        $out .= "; hear a message left for them.\n";
        $out .= "exten => {$vmNumber},1,NoOp(twocans: voicemail for \${CALLERID(num)})\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,VoiceMailMain(\${CALLERID(num)}@{$vmContext},s)\n";
        $out .= " same => n,Hangup()\n\n";

        $out .= "; Phone-to-phone within the house.\n";

        $any = false;
        foreach ($rows as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || $d['extension'] === '' || !$d['available']) {
                continue;
            }
            $any = true;
            $vmContext = self::VOICEMAIL_CONTEXT;

            $out .= "exten => {$d['extension']},1,NoOp(twocans: calling {$d['name']})\n";
            // Record only while the two sides are actually bridged, so ringing
            // and voicemail prompts don't end up in the file.
            // Not when either phone is in adult mode.
            $out .= $d['adult'] ? " same => n,NoOp({$d['name']} is in adult mode: not recorded)\n" : self::renderRecord();
            // 30s to answer, then give up rather than ringing forever.
            $out .= " same => n,Dial(PJSIP/{$d['sipUsername']},30)\n";
            // Nobody picked up (or the phone was busy) — offer to take a
            // message instead of just dropping the call.
            $out .= " same => n,Goto(vm-\${DIALSTATUS})\n";
            $out .= " same => n(vm-NOANSWER),VoiceMail({$d['extension']}@{$vmContext},u)\n";
            $out .= " same => n,Hangup()\n";
            $out .= " same => n(vm-BUSY),VoiceMail({$d['extension']}@{$vmContext},b)\n";
            $out .= " same => n,Hangup()\n";
            // Anything else (unreachable, congestion) still gets a mailbox.
            $out .= " same => n(vm-CHANUNAVAIL),VoiceMail({$d['extension']}@{$vmContext},u)\n";
            $out .= " same => n,Hangup()\n";
            $out .= " same => n(vm-CANCEL),Hangup()\n";
            $out .= " same => n(vm-ANSWER),Hangup()\n";
        }

        if (!$any) {
            $out .= "; (no devices yet)\n";
        }

        $out .= $this->renderContactRules();

        $contacts = (new ContactRepository())->all();
        $out .= $this->renderCallerLookup($contacts);
        $out .= $this->renderIncomingDialplan($rows, $contacts);

        // Last, because these open contexts of their own — see the methods.
        $out .= $this->renderConfContext();
        $out .= $this->renderJokeContext();
        $out .= $this->renderDialoutContext();
        $out .= $this->renderBlockedContext();
        $out .= $this->renderLimitsContext();
        $out .= $this->renderMemberContext();
        $out .= $this->renderPageContext($rows);
        $out .= $this->renderNoOutContext();

        return $out;
    }

    /**
     * Playback paths of every enabled joke that still has its audio.
     *
     * A row whose file has gone missing is left out rather than written into
     * the dialplan: Playback on a missing file is silence, which to a child is
     * indistinguishable from a dead line.
     *
     * @return array<int,string>
     */
    private function jokeFiles(): array
    {
        $store = new JokeStore();
        $files = [];

        foreach ((new JokeRepository())->all(true) as $row) {
            $path = $store->playbackPath((string) $row['audio_file']);
            if ($path !== null) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * The joke line.
     *
     * Every enabled joke is written out as its own extension in a context of
     * its own, and the line plays them in a shuffled order, one per call, then
     * starts again. Choosing in the dialplan rather than in PHP means a child
     * can still dial it when the database or the web app is down — the same
     * reasoning as the rest of this file.
     *
     * No prompts and no menu: it answers, tells a joke and rings off. Redialling
     * is a single button on the handset, and a child who wants another one will
     * find that far quicker than any "press 1" instruction they have to sit
     * through first.
     */
    private function renderJokeLine(): string
    {
        $number = self::jokeNumber();
        $count = count($this->jokeFiles());

        $out = "; {$number} — the joke line.\n";

        if ($count === 0) {
            $out .= "; No jokes uploaded yet, so the line says so rather than\n";
            $out .= "; answering into silence.\n";
            $out .= "exten => {$number},1,Answer()\n";
            $out .= " same => n,Wait(1)\n";
            $out .= " same => n,Playback(vm-nomore)\n";
            $out .= " same => n,Hangup()\n\n";

            return $out;
        }

        // Play through every joke in a shuffled order, then start again. The
        // shuffle happens when this config is written, so each cycle hears all
        // jokes once before any repeat — a plain RAND would let the same joke
        // come around again within a handful of calls.
        $out .= "; {$count} joke(s), played through a shuffled order before repeating.\n";
        $out .= "exten => {$number},1,NoOp(twocans: joke line)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Set(POS=\${GLOBAL(TWOCANS_JOKE_POS)})\n";
        $out .= " same => n,GotoIf(\$[\"\${POS}\" = \"\"]?reset)\n";
        $out .= " same => n,GotoIf(\$[\${POS} > {$count}]?reset)\n";
        $out .= " same => n,Goto(play)\n";
        $out .= " same => n(reset),Set(POS=1)\n";
        $out .= " same => n(play),Set(J=\${POS})\n";
        $out .= " same => n,Set(NEXT=\${MATH(\${POS}+1,int)})\n";
        $out .= " same => n,GotoIf(\$[\${NEXT} > {$count}]?wrap)\n";
        $out .= " same => n,Goto(save)\n";
        $out .= " same => n(wrap),Set(NEXT=1)\n";
        $out .= " same => n(save),Set(GLOBAL(TWOCANS_JOKE_POS)=\${NEXT})\n";
        $out .= " same => n,Goto(" . self::JOKE_CONTEXT . ",\${J},1)\n\n";

        return $out;
    }

    /**
     * One extension per joke, in a context of its own.
     *
     * Written at the very end of the file: opening a new [context] closes the
     * one before it, so this cannot sit next to the joke line's own extension
     * without swallowing every device that follows.
     */
    private function renderJokeContext(): string
    {
        $files = $this->jokeFiles();

        if ($files === []) {
            return '';
        }

        // The joke line plays these sequentially, so shuffle the list here to
        // turn that sequence into a random order.
        shuffle($files);

        $out = "\n[" . self::JOKE_CONTEXT . "]\n";
        $out .= "; Reached only by Goto from the joke line, so the numbers here are\n";
        $out .= "; a position in the shuffled list — nobody dials them.\n";
        foreach ($files as $i => $path) {
            $out .= 'exten => ' . ($i + 1) . ",1,Playback({$path})\n";
            $out .= " same => n,Wait(1)\n";
            $out .= " same => n,Hangup()\n";
        }

        return $out;
    }

    /** Outbound trunk endpoint. Auth is by the peer's source IP, not credentials. */
    private function renderTrunkEndpoint(array $trunk): string
    {
        $out = $this->header('SIP trunk');
        $host = $trunk['sipHost'];

        // A trunk authenticates outbound calls either by allowlisting this
        // house's IP or by challenging for a username and password. Only the
        // second needs anything from us, and it is the better fit for a home
        // line whose address the ISP may change without warning.
        $authUser = (string) ($trunk['terminationUsername'] ?? '');
        $authPass = $authUser === '' ? null : (new TrunkRepository())->terminationPassword();
        $useAuth = $authUser !== '' && $authPass !== null && $authPass !== '';

        $out .= "\n";
        if ($useAuth) {
            $out .= "; Outbound auth. The provider challenges our INVITEs, so the password\n";
            $out .= "; has to sit here in the clear — Asterisk has no way to read it back\n";
            $out .= "; encrypted. generated/ is git-ignored for exactly this reason.\n";
            $out .= "[twocans-trunk-auth]\n";
            $out .= "type = auth\n";
            $out .= "auth_type = userpass\n";
            $out .= "username = {$authUser}\n";
            $out .= "password = {$authPass}\n";
            $out .= "\n";
        }
        $out .= "; Outbound trunk.\n";
        if (!$useAuth) {
            $out .= "; The provider authenticates the call by its source IP (Twilio's IP\n";
            $out .= "; Access Control List, or SIP.IO's trunk ACL), so there is no SIP auth\n";
            $out .= "; or registration section here.\n";
        }
        $out .= "[twocans-trunk]\n";
        $out .= "type = endpoint\n";
        $out .= "context = twocans-incoming\n";
        $out .= "disallow = all\n";
        $out .= "allow = ulaw\n";
        $out .= "allow = alaw\n";
        $out .= "; Media through Asterisk so outbound calls can be recorded like the rest.\n";
        $out .= "direct_media = no\n";
        $out .= "aors = twocans-trunk\n";

        if ($useAuth) {
            $out .= "outbound_auth = twocans-trunk-auth\n";
        }

        $public = self::trunkPublicHost();
        if ($public !== '') {
            $out .= "; Answers on the public address, not the LAN one the handsets use.\n";
            $out .= 'transport = ' . self::TRUNK_TRANSPORT . "\n";
        }

        $out .= "\n";
        $out .= "[twocans-trunk]\n";
        $out .= "type = aor\n";
        $out .= "contact = sip:{$host}\n";
        $out .= "; Ping the provider on a timer. Two reasons: it keeps the router's NAT\n";
        $out .= "; mapping for our trunk port alive, which some routers need before they\n";
        $out .= "; will let the provider's calls back in, and it gives the trunk a\n";
        $out .= "; reachability figure instead of the permanent 'NonQual' it had before.\n";
        $out .= "qualify_frequency = 30\n";
        $out .= "qualify_timeout = 5\n";
        $out .= $this->renderTrunkIdentify($trunk);

        return $out;
    }

    /**
     * Teach PJSIP to recognise the provider by source address.
     *
     * Neither provider registers or authenticates inbound calls, so an INVITE
     * arrives with nothing to tie it to the trunk endpoint. Without this the
     * call is rejected as unidentified and twocans-incoming never runs.
     */
    private function renderTrunkIdentify(array $trunk): string
    {
        // Twilio's gateways are published ranges; SIP.IO sends from the same
        // host the trunk points at, which res_pjsip resolves on load.
        $matches = $trunk['provider'] === 'SIP.IO'
            ? array_filter([$trunk['sipHost']])
            : self::TWILIO_SIGNALLING_NETS;

        if ($matches === []) {
            return '';
        }

        $out = "\n; Inbound calls come from these addresses and carry no credentials.\n";
        $out .= "[twocans-trunk]\n";
        $out .= "type = identify\n";
        $out .= "endpoint = twocans-trunk\n";
        foreach ($matches as $match) {
            $out .= "match = {$match}\n";
        }

        return $out;
    }

    /**
     * Outbound route to the PSTN, used by the emergency numbers as dialled.
     *
     * Allowlisted contacts and dial-plan rules dial the trunk directly (after
     * normalising to E.164 in twocans-dialout); only emergency numbers route
     * through here, because they must go out exactly as dialled.
     */
    private function renderTrunkDialplan(array $trunk): string
    {
        $out = $this->header('Outbound dialplan');
        $number = $trunk['number'];

        $out .= "\n";
        $out .= "[twocans-outbound]\n";
        $out .= "exten => _X.,1,NoOp(twocans: outbound \${EXTEN} via trunk)\n";
        $out .= " same => n,Set(CALLERID(num)=" . self::callerIdNumber($number) . ")\n";
        $out .= " same => n,Dial(PJSIP/\${EXTEN}@twocans-trunk,60)\n";
        $out .= " same => n,Hangup()\n";

        return $out;
    }

    private function header(string $what): string
    {
        return "; {$what} — generated by twocans on " . date('Y-m-d H:i:s') . ".\n"
             . "; Do not edit: this file is rewritten whenever a phone changes.\n";
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['"', "\n", "\r"], '', $value) . '"';
    }

    /** Strip the indentation that heredocs inside a method pick up. */
    private function dedent(string $text): string
    {
        return preg_replace('/^[ \t]+/m', '', $text) ?? $text;
    }
}
