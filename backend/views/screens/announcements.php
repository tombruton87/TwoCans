<?php
/**
 * Announcements: buttons that page the phones with a recorded message. Each
 * one is set up here — its name, who it goes to, whether the phones pick up by
 * themselves, the recording — and pressed from the dashboard, or from its
 * trigger URL by Home Assistant, IFTTT or Uptime Kuma.
 *
 * @var Store $store
 */
$canEdit = Auth::can('rules');
$announcements = (new AnnouncementRepository())->all();
$phones = array_values(array_filter(
    array_map([DeviceRepository::class, 'toView'], (new DeviceRepository())->all()),
    static fn(array $d): bool => $d['sipUsername'] !== '' && $d['available']
));
$editing = (int) ($_GET['edit'] ?? 0);

// The address this page was reached on; the trigger URLs hang off the same one.
$https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>
<div class="tc-stack tc-announce">

  <p class="tc-card__hint tc-greetings__intro">
    Record a message once — “dinner's ready”, “ten minutes till bedtime” — and
    it becomes a button on the home page that plays it on the phones. Each one
    also has a trigger URL, so Home Assistant, IFTTT or Uptime Kuma can press it.
  </p>

  <?php if ($announcements === []): ?>
    <div class="tc-card tc-empty">No announcements yet.</div>
  <?php endif; ?>

  <?php foreach ($announcements as $a): ?>
    <?php
    $open = $editing === $a['id'] || $a['label'] === '' || $a['audio'] === '';
    $hook = $base . '/hook/announce/' . $a['token'];
    $toSome = $a['devices'] !== null;
    ?>
    <details class="tc-card tc-announce__card" id="announce-<?= (int) $a['id'] ?>"<?= $open ? ' open' : '' ?>>
      <summary class="tc-announce__head">
        <span class="tc-announce__emoji"><?= icon_html($a['emoji']) ?></span>
        <span class="tc-grow">
          <span class="tc-card__title"><?= e($a['label'] !== '' ? $a['label'] : 'New announcement') ?></span>
          <span class="tc-card__hint tc-announce__sum">
            <?= $a['mode'] === 'auto' ? 'Phones pick up by themselves' : 'Phones ring' ?>
            · <?= $toSome ? count($a['devices']) . ' phone' . (count($a['devices']) === 1 ? '' : 's') : 'every phone' ?>
            <?= $a['audio'] !== '' ? '· ' . e(fmt_duration($a['seconds'])) : '· no recording yet' ?>
          </span>
        </span>
        <span class="tc-link tc-announce__toggle">Edit</span>
      </summary>

      <?php if ($canEdit): ?>
        <form method="post" action="/" class="tc-announce__form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="announce_save">
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">

          <div class="tc-row tc-row--wrap">
            <div class="tc-label tc-label--sm">Icon
              <?php view('partials/icon_picker', ['field' => 'emoji', 'value' => $a['emoji']]); ?>
            </div>
            <label class="tc-label tc-label--sm tc-grow">Button name
              <input class="tc-input" type="text" name="label" value="<?= e($a['label']) ?>"
                     placeholder="Dinner's ready" maxlength="60" required>
            </label>
          </div>

          <fieldset class="tc-announce__set">
            <legend class="tc-card__subtitle">How the phones get it</legend>
            <label class="tc-announce__choice">
              <input type="radio" name="mode" value="auto" <?= $a['mode'] === 'auto' ? 'checked' : '' ?>>
              <span><b>Pick up by themselves</b> — like an intercom, for phones that can.</span>
            </label>
            <?php
            // Say which phones actually will: an app like Linphone can't pick up
            // for one call without picking up for all of them, so it rings.
            $willRing = array_values(array_filter($phones, static fn(array $p): bool => !$p['autoAnswer']));
            ?>
            <?php if ($willRing !== []): ?>
              <p class="tc-micro tc-announce__note">
                <?= e(implode(', ', array_map(static fn(array $p): string => $p['name'], $willRing))) ?>
                <?= count($willRing) === 1 ? 'is a phone app, so it' : 'are phone apps, so they' ?>
                will ring instead, and the message plays when answered.
              </p>
            <?php endif; ?>
            <label class="tc-announce__choice">
              <input type="radio" name="mode" value="ring" <?= $a['mode'] === 'ring' ? 'checked' : '' ?>>
              <span><b>Ring</b> — the message plays when somebody answers.</span>
            </label>
            <label class="tc-announce__choice">
              <input type="checkbox" name="repeat" <?= $a['repeat'] ? 'checked' : '' ?>>
              <span>Play it twice, for a phone in the next room.</span>
            </label>
          </fieldset>

          <fieldset class="tc-announce__set">
            <legend class="tc-card__subtitle">Which phones</legend>
            <label class="tc-announce__choice">
              <input type="radio" name="to" value="all" <?= !$toSome ? 'checked' : '' ?>>
              <span>Every phone, including ones added later</span>
            </label>
            <label class="tc-announce__choice">
              <input type="radio" name="to" value="some" <?= $toSome ? 'checked' : '' ?>>
              <span>Just these:</span>
            </label>
            <div class="tc-announce__phones">
              <?php foreach ($phones as $p): ?>
                <label class="tc-announce__phone">
                  <input type="checkbox" name="devices[]" value="<?= (int) $p['id'] ?>"
                         <?= $toSome && in_array((int) $p['id'], $a['devices'], true) ? 'checked' : '' ?>>
                  <?= e($p['name']) ?>
                </label>
              <?php endforeach; ?>
              <?php if ($phones === []): ?>
                <span class="tc-micro">No phones are set up yet.</span>
              <?php endif; ?>
            </div>
          </fieldset>

          <button class="tc-btn tc-btn--teal" type="submit">Save</button>
        </form>

        <div class="tc-card__intro tc-card__intro--sub">
          <h3 class="tc-card__subtitle">The message</h3>
          <p class="tc-card__hint">Up to a minute — any audio file or voice memo will do.</p>
        </div>
        <?php if ($a['audio'] !== ''): ?>
          <div class="tc-vm-row tc-vm-row--bare">
            <audio data-audio preload="none" src="<?= e(url(['download' => 'announcement', 'id' => $a['id']])) ?>"></audio>
            <button class="tc-vm-play" type="button" data-play aria-label="Play this message">▶</button>
            <div class="tc-grow">
              <div class="tc-vm-row__name">What the phones will hear</div>
              <div class="tc-call-row__meta"><?= e(fmt_duration($a['seconds'])) ?></div>
            </div>
          </div>
        <?php endif; ?>
        <form method="post" action="/" enctype="multipart/form-data" class="tc-row tc-row--wrap">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="announce_audio">
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <label class="tc-btn tc-btn--ghost tc-audio-file">
            <span data-tc-filename><?= $a['audio'] === '' ? 'Record or choose a file' : 'Replace the recording' ?></span>
            <input type="file" name="message"
                   accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                   data-tc-audiofile data-tc-autosave required>
          </label>
          <noscript><button class="tc-btn tc-btn--teal" type="submit">Save message</button></noscript>
        </form>

        <div class="tc-card__intro tc-card__intro--sub">
          <h3 class="tc-card__subtitle">Trigger URL</h3>
          <p class="tc-card__hint">
            Fetching this address — GET or POST — presses the button. Point Home
            Assistant's <code>rest_command</code>, an IFTTT webhook or an Uptime Kuma
            webhook at it. Anyone with the address can press it, so keep it private.
          </p>
        </div>
        <div class="tc-cred">
          <div class="tc-cred__main">
            <div class="tc-cred__label">Press “<?= e($a['label'] !== '' ? $a['label'] : 'this announcement') ?>”</div>
            <div class="tc-cred__value tc-announce__url"><?= e($hook) ?></div>
          </div>
          <button class="tc-copy" type="button" data-tc-copy="<?= e($hook) ?>" aria-label="Copy the trigger URL">⧉</button>
        </div>
        <details class="tc-manual-setup">
          <summary>Examples</summary>
          <pre class="tc-announce__code"># Home Assistant — configuration.yaml
rest_command:
  twocans_<?= (int) $a['id'] ?>:
    url: "<?= e($hook) ?>"
    method: post

# Anything with a shell
curl -X POST <?= e($hook) ?></pre>
        </details>

        <div class="tc-row tc-row--wrap tc-announce__foot">
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="announce_token">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="tc-link" type="submit">Make a new trigger URL</button>
          </form>
          <span class="tc-grow"></span>
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="announce_delete">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="tc-link tc-link--danger" type="submit">Remove</button>
          </form>
        </div>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <form method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="announce_new">
      <button class="tc-btn tc-btn--coral" type="submit">+ New announcement</button>
    </form>
  <?php endif; ?>
</div>
