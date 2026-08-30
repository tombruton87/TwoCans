-- twocans baseline schema.
--
-- Idempotent: safe against a database already created by the old initdb script.
-- Mirrors the shapes the UI renders (see backend/src/Store.php).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- guardians
CREATE TABLE IF NOT EXISTS guardians (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)  NOT NULL,
  email         VARCHAR(190)  NOT NULL,
  password_hash VARCHAR(255)  NULL,
  role          ENUM('Owner','Admin','Viewer') NOT NULL DEFAULT 'Viewer',
  color         CHAR(7)       NOT NULL DEFAULT '#5BC7B8',
  status        ENUM('active','pending') NOT NULL DEFAULT 'pending',
  invited_at    DATETIME      NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_guardians_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ devices
-- One row per Grandstream ATA on the line.
CREATE TABLE IF NOT EXISTS devices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  model         ENUM('HT801','HT802') NOT NULL DEFAULT 'HT802',
  mac           CHAR(12)     NULL,
  sip_username  VARCHAR(64)  NULL,
  sip_secret    VARCHAR(128) NULL,
  online        TINYINT(1)   NOT NULL DEFAULT 0,
  registered    TINYINT(1)   NOT NULL DEFAULT 0,
  last_seen_at  DATETIME     NULL,
  allow_in      TINYINT(1)   NOT NULL DEFAULT 1,
  allow_out     TINYINT(1)   NOT NULL DEFAULT 1,
  time_from     TIME         NOT NULL DEFAULT '15:00:00',
  time_to       TIME         NOT NULL DEFAULT '19:30:00',
  blocked_msg   TEXT         NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_devices_mac (mac),
  UNIQUE KEY uq_devices_sip (sip_username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- contacts
-- The allowlist: nobody outside this table can reach the kids' phones.
CREATE TABLE IF NOT EXISTS contacts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  relationship  VARCHAR(80)  NULL,
  number_e164   VARCHAR(24)  NOT NULL,
  color         CHAR(7)      NOT NULL DEFAULT '#FFC857',
  photo_path    VARCHAR(255) NULL,
  call_window   ENUM('anytime','afterschool','weekends','custom') NOT NULL DEFAULT 'afterschool',
  window_from   TIME         NULL,
  window_to     TIME         NULL,
  allow_in      TINYINT(1)   NOT NULL DEFAULT 1,
  allow_out     TINYINT(1)   NOT NULL DEFAULT 1,
  sos           TINYINT(1)   NOT NULL DEFAULT 0,
  ring_both     TINYINT(1)   NOT NULL DEFAULT 0,
  failover_id   INT UNSIGNED NULL,
  speed_dial    VARCHAR(4)   NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contacts_number (number_e164),
  UNIQUE KEY uq_contacts_speeddial (speed_dial),
  KEY ix_contacts_failover (failover_id),
  CONSTRAINT fk_contacts_failover FOREIGN KEY (failover_id) REFERENCES contacts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------- calls
CREATE TABLE IF NOT EXISTS calls (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id      INT UNSIGNED NULL,
  contact_id     INT UNSIGNED NULL,
  peer_name      VARCHAR(120) NULL,
  peer_number    VARCHAR(24)  NOT NULL,
  direction      ENUM('in','out') NOT NULL,
  status         ENUM('done','blocked','missed') NOT NULL,
  block_reason   VARCHAR(160) NULL,
  started_at     DATETIME     NOT NULL,
  duration_secs  INT UNSIGNED NOT NULL DEFAULT 0,
  recording_path VARCHAR(255) NULL,
  transcript     MEDIUMTEXT   NULL,
  listened_in    TINYINT(1)   NOT NULL DEFAULT 0,
  listened_by    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_calls_started (started_at),
  KEY ix_calls_device (device_id),
  KEY ix_calls_contact (contact_id),
  CONSTRAINT fk_calls_device  FOREIGN KEY (device_id)  REFERENCES devices (id)   ON DELETE SET NULL,
  CONSTRAINT fk_calls_contact FOREIGN KEY (contact_id) REFERENCES contacts (id)  ON DELETE SET NULL,
  CONSTRAINT fk_calls_listener FOREIGN KEY (listened_by) REFERENCES guardians (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- voicemail
CREATE TABLE IF NOT EXISTS voicemails (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id    INT UNSIGNED NULL,
  peer_name     VARCHAR(120) NULL,
  peer_number   VARCHAR(24)  NOT NULL,
  left_at       DATETIME     NOT NULL,
  duration_secs INT UNSIGNED NOT NULL DEFAULT 0,
  heard         TINYINT(1)   NOT NULL DEFAULT 0,
  audio_path    VARCHAR(255) NULL,
  transcript    MEDIUMTEXT   NULL,
  PRIMARY KEY (id),
  KEY ix_vm_left (left_at),
  CONSTRAINT fk_vm_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- ask-to-call requests
CREATE TABLE IF NOT EXISTS call_requests (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id   INT UNSIGNED NULL,
  number_e164 VARCHAR(24)  NOT NULL,
  label       VARCHAR(160) NULL,
  attempts    INT UNSIGNED NOT NULL DEFAULT 1,
  requested_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME    NULL,
  resolution   ENUM('approved','denied') NULL,
  resolved_by  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_requests_open (resolved_at),
  CONSTRAINT fk_requests_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- SIP trunk
CREATE TABLE IF NOT EXISTS trunk (
  id             TINYINT UNSIGNED NOT NULL DEFAULT 1,
  provider       VARCHAR(40)  NOT NULL DEFAULT 'Twilio',
  connected      TINYINT(1)   NOT NULL DEFAULT 0,
  number_e164    VARCHAR(24)  NULL,
  account_sid    VARCHAR(64)  NULL,
  auth_token_enc VARBINARY(512) NULL,   -- store encrypted, never plaintext
  balance        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency       CHAR(1)      NOT NULL DEFAULT '$',
  low_threshold  DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  auto_topup     TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  CONSTRAINT ck_trunk_single CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- settings
-- Global quiet hours and anything else that is one-row-per-household.
CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(64)  NOT NULL,
  value      VARCHAR(255) NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value) VALUES
  ('quiet_hours', '1'),
  ('quiet_from',  '19:30'),
  ('quiet_to',    '07:00');

INSERT IGNORE INTO trunk (id, provider, connected) VALUES (1, 'Twilio', 0);
