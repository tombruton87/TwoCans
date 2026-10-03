<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// A dead database means nobody can sign in — say so plainly rather than
// throwing a stack trace at a parent.
if (!Database::isAvailable()) {
    http_response_code(503);
    view('error_db');
    exit;
}

/*
 * Provisioning is fetched by the phone itself, which has no session — the
 * one-time token in the URL is the credential. It therefore has to be served
 * before the login gate, and never leaks anything without a valid token.
 */
if (isset($_GET['provision'])) {
    $result = (new Provisioning())->redeem((string) $_GET['provision']);

    if ($result['error'] !== null) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit('twocans: ' . $result['error'] . "\n");
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Disposition: inline; filename="linphone-provisioning.xml"');
    echo (new Provisioning())->xml($result['device']);
    exit;
}

/*
 * An announcement's trigger URL, for Home Assistant, IFTTT, Uptime Kuma or
 * anything else that can fetch a URL: /hook/announce/<token>, GET or POST.
 * Before the login gate — the 32-character secret in the path is the
 * credential, and a new one can be made from the Announcements screen.
 */
if (preg_match('#^/hook/announce/([a-f0-9]{32})/?(?:\?.*)?$#', $_SERVER['REQUEST_URI'] ?? '', $hookMatch)) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $announcements = new AnnouncementRepository();
    $announcement = $announcements->findByToken($hookMatch[1]);
    if ($announcement === null) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'No announcement has that trigger URL.']));
    }
    $sent = $announcements->send($announcement['id']);
    http_response_code($sent['ok'] ? 200 : 409);
    exit(json_encode([
        'ok' => $sent['ok'],
        'announcement' => $announcement['label'],
        'phones' => $sent['phones'],
        'error' => $sent['error'],
    ]));
}

/*
 * Self-service: /hello/<token>, the page a parent sends one person so they can
 * add their own photo and say their own name. Before the login gate — the
 * token is the credential, and all it reaches is that one person's photo and
 * name clip. See ContactLinkRepository.
 */
