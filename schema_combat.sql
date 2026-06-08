-- schema_combat.sql — Combat Sim (PvE drones); shaped to extend to PvP later.
-- Apply AFTER schema_bazaar.sql (loot references the items catalog).
--   mysql -u <user> -p sprawl9 < schema_combat.sql

-- Drone roster. Player stats are derived from level + the `combat` skill, so
-- nothing needs to be added to the players table.
CREATE TABLE enemies (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(48) UNIQUE NOT NULL,
  name         VARCHAR(96) NOT NULL,
  tier         INT NOT NULL DEFAULT 1,
  level_req    INT NOT NULL DEFAULT 1,      -- player level needed to engage
  hp           INT NOT NULL,
  attack       INT NOT NULL,                -- damage per round (before variance)
  defense      INT NOT NULL DEFAULT 0,
  creds_min    BIGINT NOT NULL DEFAULT 0,
  creds_max    BIGINT NOT NULL DEFAULT 0,
  xp_reward    INT NOT NULL DEFAULT 10,
  loot_item_id INT NULL,                    -- optional drop
  loot_chance  INT NOT NULL DEFAULT 0,      -- percent (0-100)
  descr        VARCHAR(255) NOT NULL DEFAULT '',
  FOREIGN KEY (loot_item_id) REFERENCES items(id)
) ENGINE=InnoDB;

-- Per-fight history (powers the "recent fights" feed; foundation for PvP records).
CREATE TABLE combat_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  player_id  INT NOT NULL,
  enemy_name VARCHAR(96) NOT NULL,
  outcome    VARCHAR(8) NOT NULL,           -- win / loss
  dmg_dealt  INT NOT NULL DEFAULT 0,
  dmg_taken  INT NOT NULL DEFAULT 0,
  creds_won  BIGINT NOT NULL DEFAULT 0,
  xp_won     INT NOT NULL DEFAULT 0,
  fought_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_player (player_id, fought_at),
  FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB;

-- Seed drones (loot ids resolved by item code).
INSERT INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_scout','Scrapyard Scout Drone',1,1,12,3,0,15,40,12,id,40,'A twitchy camera on rotors. Barely armed.'
  FROM items WHERE code='scrap_alloy';
INSERT INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_sentry','Rusted Sentry Unit',2,3,28,6,1,40,90,25,id,35,'An old security bot, still mean, still slow.'
  FROM items WHERE code='circuit_dust';
INSERT INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_enforcer','Grid Enforcer',3,6,55,11,3,100,220,55,id,20,'Corporate muscle with a charged baton.'
  FROM items WHERE code='power_cell';
INSERT INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_reaper','Firewall Reaper',4,10,100,20,6,250,500,120,id,12,'The thing they send when they want you gone for good.'
  FROM items WHERE code='drone_frame';
