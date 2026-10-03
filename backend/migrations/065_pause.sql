-- A phone paused for a while — homework, tidy-up time: no calls in or out,
-- and none of the fun lines, until this time (emergency numbers and its own
-- messages still work). bin/minute.php lets it go when the time comes.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS paused_until DATETIME NULL;
