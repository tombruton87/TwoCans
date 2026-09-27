-- The message a caller who isn't on the list hears.
--
-- Until now this was `blocked_msg`: text typed into a box on the phone's page
-- that nothing ever read. Asterisk can only play a file and there is no
-- text-to-speech on this box, so the box was decoration.
--
-- The message is a recording now — one per phone — and the transcript is what
-- the app shows back, filled in from the audio by the transcription worker.
-- Everything typed into the old box is carried over as the starting transcript:
-- a household that had written its wording down keeps it on screen, ready to
-- read out next time they record.

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS refusal_audio VARCHAR(64) NULL AFTER blocked_msg,
  ADD COLUMN IF NOT EXISTS refusal_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER refusal_audio,
  ADD COLUMN IF NOT EXISTS refusal_transcript TEXT NULL AFTER refusal_seconds,
  -- 'skipped' by default: a phone with no recording has nothing to transcribe,
  -- and the worker only ever picks up rows with audio.
  ADD COLUMN IF NOT EXISTS refusal_status ENUM('pending','running','done','failed','skipped')
      NOT NULL DEFAULT 'skipped' AFTER refusal_transcript,
  ADD COLUMN IF NOT EXISTS refusal_engine VARCHAR(48) NULL AFTER refusal_status,
  ADD COLUMN IF NOT EXISTS refusal_error VARCHAR(255) NULL AFTER refusal_engine,
  ADD COLUMN IF NOT EXISTS refusal_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER refusal_error,
  ADD COLUMN IF NOT EXISTS refusal_transcribed_at DATETIME NULL AFTER refusal_attempts;

UPDATE devices SET refusal_transcript = blocked_msg WHERE blocked_msg IS NOT NULL AND blocked_msg <> '';

ALTER TABLE devices DROP COLUMN IF EXISTS blocked_msg;

-- The worker looks for work with this, so give it an index.
ALTER TABLE devices ADD KEY IF NOT EXISTS ix_devices_refusal (refusal_status, refusal_attempts);
