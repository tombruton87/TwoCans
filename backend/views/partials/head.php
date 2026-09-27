<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php /* Installable: "Add to Home Screen" opens twocans as an app of its own. */ ?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#FBF3E4">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="twocans">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="/assets/pwa/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/pwa/icon-192.png">
<title><?= e($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php /* Font Awesome Free, bundled — see assets/vendor/fontawesome/LICENSE.txt. */ ?>
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/fontawesome.min.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/solid.min.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/regular.min.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome/css/brands.min.css">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700;800&family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/twocans.css')) ?>">
