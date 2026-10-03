<?php
/**
 * The games line: its numbers, what each game asks, and how everyone's doing.
 * See Games, and Quiz for the scores.
 *
 * @var Store $store
 */
$quiz = new Quiz();
// Games still being played, or since the last minute, are brought in now.
$quiz->import();

$settings = new SettingsRepository();
$gamesNumber = $settings->gamesNumber();
$number = $settings->quizNumber();
$tables = $settings->quizTables();
$questions = $settings->quizQuestions();
$sumsMax = $settings->sumsMax();
$bondsTo = $settings->bondsTo();
$canEdit = Auth::can('rules');
$summary = $quiz->summary();
$recent = $quiz->recent(15);
$pct = static fn(int $right, int $asked): int => $asked > 0 ? (int) round(100 * $right / $asked) : 0;
?>
<div class="tc-split">
  <aside class="tc-split__side tc-stack tc-stack--tight">
    <div class="tc-info-banner">
      <span class="tc-info-banner__icon tc-info-banner__icon--sun"><i class="fa-solid fa-dice" aria-hidden="true"></i></span>
      <span>
        Dial <b><?= e($gamesNumber) ?></b> (G-A-M-E) from any phone for the games, then press:
        <?php $lastGame = array_key_last(Games::GAMES); ?>
        <?php foreach (Games::GAMES as $key => $g): ?>
          <b><?= e($g['key']) ?></b> <?= e(strtolower($g['label'])) ?><?= $key === $lastGame ? '.' : ',' ?>
        <?php endforeach; ?>
        Or <b><?= e($number) ?></b> goes straight to times tables. Answers are typed on
        the keypad, then <b>#</b>.
      </span>
    </div>

    <?php if ($canEdit): ?>
      <section class="tc-card" data-tc-ajax-region id="quiz-settings">
        <h2 class="tc-card__title">The games</h2>
        <form method="post" action="/" class="tc-stack tc-stack--tight" data-tc-ajax>
          <?= form_fields() ?>
          <input type="hidden" name="action" value="quiz_settings">

          <div class="tc-row tc-row--wrap">
            <label class="tc-label tc-label--sm">The games line
              <input class="tc-input tc-code-input" type="text" name="games_number" value="<?= e($gamesNumber) ?>"
                     inputmode="numeric" pattern="[0-9]*" maxlength="4" style="max-width:120px">
            </label>
            <label class="tc-label tc-label--sm">Straight to times tables
              <input class="tc-input tc-code-input" type="text" name="number" value="<?= e($number) ?>"
                     inputmode="numeric" pattern="[0-9]*" maxlength="4" style="max-width:120px">
            </label>
          </div>

          <fieldset class="tc-quiz-tables">
            <legend class="tc-label tc-label--sm">Times tables in a mix</legend>
            <div class="tc-quiz-tables__grid">
              <?php foreach (range(2, 12) as $t): ?>
                <label class="tc-quiz-table">
                  <input type="checkbox" name="tables[]" value="<?= $t ?>"<?= in_array($t, $tables, true) ? ' checked' : '' ?>>
                  <span><?= $t ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="tc-card__hint">The ones your child is learning. They can still pick any table themselves.</p>
          </fieldset>

          <div class="tc-row tc-row--wrap">
            <label class="tc-label tc-label--sm">Sums go up to
              <select class="tc-input" name="sums_max" style="max-width:120px">
                <?php foreach (SettingsRepository::SUMS_LIMITS as $n): ?>
                  <option value="<?= $n ?>"<?= $n === $sumsMax ? ' selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="tc-label tc-label--sm">Questions a game
              <select class="tc-input" name="questions" style="max-width:160px">
                <?php foreach (SettingsRepository::QUIZ_LENGTHS as $n): ?>
                  <option value="<?= $n ?>"<?= $n === $questions ? ' selected' : '' ?>><?= $n ?> questions</option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <fieldset class="tc-quiz-tables">
            <legend class="tc-label tc-label--sm">Number bonds make</legend>
            <div class="tc-quiz-tables__grid">
              <?php foreach ([10, 20] as $t): ?>
                <label class="tc-quiz-table">
                  <input type="checkbox" name="bonds_to[]" value="<?= $t ?>"<?= in_array($t, $bondsTo, true) ? ' checked' : '' ?>>
                  <span><?= $t ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="tc-card__hint">“What goes with 7 to make 10?” Both, and it's a mix.</p>
          </fieldset>

          <div><button class="tc-btn tc-btn--teal" type="submit">Save</button></div>
        </form>
      </section>
    <?php endif; ?>

    <details class="tc-card tc-riddles">
      <summary class="tc-card__title">The animal riddles</summary>
      <p class="tc-card__hint"><?= Games::RIDDLES_A_GAME ?> a game, taking turns through all <?= count(Games::RIDDLES) ?>.</p>
      <ol class="tc-riddles__list">
        <?php foreach (Games::RIDDLES as $r): ?>
          <li><?= e($r['text']) ?> <b class="tc-riddles__answer">(<?= (int) $r['answer'] ?>)</b></li>
        <?php endforeach; ?>
      </ol>
    </details>
  </aside>

  <div class="tc-stack tc-stack--tight">
    <?php if ($summary === []): ?>
      <div class="tc-card" style="text-align:center;padding:34px 22px">
        <div style="font:800 18px var(--tc-display);margin-bottom:6px">No games yet</div>
        <p class="tc-card__hint" style="margin:0 auto;max-width:400px">
          When somebody dials <?= e($gamesNumber) ?> and plays, how they got on shows up here.
        </p>
      </div>
    <?php else: ?>
      <section class="tc-card">
        <h2 class="tc-card__title">The last 30 days</h2>
        <div class="tc-quiz-phones">
          <?php foreach ($summary as $s): ?>
            <div class="tc-quiz-phone">
              <div class="tc-quiz-phone__head">
                <b><?= e($s['phone']) ?></b>
                <span class="tc-card__hint"><?= (int) $s['games'] ?> <?= $s['games'] === 1 ? 'game' : 'games' ?></span>
              </div>
              <div class="tc-quiz-bars">
                <?php foreach ($s['bars'] as $b): ?>
                  <?php $p = $pct($b['right'], $b['asked']); ?>
                  <div class="tc-quiz-bar" title="<?= e($b['title']) ?>: <?= (int) $b['right'] ?> of <?= (int) $b['asked'] ?> right">
                    <span class="tc-quiz-bar__label"><?= e($b['label']) ?></span>
                    <span class="tc-quiz-bar__track"><span class="tc-quiz-bar__fill" style="width:<?= $p ?>%"></span></span>
                    <span class="tc-quiz-bar__pct"><?= $p ?>%</span>
                  </div>
                <?php endforeach; ?>
                <?php if ($s['goes'] !== null): ?>
                  <div class="tc-card__hint">Guess my number: <?= (int) $s['guesses'] ?> guessed, about <?= e((string) $s['goes']) ?> goes each</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="tc-card">
        <h2 class="tc-card__title">Latest games</h2>
        <ul class="tc-quiz-games">
          <?php foreach ($recent as $g): ?>
            <li>
              <span class="tc-quiz-games__score<?= $g['perfect'] ? ' is-perfect' : '' ?>"><?= e($g['result']) ?></span>
              <span class="tc-grow"><b><?= e($g['phone']) ?></b> · <?= e($g['about']) ?></span>
              <span class="tc-card__hint"><?= e($g['when']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>
</div>
