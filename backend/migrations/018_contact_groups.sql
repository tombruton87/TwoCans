-- Three-way calls, as a kind of contact.
--
-- The interface problem with conferencing on a children's phone is that every
-- normal answer is wrong: a child cannot hold a call, dial a second party and
-- press a key to merge them, and an ATA with a rotary handset has nowhere to
-- press anyway. So a group is not a call feature here — it is a *person*.
-- "Grandma & Grandad" sits in the contact list with its own speed dial, and the
-- child dials it exactly like they dial Grandma. Everyone's phone rings and
-- everybody ends up in the same conversation.
--
-- Members are other contacts rather than raw numbers, which means a group can
-- only ever contain people who are already allowed. There is no way to reach
-- somebody new by putting them in a group.

ALTER TABLE contacts
  ADD COLUMN IF NOT EXISTS is_group TINYINT(1) NOT NULL DEFAULT 0 AFTER relationship;

CREATE TABLE IF NOT EXISTS contact_members (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    contact_id        INT UNSIGNED NOT NULL,
    member_contact_id INT UNSIGNED NOT NULL,
    sort_order        TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- Somebody can only be in a given group once.
    UNIQUE KEY uq_member (contact_id, member_contact_id),
    KEY ix_member_contact (member_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
