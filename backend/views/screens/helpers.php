<?php
/**
 * The kitchen timer and "what time is it?": their numbers, what the clock
 * says now, and the timers running. See Clock and Timers.
 *
 * @var Store $store
 */
$settings = new SettingsRepository();
$canEdit = Auth::can('rules');
$now = new DateTimeImmutable('now', new DateTimeZone(PjsipConfig::timezone()));
$running = (new Timers())->running();
$names = [];
foreach ((new DeviceRepository())->all() as $row) {
    $names[(string) $row['sip_username']] = (string) $row['name'];
}
?>
<?php
// A line's number, changeable here.
$numberForm = static function (string $which, string $number) use ($canEdit): void {
    if (!$canEdit) {
        return;
    } ?>
      <form method="post" action="/" class="tc-row tc-row--wrap" data-tc-ajax>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="helper_numbers">
        <input type="hidden" name="which" value="<?= e($which) ?>">
        <label class="tc-label tc-label--sm">Number to dial
          <input class="tc-input tc-code-input" type="text" name="number" value="<?= e($number) ?>"
                 inputmode="numeric" pattern="[0-9]*" maxlength="4" style="max-width:120px" data-tc-autosave>
        </label>
      </form>
<?php }; ?>
<div class="tc-stack tc-helpers">
  <section class="tc-card" id="helper-clock" data-tc-ajax-region>
    <h2 class="tc-card__title"><i class="fa-solid fa-clock" aria-hidden="true"></i> What time is it?</h2>
    <p class="tc-card__hint">
      Dial <b><?= e($settings->clockNumber()) ?></b> (T-I-M-E) and it says the time the way children learn it — to the
      nearest five minutes — and, when bedtime's less than two hours away, how long till then. Right now, it says:
    </p>
    <p class="tc-xmas__today"><?= e(Clock::say($now, $settings)) ?></p>
    <?php $numberForm('clock', $settings->clockNumber()); ?>
  </section>

  <section class="tc-card" id="helper-timer" data-tc-ajax-region>
    <h2 class="tc-card__title"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> Kitchen timer</h2>
    <p class="tc-card__hint">
      Dial <b><?= e($settings->timerNumber()) ?></b> (C-H-I-M-E), type the minutes and <b>#</b>, and hang up: the phone
      rings when they're up — for baking, brushing teeth, or ten more minutes of play. One timer a phone; setting
      another replaces it, and <b>0#</b> cancels it. Up to <?= Timers::MAX_MINUTES / 60 ?> hours.
    </p>
    <?php if ($running === []): ?>
      <p class="tc-card__hint"><b>No timers running.</b></p>
    <?php else: ?>
      <ul class="tc-timers">
        <?php foreach ($running as $t): ?>
          <li>
            <span class="tc-grow"><b><?= e($t['phone']) ?></b> — rings at <?= e(date('g:ia', $t['due'])) ?>
              <span class="tc-card__hint">(in <?= max(1, (int) ceil(($t['due'] - time()) / 60)) ?> min)</span></span>
            <?php if ($canEdit): ?>
              <form method="post" action="/" data-tc-ajax>
                <?= form_fields() ?>
                <input type="hidden" name="action" value="timer_cancel">
                <input type="hidden" name="endpoint" value="<?= e($t['endpoint']) ?>">
                <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Cancel</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php $numberForm('timer', $settings->timerNumber()); ?>
  </section>

  <section class="tc-card" id="helper-silly" data-tc-ajax-region>
    <h2 class="tc-card__title"><i class="fa-solid fa-face-grin-squint-tears" aria-hidden="true"></i> Silly voices</h2>
    <p class="tc-card__hint">
      Dial <b><?= e($settings->sillyNumber()) ?></b> (S-I-L-L), say something after the beep, and hear it back as a
      chipmunk and then a giant. Press <b>1</b> for another go. Nothing's kept: the recording's thrown away when
      they hang up.
    </p>
    <?php $numberForm('silly', $settings->sillyNumber()); ?>
  </section>

  <section class="tc-card" id="helper-walkie" data-tc-ajax-region>
    <h2 class="tc-card__title"><i class="fa-solid fa-walkie-talkie" aria-hidden="true"></i> Walkie-talkie</h2>
    <p class="tc-card__hint">
      Dial <b><?= e($settings->walkieNumber()) ?></b> (W-A-L-K) — or put it on a hotkey — and the phone it's paired with
      answers by itself on speaker, with a beep, to talk both ways. Pair them on each phone's page, under Rules.
      Never into a call, and not at bedtime.
    </p>
    <?php
    $pairs = [];
    foreach ((new DeviceRepository())->all() as $row) {
        $p = DeviceRepository::toView($row);
        if ($p['walkieTo'] !== null) {
            $to = (new DeviceRepository())->find($p['walkieTo']);
            $pairs[] = $p['name'] . ' → ' . ($to['name'] ?? '?');
        }
    }
    ?>
    <p class="tc-card__hint"><b><?= $pairs === [] ? 'No phones paired yet.' : e(implode(' · ', $pairs)) ?></b></p>
    <?php $numberForm('walkie', $settings->walkieNumber()); ?>
  </section>
</div>
