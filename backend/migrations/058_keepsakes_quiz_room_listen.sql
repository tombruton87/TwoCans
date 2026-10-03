-- Keepsakes: voicemails a family wants to keep for good — a five-year-old
-- saying goodnight to Grandad. Each is a copy of the message's audio in
-- storage/keepsakes, so neither retention nor a child deleting it on the
-- handset takes it away. voicemail_msg_id says where it came from, and stops
-- the same message being kept twice.
CREATE TABLE IF NOT EXISTS keepsakes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    voicemail_msg_id VARCHAR(80)  NULL,
    title            VARCHAR(120) NOT NULL DEFAULT '',
    peer_name        VARCHAR(120) NOT NULL DEFAULT '',
    peer_number      VARCHAR(40)  NOT NULL DEFAULT '',
    mailbox          VARCHAR(16)  NOT NULL DEFAULT '',
    recorded_at      DATETIME     NOT NULL,
    duration_secs    INT UNSIGNED NOT NULL DEFAULT 0,
    transcript       TEXT         NULL,
    audio_file       VARCHAR(80)  NOT NULL,
    kept_by          INT UNSIGNED NULL,
    kept_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_keepsake_msg (voicemail_msg_id),
    KEY ix_keepsake_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The times tables quiz: one row a game, noted by the dialplan as it goes
-- (see PjsipConfig::QUIZ_FAMILY) and brought in by bin/minute.php. table_no
-- is the table a child picked, 0 for a mix.
CREATE TABLE IF NOT EXISTS quiz_games (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uniqueid  VARCHAR(64)  NOT NULL,
    device_id INT UNSIGNED NULL,
    table_no  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    score     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    asked     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    played_at DATETIME     NOT NULL,
    UNIQUE KEY uq_quiz_uniqueid (uniqueid),
    KEY ix_quiz_device (device_id, played_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listening to a room: a grown-up's phone rings a child's, which answers by
-- itself on speaker, one way. Both ends have to be switched on — the room's
-- phone (room_listen) and the phone listening (can_room_listen) — and both are
-- off to begin with. Every listen is noted (see PjsipConfig::ROOM_LISTEN_FAMILY).
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS room_listen TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS can_room_listen TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS room_listens (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uniqueid    VARCHAR(64)  NOT NULL,
    device_id   INT UNSIGNED NULL,
    listener_id INT UNSIGNED NULL,
    listened_at DATETIME     NOT NULL,
    UNIQUE KEY uq_room_listen_uniqueid (uniqueid),
    KEY ix_room_listen_device (device_id, listened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
