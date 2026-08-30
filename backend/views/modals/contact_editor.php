<?php
/**
 * Contact editor bottom sheet. Everything is one form saved with the Save
 * button, matching the design's sheet layout.
 *
 * @var array $contact Already run through Presenter::contact()
 */
$c = $contact;
$closeUrl = url(['screen' => 'contacts']);

$toggles = [
    ['field' => 'allowIn',  'title' => 'Can call in',        'hint' => 'They may ring the kids',                       'mod' => ''],
    ['field' => 'allowOut', 'title' => 'Can be called',      'hint' => 'The kids may dial them',                       'mod' => ''],
    ['field' => 'ringboth', 'title' => 'Ring both ↦ failover', 'hint' => 'If no answer, ring the backup grown-up',     'mod' => 'lav'],
    ['field' => 'sos',      'title' => 'SOS contact',        'hint' => 'Always rings through — even in bedtime mode',  'mod' => 'sos'],
];
?>
<div class="tc-modal tc-modal--sheet" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>" role="dialog" aria-modal="true" aria-label="Edit person">
  <div class="tc-modal__panel tc-modal__panel--sheet">

    <div class="tc-modal__head tc-modal__head--sticky">
      <div class="tc-modal__title">Edit person</div>
      <form method="post" action="/" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_delete">
        <input type="hidden" name="id" value="<?= e($c['id']) ?>">
        <button type="submit" style="border:none;background:transparent;color:var(--tc-coral-lip);font:800 13px var(--tc-display);cursor:pointer">Remove</button>
      </form>
      <a class="tc-modal__close" href="<?= e($closeUrl) ?>" aria-label="Close">×</a>
    </div>

    <?php /* Flips person/group and nothing else. Outside the main form because
             HTML has no nested forms; the switch reaches it by `form` id. */ ?>
    <form id="contact-group-form" method="post" action="/" hidden>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="contact_group_toggle">
      <input type="hidden" name="id" value="<?= e($c['id']) ?>">
    </form>

    <?php /* enctype: this form now carries a file. */ ?>
    <form class="tc-modal__body tc-modal__body--sheet" method="post" action="/" enctype="multipart/form-data">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="contact_save">
      <input type="hidden" name="id" value="<?= e($c['id']) ?>">

      <div class="tc-editor-head">
        <label class="tc-photo-pick" title="Choose a photo">
          <?php view('partials/avatar', [
              'photo' => $c['photo'], 'initial' => $c['initial'],
              'color' => $c['color'], 'size' => '64', 'alt' => '',
          ]); ?>
          <span class="tc-photo-pick__hint" aria-hidden="true">📷</span>
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-tc-photo>
        </label>
        <div class="tc-editor-head__fields">
          <input class="tc-input" type="text" name="name" value="<?= e($c['name']) ?>" placeholder="Name (e.g. Grandma)"
                 style="padding:11px 14px;border-radius:12px;font:800 16px var(--tc-display);background:var(--tc-card)" aria-label="Name">
          <input class="tc-input" type="text" name="rel" value="<?= e($c['rel']) ?>" placeholder="Relationship (e.g. Grandma)"
                 style="padding:9px 14px;border-radius:12px;font:600 13px var(--tc-body);color:#6E5C4D;background:var(--tc-card)" aria-label="Relationship">
        </div>
      </div>

      <?php
      /*
       * Three-way calling, from the child's side, is not a call feature — it is
       * a person. "Grandma & Grandad" lives in the contact list with its own
       * speed dial and is dialled exactly like Grandma. There is nothing for a
       * child to hold, merge or press.
       */
      $isGroup = !empty($c['isGroup']);
      $members = $isGroup ? (new ContactRepository())->memberIds((int) $c['id']) : [];
      $candidates = (new ContactRepository())->groupCandidates((int) $c['id']);
      ?>
      <?php /* Its own form, submitted through the `form` attribute: switching
               mode must not drag the rest of the sheet through validation. A
               group has no number field on screen, so running the full save
               here made it impossible to switch back off — the number it then
               demanded was one the form could not supply. */ ?>
      <label class="tc-group-toggle">
        <input class="tc-switch-input" type="checkbox" name="isGroup" value="1"
               <?= $isGroup ? 'checked' : '' ?> data-tc-autosave
               form="contact-group-form">
        <span class="tc-switch"></span>
        <span class="tc-grow">
          <span class="tc-group-toggle__title">Ring several people at once</span>
          <span class="tc-group-toggle__hint">
            One speed dial that puts everybody in the same conversation.
          </span>
        </span>
      </label>

      <?php if ($isGroup): ?>
        <div class="tc-members">
          <div class="tc-members__title">Who is in this group?</div>
          <?php if ($candidates === []): ?>
            <p class="tc-editor-hint" style="margin:0">
              Add a couple of people to the call list first, then come back and
              put them in a group.
            </p>
          <?php else: ?>
            <?php foreach ($candidates as $person): ?>
              <?php $p = Presenter::contact(ContactRepository::toView($person)); ?>
              <label class="tc-member">
                <input type="checkbox" name="members[]" value="<?= (int) $p['id'] ?>"
                       <?= in_array((int) $p['id'], $members, true) ? 'checked' : '' ?>>
                <?php view('partials/avatar', [
                    'photo' => $p['photo'], 'initial' => $p['initial'],
                    'color' => $p['color'], 'size' => '36', 'alt' => '',
                ]); ?>
                <span class="tc-grow">
                  <span class="tc-member__name"><?= e($p['name']) ?></span>
                  <span class="tc-member__num"><?= e($p['number']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
            <p class="tc-editor-hint" style="margin:6px 0 0">
              Only people already on the call list can be in a group, so a group
              can never reach somebody new. Everyone's phone rings at once and
              whoever picks up joins in — the others can still arrive late.
            </p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <label class="tc-label tc-label--sm">Phone number
          <input class="tc-input tc-input--white" type="text" name="number" value="<?= e($c['number']) ?>" placeholder="+1 (415) 555-0148">
        </label>
      <?php endif; ?>

      <p class="tc-editor-hint">
        Tap the circle to add a photo — kids recognise faces, not numbers.
        <?php if ($c['photo'] !== ''): ?>
          <button class="tc-link" type="submit" form="contact-photo-remove">Remove photo</button>
        <?php endif; ?>
      </p>

      <div class="tc-code-row">
        <div class="tc-code-row__icon">⌗</div>
        <div class="tc-grow">
          <div class="tc-code-row__title">Speed-dial code</div>
          <div class="tc-code-row__hint">Kids type this short number to call <?= e($c['name'] !== '' ? $c['name'] : 'them') ?></div>
        </div>
        <input class="tc-input tc-code-input" type="text" name="code" value="<?= e($c['code']) ?>"
               inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="123" aria-label="Speed-dial code">
      </div>

      <div>
        <div style="font:800 14px var(--tc-display);margin-bottom:9px">When can they talk?</div>
        <div class="tc-win-grid">
          <?php foreach (Presenter::WINDOWS as $key => $w): ?>
            <input class="tc-win-radio" type="radio" name="window" id="win-<?= e($key) ?>" value="<?= e($key) ?>"
                   <?= $c['window'] === $key ? 'checked' : '' ?>>
            <label class="tc-win-card tc-win-card--<?= e($w['mod']) ?>" for="win-<?= e($key) ?>">
              <span class="tc-win-card__label" style="display:block"><?= e($w['label']) ?></span>
              <span class="tc-win-card__sub" style="display:block"><?= e($w['sub']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($toggles as $t): ?>
          <div class="tc-toggle-row <?= $t['mod'] !== '' ? 'tc-toggle-row--' . $t['mod'] : '' ?>">
            <div class="tc-grow">
              <div class="tc-toggle-row__title"><?= e($t['title']) ?></div>
              <div class="tc-toggle-row__hint"><?= e($t['hint']) ?></div>
            </div>
            <input class="tc-switch-input" type="checkbox" id="tog-<?= e($t['field']) ?>" name="<?= e($t['field']) ?>"
                   <?= !empty($c[$t['field']]) ? 'checked' : '' ?>>
            <label class="tc-switch" for="tog-<?= e($t['field']) ?>" aria-label="<?= e($t['title']) ?>"></label>
          </div>
        <?php endforeach; ?>
      </div>

      <button class="tc-btn tc-btn--teal tc-btn--lg" type="submit">Save</button>
    </form>

    <?php /* Separate form so "Remove photo" doesn't submit the whole editor. */ ?>
    <form id="contact-photo-remove" method="post" action="/" hidden>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="contact_photo_remove">
      <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
    </form>
  </div>
</div>
