-- An announcement's icon can be a Font Awesome icon as well as an emoji:
-- "fa-solid fa-utensils" is longer than a column sized for one emoji.
ALTER TABLE announcements MODIFY emoji VARCHAR(64) NOT NULL DEFAULT '📣';
