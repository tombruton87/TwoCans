<?php
/**
 * Scan-or-paste set-up panel.
 *
 * The QR code is the mobile path; the copyable link below it is the desktop
 * path. A fresh, short-lived token is minted each time this renders, so leaving
 * the page open and coming back tomorrow does not leave a working credential
 * lying around in a stale QR code.
 *
 * @var array $d Device, via DeviceRepository::toView()
 */
$provisioning = new Provisioning();

$token = $provisioning->issue($d['id']);
$url = $provisioning->url($token);
$svg = $provisioning->qrSvg($url);
$minutes = (int) (Provisioning::TTL_SECONDS / 60);
?>
<div class="tc-qr">
  <div class="tc-qr__code">
    <?php if ($svg !== ''): ?>
      <?= $svg ?>
    <?php else: ?>
      <div class="tc-qr__fallback">QR code unavailable</div>
    <?php endif; ?>
  </div>

  <div class="tc-qr__body">
    <div class="tc-qr__title">Set it up on phone or desktop</div>
    <ol class="tc-qr__steps">
      <li>Open Linphone</li>
      <li>Choose <b>Fetch Remote Configuration</b></li>
      <li>Scan the QR code — or paste the link below on a desktop</li>
    </ol>

    <div class="tc-creds">
      <div class="tc-cred">
        <div class="tc-cred__main">
          <div class="tc-cred__label">Provisioning link</div>
          <div class="tc-cred__value"><?= e($url) ?></div>
        </div>
        <button class="tc-copy" type="button" data-tc-copy="<?= e($url) ?>" aria-label="Copy the provisioning link">⧉</button>
      </div>
    </div>

    <p class="tc-qr__note">
      Works for <?= $minutes ?> minutes.
      The phone or desktop must be on the same wi-fi as this server.
    </p>
  </div>
</div>
