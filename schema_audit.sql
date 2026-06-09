-- ============================================================================
-- schema_audit.sql — idempotent full-schema audit for Sprawl-9.
-- Safe to run on a live DB at any time: it only creates what's missing.
-- Requires MariaDB 10.0.2+ (GoDaddy). Run in phpMyAdmin -> SQL tab.
-- ============================================================================

-- ---------- core tables (full current definitions) ----------
CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) UNIQUE NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  level INT NOT NULL DEFAULT 1,
  creds_pocket BIGINT NOT NULL DEFAULT 500,
  creds_bank BIGINT NOT NULL DEFAULT 0,
  shards INT NOT NULL DEFAULT 0,
  integrity INT NOT NULL DEFAULT 15,
  integrity_max INT NOT NULL DEFAULT 15,
  xp INT NOT NULL DEFAULT 0,
  xp_next INT NOT NULL DEFAULT 300,
  `signal` INT NOT NULL DEFAULT 10,
  signal_max INT NOT NULL DEFAULT 10,
  cycles INT NOT NULL DEFAULT 50,
  cycles_max INT NOT NULL DEFAULT 1500,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- columns added by later features (no-op if already present)
ALTER TABLE players ADD COLUMN IF NOT EXISTS role VARCHAR(16) NOT NULL DEFAULT 'member';
ALTER TABLE players ADD COLUMN IF NOT EXISTS chat_color VARCHAR(7) NOT NULL DEFAULT '#c9d1e0';
ALTER TABLE players ADD COLUMN IF NOT EXISTS theme VARCHAR(16) NOT NULL DEFAULT 'neon';
ALTER TABLE players ADD COLUMN IF NOT EXISTS accent_color VARCHAR(7) NOT NULL DEFAULT '';
ALTER TABLE players ADD COLUMN IF NOT EXISTS bio VARCHAR(200) NOT NULL DEFAULT '';
ALTER TABLE players ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE players ADD COLUMN IF NOT EXISTS avatar TINYINT NOT NULL DEFAULT 1;
ALTER TABLE players ADD COLUMN IF NOT EXISTS loan BIGINT NOT NULL DEFAULT 0;
ALTER TABLE players ADD COLUMN IF NOT EXISTS sub_until DATE NULL;
ALTER TABLE players ADD COLUMN IF NOT EXISTS shard_pull_at DATE NULL;
ALTER TABLE players ADD COLUMN IF NOT EXISTS cryo_until DATE NULL;
ALTER TABLE players ADD COLUMN IF NOT EXISTS country VARCHAR(2) NOT NULL DEFAULT '';
ALTER TABLE players ADD COLUMN IF NOT EXISTS signature VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE players ADD COLUMN IF NOT EXISTS equipped_weapon INT NULL;
ALTER TABLE players ADD COLUMN IF NOT EXISTS equipped_armor INT NULL;
ALTER TABLE players ADD COLUMN IF NOT EXISTS sidebar VARCHAR(255) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS skills (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) UNIQUE NOT NULL,
  name VARCHAR(64) NOT NULL, max_pts INT NOT NULL DEFAULT 1000) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS player_skills (
  player_id INT NOT NULL, skill_id INT NOT NULL, points INT NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, skill_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(48) UNIQUE NOT NULL,
  name VARCHAR(96) NOT NULL, category VARCHAR(32) NOT NULL DEFAULT 'misc',
  tier INT NOT NULL DEFAULT 1, base_value BIGINT NOT NULL DEFAULT 0,
  descr VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB;
ALTER TABLE items ADD COLUMN IF NOT EXISTS slot VARCHAR(16) NOT NULL DEFAULT '';
ALTER TABLE items ADD COLUMN IF NOT EXISTS atk INT NOT NULL DEFAULT 0;
ALTER TABLE items ADD COLUMN IF NOT EXISTS def INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS player_items (
  player_id INT NOT NULL, item_id INT NOT NULL, qty INT NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, item_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_listings (
  id INT AUTO_INCREMENT PRIMARY KEY, seller_id INT NOT NULL, item_id INT NOT NULL,
  qty INT NOT NULL, unit_price BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_item (item_id), INDEX idx_seller (seller_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_sales (
  id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, qty INT NOT NULL,
  unit_price BIGINT NOT NULL, seller_id INT NOT NULL, buyer_id INT NOT NULL,
  sold_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_item (item_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gather_nodes (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(48) UNIQUE NOT NULL, name VARCHAR(96) NOT NULL,
  skill_code VARCHAR(32) NOT NULL, skill_req INT NOT NULL DEFAULT 0, item_id INT NOT NULL,
  yield_min INT NOT NULL DEFAULT 1, yield_max INT NOT NULL DEFAULT 1, xp_reward INT NOT NULL DEFAULT 5,
  descr VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB;
ALTER TABLE gather_nodes ADD COLUMN IF NOT EXISTS venue VARCHAR(16) NOT NULL DEFAULT 'foundry';

CREATE TABLE IF NOT EXISTS recipes (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(48) UNIQUE NOT NULL, name VARCHAR(96) NOT NULL,
  skill_code VARCHAR(32) NOT NULL DEFAULT 'fab', skill_req INT NOT NULL DEFAULT 0,
  out_item_id INT NOT NULL, out_qty INT NOT NULL DEFAULT 1, xp_reward INT NOT NULL DEFAULT 15,
  descr VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recipe_inputs (
  recipe_id INT NOT NULL, item_id INT NOT NULL, qty INT NOT NULL,
  PRIMARY KEY (recipe_id, item_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enemies (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(48) UNIQUE NOT NULL, name VARCHAR(96) NOT NULL,
  tier INT NOT NULL DEFAULT 1, level_req INT NOT NULL DEFAULT 1, hp INT NOT NULL, attack INT NOT NULL,
  defense INT NOT NULL DEFAULT 0, creds_min BIGINT NOT NULL DEFAULT 0, creds_max BIGINT NOT NULL DEFAULT 0,
  xp_reward INT NOT NULL DEFAULT 10, loot_item_id INT NULL, loot_chance INT NOT NULL DEFAULT 0,
  descr VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS combat_log (
  id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, enemy_name VARCHAR(96) NOT NULL,
  outcome VARCHAR(8) NOT NULL, dmg_dealt INT NOT NULL DEFAULT 0, dmg_taken INT NOT NULL DEFAULT 0,
  creds_won BIGINT NOT NULL DEFAULT 0, xp_won INT NOT NULL DEFAULT 0,
  fought_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_player (player_id, fought_at)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS casino_log (
  id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, game VARCHAR(16) NOT NULL,
  bet BIGINT NOT NULL, detail VARCHAR(64) NOT NULL, payout BIGINT NOT NULL, net BIGINT NOT NULL,
  played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_player (player_id, played_at)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS board_cats (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, sort INT NOT NULL DEFAULT 0) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS boards (
  id INT AUTO_INCREMENT PRIMARY KEY, cat_id INT NOT NULL, name VARCHAR(96) NOT NULL,
  descr VARCHAR(255) NOT NULL DEFAULT '', sort INT NOT NULL DEFAULT 0) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS topics (
  id INT AUTO_INCREMENT PRIMARY KEY, board_id INT NOT NULL, author_id INT NOT NULL,
  title VARCHAR(160) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_post_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_board (board_id, last_post_at)) ENGINE=InnoDB;
ALTER TABLE topics ADD COLUMN IF NOT EXISTS views INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS posts (
  id INT AUTO_INCREMENT PRIMARY KEY, topic_id INT NOT NULL, author_id INT NOT NULL,
  body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_topic (topic_id, created_at)) ENGINE=InnoDB;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS parent_id INT NULL;

CREATE TABLE IF NOT EXISTS post_votes (
  post_id INT NOT NULL, player_id INT NOT NULL, value TINYINT NOT NULL,
  PRIMARY KEY (post_id, player_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, body VARCHAR(240) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_id (id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS updates (
  id INT AUTO_INCREMENT PRIMARY KEY, author_id INT NOT NULL, body TEXT NOT NULL,
  credit VARCHAR(64) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS update_votes (
  update_id INT NOT NULL, player_id INT NOT NULL, value TINYINT NOT NULL,
  PRIMARY KEY (update_id, player_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY, from_id INT NOT NULL, to_id INT NOT NULL, body TEXT NOT NULL,
  is_read TINYINT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_to (to_id, is_read), INDEX idx_pair (from_id, to_id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tx_log (
  id INT AUTO_INCREMENT PRIMARY KEY, from_id INT NULL, to_id INT NULL, kind VARCHAR(24) NOT NULL,
  amount BIGINT NOT NULL DEFAULT 0, note VARCHAR(160) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_created (created_at)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_log (
  id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, target_id INT NOT NULL,
  field VARCHAR(32) NOT NULL, old_value VARCHAR(255) NOT NULL DEFAULT '', new_value VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_target (target_id, created_at)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (k VARCHAR(48) PRIMARY KEY, v TEXT) ENGINE=InnoDB;

-- ---------- seed catalogs (INSERT IGNORE = no duplicates) ----------
INSERT IGNORE INTO skills (code, name) VALUES
  ('netrun','Netrunning 101'),('hydro','Hydroponics 101'),('scav','Scavenging 101'),('combat','Combat Sim 101'),
  ('chem','Streetchem 101'),('hack','Cryptocracking 101'),('fab','Fabrication 101'),('drone','Drone Piloting 101');

INSERT IGNORE INTO items (code,name,category,tier,base_value,descr) VALUES
  ('scrap_alloy','Scrap Alloy','raw',1,8,'Stripped hull plating.'),
  ('copper_wire','Salvaged Wire','raw',1,5,'Frayed copper from dead walls.'),
  ('circuit_dust','Circuit Dust','raw',1,12,'Ground-up boards.'),
  ('bio_gel','Bio-Gel Cartridge','raw',2,20,'Hydrofarm runoff.'),
  ('data_shard','Cracked Data Shard','data',2,35,'Half-corrupted memory.'),
  ('stim_patch','Streetchem Stim','chem',2,40,'Makes the nights shorter.'),
  ('power_cell','Recharged Power Cell','component',3,75,'Holds a charge. Mostly.'),
  ('drone_frame','Junk Drone Frame','component',3,120,'One rotor from flying.'),
  ('patch_kit','Field Patch Kit','gear',2,60,'Slap-on nanofoam.'),
  ('tunnel_scrap','Tunnel Scrap','raw',1,10,'Compacted junk from the tunnels.'),
  ('conductive_ore','Conductive Ore','raw',2,28,'Veined with charge.'),
  ('rare_isotope','Rare Isotope','raw',4,150,'Faintly warm. Worth a fortune.');
INSERT IGNORE INTO items (code,name,category,tier,base_value,slot,atk,def,descr) VALUES
  ('shiv','Scrap Shiv','gear',1,40,'weapon',4,0,'Better than fists.'),
  ('volt_blade','Volt Blade','gear',3,260,'weapon',12,0,'Hums with current.'),
  ('plate_vest','Plated Vest','gear',2,120,'armor',0,5,'Riot plating in a jacket.'),
  ('mesh_rig','Mesh Rig','gear',3,300,'armor',0,11,'Conductive mesh armor.');

-- gather nodes (foundry + transit)
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'scav_scrap','Strip Hull Plating','scav',0,id,1,3,5,'foundry','Peel alloy off a dead transit car.' FROM items WHERE code='scrap_alloy';
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'scav_wire','Pull Dead Wiring','scav',20,id,1,3,6,'foundry','Rip copper from the walls.' FROM items WHERE code='copper_wire';
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'scav_circuit','Harvest Circuit Dust','scav',60,id,1,2,8,'foundry','Grind scrap boards into dust.' FROM items WHERE code='circuit_dust';
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'hydro_gel','Tap the Hydrofarms','hydro',0,id,1,2,6,'foundry','Siphon bio-gel from the vats.' FROM items WHERE code='bio_gel';
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'mine_scrap','Strip the Service Tunnels','drone',0,id,1,3,6,'transit','Claw out compacted scrap.' FROM items WHERE code='tunnel_scrap';
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'mine_ore','Drill the Conductive Seams','drone',30,id,1,2,12,'transit','Live current, better haul.' FROM items WHERE code='conductive_ore';
INSERT IGNORE INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'mine_isotope','Crack the Sealed Vault','drone',70,id,1,1,30,'transit','Whatever they buried, it pays.' FROM items WHERE code='rare_isotope';

-- recipes
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_patchkit','Assemble Field Patch Kit','fab',0,id,1,12,'Basic field repairs.' FROM items WHERE code='patch_kit';
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_powercell','Recharge a Power Cell','fab',25,id,1,20,'Coax a dead cell back to life.' FROM items WHERE code='power_cell';
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_drone','Rebuild a Junk Drone','fab',75,id,1,40,'Bolt a flyer together.' FROM items WHERE code='drone_frame';
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_shiv','Forge a Scrap Shiv','fab',0,id,1,15,'Bash scrap into something pointy.' FROM items WHERE code='shiv';
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_vest','Stitch a Plated Vest','fab',25,id,1,28,'Bolt riot plating into a jacket.' FROM items WHERE code='plate_vest';
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_volt','Build a Volt Blade','fab',60,id,1,50,'Wire a cell into a blade.' FROM items WHERE code='volt_blade';
INSERT IGNORE INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_mesh','Weave a Mesh Rig','fab',60,id,1,55,'Weave conductive mesh into armor.' FROM items WHERE code='mesh_rig';

-- recipe inputs (PK keeps these unique)
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_patchkit'),(SELECT id FROM items WHERE code='bio_gel'),1;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_patchkit'),(SELECT id FROM items WHERE code='copper_wire'),1;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_powercell'),(SELECT id FROM items WHERE code='circuit_dust'),3;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_powercell'),(SELECT id FROM items WHERE code='copper_wire'),2;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_drone'),(SELECT id FROM items WHERE code='scrap_alloy'),5;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_drone'),(SELECT id FROM items WHERE code='power_cell'),2;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_shiv'),(SELECT id FROM items WHERE code='scrap_alloy'),2;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_vest'),(SELECT id FROM items WHERE code='scrap_alloy'),4;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_vest'),(SELECT id FROM items WHERE code='copper_wire'),1;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_volt'),(SELECT id FROM items WHERE code='power_cell'),1;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_volt'),(SELECT id FROM items WHERE code='circuit_dust'),2;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_volt'),(SELECT id FROM items WHERE code='copper_wire'),3;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_mesh'),(SELECT id FROM items WHERE code='circuit_dust'),3;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_mesh'),(SELECT id FROM items WHERE code='power_cell'),1;
INSERT IGNORE INTO recipe_inputs (recipe_id,item_id,qty) SELECT (SELECT id FROM recipes WHERE code='fab_mesh'),(SELECT id FROM items WHERE code='copper_wire'),4;

-- enemies
INSERT IGNORE INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_scout','Scrapyard Scout Drone',1,1,12,3,0,15,40,12,id,40,'A twitchy camera on rotors.' FROM items WHERE code='scrap_alloy';
INSERT IGNORE INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_sentry','Rusted Sentry Unit',2,3,28,6,1,40,90,25,id,35,'An old security bot, still mean.' FROM items WHERE code='circuit_dust';
INSERT IGNORE INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_enforcer','Grid Enforcer',3,6,55,11,3,100,220,55,id,20,'Corporate muscle with a baton.' FROM items WHERE code='power_cell';
INSERT IGNORE INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,loot_item_id,loot_chance,descr)
  SELECT 'drone_reaper','Firewall Reaper',4,10,100,20,6,250,500,120,id,12,'Sent when they want you gone.' FROM items WHERE code='drone_frame';

-- board categories (explicit ids keep them unique) + boards (guarded by name)
INSERT IGNORE INTO board_cats (id,name,sort) VALUES (1,'Signal',1),(2,'Sprawl Talk',2),(3,'Off-Grid',3);
INSERT INTO boards (cat_id,name,descr,sort) SELECT 1,'Broadcasts','Official word from whoever is still in charge.',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='Broadcasts');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 2,'The Terminal','General chatter about life in the Sprawl.',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='The Terminal');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 2,'New Ghosts','New to the Sprawl? Ask here.',2 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='New Ghosts');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 2,'Bug Reports','Found a glitch in the Grid? Log it here.',3 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='Bug Reports');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 2,'Patch Notes & Ideas','Pitch features, tweaks, and improvements.',4 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='Patch Notes & Ideas');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 2,'Black Market Chatter','Talk trades, prices, and Bazaar deals.',5 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='Black Market Chatter');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 3,'The Static Lounge','Off-topic. Anything and nothing.',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='The Static Lounge');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 3,'Tech & Rigs','Hardware, software, and the rigs you run.',2 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='Tech & Rigs');
INSERT INTO boards (cat_id,name,descr,sort) SELECT 3,'Media Static','Feeds, films, music, and games.',3 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM boards WHERE name='Media Static');
