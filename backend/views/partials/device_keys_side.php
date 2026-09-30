<?php
/**
 * Beside a desk phone's hotkeys: its faceplate to print, and how it hears
 * announcements. See Faceplate and Pager.
 *
 * @var array $d DeviceRepository::toView()
 */
$strip = $d['faceplate'] === 'strip';
?>
<section class="tc-card">
  <div class="tc-card__intro">
    <h2 class="tc-card__title">Faceplate</h2>
    <p class="tc-card__hint">
      <?php if ($strip): ?>
        A label for above its three keys, with the photo and name of who each one
        rings. It's 35 × 8.5 mm: print it on sticker paper, or on plain paper and
        tape it under the clear cover.
      <?php else: ?>
        A card for behind its clear cover, with the photo and name of who each
        key rings above the key itself. Print it at actual size, cut it out and
        slide it in.
      <?php endif; ?>
      It follows the hotkeys — change them, then print it again.
    </p>
  </div>
  <?php view('partials/faceplate_preview', ['d' => $d]); ?>
  <a class="tc-btn tc-btn--teal" href="<?= e(url(['faceplate' => $d['id']])) ?>" target="_blank" rel="noopener">
    <i class="fa-solid fa-print" aria-hidden="true"></i> Make the <?= $strip ? 'label' : 'faceplate' ?>
  </a>
</section>

<section class="tc-card">
  <div class="tc-card__intro">
    <h2 class="tc-card__title">Announcements</h2>
    <?php if ($d['pagingMulticast'] && Pager::alive()): ?>
      <p class="tc-card__hint">
        <i class="fa-solid fa-circle-check" style="color:var(--tc-teal-deep)" aria-hidden="true"></i>
        Play straight through its speaker — no call to answer, and its microphone stays off.
      </p>
    <?php elseif ($d['pagingMulticast']): ?>
      <p class="tc-card__hint">
        It's ready for them to play straight through its speaker, but the pager
        isn't running, so for now it's paged with a call it answers by itself.
        <code>./twocans status</code> shows what's wrong.
      </p>
    <?php else: ?>
      <p class="tc-card__hint">
        It's paged with a call it answers by itself. Once it fetches its settings
        on your home network — send them from <a class="tc-link" href="<?= e(url(['screen' => 'phones', 'device' => $d['id'], 'tab' => 'setup'])) ?>">Setup</a>
        — announcements play straight through its speaker, with no call at all.
      </p>
    <?php endif; ?>
  </div>
  <a class="tc-link" href="<?= e(url(['screen' => 'announcements'])) ?>">Announcements →</a>
</section>
