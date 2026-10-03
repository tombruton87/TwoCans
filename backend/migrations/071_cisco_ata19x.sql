-- Cisco ATA 191 and ATA 192 adapters, on Multiplatform firmware (see
-- CiscoProvisioning): two corded phones each, like the SPA112 and SPA122.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h','spa112','spa122','ata191','ata192') NOT NULL DEFAULT 'linphone';
