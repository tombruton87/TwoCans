<?php
declare(strict_types=1);

/**
 * Turns household events into an email (via Mailgun), a notification on the
 * grown-ups' phones and computers (Web Push — see Push), and a heartbeat (for
 * Uptime Kuma). Runs once a minute; each event is reported only once.
 *
 * Notifications on a device need nothing set up but the device itself: they
 * go out whenever a grown-up has asked for them, email or not.
 */
final class Notifier
{
    /** @return array{ran:bool,sections:int,emailed:int,error:?string} */
    public function run(): array
    {
        $repo = new NotificationRepository();
        $config = $repo->get();
        $push = new Push();
        $pushing = $push->all() !== [];

        if (!$config['enabled'] && !$pushing) {
            return ['ran' => false, 'sections' => 0, 'emailed' => 0, 'error' => null];
        }
        // Email and the heartbeat are for when notifications are switched on;
        // a device that asked to be told is told regardless.
        $emailing = $config['enabled'];

        $firstRun = $config['lastRunAt'] === null;
        $error = null;

        // Bring the log up to date first. The app only imports on a page load,
        // so without this an ask or a message waits for somebody to open the
        // app before anyone is told about it.
        $this->catchUp();

        // Heartbeat first: it must fire even when there is nothing to email,
        // because the *absence* of heartbeats is how Uptime Kuma learns the box
        // is down.
        if ($emailing && $config['kumaUrl'] !== '') {
            $hb = UptimeKuma::heartbeat($config['kumaUrl']);
            if (!$hb['ok']) {
                $error = $hb['error'] ?? 'Uptime Kuma heartbeat failed';
            }
        }

        $mailer = static function () use ($repo, $config, $emailing): ?Mailgun {
            return $emailing && $config['mailgunConfigured']
                ? new Mailgun($repo->apiKey() ?? '', $config['region'], $config['domain'])
                : null;
        };

        // An emergency call goes out on its own, straight away, with a subject
        // nobody will skim past.
        if ($config['notifyEmergency']) {
            $lines = $this->emergencyCalls();
            if ($lines !== [] && $pushing) {
                $push->send('Emergency number dialled', implode("\n", $lines), url(['screen' => 'calllog']), true);
            }
            if ($lines !== [] && ($mail = $mailer()) !== null) {
                $res = $mail->send($config['from'], $config['to'], 'twocans — EMERGENCY number dialled',
                    $this->renderEmail([['title' => 'A phone dialled an emergency number', 'lines' => $lines]]));
                if (!$res['ok']) {
                    $error = $res['error'] ?? 'Could not send the emergency email';
                }
            }
        }

        // The week in a few lines, Sunday evening.
        if ($config['notifyDigest'] && $this->digestDue($repo)) {
            if (($mail = $mailer()) !== null) {
                $res = $mail->send($config['from'], $config['to'], 'twocans — your week', $this->renderDigest());
                if ($res['ok']) {
                    $repo->markDigestSent();
                } else {
                    $error = $res['error'] ?? 'Could not send the weekly summary';
                }
            }
        }

        $sections = [];
        if ($config['notifyMessages']) {
            $sections = array_merge($sections, $this->newMessages($repo, $firstRun));
        }
        if ($config['notifyAsks']) {
            $sections = array_merge($sections, $this->newAsks($repo, $firstRun));
        }
        if ($config['notifyOffline']) {
            $sections = array_merge($sections, $this->newOffline($repo), $this->leftOffHook());
        }
        if ($config['notifyLowCredit']) {
            $sections = array_merge($sections, $this->lowCredit($repo));
        }

        // Each on its own notification, opening the page it's about.
        if ($pushing) {
            foreach ($sections as $section) {
                $lines = array_slice($section['lines'], 0, 3);
                $more = count($section['lines']) - count($lines);
                $push->send($section['title'], implode("\n", $lines) . ($more > 0 ? "\n…and {$more} more" : ''),
                    $section['url'] ?? url(['screen' => 'dashboard']), false, null, $section['tag'] ?? 'twocans');
            }
        }

        $emailed = 0;
        if ($emailing && $sections !== [] && $config['mailgunConfigured']) {
            try {
                $mail = new Mailgun($repo->apiKey() ?? '', $config['region'], $config['domain']);
                $subject = 'twocans — ' . count($sections) . ' thing' . (count($sections) === 1 ? '' : 's') . ' need your attention';
                $res = $mail->send($config['from'], $config['to'], $subject, $this->renderEmail($sections));
                if ($res['ok']) {
                    $emailed = 1;
                } else {
                    $error = $res['error'] ?? 'Could not send email';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $repo->recordRun($error);

        return ['ran' => true, 'sections' => count($sections), 'emailed' => $emailed, 'error' => $error];
    }

    // -------------------------------------------------------------- events

    /** Import calls, messages and asks, so the events below are current. */
    private function catchUp(): void
    {
        foreach ([
            static fn() => (new CallRepository(new DeviceRepository()))->import(),
            static fn() => (new VoicemailRepository())->import(),
            static fn() => (new CallRequestRepository())->import(),
        ] as $step) {
            try {
                $step();
            } catch (Throwable) {
                // One failing import shouldn't stop the others, or the heartbeat.
            }
        }
    }

    /**
     * Emergency calls noted by the dialplan since the last run — see
     * PjsipConfig::EMERGENCY_FAMILY. Each note is cleared once read.
     *
     * @return array<int,string>
     */
    private function emergencyCalls(): array
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $output = $ami->command('database show ' . PjsipConfig::EMERGENCY_FAMILY);
        } catch (Throwable) {
            return [];
        }

        $byUsername = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $byUsername[(string) $row['sip_username']] = (string) $row['name'];
        }

        $lines = [];
        foreach ($output as $line) {
            // "/tc_emergency/1790511012.63                      : kitchen-1a2b|999|1790511012"
            if (!preg_match('#^/' . preg_quote(PjsipConfig::EMERGENCY_FAMILY, '#') . '/(\S+)\s*:\s*(.*)$#', trim((string) $line), $m)) {
                continue;
            }
            [$endpoint, $number, $at] = array_pad(explode('|', trim($m[2])), 3, '');
            $phone = $byUsername[$endpoint] ?? ($endpoint !== '' ? $endpoint : 'A phone');
            $lines[] = $phone . ' dialled ' . $number . ' at ' . date('g:ia \o\n D j M', (int) $at ?: time());
            $ami->command('database del ' . PjsipConfig::EMERGENCY_FAMILY . ' ' . $m[1]);
        }
        $ami->disconnect();

        return $lines;
    }

