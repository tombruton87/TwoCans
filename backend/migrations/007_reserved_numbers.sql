-- Move device extensions clear of real service numbers.
--
-- Extensions started at 101, which is the UK police non-emergency line — a
-- child dialling 101 would have reached a handset in the house instead. 111
-- (NHS), 105 and 116xxx have the same problem. Shifting the range to 201+
-- keeps every internal extension out of the way of anything a person might
-- genuinely need to dial.
UPDATE devices
   SET extension = CAST(extension AS UNSIGNED) + 100
 WHERE extension IS NOT NULL
   AND CAST(extension AS UNSIGNED) BETWEEN 100 AND 199;

-- A speed dial must never shadow an emergency or service number either; that
-- is enforced in ContactRepository, but clear out anything already saved.
UPDATE contacts
   SET speed_dial = NULL
 WHERE speed_dial IN ('999', '112', '911', '101', '111', '105', '116');
