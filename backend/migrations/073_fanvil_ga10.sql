-- Fanvil GA10 adapters (see FanvilProvisioning): one corded phone each.
-- Untested — set up from Fanvil's documentation for its newer phones.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h','spa112','spa122','ata191','ata192',
    'vvx150','vvx201','vvx250','vvx300','vvx350','vvx400','vvx450','vvx500','vvx600','ga10') NOT NULL DEFAULT 'linphone';
