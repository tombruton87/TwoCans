<?php
/**
 * Getting started: the steps from a fresh install to a line the family can
 * use, each ticked from what's really there — see Onboarding. Shown after the
 * Owner is created; "Do it later" puts it in the menu instead.
 *
 * @var Store $store
 * @var DeviceRepository $devices
 */
$onboarding = new Onboarding();
$steps = [];
foreach ($onboarding->steps() as $s) {
    $steps[$s['key']] = $s;
}
$progress = $onboarding->progress();
$complete = $progress['done'] === $progress['total'];
$state = $onboarding->state();

$phones = array_map([DeviceRepository::class, 'toView'], $devices->all());
$online = array_values(array_filter($phones, static fn(array $d): bool => $d['online']));
$waitingPhone = array_values(array_filter($phones, static fn(array $d): bool => !$d['registered']))[0] ?? null;

// Which step is up next: the first not done or skipped.
$next = null;
foreach ($steps as $key => $s) {
    if (!$s['done'] && !$s['skipped']) {
        $next = $key;
        break;
    }
}
$number = 0;
?>
<div class="tc-split tc-split--main-first">
<div class="tc-split__main tc-start">

  <section class="tc-card tc-start__intro">
    <div class="tc-grow">
      <?php if ($complete): ?>
        <h2 class="tc-start__title">You're all set 🎉</h2>
        <p class="tc-card__hint">The line is ready for the family. Everything here stays in the menu under <b>Getting started</b>.</p>
      <?php else: ?>
        <h2 class="tc-start__title">Let's get your line working</h2>
        <p class="tc-card__hint">A few steps, each ticked off as you do it. Stop whenever you like — they'll wait for you.</p>
      <?php endif; ?>
      <div class="tc-start__bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $progress['total'] ?>" aria-valuenow="<?= (int) $progress['done'] ?>">
        <span style="width:<?= (int) round(100 * $progress['done'] / max(1, $progress['total'])) ?>%"></span>
      </div>
      <div class="tc-micro"><?= (int) $progress['done'] ?> of <?= (int) $progress['total'] ?> done</div>
    </div>
    <div class="tc-start__actions">
      <?php if ($complete && $state !== 'done'): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="onboarding">
          <input type="hidden" name="do" value="done">
          <button class="tc-btn tc-btn--coral" type="submit">Finish</button>
        </form>
      <?php elseif (!$complete && $state === ''): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="onboarding">
          <input type="hidden" name="do" value="later">
          <button class="tc-btn tc-btn--ghost" type="submit">Do it later</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <?php
  /** One step: its number or tick, title, why, and what to do. */
  $open = static function (string $key, string $title, string $why) use ($steps, $next, &$number): void {
      $s = $steps[$key];
      $number++;
      $class = $s['done'] ? 'is-done' : ($s['skipped'] ? 'is-skipped' : ($key === $next ? 'is-next' : ''));
      echo '<section class="tc-card tc-start__step ' . $class . '">';
      echo '<div class="tc-start__mark">'
          . ($s['done'] ? '<i class="fa-solid fa-check" aria-label="done"></i>' : ($s['skipped'] ? '<i class="fa-solid fa-forward" aria-label="skipped"></i>' : (string) $number))
          . '</div><div class="tc-grow"><h3 class="tc-start__step-title">' . e($title) . '</h3>'
          . '<p class="tc-card__hint">' . $why . '</p>';
  };
  $close = static function (): void {
      echo '</div></section>';
  };
  ?>

  <?php $open('phone', 'Add your first phone', 'The twocans app on a phone or tablet, or a Grandstream desk phone or adapter for an ordinary corded phone.'); ?>
    <?php if ($steps['phone']['done']): ?>
      <div class="tc-start__status"><i class="fa-solid fa-circle-check"></i> <?= count($phones) === 1 ? e($phones[0]['name']) . ' has' : count($phones) . ' phones have' ?> signed in.</div>
    <?php elseif ($steps['phone']['waiting'] && $waitingPhone !== null): ?>
      <div class="tc-start__status tc-start__status--wait">
        <i class="fa-solid fa-hourglass-half"></i> <?= e($waitingPhone['name']) ?> is added — waiting for it to sign in.
      </div>
      <a class="tc-btn tc-btn--teal" href="<?= e(url(['screen' => 'phones', 'device' => $waitingPhone['id']])) ?>">Finish setting it up</a>
    <?php else: ?>
      <a class="tc-btn tc-btn--teal" href="<?= e(url(['screen' => 'phones', 'wizard' => 1])) ?>"><i class="fa-solid fa-plus"></i> Add a phone</a>
    <?php endif; ?>
  <?php $close(); ?>

  <?php $open('people', 'Add the people they can call', 'Grandparents, friends, a grown-up\'s mobile — each with when they may call. Only people on this list can ring the kids.'); ?>
    <?php if ($steps['people']['done']): ?>
      <div class="tc-start__status"><i class="fa-solid fa-circle-check"></i> People are on the list. <a class="tc-link" href="<?= e(url(['screen' => 'contacts'])) ?>">Add more</a></div>
    <?php else: ?>
      <form method="post" action="/">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_add">
        <button class="tc-btn tc-btn--teal" type="submit"><i class="fa-solid fa-plus"></i> Add a person</button>
      </form>
    <?php endif; ?>
  <?php $close(); ?>

  <?php $open('line', 'Connect a phone line', 'To call numbers outside the house, and be called from them — with a number from Twilio, for instance. Or skip it for now: the phones can still call each other and the joke line.'); ?>
    <?php if ($steps['line']['done']): ?>
      <div class="tc-start__status"><i class="fa-solid fa-circle-check"></i> Connected. <a class="tc-link" href="<?= e(url(['screen' => 'trunk'])) ?>">Phone line</a></div>
    <?php elseif ($steps['line']['skipped']): ?>
      <div class="tc-start__status tc-start__status--wait"><i class="fa-solid fa-forward"></i> Skipped — calls stay inside the house.</div>
      <a class="tc-btn tc-btn--ghost" href="<?= e(url(['screen' => 'trunk', 'trunkwizard' => 1])) ?>">Connect one after all</a>
    <?php else: ?>
      <div class="tc-row" style="gap:8px">
        <a class="tc-btn tc-btn--teal" href="<?= e(url(['screen' => 'trunk', 'trunkwizard' => 1])) ?>"><i class="fa-solid fa-plug"></i> Connect a line</a>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="onboarding">
          <input type="hidden" name="do" value="skip-line">
          <button class="tc-btn tc-btn--ghost" type="submit">Skip for now</button>
        </form>
      </div>
    <?php endif; ?>
  <?php $close(); ?>

  <?php $open('test', 'Make a test call', 'Ring a phone from here and pick it up to hear a short message. Or, from any phone, dial <b>600</b> to hear your own voice back.'); ?>
    <?php if ($steps['test']['done']): ?>
      <div class="tc-start__status"><i class="fa-solid fa-circle-check"></i> A call has got through.</div>
    <?php elseif ($online === []): ?>
      <div class="tc-start__status tc-start__status--wait"><i class="fa-solid fa-hourglass-half"></i> Once a phone has signed in, ring it from here.</div>
    <?php else: ?>
      <div class="tc-row tc-row--wrap" style="gap:8px">
        <?php foreach ($online as $d): ?>
          <form method="post" action="/">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="device_test_call">
            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button class="tc-btn tc-btn--teal" type="submit"><i class="fa-solid fa-phone-volume"></i> Ring <?= e($d['name']) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php $close(); ?>

  <p class="tc-micro tc-start__more">
    Later, when you want them: <a class="tc-link" href="<?= e(url(['screen' => 'guardians'])) ?>">invite another grown-up</a>,
    record your own <a class="tc-link" href="<?= e(url(['screen' => 'greetings'])) ?>">greetings</a>, or set
    <a class="tc-link" href="<?= e(url(['screen' => 'dashboard'])) ?>">bedtime</a> from the dashboard.
  </p>
