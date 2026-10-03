-- The phone a phone walkie-talkies to (dial 9255, W-A-L-K): it answers by
-- itself on speaker, two-way, with a beep. See PjsipConfig::renderWalkie().
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS walkie_device_id INT UNSIGNED NULL;
