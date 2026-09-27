-- Which Twilio Region the line lives in.
--
-- Twilio Regions are isolated: resources created in ie1 are invisible from the
-- default us1 API, and each region issues its own auth token. Talking to the
-- wrong one answers "no such trunk" for a trunk that plainly exists, so the
-- region has to be stored alongside the credentials rather than assumed.
ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS region VARCHAR(16) NOT NULL DEFAULT 'us1' AFTER provider;
