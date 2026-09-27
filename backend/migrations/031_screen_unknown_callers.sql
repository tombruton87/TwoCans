-- Screening the calls that come in from numbers nobody recognises.
--
-- twocans already refuses an inbound caller who is not on the call list: the
-- line answers, a recording says so in the household's own voice, and the call
-- ends. What that can't do is let the caller say anything — and a wrong number
-- and a grandparent's new mobile look identical on the way in.
--
-- So the refusal can now end at the house mailbox instead of a hang-up, and the
-- messages left there are listed on the dashboard until a grown-up decides:
-- added to the call list, or junk. These columns record that decision, in the
-- same shapes call_requests already uses for its own (resolution, resolved_by,
-- resolved_at), so the screening queue behaves the way the ask-to-call queue
-- does.
--
-- The row is kept either way. The message is somebody's real words, and the
-- decision is what stops it asking again; deleting it would only mean the same
-- number producing another card the next time it rings.

ALTER TABLE voicemails
  ADD COLUMN IF NOT EXISTS resolution  ENUM('approved','junk') NULL AFTER heard,
  ADD COLUMN IF NOT EXISTS resolved_by INT UNSIGNED NULL AFTER resolution,
  ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER resolved_by;

-- The dashboard asks one question: messages from numbers nobody recognises that
-- nobody has ruled on yet. contact_id and resolution are the filters, left_at
-- the sort, so they are indexed in that order.
ALTER TABLE voicemails
  ADD KEY IF NOT EXISTS ix_voicemails_screening (contact_id, resolution, left_at);
