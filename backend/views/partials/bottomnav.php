<?php
/**
 * Replaces the sidebar below 880px (see the responsive block in twocans.css).
 *
 * @var string $screen
 * @var int    $unheard
 */
$tabs = [
    ['screen' => 'dashboard', 'label' => 'Home',      'glyph' => '▦'],
    ['screen' => 'phones',    'label' => 'Phones',    'glyph' => '▮'],
    ['screen' => 'contacts',  'label' => 'People',    'glyph' => '☺'],
    ['screen' => 'calllog',   'label' => 'Log',       'glyph' => '≣'],
    ['screen' => 'voicemail', 'label' => 'Voicemail', 'glyph' => '◌', 'dot' => $unheard > 0],
    ['screen' => 'jokes',     'label' => 'Jokes',     'glyph' => '☺'],
];
?>
<nav class="tc-bottomnav">
  <?php foreach ($tabs as $tab): ?>
    <a class="tc-tab <?= $screen === $tab['screen'] ? 'is-active' : '' ?>" href="<?= e(url(['screen' => $tab['screen']])) ?>">
      <span class="tc-tab__glyph"><?= $tab['glyph'] ?><?php if (!empty($tab['dot'])): ?><span class="tc-tab__dot"></span><?php endif; ?></span>
      <?= e($tab['label']) ?>
    </a>
  <?php endforeach; ?>
</nav>
