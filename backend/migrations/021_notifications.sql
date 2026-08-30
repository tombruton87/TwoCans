-- Notifications: email via Mailgun, and a heartbeat for Uptime Kuma.
--
-- One row (id = 1), like trunk and dynamic_dns: config plus the state the
-- notifier needs so it only ever reports each event once.

CREATE TABLE IF NOT EXISTS notifications (
    id                      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    enabled                 TINYINT(1) NOT NULL DEFAULT 0,
    mailgun_api_key_enc     VARBINARY(512) NULL,
    mailgun_region          ENUM('us','eu') NOT NULL DEFAULT 'us',
    mailgun_domain          VARCHAR(190) NOT NULL DEFAULT '',
    mailgun_from            VARCHAR(190) NOT NULL DEFAULT '',
    mailgun_to              VARCHAR(500) NOT NULL DEFAULT '',
    uptime_kuma_url         VARCHAR(500) NOT NULL DEFAULT '',
    notify_asks             TINYINT(1) NOT NULL DEFAULT 1,
    notify_offline          TINYINT(1) NOT NULL DEFAULT 1,
    notify_low_credit       TINYINT(1) NOT NULL DEFAULT 1,
    last_ask_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_low_credit_alerted TINYINT(1) NOT NULL DEFAULT 0,
    last_online_json        TEXT NULL,
    last_error              VARCHAR(255) NULL,
    last_run_at             DATETIME NULL,
    PRIMARY KEY (id),
    CONSTRAINT ck_notifications_single CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO notifications (id) VALUES (1);
