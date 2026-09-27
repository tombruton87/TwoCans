<?php
/**
 * A weekly timetable editor — rows of days with a from–until time each. Used
 * for bedtime, a phone's hours and a contact's custom window; read back with
 * Schedule::fromInput().
 *
 * Fields: {name}[i][days][], {name}[i][from], {name}[i][to]. A row with no
 * day ticked is dropped on save, so removing a row can simply clear it.
 *
 * @var string $field  form field prefix, e.g. "schedule" (not $name: view()
 *                     keeps that for the partial's own path)
 * @var array  $rules  the schedule to show — see Schedule
 * @var string $hint   optional line under the rows
 */
$hint = $hint ?? '';
$letters = ['mon' => 'M', 'tue' => 'T', 'wed' => 'W', 'thu' => 'T', 'fri' => 'F', 'sat' => 'S', 'sun' => 'S'];
$long = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

$row = static function (string $index, array $rule) use ($field, $letters, $long): void { ?>
  <div class="tc-sched__row" data-tc-sched-row>
    <div class="tc-sched__days" role="group" aria-label="Days">
      <?php foreach (Schedule::DAYS as $day): ?>
        <label class="tc-daychip" title="<?= e($long[$day]) ?>">
          <input type="checkbox" name="<?= e($field) ?>[<?= e($index) ?>][days][]" value="<?= e($day) ?>"
                 <?= in_array($day, $rule['days'], true) ? 'checked' : '' ?>>
          <span aria-hidden="true"><?= e($letters[$day]) ?></span>
          <span class="tc-sr"><?= e($long[$day]) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="tc-sched__times">
      <label class="tc-time-field">From
        <input type="time" name="<?= e($field) ?>[<?= e($index) ?>][from]" value="<?= e($rule['from']) ?>">
      </label>
      <label class="tc-time-field">Until
        <input type="time" name="<?= e($field) ?>[<?= e($index) ?>][to]" value="<?= e($rule['to']) ?>">
      </label>
      <button class="tc-sched__remove" type="button" data-tc-sched-remove aria-label="Remove these times">×</button>
    </div>
  </div>
<?php };
?>
<div class="tc-sched" data-tc-schedule data-tc-sched-name="<?= e($field) ?>">
  <div class="tc-sched__rows" data-tc-sched-rows>
    <?php foreach (array_values($rules) as $i => $rule): ?>
      <?php $row((string) $i, $rule); ?>
    <?php endforeach; ?>
  </div>
  <template data-tc-sched-template>
    <?php $row('__i__', Schedule::rule([], '', '')); ?>
  </template>
  <noscript>
    <?php /* Without the add button, a spare row to fill in. */ ?>
    <?php $row((string) count($rules), Schedule::rule([], '', '')); ?>
  </noscript>
  <div class="tc-sched__foot">
    <button class="tc-link" type="button" data-tc-sched-add>+ Add different times for other days</button>
    <span class="tc-micro">An until earlier than from runs overnight into the next morning.</span>
  </div>
  <?php if ($hint !== ''): ?>
    <p class="tc-card__hint tc-card__after"><?= e($hint) ?></p>
  <?php endif; ?>
</div>
