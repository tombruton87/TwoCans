-- Browsers a grown-up has asked to be notified on (Web Push — see WebPush):
-- where to send, and the keys to encrypt for it. A browser that unsubscribes
-- is forgotten the next time a push to it is turned away.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    guardian_id INT UNSIGNED NOT NULL,
    endpoint    VARCHAR(700) NOT NULL,
    p256dh      VARCHAR(120) NOT NULL,
    auth        VARCHAR(60)  NOT NULL,
    label       VARCHAR(80)  NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME    NULL,
    UNIQUE KEY uq_push_endpoint (endpoint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A handset left off the hook is told about once, until it's put back.
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS offhook_notified TINYINT(1) NOT NULL DEFAULT 0;
