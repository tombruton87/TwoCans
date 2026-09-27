<?php
/**
 * Pick an icon: any of Font Awesome Free's, or an emoji. The choice lands in
 * a hidden field as "fa-solid fa-utensils" or the emoji itself — see
 * icon_html() and AnnouncementRepository::icon().
 *
 * The icon list (assets/vendor/fontawesome/icons.json) is fetched the first
 * time a picker opens, so pages that never open one don't pay for it.
 *
 * @var string $field  form field name
 * @var string $value  the current icon
 */
$emoji = ['📣', '🍝', '🍽️', '🍕', '🥪', '🍪', '🛁', '🪥', '🛏️', '🌙', '⏰', '🚗', '🎒', '📚', '🎮', '📺', '🐶', '🧹', '👟', '🧥', '☔', '🎉', '❤️', '👋'];
$id = 'icon-' . bin2hex(random_bytes(4));
?>
<div class="tc-iconpick" data-tc-iconpick>
  <input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>" data-tc-iconpick-value>
  <button class="tc-iconpick__current" type="button" data-tc-iconpick-toggle
          aria-expanded="false" aria-controls="<?= e($id) ?>" title="Choose an icon">
    <span class="tc-iconpick__shown" data-tc-iconpick-shown><?= icon_html($value) ?></span>
    <span class="tc-iconpick__caret" aria-hidden="true">▾</span>
  </button>

  <div class="tc-iconpick__panel" id="<?= e($id) ?>" data-tc-iconpick-panel hidden>
    <div class="tc-iconpick__bar">
      <input class="tc-input tc-iconpick__search" type="search" placeholder="Search 2,000 icons — dinner, bed, car…"
             aria-label="Search icons" data-tc-iconpick-search>
      <select class="tc-input tc-iconpick__cat" aria-label="Category" data-tc-iconpick-cat>
        <option value="">All categories</option>
      </select>
    </div>
    <div class="tc-iconpick__emoji" role="group" aria-label="Emoji">
      <?php foreach ($emoji as $em): ?>
        <button type="button" class="tc-iconpick__opt" data-tc-icon="<?= e($em) ?>" title="<?= e($em) ?>"><?= e($em) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="tc-iconpick__grid" role="listbox" aria-label="Icons" data-tc-iconpick-grid>
      <span class="tc-micro">Loading icons…</span>
    </div>
    <div class="tc-iconpick__foot">
      <span class="tc-micro" data-tc-iconpick-count></span>
      <span class="tc-micro">Icons by <a href="https://fontawesome.com" target="_blank" rel="noopener">Font Awesome</a> (CC BY 4.0)</span>
    </div>
  </div>
</div>
