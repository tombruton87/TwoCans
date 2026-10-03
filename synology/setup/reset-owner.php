<?php
// No declare(strict_types=1): it has to be a file's first statement, and run.sh
// puts the request in front of this one.

/**
 * Reset the Owner account, from twocans' window in DSM — for an Owner who's
 * locked out. bin/reset-owner.php does the same at a terminal, asking as it
 * goes; this one is told: run.sh puts $req in front of it, and runs it inside
 * the web container (docker exec -i twocans-web php), so any app from 0.1.6
 * on will do.
 *
 *   $req = ['password' => new password, 'email' => new email or '',
 *           'passkeys' => remove them?, 'signout' => sign out everywhere?]
 *
 * Being a DSM administrator is the proof, as being at the machine is for the
 * terminal one. Prints JSON: who the Owner is, and what changed.
 *
 * @var array{password:string,email:string,passkeys:bool,signout:bool} $req
 */

require '/var/www/html/src/bootstrap_cli.php';

$say = static function (array $answer): never {
    echo json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(isset($answer['error']) ? 1 : 0);
};

$guardians = new GuardianRepository();
$passkeys = new PasskeyRepository();

$owners = $guardians->owners();
if ($owners === []) {
    $say(['error' => 'This household has no Owner to reset. Over SSH, `sudo ./twocans reset-owner` in the twocans folder makes one.']);
}
if (count($owners) > 1) {
    $say(['error' => 'This household has more than one Owner, so it isn\'t clear which to reset. Over SSH, `sudo ./twocans reset-owner` in the twocans folder asks which.']);
}
$owner = $owners[0];
$id = (int) $owner['id'];

$email = mb_strtolower(trim((string) ($req['email'] ?? '')));
if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $say(['error' => 'That doesn\'t look like an email address.']);
    }
    $other = $guardians->findByEmail($email);
    if ($other !== null && (int) $other['id'] !== $id) {
        $say(['error' => "{$other['name']} already signs in with that email — pick another."]);
    }
}
if (strlen((string) ($req['password'] ?? '')) < 12) {
    $say(['error' => 'No new password was made.']);
}

if ($email !== '') {
    $guardians->setEmail($id, $email);
}
$guardians->setPassword($id, (string) $req['password']);
$removed = 0;
if (!empty($req['passkeys'])) {
    foreach ($passkeys->forGuardian($id) as $key) {
        $passkeys->remove((int) $key['id'], $id);
        $removed++;
    }
}
if (!empty($req['signout'])) {
    $guardians->signOutEverywhere($id);
}

$say([
    'ok' => true,
    'name' => (string) $owner['name'],
    'email' => $email !== '' ? $email : (string) $owner['email'],
    'passkeysRemoved' => $removed,
    'signedOut' => !empty($req['signout']),
]);
