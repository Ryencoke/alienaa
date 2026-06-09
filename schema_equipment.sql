-- schema_equipment.sql — equippable gear (weapons/armor) + combat stats
--   run in phpMyAdmin SQL tab (after schema_bazaar/foundry)

ALTER TABLE items   ADD COLUMN slot VARCHAR(16) NOT NULL DEFAULT '';   -- 'weapon' | 'armor' | ''
ALTER TABLE items   ADD COLUMN atk  INT NOT NULL DEFAULT 0;
ALTER TABLE items   ADD COLUMN def  INT NOT NULL DEFAULT 0;
ALTER TABLE players ADD COLUMN equipped_weapon INT NULL;
ALTER TABLE players ADD COLUMN equipped_armor  INT NULL;

-- Gear items
INSERT INTO items (code,name,category,tier,base_value,slot,atk,def,descr) VALUES
  ('shiv',       'Scrap Shiv',   'gear', 1,  40, 'weapon',  4, 0, 'A sharpened bit of hull. Better than fists.'),
  ('volt_blade', 'Volt Blade',   'gear', 3, 260, 'weapon', 12, 0, 'Hums with stolen current.'),
  ('plate_vest', 'Plated Vest',  'gear', 2, 120, 'armor',   0, 5, 'Riot plating stitched into a jacket.'),
  ('mesh_rig',   'Mesh Rig',     'gear', 3, 300, 'armor',   0,11, 'Woven conductive mesh that eats impacts.');

-- Recipes to craft them (Fabrication-gated, made at the Foundry)
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_shiv','Forge a Scrap Shiv','fab',0,id,1,15,'Bash scrap into something pointy.' FROM items WHERE code='shiv';
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_vest','Stitch a Plated Vest','fab',25,id,1,28,'Bolt riot plating into a jacket.' FROM items WHERE code='plate_vest';
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_volt','Build a Volt Blade','fab',60,id,1,50,'Wire a cell into a blade. Mind the sparks.' FROM items WHERE code='volt_blade';
INSERT INTO recipes (code,name,skill_code,skill_req,out_item_id,out_qty,xp_reward,descr)
  SELECT 'fab_mesh','Weave a Mesh Rig','fab',60,id,1,55,'Weave conductive mesh into armor.' FROM items WHERE code='mesh_rig';

-- Recipe inputs (all reference items seeded in schema_bazaar.sql)
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_shiv'), (SELECT id FROM items WHERE code='scrap_alloy'), 2;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_vest'), (SELECT id FROM items WHERE code='scrap_alloy'), 4;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_vest'), (SELECT id FROM items WHERE code='copper_wire'), 1;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_volt'), (SELECT id FROM items WHERE code='power_cell'), 1;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_volt'), (SELECT id FROM items WHERE code='circuit_dust'), 2;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_volt'), (SELECT id FROM items WHERE code='copper_wire'), 3;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_mesh'), (SELECT id FROM items WHERE code='circuit_dust'), 3;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_mesh'), (SELECT id FROM items WHERE code='power_cell'), 1;
INSERT INTO recipe_inputs (recipe_id,item_id,qty)
  SELECT (SELECT id FROM recipes WHERE code='fab_mesh'), (SELECT id FROM items WHERE code='copper_wire'), 4;
