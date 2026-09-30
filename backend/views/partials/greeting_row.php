<?php
/**
 * One line on the Greetings screen: play what's recorded, say where it plays,
 * and either take a new recording or link to where it's set.
 *
 * Choosing a file saves it straight away (data-tc-autosave), so a row needs no
 * Save button of its own.
 *
 * @var string      $title
 * @var string      $when     where it plays, one or two sentences
 * @var string      $says     what the standard prompt says, or ''
 * @var string      $tip      an example of what to record, or ''
 * @var string|null $src      playback URL of the household's recording, or null
 * @var int         $seconds  its length
 * @var string|null $href     set for a row edited elsewhere: links there instead
 * @var string      $action   upload action
 * @var string      $remove   remove action
 * @var array       $hidden   extra fields both forms carry (e.g. the slot)
 * @var string      $field    file input name the upload action reads
 * @var int         $max      longest clip accepted, in seconds
 * @var bool        $canEdit
 */
$hidden = $hidden ?? [];
$href = $href ?? null;
$says = $says ?? '';
$tip = $tip ?? '';
$hiddenFields = static function () use ($hidden): string {
    $html = '';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e((string) $value) . '">';
    }
    return $html;
};
?>
<div class="tc-greet">
  <div class="tc-greet__play">
    <?php if ($src !== null): ?>
      <audio data-audio preload="none" src="<?= e($src) ?>"></audio>
      <button class="tc-vm-play" type="button" data-play aria-label="Play: <?= e($title) ?>">▶</button>
    <?php else: ?>
      <span class="tc-greet__stock" title="Standard prompt — only playable on a call">♪</span>
    <?php endif; ?>
  </div>

  <div class="tc-greet__body">
    <div class="tc-greet__title">
      <?= e($title) ?>
      <?php if ($href === null): ?>
        <?php if ($src !== null): ?>
          <span class="tc-chip tc-chip--teal">Yours · <?= e(fmt_duration($seconds)) ?></span>
        <?php else: ?>
          <span class="tc-chip tc-chip--sun">Standard</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="tc-greet__when"><?= e($when) ?></div>
    <?php if ($src === null && $says !== ''): ?>
      <div class="tc-greet__says">Says: <?= e($says) ?></div>
    <?php endif; ?>
  </div>

  <div class="tc-greet__actions">
    <?php if ($href !== null): ?>
      <a class="tc-btn tc-btn--ghost tc-btn--sm" href="<?= e($href) ?>">Open →</a>
    <?php elseif ($canEdit): ?>
      <form method="post" action="/" enctype="multipart/form-data" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <?= $hiddenFields() ?>
        <label class="tc-btn tc-btn--ghost tc-btn--sm tc-audio-file"
               title="<?= e(($tip !== '' ? 'For example ' . $tip . ' ' : '') . 'Up to ' . (int) $max . ' seconds, any audio file or voice memo.') ?>">
          <span data-tc-filename><?= $src === null ? 'Upload' : 'Replace' ?></span>
          <input type="file" name="<?= e($field) ?>"
                 accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                 data-tc-audiofile data-tc-rec-max="<?= (int) $max ?>" data-tc-autosave required>
        </label>
        <noscript><button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Save</button></noscript>
      </form>
      <?php if ($src !== null): ?>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="<?= e($remove) ?>">
          <?= $hiddenFields() ?>
          <button class="tc-link tc-greet__reset" type="submit">use standard</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
