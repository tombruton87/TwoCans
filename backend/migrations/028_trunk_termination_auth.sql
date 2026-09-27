-- Username/password for the provider's termination side.
--
-- Twilio authenticates outbound calls by either a source-IP allowlist or a
-- credential list. twocans only ever assumed the former, so a trunk set up
-- with credentials got challenged for digest auth and Asterisk had nothing to
-- answer with ("There were no auth ids available"). Credentials also suit a
-- home line better: an IP allowlist breaks every time the ISP renumbers you.
--
-- Stored encrypted, like the provider tokens. Blank means IP authentication.
ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS termination_username VARCHAR(64) NULL AFTER termination_uri,
  ADD COLUMN IF NOT EXISTS termination_password_enc VARBINARY(512) NULL AFTER termination_username;
