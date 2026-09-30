-- Grandstream phones and adapters twocans has spotted but not been told
-- about: ones that asked for a settings file with a MAC it doesn't know, and
-- ones a scan of the home network found (see Pager::scan). The add-a-phone
-- wizard offers them, so nobody copies a MAC off a label.
CREATE TABLE IF NOT EXISTS found_phones (
    mac       CHAR(12)     NOT NULL PRIMARY KEY,
    ip        VARCHAR(45)  NOT NULL DEFAULT '',
    model     VARCHAR(40)  NOT NULL DEFAULT '',
    firmware  VARCHAR(40)  NOT NULL DEFAULT '',
    source    VARCHAR(10)  NOT NULL DEFAULT 'fetch',
    seen_at   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
