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
 * Phone and phonebook provisioning. Served before the login gate (phones have
 * no session), but gated by HTTP basic auth — unlike Linphone's one-time token,
 * these URLs are stable and re-fetched, so they need their own credential.
 */
$gsConfigMatch = preg_match('#^/grandstream/cfg([0-9A-Fa-f]{12})\.xml$#', $_SERVER['REQUEST_URI'] ?? '', $gsMac);
$phonebookMatch = preg_match('#^/phonebook/(grandstream|yealink)\.xml$#', $_SERVER['REQUEST_URI'] ?? '', $pbVendor);

if ($gsConfigMatch || $phonebookMatch) {
    $expected = 'Basic ' . base64_encode('twocans:' . (new SettingsRepository())->provisionPass());
    $provided = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if ($provided === '' || !hash_equals($expected, $provided)) {
        header('WWW-Authenticate: Basic realm="twocans provisioning"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        exit("twocans: provisioning needs a username and password\n");
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store');

    // The allowlist as a remote phonebook, in whichever shape the phone reads.
    if ($phonebookMatch) {
        $contacts = (new ContactRepository())->all();
        echo $pbVendor[1] === 'yealink'
            ? Phonebook::yealink($contacts)
            : Phonebook::grandstream($contacts);
        exit;
    }

    $device = (new DeviceRepository())->findByMac(strtoupper($gsMac[1]));
    if ($device === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit("twocans: no phone with that MAC\n");
    }

    $hotkeys = (new DeviceHotkeyRepository())->forDevice((int) $device['id']);
    echo (new GrandstreamProvisioning())->xml(DeviceRepository::toView($device), $hotkeys);
    exit;
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
    'editingContact' => $editingContact,
    'deviceWizard' => $deviceWizard,
    'trunkWizard' => $trunkWizard,
    'passwordFor' => $passwordFor,
    'headerTitle' => $headerTitle,
    'headerSub' => $headerSub,
    'toast' => take_flash(),
]);
