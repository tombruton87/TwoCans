-- Which phone each of the line's numbers rings.
--
-- With several numbers on the line (migration 033) one choice for all of them
-- stops being enough: a second number is often a particular child's. A row
-- here points one number at one phone; a number with no row rings every phone
-- that accepts incoming calls, as before.
--
-- Keyed by the number in E.164, the same spelling trunk.number_e164 and
-- trunk.extra_numbers use. ON DELETE CASCADE: removing a phone puts its
-- numbers back to ringing everyone rather than ringing nothing.
CREATE TABLE IF NOT EXISTS trunk_number_rings (
    number_e164 VARCHAR(20) NOT NULL PRIMARY KEY,
    device_id   INT UNSIGNED NOT NULL,
    CONSTRAINT fk_trunk_number_rings_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE CASCADE
);

-- The line's one existing choice belonged to its main number.
INSERT IGNORE INTO trunk_number_rings (number_e164, device_id)
SELECT number_e164, ring_device_id FROM trunk
 WHERE ring_device_id IS NOT NULL AND number_e164 IS NOT NULL AND number_e164 <> '';
