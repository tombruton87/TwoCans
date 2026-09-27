<?php
declare(strict_types=1);

/**
 * Everyone we don't know, in one queue.
 *
 * Two very different things reach the dashboard with the same question behind
 * them. A child dials a number that isn't on the list, is told no, and is
 * invited to say who they were after — that recording and the count of tries is
 * an *ask*, built from the call log by CallRequestRepository. Somebody nobody
 * recognises rings in, hears the refusal, and can leave a message in the house
 * mailbox — that is a *message*, listed by VoicemailRepository::unrecognised().
 * Either way it is a number the household has never agreed to, so both are
 * drawn as the same card with the same two buttons. They are listed apart,
 * though: messages wait on the dashboard (pending()), and the numbers a child
 * tried are a log on the call log screen (tried()), since an attempt from the
 * house is a record of what the phones did rather than somebody waiting.
 *
 * The records behind them stay apart, deliberately. An ask is a blocked attempt
 * with a voice note; a message is somebody's real words with a duration and a
 * mailbox, which the voicemail screen lists and the phone's message light
 * counts. Merging the tables to merge two cards would break those; this is a
 * view of both, not a new home for either.
 *
 * A row is plain strings and ids — no HTML and no URLs — so the card can escape
 * it, and this can be tested without a request or a database.
 */
final class UnknownQueue
{
    /** A child asking to reach a number that isn't allowed yet. */
    public const KIND_ASK = 'ask';

    /** Somebody nobody recognises, who rang in and left a message. */
    public const KIND_MESSAGE = 'message';

    /**
     * Callers nobody recognises who left a message, newest first — the
     * dashboard's "People we don't know".
     *
     * Only the way in. A number a child tried is the other way out and is
     * listed on the call log instead (see tried()): it is a record of what the
     * phones attempted, not somebody waiting on the house.
     *
     * Somebody added to the list another way — straight into People, or from a
     * different card for the same number — is no longer somebody we don't
     * know, so their card goes. Left undecided rather than resolved: take them
     * off the list again and the card comes back.
     *
     * @return array<int,array>
     */
    public function pending(int $limit = 20): array
    {
        $contacts = new ContactRepository();
        $rows = [];

        foreach ((new VoicemailRepository())->unrecognised($limit) as $message) {
            if ($contacts->findByNumber((string) ($message['peer_number'] ?? '')) !== null) {
                continue;
            }
            $rows[] = self::fromMessage($message);
        }

        return self::mix($rows, $limit);
    }

    /**
     * Every number a child tried to call that still isn't on the list, most
     * recent first — decided or not, so it reads as a log. A number since added
     * as a contact drops out, the same way it does from pending().
     *
     * @return array<int,array>
     */
    public function tried(int $limit = 50): array
    {
        $asks = new CallRequestRepository();
        $contacts = new ContactRepository();
        $rows = [];

        foreach ($asks->all() as $ask) {
            $number = (string) $ask['number_e164'];
            if ($contacts->findByNumber($number) !== null) {
                continue;
            }
            $rows[] = self::fromAsk($ask, $asks->attemptCount($number));
        }

        return self::mix($rows, $limit);
    }

    /**
     * One ask, in the shape the card draws.
     *
     * @param array $row a call_requests row, straight from the database
     */
    public static function fromAsk(array $row, int $attempts): array
    {
        $view = CallRequestRepository::toView($row);
        $said = $view['saidName'];
        $listening = in_array($view['transcriptStatus'], ['pending', 'running'], true);

        /*
         * What the child said is a guess at a name, so the card offers it as
         * one. An ask nobody has transcribed yet says so rather than pretending
         * there is nothing to hear.
         */
        $headline = $said !== ''
            ? 'Maybe “' . $said . '”?'
            : ($listening ? 'Listening to what they said…' : 'Somebody new');

        return [
            'kind' => self::KIND_ASK,
            'id' => $view['id'],
            'tag' => 'Wants to call',
            'at' => self::timestamp($row['last_asked_at'] ?? $row['requested_at'] ?? null),
            'number' => $view['number'],
            'headline' => $headline,
            // Only the "still listening" line is a live one, so it gets the pulse.
            'headlineLive' => $said === '' && $listening,
            'note' => ($attempts <= 1 ? 'Tried once' : "Tried {$attempts} times") . ' · ' . $view['when']
                . (($row['resolution'] ?? null) === 'denied' ? ' · you said not now' : ''),
            'clip' => $view['hasRecording'] ? [
                'route' => 'ask_audio',
                'id' => $view['id'],
                'label' => 'Hear them ask',
                'aria' => 'Hear what they said',
            ] : null,
            // An ask has a voice note, not a message — nothing to quote.
            'showTranscript' => false,
            'transcript' => '',
            'transcriptStatus' => '',
            /*
             * Adding somebody opens the contact editor with the number filled in
             * and the contact switched off until it is saved — what this queue
             * has always done. "Not now" keeps the row, so the number does not
             * come straight back, and drops the voice note.
             */
            'approve' => ['action' => 'request_approve', 'label' => 'Add them'],
            // Already turned down: nothing left to say no to, but it can
            // still be added if a grown-up changes their mind.
            'dismiss' => ($row['resolution'] ?? null) === 'denied'
                ? null
                : ['action' => 'request_deny', 'label' => 'Not now'],
        ];
    }

    /**
     * One message from a number nobody recognises, in the same shape.
     *
     * @param array $row a voicemails row, straight from the database
     */
    public static function fromMessage(array $row): array
    {
        $view = VoicemailRepository::toView($row);

        return [
            'kind' => self::KIND_MESSAGE,
            'id' => $view['id'],
            'tag' => 'Rang us',
            'at' => self::timestamp($row['left_at'] ?? null),
            'number' => $view['number'],
            /*
             * A message is somebody's words rather than a guess at a name, so
             * there is no "Maybe …?" headline: it is quoted underneath instead,
             * where a long one can be read as the sentence it is.
             */
            'headline' => '',
            'headlineLive' => false,
            'note' => trim(
                $view['date'] . ' ' . $view['time'] . ' · ' . $view['dur'] . ' · left in the house mailbox'
            ),
            'clip' => $view['hasAudio'] ? [
                'route' => 'voicemail_audio',
                'id' => $view['id'],
                'label' => 'Hear the message',
                'aria' => 'Play the message left by ' . $view['number'],
            ] : null,
            'showTranscript' => true,
            'transcript' => $view['transcript'],
            'transcriptStatus' => $view['transcriptStatus'],
            /*
             * "Not now" on a message records the verdict and leaves the
             * recording where it is: those are somebody's real words, and a new
             * message from the same number arrives as a row of its own, so
             * somebody persistent gets noticed instead of hidden.
             */
            'approve' => ['action' => 'screening_allow', 'label' => 'Add them'],
            'dismiss' => ['action' => 'screening_junk', 'label' => 'Not now'],
        ];
    }

    /**
     * Newest first, both kinds interleaved, capped to a card's worth.
     *
     * A row with no usable timestamp — an old record, or a column a migration
     * has not filled in — sorts to the bottom rather than to the top.
     */
    public static function mix(array $rows, int $limit = 20): array
    {
        usort($rows, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($rows, 0, max(0, $limit));
    }

    private static function timestamp(mixed $value): int
    {
        return (int) (strtotime((string) $value) ?: 0);
    }
}
