-- Settings values can be longer than 255 characters.
--
-- Most are a word or a time, but some are lists: the weekly bedtime schedule
-- (migration 035) and the Home Assistant bridge's record of the entities it
-- has published, which grows with every phone and announcement.
ALTER TABLE settings MODIFY value TEXT NOT NULL;
