-- The radio's songs in an order of the household's choosing — played in that
-- order when the radio isn't shuffled (see Radio). Songs already on it keep
-- the order they were added in.
ALTER TABLE radio_songs
  ADD COLUMN IF NOT EXISTS position INT UNSIGNED NOT NULL DEFAULT 0 AFTER enabled;

UPDATE radio_songs SET position = id WHERE position = 0;
