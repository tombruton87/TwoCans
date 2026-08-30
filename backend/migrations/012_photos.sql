-- Profile pictures for phones. Contacts already have `photo_path` from the
-- baseline schema; this gives devices the same.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER display_name;
