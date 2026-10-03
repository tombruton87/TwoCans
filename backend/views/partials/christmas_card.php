<?php
/**
 * How many sleeps until Christmas: its number, what Santa says today, his
 * Christmas Day message, and his call on Christmas morning. See Christmas.
 *
 * @var bool $canEdit
 */
$settings = new SettingsRepository();
$today = new DateTimeImmutable('now', new DateTimeZone(PjsipConfig::timezone()));
$own = (new SantaStore())->file($settings->santaMessage()) !== null;
$phones = Christmas::childPhones();
?>
<section class="tc-card tc-xmas" id="christmas" data-tc-ajax-region>
  <div class="tc-card__intro">
    <h2 class="tc-card__title"><i class="fa-solid fa-tree tc-xmas__tree" aria-hidden="true"></i> Santa's countdown</h2>
    <p class="tc-card__hint">
      Dial <b><?= e($settings->sleepsNumber()) ?></b> and Santa counts down the sleeps. Christmas
      Eve is one more sleep, and on Christmas Day he says Merry Christmas. Today, he says:
    </p>
    <p class="tc-xmas__today"><?= e(Christmas::saying($today)) ?></p>
  </div>

  <h3 class="tc-card__subtitle">Santa's Christmas Day message</h3>
  <div class="tc-vm-row tc-vm-row--bare">
    <audio data-audio preload="none" src="<?= e(url(['download' => 'santa_message', 'builtin' => '1'])) ?>"></audio>
    <button class="tc-vm-play" type="button" data-play aria-label="Play Santa's built-in message">▶</button>
    <div class="tc-grow">
      <div class="tc-vm-row__name">Santa's own<?= $own ? '' : ' — playing on Christmas Day' ?></div>
      <div class="tc-call-row__meta">From the North Pole, for everyone in the house</div>
    </div>
  </div>
  <?php if ($own): ?>
    <div class="tc-vm-row tc-vm-row--bare">
      <audio data-audio preload="none" src="<?= e(url(['download' => 'santa_message', 'v' => substr((string) $settings->santaMessage(), 0, 8)])) ?>"></audio>
      <button class="tc-vm-play" type="button" data-play aria-label="Play your Santa message">▶</button>
      <div class="tc-grow">
        <div class="tc-vm-row__name">Yours — playing on Christmas Day</div>
        <div class="tc-call-row__meta"><?= e(fmt_duration($settings->santaMessageSeconds())) ?></div>
      </div>
      <?php if ($canEdit): ?>
        <form method="post" action="/" class="tc-inline-form" data-tc-ajax>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="santa_audio_remove">
          <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit"
                  data-tc-confirm="Go back to Santa's own message? Yours is deleted.">Use Santa's own</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <p class="tc-card__hint">Or record your own — a grown-up doing Santa, with the children's names in it. Up to three minutes.</p>
    <form method="post" action="/" enctype="multipart/form-data" class="tc-row tc-row--wrap" data-tc-ajax>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="santa_audio">
      <label class="tc-btn tc-btn--ghost tc-audio-file">
        <span data-tc-filename><?= $own ? 'Replace yours' : 'Choose a file' ?></span>
        <input type="file" name="message"
               accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
               data-tc-audiofile data-tc-rec-max="180" data-tc-autosave required>
      </label>
      <noscript><button class="tc-btn tc-btn--teal" type="submit">Save message</button></noscript>
    </form>

    <h3 class="tc-card__subtitle tc-mt-14">The number, and Santa's call</h3>
    <form method="post" action="/" class="tc-stack tc-stack--tight" data-tc-ajax>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="christmas_settings">
      <label class="tc-label tc-label--sm">Number to dial
        <input class="tc-input tc-code-input" type="text" name="number" value="<?= e($settings->sleepsNumber()) ?>"
               inputmode="numeric" pattern="[0-9]*" maxlength="4" style="max-width:120px">
      </label>
      <label class="tc-xmas__ring">
        <input type="hidden" name="ring" value="0">
        <input type="checkbox" name="ring" value="1"<?= $settings->santaRings() ? ' checked' : '' ?>>
        Santa rings on Christmas morning, at
        <input class="tc-input tc-announce__time" type="time" name="ring_time" value="<?= e($settings->santaRingTime()) ?>"
               aria-label="The time Santa rings">
      </label>
      <p class="tc-card__hint">
        <?php if ($phones === []): ?>
          No children's phones to ring yet.
        <?php else: ?>
          He'd ring <?= e(implode(', ', array_column($phones, 'name'))) ?> — once, like a normal call,
          with his message when it's answered. Not phones in adult mode, or with incoming calls off.
        <?php endif; ?>
      </p>
      <div><button class="tc-btn tc-btn--teal" type="submit">Save</button></div>
    </form>
  <?php endif; ?>
</section>
