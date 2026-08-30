-- The joke line: audio a child can dial in and hear.
--
-- Audio lives on disk (storage/jokes) rather than in the database, for the same
-- reason recordings do — Asterisk plays a file path, and a blob would mean
-- writing it back out on every call.
--
-- `transcript` is what a parent reads on screen, and it is editable. Whisper is
-- reliably wrong about puns, which is most of what a children's joke is, so the
-- machine transcript is a starting point and never the last word.

CREATE TABLE IF NOT EXISTS jokes (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    audio_file       VARCHAR(64)  NOT NULL,
    transcript       TEXT         NULL,
    duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    enabled          TINYINT(1)   NOT NULL DEFAULT 1,
    source_name      VARCHAR(255) NULL,

    transcript_status ENUM('pending','running','done','failed','skipped')
                      NOT NULL DEFAULT 'pending',
    transcript_engine VARCHAR(48)  NULL,
    transcript_error  VARCHAR(255) NULL,
    transcript_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    transcribed_at    DATETIME     NULL,

    added_by         INT          NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_jokes_file (audio_file),
    KEY ix_jokes_transcript (transcript_status, transcript_attempts),
    -- The dialplan only ever plays enabled jokes, and the list page orders by
    -- newest first.
    KEY ix_jokes_enabled (enabled, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
