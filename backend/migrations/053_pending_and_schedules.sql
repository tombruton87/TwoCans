-- A Grandstream that was offline when its settings changed: sent them the
-- moment it's back (see GrandstreamProvisioning::notify and bin/minute.php),
-- and cleared when it fetches them.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS settings_pending TINYINT(1) NOT NULL DEFAULT 0;

-- Announcements that play by themselves at a set time on chosen days — "bath
-- time in ten minutes" at 18:50 on school nights. schedule_days is the ISO
-- weekdays it plays on (1 Monday … 7 Sunday), comma-separated; empty is off.
-- last_scheduled_at stops one playing twice for the same time.
ALTER TABLE announcements
  ADD COLUMN IF NOT EXISTS schedule_days VARCHAR(20) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS schedule_time TIME NULL,
  ADD COLUMN IF NOT EXISTS last_scheduled_at DATETIME NULL;
