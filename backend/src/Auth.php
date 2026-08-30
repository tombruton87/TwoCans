<?php
declare(strict_types=1);

/**
 * Authentication and authorisation.
 *
 * Threat model worth keeping in mind: a compromise here lets a stranger add
 * themselves to a child's allowlist and call them, and read every recorded call
 * transcript. So: argon2id hashing, lockout on repeated failures, no user
 * enumeration, session regeneration on privilege change, and server-side role
 * checks on every mutating action.
 */
final class Auth
{
    /**
     * OWASP-recommended argon2id parameters (19 MiB, t=2, p=1).
     *
     * PHP's defaults are 64 MiB / t=4, which costs ~284ms and 64 MiB per
     * attempt — too heavy on a box also carrying RTP media, and a memory
     * pressure vector under concurrent attempts. Lockout below is the primary
     * brute-force defence.
     */
    private const HASH_ALGO = PASSWORD_ARGON2ID;
    private const HASH_OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    /**
     * Verified against when the email is unknown, so a missing account costs the
     * same time as a wrong password. Must use the same parameters as above.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$ckhOZXFCem85TUVNMk9qeg$Bx+EpXKeZGu4++OWE9534jujFmz1kcTePf3Hn2ytZBE';

    /** Lockout thresholds, counted over a rolling window. */
    private const WINDOW_SECONDS = 900;   // 15 minutes
    private const MAX_EMAIL_FAILURES = 5;
    private const MAX_IP_FAILURES = 20;

    public const MIN_PASSWORD_LENGTH = 10;

    /**
     * What each role may change. Owner has everything implicitly.
     *
     * Mirrors the promises the UI makes on the Guardians screen:
     *   Owner  — full control, billing, guardians, everything
     *   Admin  — phones, contacts & rules; no billing
     *   Viewer — call logs & voicemail only; cannot change settings
     */
    private const ROLE_PERMISSIONS = [
        'Owner' => ['*'],
        'Admin' => ['devices', 'contacts', 'rules', 'voicemail', 'listen', 'system'],
        'Viewer' => [],
    ];

    private static ?array $cachedUser = null;

    // ------------------------------------------------------------- hashing

    public static function hash(string $password): string
    {
        return password_hash($password, self::HASH_ALGO, self::HASH_OPTIONS);
    }

    /** Rejects passwords that are too short or obviously guessable. */
    public static function passwordProblem(string $password, ?string $confirm = null): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Use at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if ($confirm !== null && !hash_equals($password, $confirm)) {
            return "Those two passwords don't match.";
        }

        $weak = ['password', 'password1', '1234567890', 'qwertyuiop', 'letmein123', 'twocans123', 'changeme1'];
        if (in_array(mb_strtolower($password), $weak, true)) {
            return 'That password is too easy to guess.';
        }

