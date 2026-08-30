-- "Asks to call" — a child trying to reach somebody who isn't on the list yet.
--
-- The table itself comes from the baseline schema, which anticipated this
-- feature; what was missing was anywhere to put the child's own account of who
-- they were ringing. When a blocked number is dialled the line now invites them
-- to say who they meant, and that recording is what turns a dead end into a
-- request a grown-up can say yes to.
--
-- Column names follow the baseline's (label, attempts, resolution) rather than
-- introducing a second vocabulary for the same idea.

ALTER TABLE call_requests
  ADD COLUMN IF NOT EXISTS recording_path VARCHAR(255) NULL AFTER label,
  ADD COLUMN IF NOT EXISTS transcript_status ENUM('pending','running','done','failed','skipped')
      NOT NULL DEFAULT 'skipped' AFTER recording_path,
  ADD COLUMN IF NOT EXISTS transcript_engine   VARCHAR(48)  NULL AFTER transcript_status,
  ADD COLUMN IF NOT EXISTS transcript_error    VARCHAR(255) NULL AFTER transcript_engine,
  ADD COLUMN IF NOT EXISTS transcript_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER transcript_error,
  ADD COLUMN IF NOT EXISTS transcribed_at      DATETIME     NULL AFTER transcript_attempts,
  -- When it was last tried, as opposed to when it was first asked.
  ADD COLUMN IF NOT EXISTS last_asked_at DATETIME NULL AFTER requested_at;

UPDATE call_requests SET last_asked_at = requested_at WHERE last_asked_at IS NULL;

-- One row per number: a child dialling the same number all afternoon is one
-- ask, not fifteen. Safe to add — the table is only written through the
-- importer, which upserts on exactly this key.
ALTER TABLE call_requests
  ADD UNIQUE KEY IF NOT EXISTS uq_request_number (number_e164),
  ADD KEY IF NOT EXISTS ix_requests_transcript (transcript_status, transcript_attempts);
