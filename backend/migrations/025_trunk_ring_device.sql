-- Which phone the line's number rings.
--
-- NULL keeps the original behaviour: ring every phone that accepts incoming
-- calls and is inside its own hours. Pointing it at a device narrows that to
-- one handset, for a household that wants the number to reach a single phone.
--
-- ON DELETE SET NULL so removing a phone falls back to ringing them all rather
-- than leaving the line with nothing to ring.
ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS ring_device_id INT UNSIGNED NULL AFTER number_e164;

ALTER TABLE trunk
  ADD CONSTRAINT fk_trunk_ring_device FOREIGN KEY (ring_device_id)
      REFERENCES devices (id) ON DELETE SET NULL;
