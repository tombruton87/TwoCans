<?php
/**
 * App shell: sticky sidebar (or bottom tab bar on narrow screens) beside a
 * sticky header over the scrolling content area.
 *
 * @var Store  $store
 * @var string $screen
 * @var ?array $selectedDevice
 * @var ?array $editingContact
 * @var int    $deviceWizard
 * @var int    $trunkWizard
 * @var bool   $listenOpen
 * @var ?array $passwordFor
 * @var DeviceRepository $devices
 * @var CallRepository $calls
 * @var ContactRepository $contacts
 * @var VoicemailRepository $voicemails
 * @var array $callFilters
 * @var int $callPage
 * @var array $activeCalls
 * @var ?array $listenCall
 */
$unheard = $voicemails->unheardCount();
$activeCall = $activeCalls[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php view('partials/head', ['title' => 'twocans — ' . strip_tags($headerTitle)]); ?>
</head>
<body>
<div class="tc-page">
  <div class="tc-shell">

    <?php view('partials/sidebar', [
        'store' => $store,
        'screen' => $screen,
        'unheard' => $unheard,
    ]); ?>

    <div class="tc-main">

      <?php view('partials/header', [
          'store' => $store,
          'screen' => $screen,
          'selectedDevice' => $selectedDevice,
          'headerTitle' => $headerTitle,
          'headerSub' => $headerSub,
      ]); ?>

      <main class="tc-content">
        <?php
        switch ($screen) {
            case 'phones':
                $selectedDevice !== null
                    ? view('screens/phone_detail', ['store' => $store, 'device' => $selectedDevice])
                    : view('screens/phones', ['store' => $store, 'devices' => $devices]);
                break;
            case 'contacts':
                view('screens/contacts', ['store' => $store, 'contacts' => $contacts]);
                break;
            case 'calllog':
                view('screens/calllog', ['store' => $store, 'calls' => $calls,
                    'filters' => $callFilters, 'page' => $callPage]);
                break;
            case 'voicemail':
                view('screens/voicemail', ['store' => $store, 'voicemails' => $voicemails]);
                break;
            case 'jokes':
                view('screens/jokes', ['store' => $store, 'jokes' => new JokeRepository(),
                    'page' => $callPage]);
                break;
            case 'guardians':
                view('screens/guardians', ['store' => $store]);
                break;
            case 'trunk':
                view('screens/trunk', ['store' => $store]);
                break;
            case 'dialplan':
                view('screens/dialplan', ['store' => $store, 'rules' => new DialplanRuleRepository()]);
                break;
            case 'system':
                view('screens/system', ['store' => $store]);
                break;
            case 'notifications':
                view('screens/notifications', ['store' => $store]);
                break;
            default:
                view('screens/dashboard', [
                    'store' => $store, 'activeCalls' => $activeCalls,
                    'devices' => $devices, 'calls' => $calls,
                ]);
        }
        ?>
      </main>

      <?php view('partials/bottomnav', ['screen' => $screen, 'unheard' => $unheard]); ?>
    </div>
  </div>
</div>

<?php
if ($deviceWizard > 0) {
    view('modals/device_wizard', [
        'store' => $store,
        'step' => $deviceWizard,
        'device' => $selectedDevice,
    ]);
}
if ($trunkWizard > 0) {
    view('modals/trunk_wizard', ['store' => $store, 'step' => $trunkWizard]);
}
if ($editingContact !== null) {
    view('modals/contact_editor', ['contact' => Presenter::contact(ContactRepository::toView($editingContact))]);
}
if ($passwordFor !== null) {
    view('modals/guardian_password', [
        'guardian' => $passwordFor,
        'isSelf' => (int) $passwordFor['id'] === (int) Auth::user()['id'],
    ]);
}
if ($listenCall !== null) {
    view('modals/listen', [
        'store' => $store,
        'call' => $listenCall,
        'devices' => $devices,
    ]);
}
if ($toast !== null) {
    echo '<div class="tc-toast" data-tc-toast role="status">' . e($toast) . '</div>';
}
?>

<script src="<?= e(asset('assets/js/twocans.js')) ?>" defer></script>
</body>
</html>
