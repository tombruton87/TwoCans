<?php
declare(strict_types=1);

/**
 * The line's stock prompts, each of which the household can re-record.
 *
 * Out of the box every one of these is an Asterisk sound file in Asterisk's
 * voice, some of them saying something not quite right for this house — the
 * child asked "who were you trying to call?" actually hears "say your name and
 * then press the pound key". Each slot here names where it plays and what the
 * stock one says, so the Greetings screen can explain it and the dialplan can
 * swap in the household's recording when there is one.
 *
 * Stored as a filename per slot in settings (`greeting_<slot>`), never a path:
 * the path is re-derived from GreetingStore, so a garbled row can't point
 * Asterisk anywhere else. A slot whose file has gone missing reads as "not
 * recorded" and the stock prompt plays, rather than silence.
 */
final class Greetings
{
    /**
     * slot => title, who hears it, when it plays, the stock prompt and what it says.
     * `section` groups them on screen by who is listening.
     */
    public const SLOTS = [
        'voicemail' => [
            'section' => 'callers',
            'title' => 'House voicemail greeting',
            'when' => "Plays when a call to the house isn't answered: nobody picked up, the line is "
                    . "asleep for bedtime or outside someone's hours (after the quiet-time message), "
                    . "or an unknown caller is offered the mailbox. The caller leaves their message "
                    . 'after the beep that follows.',
            'stock' => null,
            'says' => '“The person at extension 1-0-0 is unavailable. Please leave your message after the tone…”',
            'tip' => '“You\'ve reached the Smith house — we can\'t get to the phone. Leave a message after the beep.”',
        ],
        'unknown_caller' => [
            'section' => 'callers',
            'title' => 'Not on the list (house default)',
            'when' => "Plays to a caller whose number isn't on the call list, when the phone they "
                    . "rang — and every other phone — has no refusal message of its own (set on "
                    . "each phone's page). They can press 5 for the joke line.",
            'stock' => 'invalid',
            'says' => '“I am sorry, that\'s not a valid extension. Please try again.”',
            'tip' => '“Sorry, this phone only takes calls from people the family knows. Press 5 for a joke.”',
        ],
        'not_allowed' => [
            'section' => 'kids',
            'title' => 'That number isn\'t allowed',
            'when' => "Plays to a child who dials a number that isn't on their list, or one a dial "
                    . 'plan rule blocks. Straight after it, they\'re asked who they were trying to call.',
            'stock' => PjsipConfig::BLOCKED_MESSAGE,
            'says' => '“I am sorry, that\'s not a valid extension. Please try again.”',
            'tip' => '“That number isn\'t on your list yet.”',
        ],
        'ask' => [
            'section' => 'kids',
            'title' => 'Who were you trying to call?',
            'when' => 'Plays after “that number isn\'t allowed”. The child speaks after the beep, and '
                    . 'the recording shows up on the dashboard for a grown-up to approve.',
            'stock' => PjsipConfig::ASK_PROMPT,
            'says' => '“After the tone say your name and then press the pound key.”',
            'tip' => '“Who were you trying to call? Say their name after the beep and we\'ll ask a grown-up.”',
        ],
        'outside_hours' => [
            'section' => 'kids',
            'title' => 'Not right now',
            'when' => 'Plays to a child calling someone outside the hours set on that person or group, '
                    . 'or while bedtime mode has the line asleep.',
            'stock' => PjsipConfig::WINDOW_MESSAGE,
            'says' => '“Nobody is available to take your call at the moment.”',
            'tip' => '“It\'s not a good time to call Grandma right now — try again after school.”',
        ],
        'limit_reached' => [
            'section' => 'kids',
            'title' => 'Out of phone time today',
            'when' => 'Plays to a child who tries to call out once their phone has used up its '
                    . "daily call time (set on the phone's page). SOS contacts still get through.",
            'stock' => 'beeperr&vm-goodbye',
            'says' => '(an error tone) “Goodbye.”',
            'tip' => '“That\'s all your phone time for today — you can call again tomorrow.”',
        ],
        'no_line' => [
            'section' => 'kids',
            'title' => 'No phone line',
            'when' => 'Plays when a child calls someone outside the house but no phone line (trunk) '
                    . 'is connected, so the call has nowhere to go.',
            'stock' => PjsipConfig::NO_LINE_MESSAGE,
            'says' => '“Nobody is available to take your call at the moment.”',
            'tip' => '“The phone line isn\'t working just now. Ask a grown-up to have a look.”',
        ],
    ];

    private SettingsRepository $settings;
    private GreetingStore $store;

    public function __construct(?SettingsRepository $settings = null, ?GreetingStore $store = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
        $this->store = $store ?? new GreetingStore();
    }

    public static function exists(string $slot): bool
    {
        return isset(self::SLOTS[$slot]);
    }

    /** Stored filename for a slot, or null when it plays the stock prompt. */
    public function file(string $slot): ?string
    {
        $name = trim((string) ($this->settings->all()['greeting_' . $slot] ?? ''));

        return $name === '' ? null : $name;
    }

    public function seconds(string $slot): int
    {
        return max(0, (int) ($this->settings->all()['greeting_' . $slot . '_seconds'] ?? 0));
    }

    /** Remember a new recording for a slot, or forget it when $file is null. */
    public function set(string $slot, ?string $file, int $seconds = 0): void
    {
        $this->settings->set('greeting_' . $slot, (string) $file);
        $this->settings->set('greeting_' . $slot . '_seconds', $file === null ? '0' : (string) max(0, $seconds));
    }

    /** The household's recording as Asterisk plays it, or null if there isn't one. */
    public function custom(string $slot): ?string
    {
        $file = $this->file($slot);

        return $file === null ? null : $this->store->playbackPath($file);
    }

    /** What Asterisk should play for this slot: the recording, else the stock prompt. */
    public function prompt(string $slot): ?string
    {
        return $this->custom($slot) ?? self::SLOTS[$slot]['stock'];
    }
}
