<?php
/**
 * One card for a number the household hasn't agreed to: a caller who left a
 * message (dashboard) or a number a child tried (call log). Rows come from
 * UnknownQueue, so both screens draw them the same way.
 *
 * @var array $u one UnknownQueue row
 */
?>
<div class="tc-request">
  <div class="tc-row tc-row--tight">
    <span class="tc-pill tc-pill--muted"><?= e($u['tag']) ?></span>
    <?php if ($u['headline'] !== ''): ?>
      <span class="tc-request__label<?= $u['headlineLive'] ? ' tc-transcribing' : '' ?>"><?= e($u['headline']) ?></span>
    <?php endif; ?>
  </div>
  <div class="tc-request__num"><?= e($u['number']) ?></div>
  <div class="tc-request__note"><?= e($u['note']) ?></div>

  <?php if ($u['clip'] !== null): ?>
    <div class="tc-request__clip">
      <?php /* Same player as voicemail and the phones' refusal
               messages, so audio behaves the same everywhere. */ ?>
      <audio data-audio preload="none"
             src="<?= e(url(['download' => $u['clip']['route'], 'id' => $u['clip']['id']])) ?>"></audio>
      <button class="tc-vm-play tc-vm-play--sm" type="button" data-play
              aria-label="<?= e($u['clip']['aria']) ?>">▶</button>
      <span><?= e($u['clip']['label']) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($u['showTranscript']): ?>
    <?php if ($u['transcript'] !== ''): ?>
      <div class="tc-transcript">“<?= e($u['transcript']) ?>”</div>
    <?php elseif ($u['transcriptStatus'] === 'pending' || $u['transcriptStatus'] === 'running'): ?>
      <div class="tc-transcript tc-transcript--empty">
        <span class="tc-transcribing">Listening to this message… the transcript will appear shortly.</span>
      </div>
    <?php elseif ($u['transcriptStatus'] === 'failed'): ?>
      <div class="tc-transcript tc-transcript--empty">Couldn't transcribe this one — play it above.</div>
    <?php else: ?>
      <div class="tc-transcript tc-transcript--empty">Nothing audible in this message.</div>
    <?php endif; ?>
  <?php endif; ?>

  <?php /* Only drawn for a role that may actually decide: `hidden`
           would not do it, because .tc-request__actions is a flex
           box and that beats the hidden attribute. A Viewer would
           have seen buttons that only 403. */ ?>
  <?php if (Auth::can('contacts')): ?>
    <div class="tc-request__actions">
      <form method="post" action="/">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="<?= e($u['approve']['action']) ?>">
        <input type="hidden" name="id" value="<?= e((string) $u['id']) ?>">
        <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit"><?= e($u['approve']['label']) ?></button>
      </form>
      <?php if ($u['dismiss'] !== null): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="<?= e($u['dismiss']['action']) ?>">
          <input type="hidden" name="id" value="<?= e((string) $u['id']) ?>">
          <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit"><?= e($u['dismiss']['label']) ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
