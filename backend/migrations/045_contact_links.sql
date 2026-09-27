-- Self-service links: a grandparent adds their own photo and name clip.
--
-- A parent makes a link for one person and sends it to them. Opening it needs
-- no account: the random token in the path is the credential, and all it can
-- do is set that one person's photo and name clip — see index.php (/hello/).
-- One link per person; making a new one replaces the old. It stops working at
-- expires_at, or when a parent stops it.
CREATE TABLE IF NOT EXISTS contact_links (
  contact_id   INT UNSIGNED NOT NULL PRIMARY KEY,
  token        CHAR(32)     NOT NULL,
  expires_at   DATETIME     NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at      DATETIME     NULL,
  UNIQUE KEY uq_contact_links_token (token),
  CONSTRAINT fk_contact_links_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
