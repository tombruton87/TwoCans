<?php
/**
 * First-run setup. Shown until a household Owner exists — this is the "Set up
 * your family" path the design's login screen points at.
 *
 * @var ?string $error
 * @var array   $old
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php view('partials/head', ['title' => 'twocans — set up your family']); ?>
</head>
<body>
<div class="tc-page">
  <div class="tc-login">
    <div class="tc-login__inner">

      <div class="tc-login__head">
        <?php view('partials/logo', ['variant' => 'login']); ?>
        <div style="text-align:center">
          <div class="tc-wordmark"><span class="tc-two">two</span><span class="tc-cans">cans</span></div>
          <div class="tc-tagline">Let's set up your family line.</div>
        </div>
      </div>

      <form class="tc-login__card" method="post" action="/">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="setup">

        <div class="tc-setup-note">
          You're the first grown-up here, so this account owns the line. You can
          invite others once you're in.
        </div>

        <?php if ($error !== null): ?>
          <div class="tc-form-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <label class="tc-label">Your name
          <input class="tc-input" type="text" name="name" value="<?= e($old['name'] ?? '') ?>"
                 placeholder="Priya Sharma" autocomplete="name" required>
        </label>

        <label class="tc-label">Email
          <input class="tc-input" type="email" name="email" value="<?= e($old['email'] ?? '') ?>"
                 placeholder="you@home.co" autocomplete="username" required>
        </label>

        <label class="tc-label">Password
          <input class="tc-input" type="password" name="password" autocomplete="new-password"
                 minlength="<?= Auth::MIN_PASSWORD_LENGTH ?>" required>
        </label>

        <label class="tc-label">Password again
          <input class="tc-input" type="password" name="password_confirm" autocomplete="new-password"
                 minlength="<?= Auth::MIN_PASSWORD_LENGTH ?>" required>
        </label>

        <div class="tc-micro" style="margin-top:-4px">
          At least <?= Auth::MIN_PASSWORD_LENGTH ?> characters. A few random words works well
          and is easy to remember.
        </div>

        <button class="tc-btn tc-btn--coral tc-btn--lg" type="submit" style="margin-top:6px">Create my line →</button>
      </form>

      <div class="tc-login__foot">Open-source · self-hosted · your kids' calls never touch our servers</div>
    </div>
  </div>
</div>
<script src="<?= e(asset('assets/js/twocans.js')) ?>" defer></script>
</body>
</html>
