<?php
/**
 * The printable contact sheet — see ContactSheet. A4 or A5, in a theme; the
 * bar across the top chooses, and isn't printed.
 *
 * @var array   $options theme, paper, deviceId (?int), title
 * @var array   $people  ContactSheet::people()
 * @var array   $services ContactSheet::services()
 * @var array   $available ContactSheet::available()
 * @var ?array  $device  the phone it's for, DeviceRepository::toView(), or null
 * @var array   $phones  every phone, for the chooser: id => name
 */
$theme = ContactSheet::THEMES[$options['theme']];
$a5 = $options['paper'] === 'a5';
$title = 'Contact sheet';

// Fit the page: more people, more columns and smaller cards. Everything in a
// card is sized from its column's width, so it scales as one.
$count = count($people);
$cols = $a5 ? ($count <= 4 ? 2 : ($count <= 6 ? 3 : 4)) : ($count <= 6 ? 3 : ($count <= 12 ? 4 : 5));
$usable = $a5 ? 132 : 188;                      // mm across, inside the margins
$gap = $a5 ? 3.5 : 5;
$colW = ($usable - $gap * ($cols - 1)) / $cols;
$mm = static fn(float $v): string => round($v, 2) . 'mm';
$photo = min($a5 ? 17 : 24, $colW * 0.42);
// More lines to dial: smaller pills, and the picture gives up the room.
$lines = count($services);
$snug = $lines > 7;
$art = ($a5 ? ($count > 4 ? 34 : 50) : ($count > 9 ? 62 : 78)) - max(0, $lines - 6) * ($a5 ? 3.5 : 3);
$choice = static fn(string $name, string $value): string => (string) $options[$name] === $value ? ' selected' : '';
?>
<!doctype html>
<html lang="en">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
<style>
  @page { size: <?= $a5 ? 'A5' : 'A4' ?> portrait; margin: 0; }
  body { background: var(--tc-bg-wash); margin: 0; }
  .cs-bar { max-width: 900px; margin: 0 auto; padding: 20px 16px 8px; }
  .cs-bar h1 { font: 800 24px var(--tc-display); color: var(--tc-ink); margin: 0 0 4px; }
  .cs-options { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: end; margin: 14px 0 10px; }
  .cs-options .tc-input { min-width: 150px; }
  .cs-lines { flex-basis: 100%; border: 0; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 6px 14px; }
  .cs-lines legend { padding: 0; margin-bottom: 6px; }
  .cs-lines__note { grid-column: 1 / -1; margin: 0 0 4px; }
  .cs-line { display: flex; align-items: center; gap: 8px; font: 600 14px var(--tc-body); color: var(--tc-ink); cursor: pointer; }
  .cs-line input { width: 18px; height: 18px; accent-color: var(--tc-coral-ink); flex: none; }
  .cs-line i { width: 18px; text-align: center; color: var(--tc-coral-ink); }
  .cs-line b { color: var(--tc-coral-ink); font-weight: 700; }

  /* The page itself: millimetres, so it prints true. */
  .cs-page {
    --pad: <?= $a5 ? '8mm' : '11mm' ?>;
    width: <?= $a5 ? '148mm' : '210mm' ?>; min-height: <?= $a5 ? '210mm' : '297mm' ?>;
    margin: 12px auto 40px; padding: var(--pad); box-sizing: border-box;
    background: var(--cs-bg); color: var(--cs-ink); position: relative; overflow: hidden;
    box-shadow: 0 4px 24px rgba(74, 59, 51, .18);
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
    font-family: var(--tc-body);
  }
  .cs-art { display: block; width: 100%; height: <?= $art ?>mm; object-fit: contain; object-position: center bottom; margin-bottom: 2mm; }
  .cs-title { font: 800 <?= $a5 ? '8.5mm' : '12mm' ?>/1.05 var(--tc-display); text-align: center; margin: 0 0 1.5mm; color: var(--cs-accent); }
  .cs-sub { text-align: center; font: 700 <?= $a5 ? '3.2mm' : '4mm' ?> var(--tc-body); margin: 0 0 <?= $a5 ? '5mm' : '7mm' ?>; color: var(--cs-ink); opacity: .85; }

  .cs-people { display: grid; grid-template-columns: repeat(<?= $cols ?>, 1fr); gap: <?= $mm($gap) ?>; }
  .cs-person {
    background: #fff; border: 0.6mm solid var(--cs-line); border-radius: 5mm;
    padding: <?= $mm(min(4, $colW * 0.06)) ?> 2mm; text-align: center; break-inside: avoid;
    display: flex; flex-direction: column; align-items: center; gap: 1.2mm; position: relative;
  }
  .cs-photo {
    width: <?= $mm($photo) ?>; height: <?= $mm($photo) ?>; border-radius: 50%; overflow: hidden;
    display: flex; align-items: center; justify-content: center; color: #fff;
    font: 800 <?= $mm($photo * 0.42) ?> var(--tc-display); box-shadow: 0 0 0 0.8mm #fff, 0 0 0 1.4mm var(--cs-line);
  }
  .cs-photo img { width: 100%; height: 100%; object-fit: cover; }
  .cs-name { font: 800 <?= $mm(min(5.6, $colW * 0.095)) ?>/1.1 var(--tc-display); color: var(--cs-ink); margin-top: 1mm; overflow-wrap: anywhere; }
  .cs-rel { font: 700 <?= $mm(min(3.4, $colW * 0.062)) ?> var(--tc-body); color: var(--cs-ink); opacity: .8; }
  .cs-dial {
    margin-top: 1mm; padding: 1mm 3.5mm; border-radius: 99px; background: var(--cs-accent); color: #fff;
    font: 800 <?= $mm(min(6.2, $colW * 0.105)) ?>/1.2 var(--tc-display); letter-spacing: .02em;
  }
  .cs-dial--long { font-size: <?= $mm(min(4.4, $colW * 0.075)) ?>; padding-left: 2mm; padding-right: 2mm; }
  .cs-dial small { font: 700 0.62em var(--tc-body); opacity: .9; margin-right: 1mm; }
  .cs-key {
    position: absolute; top: 2mm; right: 2mm; font: 800 <?= $a5 ? '2.8mm' : '3.2mm' ?> var(--tc-body);
    background: var(--cs-bg); color: var(--cs-ink); border: 0.4mm solid var(--cs-line); border-radius: 2mm; padding: 0.4mm 1.6mm;
  }

  .cs-services { display: flex; flex-wrap: wrap; justify-content: center; gap: <?= $a5 ? ($snug ? '2mm' : '2.5mm') : ($snug ? '2.8mm' : '3.5mm') ?>; margin-top: <?= $a5 ? ($snug ? '4mm' : '5mm') : ($snug ? '6mm' : '8mm') ?>; }
  .cs-service {
    display: flex; align-items: center; gap: 2mm; background: #fff; border: 0.5mm solid var(--cs-line);
    border-radius: 99px; padding: <?= $snug ? '1.1mm 3mm' : '1.4mm 3.5mm' ?>; font: 800 <?= $a5 ? ($snug ? '3.1mm' : '3.4mm') : ($snug ? '3.7mm' : '4mm') ?> var(--tc-display); color: var(--cs-ink);
  }
  .cs-service b { background: var(--cs-accent); color: #fff; border-radius: 99px; padding: 0.3mm 2.4mm; }
  .cs-service--sos { border-color: #B3261E; }
  .cs-service--sos b { background: #B3261E; }
  .cs-foot { margin-top: <?= $a5 ? '5mm' : '8mm' ?>; text-align: center; font: 700 <?= $a5 ? '2.8mm' : '3.2mm' ?> var(--tc-body); color: var(--cs-ink); opacity: .75; }
  .cs-empty { text-align: center; font: 700 5mm var(--tc-body); padding: 20mm 0; }

  /* Themes: a background, lines, an accent — each accent dark enough for white on it. */
  .cs-page--dino     { --cs-bg: #EEF8EA; --cs-line: #8FCB98; --cs-accent: #1F6B43; --cs-ink: #1E3D2A; }
  .cs-page--princess { --cs-bg: #FFF0F7; --cs-line: #F2AFD2; --cs-accent: #A42A6C; --cs-ink: #4A2140; }
  .cs-page--racing   { --cs-bg: #FFF7E3; --cs-line: #F2B233; --cs-accent: #B71C1C; --cs-ink: #2B2B2B; }
  .cs-page--plain    { --cs-bg: #FBF3E4; --cs-line: #E6D6BE; --cs-accent: #2A7269; --cs-ink: #4A3B33; }
  /* Racing: a chequered strip along the top and bottom of the page. */
  .cs-page--racing::before, .cs-page--racing::after {
    content: ''; position: absolute; left: 0; right: 0; height: 5mm;
    background: repeating-conic-gradient(#222 0 25%, #fff 0 50%) 0 0 / 5mm 5mm;
  }
  .cs-page--racing::before { top: 0; }
  .cs-page--racing::after { bottom: 0; }
  .cs-page--racing { padding-top: calc(var(--pad) + 3mm); padding-bottom: calc(var(--pad) + 5mm); }

  @media print {
    body { background: #fff; }
    .cs-bar { display: none; }
    .cs-page { margin: 0; box-shadow: none; }
  }
  @media (max-width: 800px) {
    .cs-page { transform-origin: top left; zoom: .5; }
  }
</style>
</head>
<body>
<div class="cs-bar">
  <a class="tc-link" href="<?= e(url(['screen' => 'contacts'])) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to People</a>
  <h1>Contact sheet</h1>
  <p class="tc-card__hint">Everyone the phones can call, with what to dial — for the fridge, or beside a phone with no screen.
    It follows the call list: add someone or give them a speed dial, then print it again.</p>
  <form method="get" action="/" class="cs-options">
    <input type="hidden" name="contactsheet" value="1">
    <label class="tc-label tc-label--sm">Look
      <select class="tc-input" name="theme" data-tc-autosave>
        <?php foreach (ContactSheet::THEMES as $key => $t): ?>
          <option value="<?= e($key) ?>"<?= $choice('theme', $key) ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="tc-label tc-label--sm">Paper
      <select class="tc-input" name="paper" data-tc-autosave>
        <?php foreach (ContactSheet::PAPERS as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $choice('paper', $key) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="tc-label tc-label--sm">For
      <select class="tc-input" name="phone" data-tc-autosave>
        <option value=""<?= $options['deviceId'] === null ? ' selected' : '' ?>>Every phone</option>
        <?php foreach ($phones as $id => $name): ?>
          <option value="<?= (int) $id ?>"<?= $options['deviceId'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?> (shows its hotkeys)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="tc-label tc-label--sm">Title
      <input class="tc-input" type="text" name="title" value="<?= e($options['title']) ?>" maxlength="40">
    </label>
    <fieldset class="cs-lines">
      <legend class="tc-label tc-label--sm">Lines on the sheet</legend>
      <?php if ($device !== null && ContactSheet::rotary($device)): ?>
        <p class="tc-card__hint cs-lines__note">
          <?= e($device['name']) ?> is a rotary phone, so lines that need keys pressed during the call aren't
          offered: <?= e(implode(', ', ContactSheet::keyLines())) ?>.
        </p>
      <?php endif; ?>
      <input type="hidden" name="lines_set" value="1">
      <?php foreach ($available as $key => $line): ?>
        <?php $on = $options['lines'] === null ? $line['ticked'] : in_array($key, $options['lines'], true); ?>
        <label class="cs-line">
          <input type="checkbox" name="lines[]" value="<?= e($key) ?>" data-tc-autosave<?= $on ? ' checked' : '' ?>>
          <span><i class="<?= e($line['icon']) ?>" aria-hidden="true"></i> <?= e($line['label']) ?> <b><?= e($line['dial']) ?></b></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <noscript><button class="tc-btn tc-btn--ghost" type="submit">Update</button></noscript>
    <button class="tc-btn tc-btn--coral" type="button" data-tc-print><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
  </form>
</div>

<main class="cs-page cs-page--<?= e($options['theme']) ?>">
  <?php if ($theme['art'] !== null): ?>
    <img class="cs-art" src="<?= e(asset($theme['art'])) ?>" alt="">
  <?php endif; ?>
  <h2 class="cs-title"><?= e($options['title']) ?></h2>
  <p class="cs-sub">Pick up the phone and dial the number<?= $device !== null && $device['keys'] > 0 ? ' — or press the key' : '' ?>.</p>

  <?php if ($people === []): ?>
    <div class="cs-empty">Nobody on the call list yet — add people under People.</div>
  <?php else: ?>
    <div class="cs-people">
      <?php foreach ($people as $p): ?>
        <div class="cs-person">
          <?php if ($p['key'] !== null): ?><span class="cs-key">Key <?= (int) $p['key'] ?></span><?php endif; ?>
          <div class="cs-photo" style="background:<?= e($p['color'] ?: '#C9B79E') ?>">
            <?php if ($p['photo'] !== ''): ?>
              <img src="<?= e(url(['photo' => $p['photo']])) ?>" alt="">
            <?php elseif ($p['group']): ?>
              <i class="fa-solid fa-users" aria-hidden="true"></i>
            <?php else: ?>
              <?= e($p['initial']) ?>
            <?php endif; ?>
          </div>
          <div class="cs-name"><?= e($p['name']) ?></div>
          <?php if ($p['rel'] !== ''): ?><div class="cs-rel"><?= e($p['rel']) ?></div><?php endif; ?>
          <div class="cs-dial<?= $p['isCode'] ? '' : ' cs-dial--long' ?>"><small>Dial</small><?= e($p['dial']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cs-services">
    <?php foreach ($services as $s): ?>
      <div class="cs-service<?= $s['sos'] ? ' cs-service--sos' : '' ?>">
        <i class="<?= e($s['icon']) ?>" aria-hidden="true"></i> <?= e($s['label']) ?> <b><?= e($s['dial']) ?></b>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cs-foot">
    <?php if ($device !== null): ?>
      This phone is <b><?= e($device['name']) ?></b> — ring it from another phone in the house on <b><?= e($device['extension']) ?></b>.
    <?php else: ?>
      twocans — the family phone line
    <?php endif; ?>
  </div>
</main>
<script src="<?= e(asset('assets/js/twocans.js')) ?>" defer></script>
</body>
</html>
