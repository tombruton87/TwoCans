<!DOCTYPE html>
<html lang="en">
<head>
<?php view('partials/head', ['title' => 'twocans — database unavailable']); ?>
</head>
<body>
<div class="tc-page">
  <div class="tc-login">
    <div class="tc-login__inner">
      <?php view('partials/logo', ['variant' => 'login']); ?>
      <div class="tc-login__card" style="text-align:center;gap:10px">
        <div style="font:800 20px var(--tc-display)">The line is down</div>
        <p class="tc-muted" style="margin:0">
          twocans can't reach its database, so nobody can sign in. Existing calls
          are unaffected — this only stops the admin screens.
        </p>
        <p class="tc-micro" style="margin:0">
          Check the <code>mariadb</code> container is running, then reload.
        </p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
