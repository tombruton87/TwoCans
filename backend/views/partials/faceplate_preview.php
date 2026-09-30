<?php
/**
 * A desk phone's faceplate, small, beside its hotkeys: redrawn by the page's
 * script as each key is picked (data-tc-fpview), before anything is saved or
 * printed. The same layout as views/faceplate.php — see Faceplate — in
 * twocans colours, with photos.
 *
 * @var array $d DeviceRepository::toView()
 */
$strip = $d['faceplate'] === 'strip';
$keys = Faceplate::keys((new DeviceHotkeyRepository())->forDevice((int) $d['id']), $d['keys']);
$choices = [];
foreach (Faceplate::everyChoice() as $number => $k) {
    $k['photo'] = $k['photo'] !== '' ? url(['photo' => $k['photo']]) : '';
    $choices[$number] = $k;
}
$mm = static fn(float $v): string => round($v, 3) . 'mm';

/** Where each key's picture and name sit: [centre x, photo top, photo size, name top, name size, name width]. */
$slot = static function (int $i) use ($strip): array {
    if ($strip) {
        $cell = Faceplate::STRIP_WIDTH / 3;

        return [$cell * ($i - 0.5), 0.5, 4.6, 5.25, 2.1, $cell - 0.8];
    }

    return $i <= 3
        ? [Faceplate::keyX($i), 39.2, 11.0, 50.9, 3.3, 19.5]
        : [Faceplate::keyX($i), 65.9, 6.4, 72.7, 2.9, 19.5];
};
?>
<div class="tc-fpview<?= $strip ? ' tc-fpview--strip' : '' ?>" data-tc-fpview aria-hidden="true">
  <script type="application/json" data-tc-fpview-choices><?= json_encode($choices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <div class="tc-fpview__card" style="width:<?= $mm($strip ? Faceplate::STRIP_WIDTH : Faceplate::WIDTH) ?>;height:<?= $mm($strip ? Faceplate::STRIP_HEIGHT : Faceplate::HEIGHT) ?>">
    <?php if (!$strip): ?>
      <svg class="tc-fpview__logo" viewBox="0 0 120 60">
        <path stroke="#CDB89B" stroke-width="3" stroke-linecap="round" stroke-dasharray="1.5 7" fill="none" d="M36 30 Q60 30 84 30"/>
        <g transform="rotate(-8 22 31)"><rect x="8" y="14" width="28" height="34" rx="8" fill="#FF7A59"/><ellipse cx="22" cy="14" rx="14" ry="4.6" fill="#FFA98F"/></g>
        <g transform="rotate(8 98 31)"><rect x="84" y="14" width="28" height="34" rx="8" fill="#5BC7B8"/><ellipse cx="98" cy="14" rx="14" ry="4.6" fill="#86DDD1"/></g>
      </svg>
      <div class="tc-fpview__title"><?= e($d['name']) ?></div>
      <div class="tc-fpview__slot" style="left:<?= $mm(Faceplate::KEY_X[1] - Faceplate::KEY_WIDTH / 2 - Faceplate::CUT_SPARE) ?>;width:<?= $mm(Faceplate::KEY_X[3] - Faceplate::KEY_X[1] + Faceplate::KEY_WIDTH + 2 * Faceplate::CUT_SPARE) ?>;top:<?= $mm(Faceplate::CUT_TOP) ?>;height:<?= $mm(Faceplate::CUT_BOTTOM - Faceplate::CUT_TOP) ?>"></div>
    <?php endif; ?>
    <?php foreach ($keys as $i => $k): ?>
      <?php [$cx, $top, $size, $nameTop, $nameSize, $width] = $slot($i); ?>
      <div class="tc-fpview__key" data-tc-fpview-key="<?= (int) $i ?>"
           style="--photo:<?= $mm($size) ?>;--name:<?= $mm($nameSize) ?>">
        <span class="tc-fpview__photo" style="left:<?= $mm($cx - $size / 2) ?>;top:<?= $mm($top) ?>"></span>
        <span class="tc-fpview__name" style="left:<?= $mm($cx - $width / 2) ?>;top:<?= $mm($nameTop) ?>;width:<?= $mm($width) ?>"></span>
      </div>
    <?php endforeach; ?>
  </div>
  <script type="application/json" data-tc-fpview-now><?= json_encode(array_map(static fn(array $k): string => $k['number'], $keys), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
