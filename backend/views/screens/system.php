<?php
/**
 * System health and backups.
 *
 * @var Store $store
 */
$health = SystemHealth::summary();
$backup = new Backup();
$backups = $backup->list();
$canBackup = Auth::can('backups');
?>
<div class="tc-stack tc-narrow">

  <?php
  // Is there a newer twocans? See UpdateCheck; asked by bin/minute.php.
  $updates = new UpdateCheck();
  $latest = $updates->latest();
  ?>
  <section class="tc-card tc-update<?= $updates->isNewer() ? ' is-new' : '' ?>" id="updates" data-tc-ajax-region>
    <div class="tc-card__head">
      <h2 class="tc-card__title">twocans <?= e(UpdateCheck::current()) ?></h2>
      <?php if ($updates->isNewer()): ?>
        <span class="tc-pill tc-pill--coral">Update available</span>
      <?php elseif ($updates->enabled() && $latest['version'] !== ''): ?>
        <span class="tc-pill tc-pill--ok">Up to date</span>
      <?php endif; ?>
    </div>
    <?php if ($updates->isNewer()): ?>
      <p class="tc-card__hint">
        <b>twocans <?= e($latest['version']) ?> is out.</b> To update, on the machine twocans runs on,
        in its folder: <code>./twocans update</code> — your settings, phones and recordings are kept.
        <?php if ($latest['url'] !== ''): ?>
          <a class="tc-link" href="<?= e($latest['url']) ?>" target="_blank" rel="noopener">What's new</a>
        <?php endif; ?>
      </p>
    <?php elseif (!$updates->enabled()): ?>
      <p class="tc-card__hint">Not checking for new versions. See what's out at
        <a class="tc-link" href="https://github.com/<?= e(UpdateCheck::REPO) ?>/releases" target="_blank" rel="noopener">GitHub</a>.</p>
    <?php elseif ($latest['version'] === ''): ?>
      <p class="tc-card__hint">Not checked for new versions yet — it will within a minute or so.</p>
    <?php endif; ?>
    <form method="post" action="/" class="tc-row tc-row--wrap" data-tc-ajax style="gap:10px;align-items:center">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="update_check">
      <input type="hidden" name="on" value="<?= $updates->enabled() ? '0' : '1' ?>">
      <span class="tc-micro">
        <?php if ($updates->enabled()): ?>
          Checks GitHub twice a day<?= $latest['checkedAt'] > 0 ? ' · last ' . e(date('j M, H:i', $latest['checkedAt'])) : '' ?>.
        <?php else: ?>
          Checking is off.
        <?php endif; ?>
      </span>
      <button class="tc-link" type="submit"><?= $updates->enabled() ? 'Stop checking' : 'Check for new versions' ?></button>
    </form>
  </section>

  <section class="tc-card">
    <div class="tc-card__head">
      <h2 class="tc-card__title">System health</h2>
      <form method="post" action="/" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="health_check">
        <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Re-run checks</button>
      </form>
    </div>

    <?php if ($health['failed'] > 0): ?>
      <div class="tc-info-banner" style="border-left-color:var(--tc-coral)">
        <b><?= (int) $health['failed'] ?></b> of <?= count($health['checks']) ?> checks need a look.
      </div>
    <?php endif; ?>

    <div style="display:flex;flex-direction:column">
      <?php foreach ($health['checks'] as $check): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--tc-border)">
          <span style="flex:none;width:10px;height:10px;border-radius:999px;background:<?= $check['ok'] ? 'var(--tc-teal)' : 'var(--tc-coral)' ?>"></span>
          <div style="flex:1;min-width:0">
            <div style="font:800 13px var(--tc-display)"><?= e($check['label']) ?></div>
            <div class="tc-card__hint" style="margin:0"><?= e($check['detail']) ?></div>
          </div>
          <span style="font:800 11px var(--tc-body);padding:4px 10px;border-radius:999px;white-space:nowrap;
            <?= $check['ok']
                ? 'background:var(--tc-teal-bg);color:var(--tc-teal-deep);'
                : 'background:var(--tc-coral-bg);color:var(--tc-coral-ink);' ?>">
            <?= $check['ok'] ? 'OK' : 'Check' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tc-card">
    <div class="tc-card__head">
      <h2 class="tc-card__title">Backups</h2>
      <?php if ($canBackup): ?>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="backup_create">
          <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Create backup</button>
        </form>
      <?php endif; ?>
    </div>

    <p class="tc-card__hint">
      A backup is the database plus every recording, voicemail, photo and joke.
      Restoring one is done from the command line on the box itself.
    </p>

    <?php if ($backups === []): ?>
      <div class="tc-empty">No backups yet.</div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($backups as $b): ?>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:0">
              <div style="font:800 13px var(--tc-display)"><?= e($b['name']) ?></div>
              <div class="tc-card__hint" style="margin:0"><?= e($b['when']) ?> · <?= e(round($b['size'] / 1048576, 1) . ' MB') ?></div>
            </div>
            <a class="tc-btn tc-btn--ghost tc-btn--sm" href="<?= e(url(['download' => 'backup', 'file' => $b['name']])) ?>">Download</a>
            <?php if ($canBackup): ?>
              <form method="post" action="/" class="tc-inline-form" onsubmit="return confirm('Delete this backup?')">
                <?= form_fields() ?>
                <input type="hidden" name="action" value="backup_delete">
                <input type="hidden" name="name" value="<?= e($b['name']) ?>">
                <button class="tc-btn--icon tc-btn--icon-danger" type="submit" title="Delete">×</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($canBackup): ?>
    <section class="tc-card" style="border-left:4px solid var(--tc-coral)">
      <div class="tc-card__head">
        <h2 class="tc-card__title">Restore a backup</h2>
      </div>
      <p class="tc-card__hint" style="color:var(--tc-coral-ink)">
        This replaces the current database, recordings, voicemails, photos and
        jokes with the ones inside the backup. It cannot be undone — a safety
        dump of the current database is written first.
      </p>
      <form method="post" action="/" enctype="multipart/form-data" data-tc-ajax data-tc-ajax-go
            data-tc-preparing="Uploaded — restoring the backup. This can take a few minutes, so stay on this page…">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="backup_restore">
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px">
          <input class="tc-input" type="file" name="backup" accept=".tgz,application/gzip" aria-label="The backup file to restore" required>
          <input class="tc-input" type="text" name="confirm" placeholder="Type RESTORE to confirm"
                 autocomplete="off" required style="max-width:260px">
          <button class="tc-btn tc-btn--coral" type="submit">Restore from this file</button>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <?php $guide = new Onboarding(); ?>
  <?php if (Auth::can('devices') && !$guide->visible()): ?>
    <section class="tc-card">
      <div class="tc-row" style="justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <div>
          <h2 class="tc-card__title">Getting started</h2>
          <p class="tc-card__hint" style="margin:0">
            <?= $guide->complete() ? 'Finished' : 'Hidden' ?> — the steps from a fresh install to a line the family can use.
          </p>
        </div>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="onboarding">
          <input type="hidden" name="do" value="show">
          <button class="tc-btn tc-btn--ghost" type="submit">Show it again</button>
        </form>
      </div>
    </section>
  <?php endif; ?>

</div>
