-- Twilio's Balance API reports an ISO 4217 code ("USD", "GBP", "EUR"), not the
-- symbol this column was sized for. Writing one into CHAR(1) raised MySQL 1406
-- and took the trunk wizard down with a 500, so widen the column and translate
-- the symbols any existing row was storing. Presenter::money() maps the code
-- back to a symbol for display.
ALTER TABLE trunk
  MODIFY COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD';

UPDATE trunk SET currency = 'USD' WHERE currency = '$';
UPDATE trunk SET currency = 'GBP' WHERE currency = '£';
UPDATE trunk SET currency = 'EUR' WHERE currency = '€';
