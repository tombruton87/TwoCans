<?php
/**
 * Live listen-in.
 *
 * Listening rings a handset in the house and attaches it to the call — ChanSpy
 * runs on a channel, so there has to be a phone on the other end of it. The
 * parent picks which handset to use; the one already on the call is excluded,
 * since it can't listen to itself.
 *
 * @var Store            $store
 * @var array            $call    From LiveCalls::active()
 * @var DeviceRepository $devices
 */
$mode = $store->listenMode();
$closeUrl = url(['screen' => 'dashboard']);

$candidates = [];
foreach ($devices->all() as $row) {
    $d = DeviceRepository::toView($row);
    if ($d['id'] === $call['deviceId'] || !$d['available'] || !$d['online']) {
        continue;
    }
    $candidates[] = $d;
}

$modes = ['listen' => 'Listen', 'whisper' => 'Whisper', 'join' => 'Join'];
?>
<div class="tc-modal tc-modal--listen" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>"
     role="dialog" aria-modal="true" aria-label="Live call">
  <div class="tc-modal__panel tc-modal__panel--sm">

    <div class="tc-modal__head tc-modal__head--dark">
      <span class="tc-live-dot"></span>
      <div class="tc-modal__title">
        Live · <span data-tc-elapsed="<?= (int) $call['startTs'] ?>"><?= e(fmt_duration($call['seconds'])) ?></span>
      </div>
      <a class="tc-modal__close" href="<?= e($closeUrl) ?>" aria-label="Close">×</a>
    </div>

    <div class="tc-modal__body" style="padding:22px">

      <div class="tc-listen-parties">
        <div class="tc-listen-party">
          <?php if ($call['devicePhoto'] !== ''): ?>
            <img class="tc-device-photo" src="<?= e(url(['photo' => $call['devicePhoto']])) ?>" alt="">
          <?php else: ?>
            <div class="tc-listen-phone"></div>
          <?php endif; ?>
          <div class="tc-listen-party__label"><?= e($call['deviceName']) ?></div>
        </div>
        <div class="tc-listen-string"></div>
        <div class="tc-listen-party">
          <?php view('partials/avatar', [
              'photo' => $call['peerPhoto'], 'initial' => initial($call['peerName']),
              'color' => $call['peerColor'], 'size' => '60', 'alt' => $call['peerName'],
          ]); ?>
          <div class="tc-listen-party__label"><?= e($call['peerName']) ?></div>
        </div>
      </div>

      <?php view('partials/eq', ['variant' => 'live']); ?>

      <?php if ($candidates === []): ?>
        <div class="tc-note" style="margin-bottom:14px">
          No other phone is online to listen on. Listening works by ringing a
          handset in the house and joining it to the call, so one needs to be
          available.
        </div>
      <?php else: ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="listen_start">
          <input type="hidden" name="channel" value="<?= e($call['channel']) ?>">
          <input type="hidden" name="uniqueid" value="<?= e($call['uniqueid']) ?>">

          <div class="tc-mode-grid">
            <?php foreach ($modes as $key => $label): ?>
              <input class="tc-win-radio" type="radio" name="mode" id="mode-<?= e($key) ?>"
                     value="<?= e($key) ?>" <?= $mode === $key ? 'checked' : '' ?>>
              <label class="tc-mode-card <?= $mode === $key ? 'is-active' : '' ?>" for="mode-<?= e($key) ?>">
                <?= e($label) ?>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="tc-mode-desc"><?= e(Presenter::listenModeDescription($mode)) ?></div>

          <label class="tc-label tc-label--sm" style="display:block;margin-bottom:12px">
            Ring which phone to listen on?
            <select class="tc-input tc-input--white" name="listen_on">
              <?php foreach ($candidates as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['extension']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="tc-listen-note">
            For safety &amp; trust, listening in is noted in the call record.
          </div>

          <button class="tc-btn tc-btn--teal tc-btn--block" type="submit" style="padding:13px">
            Ring my phone and listen
          </button>
        </form>
      <?php endif; ?>

      <div style="display:flex;gap:10px;margin-top:10px">
        <a class="tc-btn tc-btn--ghost" href="<?= e($closeUrl) ?>" style="flex:1;padding:13px;justify-content:center">Close</a>
        <form method="post" action="/" style="flex:1;display:flex">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="call_end">
          <input type="hidden" name="channel" value="<?= e($call['channel']) ?>">
          <button class="tc-btn tc-btn--danger tc-btn--block" type="submit" style="padding:13px">End call</button>
        </form>
      </div>
    </div>
  </div>
</div>
