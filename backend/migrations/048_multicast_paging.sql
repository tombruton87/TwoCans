-- Paging a Grandstream desk phone the way it pages natively: a message played
-- straight through its speaker over multicast RTP (see Pager), not a call it
-- answers. Only once the phone has fetched a settings file telling it where to
-- listen, from this house's own network — set when it does, so a phone not
-- yet rebooted, or away from home, is still paged with a call.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS paging_multicast TINYINT(1) NOT NULL DEFAULT 0;
