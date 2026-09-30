-- The household's own music on hold: tracks a grown-up uploads, played (in
-- turn) to whoever a phone puts on hold, instead of the built-in set. See
-- HoldMusic.
CREATE TABLE IF NOT EXISTS hold_music (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    audio_file  VARCHAR(64)  NOT NULL,
    name        VARCHAR(120) NOT NULL DEFAULT '',
    seconds     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