    /** @return array<int,array{title:string,lines:array<int,string>}> */
    private function newMessages(NotificationRepository $repo, bool $firstRun): array
    {
        $last = $repo->lastMessageId();
        $rows = array_filter(
            (new VoicemailRepository())->unrecognised(50),
            static fn(array $r): bool => (int) $r['id'] > $last
        );
        if ($rows === []) {
            return [];
        }

        $max = max(array_map(static fn(array $r): int => (int) $r['id'], $rows));
        $repo->setLastMessageId($max);
        // Turning notifications on isn't news about every old message; the
        // migration starts the bookmark at the newest one for the same reason.
        if ($firstRun) {
            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $said = trim(preg_replace('/\s+/', ' ', (string) ($row['transcript'] ?? '')) ?? '');
            if (mb_strlen($said) > 120) {
                $said = mb_substr($said, 0, 117) . '…';
            }
            $lines[] = (string) $row['peer_number'] . ($said !== '' ? ' — “' . $said . '”' : ' — no transcript yet');
        }

        return [['title' => 'Somebody not on the list left a message', 'lines' => $lines,
            'url' => url(['screen' => 'voicemail']), 'tag' => 'message']];
    }

    /** Sunday from 6pm, once a week. */
    private function digestDue(NotificationRepository $repo): bool
    {
        if (date('N') !== '7' || (int) date('G') < 18) {
            return false;
        }
        $last = $repo->lastDigestAt();

        return $last === null || strtotime($last) < strtotime('-6 days');
    }

