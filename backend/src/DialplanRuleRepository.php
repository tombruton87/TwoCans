<?php
declare(strict_types=1);

/**
 * Outbound dial-plan rules: prefix matches that widen (or keep shut) what the
 * kids can dial beyond the allowlist.
 *
 * A rule is a prefix of digits plus an action. "07" + allow means any UK mobile;
 * "09" + block means premium numbers stay closed even if a broader "0" rule
 * would otherwise let them through. Longest prefix wins in the generated
 * dialplan, which is Asterisk's own rule rather than something this class has
 * to enforce.
 */
final class DialplanRuleRepository
{
    public const ACTIONS = ['allow', 'block'];

    /** Rules in display order — the order does not decide matching. */
    public function all(): array
    {
        return Database::pdo()
            ->query('SELECT * FROM dialplan_rules ORDER BY sort ASC, id ASC')
            ->fetchAll();
    }

    /**
     * Add a rule.
     *
     * @return array{ok:bool,error:?string}
     */
    public function create(string $action, string $prefix, string $label): array
    {
        $action = $action === 'block' ? 'block' : 'allow';
        $prefix = self::normalizePrefix($prefix);
        $label = trim($label);

        if (($problem = self::problem($prefix)) !== null) {
            return ['ok' => false, 'error' => $problem];
        }

        try {
            Database::pdo()->prepare(
                'INSERT INTO dialplan_rules (action, prefix, label) VALUES (?, ?, ?)'
            )->execute([$action, $prefix, $label !== '' ? $label : $prefix]);
        } catch (PDOException) {
            return ['ok' => false, 'error' => 'That prefix already has a rule.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /** Rename a rule. The prefix is fixed once created — delete and re-add to move it. */
    public function rename(int $id, string $label): void
    {
        Database::pdo()->prepare('UPDATE dialplan_rules SET label = ? WHERE id = ?')
            ->execute([trim($label), $id]);
    }

    /** Flip a rule between allow and block. */
    public function toggleAction(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE dialplan_rules SET action = IF(action = 'allow', 'block', 'allow') WHERE id = ?"
        )->execute([$id]);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM dialplan_rules WHERE id = ?')->execute([$id]);
    }

    /** Why a prefix cannot become a rule — or null when it can. */
    public static function problem(string $prefix): ?string
    {
        $prefix = self::normalizePrefix($prefix);

        if ($prefix === '') {
            return 'Enter the digits a dialled number starts with, like 07.';
        }
        if (strlen($prefix) > 20) {
            return 'That prefix is too long.';
        }
        if (in_array($prefix, ContactRepository::RESERVED_NUMBERS, true)) {
            return $prefix . ' is an emergency or service number and cannot become a dial-plan rule.';
        }

        return null;
    }

    /** Keep only the digits — a prefix is always the leading digits dialled. */
    public static function normalizePrefix(string $input): string
    {
        return preg_replace('/\D/', '', trim($input)) ?? '';
    }

    /** The Asterisk extension pattern that matches this prefix. */
    public static function pattern(string $prefix): string
    {
        // Prefix + at least one more digit, so "07" matches "07700…" but not the
        // bare "07" itself (which is not a dialable number).
        return '_' . self::normalizePrefix($prefix) . 'X.';
    }

    public static function toView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'prefix' => (string) $row['prefix'],
            'label' => (string) $row['label'],
        ];
    }
}
