<?php
/**
 * @var ?string $error
 * @var array   $old
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php view('partials/head', ['title' => 'twocans — sign in']); ?>
</head>
<body>
<div class="tc-page">
  <div class="tc-login">
    <div class="tc-login__inner">

      <div class="tc-login__head">
        <?php view('partials/logo', ['variant' => 'login']); ?>
        <div style="text-align:center">
          <div class="tc-wordmark"><span class="tc-two">two</span><span class="tc-cans">cans</span></div>
          <div class="tc-tagline">A tiny phone company — run by you.</div>
        </div>
      </div>

      <form class="tc-login__card" method="post" action="/">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="login">

        <?php if ($error !== null): ?>
          <div class="tc-form-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <label class="tc-label">Email
          <input class="tc-input" type="email" name="email" value="<?= e($old['email'] ?? '') ?>"
                 autocomplete="username" required>
        </label>

        <label class="tc-label">Password
          <input class="tc-input" type="password" name="password" autocomplete="current-password" required>
        </label>

        <button class="tc-btn tc-btn--coral tc-btn--lg" type="submit" style="margin-top:6px">Pick up the line →</button>

        <div class="tc-login__alt">Forgotten it? A grown-up with Owner access can reset it for you.</div>
      </form>

      <div class="tc-login__foot">Open-source · self-hosted · your kids' calls never touch our servers</div>
    </div>
  </div>
</div>
<script src="<?= e(asset('assets/js/twocans.js')) ?>" defer></script>
</body>
</html>
