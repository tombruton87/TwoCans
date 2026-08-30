-- Real authentication: credentials, login auditing, brute-force protection.

-- password_hash already exists in the baseline; add the auth bookkeeping.
ALTER TABLE guardians
  ADD COLUMN IF NOT EXISTS password_set_at DATETIME NULL AFTER password_hash,
  ADD COLUMN IF NOT EXISTS last_login_at   DATETIME NULL AFTER status;

-- Every attempt is recorded, successful or not: this drives the lockout and
-- gives the Owner an audit trail of who has been trying to get in.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email        VARCHAR(190)    NOT NULL,
  ip           VARBINARY(16)   NULL,          -- inet_pton form; NULL if unknown
  successful   TINYINT(1)      NOT NULL DEFAULT 0,
  attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_attempts_email (email, attempted_at),
  KEY ix_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
