<?php
declare(strict_types=1);

/**
 * Questions for the command-line tools in bin/: a line of text, a yes or no,
 * and a password typed without showing it.
 *
 * Passwords are read from the terminal rather than taken as arguments, so they
 * never land in shell history or the process list. With input piped in (no
 * terminal) each answer is simply the next line.
 */
final class Cli
{
    /** A line of text; Enter takes $suggested. */
    public static function ask(string $question, string $suggested = ''): string
    {
        echo '  ' . $question . ($suggested !== '' ? " [{$suggested}]" : '') . ': ';
        $answer = trim((string) fgets(STDIN));

        return $answer !== '' ? $answer : $suggested;
    }

    /** Yes or no; Enter takes $default. */
    public static function confirm(string $question, bool $default): bool
    {
        echo '  ' . $question . ($default ? ' [Y/n]' : ' [y/N]') . ': ';
        $answer = strtolower(trim((string) fgets(STDIN)));

        return $answer === '' ? $default : str_starts_with($answer, 'y');
    }

    /** A line typed without it showing on screen. */
    public static function secret(string $label): string
    {
        echo '  ' . $label;
        if (!stream_isatty(STDIN)) {
            return rtrim((string) fgets(STDIN), "\r\n");
        }
        shell_exec('stty -echo 2>/dev/null');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo 2>/dev/null');
        echo "\n";

        return $value;
    }

    /** A new password, typed twice, until it's acceptable. Null if they give up. */
    public static function newPassword(int $tries = 3): ?string
    {
        for ($i = 0; $i < $tries; $i++) {
            $password = self::secret('New password: ');
            $problem = Auth::passwordProblem($password, self::secret('Same again: '));
            if ($problem === null) {
                return $password;
            }
            echo "    {$problem}\n";
        }

        return null;
    }

    public static function line(string $text = ''): void
    {
        echo $text === '' ? "\n" : "  {$text}\n";
    }
}
