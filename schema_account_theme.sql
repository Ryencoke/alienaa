-- schema_account_theme.sql — per-player theme + accent color
--   run in phpMyAdmin SQL tab (or mysql CLI)

ALTER TABLE players ADD COLUMN theme        VARCHAR(16) NOT NULL DEFAULT 'neon';
ALTER TABLE players ADD COLUMN accent_color VARCHAR(7)  NOT NULL DEFAULT '';
