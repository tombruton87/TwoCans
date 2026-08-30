-- Real SIP trunk: persist the Twilio connection and verification state.

ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS termination_uri   VARCHAR(255)    NULL        AFTER auth_token_enc,
  ADD COLUMN IF NOT EXISTS last_verified_at  DATETIME        NULL        AFTER termination_uri,
  ADD COLUMN IF NOT EXISTS minutes_this_month INT UNSIGNED   NOT NULL DEFAULT 0 AFTER low_threshold,
  ADD COLUMN IF NOT EXISTS rate              VARCHAR(20)     NULL        AFTER minutes_this_month;