        return null;
    }

    // ------------------------------------------------------------ sign-in

    /**
     * Attempt a sign-in.
     *
     * @return string|null Error message for the user, or null on success.
     */
    public static function attempt(string $email, string $password): ?string
    {
        $email = mb_strtolower(trim($email));
        $ip = self::clientIp();

        if (($wait = self::lockoutSeconds($email, $ip)) > 0) {
            return 'Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        }

        $guardian = (new GuardianRepository())->findByEmail($email);
        $hash = $guardian['password_hash'] ?? null;

        // Always verify something, so "no such account" and "wrong password"
        // take the same time and are indistinguishable to an attacker.
        $ok = password_verify($password, $hash ?? self::DUMMY_HASH) && $hash !== null;

        self::recordAttempt($email, $ip, $ok);

        if (!$ok) {
            // Deliberately identical for unknown email, wrong password, and an
            // invited guardian who has not set a password yet.
            return 'That email and password combination is not recognised.';
        }

        // Rehash if the cost parameters have moved on since this was set.
        if (password_needs_rehash($hash, self::HASH_ALGO, self::HASH_OPTIONS)) {
            (new GuardianRepository())->setPassword((int) $guardian['id'], $password);
        }

        self::startSession((int) $guardian['id']);
        (new GuardianRepository())->recordLogin((int) $guardian['id']);

        return null;
    }

    /** Establish an authenticated session for a guardian id. */
    public static function startSession(int $guardianId): void
    {
        // New id on privilege change — defeats session fixation.
        session_regenerate_id(true);

        $_SESSION['auth'] = [
            'guardian_id' => $guardianId,
            'signed_in_at' => time(),
        ];
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    // -------------------------------------------------------------- state

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** The signed-in guardian, re-read from the database once per request. */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $id = $_SESSION['auth']['guardian_id'] ?? null;
        if ($id === null) {
            return null;
        }

        // Re-read every request so a role change or removal takes effect at once.
        $user = (new GuardianRepository())->find((int) $id);
        if ($user === null) {
            unset($_SESSION['auth']);

            return null;
        }

        return self::$cachedUser = $user;
    }

    public static function role(): string
    {
        return (string) (self::user()['role'] ?? 'Viewer');
    }

    // ------------------------------------------------------ authorisation

    public static function can(string $permission): bool
    {
        return self::canFor(self::role(), $permission);
    }

    /**
     * Pure role check, so the matrix is testable without a live session.
     */
    public static function canFor(string $role, string $permission): bool
    {
        $allowed = self::ROLE_PERMISSIONS[$role] ?? [];

        return in_array('*', $allowed, true) || in_array($permission, $allowed, true);
    }

    /** Refuse the request outright when the role does not allow it. */
    public static function requirePermission(string $permission): void
    {
        if (self::can($permission)) {
            return;
        }

        http_response_code(403);
        flash('Your role does not allow that.');
        redirect(back());
    }

    // ------------------------------------------------------ rate limiting

    /** Seconds remaining before another attempt is allowed; 0 if not locked. */
    public static function lockoutSeconds(string $email, ?string $ip): int
    {
        $pdo = Database::pdo();
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

        $st = $pdo->prepare(
            'SELECT COUNT(*) c, COALESCE(MAX(attempted_at), NOW()) last
               FROM login_attempts
              WHERE email = ? AND successful = 0 AND attempted_at > ?'
        );
        $st->execute([$email, $since]);
        $byEmail = $st->fetch();

        if ((int) $byEmail['c'] >= self::MAX_EMAIL_FAILURES) {
            return max(0, self::WINDOW_SECONDS - (time() - strtotime((string) $byEmail['last'])));
        }

        if ($ip !== null) {
            $st = $pdo->prepare(
                'SELECT COUNT(*) c, COALESCE(MAX(attempted_at), NOW()) last
                   FROM login_attempts
                  WHERE ip = ? AND successful = 0 AND attempted_at > ?'
            );
            $st->execute([$ip, $since]);
            $byIp = $st->fetch();

            if ((int) $byIp['c'] >= self::MAX_IP_FAILURES) {
                return max(0, self::WINDOW_SECONDS - (time() - strtotime((string) $byIp['last'])));
            }
        }

        return 0;
    }

    private static function recordAttempt(string $email, ?string $ip, bool $successful): void
    {
        Database::pdo()
            ->prepare('INSERT INTO login_attempts (email, ip, successful) VALUES (?, ?, ?)')
            ->execute([$email, $ip, $successful ? 1 : 0]);

        // A successful sign-in clears that account's failure history.
        if ($successful) {
            Database::pdo()
                ->prepare('DELETE FROM login_attempts WHERE email = ? AND successful = 0')
                ->execute([$email]);
        }
    }

    /**
     * Packed client IP for the attempts table.
     *
     * nginx sits directly in front of PHP-FPM, so REMOTE_ADDR is the real
     * client. TODO(wire): if this ever sits behind another reverse proxy,
     * honour X-Forwarded-For only for a configured list of trusted proxies —
     * never unconditionally, or the per-IP limit becomes trivial to evade.
     */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $packed = $ip === '' ? false : @inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}
