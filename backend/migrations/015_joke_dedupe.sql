-- Stop the same joke going on the line twice.
--
-- The library is built up in batches from folders of audio, and a folder that
-- gets re-added — or a clip exported twice under different names — would
-- otherwise be imported again. The hash is of the *converted* audio rather than
-- the upload: conversion is byte-for-byte deterministic, so the same source
-- always lands on the same hash regardless of what it arrived as.

ALTER TABLE jokes ADD COLUMN IF NOT EXISTS audio_sha256 CHAR(64) NULL AFTER audio_file;

-- NULL is exempt from a UNIQUE index in MariaDB, so rows predating this
-- migration don't block it. bin/backfill-joke-hashes.php fills them in.
ALTER TABLE jokes ADD UNIQUE KEY IF NOT EXISTS uq_jokes_audio_hash (audio_sha256);
