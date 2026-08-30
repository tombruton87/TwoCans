<?php
/**
 * @var Store          $store
 * @var CallRepository $calls
 * @var array          $filters Current search/filter state
 * @var int            $page
 */
$total = $calls->countMatching($filters);
$perPage = CallRepository::PER_PAGE;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$rows = array_map([CallRepository::class, 'toView'], $calls->search($filters, $page, $perPage));
$rows = array_map([Presenter::class, 'call'], $rows);

$settings = new SettingsRepository();
$retentionDays = $settings->retentionDays();
$retentionLabel = $settings->retentionLabel();
// Anything past its date but not yet swept — the sweep runs hourly, so there is
// usually a small gap between "expired" and "gone".
$expiringSoon = array_sum((new Retention($settings))->pending());

$callers = $calls->callers();
$term = trim((string) ($filters['q'] ?? ''));
$filtering = $term !== '' || (int) ($filters['contact'] ?? 0) > 0 || ($filters['status'] ?? '') !== '';

/** Keep the current filters when linking to another page. */
$pageUrl = static fn(int $n): string => url([
    'screen' => 'calllog',
    'q' => $filters['q'] ?? '',
    // Cast 0 to '' so an unset person filter drops out of the URL entirely.
    'contact' => ((int) ($filters['contact'] ?? 0)) ?: '',
    'status' => $filters['status'] ?? '',
    'page' => $n > 1 ? (string) $n : '',
]);
?>
<div class="tc-stack tc-stack--tight tc-narrow--log">

  <form class="tc-filters" method="get" action="/">
    <input type="hidden" name="screen" value="calllog">

    <label class="tc-filters__search">
      <span class="tc-filters__icon" aria-hidden="true">⌕</span>
      <input type="search" name="q" value="<?= e($term) ?>"
             placeholder="Search names, numbers or what was said"
             aria-label="Search the call log">
    </label>

    <select class="tc-filters__select" name="contact" aria-label="Filter by person">
      <option value="">Everyone</option>
      <?php foreach ($callers as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) ($filters['contact'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
          <?= e($c['name']) ?> (<?= (int) $c['calls'] ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <select class="tc-filters__select" name="status" aria-label="Filter by outcome">
      <option value="">Any outcome</option>
      <?php foreach (['done' => 'Answered', 'missed' => 'Missed', 'blocked' => 'Blocked'] as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>

    <button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Search</button>
    <?php if ($filtering): ?>
      <a class="tc-link" href="<?= e(url(['screen' => 'calllog'])) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--lav">i</span>
    <?php if ($filtering): ?>
      <?= $total === 1 ? '1 call matches' : e((string) $total) . ' calls match' ?><?php if ($term !== ''): ?> “<?= e($term) ?>”<?php endif; ?>.
      Searching looks inside transcripts as well as names and numbers.
    <?php else: ?>
      Every call through your line is recorded and transcribed automatically, on
      your own server. The audio never leaves this machine.
    <?php endif; ?>
  </div>

  <?php /* Retention lives here rather than on a settings page of its own: this
           is the screen where a parent is looking at the recordings, and so the
           screen where "how long do we keep these?" is a natural question. */ ?>
  <div class="tc-retention">
    <span class="tc-retention__label">
      Keep recordings and transcripts for
    </span>

    <?php if (Auth::can('rules')): ?>
      <form method="post" action="/" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="retention_set">
        <select class="tc-filters__select" name="days" data-tc-autosave aria-label="How long to keep recordings">
          <?php foreach (SettingsRepository::RETENTION_CHOICES as $value => $label): ?>
            <option value="<?= e((string) $value) ?>" <?= $retentionDays === (int) $value ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Save</button></noscript>
      </form>
    <?php else: ?>
      <b><?= e($retentionLabel) ?></b>
    <?php endif; ?>

    <span class="tc-retention__note">
      <?php if ($retentionDays === 0): ?>
        Nothing is ever deleted. These are recordings of children's
        conversations — it's worth picking a window.
      <?php else: ?>
        The call stays in this log; only the audio and transcript go.
        <?php if ($expiringSoon > 0): ?>
          <?= (int) $expiringSoon ?> due to be cleared.
        <?php endif; ?>
      <?php endif; ?>
    </span>
  </div>

  <?php if ($rows === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">
        <?= $filtering ? 'Nothing matches' : 'No calls yet' ?>
      </div>
      <p class="tc-card__hint" style="margin:0 auto;max-width:360px">
        <?php if ($filtering): ?>
          Try a different word, or <a class="tc-link" href="<?= e(url(['screen' => 'calllog'])) ?>">clear the filters</a>.
        <?php else: ?>
          As soon as a phone on your line makes or receives a call it will appear
          here. Try the echo test on <b>600</b>, or the Test call button on a phone.
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $c): ?>
    <article class="tc-card tc-card--flat">
      <div class="tc-call-row">
        <?php if ($c['hasRecording']): ?>
          <?php /* Same control as the voicemail screen: circle plays, goes
                   solid teal while playing, equalizer appears below. */ ?>
          <audio data-audio preload="none" src="<?= e(url(['download' => 'recording', 'id' => $c['id']])) ?>"></audio>
          <button class="tc-vm-play" type="button" data-play
                  aria-label="Play the recording of this call with <?= e($c['name']) ?>">▶</button>
        <?php endif; ?>
        <div class="tc-avatar" style="background:<?= e($c['color']) ?>"><?= e($c['initial']) ?></div>
        <div class="tc-grow">
          <div class="tc-call-row__name"><?= highlight($c['name'], $term) ?></div>
          <div class="tc-call-row__meta">
            <?= highlight($c['number'], $term) ?> · <?= e($c['date']) ?> <?= e($c['time']) ?>
          </div>
        </div>
        <span class="tc-pill tc-pill--lg tc-pill--<?= e($c['statusMod']) ?>"><?= e($c['statusLabel']) ?></span>
        <?php if ($c['hasRecording']): ?>
          <a class="tc-btn--icon" style="display:flex;align-items:center;justify-content:center"
             href="<?= e(url(['download' => 'recording', 'id' => $c['id']])) ?>" download
             title="Save the recording">↓</a>
        <?php elseif ($c['showDownload']): ?>
          <a class="tc-btn--icon" style="display:flex;align-items:center;justify-content:center"
             href="<?= e(url(['download' => 'call', 'id' => $c['id']])) ?>"
             title="Download this record">↓</a>
        <?php endif; ?>
      </div>

      <?php if ($c['hasRecording']): ?>
        <div class="tc-eqstrip" data-eq title="Click to skip through the call">
          <?php view('partials/eq', ['variant' => 'vm']); ?>
        </div>
      <?php endif; ?>

      <?php if ($c['transcript'] !== ''): ?>
        <div class="tc-transcript"><?= highlight($c['transcript'], $term) ?></div>
      <?php else: ?>
        <div class="tc-transcript tc-transcript--empty">
          <?php if ($c['contentExpired']): ?>
            Recording and transcript deleted — this call is older than <?= e($retentionLabel) ?>.
          <?php elseif ($c['blockReason'] !== ''): ?>
            <?= e($c['blockReason']) ?> — the caller heard the blocked-call message.
          <?php elseif ($c['status'] === 'missed'): ?>
            Nobody answered<?= $c['disposition'] !== '' ? ' (' . e(strtolower($c['disposition'])) . ')' : '' ?>.
          <?php elseif ($c['transcriptStatus'] === 'pending' || $c['transcriptStatus'] === 'running'): ?>
            <span class="tc-transcribing">Listening to this call… the transcript will appear here shortly.</span>
          <?php elseif ($c['transcriptStatus'] === 'done'): ?>
            Nothing was said, or the call was too quiet to make out.
          <?php elseif ($c['transcriptStatus'] === 'failed'): ?>
            Couldn't transcribe this one<?= $c['transcriptError'] !== '' ? ' — ' . e($c['transcriptError']) : '' ?>.
          <?php else: ?>
            Nothing recorded for this call.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
    <nav class="tc-pager" aria-label="Call log pages">
      <?php if ($page > 1): ?>
        <a class="tc-pager__page tc-pager__page--step" href="<?= e($pageUrl($page - 1)) ?>"
           rel="prev" aria-label="Newer calls">‹</a>
      <?php else: ?>
        <span class="tc-pager__page tc-pager__page--step is-disabled" aria-hidden="true">‹</span>
      <?php endif; ?>

      <?php foreach (Presenter::pageNumbers($page, $pages) as $n): ?>
        <?php if ($n === null): ?>
          <span class="tc-pager__gap" aria-hidden="true">…</span>
        <?php elseif ($n === $page): ?>
          <span class="tc-pager__page is-current" aria-current="page"><?= (int) $n ?></span>
        <?php else: ?>
          <a class="tc-pager__page" href="<?= e($pageUrl($n)) ?>"
             aria-label="Page <?= (int) $n ?>"><?= (int) $n ?></a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($page < $pages): ?>
        <a class="tc-pager__page tc-pager__page--step" href="<?= e($pageUrl($page + 1)) ?>"
           rel="next" aria-label="Older calls">›</a>
      <?php else: ?>
        <span class="tc-pager__page tc-pager__page--step is-disabled" aria-hidden="true">›</span>
      <?php endif; ?>

      <span class="tc-pager__count">
        <?= (int) $total ?> call<?= $total === 1 ? '' : 's' ?>
      </span>
    </nav>
  <?php endif; ?>
</div>
