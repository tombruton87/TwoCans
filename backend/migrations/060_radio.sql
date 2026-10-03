-- The radio: songs a household uploads, played one after another on a number
-- a child dials (7234, R-A-D-I-O, by default) — see Radio. sha256 stops the
-- same song going on twice.
CREATE TABLE IF NOT EXISTS radio_songs (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(120) NOT NULL DEFAULT '',
    audio_file VARCHAR(80)  NOT NULL,
    seconds    INT UNSIGNED NOT NULL DEFAULT 0,
    sha256     CHAR(64)     NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_radio_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
