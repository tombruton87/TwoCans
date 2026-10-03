<?php
/**
 * One device's settings. Text and time fields save on blur (JS submits the
 * surrounding form); without JS the same forms still submit normally.
 *
 * In tabs, so each kind of phone shows what it has: Rules (calls, hours,
 * limits, what an unknown caller hears — the same for every phone), Keys (a
 * desk phone's hotkeys, its faceplate and how it's paged) and Setup (the QR
 * code for the app, or a Grandstream's provisioning; its account; the numbers
 * to dial). Each tab is a link of its own, and works without JavaScript; two
 * columns on a wide screen, one once it's narrow.
 *
 * @var Store $store
 * @var array $device
 */
$d = Presenter::device(DeviceRepository::toView($device));
$canEdit = Auth::can('devices');

$tabs = ['rules' => 'Rules'];
if ($d['desk'] && $canEdit) {
    $tabs['keys'] = $d['faceplate'] !== null ? 'Keys & faceplate' : 'Speed-dial keys';
}
if (PhoneSettings::has($d['type']) && $canEdit) {
    $tabs['settings'] = 'Phone settings';
}
$tabs['setup'] = 'Setup';
// A phone that has never signed in opens on how to set it up.
$tab = (string) ($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) {
    $tab = $d['available'] && !$d['registered'] ? 'setup' : 'rules';
}
$tabUrl = static fn(string $t): string => url(['screen' => 'phones', 'device' => $d['id'], 'tab' => $t]);
?>
<div class="tc-stack tc-device">
  <a class="tc-back" href="<?= e(url(['screen' => 'phones'])) ?>">← All phones</a>

  <?php /* Its own form so picking a photo uploads immediately, without
           dragging the rest of the page's fields along. */ ?>
  <form id="device-photo-form" method="post" action="/" enctype="multipart/form-data" hidden>
    <?= form_fields() ?>
    <input type="hidden" name="action" value="device_photo">
    <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
  </form>
  <?php if ($d['photo'] !== '' && $canEdit): ?>
    <form id="device-photo-remove" method="post" action="/" hidden>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_photo_remove">
      <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
    </form>
  <?php endif; ?>

  <!-- Header card: inline rename -->
  <section class="tc-card tc-device-head<?= $d['adult'] ? ' is-adult' : '' ?>">
    <?php
    // The picture itself, with or without the picker wrapped around it.
    $picture = function () use ($d): void { ?>
      <?php if ($d['photo'] !== ''): ?>
        <img class="tc-device-photo" src="<?= e(url(['photo' => $d['photo']])) ?>" alt="<?= e($d['name']) ?>">
      <?php else: ?>
        <span class="tc-can tc-can--xl <?= $d['online'] ? '' : 'is-offline' ?>" data-tc-can></span>
      <?php endif;
    }; ?>

    <?php if ($canEdit): ?>
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
    <form class="tc-grow" method="post" action="/" data-tc-ajax>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_edit">
      <input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <input class="tc-inline-input" type="text" name="name" value="<?= e($d['name']) ?>" data-tc-autosave aria-label="Phone name">
      <div class="tc-card__hint tc-device-head__meta">
        <?= e($d['model']) ?><?php if ($d['untested']): ?> <span class="tc-untested" title="Set up from its maker's documentation — not yet tried with a real one">Untested</span><?php endif; ?> · <span data-tc-status-text><?= e($d['statusText']) ?></span> · tap the name to rename
        <?php if ($canEdit): ?>
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
      <?php /* What the phone itself has told twocans — see GrandstreamProvisioning's
               event addresses. Off the hook for a few minutes with nobody on a
               call: left off, so calls to it can't get through. */ ?>
      <?php if ($d['desk'] && $d['offhookSince'] !== null && time() - $d['offhookSince'] > 180
                && !in_array($d['id'], array_column((new LiveCalls())->active(), 'deviceId'), true)): ?>
        <div class="tc-offhook" role="status">
          <i class="fa-solid fa-phone-slash" aria-hidden="true"></i>
          Its handset has been off the hook since <?= e(date('g:ia', $d['offhookSince'])) ?>
          <?= date('Y-m-d', $d['offhookSince']) !== date('Y-m-d') ? e(date('D j M', $d['offhookSince'])) : '' ?>
          — calls to it can't get through until it's put back.
        </div>
      <?php endif; ?>
      <?php if ($d['desk'] && $d['startedAt'] !== null): ?>
        <div class="tc-card__hint">Last started up <?= e($d['startedAt']) ?>.</div>
      <?php endif; ?>
    </form>
    <div class="tc-device-head__side" data-tc-device-status="<?= e((string) $d['id']) ?>" data-tc-device-detail>
      <span class="tc-pill tc-pill--lg tc-pill--<?= e($d['statusMod']) ?>"
            data-tc-status-pill data-tc-status-mod="<?= e($d['statusMod']) ?>"><?= e($d['statusText']) ?></span>
      <?php if ($d['adult']): ?>
        <span class="tc-adult-badge"><i class="fa-solid fa-unlock" aria-hidden="true"></i> Adult mode</span>
      <?php endif; ?>

      <?php if ($d['available'] && $canEdit): ?>
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
    <div class="tc-note tc-note--flush">
      <?php if ($d['registered']): ?>
        <?php if ($d['type'] === 'linphone'): ?>
        This phone has signed in before but isn't reachable right now. Phone apps
        stop answering when they're closed or the screen has been off for a while —
        open Linphone again and it should come back within a few seconds.
        <?php else: ?>
        This <?= $d['ata'] ? 'adapter' : 'phone' ?> has signed in before but isn't reachable right now —
        check it's plugged in and has power. It comes back within a minute or so of starting.
        <?php endif; ?>
      <?php elseif ($d['type'] !== 'linphone'): ?>
        Waiting for the <?= $d['ata'] ? 'adapter' : 'phone' ?> to sign in — follow the steps under
        <a class="tc-link" href="<?= e($tabUrl('setup')) ?>">Setup</a>, then reboot it. This turns green on its own once it signs in.
      <?php else: ?>
        Waiting for Linphone to sign in with the details under <a class="tc-link" href="<?= e($tabUrl('setup')) ?>">Setup</a>.
        Leave this page open — it turns green on its own once the app signs in.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($d['adult']): ?>
    <?php /* Loud on purpose: a child's phone must never sit in this mode unnoticed. */ ?>
    <section class="tc-adult-banner" role="status">
      <i class="fa-solid fa-triangle-exclamation tc-adult-banner__icon" aria-hidden="true"></i>
      <div class="tc-grow">
        <div class="tc-adult-banner__title">Adult mode is on — no restrictions apply to this phone</div>
        <div class="tc-adult-banner__text">
          It can call any number at any time, anyone can ring it, and bedtime, hours,
          call limits and the dial plan are all ignored. Only for a grown-up's own phone.
        </div>
      </div>
      <?php if ($canEdit): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_adult">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <input type="hidden" name="on" value="0">
          <button class="tc-btn tc-btn--white" type="submit">Turn adult mode off</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php /* Pause it for a while — homework, tidy-up time: no calls in or out
           and no fun lines, until it lets itself go. 999 and its messages
           still work. See DeviceRepository::pause(). */ ?>
  <?php if ($canEdit && $d['available']): ?>
    <section class="tc-card tc-pause<?= $d['pausedUntil'] !== null ? ' is-paused' : '' ?>" id="device-pause" data-tc-ajax-region>
      <?php if ($d['pausedUntil'] !== null): ?>
        <div class="tc-grow">
          <div class="tc-pause__title"><i class="fa-solid fa-circle-pause" aria-hidden="true"></i>
            Paused until <?= e(date('g:ia', $d['pausedUntil'])) ?><?= date('Y-m-d', $d['pausedUntil']) !== date('Y-m-d') ? ' tomorrow' : '' ?></div>
          <div class="tc-card__hint">It doesn't ring or call out, and the fun lines are off. 999 and its messages still work.</div>
        </div>
        <form method="post" action="/" data-tc-ajax data-tc-ajax-go>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_resume">
          <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
          <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Resume now</button>
        </form>
      <?php else: ?>
        <div class="tc-grow">
          <div class="tc-pause__title"><i class="fa-solid fa-circle-pause" aria-hidden="true"></i> Pause it for a while</div>
          <div class="tc-card__hint">Homework or tidy-up time: no calls in or out and no fun lines, then it turns itself back on.</div>
        </div>
        <form method="post" action="/" class="tc-row" data-tc-ajax data-tc-ajax-go>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_pause">
          <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
          <label class="tc-sr-only" for="pause-for-<?= (int) $d['id'] ?>">For how long</label>
          <select class="tc-input" name="for" id="pause-for-<?= (int) $d['id'] ?>" style="max-width:170px">
            <option value="30">30 minutes</option>
            <option value="60" selected>An hour</option>
            <option value="120">Two hours</option>
            <option value="morning">Until the morning</option>
          </select>
          <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Pause</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <nav class="tc-tabs" aria-label="<?= e($d['name']) ?>">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="tc-tabs__tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>"
         <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'rules'): ?>
  <div class="tc-device__grid">
    <div class="tc-stack">
      <?php if ($d['adult']): ?>
        <section class="tc-card tc-adult-off">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Calls</h2>
            <p class="tc-card__hint">
              None of this phone's rules apply while it's in adult mode — the in and out
              switches, its hours and its call limits are kept, and come back into force
              when adult mode is turned off.
            </p>
          </div>
        </section>
      <?php else: ?>
      <section id="device-calls" data-tc-ajax-region class="tc-card">
        <div class="tc-card__intro">
          <h2 class="tc-card__title">Calls</h2>
          <p class="tc-card__hint">Master switches for the whole phone. Fine-tune per person in People.</p>
        </div>

        <div class="tc-stack tc-stack--tight">
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
              <form method="post" action="/" data-tc-ajax>
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

        <div class="tc-card__intro tc-card__intro--sub">
          <h3 class="tc-card__subtitle">When can it be used?</h3>
          <p class="tc-card__hint">
            Outside these times the phone goes quiet — an SOS contact still rings.
            <b><?= e(Schedule::describe($d['hours'])) ?></b>
          </p>
        </div>
        <?php if ($canEdit): ?>
          <form method="post" action="/" data-tc-ajax>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_hours">
            <input type="hidden" name="id" value="<?= e($d['id']) ?>">
            <?php view('partials/schedule_editor', ['field' => 'schedule', 'rules' => $d['hours']]); ?>
            <button class="tc-btn tc-btn--teal tc-mt-14" type="submit">Save hours</button>
          </form>
        <?php endif; ?>

        <?php
        /*
         * Call limits. Choices rather than a free number: a parent picks
         * "half an hour", not "27". SOS and "always put through" calls, and
         * emergency numbers, are never cut off — see PjsipConfig::renderLimits().
         */
        $callChoices = [null => 'No limit', 5 => '5 minutes', 10 => '10 minutes', 15 => '15 minutes',
            20 => '20 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '1 hour'];
        $dailyChoices = [null => 'No limit', 15 => '15 minutes', 30 => '30 minutes', 45 => '45 minutes',
            60 => '1 hour', 90 => '1½ hours', 120 => '2 hours', 180 => '3 hours'];
        $usedToday = (new DeviceRepository())->minutesToday((int) $d['id']);
        ?>
        <div class="tc-card__intro tc-card__intro--sub">
          <h3 class="tc-card__subtitle">Call limits</h3>
          <p class="tc-card__hint">
            A minute before a call reaches its limit the phone beeps, then the call
            ends. Calls to and from an SOS or “always put through” contact, and
            emergency calls, are never cut off.
            <?php if ($d['dailyMinutes'] !== null): ?>
              <b><?= (int) $usedToday ?> of <?= (int) $d['dailyMinutes'] ?> minutes used today.</b>
            <?php else: ?>
              <?= (int) $usedToday ?> minute<?= $usedToday === 1 ? '' : 's' ?> on calls today.
            <?php endif; ?>
          </p>
        </div>
        <form method="post" action="/" class="tc-row tc-row--wrap" data-tc-ajax>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_limits">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <label class="tc-label tc-label--sm">Longest single call
            <select class="tc-input" name="maxCall" data-tc-autosave <?= $canEdit ? '' : 'disabled' ?>>
              <?php foreach ($callChoices as $value => $label): ?>
                <option value="<?= e((string) $value) ?>"<?= $d['maxCallMinutes'] === ($value === '' ? null : $value) ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tc-label tc-label--sm">Total each day
            <select class="tc-input" name="daily" data-tc-autosave <?= $canEdit ? '' : 'disabled' ?>>
              <?php foreach ($dailyChoices as $value => $label): ?>
                <option value="<?= e((string) $value) ?>"<?= $d['dailyMinutes'] === ($value === '' ? null : $value) ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <noscript><button class="tc-btn tc-btn--teal" type="submit">Save limits</button></noscript>
        </form>

        <div class="tc-card__intro tc-card__intro--sub">
          <h3 class="tc-card__subtitle">Rings before voicemail</h3>
          <p class="tc-card__hint">
            How long it rings before the caller is offered a message. When a call
            rings several phones, it goes to voicemail once the longest of them stops.
          </p>
        </div>
        <form method="post" action="/" class="tc-row tc-row--wrap" data-tc-ajax>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_rings">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <label class="tc-label tc-label--sm">Rings
            <select class="tc-input" name="rings" data-tc-autosave <?= $canEdit ? '' : 'disabled' ?>>
              <?php foreach (DeviceRepository::RING_CHOICES as $n): ?>
                <option value="<?= $n ?>"<?= $d['rings'] === $n ? ' selected' : '' ?>>
                  <?= $n ?> rings — about <?= $n * DeviceRepository::RING_SECONDS ?> seconds<?= $n === DeviceRepository::DEFAULT_RINGS ? ' (standard)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <noscript><button class="tc-btn tc-btn--teal" type="submit">Save</button></noscript>
        </form>

        <?php /* Its walkie-talkie: dialling 9255 (or a hotkey for it) makes the
                 phone chosen here answer by itself on speaker — see
                 PjsipConfig::renderWalkie(). Only a phone that can answer by itself. */ ?>
        <?php
        $walkieTargets = array_values(array_filter(
            array_map([DeviceRepository::class, 'toView'], (new DeviceRepository())->all()),
            static fn(array $o): bool => $o['id'] !== $d['id'] && $o['autoAnswer'] && $o['available']
        ));
        ?>
        <?php if ($walkieTargets !== []): ?>
          <div class="tc-rule-row tc-mt-14" id="walkie" data-tc-ajax-region>
            <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-walkie-talkie" aria-hidden="true"></i></div>
            <div class="tc-grow">
              <div class="tc-rule-row__title">Walkie-talkie</div>
              <div class="tc-rule-row__hint">
                Dial <?= e((new SettingsRepository())->walkieNumber()) ?> (or a hotkey for it) and the phone chosen here
                answers by itself on speaker, with a beep, to talk both ways. Never into a call, and not at bedtime.
              </div>
            </div>
            <?php if ($canEdit): ?>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_walkie">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <label class="tc-sr-only" for="walkie-<?= (int) $d['id'] ?>">Walkie-talkie to</label>
                <select class="tc-input" name="to" id="walkie-<?= (int) $d['id'] ?>" data-tc-autosave style="max-width:200px">
                  <option value="0">Nobody</option>
                  <?php foreach ($walkieTargets as $o): ?>
                    <option value="<?= (int) $o['id'] ?>"<?= $d['walkieTo'] === $o['id'] ? ' selected' : '' ?>><?= e($o['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php /* Its favourite radio station: dialling the radio plays it straight
                 away, instead of the menu. See Radio. */ ?>
        <?php $radioStations = (new Radio())->stations(); ?>
        <?php if ($radioStations !== []): ?>
          <div class="tc-rule-row tc-mt-14" id="radio-favourite" data-tc-ajax-region>
            <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-radio" aria-hidden="true"></i></div>
            <div class="tc-grow">
              <div class="tc-rule-row__title">Favourite radio station</div>
              <div class="tc-rule-row__hint">
                Dialling the radio (<?= e((new SettingsRepository())->radioNumber()) ?>) plays it straight away. ★ still goes to the menu.
              </div>
            </div>
            <?php if ($canEdit): ?>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_radio_station">
                <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <label class="tc-sr-only" for="radio-fav-<?= (int) $d['id'] ?>">Favourite radio station</label>
                <select class="tc-input" name="station" id="radio-fav-<?= (int) $d['id'] ?>" data-tc-autosave style="max-width:200px">
                  <option value="0">None — ask each time</option>
                  <?php foreach ($radioStations as $st): ?>
                    <option value="<?= (int) $st['id'] ?>"<?= $d['radioStation'] === (int) $st['id'] ? ' selected' : '' ?>><?= e((string) $st['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php /* The house's mailbox on 701: off unless allowed — a message for the
                 house may be for a grown-up. See PjsipConfig::HOUSE_MESSAGES_NUMBER. */ ?>
        <div class="tc-rule-row tc-mt-14">
          <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-inbox" aria-hidden="true"></i></div>
          <div class="tc-grow">
            <div class="tc-rule-row__title">Can hear the house's messages</div>
            <div class="tc-rule-row__hint">
              By dialling <?= e(PjsipConfig::HOUSE_MESSAGES_NUMBER) ?>. For a grown-up's phone —
              a message left for the house may not be for a child.
            </div>
          </div>
          <?php if ($canEdit): ?>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_toggle">
              <input type="hidden" name="id" value="<?= e($d['id']) ?>">
              <input type="hidden" name="field" value="houseMessages">
              <button type="submit" class="tc-switch <?= $d['houseMessages'] ? 'is-on' : '' ?>" role="switch"
                      aria-checked="<?= $d['houseMessages'] ? 'true' : 'false' ?>" aria-label="Can hear the house's messages"></button>
            </form>
          <?php endif; ?>
        </div>

        <?php /* Listening to a room: this phone's room (it answers by itself, one
                 way, with a beep), and whether this phone may listen to others'.
                 Both are listening in, so both take the listen permission. See
                 RoomListen and migration 058. */ ?>
        <?php if (Auth::can('listen')): ?>
          <?php if ($d['autoAnswer']): ?>
            <div class="tc-rule-row tc-mt-14" id="room-listen" data-tc-ajax-region>
              <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-ear-listen" aria-hidden="true"></i></div>
              <div class="tc-grow">
                <div class="tc-rule-row__title">Its room can be listened to</div>
                <div class="tc-rule-row__hint">
                  Like a baby monitor: a grown-up's phone rings this one, which answers by itself
                  on speaker and sends the room's sound one way. It beeps as it picks up and shows
                  "Listening in" on its screen — never secret. Never breaks into a call.
                  <?php if ($d['roomListen'] && $d['extension'] !== ''): ?>
                    From a phone allowed to listen, dial <b><?= e(PjsipConfig::ROOM_LISTEN_PREFIX . $d['extension']) ?></b>.
                  <?php endif; ?>
                </div>
                <?php if ($d['roomListen']): ?>
                  <?php
                  $listeners = array_values(array_filter(
                      array_map([DeviceRepository::class, 'toView'], (new DeviceRepository())->all()),
                      static fn(array $o): bool => $o['canRoomListen'] && $o['id'] !== $d['id']
                  ));
                  $listens = (new RoomListen());
                  $listens->import();
                  $recentListens = $listens->recentFor($d['id']);
                  ?>
                  <?php if ($listeners === []): ?>
                    <p class="tc-card__hint tc-mt-8">No phone may listen yet — switch on <i>Can listen to rooms</i> on a grown-up's phone.</p>
                  <?php else: ?>
                    <form method="post" action="/" class="tc-row tc-row--wrap tc-mt-8" data-tc-ajax>
                      <?= form_fields() ?>
                      <input type="hidden" name="action" value="room_listen_start">
                      <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                      <label class="tc-sr-only" for="listen-on-<?= (int) $d['id'] ?>">Listen on</label>
                      <select class="tc-input" name="listen_on" id="listen-on-<?= (int) $d['id'] ?>" style="max-width:220px">
                        <?php foreach ($listeners as $o): ?>
                          <option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?><?= $o['online'] ? '' : ' (offline)' ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit"><i class="fa-solid fa-ear-listen" aria-hidden="true"></i> Listen now</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($recentListens !== []): ?>
                    <p class="tc-card__hint tc-mt-8">Last listened:
                      <?= e(implode(' · ', array_map(static fn(array $l): string => $l['listener'] . ', ' . $l['when'], $recentListens))) ?></p>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="room_listen_switch">
                <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <input type="hidden" name="field" value="roomListen">
                <button type="submit" class="tc-switch <?= $d['roomListen'] ? 'is-on' : '' ?>" role="switch"
                        aria-checked="<?= $d['roomListen'] ? 'true' : 'false' ?>" aria-label="Its room can be listened to"></button>
              </form>
            </div>
          <?php endif; ?>

          <div class="tc-rule-row tc-mt-14" id="can-room-listen" data-tc-ajax-region>
            <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-headphones" aria-hidden="true"></i></div>
            <div class="tc-grow">
              <div class="tc-rule-row__title">Can listen to rooms</div>
              <div class="tc-rule-row__hint">
                For a grown-up's phone: it may listen to the rooms of phones that allow it, by dialling
                <?= e(PjsipConfig::ROOM_LISTEN_PREFIX) ?> and their extension.
              </div>
            </div>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="room_listen_switch">
              <input type="hidden" name="id" value="<?= e($d['id']) ?>">
              <input type="hidden" name="field" value="canRoomListen">
              <button type="submit" class="tc-switch <?= $d['canRoomListen'] ? 'is-on' : '' ?>" role="switch"
                      aria-checked="<?= $d['canRoomListen'] ? 'true' : 'false' ?>" aria-label="Can listen to rooms"></button>
            </form>
          </div>
        <?php endif; ?>
      </section>

      <?php endif; ?>
    </div>

    <div class="tc-stack">
      <!-- The message a caller who isn't on the list hears -->
      <section id="device-refusal" data-tc-ajax-region class="tc-card">
        <div class="tc-card__intro">
          <h2 class="tc-card__title">If someone not on the list calls…</h2>
          <p class="tc-card__hint">
            They hear this and the phone doesn't ring. Each phone can have its own,
            so a caller is refused in the voice of the one that would have rung.
            Without one, they hear the house default from
            <a class="tc-link" href="<?= e(url(['screen' => 'greetings'])) ?>">Greetings</a>.
          </p>
        </div>

        <?php if ($d['refusalAudio'] !== ''): ?>
          <div class="tc-vm-row tc-vm-row--bare">
            <?php /* Same player as voicemail and the joke line, so audio behaves the
                     same wherever it turns up. */ ?>
            <audio data-audio preload="none"
                   src="<?= e(url(['download' => 'refusal_audio', 'id' => $d['id']])) ?>"></audio>
            <button class="tc-vm-play" type="button" data-play aria-label="Play this message">▶</button>
            <div class="tc-grow">
              <?php /* The words themselves are in the box below, so they aren't
                       repeated here as a heading. */ ?>
              <div class="tc-vm-row__name">This phone's message</div>
              <div class="tc-call-row__meta">
                <?= e(fmt_duration($d['refusalSeconds'])) ?>
                <?php if ($d['refusalStatus'] === 'pending' || $d['refusalStatus'] === 'running'): ?>
                  · <span class="tc-transcribing">writing down what it says…</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($canEdit): ?>
              <form method="post" action="/" class="tc-inline-form" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_refusal_remove">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <button class="tc-link" type="submit">remove</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if (!$canEdit && $d['refusalTranscript'] !== ''): ?>
          <p class="tc-card__hint tc-card__lead">
            The wording for this phone: “<?= e($d['refusalTranscript']) ?>”
          </p>
        <?php endif; ?>

        <?php if ($canEdit): ?>
          <form method="post" action="/" enctype="multipart/form-data" class="tc-row tc-row--wrap" data-tc-ajax>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_refusal_message">
            <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
            <label class="tc-btn tc-btn--ghost tc-audio-file">
              <span data-tc-filename>Choose a file</span>
              <input type="file" name="message"
                     accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                     data-tc-audiofile data-tc-rec-max="30" required>
            </label>
            <button class="tc-btn tc-btn--teal" type="submit">
              <?= $d['refusalAudio'] === '' ? 'Save message' : 'Replace message' ?>
            </button>
          </form>
          <p class="tc-card__hint tc-card__after">
            Up to 30 seconds — any audio file or voice memo will do.
          </p>

          <form method="post" action="/" class="tc-device__wording" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_refusal_transcript">
              <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
            <?php /* Written down from the audio by the transcription worker and
                     correctable here. Before anything is recorded it holds the
                     wording to read out — see migration 029. */ ?>
            <label class="tc-label tc-label--sm"><?= $d['refusalAudio'] !== '' ? 'What it says' : 'Wording to read out' ?>
              <textarea class="tc-textarea" name="transcript" data-tc-autosave
                        placeholder="<?= $d['refusalAudio'] !== ''
                            ? 'We write this down from the recording — correct it if we misheard.'
                            : 'Jot down what you\'ll say, then record it.' ?>"><?= e($d['refusalTranscript']) ?></textarea>
            </label>
            <noscript><button class="tc-btn tc-btn--teal" type="submit">Save wording</button></noscript>
          </form>
        <?php endif; ?>
      </section>

      <!-- Saying who's calling -->
      <?php
      $people = array_filter((new ContactRepository())->all(), static fn(array $r): bool => (int) ($r['is_group'] ?? 0) !== 1);
      $named = count(array_filter($people, static fn(array $r): bool => (string) ($r['announce_clip'] ?? '') !== ''));
      ?>
      <section id="device-announce" data-tc-ajax-region class="tc-card">
        <div class="tc-rule-row">
          <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></div>
          <div class="tc-grow">
            <div class="tc-rule-row__title">Say who's calling</div>
            <div class="tc-rule-row__hint">
              When it's picked up, plays the caller's name — “It's Nana!” — then connects.
              <?= $d['type'] === 'linphone' ? 'The app shows their name and photo already.' : 'For a phone with no screen.' ?>
            </div>
          </div>
          <?php if ($canEdit): ?>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_toggle">
              <input type="hidden" name="id" value="<?= e($d['id']) ?>">
              <input type="hidden" name="field" value="announceCaller">
              <button type="submit" class="tc-switch <?= $d['announceCaller'] ? 'is-on' : '' ?>" role="switch"
                      aria-checked="<?= $d['announceCaller'] ? 'true' : 'false' ?>" aria-label="Say who's calling"></button>
            </form>
          <?php endif; ?>
        </div>
        <?php if ($d['announceCaller']): ?>
          <p class="tc-card__hint" style="margin-top:10px">
            <?= $named === 0 ? 'Nobody has a name clip yet' : (int) $named . ' of ' . count($people) . ' people have a name clip' ?> —
            add them in <a class="tc-link" href="<?= e(url(['screen' => 'contacts'])) ?>">People</a>. Anyone without one just connects.
          </p>
        <?php endif; ?>
      </section>

      <?php if ($canEdit && !$d['adult']): ?>
        <!-- Adult mode: switched on only through the confirmation below -->
        <section class="tc-card tc-adult-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title"><i class="fa-solid fa-unlock" aria-hidden="true"></i> Adult mode</h2>
            <p class="tc-card__hint">
              For a grown-up's own phone on the line: removes every restriction, in
              both directions. Not for a child's phone.
            </p>
          </div>
          <button class="tc-btn tc-btn--outline-danger" type="button" data-tc-dialog-open="adult-dialog">
            Put <?= e($d['name']) ?> in adult mode…
          </button>
        </section>

        <dialog class="tc-dialog" id="adult-dialog" aria-labelledby="adult-dialog-title">
          <form method="post" action="/" class="tc-dialog__body">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_adult">
            <input type="hidden" name="id" value="<?= e($d['id']) ?>">
            <input type="hidden" name="on" value="1">
            <input type="hidden" name="confirm" value="remove-all-restrictions">
            <div class="tc-dialog__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
            <h2 class="tc-dialog__title" id="adult-dialog-title">Remove all restrictions from <?= e($d['name']) ?>?</h2>
            <p class="tc-dialog__lead">Are you sure? In adult mode, <b>every restriction is removed</b> from this phone:</p>
            <ul class="tc-dialog__list">
              <li>It can call <b>any number</b> — not just the people on the list.</li>
              <li><b>Anyone</b> can ring it, including numbers nobody recognises.</li>
              <li>Bedtime, its hours and each person's hours are <b>ignored</b>.</li>
              <li>Call limits and dial plan rules <b>don't apply</b>.</li>
            </ul>
            <p class="tc-dialog__lead">Only do this for a grown-up's own phone. Its settings are kept and come back when you turn it off.</p>
            <div class="tc-dialog__actions">
              <button class="tc-btn tc-btn--ghost" type="button" data-tc-dialog-close>Cancel</button>
              <button class="tc-btn tc-btn--danger" type="submit">Yes, remove all restrictions</button>
            </div>
          </form>
        </dialog>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($tab === 'keys'): ?>
  <div class="tc-device__grid tc-device__grid--keys">
    <div class="tc-stack">
      <?php if ($d['desk'] && $canEdit): ?>
        <!-- Hotkeys -->
        <section id="device-hotkeys" data-tc-ajax-region class="tc-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title"><?= $d['faceplate'] !== null ? 'Hotkeys' : 'Speed-dial keys' ?></h2>
            <?php if ($d['faceplate'] !== null): ?>
              <p class="tc-card__hint">Pick who each of its <?= $d['keys'] === 3 ? 'three' : 'six' ?> hotkeys dials — press the key and it rings that person.
                Saving sends them straight to the phone. Keys set on the phone itself are replaced by these.</p>
            <?php else: ?>
              <p class="tc-card__hint">Pick who each of its <?= (int) $d['keys'] ?> line key<?= $d['keys'] === 1 ? '' : 's' ?> after its own line dials —
                the phone shows their names beside the keys. Saving sends them straight to the phone; contacts added on the phone itself are replaced by these.</p>
            <?php endif; ?>
          </div>
          <form method="post" action="/" class="tc-stack tc-stack--tight" data-tc-ajax>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="hotkey_set">
            <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
            <?php
            $hotkeyRepo = new DeviceHotkeyRepository();
            $assigned = $hotkeyRepo->forDevice((int) $d['id']);
            $keyLabels = $hotkeyRepo->labels();
            // People by their number, groups by their speed dial; a group
            // without one can't be dialled yet, so it's shown but can't be picked.
            $callable = $hotkeyRepo->contactTargets();
            $people = array_filter($callable, static fn(array $c): bool => (int) $c['is_group'] === 0);
            $groups = array_filter($callable, static fn(array $c): bool => (int) $c['is_group'] === 1);
            $unreachable = array_filter((new ContactRepository())->groups(), static fn(array $g): bool => (string) ($g['speed_dial'] ?? '') === '');
            $services = PjsipConfig::testNumbers();
            // What the phone shows above a key: who it rings, or the number itself.
            $keyLabel = static fn(string $number): string => $number === '' ? '' : ($keyLabels[$number] ?? $number);
            ?>
            <?php
            // Key index => its place: a Grandstream's P-code, or for anything
            // else just the key's number.
            $phoneKeys = $d['faceplate'] !== null
                ? array_slice(GrandstreamProvisioning::HOTKEY_PCODES, 0, $d['keys'], true)
                : array_combine(range(1, max(1, $d['keys'])), range(1, max(1, $d['keys'])));
            ?>
            <?php if ($d['faceplate'] !== null): ?>
            <!-- The phone's face, in its own colour: keys 1–3 in a row, and 4–6 under them on a GHP62x -->
            <div class="tc-gsface tc-gsface--<?= e((string) $d['body']) ?>" data-tc-gsface aria-hidden="true">
              <?php foreach ($phoneKeys as $index => $code): ?>
                <div class="tc-gsface__key<?= ($assigned[$index] ?? '') === '' ? ' is-empty' : '' ?>" data-tc-gsface-key="<?= (int) $index ?>">
                  <span class="tc-gsface__label"><?= e($keyLabel($assigned[$index] ?? '')) ?: (int) $index ?></span>
                  <span class="tc-gsface__button"></span>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="tc-gskeys">
              <?php foreach ($phoneKeys as $index => $code): ?>
                <label class="tc-label">Key <?= (int) $index ?>
                  <select class="tc-input" name="hotkey[<?= (int) $index ?>]" data-tc-gskey="<?= (int) $index ?>">
                    <option value="" data-label="">— none —</option>
                    <?php foreach ($people as $target => $c): $target = (string) $target; ?>
                      <option value="<?= e($target) ?>" data-label="<?= e($keyLabel($target)) ?>" <?= ($assigned[$index] ?? '') === $target ? 'selected' : '' ?>>
                        <?= e($c['name'] !== '' ? $c['name'] : $target) ?>
                      </option>
                    <?php endforeach; ?>
                    <?php if ($groups !== [] || $unreachable !== []): ?>
                      <optgroup label="Groups">
                        <?php foreach ($groups as $target => $c): $target = (string) $target; ?>
                          <option value="<?= e($target) ?>" data-label="<?= e($keyLabel($target)) ?>" <?= ($assigned[$index] ?? '') === $target ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                          </option>
                        <?php endforeach; ?>
                        <?php foreach ($unreachable as $g): ?>
                          <option value="" disabled><?= e($g['name']) ?> — give it a speed dial first</option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                    <optgroup label="twocans">
                      <?php foreach ($services as $number => $service): ?>
                        <option value="<?= e($number) ?>" data-label="<?= e($keyLabel((string) $number)) ?>" <?= ($assigned[$index] ?? '') === (string) $number ? 'selected' : '' ?>>
                          <?= e($service['label']) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  </select>
                </label>
              <?php endforeach; ?>
            </div>
            <button class="tc-btn tc-btn--teal tc-device__save" type="submit">Save hotkeys</button>
          </form>
        </section>
      <?php endif; ?>
    </div>

    <?php if ($d['faceplate'] !== null): ?>
    <div class="tc-stack">
      <?php view('partials/device_keys_side', ['d' => $d]); ?>
    </div>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'settings'): ?>
  <?php
  // Every setting its kind of phone has, by group; each saved on its own and
  // sent straight to the phone. See PhoneSettings.
  $chosenSettings = PhoneSettings::for($d);
  $settingGroups = [];
  // Ring volume and the hotline sit at the top: under Ringing on a desk
  // phone, under Calls on an adapter (whose phone rings as loud as it does).
  $hotlineGroup = PhoneSettings::can($d['type'], 'ringVolume')
      || in_array('Ringing', array_column(PhoneSettings::catalog($d['type']), 'group'), true) ? 'Ringing' : 'Calls';
  if (PhoneSettings::can($d['type'], 'ringVolume') || PhoneSettings::can($d['type'], 'hotline')) {
      $settingGroups[$hotlineGroup] = [];
  }
  foreach (PhoneSettings::catalog($d['type']) as $key => $setting) {
      $settingGroups[$setting['group']][$key] = $setting;
  }
  // The other phones on the same box — a base's handsets, an adapter's other
  // socket — which share the 'shared' settings.
  $baseMates = DeviceRepository::ports($d['type']) > 1 && $d['mac'] !== '' ? count((new DeviceRepository())->findByMac($d['mac'])) - 1 : 0;
  ?>
  <div class="tc-stack tc-phone-settings">
    <p class="tc-card__hint">
      Settings on the phone itself, sent to it as soon as they're changed. Each starts as what's best
      for a child's phone; change what suits this one.
      <?php if ($d['dect']): ?>
        Most belong to the base, so <?= $baseMates > 0 ? 'every handset on it — this one and ' . $baseMates . ' more — has' : 'any handset added to it later will have' ?>
        them the same.
      <?php endif; ?>
    </p>
    <?php $wallpaperSize = YealinkProvisioning::WALLPAPER[$d['type']] ?? null; ?>
    <?php if ($wallpaperSize !== null): ?>
      <?php /* Its own wallpaper: a photo for its screen, cropped to fill it, fetched
               from twocans. The family on a child's phone. */ ?>
      <section class="tc-card" id="device-wallpaper" data-tc-ajax-region>
        <h2 class="tc-card__title">Wallpaper</h2>
        <p class="tc-card__hint">
          A picture of your own on its screen — a family photo, a pet, a favourite drawing. It's cropped to fill the
          screen (<?= (int) $wallpaperSize[0] ?> × <?= (int) $wallpaperSize[1] ?>), and its key labels sit on top.
        </p>
        <div class="tc-wallpaper">
          <div class="tc-wallpaper__screen" style="aspect-ratio:<?= (int) $wallpaperSize[0] ?> / <?= (int) $wallpaperSize[1] ?>">
            <?php if ($d['wallpaper'] !== ''): ?>
              <img src="<?= e(url(['photo' => $d['wallpaper']])) ?>" alt="<?= e($d['name']) ?>'s wallpaper">
            <?php else: ?>
              <span class="tc-wallpaper__none">Its own built-in wallpaper</span>
            <?php endif; ?>
          </div>
          <div class="tc-stack tc-stack--tight">
            <form method="post" action="/" enctype="multipart/form-data" class="tc-row tc-row--wrap" style="gap:8px" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_wallpaper">
              <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
              <label class="tc-sr-only" for="wallpaper-file">A picture for its screen</label>
              <input class="tc-input" type="file" id="wallpaper-file" name="wallpaper" accept="image/jpeg,image/png,image/webp" required>
              <button class="tc-btn tc-btn--teal" type="submit">
                <i class="fa-solid fa-image" aria-hidden="true"></i> <?= $d['wallpaper'] !== '' ? 'Change it' : 'Use this picture' ?>
              </button>
            </form>
            <?php if ($d['wallpaper'] !== ''): ?>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_wallpaper_remove">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <button class="tc-btn tc-btn--ghost" type="submit">Back to its own wallpaper</button>
              </form>
            <?php endif; ?>
            <p class="tc-card__hint">A JPEG, PNG or WebP, under 12MB. Where it was taken is stripped out first.</p>
          </div>
        </div>
      </section>
    <?php endif; ?>
    <?php foreach ($settingGroups as $group => $settings): ?>
      <section class="tc-card">
        <h2 class="tc-card__title"><?= e($group) ?></h2>
        <?php if ($group === $hotlineGroup): ?>
        <?php /* How loud it rings (a desk phone: locked there, so it can't be turned
                 to nothing), and its hotline — picked up and nothing pressed, it
                 rings a grown-up. Both go straight to the phone. */ ?>
          <?php if (PhoneSettings::can($d['type'], 'ringVolume')): ?>
          <div class="tc-rule-row tc-mt-14" id="ring-volume" data-tc-ajax-region>
            <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-volume-high" aria-hidden="true"></i></div>
            <div class="tc-grow">
              <div class="tc-rule-row__title">How loud it rings</div>
              <div class="tc-rule-row__hint">1 is very quiet, 8 the loudest.</div>
            </div>
            <?php if ($canEdit): ?>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_ring_volume">
                <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <label class="tc-sr-only" for="ring-volume-<?= (int) $d['id'] ?>">How loud it rings</label>
                <select class="tc-input" name="volume" id="ring-volume-<?= (int) $d['id'] ?>" data-tc-autosave style="max-width:170px">
                  <?php foreach (range(1, 8) as $v): ?>
                    <option value="<?= $v ?>"<?= $d['ringVolume'] === $v ? ' selected' : '' ?>>
                      <?= $v ?><?= [1 => ' — very quiet', 3 => ' — quiet', 4 => ' — normal', 6 => ' — loud', 8 => ' — loudest'][$v] ?? '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (PhoneSettings::can($d['type'], 'hotline')): ?>
          <?php
          // Who it can ring: a person or group it may call (the same as its hotkeys).
          $hotlineTargets = [];
          foreach ((new DeviceHotkeyRepository())->contactTargets() as $target => $row) {
              if (ContactRepository::toView($row)['allowOut']) {
                  $hotlineTargets[(string) $target] = (string) $row['name'];
              }
          }
          ?>
          <div class="tc-rule-row tc-mt-14" id="hotline" data-tc-ajax-region>
            <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-phone-volume" aria-hidden="true"></i></div>
            <div class="tc-grow">
              <div class="tc-rule-row__title">Pick up to ring a grown-up</div>
              <div class="tc-rule-row__hint">
                Lift the handset and press nothing, and after a few seconds it rings them — for a child
                too little to dial. Pressing a key first dials as normal.
              </div>
              <?php if ($canEdit): ?>
                <form method="post" action="/" class="tc-row tc-row--wrap tc-mt-8" data-tc-ajax>
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="device_hotline">
                  <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                  <label class="tc-sr-only" for="hotline-<?= (int) $d['id'] ?>">Who it rings</label>
                  <select class="tc-input" name="number" id="hotline-<?= (int) $d['id'] ?>" data-tc-autosave style="max-width:200px">
                    <option value="">Nobody — it waits for a number</option>
                    <?php foreach ($hotlineTargets as $target => $name): ?>
                      <option value="<?= e($target) ?>"<?= $d['hotline'] === $target ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                    <?php if ($d['hotline'] !== '' && !isset($hotlineTargets[$d['hotline']])): ?>
                      <option value="<?= e($d['hotline']) ?>" selected><?= e($d['hotline']) ?> (not on the call list now)</option>
                    <?php endif; ?>
                  </select>
                  <label class="tc-label tc-label--sm tc-row">after
                    <select class="tc-input" name="delay" data-tc-autosave style="max-width:110px">
                      <?php foreach ([2, 3, 4, 6, 8, 10] as $s): ?>
                        <option value="<?= $s ?>"<?= $d['hotlineDelay'] === $s ? ' selected' : '' ?>><?= $s ?> seconds</option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($settings as $key => $setting): ?>
          <?php $value = $chosenSettings[$key]; $changed = $value !== $setting['default']; ?>
          <div class="tc-rule-row tc-mt-14" id="phone-setting-<?= e($key) ?>" data-tc-ajax-region>
            <div class="tc-grow">
              <div class="tc-rule-row__title"><?= e($setting['label']) ?><?= $changed ? ' <span class="tc-changed">changed</span>' : '' ?></div>
              <div class="tc-rule-row__hint"><?= e($setting['hint']) ?><?php if ($baseMates > 0): ?><?= $d['dect']
                  ? (!($setting['shared'] ?? false) ? ' <b>Just this handset.</b>' : '')
                  : (($setting['shared'] ?? false) ? ' <b>Both phones on this adapter.</b>' : '') ?><?php endif; ?></div>
            </div>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="device_phone_setting">
              <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
              <input type="hidden" name="key" value="<?= e($key) ?>">
              <?php if (isset($setting['choices'])): ?>
                <label class="tc-sr-only" for="ps-<?= e($key) ?>"><?= e($setting['label']) ?></label>
                <select class="tc-input" name="value" id="ps-<?= e($key) ?>" data-tc-autosave style="max-width:190px">
                  <?php foreach ($setting['choices'] as $choice => $label): ?>
                    <option value="<?= e((string) $choice) ?>"<?= (string) $choice === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="hidden" name="value" value="<?= $value ? '0' : '1' ?>">
                <button type="submit" class="tc-switch <?= $value ? 'is-on' : '' ?>" role="switch"
                        aria-checked="<?= $value ? 'true' : 'false' ?>" aria-label="<?= e($setting['label']) ?>"></button>
              <?php endif; ?>
            </form>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="tc-device__grid">
    <div class="tc-stack">
      <?php if ($d['available']): ?>
        <?php if ($d['type'] === 'linphone'): ?>
        <section class="tc-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Set up the app</h2>
            <p class="tc-card__hint">Scanning is the quick way — nothing to type, nothing to mistype.</p>
          </div>
          <?php view('partials/provision_qr', ['d' => $d]); ?>

          <details class="tc-manual-setup">
            <summary>Or enter the details by hand</summary>
            <?php view('partials/sip_credentials', ['d' => $d]); ?>
          </details>
        </section>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($d['family'] !== 'app' && $canEdit): ?>
        <!-- Provisioning: a Grandstream, or a Yealink base -->
        <?php
        $siblings = DeviceRepository::ports($d['type']) > 1 && $d['mac'] !== ''
            ? array_values(array_filter((new DeviceRepository())->findByMac($d['mac']), static fn(array $r): bool => (int) $r['id'] !== $d['id']))
            : [];
        ?>
        <section class="tc-card" id="device-provisioning" data-tc-ajax-region>
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Provisioning</h2>
            <?php if ($d['untested']): ?>
              <div class="tc-note tc-mt-8">
                <b>Untested.</b> twocans hasn't been tried with a real <?= e($d['model']) ?> yet: its settings follow its maker's
                documentation, and some may not take. If something doesn't, please
                <a class="tc-link" href="https://github.com/tombruton87/TwoCans/issues" target="_blank" rel="noopener">tell us on GitHub</a>
                — its exported configuration helps most.
              </div>
            <?php endif; ?>
            <p class="tc-card__hint tc-card__hint--loose">
              <?php if ($d['dect']): ?>
                Handset <b><?= (int) $d['port'] ?></b> on a Yealink <?= e($d['model']) ?> base. The base fetches its handsets'
                settings from twocans when it starts, or when sent them from here.
              <?php elseif ($d['ata']): ?>
                <?= DeviceRepository::ports($d['type']) === 2 ? 'The corded phone in socket <b>' . (int) $d['port'] . '</b> of this ' . e($d['model']) . '.' : 'The corded phone plugged into this ' . e($d['model']) . '.' ?>
                The adapter fetches its settings from twocans when it starts.
              <?php else: ?>
                The phone fetches its settings from twocans when it starts, or when sent them from here.
              <?php endif; ?>
            </p>
          </div>
          <div class="tc-device__fetched">
            <?php if ($d['settingsPending']): ?>
              <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
              <b>Changes waiting</b> — it's offline, and gets them the moment it's back.
            <?php elseif ($d['settingsFetched'] !== null): ?>
              <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
              Settings fetched <b><?= e($d['settingsFetched']) ?></b>
            <?php else: ?>
              <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
              It hasn't fetched its settings yet — follow the steps below, then restart it.
            <?php endif; ?>
          </div>
          <?php if ($d['online']): ?>
            <div class="tc-row tc-row--wrap tc-device__remote">
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_resync">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <button class="tc-btn tc-btn--teal" type="submit"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Send its settings now</button>
              </form>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_reboot">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <button class="tc-btn tc-btn--ghost" type="submit"
                        data-tc-confirm="Restart <?= e($d['name']) ?>? A call on it now would be cut off."><i class="fa-solid fa-power-off" aria-hidden="true"></i> Restart it</button>
              </form>
            </div>
          <?php endif; ?>
          <details class="tc-manual-setup">
            <summary>How to set it up</summary>
            <?php view('partials/grandstream_setup', ['d' => $d]); ?>
          </details>
          <form method="post" action="/" class="tc-stack tc-stack--tight tc-mt-14" data-tc-ajax>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_mac">
            <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
            <label class="tc-label"><?= $d['dect'] ? "The base's MAC address" : 'MAC address' ?>
              <input class="tc-input tc-mac-input" type="text" name="mac" value="<?= e($d['mac'] !== '' ? implode(':', str_split(strtoupper($d['mac']), 2)) : '') ?>"
                     placeholder="00:0B:82:C1:23:45" autocomplete="off" data-tc-mac>
            </label>
            <button class="tc-btn tc-btn--teal tc-device__save" type="submit">Save MAC</button>
          </form>

          <?php if ($d['ata'] && DeviceRepository::ports($d['type']) === 2 && $d['mac'] !== ''): ?>
            <div class="tc-card__intro tc-card__intro--sub">
              <h3 class="tc-card__subtitle">The other socket</h3>
            </div>
            <?php if ($siblings !== []): ?>
              <?php $sib = DeviceRepository::toView($siblings[0]); ?>
              <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'phones', 'device' => $sib['id']])) ?>">
                <i class="fa-solid fa-plug" aria-hidden="true"></i> Socket <?= (int) $sib['port'] ?>: <?= e($sib['name']) ?>
              </a>
            <?php else: ?>
              <form method="post" action="/" class="tc-row" style="gap:8px">
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_add_socket">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <input class="tc-input tc-grow" type="text" name="name" placeholder="Kitchen Phone" aria-label="Name for the phone in the other socket" required>
                <button class="tc-btn tc-btn--teal" type="submit">Add socket <?= $d['port'] === 1 ? 2 : 1 ?></button>
              </form>
              <p class="tc-card__hint">Plug a second corded phone in and give it a name — reboot the adapter afterwards.</p>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($d['dect'] && $d['mac'] !== ''): ?>
            <?php $free = array_values(array_diff(range(1, DeviceRepository::ports($d['type'])), [$d['port']], array_map(static fn(array $r): int => (int) $r['port'], $siblings))); ?>
            <div class="tc-card__intro tc-card__intro--sub">
              <h3 class="tc-card__subtitle">The base's other handsets</h3>
            </div>
            <?php if ($siblings !== []): ?>
              <div class="tc-row tc-row--wrap" style="gap:8px">
                <?php foreach ($siblings as $row): ?>
                  <?php $sib = DeviceRepository::toView($row); ?>
                  <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'phones', 'device' => $sib['id']])) ?>">
                    <i class="fa-solid fa-mobile-retro" aria-hidden="true"></i> Handset <?= (int) $sib['port'] ?>: <?= e($sib['name']) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($free !== []): ?>
              <form method="post" action="/" class="tc-row tc-row--wrap tc-mt-8" style="gap:8px">
                <?= form_fields() ?>
                <input type="hidden" name="action" value="device_add_socket">
                <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
                <input class="tc-input tc-grow" type="text" name="name" placeholder="Kitchen Phone" aria-label="Name for the other handset" required>
                <label class="tc-sr-only" for="add-handset-port">Handset number</label>
                <select class="tc-input" name="port" id="add-handset-port" style="max-width:150px">
                  <?php foreach ($free as $n): ?><option value="<?= $n ?>">Handset <?= $n ?></option><?php endforeach; ?>
                </select>
                <button class="tc-btn tc-btn--teal" type="submit">Add handset</button>
              </form>
              <p class="tc-card__hint">Register the handset to the base first (on the handset: <b>OK → Settings → Registration</b>), then add it here with the number it shows — and restart the base.</p>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>

    <div class="tc-stack">
      <?php if ($d['available'] && $d['type'] !== 'linphone'): ?>
        <section class="tc-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Its account</h2>
            <p class="tc-card__hint">twocans hands these over by itself — only needed if you set the <?= $d['ata'] ? 'adapter' : 'phone' ?> up by hand.</p>
          </div>
          <details class="tc-manual-setup">
            <summary>Show the details</summary>
            <?php view('partials/sip_credentials', ['d' => $d]); ?>
          </details>
        </section>
      <?php endif; ?>

      <?php if ($canEdit): ?>
        <?php /* Everything needed to work out why it isn't working, masked, to send
                 to whoever's helping — see Diagnostics. */ ?>
        <section class="tc-card" id="device-diagnostics">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Diagnostics</h2>
            <p class="tc-card__hint">
              Not coming online, or a setting not taking? Download this and send it to whoever's helping —
              or attach it to a <a class="tc-link" href="https://github.com/tombruton87/TwoCans/issues" target="_blank" rel="noopener">GitHub issue</a>.
              It has what twocans knows about this <?= $d['ata'] ? 'adapter' : 'phone' ?>, how it's signed in, what it's asked
              twocans for lately, and the settings it's handed.
            </p>
          </div>
          <p class="tc-card__hint">
            <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
            Passwords, names, phone numbers, email, MAC and IP addresses are taken out first.
            <a class="tc-link" href="<?= e(url(['diagnostics' => $d['id'], 'view' => 1])) ?>" target="_blank" rel="noopener">See what's in it</a>.
          </p>
          <a class="tc-btn tc-btn--teal" href="<?= e(url(['diagnostics' => $d['id']])) ?>" download>
            <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> Download diagnostics
          </a>
        </section>

        <form method="post" action="/" class="tc-device__remove">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_remove">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <button class="tc-btn tc-btn--outline-danger" type="submit">Remove this phone</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($d['available']): ?>
    <?php /* Full width, below both columns: the line's numbers are a long list
             now (games, radio, Christmas…), so they sit side by side here
             rather than stretching the right-hand column. */ ?>
    <?php
    // What the people it rings see: see TrunkRepository::outgoingNumberFor().
    $line = (new TrunkRepository())->get();
    $callsOut = $line['connected'] && $line['numbers'] !== []
        ? ((new TrunkRepository())->outgoingNumberFor((int) $d['id']) ?? $line['outgoing']) : null;
    ?>
    <section class="tc-card tc-dial-card">
      <div class="tc-card__intro">
        <h2 class="tc-card__title">Numbers to dial</h2>
        <p class="tc-card__hint">
          From this phone, or any other phone on your line.
          <a class="tc-link" href="<?= e(url(['contactsheet' => 1, 'phone' => $d['id']])) ?>" target="_blank" rel="noopener">
            <i class="fa-solid fa-print" aria-hidden="true"></i> Print a contact sheet for <?= e($d['name']) ?></a>
          — who it can call, with photos, for beside the phone.
        </p>
      </div>

      <div class="tc-dial-grid">
        <div class="tc-dial tc-dial--self">
          <span class="tc-dial__num"><?= e($d['extension']) ?></span>
          <span class="tc-grow">
            <span class="tc-dial__label">This phone</span>
            <span class="tc-dial__sub">Dial it from another phone on the line to ring <?= e($d['name']) ?>.</span>
          </span>
        </div>
        <?php if ($callsOut !== null): ?>
          <div class="tc-dial">
            <span class="tc-dial__num"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></span>
            <span class="tc-grow">
              <span class="tc-dial__label">Calls out as <?= e($callsOut) ?></span>
              <span class="tc-dial__sub">
                What the people it rings see, and ring back.
                <?php if (count($line['numbers']) > 1): ?>
                  <a class="tc-link" href="<?= e(url(['screen' => 'trunk'])) ?>#calls-out">Change it</a>
                <?php endif; ?>
              </span>
            </span>
          </div>
        <?php endif; ?>
        <?php foreach (PjsipConfig::testNumbers() as $number => $test): ?>
          <div class="tc-dial">
            <span class="tc-dial__num"><?= e((string) $number) ?></span>
            <span class="tc-grow">
              <span class="tc-dial__label"><?= e($test['label']) ?></span>
              <span class="tc-dial__sub"><?= e($test['sub']) ?></span>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
  <?php endif; ?>
</div>
