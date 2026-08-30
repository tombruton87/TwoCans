<?php
/**
 * The joke line.
 *
 * @var Store          $store
 * @var JokeRepository $jokes
 * @var int            $page
 */
$settings = new SettingsRepository();
$jokeNumber = $settings->jokeNumber();

$total = $jokes->count();
$pages = max(1, (int) ceil($total / JokeRepository::PER_PAGE));
$page = min(max(1, $page ?? 1), $pages);

$rows = array_map([JokeRepository::class, 'toView'], $jokes->page($page));
$canEdit = Auth::can('rules');
// Counted across the whole library, not just this page.
$live = $jokes->count(true);

$pageUrl = static fn(int $n): string => url([
    'screen' => 'jokes',
    'page' => $n > 1 ? (string) $n : '',
]);
?>
<div class="tc-stack tc-stack--tight tc-narrow--vm">

  <div class="tc-info-banner">
    <span class="tc-info-banner__icon tc-info-banner__icon--sun">☺</span>
    Dial <b><?= e($jokeNumber) ?></b> from any phone on the line to
    hear one, picked at random. Never the same joke twice in a row.
    <?php if ($live > 0): ?>
      <b><?= $live ?></b> on the line right now.
    <?php endif; ?>
  </div>

  <?php /* Same idea as retention living on the call log: the setting sits on
           the screen it governs, rather than in a settings page of its own. */ ?>
  <div class="tc-retention">
    <span class="tc-retention__label">Dial</span>

    <?php if ($canEdit): ?>
      <form method="post" action="/" class="tc-inline-form">
        <?= form_fields() ?>
        <input type="hidden" name="action" value="joke_number">
        <input class="tc-input tc-code-input" type="text" name="number"
               value="<?= e($jokeNumber) ?>" data-tc-autosave
               inputmode="numeric" pattern="[0-9]*" maxlength="4"
               aria-label="The number to dial for a joke">
        <noscript><button class="tc-btn tc-btn--teal tc-btn--sm" type="submit">Save</button></noscript>
      </form>
    <?php else: ?>
      <b><?= e($jokeNumber) ?></b>
    <?php endif; ?>

    <span class="tc-retention__note">
      Change it to whatever is easiest for your child to remember. It can't be an
      emergency number, a handset's extension, or a speed dial you've already
      given somebody.
    </span>
  </div>

  <?php if ($canEdit): ?>
    <form class="tc-card tc-joke-add" method="post" action="/" enctype="multipart/form-data">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="joke_add">

      <div class="tc-joke-add__body">
        <div class="tc-joke-add__title">Add a joke</div>
        <p class="tc-card__hint" style="margin:0">
          Record one on a phone, or drop in a clip you already have. Almost any
          audio file works — we convert it to something the handsets can play.
        </p>
      </div>

      <label class="tc-btn tc-btn--ghost tc-joke-file">
        <span data-tc-filename>Choose a file</span>
        <input type="file" name="audio"
               accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
               data-tc-jokefile required>
      </label>

      <button class="tc-btn tc-btn--teal" type="submit">Add</button>
    </form>
  <?php endif; ?>

  <?php if ($rows === []): ?>
    <div class="tc-card" style="text-align:center;padding:34px 22px">
      <div style="font:800 18px var(--tc-display);margin-bottom:6px">No jokes yet</div>
      <p class="tc-card__hint" style="margin:0 auto;max-width:400px">
        Dialling <?= e($jokeNumber) ?> will politely say there's
        nothing here. Add a clip above and it goes on the line straight away.
      </p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $j): ?>
    <article class="tc-card tc-card--flat <?= $j['enabled'] ? '' : 'is-off' ?>">
      <div class="tc-vm-row">
        <?php /* Same player as voicemail and the call log, so audio behaves
                 the same wherever it turns up. */ ?>
        <audio data-audio preload="none" src="<?= e(url(['download' => 'joke_audio', 'id' => $j['id']])) ?>"></audio>
        <button class="tc-vm-play" type="button" data-play aria-label="Play this joke">▶</button>

        <div class="tc-grow">
          <div class="tc-vm-row__name">
            <?php if ($j['transcript'] !== ''): ?>
              <?= e($j['transcript']) ?>
            <?php elseif ($j['transcriptStatus'] === 'pending' || $j['transcriptStatus'] === 'running'): ?>
              <span class="tc-transcribing">Listening to this one…</span>
            <?php else: ?>
              <span class="tc-muted">No transcript — play it to hear what it is</span>
            <?php endif; ?>
          </div>
          <div class="tc-call-row__meta">
            <?= e($j['duration']) ?> · added <?= e($j['addedOn']) ?>
            <?php if (!$j['enabled']): ?> · <b>off the line</b><?php endif; ?>
          </div>
        </div>

        <?php if ($canEdit): ?>
          <form method="post" action="/" class="tc-inline-form">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="joke_toggle">
            <input type="hidden" name="id" value="<?= e((string) $j['id']) ?>">
            <button class="tc-switch tc-switch--sm <?= $j['enabled'] ? 'is-on' : '' ?>" type="submit"
                    role="switch" aria-checked="<?= $j['enabled'] ? 'true' : 'false' ?>"
                    aria-label="<?= $j['enabled'] ? 'Take this joke off the line' : 'Put this joke back on the line' ?>"
                    title="<?= $j['enabled'] ? 'On the line' : 'Off the line' ?>"></button>
          </form>

          <form method="post" action="/" class="tc-inline-form"
                onsubmit="return confirm('Delete this joke for good?')">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="joke_delete">
            <input type="hidden" name="id" value="<?= e((string) $j['id']) ?>">
            <button class="tc-btn--icon tc-btn--icon-danger" type="submit" title="Delete">×</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="tc-eqstrip" data-eq title="Click to skip through">
        <?php view('partials/eq', ['variant' => 'vm']); ?>
      </div>

      <?php if ($canEdit): ?>
        <?php /* Whisper mangles puns — homophones are the whole point of a
                 joke — so the transcript is a first draft a parent can fix. */ ?>
        <form class="tc-joke-edit" method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="joke_transcript">
          <input type="hidden" name="id" value="<?= e((string) $j['id']) ?>">
          <input class="tc-input tc-input--white" type="text" name="transcript"
                 value="<?= e($j['transcript']) ?>" data-tc-autosave
                 placeholder="What's the joke? (tap to correct)"
                 aria-label="Joke transcript">
        </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
    <nav class="tc-pager" aria-label="Joke pages">
      <?php if ($page > 1): ?>
        <a class="tc-pager__page tc-pager__page--step" href="<?= e($pageUrl($page - 1)) ?>"
           rel="prev" aria-label="Newer jokes">&lsaquo;</a>
      <?php else: ?>
        <span class="tc-pager__page tc-pager__page--step is-disabled" aria-hidden="true">&lsaquo;</span>
      <?php endif; ?>

      <?php foreach (Presenter::pageNumbers($page, $pages) as $n): ?>
        <?php if ($n === null): ?>
          <span class="tc-pager__gap" aria-hidden="true">&hellip;</span>
        <?php elseif ($n === $page): ?>
          <span class="tc-pager__page is-current" aria-current="page"><?= (int) $n ?></span>
        <?php else: ?>
          <a class="tc-pager__page" href="<?= e($pageUrl($n)) ?>"
             aria-label="Page <?= (int) $n ?>"><?= (int) $n ?></a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($page < $pages): ?>
        <a class="tc-pager__page tc-pager__page--step" href="<?= e($pageUrl($page + 1)) ?>"
           rel="next" aria-label="Older jokes">&rsaquo;</a>
      <?php else: ?>
        <span class="tc-pager__page tc-pager__page--step is-disabled" aria-hidden="true">&rsaquo;</span>
      <?php endif; ?>

      <span class="tc-pager__count">
        <?= (int) $total ?> joke<?= $total === 1 ? '' : 's' ?>
      </span>
    </nav>
  <?php endif; ?>
</div>
