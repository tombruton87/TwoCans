-- Hits against the routes that have no login behind them.
--
-- /hook/announce/<token> and /hello/<token> are reachable by anyone holding
-- the link. The 128-bit token makes guessing hopeless, but a link that leaks
-- — into a Home Assistant config, a screenshot, a proxy log — lets a stranger
-- page the house on repeat or push 40MB uploads until the disk is full.
--
-- One row per hit, counted over a window. Old rows are swept as they are read,
-- so nothing needs a cron job.
CREATE TABLE IF NOT EXISTS rate_hits (
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket  VARCHAR(80)  NOT NULL,   -- what is being limited, plus its token
  at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rate_bucket (bucket, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
