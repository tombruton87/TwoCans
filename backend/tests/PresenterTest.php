<?php
declare(strict_types=1);

/** Presentation helpers that have to be exactly right for the UI to make sense. */

return [
    test('pageNumbers returns every page when few', function () {
        assertSame([1, 2, 3, 4, 5], Presenter::pageNumbers(3, 5));
    }),
    test('pageNumbers fills a one-page gap rather than hiding it', function () {
        assertSame([1, 2, 3, 4, 5, null, 8], Presenter::pageNumbers(4, 8));
    }),
    test('window() maps known presets', function () {
        assertSame('Anytime', Presenter::window('anytime')['label']);
        assertSame('After school', Presenter::window('afterschool')['label']);
    }),
    test('window() falls back to custom for an unknown key', function () {
        assertSame('Custom hours', Presenter::window('nonsense')['label']);
    }),
    test('listenModeDescription covers the three modes', function () {
        assertContains('child', Presenter::listenModeDescription('whisper'));
        assertContains('joined', Presenter::listenModeDescription('join'));
        assertContains('mute', Presenter::listenModeDescription('listen'));
    }),
];
