<?php
/**
 * Add-a-phone wizard, each kind of phone its own way: pick the kind (an app,
 * a desk phone, an adapter) and for hardware the model → name it, with how it
 * connects (the app) or its MAC (hardware) → set it up: a QR code for the app,
 * the steps for a Grandstream, and for a desk phone its hotkeys next.
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
// Step 1 is two choices: the kind of phone, then (for hardware) the model.
$family = isset(DeviceRepository::FAMILIES[$_GET['family'] ?? '']) ? (string) $_GET['family'] : null;
$canAdd = Auth::can('devices');
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

      <?php if ($step === 1 && $family === null): ?>
        <?php
        // Grandstreams on the network that aren't added yet: pick one and its
        // model and MAC are filled in. A scan asked for a moment ago is still
        // running until its results are newer than the ask.
        $found = $canAdd ? (new FoundPhones())->unassigned() : [];
        $scan = Pager::scanResults();
        $askedAt = (int) ($_GET['scan'] ?? 0);
        $scanning = $askedAt > 0 && ($scan['running'] || ($scan['at'] ?? 0) < $askedAt) && time() - $askedAt < 30;
        ?>
        <div class="tc-wizard-found"<?= $scanning ? ' data-tc-reload-after="3"' : '' ?>>
          <div class="tc-row" style="justify-content:space-between;align-items:center;gap:10px">
            <div class="tc-wizard-found__title">
              <?= $found !== [] ? 'Found on your network' : 'Is it a Grandstream?' ?>
            </div>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_scan">
              <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit" <?= $scanning ? 'disabled' : '' ?>>
                <i class="fa-solid <?= $scanning ? 'fa-spinner fa-spin' : 'fa-magnifying-glass' ?>" aria-hidden="true"></i>
                <?= $scanning ? 'Looking…' : 'Look for phones' ?>
              </button>
            </form>
          </div>
          <?php if ($found === []): ?>
            <p class="tc-card__hint">
              <?= $scanning
                  ? 'Looking over your network for Grandstream phones and adapters…'
                  : ($scan['at'] !== null && $askedAt > 0
                      ? "None found that aren't added already. Check it's plugged in and has started up, then look again."
                      : 'Plug it in, let it start up, then look — twocans finds it and knows which model it is.') ?>
            </p>
          <?php else: ?>
            <div class="tc-stack tc-stack--tight">
              <?php foreach ($found as $f): ?>
                <form method="post" action="/" style="display:flex">
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="device_pick_found">
                  <input type="hidden" name="mac" value="<?= e($f['mac']) ?>">
                  <input type="hidden" name="type" value="<?= e($f['type']) ?>">
                  <button class="tc-provider-row tc-wizard-found__phone" type="submit" <?= $f['type'] === '' ? 'disabled' : '' ?>>
                    <span class="tc-provider-row__mark"><i class="fa-solid <?= DeviceRepository::isDesk($f['type']) ? 'fa-phone-flip' : ($f['type'] !== '' ? 'fa-plug' : 'fa-question') ?>" aria-hidden="true"></i></span>
                    <span class="tc-grow">
                      <span class="tc-provider-row__name" style="display:block"><?= e($f['label']) ?> <span class="tc-card__hint">at <?= e($f['ip']) ?></span></span>
                      <span class="tc-card__hint" style="display:block">
                        MAC <?= e(implode(':', str_split($f['mac'], 2))) ?>
                        <?= $f['type'] === '' ? ' · not a model twocans knows' : '' ?>
                      </span>
                    </span>
                    <?php if ($f['type'] !== ''): ?><span class="tc-wizard-pick__go">Add →</span><?php endif; ?>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="tc-wizard-title">What are you setting up?</div>
        <div class="tc-wizard-sub">Each is added its own way. Start with the app if you're not sure — it works on any phone or tablet you already have.</div>

        <div class="tc-stack tc-stack--tight">
          <?php foreach (DeviceRepository::FAMILIES as $key => $f): ?>
            <form method="post" action="/" style="display:flex">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_pick_family">
              <input type="hidden" name="family" value="<?= e($key) ?>">
              <button class="tc-provider-row tc-provider-row--active tc-wizard-pick" type="submit">
                <span class="tc-provider-row__mark"><i class="fa-solid <?= e($f['icon']) ?>" aria-hidden="true"></i></span>
                <span class="tc-grow">
                  <span class="tc-provider-row__name" style="display:block"><?= e($f['label']) ?></span>
                  <span class="tc-card__hint" style="display:block"><?= e($f['sub']) ?></span>
                </span>
                <span class="tc-wizard-pick__go">→</span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>

      <?php elseif ($step === 1): ?>
        <?php if ($family === 'desk'): ?>
          <div class="tc-wizard-title">Which desk phone?</div>
          <div class="tc-wizard-sub">The model is on the label underneath. They all work the same way — the 61x has three hotkeys, the 62x six.</div>
        <?php else: ?>
          <div class="tc-wizard-title">Which adapter?</div>
          <div class="tc-wizard-sub">An HT801 takes one corded phone; an HT802 takes two, each a phone of its own here.</div>
        <?php endif; ?>

        <div class="tc-wizard-models<?= $family === 'desk' ? ' tc-wizard-models--desk' : '' ?>">
          <?php foreach (DeviceRepository::typesIn($family) as $key => $type): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_pick_model">
              <input type="hidden" name="type" value="<?= e($key) ?>">
              <button class="tc-wizard-model" type="submit">
                <?php if ($family === 'desk'): ?>
                  <?php /* A little drawing of it: its colour, and its keys in their rows. */ ?>
                  <span class="tc-wizard-model__phone tc-wizard-model__phone--<?= e((string) $type['body']) ?>" aria-hidden="true">
                    <?php for ($i = 0; $i < $type['keys']; $i++): ?><span></span><?php endfor; ?>
                  </span>
                <?php else: ?>
                  <span class="tc-wizard-model__adapter" aria-hidden="true">
                    <?php for ($i = 0; $i < ($key === 'ht802' ? 2 : 1); $i++): ?><i class="fa-solid fa-phone"></i><?php endfor; ?>
                  </span>
                <?php endif; ?>
                <span class="tc-wizard-model__name"><?= e($type['label']) ?></span>
                <span class="tc-wizard-model__sub"><?= e(preg_replace('/^Grandstream (hotel phone|adapter) · /', '', $type['sub'])) ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>

        <div class="tc-note" style="margin:16px 0 0">
          <?= $family === 'desk'
              ? "You'll need the MAC address from the label underneath — it's how twocans hands the phone its settings. A W on the end of the model (GHP621W) is the Wi-Fi version, set up the same way."
              : "You'll need the MAC address from the label underneath — it's how twocans hands the adapter its settings." ?>
        </div>
        <div class="tc-wizard-actions">
          <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'phones', 'wizard' => 1])) ?>" style="padding:13px 18px">Back</a>
        </div>

      <?php elseif ($step === 2): ?>
        <div class="tc-wizard-title"><?= $draft['type'] === 'linphone' ? 'Name it, and pick how it connects' : 'Name it, and tell us which one it is' ?></div>
        <div class="tc-wizard-sub">A name kids recognise — like "Playroom Phone".</div>

        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_finish">

          <input class="tc-name-input" type="text" name="name" placeholder="Playroom Phone"
                 aria-label="Phone name" required autofocus>

          <?php $ata = in_array($draft['type'], ['ht801', 'ht802'], true); ?>
          <?php if ($ata && $draft['type'] === 'ht802'): ?>
            <div style="font:800 14px var(--tc-display);margin:4px 0 4px">Phone 2 socket <span class="tc-card__hint">(optional)</span></div>
            <input class="tc-name-input" type="text" name="name2" placeholder="Kitchen Phone"
                   aria-label="Name for the phone in socket 2">
            <div class="tc-note" style="margin-top:-4px">The name above is for the phone in socket 1. Leave this blank if only one is plugged in — you can add it later.</div>
          <?php endif; ?>

          <?php if ($draft['type'] === 'linphone'): ?>
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
          <input class="tc-name-input" type="text" name="mac" placeholder="00:0B:82:C1:23:45" value="<?= e(($draft['mac'] ?? '') !== '' ? implode(':', str_split((string) $draft['mac'], 2)) : '') ?>"
                 aria-label="MAC address" autocomplete="off" style="font-size:15px" required>
          <div class="tc-note">Printed on the underside of the <?= $ata ? 'adapter' : 'phone' ?> — used to serve its settings.</div>
          <?php endif; ?>

          <div class="tc-wizard-actions">
            <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'phones', 'wizard' => 1] + ($draft['type'] === 'linphone' ? [] : ['family' => DeviceRepository::TYPES[$draft['type']]['family'] ?? '']))) ?>"
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
            <?= $d['type'] !== 'linphone' ? 'Point the ' . ($d['ata'] ? 'adapter' : 'phone') . ' at twocans as below, then reboot it.' : 'Scan it on a phone, or copy the link on a desktop.' ?>
          </div>
        </div>

        <?php if ($d['family'] !== 'app'): ?>
          <div class="tc-manual-setup" style="padding:16px;text-align:left">
            <div style="font:800 14px var(--tc-display);margin-bottom:8px">Grandstream <?= e($d['model']) ?> setup</div>
            <?php view('partials/grandstream_setup', ['d' => $d]); ?>
          </div>
        <?php else: ?>
          <?php view('partials/provision_qr', ['d' => $d]); ?>

          <details class="tc-manual-setup">
            <summary>Or enter the details by hand</summary>
            <?php view('partials/sip_credentials', ['d' => $d]); ?>
          </details>
        <?php endif; ?>

        <?php if ($d['desk']): ?>
          <a class="tc-btn tc-btn--teal tc-btn--block" href="<?= e(url(['screen' => 'phones', 'device' => $d['id'], 'tab' => 'keys'])) ?>"
             style="padding:14px;font-size:16px;margin-top:16px">Next: who its <?= (int) $d['keys'] ?> hotkeys ring →</a>
        <?php else: ?>
          <a class="tc-btn tc-btn--teal tc-btn--block" href="<?= e(url(['screen' => 'phones', 'device' => $d['id']])) ?>"
             style="padding:14px;font-size:16px;margin-top:16px">Done</a>
        <?php endif; ?>

      <?php else: ?>
        <div class="tc-empty">That phone no longer exists.</div>
        <a class="tc-btn tc-btn--ghost tc-btn--block" href="<?= e($closeUrl) ?>">Back to phones</a>
      <?php endif; ?>

    </div>
  </div>
</div>
