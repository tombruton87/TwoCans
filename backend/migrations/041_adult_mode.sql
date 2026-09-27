-- Adult mode: a phone with no restrictions at all.
--
-- For a grown-up's own handset on the line. It may call any number at any
-- time, and anyone may ring it: no call list, no dial plan rules, no bedtime,
-- no hours, no call limits, and its own in/out switches are not consulted.
-- Enforced in the dialplan (PjsipConfig: TC_ADULT on the phone, and a ring
-- list of its own for incoming calls). Off by default, and only switched on
-- through a confirmation that says all of that.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS adult_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_out;
