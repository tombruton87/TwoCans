-- When a Grandstream last fetched its settings file from twocans, so its page
-- can say "fetched 2 minutes ago" — and a parent can tell a change reached it.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS settings_fetched_at DATETIME NULL;
