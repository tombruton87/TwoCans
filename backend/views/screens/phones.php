<?php
/**
 * @var Store $store
 * @var DeviceRepository $devices
 */
$deviceRows = array_map([DeviceRepository::class, 'toView'], $devices->all());
$deviceRows = array_map([Presenter::class, 'device'], $deviceRows);
?>
<div class="tc-grid tc-grid--devices">
  <?php foreach ($deviceRows as $d): ?>
    <a class="tc-device-card" href="<?= e(url(['screen' => 'phones', 'device' => $d['id']])) ?>"
       data-tc-device-status="<?= e((string) $d['id']) ?>">
      <div class="tc-device-card__top">
        <?php if ($d['photo'] !== ''): ?>
          <img class="tc-device-photo tc-device-photo--card" src="<?= e(url(['photo' => $d['photo']])) ?>"
               alt="<?= e($d['name']) ?>">
        <?php else: ?>
          <span class="tc-can tc-can--big <?= $d['online'] ? '' : 'is-offline' ?>" data-tc-can>
            <span class="tc-can__model"><?= e($d['model']) ?></span>
          </span>
        <?php endif; ?>
        <span class="tc-pill tc-pill--<?= e($d['statusMod']) ?>"
              data-tc-status-pill data-tc-status-mod="<?= e($d['statusMod']) ?>"><?= e($d['statusText']) ?></span>
      </div>
      <div>
        <div class="tc-device-card__name">
          <?= e($d['name']) ?>
          <?php if ($d['extension'] !== ''): ?>
            <span class="tc-chip tc-chip--teal" style="vertical-align:middle;margin-left:6px">⌗ <?= e($d['extension']) ?></span>
          <?php endif; ?>
        </div>
        <div class="tc-device-card__rule"><?= e($d['ruleSummary']) ?></div>
      </div>
      <div class="tc-divider"></div>
      <div class="tc-device-card__foot">
        <span class="tc-device-card__seen" data-tc-status-seen><?= e($d['lastSeenText']) ?></span>
        <span class="tc-device-card__open">Open →</span>
      </div>
    </a>
  <?php endforeach; ?>

  <?php if (Auth::can('devices')): ?>
    <a class="tc-add-tile" href="<?= e(url(['screen' => 'phones', 'wizard' => 1])) ?>">
      <div class="tc-add-tile__plus">+</div>
      <div class="tc-add-tile__title">Add a phone</div>
      <div class="tc-add-tile__hint">Plug in a Grandstream HT801 or HT802 and we'll walk you through it.</div>
    </a>
  <?php endif; ?>
</div>
