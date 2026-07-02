-- schema_territory.sql — Syndicate Territory (guilds.php).
-- One row per contestable district (keys from territory_engine.php's
-- TERR_DISTRICTS). Also created lazily by guilds.php's ensure_territory_table()
-- on page load, so applying this file is optional but documents the shape.
--   mysql -u <user> -p sprawl9 < schema_territory.sql

CREATE TABLE IF NOT EXISTS syndicate_territory (
  district_key  VARCHAR(24) PRIMARY KEY,          -- e.g. 'docks','exchange'
  controller_id INT NULL,                          -- syndicates.id, or NULL = unclaimed
  fortification DECIMAL(6,2) NOT NULL DEFAULT 0,   -- 0..100
  pot           INT NOT NULL DEFAULT 0,            -- accrued credits awaiting collection
  last_tick     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- for lazy regen + yield
  claimed_at    DATETIME NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_controller (controller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-assault history (drives the district activity feed).
CREATE TABLE IF NOT EXISTS territory_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  district_key  VARCHAR(24) NOT NULL,
  attacker_id   INT NOT NULL,                      -- player id
  syndicate_id  INT NOT NULL,                      -- attacker's syndicate
  outcome       VARCHAR(12) NOT NULL,              -- win / loss / fled / draw / flip
  fort_after    DECIMAL(6,2) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_district (district_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
