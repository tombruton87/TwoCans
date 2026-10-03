<?php
/**
 * Sleeps till Christmas — its own page, from the settings menu. See Christmas.
 *
 * @var Store $store
 */
?>
<div class="tc-stack tc-stack--tight tc-xmas-page">
  <?php view('partials/christmas_card', ['canEdit' => Auth::can('rules')]); ?>
</div>
