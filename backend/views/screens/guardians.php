<?php
/** @var Store $store */
$me = Auth::user();
$repo = new GuardianRepository();
$guardians = array_map(
    static fn(array $g): array => Presenter::guardian($g, (int) $me['id']),
    $repo->all()
);
$inviteRole = $store->inviteRole();
$canManage = Auth::can('guardians');
?>
<div class="tc-stack" style="gap:16px;max-width:660px">
  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--teal">♟</span>
    <?php if ($canManage): ?>
      Invite other grown-ups to help. Everyone shares the same line — you decide how much each can change.
    <?php else: ?>
      These are the grown-ups who help run this line. Only the Owner can invite people or change roles.
    <?php endif; ?>
  </div>

  <section class="tc-card" style="padding:0;overflow:hidden;border-radius:20px;box-shadow:var(--tc-shadow-card)">
    <?php foreach ($guardians as $g): ?>
      <div class="tc-guardian-row">
        <div class="tc-avatar tc-avatar--46" style="background:<?= e($g['color']) ?>"><?= e($g['initial']) ?></div>

        <div class="tc-grow">
          <div class="tc-guardian-row__name">
            <?= e($g['name']) ?>
            <?php if ($g['you']): ?><span class="tc-mini-tag tc-mini-tag--you">You</span><?php endif; ?>
            <?php if ($g['isPending']): ?><span class="tc-mini-tag tc-mini-tag--pending">Invite sent</span><?php endif; ?>
          </div>
          <div class="tc-guardian-row__mail"><?= e($g['email']) ?></div>
        </div>

        <?php if (!$g['password_set']): ?>
          <span class="tc-mini-tag tc-mini-tag--pending" title="They cannot sign in until a password is set">No password</span>
        <?php endif; ?>

        <?php if ($canManage && $g['canEditRole']): ?>
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="guardian_role">
            <input type="hidden" name="id" value="<?= e((string) $g['id']) ?>">
            <button class="tc-role-pill tc-role-pill--<?= e($g['roleMod']) ?>" type="submit"
                    title="Switch between Admin and Viewer"><?= e($g['role']) ?></button>
          </form>
        <?php else: ?>
          <span class="tc-role-pill tc-role-pill--<?= e($g['roleMod']) ?>"><?= e($g['role']) ?></span>
        <?php endif; ?>

        <?php /* You can always change your own; an Owner can set anyone's. */ ?>
        <?php if ($g['you'] || $canManage): ?>
          <a class="tc-btn--icon" href="<?= e(url(['screen' => 'guardians', 'password' => $g['id']])) ?>"
             style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;font-size:14px"
             title="<?= $g['you'] ? 'Change your password' : 'Set a password for ' . e($g['name']) ?>">⚿</a>
        <?php endif; ?>

        <?php if ($canManage && $g['canEditRole']): ?>
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="guardian_remove">
            <input type="hidden" name="id" value="<?= e((string) $g['id']) ?>">
            <button class="tc-btn--icon tc-btn--icon-danger" type="submit"
                    style="width:32px;height:32px;border-radius:9px;font-size:15px" title="Remove">×</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
      <form class="tc-invite-row" method="post" action="/">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="guardian_invite">

        <div class="tc-add-guardian">
          <div class="tc-add-guardian__title">Add a grown-up</div>

          <div class="tc-add-guardian__fields">
            <label class="tc-label tc-label--sm tc-grow">Name
              <input class="tc-input tc-input--white" type="text" name="name" placeholder="Marcus Sharma">
            </label>
            <label class="tc-label tc-label--sm tc-grow">Email
              <input class="tc-input tc-input--white" type="email" name="email" placeholder="grown-up@email.com" required>
            </label>
          </div>

          <div class="tc-add-guardian__fields">
            <label class="tc-label tc-label--sm tc-grow">Password
              <input class="tc-input tc-input--white" type="password" name="password"
                     autocomplete="new-password" placeholder="Leave blank to invite without one">
            </label>

            <div class="tc-none">
              <span class="tc-label tc-label--sm" style="display:block;margin-bottom:5px">Role</span>
              <div class="tc-invite-row__roles">
                <?php foreach (['Admin', 'Viewer'] as $role): ?>
                  <label class="tc-pill-btn <?= $inviteRole === $role ? 'is-active' : '' ?>">
                    <input type="radio" name="role" value="<?= e($role) ?>" <?= $inviteRole === $role ? 'checked' : '' ?>
                           style="position:absolute;opacity:0;width:0;height:0">
                    <?= e($role) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="tc-add-guardian__foot">
            <span class="tc-micro">
              Set a password and they can sign in right away. Leave it blank and
              they'll appear as a pending invite you can set one for later.
            </span>
            <button class="tc-btn tc-btn--teal" type="submit" style="padding:11px 18px;border-radius:12px">Add</button>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <div class="tc-grid tc-grid--roles">
    <div class="tc-card tc-card--sm" style="border-radius:16px;padding:14px 16px">
      <div class="tc-role-explainer tc-role-explainer--owner">Owner</div>
      <div class="tc-card__hint" style="margin-top:3px">Full control. Billing, guardians, everything.</div>
    </div>
    <div class="tc-card tc-card--sm" style="border-radius:16px;padding:14px 16px">
      <div class="tc-role-explainer tc-role-explainer--admin">Admin</div>
      <div class="tc-card__hint" style="margin-top:3px">Manage phones, contacts &amp; rules. No billing.</div>
    </div>
    <div class="tc-card tc-card--sm" style="border-radius:16px;padding:14px 16px">
      <div class="tc-role-explainer tc-role-explainer--viewer">Viewer</div>
      <div class="tc-card__hint" style="margin-top:3px">See call logs &amp; voicemail. Can't change settings.</div>
    </div>
  </div>
</div>
