-- A desk phone's own choices among GrandstreamProvisioning::PHONE_SETTINGS —
-- only the ones changed from the defaults, as JSON. Empty: every default.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS phone_settings TEXT NULL;
