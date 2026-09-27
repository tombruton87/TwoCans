<?php
/**
 * Replaces the sidebar below 880px (see the responsive block in twocans.css).
 *
 * @var string $screen
 * @var int    $unheard
 */
$tabs = [
    ['screen' => 'dashboard', 'label' => 'Home',      'icon' => 'house'],
    ['screen' => 'phones',    'label' => 'Phones',    'icon' => 'mobile-screen-button'],
    ['screen' => 'contacts',  'label' => 'People',    'icon' => 'user-group'],
    ['screen' => 'calllog',   'label' => 'Log',       'icon' => 'clock-rotate-left'],
    ['screen' => 'voicemail', 'label' => 'Voicemail', 'icon' => 'voicemail', 'dot' => $unheard > 0],
    ['screen' => 'jokes',     'label' => 'Jokes',     'icon' => 'face-laugh-beam'],
];
?>
<?php /* Font Awesome solid icons, one per tab, each in the same fixed box so
         the row lines up whatever the phone's own fonts do. */ ?>
<nav class="tc-bottomnav" aria-label="Main">
  <?php foreach ($tabs as $tab): ?>
    <?php $active = $screen === $tab['screen']; ?>
    <a class="tc-tab <?= $active ? 'is-active' : '' ?>" href="<?= e(url(['screen' => $tab['screen']])) ?>"
       <?= $active ? 'aria-current="page"' : '' ?>>
      <span class="tc-tab__glyph">
        <i class="fa-solid fa-<?= e($tab['icon']) ?>" aria-hidden="true"></i>
        <?php if (!empty($tab['dot'])): ?><span class="tc-tab__dot" title="New messages"></span><?php endif; ?>
      </span>
      <span class="tc-tab__label"><?= e($tab['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
