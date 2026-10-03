<?php
declare(strict_types=1);

/**
 * The radio: songs on and off it, their titles, its number, and the dialplan
 * that plays them — against the database, in a transaction rolled back
 * after, and songs in a folder in the temp directory.
 */

$fresh = static function (callable $fn): void {
    $pdo = Database::pdo();
    $dir = sys_get_temp_dir() . '/tc-radio-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    putenv('RADIO_PATH=' . $dir);
    $pdo->beginTransaction();
    try {
        // The defaults, whatever this household has chosen (rolled back after).
        $pdo->exec("DELETE FROM settings WHERE name IN ('games_number', 'quiz_number', 'joke_number', 'sleeps_number', 'radio_number')");
        SettingsRepository::forget();
        $pdo->exec('DELETE FROM radio_stations');
        $pdo->exec('DELETE FROM radio_songs');
        $fn($dir);
    } finally {
        $pdo->rollBack();
        putenv('RADIO_PATH');
        SettingsRepository::forget();
        exec('rm -rf ' . escapeshellarg($dir));
    }
};

// A song "on disk": a name the store would have made, and a file with it.
$song = static function (string $dir, string $title) {
    $file = bin2hex(random_bytes(16)) . '.wav';
    file_put_contents("{$dir}/{$file}", 'RIFF');

    return [(new Radio())->create($file, 125, $title, null, hash('sha256', $file)), $file];
};

