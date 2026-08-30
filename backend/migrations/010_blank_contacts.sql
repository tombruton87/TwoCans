-- Allow more than one unsaved contact draft.
--
-- `number_e164` is UNIQUE, and a freshly added contact was created with an
-- empty string — so a parent who opened "Add a person" and closed it without
-- saving could never add anyone again: the next click hit a duplicate-key
-- fatal. NULL is exempt from UNIQUE in MySQL/MariaDB, so drafts can coexist.
ALTER TABLE contacts MODIFY COLUMN number_e164 VARCHAR(24) NULL;
UPDATE contacts SET number_e164 = NULL WHERE number_e164 = '';
