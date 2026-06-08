-- schema_online.sql — track last activity for the "Jacked In" online list
--   run in phpMyAdmin SQL tab

ALTER TABLE players ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL;
