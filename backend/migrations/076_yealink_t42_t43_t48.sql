-- More Yealink desk phones (see YealinkProvisioning): T42U/T42S, T43U and
-- T48U/T48S. Untested.
ALTER TABLE devices
  MODIFY COLUMN type ENUM('linphone','ht801','ht802','ghp610','ghp611','ghp620','ghp621','w56h','spa112','spa122','ata191','ata192',
    'vvx150','vvx201','vvx250','vvx300','vvx350','vvx400','vvx450','vvx500','vvx600','ga10',
    'w70b','w52p','t31g','t33g','t46u','t54w','t42u','t43u','t48u') NOT NULL DEFAULT 'linphone';
