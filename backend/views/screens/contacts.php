<?php
/**
 * @var Store $store
 * @var ContactRepository $contacts
 */
$rows = array_map([ContactRepository::class, 'toView'], $contacts->all());
$rows = array_map([Presenter::class, 'contact'], $rows);
$canEdit = Auth::can('contacts');
?>
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
        <span class="tc-chip tc-chip--<?= e($c['winMod']) ?>"><?= e($c['winLabel']) ?></span>
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
