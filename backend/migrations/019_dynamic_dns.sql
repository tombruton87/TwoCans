-- Dynamic DNS: keep a name you own pointed at this house.
--
-- Home broadband addresses move without warning, and everything that has to
-- reach twocans from outside — a browser away from home, a certificate renewal,
-- one day a phone registering over the internet — needs a name that follows it.
-- The household owns the domain; twocans owns one record inside it.
--
-- One row, id = 1, exactly like `trunk`: a household has one address, and a
-- table that can only hold one row cannot drift into disagreeing with itself.
-- The API token is encrypted with APP_KEY (see Crypto), because it can rewrite
-- DNS for the whole zone if it leaks.

CREATE TABLE IF NOT EXISTS dynamic_dns (
    id              TINYINT UNSIGNED NOT NULL PRIMARY KEY,   -- always 1
    enabled         TINYINT(1)        NOT NULL DEFAULT 0,
    provider        VARCHAR(32)       NOT NULL DEFAULT 'Cloudflare',

    -- The domain you own, and the full name inside it that points here.
    zone_name       VARCHAR(255)      NULL,                  -- example.com
    zone_id         VARCHAR(64)       NULL,                  -- cached; saves a call per check
    hostname        VARCHAR(255)      NULL,                  -- phone.example.com
    record_id       VARCHAR(64)       NULL,                  -- cached, as above
    record_type     VARCHAR(8)        NOT NULL DEFAULT 'A',

    -- 60 is Cloudflare's floor for an explicit TTL, and low is the point: an
    -- address that has just changed should stop being wrong quickly.
    ttl             SMALLINT UNSIGNED NOT NULL DEFAULT 60,

    -- Proxying (the orange cloud) only understands HTTP, and would hide the
    -- real address from everything else. Off, and there is no reason to move it.
    proxied         TINYINT(1)        NOT NULL DEFAULT 0,

    api_token_enc   VARBINARY(512)    NULL,

    -- What the last check saw. `last_checked_at` is also how the interface knows
    -- the checks are still happening at all.
    last_ip         VARCHAR(45)       NULL,
    last_checked_at DATETIME          NULL,
    last_updated_at DATETIME          NULL,
    last_error      VARCHAR(255)      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO dynamic_dns (id, enabled) VALUES (1, 0);
