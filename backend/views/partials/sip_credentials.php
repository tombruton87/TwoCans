<?php
/**
 * SIP account details, laid out to match Linphone's own setup screen
 * field-for-field so a parent can work straight down the list.
 *
 * @var array $d Device, already through DeviceRepository::toView()
 */
$transport = $d['transport'];
$domain = PjsipConfig::domain();
$uri = PjsipConfig::registrarUri($transport);

$main = [
    ['Username', $d['sipUsername'], ''],
    ['Password', $d['sipSecret'], ''],
    ['Domain', $domain, 'Linphone shows sip.linphone.org here as an example — replace it with this'],
    ['Display name', $d['name'], 'What the other person sees'],
    ['Transport', strtoupper($transport), 'Choose this one from the list'],
];

$advanced = [
    ['Authentication ID', '', 'Leave blank — it is the same as the username'],
    ['Registrar URI', $uri, ''],
    ['Outbound SIP proxy URI', $uri, 'Same as the registrar'],
];
?>
<div class="tc-creds">
  <?php foreach ($main as [$label, $value, $note]): ?>
    <div class="tc-cred">
      <div class="tc-cred__main">
        <div class="tc-cred__label"><?= e($label) ?></div>
        <div class="tc-cred__value"><?= e($value) ?></div>
        <?php if ($note !== ''): ?><div class="tc-cred__note"><?= e($note) ?></div><?php endif; ?>
      </div>
      <button class="tc-copy" type="button" data-tc-copy="<?= e($value) ?>" aria-label="Copy <?= e($label) ?>">⧉</button>
    </div>
  <?php endforeach; ?>

  <details class="tc-cred__advanced">
    <summary>Advanced settings</summary>
    <?php foreach ($advanced as [$label, $value, $note]): ?>
      <div class="tc-cred">
        <div class="tc-cred__main">
          <div class="tc-cred__label"><?= e($label) ?></div>
          <div class="tc-cred__value <?= $value === '' ? 'tc-cred__value--empty' : '' ?>">
            <?= $value === '' ? 'leave blank' : e($value) ?>
          </div>
          <?php if ($note !== ''): ?><div class="tc-cred__note"><?= e($note) ?></div><?php endif; ?>
        </div>
        <?php if ($value !== ''): ?>
          <button class="tc-copy" type="button" data-tc-copy="<?= e($value) ?>" aria-label="Copy <?= e($label) ?>">⧉</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </details>
</div>

<div class="tc-note" style="margin:14px 0 0">
  The phone must be on the same wi-fi as this server. If Linphone says it
  cannot reach the server, it is probably on mobile data.
</div>
