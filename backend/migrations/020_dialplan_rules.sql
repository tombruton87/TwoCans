-- Outbound dial-plan rules: which numbers the kids may dial beyond the allowlist.
--
-- The allowlist (contacts) lets a child reach specific people. These rules let a
-- parent widen that to whole classes of numbers — "any UK mobile" — or keep a
-- class shut — "premium 09 numbers" — without adding every member by hand.
--
-- A rule matches the leading digits a child dials. An "allow" rule routes the
-- call to the trunk (normalised to E.164); a "block" rule stops it the way the
-- catch-all does. Asterisk's own extension matching prefers the longest prefix,
-- so "09" blocking beats a broad "0" allow — no priority column to maintain.
--
-- One rule per prefix: the same prefix cannot be both allowed and blocked.

CREATE TABLE IF NOT EXISTS dialplan_rules (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action     ENUM('allow','block') NOT NULL DEFAULT 'allow',
    prefix     VARCHAR(20)   NOT NULL,
    label      VARCHAR(60)   NOT NULL DEFAULT '',
    sort       INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dialplan_prefix (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
