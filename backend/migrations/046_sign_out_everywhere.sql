-- Signing a grown-up out everywhere.
--
-- Sign-ins are ordinary PHP sessions holding the account's id, so there was no
-- way to end the ones already open — after a lost phone, or a password someone
-- else knows. Any session started before signed_out_at is refused from then on
-- (Auth::user). Set by bin/reset-owner.php; NULL means none refused.
ALTER TABLE guardians
  ADD COLUMN IF NOT EXISTS signed_out_at DATETIME NULL AFTER last_login_at;
