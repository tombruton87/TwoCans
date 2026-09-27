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
    ['field' => 'sos',      'title' => 'SOS contact',        'hint' => 'Emergency: skips bedtime and their own hours',  'mod' => 'sos'],
];

/*
 * "Always put through" is the stronger version of SOS, and the difference is
 * worth spelling out in the hint: an SOS contact skips bedtime and their own
 * hours, but a phone that is off for the night still doesn't ring. This one
 * rings it anyway — which is what makes it usable for Mum and Dad.
 *
 * A group gets none of these: it never calls in (each member's own entry
 * decides that), it has no backup to fail over to, and it must always be
 * callable — ContactRepository::save() pins the flags. Only its hours are set.
 */
if (empty($c['isGroup'])) {
    $toggles[] = [
        'field' => 'alwaysRing', 'title' => 'Always put through',
        'hint' => 'No restrictions — rings any hour, even a phone that is off for the night',
        'mod' => 'always',
    ];
} else {
    $toggles = [];
}
?>
<div class="tc-modal tc-modal--sheet" data-tc-modal="<?= e($closeUrl) ?>" data-tc-close="<?= e($closeUrl) ?>" role="dialog" aria-modal="true" aria-label="<?= !empty($c['isGroup']) ? 'Edit group' : 'Edit person' ?>">
  <div class="tc-modal__panel tc-modal__panel--sheet">

    <div class="tc-modal__head tc-modal__head--sticky">
      <div class="tc-modal__title"><?= !empty($c['isGroup']) ? 'Edit group' : 'Edit person' ?></div>
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

        <?php /* The group's own "press 1 to join". Uploaded through its own form
                 (by `form` id — HTML has no nested forms) so choosing a file
                 doesn't have to wait for, or be lost by, the Save button. */ ?>
        <div class="tc-members">
          <div class="tc-members__title">What they hear when they answer</div>
          <?php if ($c['groupPrompt'] !== ''): ?>
            <div class="tc-vm-row tc-vm-row--bare">
              <audio data-audio preload="none"
                     src="<?= e(url(['download' => 'group_prompt', 'id' => $c['id']])) ?>"></audio>
              <button class="tc-vm-play" type="button" data-play aria-label="Play this group's greeting">▶</button>
              <div class="tc-grow">
                <div class="tc-vm-row__name">This group's greeting</div>
                <div class="tc-call-row__meta"><?= e(fmt_duration($c['groupPromptSeconds'])) ?></div>
              </div>
              <button class="tc-link" type="submit" form="contact-group-prompt-remove">remove</button>
            </div>
          <?php endif; ?>
          <div class="tc-row tc-row--wrap">
            <label class="tc-btn tc-btn--ghost tc-audio-file">
              <span data-tc-filename>Record or choose a file</span>
              <input type="file" name="greeting" form="contact-group-prompt-form"
                     accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                     data-tc-audiofile required>
            </label>
            <button class="tc-btn tc-btn--teal" type="submit" form="contact-group-prompt-form">
              <?= $c['groupPrompt'] === '' ? 'Save greeting' : 'Replace greeting' ?>
            </button>
          </div>
          <p class="tc-editor-hint" style="margin:6px 0 0">
            Something like “The kids are calling <?= e($c['name'] !== '' ? $c['name'] : 'the grannies') ?> —
            press 1 to join, or 2 if you can't.” Up to 20 seconds.
            <?php if ($c['groupPrompt'] === ''): ?>
              Until then they hear the house one, set under <a class="tc-link" href="<?= e(url(['screen' => 'greetings'])) ?>">Greetings</a>.
            <?php endif; ?>
          </p>
        </div>
      <?php else: ?>
        <label class="tc-label tc-label--sm">Phone number
          <input class="tc-input tc-input--white" type="text" name="number" value="<?= e($c['number']) ?>" placeholder="+1 (415) 555-0148">
        </label>

        <?php /* Their name spoken, for phones with no screen. Own forms, like
                 the group greeting, so a clip never waits on Save. */ ?>
        <?php $firstName = $c['name'] !== '' ? $c['name'] : 'Nana'; ?>
        <div class="tc-members">
          <div class="tc-members__title">Saying who's calling</div>
          <?php if ($c['announce'] !== ''): ?>
            <div class="tc-vm-row tc-vm-row--bare">
              <audio data-audio preload="none"
                     src="<?= e(url(['download' => 'caller_name', 'id' => $c['id']])) ?>"></audio>
              <button class="tc-vm-play" type="button" data-play aria-label="Play their name">▶</button>
              <div class="tc-grow">
                <div class="tc-vm-row__name">Their name</div>
                <div class="tc-call-row__meta"><?= e(fmt_duration($c['announceSeconds'])) ?></div>
              </div>
              <button class="tc-link" type="submit" form="contact-announce-remove">remove</button>
            </div>
          <?php endif; ?>
          <div class="tc-row tc-row--wrap">
            <label class="tc-btn tc-btn--ghost tc-audio-file">
              <span data-tc-filename>Record or choose a file</span>
              <input type="file" name="clip" form="contact-announce-form"
                     accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                     data-tc-audiofile required>
            </label>
            <button class="tc-btn tc-btn--teal" type="submit" form="contact-announce-form">
              <?= $c['announce'] === '' ? 'Save' : 'Replace' ?>
            </button>
          </div>
          <?php if ((new HomeAssistant())->canSpeak()): ?>
            <div class="tc-row tc-row--wrap" style="margin-top:8px">
              <input class="tc-input tc-input--white tc-grow" type="text" name="text" form="contact-announce-voice"
                     maxlength="80" value="It's <?= e($firstName) ?>!" aria-label="What to say">
              <button class="tc-btn tc-btn--ghost" type="submit" form="contact-announce-voice">
                <i class="fa-solid fa-house-signal" aria-hidden="true"></i> Say it in Home Assistant's voice
              </button>
            </div>
          <?php endif; ?>
          <p class="tc-editor-hint" style="margin:6px 0 0">
            A phone with no screen plays this when it's picked up, before the call
            connects — “It's <?= e($firstName) ?>!” — so the kids know who it is.
            Up to 8 seconds; it's best in <?= e($firstName) ?>'s own voice.
          </p>
        </div>

        <?php /* A link they can open themselves to add their own photo and name. */ ?>
        <?php $selfLink = $c['id'] ? (new ContactLinkRepository())->forContact((int) $c['id']) : null; ?>
        <div class="tc-members">
          <div class="tc-members__title">Let <?= e($firstName) ?> do it</div>
          <?php if ($selfLink === null): ?>
            <p class="tc-editor-hint" style="margin:0 0 8px">
              Send <?= e($firstName) ?> a link to add their own photo and record their own name —
              from their phone, no account needed. It works for <?= (int) ContactLinkRepository::DAYS ?> days.
            </p>
            <button class="tc-btn tc-btn--ghost" type="submit" form="contact-link-create">
              <i class="fa-solid fa-link" aria-hidden="true"></i> Make a link
            </button>
          <?php else: ?>
            <?php $linkUrl = ContactLinkRepository::url($selfLink['token']); ?>
            <div class="tc-row" style="gap:8px">
              <input class="tc-input tc-input--white tc-grow" type="text" value="<?= e($linkUrl) ?>" readonly
                     aria-label="Link for <?= e($firstName) ?>" onfocus="this.select()">
              <button class="tc-btn tc-btn--ghost" type="button" data-tc-copy="<?= e($linkUrl) ?>" aria-label="Copy the link">Copy</button>
            </div>
            <?php
            // Sent from the parent's own phone, straight to theirs: WhatsApp or
            // a text, with the message written and their number filled in —
            // nothing to set up. With no number on file, the app asks who to.
            $message = 'Hi ' . $firstName . "! Add your photo and say your name for the kids' phones, so they know it's you when you ring: " . $linkUrl;
            $digits = ltrim($c['number'], '+');
            $whatsapp = 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
            // "?&body=" is the one form both iPhone and Android read.
            $sms = 'sms:' . $c['number'] . '?&body=' . rawurlencode($message);
            ?>
            <div class="tc-row tc-row--wrap" style="gap:8px;margin-top:8px">
              <a class="tc-btn tc-btn--whatsapp" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Send on WhatsApp
              </a>
              <a class="tc-btn tc-btn--ghost" href="<?= e($sms) ?>">
                <i class="fa-solid fa-comment-sms" aria-hidden="true"></i> Send as a text
              </a>
            </div>
            <p class="tc-editor-hint" style="margin:6px 0 0">
              <?= $selfLink['usedAt'] !== null
                  ? e($firstName) . ' used it on ' . e(date('j M, g:ia', strtotime($selfLink['usedAt']))) . '.'
                  : 'Not used yet.' ?>
              Works until <?= e(date('j M', strtotime($selfLink['expiresAt']))) ?>.
              <button class="tc-link" type="submit" form="contact-link-stop">Stop it</button>
            </p>
            <?php if (!ContactLinkRepository::isPublic()): ?>
              <p class="tc-editor-hint tc-editor-hint--warn" style="margin:6px 0 0">
                This address only works inside the house. Set up your outside address and
                certificate under <a class="tc-link" href="<?= e(url(['screen' => 'trunk'])) ?>">Phone line</a>, then make the link again.
              </p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <p class="tc-editor-hint">
        <?php /* Swapped by the script for a "press Save" note the moment a
                 photo is picked — see [data-tc-photo] in twocans.js. */ ?>
        <span data-tc-photo-note>Tap the circle to add a photo — kids recognise faces, not numbers.</span>
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
        <div style="font:800 14px var(--tc-display);margin-bottom:9px"><?= !empty($c['isGroup']) ? 'When can the kids call this group?' : 'When can they talk?' ?></div>
        <div class="tc-win-grid">
          <?php foreach (Presenter::WINDOWS as $key => $w): ?>
            <input class="tc-win-radio" type="radio" name="window" id="win-<?= e($key) ?>" value="<?= e($key) ?>"
                   <?= $c['window'] === $key ? 'checked' : '' ?>>
            <label class="tc-win-card tc-win-card--<?= e($w['mod']) ?>" for="win-<?= e($key) ?>">
              <span class="tc-win-card__label" style="display:block"><?= e($w['label']) ?></span>
              <span class="tc-win-card__sub" style="display:block"><?= e($key === 'custom' ? 'Pick days and times' : $w['sub']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php /* Shown only while Custom hours is picked — see .tc-win-custom. */ ?>
        <div class="tc-win-custom">
          <?php view('partials/schedule_editor', ['field' => 'schedule', 'rules' => $c['windowRules']]); ?>
        </div>
      </div>

      <?php if ($toggles !== []): ?>
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
      <?php endif; ?>

      <button class="tc-btn tc-btn--teal tc-btn--lg" type="submit">Save</button>
    </form>

    <?php if (!empty($c['isGroup'])): ?>
      <form id="contact-group-prompt-form" method="post" action="/" enctype="multipart/form-data" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_group_prompt">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
      <form id="contact-group-prompt-remove" method="post" action="/" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_group_prompt_remove">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
    <?php endif; ?>

    <?php if (empty($c['isGroup'])): ?>
      <form id="contact-announce-form" method="post" action="/" enctype="multipart/form-data" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_announce">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
      <form id="contact-announce-voice" method="post" action="/" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_announce_voice">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
      <form id="contact-link-create" method="post" action="/" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_link_create">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
      <form id="contact-link-stop" method="post" action="/" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_link_stop">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
      <form id="contact-announce-remove" method="post" action="/" hidden>
        <?= form_fields() ?>
        <input type="hidden" name="action" value="contact_announce_remove">
        <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
      </form>
    <?php endif; ?>

    <?php /* Separate form so "Remove photo" doesn't submit the whole editor. */ ?>
    <form id="contact-photo-remove" method="post" action="/" hidden>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="contact_photo_remove">
      <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
    </form>
  </div>
</div>
