-- Where messages left on each of the line's numbers go. No row is automatic:
-- a number that rings one phone takes messages in that phone's mailbox, any
-- other in the house's (100). A row picks one: 'house', or a phone's id.
-- See TrunkRepository::mailboxFor().
CREATE TABLE IF NOT EXISTS trunk_number_mailboxes (
    number_e164 VARCHAR(20) NOT NULL PRIMARY KEY,
    target      VARCHAR(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
