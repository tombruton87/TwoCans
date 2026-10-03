-- More Yealink phones (see YealinkProvisioning): W70B and W52P cordless bases
-- (a handset on each a phone of its own), and T31G, T33G, T46U and T54W desk
-- phones. Untested.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h','spa112','spa122','ata191','ata192',
    'vvx150','vvx201','vvx250','vvx300','vvx350','vvx400','vvx450','vvx500','vvx600','ga10',
    'w70b','w52p','t31g','t33g','t46u','t54w') NOT NULL DEFAULT 'linphone';
