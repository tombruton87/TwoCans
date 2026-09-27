<?php
declare(strict_types=1);

/** A guardian's passkeys — see WebAuthn and migration 040. */
final class PasskeyRepository
{
    /** @return array<int,array> newest first */
    public function forGuardian(int $guardianId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM guardian_passkeys WHERE guardian_id = ? ORDER BY created_at DESC'
        );
        $st->execute([$guardianId]);

        return $st->fetchAll();
    }

    public function findByCredential(string $credentialId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM guardian_passkeys WHERE credential_id = ?');
        $st->execute([$credentialId]);

        return $st->fetch() ?: null;
    }

    public function add(int $guardianId, array $key, string $label): void
    {
        Database::pdo()->prepare(
            'INSERT INTO guardian_passkeys (guardian_id, credential_id, public_key, sign_count, label)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$guardianId, $key['id'], $key['publicKey'], $key['signCount'], mb_substr($label, 0, 80)]);
    }

    public function used(int $id, int $signCount): void
    {
        Database::pdo()->prepare('UPDATE guardian_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?')
            ->execute([$signCount, $id]);
    }

    /** Only ever one of your own. */
    public function remove(int $id, int $guardianId): void
    {
        Database::pdo()->prepare('DELETE FROM guardian_passkeys WHERE id = ? AND guardian_id = ?')
            ->execute([$id, $guardianId]);
    }

    /**
     * "iPhone", "Android phone", "Mac" — a name for the passkey from the
     * browser that made it, so the list says which device is which.
     */
    public static function labelFromAgent(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android phone',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows PC',
            str_contains($agent, 'Linux') => 'Linux computer',
            default => 'This device',
        };
    }
}
