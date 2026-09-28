<?php
declare(strict_types=1);

/**
 * Set or reset a guardian's password from the command line.
 *
 * For any grown-up, by email — until invitation emails are wired up, the way an
 * invited one gets a usable password. For a locked-out Owner, reset-owner.php
 * does more (and needs no email): ./install.sh --reset-owner
 *
 *   docker compose exec web php /var/www/html/bin/set-password.php you@home.co
 *   docker compose exec web php /var/www/html/bin/set-password.php --list
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

$password = Cli::newPassword();
if ($password === null) {
    fwrite(STDERR, "\nNo password set.\n");
    exit(1);
}

$repo->setPassword((int) $guardian['id'], $password);

// A password change should also clear any lockout for that account.
Database::pdo()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$guardian['email']]);

echo "\nPassword updated. Any sign-in lockout for this account has been cleared.\n";
