<?php
/**
 * The two-cans mark. On load — and on click — the cans draw together (string
 * goes slack and sags) then snap apart so the string pulls taut, with a slight
 * tilt on each can. Driven by SMIL so it needs no JS to play the first time.
 *
 * @var string $variant 'login' (large, with can shadows) or 'sidebar' (small)
 */
$isLogin = ($variant ?? 'sidebar') === 'login';
$w = $isLogin ? 168 : 58;
$h = $isLogin ? 84 : 29;
$stroke = $isLogin ? 3 : 4;
$dash = $isLogin ? '1.5 7' : '1.5 9';

// Shared spring timing for the string and both cans.
$timing = 'dur="1.7s" begin="0s" fill="freeze" calcMode="spline" keyTimes="0;0.4;0.62;1"'
        . ' keySplines="0.45 0 0.55 1;0.16 0.9 0.3 1;0.4 0 0.6 1"';

/** Three corrugation ribs: a dark line paired with a light one. */
$ribs = static function (int $x): string {
    $out = '';
    foreach ([22, 30, 38] as $y) {
        $out .= '<rect x="' . $x . '" y="' . $y . '" width="28" height="1.3" fill="#000000" opacity="0.09"></rect>'
              . '<rect x="' . $x . '" y="' . ($y + 1.3) . '" width="28" height="0.8" fill="#FFFFFF" opacity="0.18"></rect>';
    }

    return $out;
};
?>
<span class="tc-logo <?= $isLogin ? 'tc-logo--login' : '' ?>" data-tc-logo title="Give the string a tug!" role="img" aria-label="twocans">
  <svg width="<?= $w ?>" height="<?= $h ?>" viewBox="0 0 120 60" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path stroke="#CDB89B" stroke-width="<?= $stroke ?>" stroke-linecap="round" stroke-dasharray="<?= $dash ?>" fill="none" d="M36 30 Q60 30 84 30">
      <animate attributeName="d" <?= $timing ?> values="M36 30 Q60 30 84 30;M36 31 Q60 47 84 31;M36 30 Q60 30 84 30;M36 30 Q60 30 84 30"/>
    </path>

    <g transform="rotate(-8 22 31)">
      <g>
        <animateTransform attributeName="transform" type="translate" additive="sum" <?= $timing ?> values="0 0;13 2;-3 0;0 0"/>
        <animateTransform attributeName="transform" type="rotate" additive="sum" <?= $timing ?> values="0 22 31;8 22 31;-3 22 31;0 22 31"/>
        <rect x="8" y="14" width="28" height="34" rx="8" fill="#FF7A59"></rect>
        <?= $ribs(8) ?>
        <?php if ($isLogin): ?><rect x="8" y="38" width="28" height="10" rx="5" fill="#000000" opacity="0.06"></rect><?php endif; ?>
        <ellipse cx="22" cy="14" rx="14" ry="4.6" fill="#FFA98F"></ellipse>
      </g>
    </g>

    <g transform="rotate(8 98 31)">
      <g>
        <animateTransform attributeName="transform" type="translate" additive="sum" <?= $timing ?> values="0 0;-13 2;3 0;0 0"/>
        <animateTransform attributeName="transform" type="rotate" additive="sum" <?= $timing ?> values="0 98 31;-8 98 31;3 98 31;0 98 31"/>
        <rect x="84" y="14" width="28" height="34" rx="8" fill="#5BC7B8"></rect>
        <?= $ribs(84) ?>
        <?php if ($isLogin): ?><rect x="84" y="38" width="28" height="10" rx="5" fill="#000000" opacity="0.06"></rect><?php endif; ?>
        <ellipse cx="98" cy="14" rx="14" ry="4.6" fill="#86DDD1"></ellipse>
      </g>
    </g>
  </svg>
</span>
