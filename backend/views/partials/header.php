<?php
/**
 * Sticky header: title/subtitle plus the actions that belong to this screen.
 *
 * @var Store  $store
 * @var string $screen
 * @var ?array $selectedDevice
 */
$user = Auth::user();
// The settings menu (Phone line, Dial plan, …) is for Owners and Admins. Both
// roles carry the 'rules' permission; a Viewer has none.
$canSettings = Auth::can('rules');
$canSystem = Auth::can('system');
$canNotifications = Auth::can('notifications');
?>
<header class="tc-header">
  <div style="min-width:0">
    <div class="tc-header__title"><?= e($headerTitle) ?></div>
    <div class="tc-header__sub"><?= e($headerSub) ?></div>
  </div>

  <div class="tc-header__actions">
    <?php if ($screen === 'phones' && $selectedDevice === null && Auth::can('devices')): ?>
      <a class="tc-btn tc-btn--coral" href="<?= e(url(['screen' => 'phones', 'wizard' => 1])) ?>">+ Add a phone</a>
    <?php endif; ?>

    <?php if ($screen === 'contacts' && Auth::can('contacts')): ?>
      <form method="post" action="/" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_add">
        <button class="tc-btn tc-btn--teal" type="submit">+ Add a person</button>
      </form>
    <?php endif; ?>

    <?php if ($screen === 'calllog'): ?>
      <?php /* Export matches whatever the log is filtered to right now. */ ?>
      <a class="tc-btn tc-btn--ghost" href="<?= e(url([
          'download' => 'calllog',
          'q' => $_GET['q'] ?? '',
          'contact' => ((int) ($_GET['contact'] ?? 0)) ?: '',
          'status' => $_GET['status'] ?? '',
      ])) ?>">↓ Export CSV</a>
    <?php endif; ?>

    <?php if ($canSettings): ?>
      <div class="tc-menu" data-tc-menu>
        <button type="button"
                class="tc-account <?= in_array($screen, ['guardians', 'trunk', 'dialplan', 'system', 'notifications'], true) ? 'is-active' : '' ?>"
                data-tc-menu-toggle aria-haspopup="menu" aria-expanded="false"
                title="Account and settings">
          <?= e(initial($user['name'])) ?>
          <span class="tc-menu__caret" aria-hidden="true"></span>
        </button>

        <div class="tc-menu__panel" data-tc-menu-panel role="menu" hidden>
          <a class="tc-menu__item <?= $screen === 'guardians' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'guardians'])) ?>">Family &amp; guardians</a>
          <div class="tc-menu__divider" role="separator"></div>
          <a class="tc-menu__item <?= $screen === 'trunk' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'trunk'])) ?>">Phone line</a>
          <a class="tc-menu__item <?= $screen === 'dialplan' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'dialplan'])) ?>">Dial plan</a>
          <?php if ($canSystem): ?>
            <div class="tc-menu__divider" role="separator"></div>
            <a class="tc-menu__item <?= $screen === 'system' ? 'is-active' : '' ?>" role="menuitem"
               href="<?= e(url(['screen' => 'system'])) ?>">System</a>
          <?php endif; ?>
          <?php if ($canNotifications): ?>
            <a class="tc-menu__item <?= $screen === 'notifications' ? 'is-active' : '' ?>" role="menuitem"
               href="<?= e(url(['screen' => 'notifications'])) ?>">Notifications</a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <a class="tc-account <?= $screen === 'guardians' ? 'is-active' : '' ?>"
         href="<?= e(url(['screen' => 'guardians'])) ?>"
         title="Family &amp; guardians"><?= e(initial($user['name'])) ?></a>
    <?php endif; ?>
  </div>
</header>
