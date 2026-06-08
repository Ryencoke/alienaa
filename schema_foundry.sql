-- schema_foundry.sql — Task 2: Foundry Sector (gather -> craft)
-- Apply AFTER schema_bazaar.sql (reuses the items table & its catalog).
--   mysql -u <user> -p sprawl9 < schema_foundry.sql

-- Crafted-goods catalog addition (raw mats & components already seeded in schema_bazaar.sql).
INSERT INTO items (code, name, category, tier, base_value, descr) VALUES
  ('patch_kit', 'Field Patch Kit', 'gear', 2, 60, 'Slap-on nanofoam. Buys you a few more minutes of uptime.');

-- Gather nodes: skill-gated actions that drop raw items into your stash.
CREATE TABLE gather_nodes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(48) UNIQUE NOT NULL,
  name       VARCHAR(96) NOT NULL,
  skill_code VARCHAR(32) NOT NULL,        -- which skill gates it (skills.code)
  skill_req  INT NOT NULL DEFAULT 0,      -- min points to unlock
  item_id    INT NOT NULL,                -- what you gather
  yield_min  INT NOT NULL DEFAULT 1,
  yield_max  INT NOT NULL DEFAULT 1,
  xp_reward  INT NOT NULL DEFAULT 5,
  descr      VARCHAR(255) NOT NULL DEFAULT '',
  FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

-- Recipes: skill-gated crafts that consume inputs -> produce an output.
CREATE TABLE recipes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(48) UNIQUE NOT NULL,
  name        VARCHAR(96) NOT NULL,
  skill_code  VARCHAR(32) NOT NULL DEFAULT 'fab',
  skill_req   INT NOT NULL DEFAULT 0,
  out_item_id INT NOT NULL,
  out_qty     INT NOT NULL DEFAULT 1,
  xp_reward   INT NOT NULL DEFAULT 15,
  descr       VARCHAR(255) NOT NULL DEFAULT '',
  FOREIGN KEY (out_item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE recipe_inputs (
  recipe_id INT NOT NULL,
  item_id   INT NOT NULL,
  qty       INT NOT NULL,
  PRIMARY KEY (recipe_id, item_id),
  FOREIGN KEY (recipe_id) REFERENCES recipes(id),
  FOREIGN KEY (item_id)   REFERENCES items(id)
) ENGINE=InnoDB;

-- ---- Seed gather nodes (item ids resolved by code) ----
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,descr)
  SELECT 'scav_scrap','Strip Hull Plating','scav',0,id,1,3,5,'Peel alloy off a dead transit car.'
  FROM items WHERE code='scrap_alloy';
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,descr)
  SELECT 'scav_wire','Pull Dead Wiring','scav',20,id,1,3,6,'Rip copper from the walls before someone else does.'
  FROM items WHERE code='copper_wire';
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,descr)
  SELECT 'scav_circuit','Harvest Circuit Dust','scav',60,id,1,2,8,'Grind scrap boards into reactive dust.'
  FROM items WHERE code='circuit_dust';
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,descr)
  SELECT 'hydro_gel','Tap the Hydrofarms','hydro',0,id,1,2,6,'Siphon bio-gel from the vats. Wear gloves.'
  FROM items WHERE code='bio_gel';

-- ---- Seed recipes ----
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_patchkit','Assemble Field Patch Kit','fab',0,id,1,12,'Basic field repairs. Everybody starts here.'
  FROM items WHERE code='patch_kit';
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_powercell','Recharge a Power Cell','fab',25,id,1,20,'Coax a dead cell back to life.'
  FROM items WHERE code='power_cell';
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_drone','Rebuild a Junk Drone','fab',75,id,1,40,'Bolt a flyer back together from scrap and cells.'
  FROM items WHERE code='drone_frame';

-- ---- Recipe inputs ----
-- Field Patch Kit = 1 Bio-Gel + 1 Salvaged Wire
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_patchkit'), (SELECT id FROM items WHERE code='bio_gel'), 1;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_patchkit'), (SELECT id FROM items WHERE code='copper_wire'), 1;
-- Power Cell = 3 Circuit Dust + 2 Salvaged Wire
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_powercell'), (SELECT id FROM items WHERE code='circuit_dust'), 3;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_powercell'), (SELECT id FROM items WHERE code='copper_wire'), 2;
-- Junk Drone Frame = 5 Scrap Alloy + 2 Power Cell
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_drone'), (SELECT id FROM items WHERE code='scrap_alloy'), 5;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_drone'), (SELECT id FROM items WHERE code='power_cell'), 2;