    /** The last seven days, in plain words. */
    private function renderDigest(): string
    {
        $pdo = Database::pdo();
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));

        $out = ["twocans — the week on your family line\n"];

        $st = $pdo->prepare(
            "SELECT d.name, COUNT(*) AS calls, COALESCE(SUM(c.billsec), 0) AS secs,
                    SUM(c.status = 'blocked') AS blocked
               FROM calls c JOIN devices d ON d.id = c.device_id
              WHERE c.started_at >= ? AND " . CallRepository::shownSql('c') . "
              GROUP BY d.id, d.name ORDER BY d.name"
        );
        $st->execute([$since]);
        $out[] = 'Each phone';
        $any = false;
        foreach ($st->fetchAll() as $row) {
            $any = true;
            $out[] = sprintf('  - %s: %d call%s, %d minute%s talking%s',
                $row['name'], $row['calls'], (int) $row['calls'] === 1 ? '' : 's',
                intdiv((int) $row['secs'] + 59, 60), intdiv((int) $row['secs'] + 59, 60) === 1 ? '' : 's',
                (int) $row['blocked'] > 0 ? ', ' . (int) $row['blocked'] . ' blocked' : '');
        }
        if (!$any) {
            $out[] = '  - No calls this week.';
        }
        $out[] = '';

        $st = $pdo->prepare(
            "SELECT peer_name, COUNT(*) AS n FROM calls
              WHERE started_at >= ? AND status = 'done' AND contact_id IS NOT NULL
              GROUP BY peer_name ORDER BY n DESC LIMIT 5"
        );
        $st->execute([$since]);
        $top = $st->fetchAll();
        if ($top !== []) {
            $out[] = 'Who they talked to most';
            foreach ($top as $row) {
                $out[] = '  - ' . $row['peer_name'] . ' (' . (int) $row['n'] . ')';
            }
            $out[] = '';
        }

        $waiting = count((new UnknownQueue())->pending()) + count(array_filter(
            (new UnknownQueue())->tried(),
            static fn(array $t): bool => $t['dismiss'] !== null
        ));
        if ($waiting > 0) {
            $out[] = $waiting . ' thing' . ($waiting === 1 ? ' is' : 's are') . ' waiting for a decision in the app.';
        }

        return implode("\n", $out);
    }

    /** @return array<int,array{title:string,lines:array<int,string>}> */
    private function newAsks(NotificationRepository $repo, bool $firstRun): array
    {
        $lastId = $repo->lastAskId();
        $st = Database::pdo()->prepare(
            'SELECT id, number_e164, label FROM call_requests WHERE resolution IS NULL AND id > ? ORDER BY id ASC'
        );
        $st->execute([$lastId]);
        $rows = $st->fetchAll();

        if ($rows === []) {
            return [];
        }

        $max = $lastId;
        foreach ($rows as $row) {
            $max = max($max, (int) $row['id']);
        }

        // First ever run: the existing backlog is not "new" — start the
        // watermark here so enabling notifications doesn't email every old ask
        // at once.
        if ($firstRun) {
            $repo->setLastAskId($max);

            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $said = trim(preg_replace('/\s+/', ' ', (string) ($row['label'] ?? '')) ?? '');
            $lines[] = (string) $row['number_e164'] . ($said !== '' ? ' — ' . $said : '');
        }
        $repo->setLastAskId($max);

        return [['title' => 'Someone asked to call a number that is not on the list', 'lines' => $lines,
            'url' => url(['screen' => 'dashboard']), 'tag' => 'ask']];
    }

    /** @return array<int,array{title:string,lines:array<int,string>}> */
    private function newOffline(NotificationRepository $repo): array
    {
        $devices = new DeviceRepository();
        (new PjsipConfig($devices))->syncRegistrations();

        $lastOnline = $repo->lastOnline();
        $next = [];
        $lines = [];

        foreach ($devices->all() as $row) {
            $view = DeviceRepository::toView($row);
            if (!$view['available'] || $view['sipUsername'] === '') {
                continue;
            }
            $id = (int) $view['id'];
            $online = $view['online'];

            // Only a transition online → offline is worth an email; a phone
            // that has never signed in is "not set up", not "went offline".
            if (($lastOnline[$id] ?? 0) === 1 && !$online) {
                $lines[] = $view['name'] . ' has gone offline';
            }
            $next[$id] = $online ? 1 : 0;
        }

        $repo->setLastOnline($next);

        return $lines === [] ? [] : [['title' => 'A phone went offline', 'lines' => $lines,
            'url' => url(['screen' => 'phones']), 'tag' => 'offline']];
    }

    /**
     * Desk phones whose handset has been off the hook a while with nobody on
     * a call — so calls to them can't get through. Told once, until it's put
     * back (DeviceRepository::phoneEvent clears it).
     *
     * @return array<int,array{title:string,lines:array<int,string>,url:string,tag:string}>
     */
    private function leftOffHook(): array
    {
        $busy = array_column((new LiveCalls())->active(), 'deviceId');
        $out = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['offhookSince'] === null || (int) ($row['offhook_notified'] ?? 0) === 1
                || time() - $d['offhookSince'] < 300 || in_array($d['id'], $busy, true)) {
                continue;
            }
            Database::pdo()->prepare('UPDATE devices SET offhook_notified = 1 WHERE id = ?')->execute([$d['id']]);
            $out[] = ['title' => $d['name'] . "'s handset is off the hook",
                'lines' => ['Since ' . date('g:ia', $d['offhookSince']) . " — calls to it can't get through until it's put back."],
                'url' => url(['screen' => 'phones', 'device' => (string) $d['id']]), 'tag' => 'offhook-' . $d['id']];
        }

        return $out;
    }

    /** @return array<int,array{title:string,lines:array<int,string>}> */
    private function lowCredit(NotificationRepository $repo): array
    {
        $low = (new TrunkRepository())->isLowCredit();
        $alerted = $repo->lowCreditAlerted();

        if ($low && !$alerted) {
            $repo->setLowCreditAlerted(true);

            return [['title' => 'Call credit is running low', 'lines' => ['Top up so calls do not get cut off'],
                'url' => url(['screen' => 'trunk']), 'tag' => 'credit']];
        }
        if (!$low && $alerted) {
            $repo->setLowCreditAlerted(false);   // credit recovered; arm the next alert
        }

        return [];
    }

    // -------------------------------------------------------------- output

    /** @param array<int,array{title:string,lines:array<int,string>}> $sections */
    private function renderEmail(array $sections): string
    {
        $out = ["twocans — here is what needs a look:\n"];
        foreach ($sections as $section) {
            $out[] = $section['title'];
            foreach ($section['lines'] as $line) {
                $out[] = '  - ' . $line;
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }
}
