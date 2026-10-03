<?php
declare(strict_types=1);

/**
 * The games played on the quiz and the games line (see Games): noted by the
 * dialplan as they're played (PjsipConfig::renderQuizContext) and brought in
 * here, for the Games page — who played what, and how they got on.
 */
final class Quiz
{
    /** Bring in the games the dialplan has noted. Returns how many were new. */
    public function import(): int
    {
        try {
            $ami = new Ami();
            $ami->connect();
            $notes = $ami->takeNotes(PjsipConfig::QUIZ_FAMILY);
            $ami->disconnect();
        } catch (Throwable) {
            return 0;
        }

        return $this->record($notes);
    }

    /**
     * Save notes "<endpoint>|<table>|<score>|<asked>|<epoch>|<game>", keyed by
     * the call (a note without a game is times tables, as they all were). A
     * game still being played is noted again after each answer, so a second
     * note for the same call brings its score up to date.
     *
     * The table is what the game was about: times tables' table (0 a mix),
     * what sums went up to, what bonds made (0 a mix). Guess my number notes
     * the goes as its score, and asked 1 once it's guessed.
     *
     * @param array<string,string> $notes
     */
    public function record(array $notes): int
    {
        $byUsername = [];
        foreach ((new DeviceRepository())->all() as $row) {
            $byUsername[(string) $row['sip_username']] = (int) $row['id'];
        }

        $upsert = Database::pdo()->prepare(
            'INSERT INTO quiz_games (uniqueid, game, device_id, table_no, score, asked, played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), asked = VALUES(asked)'
        );
        $added = 0;
        foreach ($notes as $uniqueid => $note) {
            [$endpoint, $table, $score, $asked, $at, $game] = array_pad(explode('|', $note), 6, '');
            $upsert->execute([
                $uniqueid,
                isset(Games::GAMES[$game]) ? $game : 'times',
                $byUsername[$endpoint] ?? null,
                max(0, min(255, (int) $table)),
                max(0, min(255, (int) $score)),
                max(0, min(255, (int) $asked)),
                date('Y-m-d H:i:s', (int) $at ?: time()),
            ]);
            $added += $upsert->rowCount() === 1 ? 1 : 0;
        }

        return $added;
    }

    /** What a game was about, in words: "7 times table", "Sums to 20", "Bonds to 10". */
    public static function describe(string $game, int $table): string
    {
        return match ($game) {
            'times' => $table === 0 ? 'Times tables, a mix' : $table . ' times table',
            'sums' => 'Sums to ' . $table,
            'bonds' => $table === 0 ? 'Number bonds, a mix' : 'Number bonds to ' . $table,
            default => Games::label($game),
        };
    }

    /**
     * The latest games, newest first. A guess my number not yet guessed isn't
     * one, nor a quiz with nothing answered.
     *
     * @return array<int,array{phone:string,deviceId:?int,game:string,about:string,result:string,when:string,perfect:bool,score:int,asked:int}>
     */
    public function recent(int $limit = 30): array
    {
        $rows = Database::pdo()->query(
            'SELECT q.*, d.name AS phone
               FROM quiz_games q LEFT JOIN devices d ON d.id = q.device_id
              WHERE q.asked > 0
              ORDER BY q.played_at DESC, q.id DESC
              LIMIT ' . max(1, $limit)
        )->fetchAll();

        return array_map(static function (array $r): array {
            $game = (string) $r['game'];
            $score = (int) $r['score'];
            $asked = (int) $r['asked'];
            $guess = $game === 'guess';

            return [
                'phone' => (string) ($r['phone'] ?? 'A phone'),
                'deviceId' => $r['device_id'] === null ? null : (int) $r['device_id'],
                'game' => $game,
                'about' => self::describe($game, (int) $r['table_no']),
                'result' => $guess ? ($score === 1 ? 'first go!' : $score . ' goes') : $score . '/' . $asked,
                'when' => date('D j M, g:ia', (int) strtotime((string) $r['played_at'])),
                'perfect' => $guess ? $score <= 7 : $score === $asked,
                'score' => $score,
                'asked' => $asked,
            ];
        }, $rows);
    }

    /**
     * Each phone's last 30 days: games played, a bar for each kind of question
     * (each times table, sums, bonds, riddles — the share right), and guess my
     * number's average goes.
     *
     * @return array<int,array{phone:string,games:int,bars:array<int,array{label:string,title:string,right:int,asked:int}>,guesses:int,goes:?float}>
     */
    public function summary(int $days = 30): array
    {
        $st = Database::pdo()->prepare(
            'SELECT q.device_id, d.name AS phone, q.game, q.table_no, COUNT(*) AS games,
                    SUM(q.score) AS score, SUM(q.asked) AS asked
               FROM quiz_games q LEFT JOIN devices d ON d.id = q.device_id
              WHERE q.asked > 0 AND q.played_at >= (NOW() - INTERVAL ? DAY)
              GROUP BY q.device_id, d.name, q.game, q.table_no
              ORDER BY d.name, FIELD(q.game, ' . implode(',', array_map(static fn(string $g): string => "'" . $g . "'", array_keys(Games::GAMES))) . '), q.table_no'
        );
        $st->execute([$days]);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $key = (int) ($r['device_id'] ?? 0);
            $out[$key] ??= ['phone' => (string) ($r['phone'] ?? 'A phone'), 'games' => 0, 'bars' => [], 'guesses' => 0, 'goes' => null];
            $game = (string) $r['game'];
            $table = (int) $r['table_no'];
            $out[$key]['games'] += (int) $r['games'];
            if ($game === 'guess') {
                $out[$key]['guesses'] += (int) $r['games'];
                $out[$key]['goes'] = round((int) $r['score'] / max(1, (int) $r['games']), 1);
                continue;
            }
            $out[$key]['bars'][] = [
                'label' => match ($game) {
                    'times' => $table === 0 ? '× mix' : '×' . $table,
                    'sums' => '+− ' . $table,
                    'bonds' => 'Bonds ' . ($table ?: 'mix'),
                    default => 'Riddles',
                },
                'title' => self::describe($game, $table),
                'right' => (int) $r['score'],
                'asked' => (int) $r['asked'],
            ];
        }

        return array_values($out);
    }
}
