-- Cisco SPA122 adapters: an SPA112 with a router built in, set up the same
-- way (see CiscoProvisioning).
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h','spa112','spa122') NOT NULL DEFAULT 'linphone';
