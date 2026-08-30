-- Add the GHP621 to the device type enum (introduced in 003_devices.sql).
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp621') NOT NULL DEFAULT 'linphone';
