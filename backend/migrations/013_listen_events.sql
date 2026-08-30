-- Record that a grown-up listened in on a call.
--
-- The UI tells a family "listening in is noted in the call record", so it has
-- to be. Asterisk writes its CDR at hangup, which is after the listening
-- starts, so the event is parked against the channel's uniqueid here and
-- merged into `calls` when the log is imported.
CREATE TABLE IF NOT EXISTS listen_events (
  uniqueid    VARCHAR(64)  NOT NULL,
  guardian_id INT UNSIGNED NULL,
  mode        VARCHAR(16)  NOT NULL DEFAULT 'listen',
  listened_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uniqueid),
  CONSTRAINT fk_listen_guardian FOREIGN KEY (guardian_id) REFERENCES guardians (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How the listening was done, alongside the existing listened_in / listened_by.
ALTER TABLE calls ADD COLUMN IF NOT EXISTS listen_mode VARCHAR(16) NULL AFTER listened_by;
