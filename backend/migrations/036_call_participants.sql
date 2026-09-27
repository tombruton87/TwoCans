-- Who a group call reached.
--
-- A group call is one row in `calls` — the child's — while each grown-up is
-- rung on a leg of their own. Those legs used to be dropped at import, so the
-- log said "called the grannies · 12:04" and nothing about who picked up.
-- Each leg is now tagged by the dialplan (see PjsipConfig::renderGroupRule)
-- and lands here, keyed by its own uniqueid and pointing at the child's.
--
--   joined   — answered and pressed 1: they were in the conversation
--   answered — answered but never pressed 1: a voicemail, or "not now"
--   missed   — never answered
CREATE TABLE IF NOT EXISTS call_participants (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uniqueid         VARCHAR(64)  NOT NULL,
    parent_uniqueid  VARCHAR(64)  NOT NULL,
    contact_id       INT UNSIGNED NULL,
    status           ENUM('joined', 'answered', 'missed') NOT NULL DEFAULT 'missed',
    billsec          INT UNSIGNED NOT NULL DEFAULT 0,
    started_at       DATETIME     NULL,
    UNIQUE KEY uq_call_participants (uniqueid),
    KEY ix_call_participants_parent (parent_uniqueid)
);
