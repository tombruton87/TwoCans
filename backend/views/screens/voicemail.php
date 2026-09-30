<?php
/**
 * @var Store               $store
 * @var VoicemailRepository $voicemails
 */
$rows = array_map([VoicemailRepository::class, 'toView'], $voicemails->all());
$canDelete = Auth::can('voicemail');
$vmSpeedDial = (new SettingsRepository())->voicemailSpeedDial();

// Every mailbox, by its number: the house's, and each phone's (its extension).
$mailboxes = [PjsipConfig::HOUSE_MAILBOX => "The house's mailbox"];
foreach ((new DeviceRepository())->all() as $d) {
    if ((string) $d['extension'] !== '' && DeviceRepository::toView($d)['available']) {
        $mailboxes[(string) $d['extension']] = $d['name'] . "'s mailbox";
    }
}
?>
<div class="tc-stack tc-stack--tight tc-vm-page">
  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--sun">✉</span>
    Missed callers can leave a message. We transcribe each one so you can read it
    at a glance — or dial <b><?= e(PjsipConfig::VOICEMAIL_NUMBER) ?></b><?= $vmSpeedDial !== '' ? ' (or <b>' . e($vmSpeedDial) . '</b>)' : '' ?>
    from the phone itself to listen. How long each phone rings first is on its
    page, under Rules.
  </div>

  <?php if (Auth::can('rules')): ?>
    <section class="tc-card" id="vm-speed-dial" data-tc-ajax-region>
      <div class="tc-card__intro">
        <h2 class="tc-card__title">A speed dial for messages</h2>
        <p class="tc-card__hint">
          <?= e(PjsipConfig::VOICEMAIL_NUMBER) ?> always plays a phone's messages. Give it a
          shorter one too — like <b>1</b> — that's easier for a child to remember.
          It can also go on a desk phone's hotkey.
        </p>
      </div>
      <form method="post" action="/" class="tc-row tc-row--wrap" data-tc-ajax>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="voicemail_speed_dial">
        <label class="tc-label tc-label--sm">Speed dial
          <input class="tc-input" type="text" name="code" value="<?= e($vmSpeedDial) ?>"
                 inputmode="numeric" pattern="[0-9]{0,4}" maxlength="4" placeholder="none" style="max-width:120px">
        </label>
        <button class="tc-btn tc-btn--teal" type="submit">Save</button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($rows === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">No messages</div>
      <p class="tc-card__hint" style="margin:0 auto;max-width:380px">
        When somebody rings a phone on your line and nobody picks up, they'll be
        invited to leave a message and it will appear here.
      </p>
    </div>
  <?php endif; ?>

  <?php /* Two messages a row on a wide screen: each is mostly its transcript. */ ?>
  <div class="tc-vm-grid">
  <?php foreach ($rows as $v): ?>
    <article class="tc-card tc-card--flat" id="vm-<?= (int) $v['id'] ?>" data-tc-ajax-region>
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
          <?php /* No transcript to show: say why in a line, not a box. */ ?>
          <?php if ($v['transcript'] === ''): ?>
            <div class="tc-call-row__note">
              <?php if ($v['contentExpired']): ?>
                Deleted — older than <?= e((new SettingsRepository())->retentionLabel()) ?>.
              <?php elseif ($v['transcriptStatus'] === 'pending' || $v['transcriptStatus'] === 'running'): ?>
                <span class="tc-transcribing">Transcribing…</span>
              <?php elseif ($v['transcriptStatus'] === 'failed'): ?>
                Couldn't transcribe this one — play it to listen.
              <?php else: ?>
                Nothing audible in this message.
              <?php endif; ?>
            </div>
          <?php endif; ?>
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
      <?php endif; ?>

      <?php /* Which mailbox it's in, and moving it — to a phone, whose owner
               then hears it by dialling 700. */ ?>
      <div class="tc-vm-box">
        <?php if ($canDelete && count($mailboxes) > 1): ?>
          <form method="post" action="/" class="tc-row" data-tc-ajax>
            <?= form_fields() ?>
            <input type="hidden" name="action" value="vm_move">
            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
            <label class="tc-vm-box__label" for="vm-to-<?= (int) $v['id'] ?>"><i class="fa-solid fa-inbox" aria-hidden="true"></i> In</label>
            <select class="tc-input tc-vm-box__pick" name="to" id="vm-to-<?= (int) $v['id'] ?>" data-tc-autosave>
              <?php foreach ($mailboxes as $box => $label): ?>
                <option value="<?= e((string) $box) ?>"<?= (string) $box === $v['mailbox'] ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
              <?php if (!isset($mailboxes[$v['mailbox']])): ?>
                <option value="<?= e($v['mailbox']) ?>" selected>Mailbox <?= e($v['mailbox']) ?></option>
              <?php endif; ?>
            </select>
            <noscript><button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Move</button></noscript>
          </form>
        <?php else: ?>
          <span class="tc-vm-box__label"><i class="fa-solid fa-inbox" aria-hidden="true"></i> In <?= e($mailboxes[$v['mailbox']] ?? 'mailbox ' . $v['mailbox']) ?></span>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</div>
