<?php
declare(strict_types=1);

/**
 * Reset the Owner account, from the command line — for an Owner who is locked
 * out, has forgotten which email they used, lost the phone their passkey was
 * on, or a household that has lost its Owner altogether.
 *
 *   ./install.sh --reset-owner            (from the twocans folder)
 *   make reset-owner
 *   docker compose exec web php /var/www/html/bin/reset-owner.php
 *
 * It shows the Owner and asks, one at a time, whether to change the sign-in
 * email, set a new password, remove passkeys (Face ID / fingerprint) and sign
 * out every browser already signed in, then asks once more before changing
 * anything. With no Owner, it makes one: from an existing grown-up, or new.
 *
 * Anyone who can run this can already read the database, so it asks for no
 * other proof: being at the machine is the proof.
 */

require __DIR__ . '/../src/bootstrap_cli.php';

$guardians = new GuardianRepository();
$passkeys = new PasskeyRepository();

$ago = static function (?string $when): string {
    if ($when === null) {
        return 'never';
    }
    $s = time() - (int) strtotime($when);

    return match (true) {
        $s < 120 => 'just now',
        $s < 7200 => intdiv($s, 60) . ' minutes ago',
        $s < 172800 => intdiv($s, 3600) . ' hours ago',
        default => intdiv($s, 86400) . ' days ago',
    };
};

/** An email nobody else signs in with, or null after three goes. */
$askEmail = static function (string $question, string $current, ?int $ownId) use ($guardians): ?string {
    for ($i = 0; $i < 3; $i++) {
        $email = mb_strtolower(Cli::ask($question, $current));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Cli::line('  That doesn\'t look like an email address.');
            continue;
        }
        $other = $guardians->findByEmail($email);
        if ($other !== null && (int) $other['id'] !== $ownId) {
            Cli::line("  {$other['name']} already signs in with that — pick another.");
            continue;
        }

        return $email;
    }

    return null;
};

$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
$signInAt = $appUrl !== '' ? " at {$appUrl}" : '';

Cli::line();
Cli::line('twocans — reset the Owner account');
Cli::line(str_repeat('─', 40));
Cli::line();

// ------------------------------------------------------------ which account
$owners = $guardians->owners();
$promote = false;

if ($owners === []) {
    Cli::line('This household has no Owner, so nobody can manage the phone line.');
    $others = $guardians->all();

    if ($others !== []) {
        Cli::line('Make one of these grown-ups the Owner, or press Enter to create a new one:');
        Cli::line();
        foreach ($others as $i => $g) {
            Cli::line(sprintf('  %d. %s <%s> — %s', $i + 1, $g['name'], $g['email'], $g['role']));
        }
        Cli::line();
        $pick = Cli::ask('Number');
        if ($pick !== '') {
            $index = (int) $pick - 1;
            if (!isset($others[$index])) {
                Cli::line('There\'s no number ' . $pick . '. Nothing changed.');
                exit(1);
            }
            $target = $others[$index];
            $promote = true;
        }
    }

    if (!$promote) {
        Cli::line('A new Owner:');
        $name = Cli::ask('Name');
        $email = $askEmail('Email', '', null);
        if ($name === '' || $email === null) {
            Cli::line('A name and an email are needed. Nothing changed.');
            exit(1);
        }
        $password = Cli::newPassword();
        if ($password === null) {
            Cli::line('No password set. Nothing changed.');
            exit(1);
        }
        $guardians->createOwner($name, $email, $password);
        Cli::line();
        Cli::line("✓ {$name} is the Owner. Sign in{$signInAt} with {$email}.");
        exit(0);
    }
} elseif (count($owners) > 1) {
    Cli::line('This household has more than one Owner. Which one?');
    foreach ($owners as $i => $g) {
        Cli::line(sprintf('  %d. %s <%s>', $i + 1, $g['name'], $g['email']));
    }
    $target = $owners[max(0, (int) Cli::ask('Number', '1') - 1)] ?? $owners[0];
} else {
    $target = $owners[0];
}

$id = (int) $target['id'];
$keys = $passkeys->forGuardian($id);

Cli::line(($promote ? 'Making the Owner: ' : 'The Owner: ') . "{$target['name']} <{$target['email']}>");
Cli::line('  Last signed in: ' . $ago($target['last_login_at']));
Cli::line('  Password: ' . ($target['password_hash'] === null ? 'not set' : 'set'));
Cli::line('  Passkeys (Face ID / fingerprint): ' . count($keys));
Cli::line();

// ---------------------------------------------------------- what to change
$changes = [];

$email = $askEmail('Sign-in email (Enter keeps it)', (string) $target['email'], $id);
if ($email === null) {
    Cli::line('Nothing changed.');
    exit(1);
}
if ($email !== $target['email']) {
    $changes[] = "sign-in email → {$email}";
}

// Someone being made the Owner needs a password they know.
$password = null;
$needsPassword = $promote || $target['password_hash'] === null;
if ($needsPassword || Cli::confirm('Set a new password?', true)) {
    $password = Cli::newPassword();
    if ($password === null) {
        Cli::line('No password set. Nothing changed.');
        exit(1);
    }
    $changes[] = 'a new password (and any sign-in lockout cleared)';
}

$removeKeys = false;
if ($keys !== []) {
    Cli::line('  Remove passkeys if a phone or computer that had one is lost or no longer yours.');
    $removeKeys = Cli::confirm('Remove all ' . count($keys) . ' passkey(s)?', false);
    if ($removeKeys) {
        $changes[] = 'remove ' . count($keys) . ' passkey(s)';
    }
}

$signOut = Cli::confirm('Sign out every browser already signed in to this account?', true);
if ($signOut) {
    $changes[] = 'sign out everywhere';
}
if ($promote) {
    array_unshift($changes, "make {$target['name']} the Owner");
}

Cli::line();
if ($changes === []) {
    Cli::line('Nothing to change.');
    exit(0);
}
Cli::line('About to:');
foreach ($changes as $change) {
    Cli::line('  • ' . $change);
}
Cli::line();
if (!Cli::confirm('Go ahead?', true)) {
    Cli::line('Nothing changed.');
    exit(0);
}

// ------------------------------------------------------------------- change
$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    if ($promote) {
        $guardians->makeOwner($id);
    }
    if ($email !== $target['email']) {
        $guardians->setEmail($id, $email);
    }
    if ($password !== null) {
        $guardians->setPassword($id, $password);
    }
    // Lockouts are kept by email; clear any on the old one too.
    $pdo->prepare('DELETE FROM login_attempts WHERE email IN (?, ?) AND successful = 0')
        ->execute([$target['email'], $email]);
    if ($removeKeys) {
        foreach ($keys as $key) {
            $passkeys->remove((int) $key['id'], $id);
        }
    }
    if ($signOut) {
        $guardians->signOutEverywhere($id);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    Cli::line('Something went wrong, and nothing was changed: ' . $e->getMessage());
    exit(1);
}

Cli::line();
Cli::line("✓ Done. Sign in{$signInAt} with {$email}.");
Cli::line();
