-- Real devices: softphones (Linphone) alongside the Grandstream ATAs.
--
-- A device is now anything that registers to the line, so `model` stops being
-- the identity and `type` takes over. Each device gets SIP credentials, a
-- transport, and a dialable extension.

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS type        ENUM('linphone','ht801','ht802') NOT NULL DEFAULT 'linphone' AFTER name,
  ADD COLUMN IF NOT EXISTS transport   ENUM('udp','tcp','tls')          NOT NULL DEFAULT 'udp'      AFTER type,
  ADD COLUMN IF NOT EXISTS extension   VARCHAR(8)                       NULL                        AFTER transport,
  ADD COLUMN IF NOT EXISTS display_name VARCHAR(120)                    NULL                        AFTER extension;

-- The ATAs are not wired yet, so `model` is only set for them.
ALTER TABLE devices MODIFY COLUMN model ENUM('HT801','HT802') NULL;

-- Asterisk needs the SIP secret in a form it can present to a challenge, so it
-- is stored recoverable rather than hashed. Widened for a generated secret.
ALTER TABLE devices MODIFY COLUMN sip_secret VARCHAR(191) NULL;

-- One extension per device, and one SIP username per device.
ALTER TABLE devices ADD UNIQUE KEY IF NOT EXISTS uq_devices_extension (extension);