if (preg_match('#^/hello/([a-f0-9]{32})/?(?:\?.*)?$#', $_SERVER['REQUEST_URI'] ?? '', $helloMatch)) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('Referrer-Policy: no-referrer');
    $links = new ContactLinkRepository();
    $person = $links->contactFor($helloMatch[1]);
    $helloErrors = [];

    if ($person !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $id = (int) $person['id'];
        $contacts = new ContactRepository();
        $saved = [];

        // Taken with the camera just now, or picked from their photos.
        $taken = (int) ($_FILES['camera']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $photo = (new PhotoStore())->store($_FILES[$taken ? 'camera' : 'photo'] ?? []);
        if ($photo['error'] !== null) {
            $helloErrors[] = $photo['error'];
        } elseif ($photo['file'] !== null) {
            $contacts->setPhoto($id, $photo['file']);
            $saved[] = 'photo';
        }

        if ((int) ($_FILES['clip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $names = new CallerNameStore();
            $clip = $names->store($_FILES['clip']);
            if ($clip['error'] !== null) {
                $helloErrors[] = $clip['error'];
            } else {
                $names->delete((string) ($person['announce_clip'] ?? ''));
                $contacts->setAnnounce($id, (string) $clip['file'], $clip['seconds']);
                $saved[] = 'voice';
            }
        }

        if ($saved !== []) {
            $links->markUsed($id);
            (new PjsipConfig(new DeviceRepository()))->apply();
        }
        if ($helloErrors === [] && $saved !== []) {
            header('Location: /hello/' . $helloMatch[1] . '?saved=' . implode(',', $saved), true, 303);
            exit;
        }
        if ($helloErrors === []) {
            $helloErrors[] = 'Add a photo or record your voice first.';
        }
        $person = $contacts->find($id);
    }

    if ($person === null) {
        http_response_code(404);
    }
    view('hello', [
        'person' => $person,
        'token' => $helloMatch[1],
        'errors' => $helloErrors,
        'saved' => array_filter(explode(',', (string) ($_GET['saved'] ?? ''))),
    ]);
    exit;
}

/*
 * Phone and phonebook provisioning. Served before the login gate (phones have
 * no session), but gated by HTTP basic auth — unlike Linphone's one-time token,
 * these URLs are stable and re-fetched, so they need their own credential.
 */
$gsConfigMatch = preg_match('#^/grandstream/cfg([0-9A-Fa-f]{12})\.xml$#', $_SERVER['REQUEST_URI'] ?? '', $gsMac);
$phonebookMatch = preg_match('#^/phonebook/(grandstream|yealink)\.xml$#', $_SERVER['REQUEST_URI'] ?? '', $pbVendor);
// A Fanvil GA10: its own <mac>.cfg (or .xml); not its common file.
$fanvilMatch = preg_match('#^/fanvil/(?:([0-9a-fA-F]{12})\.(?:cfg|xml)|[^/]+\.(?:cfg|xml|txt))$#', $_SERVER['REQUEST_URI'] ?? '', $fanvilFile);
// A Poly VVX: its master <mac>.cfg, then twocans-<mac>.cfg, and <mac>-directory.xml.
$polyMatch = preg_match('#^/polycom/(?:(twocans-)?([0-9a-fA-F]{12})\.cfg|([0-9a-fA-F]{12})-directory\.xml)$#', $_SERVER['REQUEST_URI'] ?? '', $polyFile);
// A Cisco SPA112: its own <mac>.xml (its Profile Rule asks for $MA.xml).
$ciscoConfigMatch = preg_match('#^/cisco/([0-9A-Fa-f]{12})\.xml$#', $_SERVER['REQUEST_URI'] ?? '', $ciscoMac);
// A Yealink desk phone's own wallpaper (YealinkProvisioning::desk).
$ylWallpaperMatch = preg_match('#^/yealink/wallpaper/([a-f0-9]{32}\.jpg)$#', $_SERVER['REQUEST_URI'] ?? '', $ylWallpaper);
// A Yealink base: the common file every one fetches, then its own <mac>.cfg.
// Newer firmware asks for a boot file first (<mac>.boot, or the common
// y000000000000.boot), which names the files to load: see YealinkProvisioning::boot().
$ylConfigMatch = preg_match('#^/yealink/(?:([0-9A-Fa-f]{12})|(y0{9}\d{3}))\.(cfg|boot)$#', $_SERVER['REQUEST_URI'] ?? '', $ylFile);
// Anything else asked of a provisioning path is not found — never the sign-in
// page, which a phone would take for its settings.
$provisioningOther = !$ylConfigMatch && preg_match('#^/(yealink|polycom|cisco|fanvil|grandstream)/#', $_SERVER['REQUEST_URI'] ?? '') === 1;

if ($gsConfigMatch || $phonebookMatch || $ylConfigMatch || $ciscoConfigMatch || $polyMatch || $fanvilMatch || $ylWallpaperMatch || $provisioningOther) {
    $expected = 'Basic ' . base64_encode('twocans:' . (new SettingsRepository())->provisionPass());
    $provided = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if ($provided === '' || !hash_equals($expected, $provided)) {
        header('WWW-Authenticate: Basic realm="twocans provisioning"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        exit("twocans: provisioning needs a username and password\n");
    }

    header('Cache-Control: no-store');

    if ($provisioningOther) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit("twocans: not a file twocans serves\n");
    }

    if ($ylWallpaperMatch) {
        // Only a picture that's a phone's wallpaper: not anyone's photo.
        $file = (new DeviceRepository())->isWallpaper($ylWallpaper[1]) ? (new PhotoStore())->file($ylWallpaper[1]) : null;
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit("twocans: no such wallpaper\n");
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    if ($fanvilMatch) {
        $mac = strtoupper((string) ($fanvilFile[1] ?? ''));
        $found = $mac === '' ? [] : array_values(array_filter((new DeviceRepository())->findByMac($mac),
            static fn(array $r): bool => (DeviceRepository::TYPES[(string) $r['type']]['brand'] ?? '') === 'fanvil'));
        if ($found === []) {
            // Its own file, not added yet: remember it, so adding a phone can offer it.
            if ($mac !== '' && trim($mac, '0') !== '') {
                (new FoundPhones())->sawFetch($mac, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            }
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit($mac === '' ? "twocans: everything is in each adapter's own <mac>.cfg\n" : "twocans: no Fanvil adapter with that MAC\n");
        }
        (new DeviceRepository())->touchSettingsFetched((int) $found[0]['id']);
        header('Content-Type: application/xml; charset=utf-8');
        echo (new FanvilProvisioning())->xml(DeviceRepository::toView($found[0]));
        exit;
    }

    if ($polyMatch) {
        $mac = strtoupper(($polyFile[2] ?? '') !== '' ? $polyFile[2] : ($polyFile[3] ?? ''));
        $found = array_values(array_filter((new DeviceRepository())->findByMac($mac),
            static fn(array $r): bool => (DeviceRepository::TYPES[(string) $r['type']]['brand'] ?? '') === 'poly'));
        if ($found === []) {
            // Not added yet: remember it, so adding a phone can offer it.
            // (Not 000000000000.cfg, every phone's fallback.)
            if (($polyFile[1] ?? '') === '' && ($polyFile[2] ?? '') !== '' && trim($mac, '0') !== '') {
                (new FoundPhones())->sawFetch($mac, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            }
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit("twocans: no Poly phone with that MAC\n");
        }
        $device = DeviceRepository::toView($found[0]);
        header('Content-Type: application/xml; charset=utf-8');
        if (($polyFile[3] ?? '') !== '') {
            $hotkeyRepo = new DeviceHotkeyRepository();
            echo (new PolyProvisioning())->directory($hotkeyRepo->forDevice($device['id']), $hotkeyRepo->labels());
        } elseif (($polyFile[1] ?? '') !== '') {
            (new DeviceRepository())->touchSettingsFetched($device['id']);
            echo (new PolyProvisioning())->config($device);
        } else {
            echo PolyProvisioning::master();
        }
        exit;
    }

    if ($ciscoConfigMatch) {
        header('Content-Type: application/xml; charset=utf-8');
        $found = array_values(array_filter((new DeviceRepository())->findByMac(strtoupper($ciscoMac[1])),
            static fn(array $r): bool => (DeviceRepository::TYPES[(string) $r['type']]['brand'] ?? '') === 'cisco'));
        if ($found === []) {
            // Not added yet: remember it, so adding a phone can offer it.
            (new FoundPhones())->sawFetch($ciscoMac[1], (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit("twocans: no Cisco adapter with that MAC\n");
        }
        $byPort = [];
        foreach ($found as $row) {
            (new DeviceRepository())->touchSettingsFetched((int) $row['id']);
            $byPort[(int) $row['port']] ??= DeviceRepository::toView($row);
        }
        echo (new CiscoProvisioning())->xml(
            $byPort,
            preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) === 1 ? (string) $_SERVER['HTTP_HOST'] : null,
            ($_SERVER['HTTPS'] ?? '') === 'on'
        );
        exit;
    }

    if ($ylConfigMatch) {
        header('Content-Type: text/plain; charset=utf-8');
        $boot = ($ylFile[3] ?? '') === 'boot';
        if (($ylFile[1] ?? '') === '') {
            // The common file: nothing in it; the common boot file names the
            // phone's own (the phone fills in $mac).
            echo $boot ? YealinkProvisioning::boot(null) : YealinkProvisioning::common();
            exit;
        }
        $found = array_values(array_filter((new DeviceRepository())->findByMac(strtoupper($ylFile[1])),
            static fn(array $r): bool => (DeviceRepository::TYPES[(string) $r['type']]['brand'] ?? '') === 'yealink'));
        if ($found === []) {
            // Not added yet: remember it, so adding a phone can offer it.
            (new FoundPhones())->sawFetch($ylFile[1], (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            http_response_code(404);
            exit("twocans: no Yealink phone with that MAC\n");
        }
        if ($boot) {
            echo YealinkProvisioning::boot(strtolower($ylFile[1]));
            exit;
        }
        $host = preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) === 1 ? (string) $_SERVER['HTTP_HOST'] : null;
        // A desk phone: its own file, with its speed-dial keys.
        if (YealinkProvisioning::isDesk((string) $found[0]['type'])) {
            $device = DeviceRepository::toView($found[0]);
            (new DeviceRepository())->touchSettingsFetched($device['id']);
            $hotkeyRepo = new DeviceHotkeyRepository();
            echo (new YealinkProvisioning())->desk($device, $hotkeyRepo->forDevice($device['id']), $hotkeyRepo->labels(), $host, ($_SERVER['HTTPS'] ?? '') === 'on');
            exit;
        }
        // A cordless base: every handset on it.
        $byHandset = [];
        foreach ($found as $row) {
            (new DeviceRepository())->touchSettingsFetched((int) $row['id']);
            $byHandset[(int) $row['port']] ??= DeviceRepository::toView($row);
        }
        echo (new YealinkProvisioning())->cfg($byHandset, $host, ($_SERVER['HTTPS'] ?? '') === 'on');
        exit;
    }

    header('Content-Type: application/xml; charset=utf-8');

    // The allowlist as a remote phonebook, in whichever shape the phone reads.
    if ($phonebookMatch) {
        $contacts = (new ContactRepository())->all();
        echo $pbVendor[1] === 'yealink'
            ? Phonebook::yealink($contacts)
            : Phonebook::grandstream($contacts);
        exit;
    }

    // A Yealink's MAC isn't a Grandstream's to fetch.
    $found = array_values(array_filter((new DeviceRepository())->findByMac(strtoupper($gsMac[1])),
        static fn(array $r): bool => (DeviceRepository::TYPES[(string) $r['type']]['brand'] ?? '') === 'grandstream'));
    if ($found === []) {
        // Not added yet: remember it, so adding a phone can offer it.
        (new FoundPhones())->sawFetch($gsMac[1], (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit("twocans: no phone with that MAC\n");
    }
    $device = $found[0];
    // Every phone behind this MAC — both of an HT802's sockets — has just
    // been handed its settings.
    foreach ($found as $row) {
        (new DeviceRepository())->touchSettingsFetched((int) $row['id']);
    }

    // An adapter: one file carries every socket's account.
    if (in_array($device['type'], ['ht801', 'ht802'], true)) {
        $bySocket = [];
        foreach ($found as $row) {
            $bySocket[(int) $row['port']] ??= DeviceRepository::toView($row);
        }
        echo (new GrandstreamProvisioning())->ataXml((string) $device['type'], $bySocket);
        exit;
    }

    // Fetched from home, it can be paged by multicast from now on; from
    // anywhere else, it can't hear those, so it's paged with a call.
    (new DeviceRepository())->setPagingMulticast((int) $device['id'], Pager::onHomeNetwork((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    $hotkeyRepo = new DeviceHotkeyRepository();
    echo (new GrandstreamProvisioning())->xml(
        DeviceRepository::toView($device),
        $hotkeyRepo->forDevice((int) $device['id']),
        $hotkeyRepo->labels(),
        // Exactly how it reached twocans: it keeps using that.
        preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) === 1 ? (string) $_SERVER['HTTP_HOST'] : null,
        ($_SERVER['HTTPS'] ?? '') === 'on'
    );
    exit;
}

/*
 * A desk phone telling twocans something about itself — it's started up, its
 * handset's off or back on the hook (GrandstreamProvisioning::phoneEventUrl).
 * Before the login gate, like provisioning; the key says which phone.
 */
if (preg_match('#^/grandstream/event(\?|$)#', $_SERVER['REQUEST_URI'] ?? '')) {
    $row = (new DeviceRepository())->find((int) ($_GET['d'] ?? 0));
    $view = $row === null ? null : DeviceRepository::toView($row);
    header('Content-Type: text/plain; charset=utf-8');
    if ($view === null || !hash_equals(GrandstreamProvisioning::eventKey($view), (string) ($_GET['k'] ?? ''))) {
        http_response_code(403);
        exit("no\n");
    }
    (new DeviceRepository())->phoneEvent((int) $view['id'], (string) ($_GET['e'] ?? ''));
    exit("ok\n");
}

$store = new Store();
$guardians = new GuardianRepository();
$devices = new DeviceRepository();
$contacts = new ContactRepository();
$calls = new CallRepository($devices);
$voicemails = new VoicemailRepository();
$live = new LiveCalls($devices);

// Before the first Owner exists the only thing on offer is first-run setup.
$needsSetup = $guardians->isEmpty();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/src/actions.php';
}

if ($needsSetup) {
    view('setup', ['error' => take_error(), 'old' => take_old()]);
    exit;
}

if (!Auth::check()) {
    view('login', ['error' => take_error(), 'old' => take_old()]);
    exit;
}

if (isset($_GET['api'])) {
    $api = (string) $_GET['api'];
    require __DIR__ . '/src/api.php';
}

/*
 * Photos are served by the app, not by nginx: they live outside the docroot so
 * a picture of a child can never be fetched by anyone who isn't signed in.
 */
if (isset($_GET['photo'])) {
    $file = (new PhotoStore())->file((string) $_GET['photo']);
    if ($file === null) {
        http_response_code(404);
        exit('Not found');
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    // Filenames are random and never reused, so this can cache hard.
    header('Cache-Control: private, max-age=604800');
    readfile($file);
    exit;
}

// The contact sheet, to print: see ContactSheet.
if (isset($_GET['contactsheet'])) {
    $deviceRepo = new DeviceRepository();
    $phones = [];
    foreach ($deviceRepo->all() as $row) {
        $phones[(int) $row['id']] = (string) $row['name'];
    }
    $deviceId = isset($_GET['phone']) && isset($phones[(int) $_GET['phone']]) ? (int) $_GET['phone'] : null;
    $theme = isset(ContactSheet::THEMES[$_GET['theme'] ?? '']) ? (string) $_GET['theme'] : 'dino';
    $title = trim((string) ($_GET['title'] ?? ''));
    view('contact_sheet', [
        'options' => [
            'theme' => $theme,
            'paper' => isset(ContactSheet::PAPERS[$_GET['paper'] ?? '']) ? (string) $_GET['paper'] : 'a4',
            'deviceId' => $deviceId,
            'title' => mb_substr($title !== '' ? $title : ContactSheet::THEMES[$theme]['title'], 0, 40),
            // The lines ticked: once somebody's chosen (lines_set), exactly
            // those; until then, each one's default.
            'lines' => $lines = isset($_GET['lines_set'])
                ? array_values(array_intersect(array_keys(ContactSheet::LINES), array_map('strval', (array) ($_GET['lines'] ?? []))))
                : null,
        ],
        'people' => ContactSheet::people($deviceId),
        'available' => ContactSheet::available($deviceId),
        'services' => ContactSheet::services($deviceId, $lines),
        'device' => $deviceId === null ? null : DeviceRepository::toView($deviceRepo->find($deviceId)),
        'phones' => $phones,
    ]);
    exit;
}

// A desk phone's faceplate, to print: see Faceplate.
// A phone's diagnostics, masked, to send to whoever's helping: see Diagnostics.
// ?view=1 shows it in the browser first.
if (isset($_GET['diagnostics'])) {
    $device = Auth::can('devices') ? (new DeviceRepository())->find((int) $_GET['diagnostics']) : null;
    if ($device === null) {
        http_response_code(404);
        exit('Not found');
    }
    $text = (new Diagnostics())->forDevice($device);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (($_GET['view'] ?? '') !== '1') {
        header('Content-Disposition: attachment; filename="' . Diagnostics::fileName(DeviceRepository::toView($device)) . '"');
    }
    echo $text;
    exit;
}

if (isset($_GET['faceplate'])) {
    $device = Auth::can('devices') ? (new DeviceRepository())->find((int) $_GET['faceplate']) : null;
    if ($device === null || (DeviceRepository::TYPES[$device['type']]['faceplate'] ?? null) === null) {
        http_response_code(404);
        exit('Not found');
    }
    $device = DeviceRepository::toView($device);
    $title = trim((string) ($_GET['title'] ?? ''));
    view('faceplate', [
        'device' => $device,
        'keys' => Faceplate::keys((new DeviceHotkeyRepository())->forDevice((int) $device['id']), $device['keys']),
        'options' => [
            'title' => mb_substr($title !== '' ? $title : (string) $device['name'], 0, 40),
            'theme' => ($_GET['theme'] ?? '') === 'white' ? 'white' : 'twocans',
            'cut' => ($_GET['cut'] ?? '') === 'split' ? 'split' : 'slot',
            'photos' => ($_GET['photos'] ?? '1') !== '0',
        ],
    ]);
    exit;
}

if (isset($_GET['download'])) {
    $download = (string) $_GET['download'];
    require __DIR__ . '/src/downloads.php';
}

// ---------------------------------------------------------------- view state
$screen = (string) ($_GET['screen'] ?? 'dashboard');
if (!in_array($screen, Presenter::SCREENS, true)) {
    $screen = 'dashboard';
}
// System health and backups are for grown-ups: a Viewer never sees this screen.
if ($screen === 'system' && !Auth::can('system')) {
    $screen = 'dashboard';
}
// Home Assistant holds the MQTT login and an HA token.
if ($screen === 'homeassistant' && !Auth::can('system')) {
    $screen = 'dashboard';
}
// Getting started walks through adding phones: for those who may.
if ($screen === 'start' && !Auth::can('devices')) {
    $screen = 'dashboard';
}
// Notifications hold the Mailgun key and recipients — Owner only.
if ($screen === 'notifications' && !Auth::can('notifications')) {
    $screen = 'dashboard';
}

// Ask Asterisk who is actually registered before drawing anything that shows
// device status. Cheap (one AMI round trip) and only on the screens that care.
if (in_array($screen, ['dashboard', 'phones'], true)) {
    (new PjsipConfig($devices))->syncRegistrations();
}

// Pull in any calls Asterisk has recorded since the last look.
if (in_array($screen, ['dashboard', 'calllog'], true)) {
    $calls->import();
}

// Messages are recorded by Asterisk into its spool; pick up any new ones.
$voicemails->import();

// Fold any newly blocked calls into the "asks to call" list.
(new CallRequestRepository())->import();

/*
 * Delete recordings and transcripts past the household's keep-until date.
 *
 * Deliberately here rather than in a cron job or the worker: this is a box in
 * somebody's house, and a scheduled job is another moving part to install and
 * explain. Rate-limited to once an hour inside Retention, and only reached on a
 * real page render — the API polls and file downloads have all exited by now,
 * so the phones page polling every two seconds doesn't trigger it.
 */
(new Retention())->sweep();

$selectedDevice = $screen === 'phones' ? $devices->find(isset($_GET['device']) ? (int) $_GET['device'] : null) : null;
$editingContact = $screen === 'contacts'
    ? $contacts->find(isset($_GET['contact']) ? (int) $_GET['contact'] : null)
    : null;
$deviceWizard = $screen === 'phones' ? max(0, min(3, (int) ($_GET['wizard'] ?? 0))) : 0;
$trunkWizard = $screen === 'trunk' ? max(0, min(3, (int) ($_GET['trunkwizard'] ?? 0))) : 0;
// What is happening on the line right now, straight from Asterisk.
$activeCalls = in_array($screen, ['dashboard'], true) ? $live->active() : [];
$listenCall = null;
if (isset($_GET['listen'])) {
    $listenCall = $live->find((string) $_GET['listen']);
}

// Call-log search, filters and paging all live in the query string, so a
// filtered view can be bookmarked or shared with the other parent.
$callFilters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'contact' => (int) ($_GET['contact'] ?? 0),
    'status' => (string) ($_GET['status'] ?? ''),
];
// One `page` parameter, shared by every paged screen — the call log and the
// joke line are never on screen at the same time.
$callPage = max(1, (int) ($_GET['page'] ?? 1));

// A link to one call (from the dashboard) opens the log on the page it's on.
$focusCall = $screen === 'calllog' ? (int) ($_GET['call'] ?? 0) : 0;
if ($focusCall > 0 && !isset($_GET['page'])) {
    $callPage = $calls->pageOf($focusCall) ?? 1;
}

// Password modal: your own always, anyone else's only with Owner rights.
$passwordFor = null;
if ($screen === 'guardians' && isset($_GET['password'])) {
    $candidate = $guardians->find((int) $_GET['password']);
    if ($candidate !== null
        && ((int) $candidate['id'] === (int) Auth::user()['id'] || Auth::can('guardians'))) {
        $passwordFor = $candidate;
    }
}

[$headerTitle, $headerSub] = Presenter::TITLES[$screen];
if ($selectedDevice !== null) {
    $view = DeviceRepository::toView($selectedDevice);
    $headerTitle = $view['name'];
    $headerSub = $view['model'] . ' settings';
}
if ($screen === 'dashboard') {
    $headerTitle = 'Hello, ' . explode(' ', (string) Auth::user()['name'])[0] . ' 👋';
}

view('layout', [
    'store' => $store,
    'screen' => $screen,
    'selectedDevice' => $selectedDevice,
    'devices' => $devices,
    'contacts' => $contacts,
    'calls' => $calls,
    'voicemails' => $voicemails,
    'live' => $live,
    'activeCalls' => $activeCalls,
    'listenCall' => $listenCall,
    'callFilters' => $callFilters,
    'callPage' => $callPage,
    'focusCall' => $focusCall,
    'editingContact' => $editingContact,
    'deviceWizard' => $deviceWizard,
    'trunkWizard' => $trunkWizard,
    'passwordFor' => $passwordFor,
    // Bedtime's times editor, from the dashboard card or the sidebar.
    'editBedtime' => isset($_GET['bedtime']) && Auth::can('rules'),
    'headerTitle' => $headerTitle,
    'headerSub' => $headerSub,
    'toast' => take_flash(),
]);
