<?php
declare(strict_types=1);

/**
 * The allowlist. Nobody outside this table can reach the kids' phones, and the
 * kids can only dial people in it — so this is the table the whole product is
 * really about, and it drives the generated dialplan.
 */
final class ContactRepository
{
    /** Avatar colours, from the design palette. */
    private const PALETTE = ['#FFC857', '#FF7A59', '#5BC7B8', '#A78BD0', '#6FB7E8'];

    /**
     * Numbers a speed dial must never shadow.
     *
     * A child in trouble dials 999 or 112 by reflex. If a contact could claim
     * that code, the call would reach Grandma instead of an ambulance — so
     * these are refused outright, and routed to the trunk untouched below.
     * 101/111/105/116 are the UK non-emergency and utility services.
     */
    public const EMERGENCY_NUMBERS = ['999', '112', '911'];
    public const RESERVED_NUMBERS = ['999', '112', '911', '101', '111', '105', '116', '18000', '18001'];

    /** Where the household is, for turning "07700..." into "+447700...". */
    public static function countryCode(): string
    {
        return getenv('DEFAULT_COUNTRY_CODE') ?: '44';
    }

    public function all(): array
    {
        return Database::pdo()
            ->query('SELECT * FROM contacts ORDER BY name = "", name, id')
            ->fetchAll();
    }

