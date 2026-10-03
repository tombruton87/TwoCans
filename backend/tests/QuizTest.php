<?php
declare(strict_types=1);

/**
 * The times tables quiz: how it says a number, what it asks, its number, and
 * the games it notes — against the database, in a transaction rolled back after.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        // The defaults, whatever this household has chosen (rolled back after).
        $pdo->exec("DELETE FROM settings WHERE name IN ('games_number', 'quiz_number', 'joke_number', 'sleeps_number', 'radio_number')");
        SettingsRepository::forget();
        $fn();
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
    }
};

return [
    test('a number is said the way a child would: forty two, one hundred and twelve', function () {
        assertSame(['n-0'], PjsipConfig::quizSay(0));
        assertSame(['n-7'], PjsipConfig::quizSay(7));
        assertSame(['n-20'], PjsipConfig::quizSay(20));
        assertSame(['n-20', 'n-1'], PjsipConfig::quizSay(21));
        assertSame(['n-40'], PjsipConfig::quizSay(40));
        assertSame(['n-90', 'n-9'], PjsipConfig::quizSay(99));
        assertSame(['n-100'], PjsipConfig::quizSay(100));
        assertSame(['n-100-and', 'n-1'], PjsipConfig::quizSay(101));
        assertSame(['n-100-and', 'n-40', 'n-4'], PjsipConfig::quizSay(144));
    }),
    test('every clip the quiz plays ships in storage/defaults/quiz', function () {
        // The repository's copy, or — in the container — where storage/ is mounted.
        $dir = is_dir(__DIR__ . '/../../storage/defaults/quiz')
            ? __DIR__ . '/../../storage/defaults/quiz'
            : '/var/lib/twocans/defaults/quiz';
        if (!is_dir($dir)) {
            return; // an image on its own, with no storage/ to look in
        }
        $clips = ['welcome', 'pick', 'how', 'whats', 'times', 'nudge', 'wrong', 'right-1', 'right-2', 'right-3',
            'right-4', 'you-got', 'right-out-of', 'perfect', 'good', 'bye',
            'games-menu', 'sums-welcome', 'plus', 'take-away', 'bonds-welcome', 'bonds-what-goes', 'bonds-to-make',
            'guess-welcome', 'higher', 'lower', 'guess-got-it', 'guess-goes', 'guess-first',
            'riddles-welcome', 'riddle-wrong', 'riddle-nudge',
            // The radio's menu and bedtime, in the same voice.
            'radio-welcome', 'radio-all', 'radio-bedtime', 'radio-goodnight',
            // The kitchen timer and "what time is it?", in the same voice.
            'timer-ask', 'timer-set', 'timer-minutes', 'timer-set-one', 'timer-done', 'timer-cancelled',
            'clock-its', 'clock-oclock', 'clock-past', 'clock-to', 'clock-quarter-past', 'clock-half-past',
            'clock-quarter-to', 'bedtime-in', 'bedtime-in-an-hour-and', 'bedtime-in-an-hour', 'bedtime-minutes', 'bedtime-now',
            'silly-say', 'silly-chipmunk', 'silly-giant', 'silly-again'];
        foreach (range(1, Radio::MAX_STATIONS) as $n) {
            array_push($clips, 'press-' . $n, 'station-' . $n);
        }
        foreach (array_keys(Games::RIDDLES) as $n) {
            $clips[] = 'riddle-' . $n;
        }
        foreach (range(0, 144) as $n) {
            array_push($clips, ...PjsipConfig::quizSay($n));
        }
        foreach (array_unique($clips) as $clip) {
            assertTrue(is_file("{$dir}/{$clip}.wav"), "{$clip}.wav is missing");
        }
    }),
    test('the tables in a mix are 2 to 12, all of them when none are chosen', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame(range(2, 12), $s->quizTables());
            $s->setQuizTables(['5', 2, 10, 13, 1, 'x']);
            SettingsRepository::forget();
            assertSame([2, 5, 10], (new SettingsRepository())->quizTables());
            $s->setQuizTables([]);
            SettingsRepository::forget();
            assertSame(range(2, 12), (new SettingsRepository())->quizTables());
            $s->setQuizQuestions(7); // not a choice
            SettingsRepository::forget();
            assertSame(10, (new SettingsRepository())->quizQuestions());
        });
    }),
    test('the quiz and the joke line can\'t share a number, or take a fixed one', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame(null, $s->quizNumberProblem($s->quizNumber()));
            assertTrue($s->quizNumberProblem($s->jokeNumber()) !== null, 'the joke line');
            assertTrue($s->jokeNumberProblem($s->quizNumber()) !== null, 'the quiz');
            assertTrue($s->quizNumberProblem('999') !== null, 'emergency');
            assertTrue($s->quizNumberProblem('700') !== null, 'messages');
            assertTrue((new ContactRepository())->speedDialProblem($s->quizNumber()) !== null, 'nobody can have it as a speed dial');
        });
    }),
    test('the dialplan dials the quiz and asks from the chosen mix', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            $s->setQuizTables([3, 4]);
            $s->setQuizQuestions(5);
            SettingsRepository::forget();
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains('exten => ' . $s->quizNumber() . ",1,NoOp(twocans: the times tables quiz)\n same => n,GotoIf(\$[\"\${TC_PAUSED}\" = \"1\"]?" . PjsipConfig::PAUSED_CONTEXT . ",s,1)\n same => n,Goto(" . PjsipConfig::QUIZ_CONTEXT . ',s,1)', $plan);
            assertContains('Set(TABLES=3,4)', $plan);
            assertContains('${CUT(TABLES,\\,,${RAND(1,2)})}', $plan);
            assertContains('GotoIf($[${ASKED} >= 5]?done)', $plan);
            assertContains('exten => 144,1,Return(' . PjsipConfig::QUIZ_SOUNDS . '/n-100-and&', $plan);
        });
    }),
    test('a game is noted as it goes, and a later note brings its score up to date', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test landing', 'ghp621', 'udp');
            $quiz = new Quiz();
            $user = (string) $phone['sip_username'];
            assertSame(1, $quiz->record(['1790000000.1' => "{$user}|7|2|3|1790000000"]));
            assertSame(0, $quiz->record(['1790000000.1' => "{$user}|7|8|10|1790000000"]));
            $games = array_values(array_filter($quiz->recent(), static fn(array $g): bool => $g['deviceId'] === (int) $phone['id']));
            assertSame(1, count($games));
            assertSame(8, $games[0]['score']);
            assertSame(10, $games[0]['asked']);
            assertSame('7 times table', $games[0]['about']);
        });
    }),
    test('the games line, the quiz and the joke line each keep a number of their own', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame('4263', $s->gamesNumber());
            assertSame(null, $s->gamesNumberProblem('4263'));
            assertTrue($s->gamesNumberProblem($s->quizNumber()) !== null, 'the quiz');
            assertTrue($s->gamesNumberProblem($s->jokeNumber()) !== null, 'the joke line');
            assertTrue($s->quizNumberProblem('4263') !== null, 'the games line');
            assertTrue($s->jokeNumberProblem('4263') !== null, 'the games line');
            assertSame('The games line', PjsipConfig::testNumbers()['4263']['label'] ?? null);
        });
    }),
    test('sums go to 10 or 20, and bonds make 10, 20 or both', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame(10, $s->sumsMax());
            $s->setSumsMax(15);
            assertSame(10, (new SettingsRepository())->sumsMax());
            $s->setSumsMax(20);
            assertSame(20, (new SettingsRepository())->sumsMax());
            assertSame([10], $s->bondsTo());
            $s->setBondsTo(['20', 10, 30]);
            assertSame([10, 20], (new SettingsRepository())->bondsTo());
            $s->setBondsTo([]);
            assertSame([10], (new SettingsRepository())->bondsTo());
        });
    }),
    test('the games line offers every game, and each is in the dialplan', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            $s->setSumsMax(20);
            $s->setBondsTo([10, 20]);
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains("exten => 4263,1,NoOp(twocans: the games line)\n same => n,GotoIf(\$[\"\${TC_PAUSED}\" = \"1\"]?" . PjsipConfig::PAUSED_CONTEXT . ",s,1)\n same => n,Goto(" . PjsipConfig::GAMES_CONTEXT . ',s,1)', $plan);
            foreach (Games::GAMES as $game => $g) {
                assertContains("GotoIf(\$[\"\${G}\" = \"{$g['key']}\"]?" . PjsipConfig::GAME_CONTEXTS[$game] . ',s,1)', $plan);
                assertContains('[' . PjsipConfig::GAME_CONTEXTS[$game] . ']', $plan);
            }
            // Sums never past 20, and never below nought.
            assertContains('Set(A=${RAND(1,19)})', $plan);
            assertContains('Set(B=${RAND(1,$[20 - ${A}])})', $plan);
            assertContains('Set(B=${RAND(1,$[${A} - 1])})', $plan);
            assertContains('Set(TARGETS=10,20)', $plan);
            // Every riddle, with the key that answers it.
            foreach (Games::RIDDLES as $n => $r) {
                assertContains("Set(RIDDLE_ANSWER={$r['answer']})\n same => n,Return(" . PjsipConfig::QUIZ_SOUNDS . "/riddle-{$n})", $plan);
            }
            assertContains('|riddles)', $plan);
            assertContains('|guess)', $plan);
        });
    }),
    test('each game is noted as the game it was, and described in words', function () use ($fresh) {
        $fresh(function () {
            $devices = new DeviceRepository();
            $phone = $devices->create('Test landing', 'ghp621', 'udp');
            $user = (string) $phone['sip_username'];
            $quiz = new Quiz();
            $quiz->record([
                '1790000000.2' => "{$user}|20|7|10|1790000100|sums",
                '1790000000.3' => "{$user}|0|6|1|1790000200|guess",
                '1790000000.4' => "{$user}|0|4|5|1790000300|riddles",
                '1790000000.5' => "{$user}|0|1|0|1790000400|guess",
            ]);
            $mine = array_values(array_filter($quiz->recent(), static fn(array $g): bool => $g['deviceId'] === (int) $phone['id']));
            assertSame(3, count($mine), 'a number not yet guessed isn\'t a game');
            assertSame(['riddles', 'guess', 'sums'], array_column($mine, 'game'));
            assertSame('Sums to 20', $mine[2]['about']);
            assertSame('7/10', $mine[2]['result']);
            assertSame('6 goes', $mine[1]['result']);
            assertSame('Number bonds, a mix', Quiz::describe('bonds', 0));
            assertSame('7 times table', Quiz::describe('times', 7));
        });
    }),
];
