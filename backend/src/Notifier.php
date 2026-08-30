<?php
declare(strict_types=1);

/**
 * Turns household events into an email (via Mailgun) and a heartbeat (for
 * Uptime Kuma). Runs once a minute; each event is reported only once.
 */
final class Notifier
{
    /** @return array{ran:bool,sections:int,emailed:int,error:?string} */
    public function run(): array
    {
        $repo = new NotificationRepository();
        $config = $repo->get();

        if (!$config['enabled']) {
            return ['ran' => false, 'sections' => 0, 'emailed' => 0, 'error' => null];
        }

        $firstRun = $config['lastRunAt'] === null;
        $error = null;

        // Heartbeat first: it must fire even when there is nothing to email,
        // because the *absence* of heartbeats is how Uptime Kuma learns the box
        // is down.
        if ($config['kumaUrl'] !== '') {
            $hb = UptimeKuma::heartbeat($config['kumaUrl']);
            if (!$hb['ok']) {
                $error = $hb['error'] ?? 'Uptime Kuma heartbeat failed';
            }
        }

        $sections = [];
        if ($config['notifyAsks']) {
            $sections = array_merge($sections, $this->newAsks($repo, $firstRun));
        }
        if ($config['notifyOffline']) {
            $sections = array_merge($sections, $this->newOffline($repo));
        }
        if ($config['notifyLowCredit']) {
            $sections = array_merge($sections, $this->lowCredit($repo));
        }

        $emailed = 0;
        if ($sections !== [] && $config['mailgunConfigured']) {
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

        return [['title' => 'Someone asked to call a number that is not on the list', 'lines' => $lines]];
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

        return $lines === [] ? [] : [['title' => 'A phone went offline', 'lines' => $lines]];
    }

    /** @return array<int,array{title:string,lines:array<int,string>}> */
    private function lowCredit(NotificationRepository $repo): array
    {
        $low = (new TrunkRepository())->isLowCredit();
        $alerted = $repo->lowCreditAlerted();

        if ($low && !$alerted) {
            $repo->setLowCreditAlerted(true);

            return [['title' => 'Call credit is running low', 'lines' => ['Top up so calls do not get cut off']]];
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
