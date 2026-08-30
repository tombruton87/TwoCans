-- Real call records, imported from Asterisk's CDR output.

ALTER TABLE calls
  ADD COLUMN IF NOT EXISTS uniqueid    VARCHAR(64) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS answered_at DATETIME    NULL AFTER started_at,
  ADD COLUMN IF NOT EXISTS billsec     INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_secs,
  ADD COLUMN IF NOT EXISTS disposition VARCHAR(24) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS dialled     VARCHAR(64) NULL AFTER peer_number;

-- Asterisk's uniqueid is the dedupe key: the CSV is re-read from the start on
-- every import, so re-importing must be a no-op.
ALTER TABLE calls ADD UNIQUE KEY IF NOT EXISTS uq_calls_uniqueid (uniqueid);

-- `blocked` needs to be a real disposition once the rules engine refuses calls.
ALTER TABLE calls MODIFY COLUMN status ENUM('done','blocked','missed') NOT NULL DEFAULT 'missed';
