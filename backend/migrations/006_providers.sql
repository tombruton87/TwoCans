-- Second provider: SIP.IO. Its API key is stored encrypted like the Twilio
-- auth token; the SIP edge host (proxy) is where Asterisk sends outbound calls.

ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS api_key_enc VARBINARY(512) NULL AFTER auth_token_enc,
  ADD COLUMN IF NOT EXISTS sip_proxy   VARCHAR(255)   NULL AFTER api_key_enc;
