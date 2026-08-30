-- Voicemail, backed by Asterisk's app_voicemail.

-- Each phone gets a mailbox and a PIN. The PIN is only needed to pick messages
-- up from somewhere other than the phone itself — dialling 700 from your own
-- handset skips it, because a child should not have to remember a number to
-- hear a message left for them.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS voicemail_pin CHAR(4) NULL AFTER extension;

UPDATE devices SET voicemail_pin = LPAD(FLOOR(RAND() * 10000), 4, '0') WHERE voicemail_pin IS NULL;

-- Messages are imported from the spool so they can be transcribed and shown.
-- Asterisk stays the source of truth for the audio itself.
ALTER TABLE voicemails
  ADD COLUMN IF NOT EXISTS device_id  INT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS mailbox    VARCHAR(16)  NULL AFTER device_id,
  ADD COLUMN IF NOT EXISTS msg_id     VARCHAR(64)  NULL AFTER mailbox,
  ADD COLUMN IF NOT EXISTS folder     VARCHAR(16)  NOT NULL DEFAULT 'INBOX' AFTER msg_id,
  ADD COLUMN IF NOT EXISTS transcript_status ENUM('pending','running','done','failed','skipped')
      NOT NULL DEFAULT 'pending' AFTER transcript,
  ADD COLUMN IF NOT EXISTS transcript_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER transcript_status,
  ADD COLUMN IF NOT EXISTS transcript_error VARCHAR(255) NULL AFTER transcript_attempts;

-- Asterisk's own message id is stable across INBOX -> Old, which renaming the
-- file is not: listening on the handset moves and renumbers it.
ALTER TABLE voicemails ADD UNIQUE KEY IF NOT EXISTS uq_voicemails_msgid (msg_id);
ALTER TABLE voicemails ADD KEY IF NOT EXISTS ix_voicemails_transcript (transcript_status, transcript_attempts);
