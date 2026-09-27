-- Announcements: a button that pages the phones with a recorded message —
-- "dinner's ready", "five minutes till bedtime".
--
--   mode        ring — the phones ring and the message plays when answered
--               auto — the phones are asked to pick up by themselves (intercom
--                      headers); a phone that can't just rings instead
--   device_ids  JSON list of phones; NULL is every phone
--   repeat_play play the message twice, for a phone in the next room
--   token       the secret in its trigger URL (/hook/announce/<token>), so
--               Home Assistant, IFTTT or Uptime Kuma can press the button
CREATE TABLE IF NOT EXISTS announcements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label         VARCHAR(60)  NOT NULL DEFAULT '',
    emoji         VARCHAR(16)  NOT NULL DEFAULT '📣',
    audio_file    VARCHAR(64)  NULL,
    audio_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    mode          ENUM('ring', 'auto') NOT NULL DEFAULT 'auto',
    device_ids    TEXT NULL,
    repeat_play   TINYINT(1) NOT NULL DEFAULT 0,
    token         CHAR(32)     NOT NULL,
    last_sent_at  DATETIME     NULL,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_announcements_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
