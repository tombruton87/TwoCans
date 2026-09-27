-- More things worth an email.
--
--   notify_messages   somebody not on the list left a message in the house
--                     mailbox (screening, migration 031)
--   notify_emergency  a phone dialled an emergency number — sent within a
--                     minute of the call starting, on its own, not bundled
--   notify_digest     a summary of the week, on Sunday evening
--
-- The last_* columns are the notifier's bookmarks, so each event is told once.
ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS notify_messages  TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_low_credit,
  ADD COLUMN IF NOT EXISTS notify_emergency TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_messages,
  ADD COLUMN IF NOT EXISTS notify_digest    TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_emergency,
  ADD COLUMN IF NOT EXISTS last_message_id  BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_ask_id,
  ADD COLUMN IF NOT EXISTS last_digest_at   DATETIME NULL AFTER last_message_id;

-- Start at the newest message already here, so switching this on isn't an
-- email about every old one.
UPDATE notifications SET last_message_id = (SELECT COALESCE(MAX(id), 0) FROM voicemails);
