-- Retention: stop keeping recordings of children's calls forever.
--
-- What expires is the *content* — the audio and the transcript. The call log
-- entry stays, so a parent can still see that Grandma rang on the 3rd of March
-- long after the recording of it has gone. That is the useful half of the
-- record and the harmless half.

ALTER TABLE calls
  ADD COLUMN IF NOT EXISTS content_expired_at DATETIME NULL AFTER transcribed_at;

ALTER TABLE voicemails
  ADD COLUMN IF NOT EXISTS content_expired_at DATETIME NULL AFTER transcript_error;

-- The sweep looks for work with these.
ALTER TABLE calls      ADD KEY IF NOT EXISTS ix_calls_expiry (content_expired_at, started_at);
ALTER TABLE voicemails ADD KEY IF NOT EXISTS ix_vm_expiry (content_expired_at, left_at);

-- A household that already has call history keeps it until somebody chooses a
-- policy: upgrading twocans must never be what deletes a family's recordings.
-- A brand-new install has no calls, so no row is written here and the code
-- default (90 days) applies — privacy by default where there is nothing to lose.
INSERT IGNORE INTO settings (name, value)
SELECT 'retention_days', '0' FROM DUAL WHERE EXISTS (SELECT 1 FROM calls);