    public function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM contacts WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    public function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    }

    /**
     * Start a contact off from an approved ask.
     *
     * Deliberately not save(): that insists on a name, and the whole point here
     * is that we don't reliably have one — the child may have said nothing, or
     * Whisper may have made a mess of "Sam". The number is safe to write since
     * it came from a real dialled call, and the grown-up names them properly in
     * the editor that opens next.
     *
     * The contact is left with allow_out off until it is saved, so a half-built
     * row can never widen the allowlist on its own.
     */
    public function prefill(int $id, string $number, string $suggestedName = ''): void
    {
        Database::pdo()->prepare(
            'UPDATE contacts
                SET number_e164 = ?, name = ?, allow_in = 0, allow_out = 0
              WHERE id = ?'
        )->execute([$number, trim($suggestedName), $id]);
    }

    // ------------------------------------------------------------- groups

    /**
     * The contacts inside a group, in the order they should be rung.
     *
     * Only members that are still reachable come back: a member who has been
     * deleted, or had their permission to be called out to taken away, drops
     * out of the group rather than making the whole thing fail.
     *
     * @return array<int,array>
     */
    public function members(int $groupId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.*
               FROM contact_members m
               JOIN contacts c ON c.id = m.member_contact_id
              WHERE m.contact_id = ?
                AND c.is_group = 0
                AND c.allow_out = 1
                AND c.number_e164 IS NOT NULL
                AND c.number_e164 <> ""
              ORDER BY m.sort_order, c.name'
        );
        $stmt->execute([$groupId]);

        return $stmt->fetchAll();
    }

    /**
     * Switch a contact between being a person and being a group.
     *
     * The stored number is left alone in both directions. A group ignores it —
     * groups are excluded from number lookups and from the dial-in-full rules —
     * so keeping it means switching to a group and back doesn't quietly throw
     * away the number that was there.
     */
    public function setIsGroup(int $id, bool $isGroup): void
    {
        Database::pdo()->prepare('UPDATE contacts SET is_group = ? WHERE id = ?')
            ->execute([$isGroup ? 1 : 0, $id]);
    }

    /** Member ids only, including any that members() would filter out. */
    public function memberIds(int $groupId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT member_contact_id FROM contact_members WHERE contact_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$groupId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Replace a group's membership.
     *
     * @param array<int,int> $memberIds
     */
    public function setMembers(int $groupId, array $memberIds): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM contact_members WHERE contact_id = ?')->execute([$groupId]);

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO contact_members (contact_id, member_contact_id, sort_order)
             VALUES (?, ?, ?)'
        );

        $order = 0;
        foreach ($memberIds as $memberId) {
            $memberId = (int) $memberId;
            // A group cannot contain itself, and cannot contain another group:
            // nested conferences are a good way to build an accidental loop.
            if ($memberId <= 0 || $memberId === $groupId) {
                continue;
            }
            $member = $this->find($memberId);
            if ($member === null || (int) ($member['is_group'] ?? 0) === 1) {
                continue;
            }

            $insert->execute([$groupId, $memberId, $order++]);
        }
    }

    /** Everyone who could be put in a group — real people, with a number. */
    public function groupCandidates(int $excludeGroupId = 0): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM contacts
              WHERE is_group = 0
                AND number_e164 IS NOT NULL AND number_e164 <> ""
                AND name <> ""
                AND id <> ?
              ORDER BY name'
        );
        $stmt->execute([$excludeGroupId]);

        return $stmt->fetchAll();
    }

    /** @return array<int,array> groups only */
    public function groups(): array
    {
        return Database::pdo()
            ->query('SELECT * FROM contacts WHERE is_group = 1 ORDER BY name')
            ->fetchAll();
    }

    /** Look a caller up by number, however it happens to be formatted. */
    public function findByNumber(string $number): ?array
    {
        $digits = self::digits($number);
        if ($digits === '') {
            return null;
        }

        // Compare on the last 9 digits so +447700900123, 07700900123 and
        // 7700900123 all resolve to the same person.
        $tail = substr($digits, -9);
        $st = Database::pdo()->prepare(
            "SELECT * FROM contacts
              WHERE is_group = 0
                AND RIGHT(REGEXP_REPLACE(number_e164, '[^0-9]', ''), 9) = ?
              LIMIT 1"
        );
        $st->execute([$tail]);

        return $st->fetch() ?: null;
    }

    public function findBySpeedDial(string $code): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM contacts WHERE speed_dial = ?');
        $st->execute([$code]);

        return $st->fetch() ?: null;
    }

    /**
     * Start a new contact.
     *
     * Reuses an abandoned blank draft rather than stacking up another: opening
     * "Add a person" and closing it without saving is a normal thing to do, and
     * it should not litter the list. The number is left NULL, not empty — the
     * column is UNIQUE and NULL is exempt, so two drafts can coexist.
     */
    public function create(): int
    {
        $pdo = Database::pdo();

        $existing = $pdo->query(
            'SELECT id FROM contacts WHERE name = "" AND (number_e164 IS NULL OR number_e164 = "")
              ORDER BY id LIMIT 1'
        )->fetchColumn();

        if ($existing !== false) {
            return (int) $existing;
        }

        $pdo->prepare(
            'INSERT INTO contacts (name, relationship, number_e164, color, call_window,
                                   allow_in, allow_out, sos, ring_both)
             VALUES ("", "Friend", NULL, ?, "afterschool", 1, 1, 0, 0)'
        )->execute([self::PALETTE[$this->count() % count(self::PALETTE)]]);

        return (int) $pdo->lastInsertId();
    }

    /** Drafts the parent started and never filled in. */
    public function removeBlankDrafts(int $exceptId = 0): void
    {
        Database::pdo()->prepare(
            'DELETE FROM contacts
              WHERE name = "" AND (number_e164 IS NULL OR number_e164 = "") AND id <> ?'
        )->execute([$exceptId]);
    }

    /**
     * Save an edited contact.
     *
     * @return string|null problem to show the parent, or null on success
     */
    public function save(int $id, array $input): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $number = self::toE164((string) ($input['number'] ?? ''));
        $code = substr(preg_replace('/\D/', '', (string) ($input['code'] ?? '')) ?? '', 0, 4);

        // A group is several people who are already on the list, so it has a
        // name and members instead of a number of its own.
        $isGroup = !empty($input['isGroup']);
        $memberIds = array_map('intval', (array) ($input['members'] ?? []));

        if ($name === '') {
            return $isGroup ? 'Give this group a name.' : 'Give this person a name.';
        }
        if (!$isGroup && $number === '') {
            return "That phone number doesn't look right.";
        }
        // No minimum on members: the switch saves as soon as it is flipped, and
        // refusing to save an empty group would mean an error message the
        // moment you tick the box. A group with nobody in it simply never
        // reaches the dialplan — see PjsipConfig::renderGroupRule().

        if ($code !== '') {
            if (($problem = $this->speedDialProblem($code, $id)) !== null) {
                return $problem;
            }
        }

        // Another contact already on this number would make the allowlist
        // ambiguous — an incoming call could match either. Groups have no
        // number, so nothing to clash with.
        if (!$isGroup) {
            $clash = $this->findByNumber($number);
            if ($clash !== null && (int) $clash['id'] !== $id) {
                return 'That number is already saved as ' . $clash['name'] . '.';
            }
        }

        $existingNumber = (string) ($this->find($id)['number_e164'] ?? '');

        $window = (string) ($input['window'] ?? 'afterschool');
        if (!isset(Presenter::WINDOWS[$window])) {
            $window = 'afterschool';
        }

        Database::pdo()->prepare(
            'UPDATE contacts SET
                name = :name, relationship = :rel, number_e164 = :number,
                is_group = :is_group,
                call_window = :window, speed_dial = :code,
                allow_in = :allow_in, allow_out = :allow_out,
                sos = :sos, ring_both = :ring_both
             WHERE id = :id'
        )->execute([
            'name' => $name,
            'rel' => trim((string) ($input['rel'] ?? '')),
            // A group's form has no number field, so an absent value means
            // "not shown", not "cleared" — keep whatever is stored. Groups are
            // excluded from number lookups, so a leftover number is inert.
            'number' => $isGroup ? ($existingNumber ?: null) : $number,
            'is_group' => $isGroup ? 1 : 0,
            'window' => $window,
            'code' => $code !== '' ? $code : null,
            'allow_in' => !empty($input['allowIn']) ? 1 : 0,
            'allow_out' => !empty($input['allowOut']) ? 1 : 0,
            'sos' => !empty($input['sos']) ? 1 : 0,
            'ring_both' => !empty($input['ringboth']) ? 1 : 0,
            'id' => $id,
        ]);

        if ($isGroup) {
            $this->setMembers($id, $memberIds);
        }

        return null;
    }

    /** Why this speed dial can't be used — or null if it's fine. */
    public function speedDialProblem(string $code, int $exceptContactId = 0): ?string
    {
        if (in_array($code, self::RESERVED_NUMBERS, true)) {
            return $code . ' is an emergency or service number and can never be a speed dial.';
        }
        $service = PjsipConfig::testNumbers();
        if (isset($service[$code])) {
            return $code . ' is already used by twocans for ' . strtolower($service[$code]['label']) . '.';
        }

        $st = Database::pdo()->prepare('SELECT name FROM devices WHERE extension = ?');
        $st->execute([$code]);
        if (($device = $st->fetchColumn()) !== false) {
            return $code . ' already rings the ' . $device . '.';
        }

        $existing = $this->findBySpeedDial($code);
        if ($existing !== null && (int) $existing['id'] !== $exceptContactId) {
            return $code . ' is already ' . $existing['name'] . "'s speed dial.";
        }

        return null;
    }

    public function toggle(int $id, string $field): void
    {
        $columns = [
            'allowIn' => 'allow_in', 'allowOut' => 'allow_out',
            'sos' => 'sos', 'ringboth' => 'ring_both',
        ];
        if (!isset($columns[$field])) {
            return;
        }
        $column = $columns[$field];
        Database::pdo()->prepare("UPDATE contacts SET {$column} = NOT {$column} WHERE id = ?")->execute([$id]);
    }

    public function remove(int $id): void
    {
        // Take the picture off disk too, rather than orphaning it.
        $row = $this->find($id);
        if ($row !== null) {
            (new PhotoStore())->delete((string) ($row['photo_path'] ?? ''));
        }

        Database::pdo()->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
    }

    /** Replace this contact's picture, discarding whatever it had. */
    public function setPhoto(int $id, ?string $file): void
    {
        $row = $this->find($id);
        if ($row !== null) {
            (new PhotoStore())->delete((string) ($row['photo_path'] ?? ''));
        }

        Database::pdo()->prepare('UPDATE contacts SET photo_path = ? WHERE id = ?')
            ->execute([$file, $id]);
    }

    // ------------------------------------------------------------- numbers

    public static function digits(string $number): string
    {
        return preg_replace('/\D/', '', $number) ?? '';
    }

    /**
     * Best-effort E.164. Stored one way so matching an inbound caller against
     * the allowlist is a straight comparison rather than a guess.
     */
    public static function toE164(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $digits = self::digits($input);
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($input, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {                 // 00 44 ...
            return '+' . substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {                  // national, drop trunk 0
            return '+' . self::countryCode() . substr($digits, 1);
        }
        if (str_starts_with($digits, self::countryCode())) {
            return '+' . $digits;
        }

        // Short codes and anything else too small to be a real number.
        return strlen($digits) < 7 ? '' : '+' . self::countryCode() . $digits;
    }

    /** Map a database row to the shape the contact views expect. */
    public static function toView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'rel' => (string) ($row['relationship'] ?? ''),
            'number' => (string) ($row['number_e164'] ?? ''),
            'color' => (string) $row['color'],
            'photo' => (string) ($row['photo_path'] ?? ''),
            'window' => (string) $row['call_window'],
            'allowIn' => (bool) $row['allow_in'],
            'allowOut' => (bool) $row['allow_out'],
            'sos' => (bool) $row['sos'],
            'ringboth' => (bool) $row['ring_both'],
            'failover' => '',
            'code' => (string) ($row['speed_dial'] ?? ''),
            // A group has members instead of a number — see migration 018.
            'isGroup' => (bool) ($row['is_group'] ?? false),
        ];
    }
}
