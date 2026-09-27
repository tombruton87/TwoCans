-- Weekly timetables and call limits.
--
-- A phone's hours and a contact's custom call window were one from–until pair,
-- the same every day. Each can now be a schedule — days of the week with their
-- own times, as JSON in the shape Schedule reads. NULL keeps the old pair
-- (time_from/time_to, window_from/window_to) as an every-day rule, so nothing
-- changes until somebody edits it. Bedtime's schedule lives in settings.
--
-- Call limits are per phone, in minutes: the longest a single call may run,
-- and the most talking in one day. NULL is no limit. Emergency numbers, SOS
-- contacts and "always put through" contacts are never cut off — see
-- PjsipConfig::renderLimits().

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS hours_schedule TEXT NULL AFTER time_to,
  ADD COLUMN IF NOT EXISTS max_call_minutes SMALLINT UNSIGNED NULL AFTER hours_schedule,
  ADD COLUMN IF NOT EXISTS daily_minutes SMALLINT UNSIGNED NULL AFTER max_call_minutes;

ALTER TABLE contacts
  ADD COLUMN IF NOT EXISTS window_schedule TEXT NULL AFTER window_to;
