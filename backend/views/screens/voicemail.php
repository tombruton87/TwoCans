<?php
/**
 * @var Store               $store
 * @var VoicemailRepository $voicemails
 */
$rows = array_map([VoicemailRepository::class, 'toView'], $voicemails->all());
$canDelete = Auth::can('voicemail');
?>
<div class="tc-stack tc-stack--tight tc-narrow--vm">
  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--sun">✉</span>
    Missed callers can leave a message. We transcribe each one so you can read it
    at a glance — or dial <b><?= e(PjsipConfig::VOICEMAIL_NUMBER) ?></b> from the
    phone itself to listen.
  </div>

  <?php if ($rows === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">No messages</div>
      <p class="tc-card__hint" style="margin:0 auto;max-width:380px">
        When somebody rings a phone on your line and nobody picks up, they'll be
        invited to leave a message and it will appear here.
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $v): ?>
    <article class="tc-card tc-card--flat">
      <div class="tc-vm-row">
        <?php /* Same control as the call log, so playing audio looks the same
                 wherever it appears in the app. */ ?>
        <?php if ($v['hasAudio']): ?>
          <audio data-audio preload="none" src="<?= e(url(['download' => 'voicemail_audio', 'id' => $v['id']])) ?>"></audio>
          <button class="tc-vm-play" type="button" data-play
                  aria-label="Play the message from <?= e($v['name']) ?>">▶</button>
        <?php else: ?>
          <span class="tc-vm-play is-gone" aria-hidden="true" title="The audio has been deleted">▶</span>
        <?php endif; ?>

        <div class="tc-avatar tc-avatar--44" style="background:<?= e($v['color']) ?>"><?= e($v['initial']) ?></div>

        <div class="tc-grow">
          <div class="tc-vm-row__name">
            <?= e($v['name']) ?>
            <?php if (!$v['heard']): ?><span class="tc-vm-unheard" title="Not heard yet"></span><?php endif; ?>
          </div>
          <div class="tc-call-row__meta">
            <?= e($v['number']) ?> · <?= e($v['date']) ?> <?= e($v['time']) ?> · <?= e($v['dur']) ?>
          </div>
        </div>

        <?php if ($v['hasAudio']): ?>
          <a class="tc-btn--icon" style="display:flex;align-items:center;justify-content:center"
             href="<?= e(url(['download' => 'voicemail_audio', 'id' => $v['id']])) ?>" download
             title="Save the audio">↓</a>
        <?php endif; ?>

        <?php if ($canDelete): ?>
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="vm_delete">
            <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
            <button class="tc-btn--icon tc-btn--icon-danger" type="submit" title="Delete">×</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($v['hasAudio']): ?>
        <div class="tc-eqstrip" data-eq title="Click to skip through the message">
          <?php view('partials/eq', ['variant' => 'vm']); ?>
        </div>
      <?php endif; ?>

      <?php if ($v['transcript'] !== ''): ?>
        <div class="tc-transcript">“<?= e($v['transcript']) ?>”</div>
      <?php else: ?>
        <div class="tc-transcript tc-transcript--empty">
          <?php if ($v['contentExpired']): ?>
            Message deleted — it was older than <?= e((new SettingsRepository())->retentionLabel()) ?>.
          <?php elseif ($v['transcriptStatus'] === 'pending' || $v['transcriptStatus'] === 'running'): ?>
            <span class="tc-transcribing">Listening to this message… the transcript will appear shortly.</span>
          <?php elseif ($v['transcriptStatus'] === 'failed'): ?>
            Couldn't transcribe this one — play it above.
          <?php else: ?>
            Nothing audible in this message.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
