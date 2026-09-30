<?php
/**
 * A desk phone's faceplate, to print at actual size. A GHP62x's is a card cut
 * out and slid in behind its clear cover; a GHP61x's a label for above its
 * three keys, printed three to a page for spares. See Faceplate for where the
 * measurements come from. Everything is in millimetres, so it prints true on
 * A4 or Letter.
 *
 * @var array $device   DeviceRepository::toView() shape
 * @var array $keys     Faceplate::keys()
 * @var array $options  theme ('twocans'|'white'), cut ('slot'|'split'), photos (bool), title
 */
$W = Faceplate::WIDTH;
$H = Faceplate::HEIGHT;
$mm = static fn(float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') . 'mm';
$cardTitle = $options['title'];
$strip = $device['faceplate'] === 'strip';
$title = 'Faceplate — ' . $device['name'];

/** One key's picture and name, centred over the key. */
$key = static function (array $k, float $cx, float $top, float $photo, float $nameTop, float $nameSize, float $width = 19.5) use ($mm, $options): string {
    if ($k['number'] === '') {
        return '';
    }
    $out = '';
    if ($options['photos']) {
        $style = 'left:' . $mm($cx - $photo / 2) . ';top:' . $mm($top) . ';width:' . $mm($photo) . ';height:' . $mm($photo)
            . ';font-size:' . $mm($photo * 0.46) . ';background:' . $k['color'];
        $inner = $k['photo'] !== ''
            ? '<img src="' . e(url(['photo' => $k['photo']])) . '" alt="">'
            : ($k['icon'] !== '' ? '<i class="' . e($k['icon']) . '"></i>' : e($k['initial']));
        $out .= '<div class="fp-photo" style="' . e($style) . '">' . $inner . '</div>';
    }
    $out .= '<div class="fp-name" data-tc-fit style="left:' . $mm($cx - $width / 2) . ';top:' . $mm($nameTop)
        . ';width:' . $mm($width) . ';font-size:' . $mm($nameSize) . '">' . e($k['name']) . '</div>';

    return $out;
};

// With no photos, the names sit nearer their keys and can be bigger.
$photos = $options['photos'];
$slot = [
    'left' => Faceplate::KEY_X[1] - Faceplate::KEY_WIDTH / 2 - Faceplate::CUT_SPARE,
    'right' => Faceplate::KEY_X[3] + Faceplate::KEY_WIDTH / 2 + Faceplate::CUT_SPARE,
];
$choice = static fn(string $name, string $value): string => $options[$name] === $value ? ' checked' : '';
?>
<!doctype html>
<html lang="en">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
<style>
  @page { size: auto; margin: 0; }
  body { background: #E9E2D6; margin: 0; }
  .fp-bar { max-width: 900px; margin: 0 auto; padding: 20px 16px 8px; }
  .fp-bar h1 { font: 800 24px var(--tc-display); color: var(--tc-ink); margin: 0 0 4px; }
  .fp-options { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: end; margin: 14px 0 10px; }
  .fp-options fieldset { border: 0; padding: 0; margin: 0; display: flex; gap: 12px; flex-wrap: wrap; }
  .fp-options legend { font: 800 12px var(--tc-body); color: var(--tc-ink-2); padding: 0; margin-bottom: 4px; }
  .fp-options label { font: 700 14px var(--tc-body); color: var(--tc-ink); display: flex; gap: 6px; align-items: center; }
  .fp-options .tc-input { min-width: 200px; }

  /* The printed page. On screen, a sheet of paper; printed, just the page. */
  .fp-sheet {
    position: relative; width: 190mm; min-height: 150mm; margin: 12px auto 40px; padding: 18mm 10mm 10mm;
    background: #fff; box-shadow: 0 4px 24px rgba(74, 59, 51, .18); box-sizing: border-box;
    display: flex; gap: 10mm; align-items: flex-start; color: #333;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .fp-help { font: 500 3.3mm/1.45 var(--tc-body); flex: 1; }
  .fp-help h2 { font: 800 4.6mm var(--tc-display); margin: 0 0 2mm; color: var(--tc-ink); }
  .fp-help ol { padding-left: 5mm; margin: 0 0 4mm; }
  .fp-ruler { position: relative; width: 50mm; height: 4mm; border: .25mm solid #333; border-top: 0; margin-top: 3mm; }
  .fp-ruler span { position: absolute; bottom: 0; width: 0; height: 2mm; border-left: .25mm solid #333; }
  .fp-ruler + p { font-size: 2.8mm; color: #666; margin: 1mm 0 0; }

  .fp-card {
    position: relative; flex: none; width: <?= $mm($W) ?>; height: <?= $mm($H) ?>;
    outline: .2mm solid #8a8a8a; overflow: hidden; font-family: var(--tc-body);
  }
  .fp-card--twocans { background: #FBF3E4; }
  .fp-strips { display: flex; flex-direction: column; gap: 6mm; flex: none; }
  .fp-strip { position: relative; outline: .2mm solid #8a8a8a; overflow: hidden; font-family: var(--tc-body); }
  .fp-card--white { background: #fff; }
  .fp-logo { position: absolute; left: 50%; transform: translateX(-50%); }
  .fp-title { position: absolute; left: 3mm; right: 3mm; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font: 800 5.2mm/1.15 var(--tc-display); color: var(--tc-ink); }
  .fp-sub { position: absolute; left: 3mm; right: 3mm; text-align: center; font: 700 2.5mm var(--tc-body); color: var(--tc-ink-2); }
  .fp-photo {
    position: absolute; border-radius: 50%; overflow: hidden; color: #fff; font-family: var(--tc-display); font-weight: 800;
    display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 .35mm #fff;
  }
  .fp-photo img { width: 100%; height: 100%; object-fit: cover; }
  .fp-name { position: absolute; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: var(--tc-display); font-weight: 800; line-height: 1.15; color: var(--tc-ink); }
  .fp-slot { position: absolute; border: .2mm dashed #555; border-radius: 1.8mm; background: #fff; box-sizing: border-box; }
  .fp-cutaway { position: absolute; left: 0; right: 0; border-top: .2mm solid #8a8a8a; border-bottom: .2mm solid #8a8a8a;
    background: repeating-linear-gradient(45deg, #fff 0 .8mm, #ddd .8mm 1.1mm); }

  @media print {
    body { background: #fff; }
    .fp-bar { display: none; }
    .fp-sheet { margin: 0; box-shadow: none; width: auto; padding: 15mm; }
  }
  @media (max-width: 760px) {
    .fp-sheet { width: auto; margin: 12px 16px 40px; padding: 10mm 6mm; flex-direction: column; }
  }
</style>
</head>
<body>
<div class="fp-bar">
  <a class="tc-link" href="<?= e(url(['screen' => 'phones', 'device' => $device['id']])) ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?= e($device['name']) ?></a>
  <h1><?= $strip ? 'Key label' : 'Faceplate' ?> for <?= e($device['name']) ?></h1>
  <p class="tc-card__hint"><?= $strip
      ? 'A label for above its three keys, showing who each one rings.'
      : 'A card for behind the clear cover, showing who each hotkey rings.' ?> Change the keys on the phone's page; this follows.</p>
  <form method="get" action="/" class="fp-options">
    <input type="hidden" name="faceplate" value="<?= (int) $device['id'] ?>">
    <?php if (!$strip): ?>
    <label class="tc-label" style="display:block">Title
      <input class="tc-input" type="text" name="title" value="<?= e($options['title']) ?>" maxlength="40">
    </label>
    <?php endif; ?>
    <fieldset>
      <legend>Look</legend>
      <label><input type="radio" name="theme" value="twocans"<?= $choice('theme', 'twocans') ?>> twocans</label>
      <label><input type="radio" name="theme" value="white"<?= $choice('theme', 'white') ?>> Plain white</label>
    </fieldset>
    <?php if (!$strip): ?>
    <fieldset>
      <legend>Cutting</legend>
      <label><input type="radio" name="cut" value="slot"<?= $choice('cut', 'slot') ?>> One piece, with a slot</label>
      <label><input type="radio" name="cut" value="split"<?= $choice('cut', 'split') ?>> Two pieces</label>
    </fieldset>
    <?php endif; ?>
    <fieldset>
      <legend>Pictures</legend>
      <label><input type="hidden" name="photos" value="0"><input type="checkbox" name="photos" value="1"<?= $photos ? ' checked' : '' ?>> Photos</label>
    </fieldset>
    <button class="tc-btn tc-btn--ghost" type="submit">Update</button>
    <button class="tc-btn tc-btn--coral" type="button" data-tc-print><i class="fa-solid fa-print"></i> Print</button>
  </form>
</div>

<div class="fp-sheet">
  <?php if ($strip): ?>
  <div class="fp-strips">
    <?php for ($copy = 0; $copy < 3; $copy++): ?>
      <div class="fp-strip fp-card--<?= e($options['theme']) ?>" style="width:<?= $mm(Faceplate::STRIP_WIDTH) ?>;height:<?= $mm(Faceplate::STRIP_HEIGHT) ?>">
        <?php
        $cell = Faceplate::STRIP_WIDTH / 3;
        foreach ([1, 2, 3] as $i) {
            $cx = $cell * ($i - 0.5);
            // The same picture and name as the card's, scaled to the strip, and
            // no wider than the key's own third of it.
            echo $photos
                ? $key($keys[$i], $cx, 0.5, 4.6, 5.25, 2.1, $cell - 0.8)
                : $key($keys[$i], $cx, 0, 0, 2.6, 2.9, $cell - 0.8);
        }
        ?>
      </div>
    <?php endfor; ?>
  </div>

  <div class="fp-help">
    <h2><?= e($device['name']) ?>'s key label</h2>
    <ol>
      <li>Print at <b>actual size</b> (100%, not "fit to page"). The line below should measure 5 cm.
        Sticker paper is easiest; there are three, so a spare or two.</li>
      <li>Cut round one label along its edge.</li>
      <li>Stick it in the strip above the three keys, each name over its key.</li>
    </ol>
    <div class="fp-ruler"><?php for ($i = 0; $i <= 5; $i++): ?><span style="left:<?= $i * 10 ?>mm"></span><?php endfor; ?></div>
    <p>5 cm</p>
  </div>
  <?php else: ?>
  <div class="fp-card fp-card--<?= e($options['theme']) ?>">
    <?php if ($options['theme'] === 'twocans'): ?>
      <svg class="fp-logo" style="top:3.2mm;width:26mm;height:13mm" viewBox="0 0 120 60" aria-hidden="true">
        <path stroke="#CDB89B" stroke-width="3" stroke-linecap="round" stroke-dasharray="1.5 7" fill="none" d="M36 30 Q60 30 84 30"/>
        <g transform="rotate(-8 22 31)"><rect x="8" y="14" width="28" height="34" rx="8" fill="#FF7A59"/><ellipse cx="22" cy="14" rx="14" ry="4.6" fill="#FFA98F"/></g>
        <g transform="rotate(8 98 31)"><rect x="84" y="14" width="28" height="34" rx="8" fill="#5BC7B8"/><ellipse cx="98" cy="14" rx="14" ry="4.6" fill="#86DDD1"/></g>
      </svg>
    <?php endif; ?>
    <div class="fp-title" style="top:<?= $options['theme'] === 'twocans' ? '17.5mm' : '9mm' ?>"><?= e($cardTitle) ?></div>
    <div class="fp-sub" style="top:<?= $options['theme'] === 'twocans' ? '24.6mm' : '16.2mm' ?>">Press a key to call</div>

    <?php
    // Top row: above the keys that come through the card.
    foreach ([1, 2, 3] as $i) {
        echo $photos
            ? $key($keys[$i], Faceplate::keyX($i), 39.2, 11.0, 50.9, 3.3)
            : $key($keys[$i], Faceplate::keyX($i), 0, 0, 50.0, 4.2);
    }
    // Bottom row: the strip between the two rows; the keys are just below the card.
    foreach ([4, 5, 6] as $i) {
        echo $photos
            ? $key($keys[$i], Faceplate::keyX($i), 65.9, 6.4, 72.7, 2.9)
            : $key($keys[$i], Faceplate::keyX($i), 0, 0, 70.4, 4.2);
    }
    ?>

    <?php if ($options['cut'] === 'split'): ?>
      <div class="fp-cutaway" style="top:<?= $mm(Faceplate::CUT_TOP) ?>;height:<?= $mm(Faceplate::CUT_BOTTOM - Faceplate::CUT_TOP) ?>"></div>
    <?php else: ?>
      <div class="fp-slot" style="left:<?= $mm($slot['left']) ?>;width:<?= $mm($slot['right'] - $slot['left']) ?>;top:<?= $mm(Faceplate::CUT_TOP) ?>;height:<?= $mm(Faceplate::CUT_BOTTOM - Faceplate::CUT_TOP) ?>"></div>
    <?php endif; ?>
  </div>

  <div class="fp-help">
    <h2><?= e($device['name']) ?>'s faceplate</h2>
    <ol>
      <li>Print at <b>actual size</b> (100%, not "fit to page"). The line below should measure 5 cm.</li>
      <?php if ($options['cut'] === 'split'): ?>
        <li>Cut round the card, then along both lines across it and throw away the shaded strip.</li>
      <?php else: ?>
        <li>Cut round the card, then cut out the dashed slot with a craft knife.</li>
      <?php endif; ?>
      <li>Slide it in behind the phone's clear cover, so the top row of keys comes through<?= $options['cut'] === 'split' ? ' the gap' : ' the slot' ?>.</li>
    </ol>
    <div class="fp-ruler"><?php for ($i = 0; $i <= 5; $i++): ?><span style="left:<?= $i * 10 ?>mm"></span><?php endfor; ?></div>
    <p>5 cm</p>
  </div>
  <?php endif; ?>
</div>
<script src="<?= e(asset('assets/js/twocans.js')) ?>" defer></script>
</body>
</html>
