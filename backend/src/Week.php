<?php
declare(strict_types=1);

/**
 * A family's week on the line, for the This week page: for each child's
 * phone, who it called and who called it, how long it talked, what it
 * missed, the messages left for it and the games it played — and, for the
 * house, the keepsakes kept. Monday to Sunday, in the household's time zone.
 *
 * Only the family's calls count (CallRepository::shownSql): not announcements,
 * test calls or the test numbers.
 */
final class Week
{
    /** Monday 00:00 of the week $day falls in. */
    public static function start(DateTimeImmutable $day): DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify('monday this week');
    }

    /** The Monday a "?week=2026-W40" names, or this week's. */
    public static function fromParam(string $param, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone(PjsipConfig::timezone()));
        if (preg_match('/^(\d{4})-W(\d{2})$/', $param, $m) === 1) {
            $week = $now->setISODate((int) $m[1], max(1, min(53, (int) $m[2])));

            return self::start($week);
        }

        return self::start($now);
    }

    public static function param(DateTimeImmutable $monday): string
    {
        return $monday->format('o-\WW');
    }

    /**
     * Each child's phone's week — not phones in adult mode — and the house's.
     *
     * @return array{phones:array<int,array>,keepsakes:int,from:string,to:string}
     */
    public function summary(DateTimeImmutable $monday): array
    {
        $from = $monday->format('Y-m-d H:i:s');
        $to = $monday->modify('+7 days')->format('Y-m-d H:i:s');
        $pdo = Database::pdo();
        $shown = CallRepository::shownSql('c');

        $phones = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $d = DeviceRepository::toView($row);
            if ($d['adult'] || !$d['available']) {
                continue;
            }
            $phones[$d['id']] = [
                'id' => $d['id'], 'name' => $d['name'], 'photo' => $d['photo'],
                'callsOut' => 0, 'callsIn' => 0, 'missed' => 0, 'blocked' => 0, 'seconds' => 0,
                'people' => [], 'busiestDay' => null, 'messages' => 0, 'games' => 0, 'gamesRight' => 0, 'gamesAsked' => 0,
            ];
        }
        if ($phones === []) {
            return ['phones' => [], 'keepsakes' => 0, 'from' => $from, 'to' => $to];
        }
        $ids = implode(',', array_map('intval', array_keys($phones)));

        // Calls: answered both ways, missed (rang, nobody picked up) and refused.
        $st = $pdo->prepare(
            "SELECT c.device_id, c.direction, c.status, COUNT(*) AS n, SUM(c.billsec) AS secs
               FROM calls c
              WHERE c.device_id IN ($ids) AND c.started_at >= ? AND c.started_at < ? AND $shown
              GROUP BY c.device_id, c.direction, c.status"
        );
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $p = &$phones[(int) $r['device_id']];
            if ($r['status'] === 'done') {
                $p[$r['direction'] === 'out' ? 'callsOut' : 'callsIn'] += (int) $r['n'];
                $p['seconds'] += (int) $r['secs'];
            } elseif ($r['status'] === 'missed' && $r['direction'] === 'in') {
                $p['missed'] += (int) $r['n'];
            } elseif ($r['status'] === 'blocked') {
                $p['blocked'] += (int) $r['n'];
            }
            unset($p);
        }

        // Who each talked to most: answered calls, either way — people on the
        // call list, or the house's other phones; not the joke line, the
        // radio, keys pressed in a menu, or itself.
        $own = [];
        $houseNames = [];
        foreach ((new DeviceRepository())->all() as $row) {
            if ((string) $row['extension'] !== '') {
                $own[(int) $row['id']] = (string) $row['extension'];
                $houseNames[(string) $row['extension']] = (string) $row['name'];
            }
        }
        $st = $pdo->prepare(
            "SELECT c.device_id, c.peer_number, c.contact_id, COALESCE(NULLIF(c.peer_name, ''), c.peer_number) AS who,
                    COUNT(*) AS n, SUM(c.billsec) AS secs
               FROM calls c
              WHERE c.device_id IN ($ids) AND c.started_at >= ? AND c.started_at < ? AND c.status = 'done' AND $shown
              GROUP BY c.device_id, c.peer_number, c.contact_id, who
              ORDER BY n DESC, secs DESC"
        );
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $houseNumber = in_array((string) $r['peer_number'], $own, true)
                && (string) $r['peer_number'] !== ($own[(int) $r['device_id']] ?? '');
            if ($r['contact_id'] === null && !$houseNumber) {
                continue;
            }
            $p = &$phones[(int) $r['device_id']];
            if (count($p['people']) < 3) {
                // A house phone by its own name: the log names a call between
                // two of them after the one it was made from.
                $name = $houseNumber ? $houseNames[(string) $r['peer_number']] : (string) $r['who'];
                $p['people'][] = ['name' => $name, 'calls' => (int) $r['n'], 'seconds' => (int) $r['secs']];
            }
            unset($p);
        }

        // Its busiest day.
        $st = $pdo->prepare(
            "SELECT c.device_id, DATE(c.started_at) AS day, COUNT(*) AS n
               FROM calls c
              WHERE c.device_id IN ($ids) AND c.started_at >= ? AND c.started_at < ? AND c.status = 'done' AND $shown
              GROUP BY c.device_id, day
              ORDER BY n DESC"
        );
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $phones[(int) $r['device_id']]['busiestDay'] ??= ['day' => date('l', (int) strtotime((string) $r['day'])), 'calls' => (int) $r['n']];
        }

        // Messages left for it.
        $st = $pdo->prepare("SELECT device_id, COUNT(*) FROM voicemails WHERE device_id IN ($ids) AND left_at >= ? AND left_at < ? GROUP BY device_id");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $n]) {
            $phones[(int) $id]['messages'] = (int) $n;
        }

        // Games: played, and the share right (guess my number counts as played only).
        $st = $pdo->prepare(
            "SELECT device_id, COUNT(*) AS games,
                    SUM(IF(game = 'guess', 0, score)) AS right_answers, SUM(IF(game = 'guess', 0, asked)) AS asked
               FROM quiz_games
              WHERE device_id IN ($ids) AND asked > 0 AND played_at >= ? AND played_at < ?
              GROUP BY device_id"
        );
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $p = &$phones[(int) $r['device_id']];
            $p['games'] = (int) $r['games'];
            $p['gamesRight'] = (int) $r['right_answers'];
            $p['gamesAsked'] = (int) $r['asked'];
            unset($p);
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM keepsakes WHERE kept_at >= ? AND kept_at < ?');
        $st->execute([$from, $to]);

        return ['phones' => array_values($phones), 'keepsakes' => (int) $st->fetchColumn(), 'from' => $from, 'to' => $to];
    }

    /** "1h 20m", "12m", "45s" — talk time, the way a grown-up reads it. */
    public static function talk(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $m = intdiv($seconds, 60);

        return $m < 60 ? $m . 'm' : intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
    }
}
