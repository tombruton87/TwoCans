-- Caller announcements: "It's Nana" in the child's ear when they pick up.
--
-- A phone with no screen — a corded phone on an adapter, the GHP621 — gives
-- no clue who is ringing. Each contact can have a short clip of their name;
-- a phone with announce_caller on plays it the moment it is answered, before
-- the call connects. On for phones without a screen, off for the Linphone
-- app, which shows the name and photo already.
ALTER TABLE contacts
  ADD COLUMN IF NOT EXISTS announce_clip VARCHAR(64) NULL AFTER group_prompt_seconds,
  ADD COLUMN IF NOT EXISTS announce_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER announce_clip;

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS announce_caller TINYINT(1) NOT NULL DEFAULT 0 AFTER adult_mode;

UPDATE devices SET announce_caller = 1 WHERE type <> 'linphone';
