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

    public static function port(string $transport): int
    {
        return $transport === 'tls' ? 5061 : 5060;
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
    public function apply(): array
    {
        $rows = $this->devices->all();

        $written = $this->write('pjsip-transports.conf', $this->renderTransports())
                 + $this->write('pjsip-devices.conf', $this->renderEndpoints($rows))
                 + $this->write('dialplan-devices.conf', $this->renderDialplan($rows))
                 + $this->writeVoicemailConf($rows);

        // The SIP trunk is optional: only write it once a line is connected.
        $trunk = (new TrunkRepository())->get();
        if ($trunk['connected'] && $trunk['sipHost'] !== '') {
            $written += $this->write('pjsip-trunk.conf', $this->renderTrunkEndpoint($trunk));
            $written += $this->write('dialplan-trunk.conf', $this->renderTrunkDialplan($trunk));
        } else {
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
    private function renderTransports(): string
    {
        $lan = self::domain();

        // Home phones reach the box directly, so the transports advertise the
        // LAN address.
        $transports = [
            ['transport-udp', 'udp', 5060, $lan],
            ['transport-tcp', 'tcp', 5060, $lan],
        ];

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

    private function renderEndpoints(array $rows): string
    {
        $out = $this->header('PJSIP endpoints');

        foreach ($rows as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available']) {
                continue;                       // ATAs are not wired up yet
            }

            $name = $d['sipUsername'];
            $transport = 'transport-' . $d['transport'];
            $vmContext = self::VOICEMAIL_CONTEXT;   // heredocs can't call self::
            $callerid = $this->quote($d['name']) . ' <' . $d['extension'] . '>';

            $out .= <<<CONF

            ;--- {$d['name']} (extension {$d['extension']})
            [{$name}]
            type = endpoint
            context = twocans-devices
            transport = {$transport}
            aors = {$name}
            auth = {$name}-auth
            callerid = {$callerid}
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

    /** Prompts played when a call is refused. Swap for recorded ones later. */
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

    /** Asterisk voicemail context, and the number for picking messages up. */
    public const VOICEMAIL_CONTEXT = 'twocans';
    public const VOICEMAIL_NUMBER = '700';

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
            '601' => ['label' => 'Test message', 'sub' => 'Plays the recorded greeting, like a real incoming call'],
            '500' => ['label' => 'Record the greeting', 'sub' => 'Speak after the beep, then hang up'],
        ];
    }

    /** The ones that never move — everything except the joke line. */
    public const FIXED_SERVICE_NUMBERS = ['700', '600', '601', '500'];


    /**
     * Time conditions for a contact's call window, as GotoIfTime arguments.
     *
     * Returns null for "anytime", meaning no check is emitted at all.
     */
    public static function windowCondition(array $contact): ?string
    {
        switch ((string) $contact['call_window']) {
            case 'anytime':
                return null;
            case 'afterschool':
                return '15:00-19:00,mon-fri,*,*';
            case 'weekends':
                return '09:00-19:00,sat-sun,*,*';
            case 'custom':
                $from = substr((string) ($contact['window_from'] ?? '09:00'), 0, 5);
                $to = substr((string) ($contact['window_to'] ?? '19:00'), 0, 5);

                return $from . '-' . $to . ',*,*,*';
            default:
                return '15:00-19:00,mon-fri,*,*';
        }
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

        $out = "\n; --- emergency ------------------------------------------------------\n";
        $out .= "; Always reachable. No allowlist, no call window, no quiet hours, and\n";
        $out .= "; deliberately listed first so nothing below can ever shadow them.\n";
        foreach (ContactRepository::EMERGENCY_NUMBERS as $number) {
            $out .= "exten => {$number},1,NoOp(twocans: EMERGENCY \${EXTEN})\n";
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
            $out .= self::renderReachRule($code, $c, $trunkNumber, $quietHours, $quietRange);
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
                $out .= self::renderReachRule($pattern, $c, $trunkNumber, $quietHours, $quietRange);
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
        $out .= " same => n,Goto(" . self::BLOCKED_CONTEXT . ",\${EXTEN},1)\n";

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
        $window = $group['sos'] ? null : $this->windowCondition($group);

        $out = "exten => {$pattern},1,NoOp(twocans: group call {$name}, "
             . count($members) . " people)\n";

        if (!$group['sos'] && $settings->quietHours()) {
            $out .= " same => n,GotoIfTime(" . $this->timeCondition($settings->quietTimeRange()) . "?shut)\n";
        }
        if ($window !== null) {
            $out .= " same => n,GotoIfTime(" . $this->timeCondition($window) . "?open)\n";
            $out .= " same => n,Goto(shut)\n";
            $out .= " same => n(open),NoOp(within {$name}'s call window)\n";
        }

        $out .= " same => n,Set(CDR(userfield)=allowed)\n";
        $out .= " same => n,Answer()\n";

        if ($trunkNumber === '') {
            // Same honesty as a one-to-one call with no line: say so rather
            // than dropping everyone into an empty room.
            $out .= " same => n,Playback(" . self::NO_LINE_MESSAGE . ")\n";
            $out .= " same => n,Hangup()\n";

            return $out . $this->renderShutBranch($name, $window !== null
                || (!$group['sos'] && $settings->quietHours()));
        }

        // Record the conference as one file, named the way every other
        // recording is, so the call log picks it up without special casing.
        $out .= " same => n,Set(CONFBRIDGE(bridge,record_file)="
              . self::RECORDINGS_DIR . "/\${UNIQUEID}." . self::RECORDING_FORMAT . ")\n";

        foreach ($members as $member) {
            $memberName = str_replace(["\n", "\r", ')', ','], '', (string) $member['name']);
            $number = (string) $member['number_e164'];
            $out .= " same => n,NoOp(ringing {$memberName})\n";
            // a = ring asynchronously, so the next member is called straight
            // away instead of waiting out this one's 45 seconds.
            $out .= " same => n,Originate(PJSIP/{$number}@twocans-trunk,exten,"
                  . self::CONF_CONTEXT . ",{$pattern},1,45,a)\n";
        }

        $out .= " same => n,ConfBridge({$room},twocans_bridge,twocans_user)\n";
        $out .= " same => n,Hangup()\n";

        return $out . $this->renderShutBranch($name, $window !== null
            || (!$group['sos'] && $settings->quietHours()));
    }

    /** The "not right now" branch shared by one-to-one and group rules. */
    public static function renderShutBranch(string $name, bool $needed): string
    {
        if (!$needed) {
            return '';
        }

        $out = " same => n(shut),NoOp(not allowed to call {$name} right now)\n";
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(" . self::WINDOW_MESSAGE . ")\n";
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

        foreach ($contacts->groups() as $group) {
            $code = (string) ($group['speed_dial'] ?? '');
            if ($code === '' || !$group['allow_out'] || $contacts->members((int) $group['id']) === []) {
                continue;
            }
            $groups[$code] = str_replace(["\n", "\r", ')'], '', (string) $group['name']);
        }

        if ($groups === []) {
            return '';
        }

        $out = "\n[" . self::CONF_CONTEXT . "]\n";
        $out .= "; Group members are originated into here, never dialled — the numbers\n";
        $out .= "; are the group's own speed dial, matching the room it joins.\n";
        foreach ($groups as $code => $name) {
            $out .= "exten => {$code},1,NoOp(joining {$name})\n";
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
        string $quietTimeRange,
    ): string {
        $name = str_replace(["\n", "\r", ')'], '', (string) $contact['name']);
        $number = (string) $contact['number_e164'];
        $window = $contact['sos'] ? null : self::windowCondition($contact);

        $out = "exten => {$pattern},1,NoOp(twocans: calling {$name})\n";

        // Bedtime stops outgoing calls as well as incoming ones — but never to
        // an SOS contact, which is the whole point of the flag.
        if (!$contact['sos'] && $quietHours) {
            $out .= " same => n,GotoIfTime(" . self::timeCondition($quietTimeRange) . "?shut)\n";
        }

        if ($window !== null) {
            // Outside the window this falls through to the shut label below.
            $out .= " same => n,GotoIfTime(" . self::timeCondition($window) . "?open)\n";
            $out .= " same => n,Goto(shut)\n";
            $out .= " same => n(open),NoOp(within {$name}'s call window)\n";
        }

        $out .= " same => n,Set(CDR(userfield)=allowed)\n";
        $out .= " same => n,MixMonitor(\${UNIQUEID}." . self::RECORDING_FORMAT . ",b)\n";

        if ($trunkNumber !== '') {
            $out .= " same => n,Set(CALLERID(num)={$trunkNumber})\n";
            $out .= " same => n,Dial(PJSIP/{$number}@twocans-trunk,60)\n";
        } else {
            $out .= " same => n,Answer()\n";
            $out .= " same => n,Playback(" . self::NO_LINE_MESSAGE . ")\n";
        }
        $out .= " same => n,Hangup()\n";

        // The shut branch is needed whenever anything can jump to it — a call
        // window, bedtime, or both.
        $out .= self::renderShutBranch($name, $window !== null
            || (!$contact['sos'] && $quietHours));

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
                $out .= " same => n,Goto(" . self::BLOCKED_CONTEXT . ",\${EXTEN},1)\n";
                continue;
            }

            $out .= "exten => {$pattern},1,NoOp(twocans: {$prefix} allowed by rule)\n";
            if ($trunkNumber !== '') {
                $out .= " same => n,Goto(" . self::DIALOUT_CONTEXT . ",\${EXTEN},1)\n";
            } else {
                $out .= " same => n,Answer()\n";
                $out .= " same => n,Wait(1)\n";
                $out .= " same => n,Playback(" . self::NO_LINE_MESSAGE . ")\n";
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
        $out .= " same => n,MixMonitor(\${UNIQUEID}." . self::RECORDING_FORMAT . ",b)\n";
        $out .= " same => n,Set(CALLERID(num)={$number})\n";
        $out .= " same => n,Set(NUM=\${EXTEN})\n";
        $out .= " same => n,GotoIf(\$[\"\${NUM:0:1}\" = \"+\"]?dial)\n";
        $out .= " same => n,GotoIf(\$[\"\${NUM:0:2}\" = \"00\"]?intl)\n";
        $out .= " same => n,GotoIf(\$[\"\${NUM:0:1}\" = \"0\"]?national)\n";
        $out .= " same => n,Set(NUM=+{$cc}\${NUM})\n";
        $out .= " same => n,Goto(dial)\n";
        $out .= " same => n(intl),Set(NUM=+\${NUM:2})\n";
        $out .= " same => n,Goto(dial)\n";
        $out .= " same => n(national),Set(NUM=+{$cc}\${NUM:1})\n";
        $out .= " same => n(dial),Dial(PJSIP/\${NUM}@twocans-trunk,60)\n";
        $out .= " same => n,Hangup()\n";

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
        $out .= " same => n,Playback(" . self::BLOCKED_MESSAGE . ")\n";
        $out .= " same => n,Playback(" . self::ASK_PROMPT . ")\n";
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
            $window = $c['sos'] ? '' : ($this->windowCondition($c) ?? '');

            // Trunks present the caller in various shapes; accept all of them.
            foreach (array_unique([$number, $digits, $national]) as $pattern) {
                $out .= "exten => {$pattern},1,Set(CALLER_NAME={$name})\n";
                $out .= " same => n,Set(CALLER_ALLOWED=" . ($c['allow_in'] ? '1' : '0') . ")\n";
                $out .= " same => n,Set(CALLER_SOS=" . ($c['sos'] ? '1' : '0') . ")\n";
                $out .= " same => n,Set(CALLER_WINDOW={$window})\n";
                $out .= " same => n,Return()\n";
            }
        }

        if (!$any) {
            $out .= "; (nobody on the allowlist yet)\n";
        }

        // Anyone not listed above.
        $out .= "exten => _X.,1,Set(CALLER_NAME=Unknown number)\n";
        $out .= " same => n,Set(CALLER_ALLOWED=0)\n";
        $out .= " same => n,Set(CALLER_SOS=0)\n";
        $out .= " same => n,Set(CALLER_WINDOW=)\n";
        $out .= " same => n,Return()\n";

        return $out;
    }

    /**
     * Calls arriving from the outside world.
     *
     * The order is the product in miniature: work out who is calling, refuse
     * anyone not on the list, let an SOS contact through regardless, otherwise
     * respect bedtime and their call window — and only then ring the phones
     * that are allowed to receive at this hour.
     */
    private function renderIncomingDialplan(array $devices, array $contacts): string
    {
        $settings = new SettingsRepository();
        $house = self::HOUSE_MAILBOX;
        $vm = self::VOICEMAIL_CONTEXT;

        $out = "\n[twocans-incoming]\n";
        $out .= "; A call from the trunk. \${EXTEN} is our own number; the person\n";
        $out .= "; calling is \${CALLERID(num)}.\n";
        $out .= "exten => _X.,1,NoOp(twocans: inbound from \${CALLERID(num)})\n";
        $out .= " same => n,Set(CDR(userfield)=inbound)\n";
        $out .= " same => n,Gosub(twocans-callers,\${CALLERID(num)},1)\n";
        $out .= " same => n,Set(CALLERID(name)=\${CALLER_NAME})\n";

        $out .= "\n; Not on the allowlist: say so kindly and log it.\n";
        $out .= " same => n,GotoIf(\$[\"\${CALLER_ALLOWED}\" = \"1\"]?allowed)\n";
        $out .= " same => n,Set(CDR(userfield)=blocked)\n";
        $out .= " same => n,Answer()\n";
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(" . self::BLOCKED_MESSAGE . ")\n";
        $out .= " same => n,Hangup()\n";

        $out .= "\n; An SOS contact skips bedtime and their call window entirely.\n";
        $out .= " same => n(allowed),GotoIf(\$[\"\${CALLER_SOS}\" = \"1\"]?ring)\n";

        if ($settings->quietHours()) {
            $out .= "\n; Bedtime mode: the line sleeps, so take a message instead.\n";
            $out .= " same => n,GotoIfTime(" . $this->timeCondition($settings->quietTimeRange()) . "?quiet)\n";
        }

        $out .= "\n; Their own call window, if they have one.\n";
        $out .= " same => n,GotoIf(\$[\"\${CALLER_WINDOW}\" = \"\"]?ring)\n";
        $out .= " same => n,GotoIfTime(\${CALLER_WINDOW}," . self::timezone() . "?ring)\n";
        $out .= " same => n,Goto(quiet)\n";

        $out .= "\n; Ring every phone that may receive right now. A phone is\n";
        $out .= "; skipped if incoming calls are off for it, or the clock is\n";
        $out .= "; outside the hours set on its own page.\n";
        $out .= " same => n(ring),Set(TARGETS=)\n";

        $ringable = 0;
        foreach ($devices as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['sipUsername'] === '' || !$d['available'] || !$d['allowIn']) {
                continue;
            }
            $ringable++;
            $label = 'dev' . $d['id'];
            $hours = $d['timeFrom'] . '-' . $d['timeTo'] . ',*,*,*';

            $out .= " same => n,GotoIfTime(" . $this->timeCondition($hours) . "?on{$label})\n";
            $out .= " same => n,Goto(off{$label})\n";
            $out .= " same => n(on{$label}),Set(TARGETS=\${TARGETS}&PJSIP/{$d['sipUsername']})\n";
            $out .= " same => n(off{$label}),NoOp({$d['name']} considered)\n";
        }

        if ($ringable === 0) {
            $out .= " same => n,NoOp(no phone can receive calls)\n";
        }

        $out .= "\n; Nothing available to ring — straight to the house mailbox.\n";
        $out .= " same => n,GotoIf(\$[\"\${TARGETS}\" = \"\"]?quiet)\n";
        $out .= " same => n,MixMonitor(\${UNIQUEID}." . self::RECORDING_FORMAT . ",b)\n";
        // TARGETS is built with a leading &, so trim it off.
        $out .= " same => n,Dial(\${TARGETS:1},30)\n";
        $out .= " same => n,Goto(quiet)\n";

        $out .= "\n; Nobody answered, or the line is asleep.\n";
        $out .= " same => n(quiet),VoiceMail({$house}@{$vm},u)\n";
        $out .= " same => n,Hangup()\n";

        return $out;
    }

    private function renderDialplan(array $rows): string
    {
        $greeting = self::GREETING;

        $out = $this->header('Device dialplan');
        $out .= "\n[twocans-devices]\n\n";

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
            $out .= " same => n,MixMonitor(\${UNIQUEID}." . self::RECORDING_FORMAT . ",b)\n";
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

        $out .= "\n";
        $out .= "; Outbound trunk. The provider authenticates the call by its source IP\n";
        $out .= "; (Twilio's IP Access Control List, or SIP.IO's trunk ACL), so there is\n";
        $out .= "; no SIP auth or registration section here.\n";
        $out .= "[twocans-trunk]\n";
        $out .= "type = endpoint\n";
        $out .= "context = twocans-incoming\n";
        $out .= "disallow = all\n";
        $out .= "allow = ulaw\n";
        $out .= "allow = alaw\n";
        $out .= "; Media through Asterisk so outbound calls can be recorded like the rest.\n";
        $out .= "direct_media = no\n";
        $out .= "aors = twocans-trunk\n";
        $out .= "\n";
        $out .= "[twocans-trunk]\n";
        $out .= "type = aor\n";
        $out .= "contact = sip:{$host}\n";

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
        $out .= " same => n,Set(CALLERID(num)={$number})\n";
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
