<?php
/**
 * One device's settings. Text and time fields save on blur (JS submits the
 * surrounding form); without JS the same forms still submit normally.
 *
 * @var Store $store
 * @var array $device
 */
$d = Presenter::device(DeviceRepository::toView($device));
?>
<div class="tc-stack tc-narrow">
  <a class="tc-back" href="<?= e(url(['screen' => 'phones'])) ?>">← All phones</a>

  <?php /* Its own form so picking a photo uploads immediately, without
           dragging the rest of the page's fields along. */ ?>
  <form id="device-photo-form" method="post" action="/" enctype="multipart/form-data" hidden>
    <?= form_fields() ?>
    <input type="hidden" name="action" value="device_photo">
    <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
  </form>
  <?php if ($d['photo'] !== '' && Auth::can('devices')): ?>
    <form id="device-photo-remove" method="post" action="/" hidden>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_photo_remove">
      <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
    </form>
  <?php endif; ?>

  <!-- Header card: inline rename -->
  <section class="tc-card tc-device-head" style="padding:22px">
    <?php
    // The picture itself, with or without the picker wrapped around it.
    $picture = function () use ($d): void { ?>
      <?php if ($d['photo'] !== ''): ?>
        <img class="tc-device-photo" src="<?= e(url(['photo' => $d['photo']])) ?>" alt="<?= e($d['name']) ?>">
      <?php else: ?>
        <span class="tc-can tc-can--xl <?= $d['online'] ? '' : 'is-offline' ?>" data-tc-can></span>
      <?php endif;
    }; ?>

    <?php if (Auth::can('devices')): ?>
      <label class="tc-photo-pick tc-photo-pick--device"
             title="<?= $d['photo'] !== '' ? 'Change this photo' : 'Add a photo' ?>">
        <?php $picture(); ?>
        <span class="tc-photo-pick__hint" aria-hidden="true">📷</span>
        <input type="file" id="device-photo-input" name="photo"
               accept="image/jpeg,image/png,image/webp" data-tc-photo
               form="device-photo-form"
               aria-label="<?= $d['photo'] !== '' ? 'Change this phone\'s photo' : 'Add a photo for this phone' ?>">
      </label>
    <?php else: ?>
      <?php $picture(); ?>
    <?php endif; ?>
    <form class="tc-grow" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_edit">
      <input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <input class="tc-inline-input" type="text" name="name" value="<?= e($d['name']) ?>" data-tc-autosave aria-label="Phone name">
      <div class="tc-card__hint" style="font-size:13px">
        <?= e($d['model']) ?> · <span data-tc-status-text><?= e($d['statusText']) ?></span> · tap the name to rename
        <?php if (Auth::can('devices')): ?>
          <?php /* A plain link as well as the picture: the camera badge alone
                   is easy to miss, and this is the whole point of the card. */ ?>
          · <label class="tc-link" for="device-photo-input">
              <?= $d['photo'] !== '' ? 'change photo' : 'add a photo' ?>
            </label>
          <?php if ($d['photo'] !== ''): ?>
            · <button class="tc-link" type="submit" form="device-photo-remove">remove photo</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </form>
    <div class="tc-device-head__side" data-tc-device-status="<?= e((string) $d['id']) ?>" data-tc-device-detail>
      <span class="tc-pill tc-pill--lg tc-pill--<?= e($d['statusMod']) ?>"
            data-tc-status-pill data-tc-status-mod="<?= e($d['statusMod']) ?>"><?= e($d['statusText']) ?></span>

      <?php if ($d['available'] && Auth::can('devices')): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_test_call">
          <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
          <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit"
                  data-tc-test-call
                  <?= $d['online'] ? '' : 'disabled title="This phone is not online"' ?>>☎ Test call</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($d['available'] && !$d['online']): ?>
    <div class="tc-note" style="margin:0">
      <?php if ($d['registered']): ?>
        This phone has signed in before but isn't reachable right now. Phone apps
        stop answering when they're closed or the screen has been off for a while —
        open Linphone again and it should come back within a few seconds.
      <?php else: ?>
        Waiting for Linphone to sign in with the details below. Leave this page
        open — it turns green on its own once the app signs in.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($d['available']): ?>
    <section class="tc-card" style="padding:20px 22px">
      <h2 class="tc-card__title" style="margin-bottom:4px">Numbers to dial</h2>
      <p class="tc-card__hint" style="margin:0 0 14px">
        From this phone, or any other phone on your line.
      </p>

      <div class="tc-dial tc-dial--self">
        <span class="tc-dial__num"><?= e($d['extension']) ?></span>
        <span class="tc-grow">
          <span class="tc-dial__label" style="display:block">This phone</span>
          <span class="tc-dial__sub" style="display:block">
            Dial it from another phone on the line to ring <?= e($d['name']) ?>.
            Calling it from this phone just rings itself.
          </span>
        </span>
      </div>

      <?php foreach (PjsipConfig::testNumbers() as $number => $test): ?>
        <div class="tc-dial">
          <span class="tc-dial__num"><?= e((string) $number) ?></span>
          <span class="tc-grow">
            <span class="tc-dial__label" style="display:block"><?= e($test['label']) ?></span>
            <span class="tc-dial__sub" style="display:block"><?= e($test['sub']) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="tc-card" style="padding:20px 22px">
      <h2 class="tc-card__title" style="margin-bottom:4px">Set up the app</h2>
      <p class="tc-card__hint" style="margin:0 0 14px">
        Scanning is the quick way — nothing to type, nothing to mistype.
      </p>
      <?php view('partials/provision_qr', ['d' => $d]); ?>

      <details class="tc-manual-setup">
        <summary>Or enter the details by hand</summary>
        <?php view('partials/sip_credentials', ['d' => $d]); ?>
      </details>
    </section>
  <?php endif; ?>

  <!-- Master in/out switches -->
  <section class="tc-card" style="padding:20px 22px">
    <h2 class="tc-card__title" style="margin-bottom:4px">Who can this phone call?</h2>
    <p class="tc-card__hint" style="margin:0 0 16px">Master switches for the whole phone. Fine-tune per person in Contacts.</p>

    <div style="display:flex;flex-direction:column;gap:12px">
      <?php
      $rules = [
          ['field' => 'allowIn',  'mod' => 'in',  'glyph' => '↙', 'title' => 'Incoming calls', 'hint' => 'Let approved people ring this phone'],
          ['field' => 'allowOut', 'mod' => 'out', 'glyph' => '↗', 'title' => 'Outgoing calls', 'hint' => 'Let this phone dial approved people'],
      ];
      foreach ($rules as $rule): ?>
        <div class="tc-rule-row">
          <div class="tc-rule-row__icon tc-rule-row__icon--<?= e($rule['mod']) ?>"><?= $rule['glyph'] ?></div>
          <div class="tc-grow">
            <div class="tc-rule-row__title"><?= e($rule['title']) ?></div>
            <div class="tc-rule-row__hint"><?= e($rule['hint']) ?></div>
          </div>
          <form method="post" action="/">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_toggle">
            <input type="hidden" name="id" value="<?= e($d['id']) ?>">
            <input type="hidden" name="field" value="<?= e($rule['field']) ?>">
            <button type="submit"
                    class="tc-switch <?= $d[$rule['field']] ? 'is-on' : '' ?>"
                    role="switch"
                    aria-checked="<?= $d[$rule['field']] ? 'true' : 'false' ?>"
                    aria-label="<?= e($rule['title']) ?>"></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Usable hours -->
  <section class="tc-card" style="padding:20px 22px">
    <h2 class="tc-card__title" style="margin-bottom:4px">When can it be used?</h2>
    <p class="tc-card__hint" style="margin:0 0 16px">Outside these hours the phone goes quiet (the SOS contact always rings).</p>
    <form method="post" action="/" style="display:flex;gap:12px;flex-wrap:wrap">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_edit">
      <input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <label class="tc-time-field">Open from
        <input type="time" name="timeFrom" value="<?= e($d['timeFrom']) ?>" data-tc-autosave>
      </label>
      <label class="tc-time-field">Until
        <input type="time" name="timeTo" value="<?= e($d['timeTo']) ?>" data-tc-autosave>
      </label>
      <noscript><button class="tc-btn tc-btn--teal" type="submit">Save hours</button></noscript>
    </form>
  </section>

  <!-- Blocked-call message -->
  <section class="tc-card" style="padding:20px 22px">
    <h2 class="tc-card__title" style="margin-bottom:4px">If someone not on the list calls…</h2>
    <p class="tc-card__hint" style="margin:0 0 14px">We'll play this friendly message, then hang up. No ring, no fuss.</p>
    <form method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_edit">
      <input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <textarea class="tc-textarea" name="blockedMsg" data-tc-autosave aria-label="Blocked call message"><?= e($d['blockedMsg']) ?></textarea>
      <noscript><button class="tc-btn tc-btn--teal" type="submit" style="margin-top:10px">Save message</button></noscript>
    </form>
    <!-- TODO(wire): render the message to audio and play it back. -->
    <button class="tc-preview-msg" type="button"><span>▶</span>Preview the message</button>
  </section>

  <?php if ($d['type'] === 'ghp621' && Auth::can('devices')): ?>
  <!-- Grandstream provisioning -->
  <section class="tc-card" style="padding:20px 22px">
    <h2 class="tc-card__title" style="margin-bottom:4px">Provisioning</h2>
    <p class="tc-card__hint" style="margin:0 0 14px;line-height:1.6">
      In the phone's web UI set the config server to
      <strong>http://<?= e(PjsipConfig::domain()) ?>:<?= (int) (getenv('HTTP_PORT') ?: 8083) ?>/grandstream/</strong>,
      username <strong>twocans</strong> and password
      <strong><?= e((new SettingsRepository())->provisionPass()) ?></strong>.
      The phone fetches its settings on boot and on reprovision.
    </p>
    <p class="tc-card__hint" style="margin:0 0 14px;line-height:1.6">
      Remote phonebook:
      <strong>http://twocans:<?= e((new SettingsRepository())->provisionPass()) ?>@<?= e(PjsipConfig::domain()) ?>:<?= (int) (getenv('HTTP_PORT') ?: 8083) ?>/phonebook/grandstream.xml</strong>
    </p>
    <form method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_mac">
      <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
      <label class="tc-label">MAC address
        <input class="tc-input" type="text" name="mac" value="<?= e($d['mac']) ?>" placeholder="00:0B:82:C1:23:45" autocomplete="off">
      </label>
      <button class="tc-btn tc-btn--teal" type="submit" style="margin-top:12px">Save MAC</button>
    </form>
  </section>

  <!-- Hotkeys -->
  <section class="tc-card" style="padding:20px 22px">
    <h2 class="tc-card__title" style="margin-bottom:4px">Hotkeys</h2>
    <p class="tc-card__hint" style="margin:0 0 14px">Pick who each key dials — press the key and it rings that person.</p>
    <form method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="hotkey_set">
      <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
      <?php
      $contactRepo = new ContactRepository();
      $assigned = (new DeviceHotkeyRepository())->forDevice((int) $d['id']);
      $services = PjsipConfig::testNumbers();
      foreach (GrandstreamProvisioning::HOTKEY_PCODES as $index => $code):
      ?>
        <label class="tc-label">Key <?= (int) $index ?>
          <select class="tc-input" name="hotkey[<?= (int) $index ?>]">
            <option value="">— none —</option>
            <?php foreach ($contactRepo->all() as $c): if (($c['number_e164'] ?? '') === '') continue; ?>
              <option value="<?= e($c['number_e164']) ?>" <?= ($assigned[$index] ?? '') === $c['number_e164'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?> — <?= e($c['number_e164']) ?>
              </option>
            <?php endforeach; ?>
            <?php foreach ($services as $number => $service): ?>
              <option value="<?= e($number) ?>" <?= ($assigned[$index] ?? '') === $number ? 'selected' : '' ?>>
                <?= e($service['label']) ?> — <?= e($number) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <button class="tc-btn tc-btn--teal" type="submit" style="margin-top:14px">Save hotkeys</button>
    </form>
  </section>
  <?php endif; ?>

  <?php if (Auth::can('devices')): ?>
  <form method="post" action="/">
    <?= form_fields() ?>
    <input type="hidden" name="action" value="device_remove">
    <input type="hidden" name="id" value="<?= e($d['id']) ?>">
    <button class="tc-btn tc-btn--outline-danger" type="submit">Remove this phone</button>
  </form>
  <?php endif; ?>
</div>
