-- schema_profile_bio.sql — public profile tagline/bio
--   run in phpMyAdmin SQL tab

ALTER TABLE players ADD COLUMN bio VARCHAR(200) NOT NULL DEFAULT '';
