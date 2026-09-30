-- How long a phone rings before the caller goes to voicemail, per phone, in
-- seconds (the app shows rings: see DeviceRepository::RING_SECONDS). NULL is
-- the old fixed 30 seconds.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS ring_seconds SMALLINT UNSIGNED NULL;
