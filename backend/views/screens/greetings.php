<?php
/**
 * Greetings: everything the line says out loud, in one place, grouped by who
 * is listening. Each row plays what's recorded, says where it plays, and takes
 * a new recording.
 *
 * Most rows are the stock prompts in Greetings. The bedtime message and the
 * house group greeting keep their own actions; each group's own greeting is
 * set on that group, so it links there, and so is each person's name clip.
 * Each phone's refusal lives only on that phone's page.
 */
$canEdit = Auth::can('rules');
$settings = new SettingsRepository();
$greetings = new Greetings($settings);
$groups = array_map([ContactRepository::class, 'toView'], (new ContactRepository())->groups());
// Everyone who can ring in, for their name clips — set on each person.
$people = array_values(array_filter(
    array_map([ContactRepository::class, 'toView'], (new ContactRepository())->all()),
    static fn(array $c): bool => !$c['isGroup'] && $c['number'] !== ''
));

$slot = static function (string $key) use ($greetings, $canEdit): void {
    $s = Greetings::SLOTS[$key];
    view('partials/greeting_row', [
        'title' => $s['title'],
        'when' => $s['when'],
        'says' => $s['says'],
        'tip' => $s['tip'],
        'src' => $greetings->file($key) === null ? null : url(['download' => 'greeting', 'slot' => $key]),
        'seconds' => $greetings->seconds($key),
        'action' => 'greeting_save',
        'remove' => 'greeting_remove',
        'hidden' => ['slot' => $key],
        'field' => 'greeting',
        'max' => 30,
        'canEdit' => $canEdit,
    ]);
};
?>
<div class="tc-stack tc-greetings">

  <p class="tc-card__hint tc-greetings__intro">
    Out of the box the line speaks in Asterisk's own voice, and some of what it
    says isn't right for a family phone. Upload your own for any of these — a
    voice memo off your phone is fine. Anything you haven't recorded keeps the
    standard one.
  </p>

  <section class="tc-card">
    <div class="tc-greetings__head">
      <h2 class="tc-card__title">Callers ringing the house</h2>
      <div class="tc-card__hint">What someone outside the house hears when they ring in.</div>
    </div>

    <?php $slot('voicemail'); ?>

    <?php view('partials/greeting_row', [
        'title' => 'Quiet-time message',
        'when' => 'Plays instead of ringing during bedtime, or when someone calls outside the hours '
                . 'set on them. They can press 5 for the joke line, or wait and leave a message. '
                . 'Without one, they go straight to the voicemail greeting.',
        'tip' => '“It\'s late, so we can\'t take your call just now — press 5 for a joke.”',
        'src' => $settings->quietMessage() === null ? null : url(['download' => 'quiet_message']),
        'seconds' => $settings->quietMessageSeconds(),
        'action' => 'quiet_message',
        'remove' => 'quiet_message_remove',
        'field' => 'message',
        'max' => 30,
        'canEdit' => $canEdit,
    ]); ?>

    <?php $slot('unknown_caller'); ?>
  </section>

  <section class="tc-card">
    <div class="tc-greetings__head">
      <h2 class="tc-card__title">What the kids hear</h2>
      <div class="tc-card__hint">When a call from one of the phones can't go through.</div>
    </div>
    <?php $slot('not_allowed'); ?>
    <?php $slot('ask'); ?>
    <?php $slot('outside_hours'); ?>
    <?php $slot('no_line'); ?>

    <div class="tc-greetings__head tc-mt-14">
      <h3 class="tc-card__subtitle">Who's calling</h3>
      <div class="tc-card__hint">
        Each person's name, played on a phone with <b>Say who's calling</b> switched on when
        it's picked up — before the call connects. Set on each person.
      </div>
    </div>
    <?php foreach ($people as $p): ?>
      <?php view('partials/greeting_row', [
          'title' => $p['name'],
          'when' => $p['announce'] !== ''
              ? 'Plays when they call.'
              : 'No name yet, so their calls just connect.',
          'src' => $p['announce'] !== '' ? url(['download' => 'caller_name', 'id' => $p['id']]) : null,
          'seconds' => $p['announceSeconds'],
          'href' => url(['screen' => 'contacts', 'contact' => $p['id']]),
          'canEdit' => $canEdit,
      ]); ?>
    <?php endforeach; ?>
  </section>

  <section class="tc-card">
    <div class="tc-greetings__head">
      <h2 class="tc-card__title">Grown-ups answering a group call</h2>
      <div class="tc-card__hint">
        They press 1 to join. Anything else — or a voicemail, which never presses
        anything — hangs up, so keep “press 1” in the recording.
      </div>
    </div>

    <?php view('partials/greeting_row', [
        'title' => 'Group call greeting',
        'when' => 'Plays to each grown-up who answers a group call, before they join. Used by every '
                . 'group without a greeting of its own.',
        'says' => '“Press 1 to accept this call, or 2 to reject it.”',
        'tip' => '“The kids are calling — press 1 to join, or 2 if you can\'t.”',
        'src' => $settings->groupPrompt() === null ? null : url(['download' => 'group_prompt']),
        'seconds' => $settings->groupPromptSeconds(),
        'action' => 'group_prompt',
        'remove' => 'group_prompt_remove',
        'field' => 'greeting',
        'max' => 20,
        'canEdit' => $canEdit,
    ]); ?>

    <?php foreach ($groups as $g): ?>
      <?php view('partials/greeting_row', [
          'title' => $g['name'],
          'when' => $g['groupPrompt'] !== ''
              ? 'This group\'s own greeting, played instead of the one above.'
              : 'No greeting of its own, so it uses the one above.',
          'src' => $g['groupPrompt'] !== '' ? url(['download' => 'group_prompt', 'id' => $g['id']]) : null,
          'seconds' => $g['groupPromptSeconds'],
          'href' => url(['screen' => 'contacts', 'contact' => $g['id']]),
          'canEdit' => $canEdit,
      ]); ?>
    <?php endforeach; ?>
  </section>

</div>
