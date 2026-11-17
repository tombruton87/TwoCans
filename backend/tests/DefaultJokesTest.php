<?php
declare(strict_types=1);

/**
 * The jokes twocans ships with: each has a transcript, a household gets each
 * once, and one it deleted stays deleted.
 */

$shipped = DefaultJokes::dir();

// A household's joke line in a transaction, its jokes and default-joke memory
// set aside, with two shipped jokes as the "defaults" and a folder of its own.
$withTwo = static function (callable $fn) use ($shipped): void {
    $pdo = Database::pdo();
    $tmp = sys_get_temp_dir() . '/tc-default-jokes-' . bin2hex(random_bytes(4));
    mkdir("{$tmp}/defaults", 0777, true);
    mkdir("{$tmp}/jokes");
    $two = array_slice(json_decode((string) file_get_contents("{$shipped}/jokes.json"), true), 0, 2);
    foreach ($two as $j) {
        copy("{$shipped}/{$j['file']}", "{$tmp}/defaults/{$j['file']}");
    }
    file_put_contents("{$tmp}/defaults/jokes.json", json_encode($two));
    putenv("DEFAULT_JOKES_PATH={$tmp}/defaults");
    putenv("JOKES_PATH={$tmp}/jokes");
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM settings WHERE name = 'default_jokes_offered'");
        $pdo->exec('DELETE FROM jokes');
        SettingsRepository::forget();
        $fn($two);
    } finally {
        $pdo->rollBack();
        SettingsRepository::forget();
        putenv('DEFAULT_JOKES_PATH');
        putenv('JOKES_PATH');
        exec('rm -rf ' . escapeshellarg($tmp));
    }
};

return [
    test('every joke twocans ships has its clip and a written transcript', function () use ($shipped) {
        if (!is_file("{$shipped}/jokes.json")) {
            return;   // No defaults mounted here (the image carries only the app).
        }
        $manifest = json_decode((string) file_get_contents("{$shipped}/jokes.json"), true);
        assertTrue(is_array($manifest) && count($manifest) >= 20, 'a manifest of jokes');
        assertSame(count($manifest), count(DefaultJokes::all()), 'each with its clip and transcript');
    }),
    test('a household gets each default joke once, with its transcript', function () use ($withTwo, $shipped) {
        if (!is_file("{$shipped}/jokes.json")) {
            return;
        }
        $withTwo(function (array $two) {
            assertSame(2, DefaultJokes::seed());
            $jokes = (new JokeRepository())->all();
            assertSame(2, count($jokes));
            $texts = array_column($jokes, 'transcript');
            sort($texts);
            $want = array_column($two, 'transcript');
            sort($want);
            assertSame($want, $texts);
            assertSame(0, DefaultJokes::seed(), 'not again');
        });
    }),
    test('a default joke the household deleted stays deleted', function () use ($withTwo, $shipped) {
        if (!is_file("{$shipped}/jokes.json")) {
            return;
        }
        $withTwo(function () {
            DefaultJokes::seed();
            $repo = new JokeRepository();
            $repo->delete((int) $repo->all()[0]['id']);
            assertSame(0, DefaultJokes::seed());
            assertSame(1, count($repo->all()));
        });
    }),
    test('one the household already has is not added twice', function () use ($withTwo, $shipped) {
        if (!is_file("{$shipped}/jokes.json")) {
            return;
        }
        $withTwo(function (array $two) use ($shipped) {
            $repo = new JokeRepository();
            $repo->create('theirs.wav', 4, 'their own upload', null, hash_file('sha256', "{$shipped}/{$two[0]['file']}"));
            assertSame(1, DefaultJokes::seed());
            assertSame(2, count($repo->all()));
        });
    }),
];
