-- Cisco SPA112 adapters (see CiscoProvisioning): two corded phones, each a
-- phone of its own sharing the adapter's MAC, its port being PHONE 1 or 2.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h','spa112') NOT NULL DEFAULT 'linphone';
