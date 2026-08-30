<?php
/**
 * @var Store  $store
 * @var string $screen
 * @var int    $unheard
 */
$settings = $store->settings();
$user = Auth::user();
$canEditRules = Auth::can('rules');

$items = [
    ['screen' => 'dashboard', 'label' => 'Dashboard',  'icon' => 'dash'],
    ['screen' => 'phones',    'label' => 'Phones',     'icon' => 'phones'],
    ['screen' => 'contacts',  'label' => 'Contacts',   'icon' => 'contacts'],
    ['screen' => 'calllog',   'label' => 'Call log',   'icon' => 'log'],
    ['screen' => 'voicemail', 'label' => 'Voicemail',  'icon' => 'voice', 'badge' => $unheard],
    ['screen' => 'jokes',     'label' => 'Joke line',  'icon' => 'joke'],
];
?>
<aside class="tc-sidebar">
  <div class="tc-brand">
    <?php view('partials/logo', ['variant' => 'sidebar']); ?>
    <div class="tc-wordmark tc-wordmark--sm"><span class="tc-two">two</span><span class="tc-cans">cans</span></div>
  </div>

  <nav>
    <?php foreach ($items as $item): ?>
      <a class="tc-nav-btn <?= $screen === $item['screen'] ? 'is-active' : '' ?>"
         href="<?= e(url(['screen' => $item['screen']])) ?>"
         <?= $screen === $item['screen'] ? 'aria-current="page"' : '' ?>>
        <?php view('partials/nav_icon', ['icon' => $item['icon']]); ?>
        <?= e($item['label']) ?>
        <?php if (!empty($item['badge'])): ?>
          <span class="tc-nav-badge"><?= (int) $item['badge'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="tc-sidebar__foot">
    <div class="tc-quiet-card">
      <div class="tc-quiet-card__row">
        <div>
          <div class="tc-quiet-card__label">Quiet hours</div>
          <div class="tc-quiet-card__value"><?= e(Presenter::quietRange($settings)) ?></div>
        </div>
        <?php if ($canEditRules): ?>
          <form method="post" action="/">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="toggle_quiet">
            <button type="submit"
                    class="tc-switch <?= $settings['quietHours'] ? 'is-on' : '' ?>"
                    role="switch"
                    aria-checked="<?= $settings['quietHours'] ? 'true' : 'false' ?>"
                    aria-label="Quiet hours"></button>
          </form>
        <?php else: ?>
          <span class="tc-switch <?= $settings['quietHours'] ? 'is-on' : '' ?>" role="img"
                title="Only an Owner or Admin can change this"></span>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="logout">
      <button class="tc-signout" type="submit">
        <span><?= e(initial($user['name'])) ?></span>Sign out
      </button>
    </form>
  </div>
</aside>