return [
    test('a song is named from its file: no track number, no extension', function () {
        assertSame('Baby Shark (Remix)', Radio::titleFrom('01 - Baby Shark (Remix).mp3'));
        assertSame('Wheels on the Bus', Radio::titleFrom('Wheels_on_the_Bus.m4a'));
        assertSame('A song', Radio::titleFrom('.mp3'));
    }),
    test('every song that\'s on is played, shuffled, carrying on where the last call stopped', function () use ($fresh, $song) {
        $fresh(function (string $dir) use ($song) {
            [$a] = $song($dir, 'Song A.mp3');
            [, $fileB] = $song($dir, 'Song B.mp3');
            (new Radio())->setEnabled($a, false);

            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            assertContains("exten => 7234,1,NoOp(twocans: the radio)\n same => n,GotoIf(\$[\"\${TC_PAUSED}\" = \"1\"]?" . PjsipConfig::PAUSED_CONTEXT . ",s,1)\n same => n,Goto(" . PjsipConfig::RADIO_CONTEXT . ',s,1)', $plan);
            assertContains("exten => 1,1,Return({$dir}/" . substr($fileB, 0, -4) . ')', $plan);
            assertFalse(str_contains($plan, 'exten => 2,1,Return(' . $dir), 'a song switched off isn\'t played');
            assertContains('ControlPlayback(${GOSUB_RETVAL},10000,6,4,#*,5)', $plan);
            assertContains('GLOBAL(TWOCANS_RADIO_POS_0)', $plan);
        });
    }),
    test('with nothing on it, the radio says so rather than answering into silence', function () use ($fresh) {
        $fresh(function () {
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            $at = strpos($plan, '[' . PjsipConfig::RADIO_CONTEXT . '-play]');
            assertContains("exten => 0,1,NoOp(twocans: the radio, all the songs, 0)\n same => n,Playback(vm-nomore)", substr($plan, $at));
        });
    }),
    test('deleting a song takes its recording with it', function () use ($fresh, $song) {
        $fresh(function (string $dir) use ($song) {
            [$id, $file] = $song($dir, 'Song C.mp3');
            (new Radio())->delete($id);
            assertFalse(is_file("{$dir}/{$file}"));
            assertSame(null, (new Radio())->find($id));
        });
    }),
    test('the radio keeps a number of its own', function () use ($fresh) {
        $fresh(function () {
            $s = new SettingsRepository();
            assertSame(null, $s->radioNumberProblem('7234'));
            assertTrue($s->radioNumberProblem($s->jokeNumber()) !== null);
            assertTrue($s->radioNumberProblem('1225') !== null, 'the Christmas countdown');
            assertTrue($s->gamesNumberProblem('7234') !== null);
            assertTrue($s->jokeNumberProblem('7234') !== null);
            assertTrue((new ContactRepository())->speedDialProblem('7234') !== null);
            assertSame('The radio', PjsipConfig::testNumbers()['7234']['label'] ?? null);
        });
    }),
    test('songs go on the end, and play in the household\'s order when it isn\'t shuffled', function () use ($fresh, $song) {
        $fresh(function (string $dir) use ($song) {
            [$a, $fileA] = $song($dir, 'Song A.mp3');
            [$b, $fileB] = $song($dir, 'Song B.mp3');
            [$c, $fileC] = $song($dir, 'Song C.mp3');
            $radio = new Radio();
            assertSame([$a, $b, $c], array_map('intval', array_column($radio->all(), 'id')));

            // An out-of-date list can't lose a song: B isn't named, so it goes last.
            $radio->reorder([$c, $a]);
            assertSame([$c, $a, $b], array_map('intval', array_column($radio->all(), 'id')));

            $settings = new SettingsRepository();
            $settings->setRadioShuffle(false);
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            $strip = static fn(string $f): string => $dir . '/' . substr($f, 0, -4);
            assertContains('exten => 1,1,Return(' . $strip($fileC) . ')', $plan);
            assertContains('exten => 2,1,Return(' . $strip($fileA) . ')', $plan);
            assertContains('exten => 3,1,Return(' . $strip($fileB) . ')', $plan);
            assertContains('in order, 0 station(s). See Radio.', $plan);

            $settings->setRadioShuffle(true);
            assertContains('shuffled, 0 station(s). See Radio.', (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf']);
        });
    }),
    test('stations: up to five, on the menu only with songs on, a key each', function () use ($fresh, $song) {
        $fresh(function (string $dir) use ($song) {
            [$a] = $song($dir, 'Party A.mp3');
            [$b] = $song($dir, 'Party B.mp3');
            $radio = new Radio();
            $party = $radio->addStation('Party songs');
            $empty = $radio->addStation('');
            assertSame('Station 2', $radio->station($empty)['name']);
            foreach (range(3, 5) as $n) {
                assertTrue($radio->addStation('More ' . $n) !== null);
            }
            assertSame(null, $radio->addStation('A sixth'), 'one for each key, 1 to 5');

            $radio->setOnStation($a, $party, true);
            $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
            $q = PjsipConfig::QUIZ_SOUNDS;
            // Only the station with songs is offered, as key 1, said "station one".
            assertContains("Read(K,{$q}/radio-welcome&{$q}/press-1&{$q}/station-1&{$q}/radio-all,1,,2,6)", $plan);
            assertContains("ExecIf(\$[\"\${K}\" = \"1\"]?Set(ST={$party}))", $plan);
            assertFalse(str_contains($plan, "Set(ST={$empty})"));
            // It plays its own songs only: one, not both.
            assertContains("[" . PjsipConfig::RADIO_CONTEXT . "-list-{$party}]\nexten => 1,1,Return(", $plan);
            assertFalse(str_contains($plan, "[" . PjsipConfig::RADIO_CONTEXT . "-list-{$party}]\nexten => 1,1,Return({$dir}/x)\nexten => 2"));
            assertContains('ControlPlayback(${GOSUB_RETVAL},10000,6,4,#*,5)', $plan);
            assertContains('?' . PjsipConfig::RADIO_CONTEXT . ',s,menu)', $plan);
        });
    }),
    test('a phone\'s favourite station plays straight away; deleting it goes back to the menu', function () use ($fresh, $song) {
        $fresh(function (string $dir) use ($song) {
            [$a] = $song($dir, 'Fav A.mp3');
            $radio = new Radio();
            $station = $radio->addStation('Favourites');
            $radio->setOnStation($a, $station, true);
            $devices = new DeviceRepository();
            $phone = $devices->create('Test bedroom', 'ghp621', 'udp');
            $devices->setRadioStation((int) $phone['id'], $station);
            assertContains("set_var = TC_RADIOST={$station}", (new PjsipConfig($devices))->render()['pjsip-devices.conf']);
            $radio->deleteStation($station);
            assertSame(null, DeviceRepository::toView($devices->find((int) $phone['id']))['radioStation']);
            assertFalse(str_contains((new PjsipConfig($devices))->render()['pjsip-devices.conf'], 'TC_RADIOST'));
        });
    }),
    test('at bedtime: calm songs only, or off, and the sleep timer — not on adult phones', function () use ($fresh, $song) {
        $fresh(function (string $dir) use ($song) {
            [$a] = $song($dir, 'Lullaby.mp3');
            [$b] = $song($dir, 'Loud one.mp3');
            $radio = new Radio();
            $radio->setCalm($a, true);
            $settings = new SettingsRepository();
            if (!$settings->quietHours()) {
                $settings->toggleQuietHours();
            }
            // The radio's own part of the dialplan: call limits use TIMEOUT too.
            $render = static function (): string {
                $plan = (new PjsipConfig(new DeviceRepository()))->render()['dialplan-devices.conf'];
                $at = strpos($plan, '[' . PjsipConfig::RADIO_CONTEXT . ']');
                $radio = substr($plan, $at);
                // Up to the first part that isn't the radio's.
                if (preg_match('/\n\[(?!' . preg_quote(PjsipConfig::RADIO_CONTEXT, '/') . ')/', $radio, $m, PREG_OFFSET_CAPTURE)) {
                    $radio = substr($radio, 0, $m[0][1]);
                }

                return $radio;
            };

            $settings->setRadioBedtime('normal', 0);
            assertFalse(str_contains($render(), '(bed),NoOp'), 'nothing to do at bedtime');
            assertFalse(str_contains($render(), 'TIMEOUT(absolute)'), 'no timer');

            $settings->setRadioBedtime('calm', 20);
            $plan = $render();
            assertContains('GotoIf($["${TC_ADULT}" = "1"]?awake)', $plan);
            assertContains('?bed)', $plan);
            assertContains('Set(CALM=c)', $plan);
            assertContains('Set(TIMEOUT(absolute)=1200)', $plan);
            assertContains('exten => T,1,Playback(' . PjsipConfig::QUIZ_SOUNDS . '/radio-goodnight)', $plan);
            // The calm list has the lullaby only.
            $calm = substr($plan, strpos($plan, '[' . PjsipConfig::RADIO_CONTEXT . '-list-0c]'));
            assertContains('exten => 1,1,Return(', $calm);
            $calmOnly = substr($calm, 0, strpos($calm, "\n[", 5) ?: strlen($calm));
            assertFalse(str_contains($calmOnly, 'exten => 2,1,'), 'only the calm song: ' . $calmOnly);

            $settings->setRadioBedtime('off', 0);
            assertContains("(bed),NoOp(twocans: the radio at bedtime)\n same => n,Playback(" . PjsipConfig::QUIZ_SOUNDS . '/radio-bedtime)', $render());
            assertFalse(str_contains($render(), 'TIMEOUT(absolute)'), 'no timer when off');

            $settings->setRadioBedtime('loud', 7);
            assertSame('normal', (new SettingsRepository())->radioBedtime());
            assertSame(0, (new SettingsRepository())->radioSleepMinutes());
        });
    }),
];
