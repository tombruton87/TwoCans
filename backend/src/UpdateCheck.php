<?php
declare(strict_types=1);

/**
 * Is there a newer twocans? The latest release on GitHub, looked up at most
 * twice a day by bin/minute.php and remembered in settings, so pages only read
 * it. Shown on the System page, with a badge on the menu — see isNewer().
 *
 * It is the one thing twocans asks the internet unprompted (GitHub sees this
 * machine's address), so a household can turn it off on the System page.
 */
final class UpdateCheck
{
    public const REPO = 'tombruton87/TwoCans';

    /** Look again after this long. */
    private const EVERY_SECONDS = 43200;

    public function __construct(private SettingsRepository $settings = new SettingsRepository())
    {
    }

    /** The version running: backend/VERSION. */
    public static function current(): string
    {
        return trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: '0.0.0';
    }

    public function enabled(): bool
    {
        return ($this->settings->all()['update_check'] ?? '1') !== '0';
    }

    public function setEnabled(bool $on): void
    {
        $this->settings->set('update_check', $on ? '1' : '0');
    }

    /**
     * What GitHub last said: the newest version, where its notes are, and when
     * we asked. Empty version when never asked, or it couldn't be reached.
     *
     * @return array{version:string,url:string,checkedAt:int}
     */
    public function latest(): array
    {
        $all = $this->settings->all();

        return [
            'version' => (string) ($all['latest_version'] ?? ''),
            'url' => (string) ($all['latest_url'] ?? ''),
            'checkedAt' => (int) ($all['latest_checked_at'] ?? 0),
        ];
    }

    /** Whether a newer version than this one is out. */
    public function isNewer(): bool
    {
        $latest = $this->latest()['version'];

        return $this->enabled() && $latest !== '' && version_compare($latest, self::current(), '>');
    }

    /**
     * Ask GitHub, if it's time (or $force). Returns what's known afterwards;
     * a failed ask keeps the last answer and tries again next time.
     *
     * @return array{version:string,url:string,checkedAt:int}
     */
    public function refresh(bool $force = false): array
    {
        $known = $this->latest();
        if (!$this->enabled() || (!$force && time() - $known['checkedAt'] < self::EVERY_SECONDS)) {
            return $known;
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => 6,
            'ignore_errors' => true,
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: twocans/" . self::current() . "\r\n",
        ]]);
        $body = @file_get_contents('https://api.github.com/repos/' . self::REPO . '/releases/latest', false, $ctx);
        $json = json_decode($body === false ? '' : $body, true);
        $tag = is_array($json) ? (string) ($json['tag_name'] ?? '') : '';

        // Remember the ask either way, so a GitHub that's down isn't asked every minute.
        $this->settings->set('latest_checked_at', (string) time());
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m)) {
            $this->settings->set('latest_version', $m[1]);
            $this->settings->set('latest_url', (string) ($json['html_url'] ?? ''));
        }

        return $this->latest();
    }
}
