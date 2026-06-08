-- schema_avatar.sql — character avatar choice from the landing page
--   run in phpMyAdmin SQL tab

ALTER TABLE players ADD COLUMN avatar TINYINT NOT NULL DEFAULT 1;
