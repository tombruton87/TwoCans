-- Phones that may hear the house's mailbox by dialling 701 — a grown-up's,
-- say. Off for every phone: a message left for the house may be meant for a
-- grown-up, and a child's phone hears only its own (700).
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS house_messages TINYINT(1) NOT NULL DEFAULT 0;
