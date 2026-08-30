<?php
/**
 * @var Store  $store
 * @var array $activeCalls
 * @var DeviceRepository $devices
 * @var CallRepository $calls
 */
$settings = $store->settings();
$deviceRows = array_map([DeviceRepository::class, 'toView'], $devices->all());
$deviceRows = array_map([Presenter::class, 'device'], $deviceRows);
$recent = array_map([CallRepository::class, 'toView'], $calls->all(4));
$recent = array_map([Presenter::class, 'call'], $recent);
// Real asks now, derived from blocked calls — see CallRequestRepository.
$askRepo = new CallRequestRepository();
$requests = [];
foreach ($askRepo->pending() as $row) {
    $view = CallRequestRepository::toView($row);
    $tries = $askRepo->attemptCount($view['number']);
    $view['attemptText'] = $tries <= 1 ? 'Tried once' : "Tried {$tries} times";
    $requests[] = $view;
}
$trunk = $store->trunk();

$callsToday = $calls->countToday('done');
$blockedToday = $calls->countToday('blocked');
$onlineCount = count(array_filter($deviceRows, static fn($d) => $d['online']));
?>
<div class="tc-stack">

  <?php foreach ($activeCalls as $call): ?>
    <div class="tc-livebar">
      <span class="tc-livebar__dot"></span>
      <div class="tc-livebar__body">
        <div class="tc-livebar__title">
          Live now · <?= e($call['deviceName']) ?> ↔ <?= e($call['peerName']) ?>
        </div>
        <div class="tc-livebar__meta">
          <?= $call['dir'] === 'in' ? 'Incoming' : 'Outgoing' ?> call ·
          <span data-tc-elapsed="<?= (int) $call['startTs'] ?>"><?= e(fmt_duration($call['seconds'])) ?></span>
          <?php if (!$call['connected']): ?> · ringing<?php endif; ?>
        </div>
      </div>
      <?php if (Auth::can('listen')): ?>
        <a class="tc-btn tc-btn--white"
           href="<?= e(url(['screen' => 'dashboard', 'listen' => $call['channel']])) ?>">Listen in</a>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="call_end">
          <input type="hidden" name="channel" value="<?= e($call['channel']) ?>">
          <button class="tc-btn tc-btn--outline-white" type="submit">End</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($store->isLowCredit()): ?>
    <div class="tc-lowcredit">
      <div class="tc-lowcredit__icon">!</div>
      <div class="tc-grow">
        <div class="tc-lowcredit__title">Phone line is running low</div>
        <div class="tc-lowcredit__body">Only <?= e(Presenter::money($trunk)) ?> of call credit left — top up so calls don't get cut off.</div>
      </div>
      <a class="tc-btn tc-btn--sun" href="<?= e(url(['screen' => 'trunk'])) ?>">Top up</a>
    </div>
  <?php endif; ?>

  <div class="tc-grid tc-grid--stats">
    <div class="tc-stat tc-stat--coral"><div class="tc-stat__num"><?= $callsToday ?></div><div class="tc-stat__label">calls today</div></div>
    <div class="tc-stat tc-stat--teal"><div class="tc-stat__num"><?= $onlineCount ?>/<?= count($deviceRows) ?></div><div class="tc-stat__label">phones online</div></div>
    <div class="tc-stat tc-stat--lav"><div class="tc-stat__num"><?= (new ContactRepository())->count() ?></div><div class="tc-stat__label">people allowed</div></div>
    <div class="tc-stat tc-stat--red"><div class="tc-stat__num"><?= $blockedToday ?></div><div class="tc-stat__label">blocked today</div></div>
  </div>

  <div class="tc-grid tc-grid--panels">

    <!-- The line -->
    <section class="tc-card">
      <div class="tc-card__head">
        <h2 class="tc-card__title">The line</h2>
        <a class="tc-link" href="<?= e(url(['screen' => 'phones'])) ?>">Manage →</a>
      </div>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($deviceRows as $d): ?>
          <a class="tc-line-row" href="<?= e(url(['screen' => 'phones', 'device' => $d['id']])) ?>">
            <span class="tc-can <?= $d['online'] ? '' : 'is-offline' ?>" style="width:42px;height:48px"></span>
            <span class="tc-grow">
              <span class="tc-line-row__name" style="display:block"><?= e($d['name']) ?></span>
              <span class="tc-line-row__meta" style="display:block"><?= e($d['model']) ?> · <?= e($d['statusText']) ?></span>
            </span>
            <span class="tc-string <?= $d['online'] ? '' : 'is-offline' ?>"></span>
            <span class="tc-dot <?= $d['online'] ? '' : 'is-offline' ?>"></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Asks to call -->
    <section class="tc-card">
      <div class="tc-row" style="gap:8px;margin-bottom:6px">
        <h2 class="tc-card__title">Asks to call</h2>
        <?php if ($requests): ?>
          <span class="tc-pill" style="background:#FF7A59;color:#fff"><?= count($requests) ?></span>
        <?php endif; ?>
      </div>
      <p class="tc-card__hint" style="margin:0 0 14px">A grown-up approves before a new number can be dialled.</p>

      <?php if ($requests): ?>
        <div style="display:flex;flex-direction:column;gap:12px">
          <?php foreach ($requests as $r): ?>
            <div class="tc-request">
              <div class="tc-request__label">
                <?php if ($r['saidName'] !== ''): ?>
                  <?php /* What the child said when asked who they meant. It is a
                           guess at a name, so it is offered as one. */ ?>
                  Maybe “<?= e($r['saidName']) ?>”?
                <?php elseif ($r['transcriptStatus'] === 'pending' || $r['transcriptStatus'] === 'running'): ?>
                  <span class="tc-transcribing">Listening to what they said…</span>
                <?php else: ?>
                  Somebody new
                <?php endif; ?>
              </div>
              <div class="tc-request__num"><?= e($r['number']) ?></div>
              <div class="tc-request__note">
                <?= e($r['attemptText']) ?> · <?= e($r['when']) ?>
              </div>

              <?php if ($r['hasRecording']): ?>
                <div class="tc-request__clip">
                  <audio data-audio preload="none"
                         src="<?= e(url(['download' => 'ask_audio', 'id' => $r['id']])) ?>"></audio>
                  <button class="tc-vm-play tc-vm-play--sm" type="button" data-play
                          aria-label="Hear what they said">▶</button>
                  <span>Hear them ask</span>
                </div>
              <?php endif; ?>

              <div class="tc-request__actions"<?= Auth::can('contacts') ? '' : ' hidden' ?>>
                <form method="post" action="/">
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="request_approve">
                  <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                  <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Add them</button>
                </form>
                <form method="post" action="/">
                  <?= form_fields() ?>
                  <input type="hidden" name="action" value="request_deny">
                  <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                  <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Not now</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="tc-empty">All caught up 🎉</div>
      <?php endif; ?>
    </section>

    <!-- Bedtime mode -->
    <section class="tc-bedtime">
      <div class="tc-bedtime__head">
        <h2 class="tc-card__title">Bedtime mode</h2>
        <?php if (Auth::can('rules')): ?>
          <form method="post" action="/">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="toggle_quiet">
            <button type="submit"
                    class="tc-switch tc-switch--light <?= $settings['quietHours'] ? 'is-on' : '' ?>"
                    role="switch"
                    aria-checked="<?= $settings['quietHours'] ? 'true' : 'false' ?>"
                    aria-label="Bedtime mode"></button>
          </form>
        <?php else: ?>
          <span class="tc-switch tc-switch--light <?= $settings['quietHours'] ? 'is-on' : '' ?>"
                role="img" title="Only an Owner or Admin can change this"></span>
        <?php endif; ?>
      </div>
      <div class="tc-bedtime__state"><?= e(Presenter::quietStateText($settings)) ?></div>
      <dl class="tc-bedtime__times">
        <div class="tc-bedtime__slot"><dt>From</dt><dd><?= e($settings['quietFrom']) ?></dd></div>
        <div class="tc-bedtime__slot"><dt>Until</dt><dd><?= e($settings['quietTo']) ?></dd></div>
      </dl>
      <div class="tc-bedtime__foot">Only the SOS contact rings through while it's quiet.</div>
    </section>

    <!-- Recent calls -->
    <section class="tc-card">
      <div class="tc-card__head">
        <h2 class="tc-card__title">Recent calls</h2>
        <a class="tc-link" href="<?= e(url(['screen' => 'calllog'])) ?>">See all →</a>
      </div>
      <div style="display:flex;flex-direction:column;gap:4px">
        <?php foreach ($recent as $c): ?>
          <div class="tc-recent-row">
            <div class="tc-avatar" style="background:<?= e($c['color']) ?>"><?= e($c['initial']) ?></div>
            <div class="tc-grow">
              <div class="tc-recent-row__name"><?= e($c['name']) ?></div>
              <div class="tc-recent-row__meta"><?= e($c['meta']) ?></div>
            </div>
            <span class="tc-pill tc-pill--<?= e($c['statusMod']) ?>"><?= e($c['tag']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

  </div>
</div>
