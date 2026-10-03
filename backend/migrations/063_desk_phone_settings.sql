-- More for the desk phones (GrandstreamProvisioning): its ring volume, a
-- number it rings when its handset's picked up and nothing's pressed (the
-- "hotline", after hotline_delay seconds), and what the phone has told
-- twocans about itself: when it last started up, and since when its handset
-- has been off the hook (null when it's on).
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS ring_volume TINYINT UNSIGNED NOT NULL DEFAULT 4,
  ADD COLUMN IF NOT EXISTS hotline_number VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS hotline_delay TINYINT UNSIGNED NOT NULL DEFAULT 4,
  ADD COLUMN IF NOT EXISTS started_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS offhook_since DATETIME NULL;
