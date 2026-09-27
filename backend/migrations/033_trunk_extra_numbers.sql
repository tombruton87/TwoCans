-- More than one number on the line.
--
-- number_e164 stays the line's main number: it is the caller ID every outgoing
-- call presents, and everything that already reads it keeps working. Any other
-- numbers the provider routes to this trunk are listed here, space-separated in
-- E.164 — a household has a handful at most, so a column of their own beats a
-- table. Incoming calls to any of them are handled the same way.

ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS extra_numbers VARCHAR(500) NULL AFTER number_e164;
