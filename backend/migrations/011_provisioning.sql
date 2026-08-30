-- One-time provisioning tokens for Linphone QR setup.
--
-- The provisioning XML contains the device's SIP password in clear, because
-- that is what the phone has to be told. So the URL must be worth nothing five
-- minutes later: a random token with a short expiry. A guessable or
-- permanent URL would hand anyone on the LAN a working credential for a
-- child's phone.
CREATE TABLE IF NOT EXISTS provisioning_tokens (
  token      CHAR(48)     NOT NULL,
  device_id  INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  used_by_ip VARBINARY(16) NULL,
  PRIMARY KEY (token),
  KEY ix_provisioning_device (device_id),
  CONSTRAINT fk_provisioning_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
