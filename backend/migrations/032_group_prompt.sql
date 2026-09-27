-- What a grown-up hears when a group call rings them.
--
-- Every answered group leg has to press 1 before it joins, so a mobile's
-- voicemail can't talk into the conference. The prompt asking for that is a
-- recording: one for the whole house (in settings), and optionally one per
-- group here — "Maeva is calling the grannies, press 1 to join" — which wins
-- when set. With neither, Asterisk's stock "press 1 to accept" plays.

ALTER TABLE contacts
  ADD COLUMN IF NOT EXISTS group_prompt VARCHAR(64) NULL AFTER always_ring,
  ADD COLUMN IF NOT EXISTS group_prompt_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER group_prompt;
