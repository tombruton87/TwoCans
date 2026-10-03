-- The games line: which game each noted game was (see Games::GAMES). Every
-- game noted before it was the times tables quiz.
ALTER TABLE quiz_games
  ADD COLUMN IF NOT EXISTS game VARCHAR(16) NOT NULL DEFAULT 'times' AFTER uniqueid;
