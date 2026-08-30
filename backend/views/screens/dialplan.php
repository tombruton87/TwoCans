<?php
/**
 * Dial-plan rules: which numbers the kids may dial beyond their contacts.
 *
 * @var Store                 $store
 * @var DialplanRuleRepository $rules
 */
$canEdit = Auth::can('rules');
$trunk = (new TrunkRepository())->get();
$rows = array_map([DialplanRuleRepository::class, 'toView'], $rules->all());
?>
<div class="tc-stack tc-narrow">

  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--sun">#</span>
    Contacts are <b>who</b> the kids can call. Rules are the <b>kinds of number</b>
    they can dial beyond that — like every UK mobile, or never a premium line.
  </div>

  <?php if (!$trunk['connected']): ?>
    <div class="tc-card" style="border-left:4px solid var(--tc-coral)">
      <div style="font:800 14px var(--tc-display);margin-bottom:4px">No phone line connected yet</div>
      <p class="tc-card__hint" style="margin:0">
        Allow rules will only actually ring out once a line is connected on the
        <a href="<?= e(url(['screen' => 'trunk'])) ?>" style="color:var(--tc-teal-deep)">Phone line</a> screen.
      </p>
    </div>
  <?php endif; ?>

  <?php if ($canEdit): ?>
    <form class="tc-card" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="dialplan_rule_add">

      <div style="font:800 14px var(--tc-display);margin-bottom:4px">Add a rule</div>
      <p class="tc-card__hint" style="margin:0 0 12px">
        A rule matches the first digits a child dials. <b>07</b> is any UK mobile,
        <b>01</b>/<b>02</b> are landlines, <b>09</b> is premium. Longest match wins,
        so a narrow block beats a broad allow.
      </p>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input class="tc-input" type="text" name="prefix" placeholder="Prefix, e.g. 07"
               inputmode="numeric" pattern="[0-9]*" maxlength="20" required
               style="max-width:150px">
        <input class="tc-input" type="text" name="label" placeholder="What is it? e.g. UK mobiles"
               maxlength="60" style="flex:1;min-width:180px">
        <select class="tc-input" name="rule_action" style="max-width:110px">
          <option value="allow">Allow</option>
          <option value="block">Block</option>
        </select>
        <button class="tc-btn tc-btn--teal" type="submit">Add</button>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($rows === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">No rules yet</div>
      <p class="tc-card__hint" style="margin:0 auto;max-width:420px">
        Without rules the kids can still call their contacts and emergency numbers —
        everything else is blocked. Add a rule to open up a whole kind of number.
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <div class="tc-card">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap">
            <span style="font:800 18px var(--tc-display)"><?= e($r['prefix']) ?></span>
            <?php if (!$canEdit && $r['label'] !== ''): ?>
              <span class="tc-micro"><?= e($r['label']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($canEdit): ?>
            <form method="post" action="/" class="tc-inline-form" style="margin-top:6px">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="dialplan_rule_label">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input class="tc-input" type="text" name="label" value="<?= e($r['label']) ?>"
                     data-tc-autosave placeholder="Name this rule"
                     aria-label="Rule name">
            </form>
          <?php endif; ?>
        </div>

        <span style="font:800 11px var(--tc-body);padding:5px 10px;border-radius:999px;
          <?= $r['action'] === 'allow'
              ? 'background:var(--tc-teal-bg);color:var(--tc-teal-deep);'
              : 'background:var(--tc-coral-bg);color:var(--tc-coral);' ?>">
          <?= $r['action'] === 'allow' ? 'Allowed' : 'Blocked' ?>
        </span>

        <?php if ($canEdit): ?>
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="dialplan_rule_toggle">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit"
                    title="<?= $r['action'] === 'allow' ? 'Make this a block rule' : 'Make this an allow rule' ?>">
              <?= $r['action'] === 'allow' ? 'Block instead' : 'Allow instead' ?>
            </button>
          </form>

          <form method="post" action="/" class="tc-inline-form"
                onsubmit="return confirm('Delete this rule?')">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="dialplan_rule_delete">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="tc-btn--icon tc-btn--icon-danger" type="submit" title="Delete">×</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
