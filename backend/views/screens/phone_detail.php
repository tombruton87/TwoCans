<?php
/**
 * One device's settings. Text and time fields save on blur (JS submits the
 * surrounding form); without JS the same forms still submit normally.
 *
 * Laid out in two columns on a wide screen: what a parent changes on the
 * left (calls, hours, the refusal, a desk phone's keys), and what they look up
 * on the right (setting the app up, the numbers to dial). One column, in that
 * order, once the screen is narrow.
 *
 * @var Store $store
 * @var array $device
 */
$d = Presenter::device(DeviceRepository::toView($device));
$canEdit = Auth::can('devices');
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
    <form class="tc-grow" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="device_edit">
      <input type="hidden" name="id" value="<?= e($d['id']) ?>">
      <input class="tc-inline-input" type="text" name="name" value="<?= e($d['name']) ?>" data-tc-autosave aria-label="Phone name">
      <div class="tc-card__hint tc-device-head__meta">
        <?= e($d['model']) ?> · <span data-tc-status-text><?= e($d['statusText']) ?></span> · tap the name to rename
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
        Waiting for the <?= $d['ata'] ? 'adapter' : 'phone' ?> to sign in — follow <b>How to set it up</b> under
        Provisioning, then reboot it. This turns green on its own once it signs in.
      <?php else: ?>
        Waiting for Linphone to sign in with the details under <b>Set up the app</b>.
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

  <div class="tc-device__grid">

    <!-- Left: what a parent changes -->
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
      <section class="tc-card">
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

        <div class="tc-card__intro tc-card__intro--sub">
          <h3 class="tc-card__subtitle">When can it be used?</h3>
          <p class="tc-card__hint">
            Outside these times the phone goes quiet — an SOS contact still rings.
            <b><?= e(Schedule::describe($d['hours'])) ?></b>
          </p>
        </div>
        <?php if ($canEdit): ?>
          <form method="post" action="/">
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
        <form method="post" action="/" class="tc-row tc-row--wrap">
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
      </section>

      <?php endif; ?>

      <!-- The message a caller who isn't on the list hears -->
      <section class="tc-card">
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
              <form method="post" action="/" class="tc-inline-form">
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
          <form method="post" action="/" enctype="multipart/form-data" class="tc-row tc-row--wrap">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_refusal_message">
            <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
            <label class="tc-btn tc-btn--ghost tc-audio-file">
              <span data-tc-filename>Record or choose a file</span>
              <input type="file" name="message"
                     accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                     data-tc-audiofile required>
            </label>
            <button class="tc-btn tc-btn--teal" type="submit">
              <?= $d['refusalAudio'] === '' ? 'Save message' : 'Replace message' ?>
            </button>
          </form>
          <p class="tc-card__hint tc-card__after">
            Up to 30 seconds — any audio file or voice memo will do.
          </p>

          <form method="post" action="/" class="tc-device__wording">
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
      <section class="tc-card">
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
            <form method="post" action="/">
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

      <?php if ($d['type'] !== 'linphone' && $canEdit): ?>
        <!-- Grandstream provisioning -->
        <?php
        $siblings = $d['type'] === 'ht802' && $d['mac'] !== ''
            ? array_values(array_filter((new DeviceRepository())->findByMac($d['mac']), static fn(array $r): bool => (int) $r['id'] !== $d['id']))
            : [];
        ?>
        <section class="tc-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Provisioning</h2>
            <p class="tc-card__hint tc-card__hint--loose">
              <?php if ($d['ata']): ?>
                <?= $d['type'] === 'ht802' ? 'The corded phone in socket <b>' . (int) $d['port'] . '</b> of this HT802.' : 'The corded phone plugged into this HT801.' ?>
                The adapter fetches its settings from twocans when it starts.
              <?php else: ?>
                The phone fetches its settings from twocans on boot and on reprovision.
              <?php endif; ?>
            </p>
          </div>
          <details class="tc-manual-setup">
            <summary>How to set it up</summary>
            <?php view('partials/grandstream_setup', ['d' => $d]); ?>
          </details>
          <form method="post" action="/" class="tc-stack tc-stack--tight tc-mt-14">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_mac">
            <input type="hidden" name="id" value="<?= e((string) $d['id']) ?>">
            <label class="tc-label">MAC address
              <input class="tc-input" type="text" name="mac" value="<?= e($d['mac']) ?>" placeholder="00:0B:82:C1:23:45" autocomplete="off">
            </label>
            <button class="tc-btn tc-btn--teal tc-device__save" type="submit">Save MAC</button>
          </form>

          <?php if ($d['type'] === 'ht802' && $d['mac'] !== ''): ?>
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
        </section>
      <?php endif; ?>

      <?php if ($d['type'] === 'ghp621' && $canEdit): ?>
        <!-- Hotkeys -->
        <section class="tc-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Hotkeys</h2>
            <p class="tc-card__hint">Pick who each key dials — press the key and it rings that person.</p>
          </div>
          <form method="post" action="/" class="tc-stack tc-stack--tight">
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
            <button class="tc-btn tc-btn--teal tc-device__save" type="submit">Save hotkeys</button>
          </form>
        </section>
      <?php endif; ?>

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

    <!-- Right: what a parent looks up -->
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

        <section class="tc-card">
          <div class="tc-card__intro">
            <h2 class="tc-card__title">Numbers to dial</h2>
            <p class="tc-card__hint">From this phone, or any other phone on your line.</p>
          </div>

          <div class="tc-dial tc-dial--self">
            <span class="tc-dial__num"><?= e($d['extension']) ?></span>
            <span class="tc-grow">
              <span class="tc-dial__label">This phone</span>
              <span class="tc-dial__sub">
                Dial it from another phone on the line to ring <?= e($d['name']) ?>.
                Calling it from this phone just rings itself.
              </span>
            </span>
          </div>

          <?php foreach (PjsipConfig::testNumbers() as $number => $test): ?>
            <div class="tc-dial">
              <span class="tc-dial__num"><?= e((string) $number) ?></span>
              <span class="tc-grow">
                <span class="tc-dial__label"><?= e($test['label']) ?></span>
                <span class="tc-dial__sub"><?= e($test['sub']) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <?php if ($canEdit): ?>
        <form method="post" action="/" class="tc-device__remove">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="device_remove">
          <input type="hidden" name="id" value="<?= e($d['id']) ?>">
          <button class="tc-btn tc-btn--outline-danger" type="submit">Remove this phone</button>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>
