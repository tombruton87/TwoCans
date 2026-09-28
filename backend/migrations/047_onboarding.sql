-- The Getting started guide (Onboarding): shown to a new household after the
-- Owner is created, until it's finished or put off for later. A household
-- that was already running when this arrived has long since got started, so
-- it's marked finished rather than greeted as new.
INSERT INTO settings (name, value)
SELECT 'onboarding', 'done' FROM DUAL WHERE EXISTS (SELECT 1 FROM guardians)
ON DUPLICATE KEY UPDATE value = value;
