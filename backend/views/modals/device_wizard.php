<?php
/**
 * Add-a-phone wizard: pick what kind → name it and choose a transport →
 * copy the credentials into the app.
 *
 * Step 3 is not a mock: by the time it renders, the device row exists, the
 * PJSIP endpoint has been written to the generated config, and Asterisk has
 * been reloaded. The phone can register the moment these are typed in.
 *
 * @var Store  $store
 * @var int    $step 1–3
 * @var ?array $device Set on step 3 — the freshly created device
 */
$draft = $store->deviceDraft();
$closeUrl = url(['screen' => 'phones']);
$d = $device !== null ? DeviceRepository::toView($device) : null;
?>
<div class="tc-modal" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>"
     role="dialog" aria-modal="true" aria-label="Add a phone">
  <div class="tc-modal__panel">

    <div class="tc-modal__head">
      <div class="tc-modal__title">Add a phone</div>
      <div class="tc-steps">
        <?php for ($i = 1; $i <= 3; $i++): ?>
          <span class="tc-step-dot <?= $step >= $i ? 'is-done' : '' ?>"></span>
        <?php endfor; ?>
      </div>
      <a class="tc-modal__close" href="<?= e($closeUrl) ?>" aria-label="Close">×</a>
    </div>

    <div class="tc-modal__body">

      <?php if ($step === 1): ?>
        <div class="tc-wizard-title">What are you setting up?</div>
        <div class="tc-wizard-sub">Start with the app — it works on any phone or tablet you already have.</div>

        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach (DeviceRepository::TYPES as $key => $type): ?>
            <?php if ($type['available']): ?>
              <form method="post" action="/" style="display:flex">
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_pick_model">
                <input type="hidden" name="type" value="<?= e($key) ?>">
                <button class="tc-provider-row tc-provider-row--active" type="submit"
                        style="border-color:var(--tc-teal-deep);background:var(--tc-teal-bg)">
                  <span class="tc-provider-row__mark" style="background:var(--tc-teal-deep)">L</span>
                  <span class="tc-grow">
                    <span class="tc-provider-row__name" style="display:block"><?= e($type['label']) ?></span>
                    <span class="tc-card__hint" style="display:block"><?= e($type['sub']) ?> · ready now</span>
                  </span>
                  <span style="color:var(--tc-teal-deep);font:800 18px var(--tc-display)">→</span>
                </button>
              </form>
            <?php else: ?>
              <div class="tc-provider-row tc-provider-row--soon">
                <span class="tc-provider-row__mark tc-provider-row__mark--soon">⬛</span>
                <span class="tc-grow">
                  <span class="tc-provider-row__name" style="display:block"><?= e($type['label']) ?></span>
                  <span class="tc-card__hint" style="display:block"><?= e($type['sub']) ?> · coming soon</span>
                </span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <div class="tc-note" style="margin:16px 0 0">
          The Grandstream adapters let an ordinary corded phone plug into the
          line. They need auto-provisioning, which isn't built yet.
        </div>

      <?php elseif ($step === 2): ?>
        <div class="tc-wizard-title">Name it, and pick how it connects</div>
        <div class="tc-wizard-sub">A name kids recognise — like "Playroom Phone".</div>

        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_finish">

          <input class="tc-name-input" type="text" name="name" placeholder="Playroom Phone"
                 aria-label="Phone name" required autofocus>

          <?php if ($draft['type'] !== 'ghp621'): ?>
          <div style="font:800 14px var(--tc-display);margin:4px 0 9px">Transport</div>
          <div class="tc-win-grid" style="margin-bottom:8px">
            <?php foreach (DeviceRepository::TRANSPORTS as $key => $t): ?>
              <?php if ($t['available']): ?>
                <input class="tc-win-radio" type="radio" name="transport" id="tr-<?= e($key) ?>"
                       value="<?= e($key) ?>" <?= $key === 'udp' ? 'checked' : '' ?>>
                <label class="tc-win-card tc-win-card--teal" for="tr-<?= e($key) ?>">
                  <span class="tc-win-card__label" style="display:block"><?= e($t['label']) ?></span>
                  <span class="tc-win-card__sub" style="display:block"><?= e($t['sub']) ?></span>
                </label>
              <?php else: ?>
                <span class="tc-win-card" style="opacity:.5;cursor:not-allowed">
                  <span class="tc-win-card__label" style="display:block"><?= e($t['label']) ?></span>
                  <span class="tc-win-card__sub" style="display:block"><?= e($t['sub']) ?></span>
                </span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <div class="tc-note">
            Linphone asks you to pick one of these. UDP is the simplest and works
            on a home network — you can change it later by adding the phone again.
          </div>
          <?php else: ?>
          <div style="font:800 14px var(--tc-display);margin:14px 0 4px">MAC address</div>
          <input class="tc-name-input" type="text" name="mac" placeholder="00:0B:82:C1:23:45"
                 aria-label="MAC address" autocomplete="off" style="font-size:15px">
          <div class="tc-note">Printed on the underside of the phone — used to serve its settings.</div>
          <?php endif; ?>

          <div class="tc-wizard-actions">
            <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'phones', 'wizard' => 1])) ?>"
               style="padding:13px 18px">Back</a>
            <button class="tc-btn tc-btn--coral tc-btn--grow" type="submit"
                    style="padding:13px;font-size:15px">Create it →</button>
          </div>
        </form>

      <?php elseif ($d !== null): ?>
        <div style="text-align:center;margin-bottom:16px">
          <div class="tc-success-tick">✓</div>
          <div class="tc-wizard-title" style="margin-bottom:0"><?= e($d['name']) ?> is ready</div>
          <div class="tc-card__hint" style="font-size:13px">
            <?= $d['type'] === 'ghp621' ? 'Point the phone at the server below, then reboot it.' : 'Scan it on a phone, or copy the link on a desktop.' ?>
          </div>
        </div>

        <?php if ($d['type'] === 'ghp621'): ?>
          <div class="tc-manual-setup" style="padding:16px;text-align:left">
            <div style="font:800 14px var(--tc-display);margin-bottom:8px">Grandstream GHP621 setup</div>
            <ol class="tc-qr__steps">
              <li>Plug the phone in and find its IP address (check your router, or the phone's menu).</li>
              <li>Open that IP in a browser to reach the phone's web UI.</li>
              <li>Set <b>Config Server Path</b> to <code>http://<?= e(PjsipConfig::domain()) ?>:<?= (int) (getenv('HTTP_PORT') ?: 8083) ?>/grandstream/</code></li>
              <li>Set the config username to <b>twocans</b> and the password to <code><?= e((new SettingsRepository())->provisionPass()) ?></code></li>
              <li>Set the remote phonebook to <code>http://twocans:<?= e((new SettingsRepository())->provisionPass()) ?>@<?= e(PjsipConfig::domain()) ?>:<?= (int) (getenv('HTTP_PORT') ?: 8083) ?>/phonebook/grandstream.xml</code></li>
              <li>Save, then reboot the phone — it fetches its settings on boot.</li>
            </ol>
          </div>
        <?php else: ?>
          <?php view('partials/provision_qr', ['d' => $d]); ?>

          <details class="tc-manual-setup">
            <summary>Or enter the details by hand</summary>
            <?php view('partials/sip_credentials', ['d' => $d]); ?>
          </details>
        <?php endif; ?>

        <a class="tc-btn tc-btn--teal tc-btn--block" href="<?= e(url(['screen' => 'phones', 'device' => $d['id']])) ?>"
           style="padding:14px;font-size:16px;margin-top:16px">Done</a>

      <?php else: ?>
        <div class="tc-empty">That phone no longer exists.</div>
        <a class="tc-btn tc-btn--ghost tc-btn--block" href="<?= e($closeUrl) ?>">Back to phones</a>
      <?php endif; ?>

    </div>
  </div>
</div>
