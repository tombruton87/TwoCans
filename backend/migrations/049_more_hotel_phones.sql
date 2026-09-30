-- The rest of Grandstream's compact hotel phones. They share the GHP621's
-- settings file; the GHP610/611 have three hotkeys to its six, and the
-- GHP610/620 are the white ones. See DeviceRepository::TYPES.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621') NOT NULL DEFAULT 'linphone';