</div>

<aside class="tc-split__side tc-stack" style="gap:14px">
  <section class="tc-card">
    <h3 class="tc-start__step-title">What you'll need</h3>
    <ul class="tc-start__list">
      <li><b>A phone for the kids</b> — the Linphone app on an old phone or tablet costs nothing; a Grandstream adapter lets an ordinary corded phone plug in.</li>
      <li><b>For calls to the outside world</b>, a phone number from a provider like Twilio — a few pounds a month. Optional.</li>
      <li><b>About fifteen minutes.</b></li>
    </ul>
  </section>
  <?php if (!WebAuthn::available()): ?>
    <section class="tc-card">
      <h3 class="tc-start__step-title">Want Face ID sign-in?</h3>
      <p class="tc-card__hint">Signing in with Face ID or a fingerprint, and adding twocans to a phone's home
        screen like an app, only work over a secure (HTTPS) connection.</p>
      <p class="tc-card__hint">Give twocans an address and a certificate under
        <a class="tc-link" href="<?= e(url(['screen' => 'trunk'])) ?>#outside">Phone line → Where the outside world finds you</a>,
        then open it at that https:// address.</p>
    </section>
  <?php endif; ?>
  <section class="tc-card">
    <h3 class="tc-start__step-title">Stuck?</h3>
    <p class="tc-card__hint">On the machine twocans runs on, <code>./twocans status</code> shows what's working and what isn't.</p>
    <p class="tc-card__hint">Still stuck? <code>./twocans report</code> makes a report with private details taken out, to attach to a question on
      <a class="tc-link" href="https://github.com/tombruton87/TwoCans/issues" target="_blank" rel="noopener">GitHub</a>.</p>
  </section>
</aside>
</div>
