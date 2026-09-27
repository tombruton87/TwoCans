<?php
/**
 * @var Store $store
 * @var DeviceRepository $devices
 */
$deviceRows = array_map([DeviceRepository::class, 'toView'], $devices->all());
$deviceRows = array_map([Presenter::class, 'device'], $deviceRows);

// The line at a glance, above the phones themselves.
$online = count(array_filter($deviceRows, static fn(array $d): bool => $d['online']));
$adults = count(array_filter($deviceRows, static fn(array $d): bool => !empty($d['adult'])));
$minutesToday = array_sum(array_map(
    static fn(array $d): int => $devices->minutesToday((int) $d['id']),
    $deviceRows
));
$callsToday = (new CallRepository($devices))->countToday('done');
?>
<div class="tc-stack tc-phones">
<div class="tc-grid tc-grid--stats4">
  <div class="tc-stat tc-stat--teal"><div class="tc-stat__num"><?= count($deviceRows) ?></div><div class="tc-stat__label">phone<?= count($deviceRows) === 1 ? '' : 's' ?> on the line</div></div>
  <div class="tc-stat tc-stat--coral"><div class="tc-stat__num"><?= $online ?>/<?= count($deviceRows) ?></div><div class="tc-stat__label">online now</div></div>
  <div class="tc-stat tc-stat--lav"><div class="tc-stat__num"><?= (int) $callsToday ?></div><div class="tc-stat__label">calls today</div></div>
  <?php if ($adults > 0): ?>
    <div class="tc-stat tc-stat--red"><div class="tc-stat__num"><?= $adults ?></div><div class="tc-stat__label">in adult mode</div></div>
  <?php else: ?>
    <div class="tc-stat tc-stat--red"><div class="tc-stat__num"><?= (int) $minutesToday ?></div><div class="tc-stat__label">minutes talking today</div></div>
  <?php endif; ?>
</div>

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
        <?php if (!empty($d['adult'])): ?>
          <span class="tc-adult-badge tc-adult-badge--sm"><i class="fa-solid fa-unlock" aria-hidden="true"></i> Adult mode — no restrictions</span>
        <?php else: ?>
          <div class="tc-device-card__rule"><?= e($d['ruleSummary']) ?></div>
        <?php endif; ?>
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
      <div class="tc-add-tile__hint">Linphone on a phone or tablet, or a Grandstream desk phone — we'll walk you through it.</div>
    </a>
  <?php endif; ?>
</div>
</div>
