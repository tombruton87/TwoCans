-- Speech-to-text for call recordings.
--
-- The transcript column already exists; this adds the bookkeeping a background
-- worker needs so it can pick up where it left off and give up on a file that
-- will never work rather than retrying it forever.

ALTER TABLE calls
  ADD COLUMN IF NOT EXISTS transcript_status ENUM('pending','running','done','failed','skipped')
      NOT NULL DEFAULT 'pending' AFTER transcript,
  ADD COLUMN IF NOT EXISTS transcript_engine VARCHAR(48)  NULL AFTER transcript_status,
  ADD COLUMN IF NOT EXISTS transcript_error  VARCHAR(255) NULL AFTER transcript_engine,
  ADD COLUMN IF NOT EXISTS transcript_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER transcript_error,
  ADD COLUMN IF NOT EXISTS transcribed_at    DATETIME     NULL AFTER transcript_attempts;

-- The worker looks for work with this, so give it an index.
ALTER TABLE calls ADD KEY IF NOT EXISTS ix_calls_transcript (transcript_status, transcript_attempts);

-- Calls that already happened have no recording to work from, and calls with a
-- recording should be queued. Anything without audio is skipped, not pending,
-- so the worker never picks it up.
UPDATE calls SET transcript_status = 'skipped' WHERE recording_path IS NULL;
UPDATE calls SET transcript_status = 'pending' WHERE recording_path IS NOT NULL AND transcript IS NULL;
