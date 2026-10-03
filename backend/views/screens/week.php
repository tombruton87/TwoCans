<?php
/**
 * This week: each child's phone's week on the line — see Week.
 *
 * @var Store $store
 */
$monday = Week::fromParam((string) ($_GET['week'] ?? ''));
$week = (new Week())->summary($monday);
$sunday = $monday->modify('+6 days');
$thisWeek = Week::start(new DateTimeImmutable('now', new DateTimeZone(PjsipConfig::timezone())));
$weekUrl = static fn(DateTimeImmutable $m): string => url(['screen' => 'week', 'week' => Week::param($m)]);
$pct = static fn(int $right, int $asked): int => $asked > 0 ? (int) round(100 * $right / $asked) : 0;
?>
<div class="tc-stack tc-week">
  <nav class="tc-week__nav" aria-label="Which week">
    <a class="tc-btn tc-btn--ghost tc-btn--sm" href="<?= e($weekUrl($monday->modify('-7 days'))) ?>">
      <i class="fa-solid fa-chevron-left" aria-hidden="true"></i> The week before
    </a>
    <h2 class="tc-week__title">
      <?= $monday == $thisWeek ? 'This week' : e($monday->format('j M')) . ' – ' . e($sunday->format('j M Y')) ?>
      <span class="tc-card__hint"><?= e($monday->format('D j M')) ?> – <?= e($sunday->format('D j M')) ?></span>
    </h2>
    <?php if ($monday < $thisWeek): ?>
      <a class="tc-btn tc-btn--ghost tc-btn--sm" href="<?= e($weekUrl($monday->modify('+7 days'))) ?>">
        The week after <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </a>
    <?php else: ?>
      <span></span>
    <?php endif; ?>
  </nav>

  <?php if ($week['phones'] === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">No children's phones yet</div>
      <p class="tc-card__hint">Add a phone, and its week shows up here.</p>
    </div>
  <?php endif; ?>

  <div class="tc-week__grid">
    <?php foreach ($week['phones'] as $p): ?>
      <?php $talked = $p['callsOut'] + $p['callsIn']; ?>
      <section class="tc-card tc-week__phone">
        <div class="tc-week__head">
          <a class="tc-week__name" href="<?= e(url(['screen' => 'phones', 'device' => (string) $p['id']])) ?>"><?= e($p['name']) ?></a>
          <span class="tc-card__hint"><?= $talked === 0 ? 'No calls' : e(Week::talk($p['seconds'])) . ' on the phone' ?></span>
        </div>

        <div class="tc-week__stats">
          <div class="tc-week__stat"><b><?= (int) $p['callsOut'] ?></b><span>calls made</span></div>
          <div class="tc-week__stat"><b><?= (int) $p['callsIn'] ?></b><span>calls answered</span></div>
          <div class="tc-week__stat"><b><?= (int) $p['missed'] ?></b><span>missed</span></div>
          <div class="tc-week__stat"><b><?= (int) $p['messages'] ?></b><span><?= $p['messages'] === 1 ? 'message' : 'messages' ?></span></div>
        </div>

        <?php if ($p['people'] !== []): ?>
          <h3 class="tc-week__sub">Talked to most</h3>
          <ol class="tc-week__people">
            <?php foreach ($p['people'] as $who): ?>
              <li><b><?= e($who['name']) ?></b> <span class="tc-card__hint"><?= (int) $who['calls'] ?> <?= $who['calls'] === 1 ? 'call' : 'calls' ?> · <?= e(Week::talk($who['seconds'])) ?></span></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>

        <ul class="tc-week__notes">
          <?php if ($p['busiestDay'] !== null && $talked > 1): ?>
            <li><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> Busiest on <?= e($p['busiestDay']['day']) ?> (<?= (int) $p['busiestDay']['calls'] ?> calls)</li>
          <?php endif; ?>
          <?php if ($p['games'] > 0): ?>
            <li><i class="fa-solid fa-dice" aria-hidden="true"></i> <?= (int) $p['games'] ?> <?= $p['games'] === 1 ? 'game' : 'games' ?> played<?= $p['gamesAsked'] > 0 ? ' · ' . $pct($p['gamesRight'], $p['gamesAsked']) . '% right' : '' ?></li>
          <?php endif; ?>
          <?php if ($p['blocked'] > 0): ?>
            <li><i class="fa-solid fa-ban" aria-hidden="true"></i> Tried <?= (int) $p['blocked'] ?> <?= $p['blocked'] === 1 ? 'number' : 'numbers' ?> that aren't allowed —
              <a class="tc-link" href="<?= e(url(['screen' => 'calllog', 'status' => 'blocked'])) ?>">see which</a></li>
          <?php endif; ?>
        </ul>
      </section>
    <?php endforeach; ?>
  </div>

  <?php if ($week['keepsakes'] > 0): ?>
    <p class="tc-card__hint"><i class="fa-solid fa-star" aria-hidden="true"></i>
      <?= (int) $week['keepsakes'] ?> <?= $week['keepsakes'] === 1 ? 'message' : 'messages' ?> kept for good this week —
      <a class="tc-link" href="<?= e(url(['screen' => 'keepsakes'])) ?>">Keepsakes</a></p>
  <?php endif; ?>
</div>
