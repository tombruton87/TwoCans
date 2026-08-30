<?php
/** Small CSS-only glyph for a nav item. @var string $icon */
switch ($icon) {
    case 'dash': ?>
        <span class="tc-nav-icon tc-nav-icon--dash"><i></i><i></i><i></i><i></i></span>
    <?php break;

    case 'phones': ?>
        <span class="tc-nav-icon tc-nav-icon--phones"><i></i></span>
    <?php break;

    case 'contacts': ?>
        <span class="tc-nav-icon tc-nav-icon--contacts"><i class="head"></i><i class="body"></i></span>
    <?php break;

    case 'log': ?>
        <span class="tc-nav-icon tc-nav-icon--log"><i></i><i></i><i></i></span>
    <?php break;

    case 'voice': ?>
        <span class="tc-nav-icon tc-nav-icon--voice"><i></i><i></i></span>
    <?php break;

    case 'joke': ?>
        <span class="tc-nav-icon tc-nav-icon--joke"><i class="eye"></i><i class="eye"></i><i class="grin"></i></span>
    <?php break;

    case 'trunk': ?>
        <span class="tc-nav-icon tc-nav-icon--trunk"><i></i></span>
    <?php break;

    case 'dialplan': ?>
        <span class="tc-nav-icon tc-nav-icon--dialplan"><i class="h1"></i><i class="h2"></i><i class="v1"></i><i class="v2"></i></span>
    <?php break;
}
