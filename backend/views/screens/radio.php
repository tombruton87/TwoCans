<?php
/**
 * The radio: its number, and the songs on it — see Radio.
 *
 * @var Store $store
 */
$settings = new SettingsRepository();
$number = $settings->radioNumber();
$songs = array_map([Radio::class, 'toView'], (new Radio())->all());
$on = count(array_filter($songs, static fn(array $s): bool => $s['enabled']));
$canEdit = Auth::can('rules');
$shuffle = $settings->radioShuffle();
$radio = new Radio();
$stations = $radio->stations();
$onStations = $radio->songStations();
$nameStore = new RadioStationStore();
?>
<div class="tc-split">
  <aside class="tc-split__side tc-stack tc-stack--tight">
    <div class="tc-info-banner">
      <span class="tc-info-banner__icon tc-info-banner__icon--sun"><i class="fa-solid fa-radio" aria-hidden="true"></i></span>
      <span>
        Dial <b><?= e($number) ?></b> (R-A-D-I-O) from any phone, and the songs play one after
        another until it's hung up — every one before any repeats. On the keypad: <b>#</b> the next
        song, <b>5</b> pause, <b>6</b> and <b>4</b> forward and back.
        <?php if ($on > 0): ?><b><?= $on ?></b> on the radio now.<?php endif; ?>
      </span>
    </div>

    <?php if ($canEdit): ?>
      <div class="tc-retention">
        <span class="tc-retention__label">Dial</span>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="radio_number">
          <input class="tc-input tc-code-input" type="text" name="number" value="<?= e($number) ?>" data-tc-autosave
                 inputmode="numeric" pattern="[0-9]*" maxlength="4" aria-label="The number to dial for the radio">
          <noscript><button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Save</button></noscript>
        </form>
        <span class="tc-retention__note">Whatever's easiest to remember. It can't be a number a child already dials.</span>
      </div>

      <section class="tc-card">
        <h2 class="tc-card__title">Add songs</h2>
        <p class="tc-card__hint">
          MP3s or any audio — up to 20 at a time, and 15 minutes each. A phone call is narrowband,
          so songs sound like songs down a phone: nursery rhymes and sing-alongs come through best.
        </p>
        <form method="post" action="/" enctype="multipart/form-data" class="tc-row tc-row--wrap" data-tc-ajax data-tc-ajax-go>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="radio_add">
          <label class="tc-btn tc-btn--teal tc-audio-file">
            <i class="fa-solid fa-music" aria-hidden="true"></i> Choose songs
            <input type="file" name="songs[]" multiple
                   accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.aac" data-tc-autosave required>
          </label>
          <noscript><button class="tc-btn tc-btn--teal" type="submit">Add</button></noscript>
        </form>
      </section>

      <?php /* Stations: playlists on the one number, from a menu — or straight
               away on a phone with one as its favourite (on its page). */ ?>
      <section class="tc-card" id="radio-stations" data-tc-ajax-region>
        <h2 class="tc-card__title">Stations</h2>
        <p class="tc-card__hint">
          Playlists on the radio: dialling it asks “Press 1 for… party songs”, or <b>0</b> for all
          the songs. Say each one's name so the menu can; a phone can have a favourite that plays
          straight away (on the phone's page). <b>★</b> while listening goes back to the menu.
        </p>
        <?php foreach ($stations as $i => $st): ?>
          <?php $count = count($radio->stationSongIds((int) $st['id'])); ?>
          <div class="tc-station" id="station-<?= (int) $st['id'] ?>">
            <span class="tc-station__key" title="Its key on the menu"><?= $i + 1 ?></span>
            <div class="tc-grow tc-stack tc-stack--tight">
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="radio_station_name">
                <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                <label class="tc-sr-only" for="station-name-<?= (int) $st['id'] ?>">Station <?= $i + 1 ?>'s name</label>
                <input class="tc-input tc-station__name" type="text" name="name" id="station-name-<?= (int) $st['id'] ?>"
                       maxlength="60" value="<?= e((string) $st['name']) ?>" data-tc-autosave>
              </form>
              <div class="tc-row tc-row--wrap tc-station__voice">
                <?php if ($nameStore->file((string) ($st['name_audio'] ?? '')) !== null): ?>
                  <span class="tc-vm-row tc-vm-row--bare">
                    <audio data-audio preload="none" src="<?= e(url(['download' => 'radio_station_name', 'id' => (string) $st['id'], 'v' => substr((string) $st['name_audio'], 0, 8)])) ?>"></audio>
                    <button class="tc-vm-play tc-vm-play--sm" type="button" data-play aria-label="Hear how the menu says <?= e((string) $st['name']) ?>">▶</button>
                  </span>
                <?php endif; ?>
                <form method="post" action="/" enctype="multipart/form-data" class="tc-inline-form" data-tc-ajax>
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="radio_station_audio">
                  <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                  <label class="tc-btn tc-btn--ghost tc-btn--sm tc-audio-file">
                    <span data-tc-filename><?= ($st['name_audio'] ?? null) === null ? 'Say its name' : 'Say it again' ?></span>
                    <input type="file" name="name_audio" accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.aac"
                           data-tc-audiofile data-tc-rec-max="8" data-tc-autosave required>
                  </label>
                </form>
                <span class="tc-card__hint"><?= $count ?> <?= $count === 1 ? 'song' : 'songs' ?><?= ($st['name_audio'] ?? null) === null ? ' · the menu says “station ' . ($i + 1) . '”' : '' ?></span>
              </div>
            </div>
            <form method="post" action="/" data-tc-ajax class="tc-station__shuffle">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="radio_station_shuffle">
              <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
              <button type="submit" class="tc-switch tc-switch--sm <?= $st['shuffle'] ? 'is-on' : '' ?>" role="switch"
                      aria-checked="<?= $st['shuffle'] ? 'true' : 'false' ?>" aria-label="Shuffle <?= e((string) $st['name']) ?>"
                      title="Shuffle"></button>
              <span aria-hidden="true"><i class="fa-solid fa-shuffle"></i></span>
            </form>
            <form method="post" action="/" class="tc-inline-form" data-tc-ajax data-tc-ajax-go>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="radio_station_delete">
              <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
              <button class="tc-btn--icon tc-btn--icon-danger" type="submit" aria-label="Take off <?= e((string) $st['name']) ?>"
                      data-tc-confirm="Take off “<?= e((string) $st['name']) ?>”? Its songs stay on the radio.">×</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (count($stations) < Radio::MAX_STATIONS): ?>
          <form method="post" action="/" class="tc-row tc-row--wrap tc-mt-8" data-tc-ajax data-tc-ajax-go>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="radio_station_add">
            <label class="tc-sr-only" for="station-new">A new station's name</label>
            <input class="tc-input" type="text" name="name" id="station-new" maxlength="60"
                   placeholder="<?= $stations === [] ? 'Party songs' : 'Another station' ?>" style="max-width:220px">
            <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">+ Add a station</button>
          </form>
        <?php endif; ?>
      </section>

      <?php /* Bedtime: calm songs only, or off; and the sleep timer. */ ?>
      <section class="tc-card" id="radio-bedtime" data-tc-ajax-region>
        <h2 class="tc-card__title"><i class="fa-solid fa-moon" aria-hidden="true"></i> At bedtime</h2>
        <p class="tc-card__hint">
          <?php if ($settings->quietHours()): ?>
            Bedtime is <?= e(Presenter::quietRange($store->settings())) ?>. Mark songs calm with
            <i class="fa-solid fa-moon" aria-hidden="true"></i><span class="tc-sr-only">the moon</span>.
            Not phones in adult mode.
          <?php else: ?>
            Bedtime is switched off, so these wait until it's on.
          <?php endif; ?>
        </p>
        <form method="post" action="/" class="tc-stack tc-stack--tight" data-tc-ajax>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="radio_bedtime">
          <label class="tc-label tc-label--sm">The radio
            <select class="tc-input" name="mode" data-tc-autosave>
              <?php foreach (Radio::BEDTIME as $mode => $label): ?>
                <option value="<?= e($mode) ?>"<?= $mode === $settings->radioBedtime() ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tc-label tc-label--sm">Sleep timer
            <select class="tc-input" name="minutes" data-tc-autosave>
              <?php foreach (Radio::SLEEP_MINUTES as $m): ?>
                <option value="<?= $m ?>"<?= $m === $settings->radioSleepMinutes() ? ' selected' : '' ?>>
                  <?= $m === 0 ? 'None — it plays until it\'s hung up' : 'Night night after ' . $m . ' minutes' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
      </section>
    <?php endif; ?>
  </aside>

  <div class="tc-stack tc-stack--tight">
    <?php if ($songs === []): ?>
      <div class="tc-card" style="text-align:center;padding:34px 22px">
        <div style="font:800 18px var(--tc-display);margin-bottom:6px">No songs yet</div>
        <p class="tc-card__hint" style="margin:0 auto;max-width:400px">
          Add some, and dialling <?= e($number) ?> plays them.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($songs !== [] && $canEdit): ?>
      <?php /* Shuffled, or in the order below — which can be changed by
               dragging a song's handle, or with its arrow keys. */ ?>
      <section class="tc-card tc-radio-shuffle" id="radio-shuffle" data-tc-ajax-region>
        <div class="tc-rule-row">
          <div class="tc-rule-row__icon tc-rule-row__icon--in"><i class="fa-solid fa-shuffle" aria-hidden="true"></i></div>
          <div class="tc-grow">
            <div class="tc-rule-row__title">Shuffle</div>
            <div class="tc-rule-row__hint">
              <?= $shuffle
                  ? 'The songs play in a mixed-up order. Turn it off to play them in the order below.'
                  : 'The songs play in the order below.' ?>
              Drag <i class="fa-solid fa-grip-vertical" aria-hidden="true"></i><span class="tc-sr-only">a song's handle</span> to change it.
            </div>
          </div>
          <form method="post" action="/" data-tc-ajax data-tc-ajax-go>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="radio_shuffle">
            <button type="submit" class="tc-switch <?= $shuffle ? 'is-on' : '' ?>" role="switch"
                    aria-checked="<?= $shuffle ? 'true' : 'false' ?>" aria-label="Shuffle"></button>
          </form>
        </div>
      </section>
      <form id="radio-order-form" method="post" action="/" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="radio_order">
      </form>
    <?php endif; ?>

    <div class="tc-stack tc-stack--tight tc-radio-list<?= $shuffle ? '' : ' is-ordered' ?>"
         <?= $canEdit ? 'data-tc-sortable="radio-order-form"' : '' ?>>
    <?php foreach ($songs as $s): ?>
      <article class="tc-card tc-card--flat tc-radio-song<?= $s['enabled'] ? '' : ' is-off' ?>" id="song-<?= (int) $s['id'] ?>"
               data-tc-ajax-region data-tc-sort-id="<?= (int) $s['id'] ?>">
        <div class="tc-vm-row">
          <?php if ($canEdit): ?>
            <button type="button" class="tc-sort-handle" data-tc-sort-handle
                    aria-label="Move <?= e($s['title']) ?> — drag it, or use the up and down arrow keys">
              <i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>
            </button>
          <?php endif; ?>
          <audio data-audio preload="none" src="<?= e(url(['download' => 'radio_song', 'id' => (string) $s['id']])) ?>"></audio>
          <button class="tc-vm-play" type="button" data-play aria-label="Play <?= e($s['title']) ?>">▶</button>
          <div class="tc-grow">
            <?php if ($canEdit): ?>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="radio_title">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <label class="tc-sr-only" for="song-title-<?= (int) $s['id'] ?>">Song title</label>
                <input class="tc-input tc-radio-song__title" type="text" name="title" id="song-title-<?= (int) $s['id'] ?>"
                       maxlength="120" value="<?= e($s['title']) ?>" data-tc-autosave>
              </form>
            <?php else: ?>
              <div class="tc-vm-row__name"><?= e($s['title']) ?></div>
            <?php endif; ?>
            <div class="tc-call-row__meta"><?= e($s['length']) ?><?= $s['enabled'] ? '' : ' · off the radio' ?></div>
            <?php if ($canEdit): ?>
              <?php /* Which stations it's on, and whether it's calm. */ ?>
              <div class="tc-song-tags">
                <form method="post" action="/" data-tc-ajax>
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="radio_calm">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button type="submit" class="tc-song-tag<?= $s['calm'] ? ' is-on' : '' ?>" aria-pressed="<?= $s['calm'] ? 'true' : 'false' ?>"
                          title="Calm — plays at bedtime"><i class="fa-solid fa-moon" aria-hidden="true"></i> Calm</button>
                </form>
                <?php foreach ($stations as $st): ?>
                  <?php $onIt = in_array((int) $st['id'], $onStations[$s['id']] ?? [], true); ?>
                  <form method="post" action="/" data-tc-ajax>
                    <?= form_fields() ?>
                    <input type="hidden" name="action" value="radio_song_station">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="station" value="<?= (int) $st['id'] ?>">
                    <button type="submit" class="tc-song-tag<?= $onIt ? ' is-on' : '' ?>" aria-pressed="<?= $onIt ? 'true' : 'false' ?>"
                            aria-label="<?= e($s['title']) ?> on <?= e((string) $st['name']) ?>"><?= e((string) $st['name']) ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($canEdit): ?>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="radio_toggle">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="tc-switch <?= $s['enabled'] ? 'is-on' : '' ?>" role="switch"
                      aria-checked="<?= $s['enabled'] ? 'true' : 'false' ?>" aria-label="<?= e($s['title']) ?> is on the radio"></button>
            </form>
            <form method="post" action="/" class="tc-inline-form">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="radio_delete">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="tc-btn--icon tc-btn--icon-danger" type="submit" aria-label="Delete <?= e($s['title']) ?>"
                      data-tc-confirm="Delete “<?= e($s['title']) ?>” from the radio?">×</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="tc-eqstrip" data-eq title="Click to skip through the song">
          <?php view('partials/eq', ['variant' => 'vm']); ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <span class="tc-sr-only" role="status" aria-live="polite" data-tc-sort-said></span>
  </div>
</div>
