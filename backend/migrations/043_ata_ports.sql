-- Grandstream adapters (HT801/HT802): which phone socket a phone is.
--
-- An HT802 has two sockets, each its own twocans phone with its own SIP
-- account, but the box has one MAC and fetches one config file for both. So
-- the MAC is no longer unique on its own: it is unique per socket. Every
-- other phone is socket 1.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS port TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER mac;

ALTER TABLE devices DROP INDEX IF EXISTS uq_devices_mac;
ALTER TABLE devices ADD UNIQUE KEY IF NOT EXISTS uq_devices_mac_port (mac, port);
