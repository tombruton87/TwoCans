<?php
/**
 * Bedtime's times, by day — "weekdays 19:30–07:00, Fri and Sat 21:30–08:30".
 * The switch that turns bedtime on and off stays on the dashboard and in the
 * sidebar; this only says when it applies.
 *
 * @var array $rules the current bedtime schedule — see Schedule
 */
$closeUrl = url(['screen' => 'dashboard']);
?>
<div class="tc-modal" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>"
     role="dialog" aria-modal="true" aria-label="Bedtime times">
  <div class="tc-modal__panel">
    <div class="tc-modal__head">
      <div class="tc-modal__title">Bedtime</div>
      <a class="tc-modal__close" href="<?= e($closeUrl) ?>" aria-label="Close">×</a>
    </div>

    <form class="tc-modal__body" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="bedtime_save">
      <p class="tc-card__hint tc-card__lead">
        While bedtime mode is on, the line sleeps at these times: nothing rings,
        callers hear the quiet-time message, and the phones can only reach an SOS
        contact. Give school nights and weekends different times with a row each.
      </p>
      <?php view('partials/schedule_editor', ['field' => 'schedule', 'rules' => $rules]); ?>
      <button class="tc-btn tc-btn--teal tc-btn--lg tc-mt-14" type="submit">Save bedtime</button>
    </form>
  </div>
</div>
