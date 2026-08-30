<?php
/**
 * Set or reset a guardian's password.
 *
 * Changing your own requires the current password; an Owner setting someone
 * else's does not (that is the recovery path when they're locked out).
 *
 * @var array $guardian
 * @var bool  $isSelf
 */
$closeUrl = url(['screen' => 'guardians']);
?>
<div class="tc-modal" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>"
     role="dialog" aria-modal="true" aria-label="Set password">
  <div class="tc-modal__panel tc-modal__panel--sm">

    <div class="tc-modal__head">
      <div class="tc-modal__title"><?= $isSelf ? 'Change your password' : 'Set a password' ?></div>
      <a class="tc-modal__close" href="<?= e($closeUrl) ?>" aria-label="Close">×</a>
    </div>

    <form class="tc-modal__body" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="guardian_password">
      <input type="hidden" name="id" value="<?= e((string) $guardian['id']) ?>">

      <div class="tc-row" style="gap:13px;margin-bottom:16px">
        <div class="tc-avatar tc-avatar--46" style="background:<?= e($guardian['color']) ?>"><?= e(initial($guardian['name'])) ?></div>
        <div class="tc-grow">
          <div style="font:800 15px var(--tc-display)"><?= e($guardian['name']) ?></div>
          <div class="tc-guardian-row__mail"><?= e($guardian['email']) ?> · <?= e($guardian['role']) ?></div>
        </div>
      </div>

      <?php if (!$isSelf): ?>
        <div class="tc-setup-note" style="margin-bottom:14px">
          They'll be able to sign in with this straight away. Tell it to them over
          something safer than email, and let them change it once they're in.
        </div>
      <?php endif; ?>

      <div style="display:flex;flex-direction:column;gap:12px">
        <?php if ($isSelf): ?>
          <label class="tc-label tc-label--sm">Current password
            <input class="tc-input tc-input--white" type="password" name="current_password"
                   autocomplete="current-password" required autofocus>
          </label>
        <?php endif; ?>

        <label class="tc-label tc-label--sm">New password
          <input class="tc-input tc-input--white" type="password" name="password"
                 autocomplete="new-password" minlength="<?= Auth::MIN_PASSWORD_LENGTH ?>" required
                 <?= $isSelf ? '' : 'autofocus' ?>>
        </label>

        <label class="tc-label tc-label--sm">New password again
          <input class="tc-input tc-input--white" type="password" name="password_confirm"
                 autocomplete="new-password" minlength="<?= Auth::MIN_PASSWORD_LENGTH ?>" required>
        </label>
      </div>

      <div class="tc-micro" style="margin:10px 0 16px">
        At least <?= Auth::MIN_PASSWORD_LENGTH ?> characters. Any sign-in lockout on
        this account will be cleared.
      </div>

      <div class="tc-wizard-actions">
        <a class="tc-btn tc-btn--ghost" href="<?= e($closeUrl) ?>" style="padding:13px 18px">Cancel</a>
        <button class="tc-btn tc-btn--teal tc-btn--grow" type="submit" style="padding:13px">Save password</button>
      </div>
    </form>
  </div>
</div>
