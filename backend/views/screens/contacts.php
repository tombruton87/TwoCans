<?php
/**
 * @var Store $store
 * @var ContactRepository $contacts
 */
$rows = array_map([ContactRepository::class, 'toView'], $contacts->all());
$rows = array_map([Presenter::class, 'contact'], $rows);
$canEdit = Auth::can('contacts');

// Just saved someone who isn't on a hotkey, with a desk phone's key free:
// offer to put them on one. See actions.php, contact_save.
$offerFor = isset($_SESSION['keyOffer']) && Auth::can('devices') ? $contacts->find((int) $_SESSION['keyOffer']) : null;
$offers = $offerFor !== null ? (new DeviceHotkeyRepository())->offersFor(DeviceHotkeyRepository::targetOf($offerFor)) : [];
unset($_SESSION['keyOffer']); // offered once; the buttons don't need it
?>
<?php if ($offers !== []): ?>
  <section class="tc-card tc-key-offer" role="status">
    <i class="fa-solid fa-phone-flip tc-key-offer__icon" aria-hidden="true"></i>
    <div class="tc-grow">
      <div class="tc-key-offer__title">Put <?= e((string) $offerFor['name']) ?> on a hotkey?</div>
      <div class="tc-card__hint">One press on a desk phone and it rings <?= (int) $offerFor['is_group'] === 1 ? 'them all' : 'them' ?>.</div>
    </div>
    <div class="tc-row tc-row--wrap" style="gap:8px">
      <?php foreach ($offers as $o): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="hotkey_offer">
          <input type="hidden" name="id" value="<?= (int) $offerFor['id'] ?>">
          <input type="hidden" name="device" value="<?= (int) $o['id'] ?>">
          <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit"><?= e($o['name']) ?> · key <?= (int) $o['key'] ?></button>
        </form>
      <?php endforeach; ?>
      <form method="post" action="/">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="hotkey_offer_dismiss">
        <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Not now</button>
      </form>
    </div>
  </section>
<?php endif; ?>
<?php if ($rows === []): ?>
  <div class="tc-card" style="text-align:center;padding:34px 22px;margin-bottom:16px">
    <div style="font:800 18px var(--tc-display);margin-bottom:6px">Nobody on the list yet</div>
    <p class="tc-card__hint" style="margin:0 auto;max-width:380px">
      Until somebody is added here, the phones can't call out and nobody can
      call in. Add the first grown-up with <b>+ Add a person</b>.
    </p>
  </div>
<?php endif; ?>

<div class="tc-grid tc-grid--contacts">
  <?php foreach ($rows as $c): ?>
    <?php $tag = $canEdit ? 'a' : 'div'; ?>
    <<?= $tag ?> class="tc-contact-card"<?= $canEdit ? ' href="' . e(url(['screen' => 'contacts', 'contact' => $c['id']])) . '"' : ' style="cursor:default"' ?>>
      <div class="tc-contact-card__head">
        <?php view('partials/avatar', [
            'photo' => $c['photo'], 'initial' => $c['initial'], 'color' => $c['color'],
            'size' => '52', 'alt' => $c['name'],
            'extra' => $c['sos'] ? '<span class="tc-avatar__sos">!</span>' : '',
        ]); ?>
        <div class="tc-grow">
          <div class="tc-contact-card__name"><?= e($c['name'] !== '' ? $c['name'] : 'New person') ?></div>
          <div class="tc-contact-card__rel"><?= e($c['rel']) ?></div>
        </div>
      </div>

      <div class="tc-contact-card__chips">
        <?php if ($c['hasCode']): ?>
          <span class="tc-chip tc-chip--sun">⌗ Dial <?= e($c['code']) ?></span>
        <?php endif; ?>
        <?php if (!empty($c['alwaysRing'])): ?>
          <?php /* Replaces the window chip rather than sitting beside it: the
                   card should state the rule that actually applies, and a card
                   reading "After school · Always through" says two things. The
                   window is still set in the editor. */ ?>
          <span class="tc-chip tc-chip--teal">Always through</span>
        <?php else: ?>
          <span class="tc-chip tc-chip--<?= e($c['winMod']) ?>"><?= e($c['winLabel']) ?></span>
        <?php endif; ?>
        <?php if ($c['ringboth']): ?>
          <span class="tc-chip tc-chip--lav">Rings both ↦ <?= e($c['failover'] !== '' ? $c['failover'] : 'backup') ?></span>
        <?php endif; ?>
        <?php if ($c['sos']): ?>
          <span class="tc-chip tc-chip--red">SOS — always</span>
        <?php endif; ?>
      </div>

      <div class="tc-divider tc-divider--fine"></div>

      <div class="tc-contact-card__foot">
        <span class="tc-tag <?= $c['allowIn'] ? 'tc-tag--in-on' : 'tc-tag--off' ?>">In <?= e($c['inText']) ?></span>
        <span class="tc-tag <?= $c['allowOut'] ? 'tc-tag--out-on' : 'tc-tag--off' ?>">Out <?= e($c['outText']) ?></span>
      </div>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>

