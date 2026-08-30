<?php
/**
 * A contact or phone's picture, falling back to the coloured initial the
 * design uses when there isn't one.
 *
 * @var string  $photo   stored filename, or ''
 * @var string  $initial
 * @var string  $color
 * @var string  $size    avatar size modifier, e.g. '52'
 * @var string  $extra   extra markup inside the avatar (SOS badge etc.)
 * @var string  $alt
 */
$size = $size ?? '';
$classes = 'tc-avatar' . ($size !== '' ? ' tc-avatar--' . $size : '');
$hasPhoto = ($photo ?? '') !== '';
?>
<div class="<?= e($classes) ?><?= $hasPhoto ? ' tc-avatar--photo' : '' ?>"
     style="background:<?= e($color) ?>">
  <?php if ($hasPhoto): ?>
    <img src="<?= e(url(['photo' => $photo])) ?>" alt="<?= e($alt ?? '') ?>" loading="lazy">
  <?php else: ?>
    <?= e($initial) ?>
  <?php endif; ?>
  <?= $extra ?? '' ?>
</div>
