<?php
declare(strict_types=1);

/**
 * Set or reset a guardian's password from the command line.
 *
 * This is the recovery path when the Owner is locked out, and — until invitation
 * emails are wired up — the way an invited guardian gets a usable password.
 *
 *   docker exec -it twocans-php php /var/www/html/bin/set-password.php you@home.co
 *   docker exec twocans-php php /var/www/html/bin/set-password.php --list
 *
 * The password is read from the terminal rather than argv, so it never lands in
 * shell history or the process list.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$repo = new GuardianRepository();

if (in_array('--list', $argv, true)) {
    printf("%-4s %-28s %-24s %-7s %s\n", 'ID', 'NAME', 'EMAIL', 'ROLE', 'PASSWORD');
    foreach ($repo->all() as $g) {
        printf(
            "%-4d %-28s %-24s %-7s %s\n",
            $g['id'],
            mb_substr((string) $g['name'], 0, 27),
            mb_substr((string) $g['email'], 0, 23),
            $g['role'],
            $g['password_hash'] === null ? 'not set' : 'set'
        );
    }
    exit(0);
}

$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage: set-password.php <email>\n       set-password.php --list\n");
    exit(1);
}

$guardian = $repo->findByEmail($email);
if ($guardian === null) {
    fwrite(STDERR, "No guardian with that email. Try --list.\n");
    exit(1);
}

echo "Setting a new password for {$guardian['name']} <{$guardian['email']}> ({$guardian['role']})\n";

$password = prompt_hidden('New password: ');
$confirm = prompt_hidden('Again: ');

if (($problem = Auth::passwordProblem($password, $confirm)) !== null) {
    fwrite(STDERR, "\n{$problem}\n");
    exit(1);
}

$repo->setPassword((int) $guardian['id'], $password);

// A password change should also clear any lockout for that account.
Database::pdo()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$guardian['email']]);

echo "\nPassword updated. Any sign-in lockout for this account has been cleared.\n";

/** Read a line from the terminal without echoing it. */
function prompt_hidden(string $label): string
{
    echo $label;

    if (!stream_isatty(STDIN)) {
        // Non-interactive (piped input): can't disable echo, just read.
        return rtrim((string) fgets(STDIN), "\r\n");
    }

    shell_exec('stty -echo 2>/dev/null');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo 2>/dev/null');
    echo "\n";

    return $value;
}
