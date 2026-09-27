<?php
declare(strict_types=1);

/**
 * The message a caller who isn't on the list hears.
 *
 * One recording per phone, uploaded on that phone's page. The dialplan plays
 * the message belonging to the phone that would have rung, so a house with a
 * phone in the hall and one in a bedroom refuses a call in the voice of
 * whichever one it reached — and a household that has never recorded anything
 * keeps the stock prompt, so nothing breaks if this is left alone.
 *
 * Uploaded rather than typed because there is no text-to-speech on this box:
 * Asterisk can only play a file, and the one format it will play is one no
 * parent has lying around — so the conversion is AudioStore's, shared with the
 * joke line, and an MP3 or a voice memo off a phone is accepted exactly as it
 * comes. That is why the box on the phone's page used to do nothing at all. The
 * transcript is what the app shows back, filled in from the recording by the
 * transcription worker, and a parent can edit it.
 */
final class RefusalStore extends AudioStore
{
    /**
     * Two sentences is plenty. Long enough for "Sorry, nobody can take your
     * call just now — please try again later", short enough that nobody is
     * left listening to a house explain itself.
     */
    private const MAX_SECONDS = 30;

    public function path(): string
    {
        return rtrim(getenv('REFUSALS_PATH') ?: '/var/lib/twocans/refusals', '/');
    }

    protected function maxSeconds(): int
    {
        return self::MAX_SECONDS;
    }

    protected function noun(): string
    {
        return 'message';
    }

    /**
     * A directory that isn't there is almost always a volume that wasn't
     * mounted, so name the path: a household can hold it next to the compose
     * file, and "try again" would only send them round in circles.
     */
    protected function nowhereToSave(): string
    {
        return "Couldn't save that message — nothing is mounted at " . $this->path() . '. '
             . "Add './storage/refusals' to the web and transcriber containers (see the compose file) and try again.";
    }
}
