-- Grandstream GHP621: per-device hotkeys (one-touch speed dials).
--
-- Each row maps one physical key on a GHP621 to a number to dial — either a
-- contact's E.164 number (copied from the allowlist) or a service number like
-- 700 (voicemail) / 258 (joke line). The number is a snapshot: deleting the
-- contact later does not silently change what a child's hotkey does.

CREATE TABLE IF NOT EXISTS device_hotkeys (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id  INT UNSIGNED NOT NULL,
    key_index  TINYINT UNSIGNED NOT NULL,
    number     VARCHAR(24)  NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hotkey_device_key (device_id, key_index),
    CONSTRAINT fk_hotkey_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
