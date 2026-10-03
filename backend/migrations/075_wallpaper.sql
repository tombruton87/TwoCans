-- A phone's own wallpaper, for a desk phone with a colour screen (see
-- YealinkProvisioning::WALLPAPER): a picture kept by PhotoStore.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS wallpaper_path VARCHAR(64) NULL AFTER photo_path;
