-- A person who is never subject to the clock.
--
-- SOS already skips bedtime and the caller's own hours, but it still respects
-- each phone's own opening hours: at 2am every handset is off duty, so even an
-- SOS contact ends up in the house mailbox. This flag is for the handful of
-- people who have to get through whatever the hour — Mum and Dad, a carer, the
-- on-call number — and it makes their call ring every phone that takes calls at
-- all.
--
-- Inbound only, deliberately: it is about them reaching the children, not about
-- what the children may dial. Groups have no flag of their own, because a group
-- can never be an inbound caller.

ALTER TABLE contacts
  ADD COLUMN IF NOT EXISTS always_ring TINYINT(1) NOT NULL DEFAULT 0 AFTER ring_both;
