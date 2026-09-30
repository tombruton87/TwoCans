-- Which of the line's numbers calls go out from. The line's own choice for
-- every phone (trunk.outgoing_number; NULL is its first number, as before),
-- and a phone's own (devices.outgoing_number; NULL is automatic: the number
-- pointed at that phone, if any, else the line's). See
-- TrunkRepository::outgoingNumberFor().
ALTER TABLE trunk
  ADD COLUMN IF NOT EXISTS outgoing_number VARCHAR(20) NULL;
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS outgoing_number VARCHAR(20) NULL;
