-- Tell "no credit" apart from "cannot read the credit".
--
-- Twilio does not serve Balance.json in every region (ie1 answers 404), and the
-- client used to swallow that and report 0.00. A fabricated zero is worse than
-- no figure at all: it reads as an empty account and trips the low-credit
-- warning. NULL now means unknown, and the screens say so.
ALTER TABLE trunk
  MODIFY COLUMN balance DECIMAL(10,2) NULL DEFAULT NULL;
