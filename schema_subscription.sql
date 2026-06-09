-- schema_subscription.sql — premium Shards subscription + daily faucet + cryo
--   run in phpMyAdmin SQL tab

ALTER TABLE players ADD COLUMN sub_until     DATE NULL;   -- subscription active through this date
ALTER TABLE players ADD COLUMN shard_pull_at DATE NULL;   -- last daily Shard vault pull
ALTER TABLE players ADD COLUMN cryo_until    DATE NULL;   -- frozen through this date
