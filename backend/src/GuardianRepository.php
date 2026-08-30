<?php
declare(strict_types=1);

/**
 * Guardians live in the database — they are the one thing that must survive a
 * restart even while the rest of the app is still session-backed.
 */
final class GuardianRepository
{
    private const COLUMNS = 'id, name, email, password_hash, role, color, status,
                             password_set_at, last_login_at, invited_at, created_at';

    /** Avatar colours cycled for new guardians, from the design palette. */
    private const PALETTE = ['#6FB7E8', '#A78BD0', '#5BC7B8', '#FFC857'];

    public function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM guardians')->fetchColumn();
    }

    /** True before first-run setup has created the Owner. */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function all(): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM guardians
                ORDER BY FIELD(role, \'Owner\', \'Admin\', \'Viewer\'), id';

        return Database::pdo()->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM guardians WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM guardians WHERE email = ?');
        $st->execute([mb_strtolower(trim($email))]);

        return $st->fetch() ?: null;
    }

    /** Creates the first Owner during first-run setup. */
    public function createOwner(string $name, string $email, string $password): int
    {
        return $this->create($name, $email, 'Owner', 'active', Auth::hash($password));
    }

    /**
     * Add a guardian.
     *
     * With a password they can sign in straight away (`active`). Without one
     * the row is `pending` — an invitation that cannot yet be used to sign in.
     */
    public function add(string $name, string $email, string $role, ?string $password): int
    {
        $role = in_array($role, ['Admin', 'Viewer'], true) ? $role : 'Viewer';
        $name = trim($name) !== '' ? trim($name) : $email;

        return $this->create(
            $name,
            $email,
            $role,
            $password === null ? 'pending' : 'active',
            $password === null ? null : Auth::hash($password)
        );
    }

    private function create(string $name, string $email, string $role, string $status, ?string $hash): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO guardians (name, email, password_hash, password_set_at, role, color, status, invited_at)
             VALUES (:name, :email, :hash, :set_at, :role, :color, :status, :invited)'
        );
        $st->execute([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'hash' => $hash,
            'set_at' => $hash === null ? null : date('Y-m-d H:i:s'),
            'role' => $role,
            'color' => self::PALETTE[$this->count() % count(self::PALETTE)],
            'status' => $status,
            'invited' => $status === 'pending' ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function setPassword(int $id, string $password): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'UPDATE guardians SET password_hash = ?, password_set_at = NOW(), status = \'active\' WHERE id = ?'
        );
        $st->execute([Auth::hash($password), $id]);

        // A new password should also lift any lockout on that account.
        $pdo->prepare(
            'DELETE FROM login_attempts
              WHERE email = (SELECT email FROM guardians WHERE id = ?) AND successful = 0'
        )->execute([$id]);
    }

    public function recordLogin(int $id): void
    {
        Database::pdo()->prepare('UPDATE guardians SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /** Flips Admin <-> Viewer. The Owner's role is immutable. */
    public function cycleRole(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE guardians
                SET role = CASE role WHEN 'Admin' THEN 'Viewer' ELSE 'Admin' END
              WHERE id = ? AND role <> 'Owner'"
        )->execute([$id]);
    }

    /** The Owner cannot be removed — it would orphan the household. */
    public function remove(int $id): void
    {
        Database::pdo()->prepare("DELETE FROM guardians WHERE id = ? AND role <> 'Owner'")->execute([$id]);
    }
}
