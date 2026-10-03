-- Yealink W56H cordless handsets, each on a W60B base (see YealinkProvisioning):
-- a handset is a phone of its own, sharing its base's MAC, its port being its
-- handset number, 1 to 8.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h') NOT NULL DEFAULT 'linphone';
