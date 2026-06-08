-- schema_bank.sql — loan balance for the Bank
--   run in phpMyAdmin SQL tab

ALTER TABLE players ADD COLUMN loan BIGINT NOT NULL DEFAULT 0;
