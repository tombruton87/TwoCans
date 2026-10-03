<?php
/**
 * Keepsakes: voicemails kept for good, by year — see Keepsakes.
 *
 * @var Store $store
 */
$keepsakes = new Keepsakes();
$years = $keepsakes->byYear();
$canEdit = Auth::can('voicemail');
?>
<div class="tc-stack tc-stack--tight tc-keepsakes">
  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--sun"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
    Messages worth keeping — a goodnight from Grandad, the first message your
    child left by themselves. Press <i class="fa-regular fa-star" aria-hidden="true"></i><span class="tc-sr-only">the star</span>
    on a message in Voicemail to keep it. A kept copy stays here whatever
    happens to the message<?php $settings = new SettingsRepository(); ?><?= $settings->retentionDays() > 0
        ? ': deleted on the phone, or cleared after ' . e($settings->retentionLabel()) . '.'
        : ', even if it\'s deleted on the phone.' ?>
  </div>

  <?php if ($years === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">Nothing kept yet</div>
      <p class="tc-card__hint" style="margin:0 auto;max-width:400px">
        In <a href="<?= e(url(['screen' => 'voicemail'])) ?>">Voicemail</a>, press the star on a message
        to keep it for good.
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($years as $year => $items): ?>
    <section class="tc-keepsakes__year" aria-labelledby="ks-year-<?= (int) $year ?>">
      <div class="tc-keepsakes__head">
        <h2 class="tc-keepsakes__title" id="ks-year-<?= (int) $year ?>"><?= (int) $year ?></h2>
        <span class="tc-keepsakes__count"><?= count($items) ?> kept</span>
        <a class="tc-btn tc-btn--ghost tc-btn--sm" href="<?= e(url(['download' => 'keepsakes_zip', 'year' => (string) $year])) ?>">
          <i class="fa-solid fa-download" aria-hidden="true"></i> Download <?= (int) $year ?>
        </a>
      </div>

      <div class="tc-vm-grid">
        <?php foreach ($items as $k): ?>
          <article class="tc-card tc-card--flat tc-keepsake" id="keepsake-<?= (int) $k['id'] ?>" data-tc-ajax-region>
            <div class="tc-vm-row">
              <audio data-audio preload="none" src="<?= e(url(['download' => 'keepsake_audio', 'id' => (string) $k['id']])) ?>"></audio>
              <button class="tc-vm-play" type="button" data-play
                      aria-label="Play the keepsake from <?= e($k['from']) ?>">▶</button>

              <div class="tc-avatar tc-avatar--44 tc-keepsake__avatar"><?= e($k['initial']) ?></div>

              <div class="tc-grow">
                <div class="tc-vm-row__name"><?= e($k['from']) ?></div>
                <div class="tc-call-row__meta"><?= e($k['date']) ?> · <?= e($k['time']) ?> · <?= e($k['dur']) ?></div>
              </div>

              <a class="tc-btn--icon" style="display:flex;align-items:center;justify-content:center"
                 href="<?= e(url(['download' => 'keepsake_audio', 'id' => (string) $k['id']])) ?>" download
                 title="Save the audio" aria-label="Save the audio">↓</a>

              <?php if ($canEdit): ?>
                <form method="post" action="/" class="tc-inline-form">
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="keepsake_remove">
                  <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <button class="tc-btn--icon tc-btn--icon-danger" type="submit" title="Remove this keepsake"
                          aria-label="Remove the keepsake from <?= e($k['from']) ?>"
                          data-tc-confirm="Remove this keepsake? Its kept copy of the recording is deleted for good.">×</button>
                </form>
              <?php endif; ?>
            </div>

            <div class="tc-eqstrip" data-eq title="Click to skip through the message">
              <?php view('partials/eq', ['variant' => 'vm']); ?>
            </div>

            <?php if ($canEdit): ?>
              <form method="post" action="/" class="tc-keepsake__caption" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="keepsake_title">
                <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                <label class="tc-sr-only" for="ks-title-<?= (int) $k['id'] ?>">A name for this keepsake</label>
                <input class="tc-input" type="text" name="title" id="ks-title-<?= (int) $k['id'] ?>" maxlength="120"
                       value="<?= e($k['title']) ?>" placeholder="Give it a name — “First goodnight”" data-tc-autosave>
              </form>
            <?php elseif ($k['title'] !== ''): ?>
              <div class="tc-keepsake__name"><?= e($k['title']) ?></div>
            <?php endif; ?>

            <?php if ($k['transcript'] !== ''): ?>
              <div class="tc-transcript">“<?= e($k['transcript']) ?>”</div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
