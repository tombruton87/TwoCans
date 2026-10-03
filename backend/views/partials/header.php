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

    <?php if ($screen === 'contacts'): ?>
      <a class="tc-btn tc-btn--ghost" href="<?= e(url(['contactsheet' => 1])) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-print" aria-hidden="true"></i> Contact sheet
      </a>
    <?php endif; ?>
    <?php if ($screen === 'contacts' && Auth::can('contacts')): ?>
      <form method="post" action="/" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_add">
        <button class="tc-btn tc-btn--teal" type="submit">+ Add a person</button>
      </form>
    <?php endif; ?>

    <?php if ($screen === 'dashboard'): ?>
      <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'week'])) ?>">
        <i class="fa-solid fa-calendar-week" aria-hidden="true"></i> This week
      </a>
    <?php endif; ?>

    <?php if ($screen === 'voicemail'): ?>
      <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'keepsakes'])) ?>">
        <i class="fa-solid fa-star" aria-hidden="true"></i> Keepsakes
      </a>
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

    <?php /* The account menu, for everyone: who you are, the settings your role
             can reach, and signing out — which on a phone has nowhere else to
             live, since the sidebar that carries it is hidden there. */ ?>
    <div class="tc-menu" data-tc-menu>
      <button type="button"
              class="tc-account <?= in_array($screen, ['start', 'guardians', 'trunk', 'dialplan', 'greetings', 'announcements', 'christmas', 'radio', 'helpers', 'homeassistant', 'system', 'notifications'], true) ? 'is-active' : '' ?>"
              data-tc-menu-toggle aria-haspopup="menu" aria-expanded="false"
              title="Account and settings">
        <?= e(initial($user['name'])) ?>
        <span class="tc-menu__caret" aria-hidden="true"></span>
      </button>

      <div class="tc-menu__panel" data-tc-menu-panel role="menu" hidden>
        <div class="tc-menu__who">
          <div class="tc-menu__name"><?= e($user['name']) ?></div>
          <div class="tc-menu__role"><?= e($user['role']) ?> · <?= e($user['email']) ?></div>
        </div>
        <div class="tc-menu__divider" role="separator"></div>

        <?php $onboardingMenu = new Onboarding(); ?>
        <?php if (Auth::can('devices') && ($onboardingMenu->visible() || $screen === 'start')): ?>
          <?php $gettingStarted = $onboardingMenu->progress(); ?>
          <a class="tc-menu__item <?= $screen === 'start' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'start'])) ?>">
            <i class="fa-solid fa-flag-checkered" aria-hidden="true"></i> Getting started
            <?php if ($gettingStarted['done'] < $gettingStarted['total']): ?>
              <span class="tc-menu__badge"><?= (int) $gettingStarted['done'] ?>/<?= (int) $gettingStarted['total'] ?></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
        <a class="tc-menu__item <?= $screen === 'guardians' ? 'is-active' : '' ?>" role="menuitem"
           href="<?= e(url(['screen' => 'guardians'])) ?>">
          <i class="fa-solid fa-user-group" aria-hidden="true"></i> Family &amp; guardians
        </a>
        <?php if ($canSettings): ?>
          <div class="tc-menu__divider" role="separator"></div>
          <a class="tc-menu__item <?= $screen === 'trunk' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'trunk'])) ?>">
            <i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i> Phone line
          </a>
          <a class="tc-menu__item <?= $screen === 'dialplan' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'dialplan'])) ?>">
            <i class="fa-solid fa-hashtag" aria-hidden="true"></i> Dial plan
          </a>
          <a class="tc-menu__item <?= $screen === 'greetings' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'greetings'])) ?>">
            <i class="fa-solid fa-comment-dots" aria-hidden="true"></i> Greetings
          </a>
          <a class="tc-menu__item <?= $screen === 'announcements' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'announcements'])) ?>">
            <i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Announcements
          </a>
          <a class="tc-menu__item <?= $screen === 'helpers' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'helpers'])) ?>">
            <i class="fa-solid fa-clock" aria-hidden="true"></i> Handy lines
          </a>
          <a class="tc-menu__item <?= $screen === 'radio' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'radio'])) ?>">
            <i class="fa-solid fa-radio" aria-hidden="true"></i> The radio
          </a>
          <a class="tc-menu__item <?= $screen === 'christmas' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'christmas'])) ?>">
            <i class="fa-solid fa-tree" aria-hidden="true"></i> Sleeps till Christmas
          </a>
        <?php endif; ?>
        <?php if ($canSystem || $canNotifications): ?>
          <div class="tc-menu__divider" role="separator"></div>
        <?php endif; ?>
        <?php if ($canSystem): ?>
          <a class="tc-menu__item <?= $screen === 'system' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'system'])) ?>">
            <i class="fa-solid fa-server" aria-hidden="true"></i> System
            <?php if ((new UpdateCheck())->isNewer()): ?>
              <span class="tc-menu__badge" title="A newer twocans is out">new</span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
        <?php if ($canNotifications): ?>
          <a class="tc-menu__item <?= $screen === 'notifications' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'notifications'])) ?>">
            <i class="fa-solid fa-bell" aria-hidden="true"></i> Notifications
          </a>
        <?php endif; ?>
        <?php if ($canSystem): ?>
          <a class="tc-menu__item <?= $screen === 'homeassistant' ? 'is-active' : '' ?>" role="menuitem"
             href="<?= e(url(['screen' => 'homeassistant'])) ?>">
            <i class="fa-solid fa-house-signal" aria-hidden="true"></i> Home Assistant
          </a>
        <?php endif; ?>

        <div class="tc-menu__divider" role="separator"></div>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="logout">
          <button class="tc-menu__item tc-menu__item--signout" type="submit" role="menuitem">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sign out
          </button>
        </form>
      </div>
    </div>
  </div>
</header>
