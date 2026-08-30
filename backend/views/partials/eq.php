<?php
/**
 * Animated equalizer. 34 bars with staggered durations and delays, exactly as
 * the prototype generates them.
 *
 * @var string $variant 'vm' (inline in a voicemail card) or 'live' (listen-in)
 */
?>
<div class="tc-eq tc-eq--<?= e($variant) ?>" aria-hidden="true">
  <?php for ($i = 0; $i < 34; $i++): ?>
    <i style="animation-duration:<?= number_format(0.6 + ($i % 6) * 0.12, 2) ?>s;animation-delay:<?= number_format($i * 0.04, 2) ?>s"></i>
  <?php endfor; ?>
</div>
