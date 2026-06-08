-- schema_transit.sql — Transit Hub: mining (extends gather_nodes) + ore items.
-- Apply AFTER schema_foundry.sql (it adds a column to gather_nodes).
--   mysql -u <user> -p sprawl9 < schema_transit.sql
-- NOTE: pages/foundry.php now filters nodes by venue='foundry', so this
-- migration must be applied for the Foundry to keep listing its nodes.

-- Tag gather nodes by venue so the Foundry and the Transit Hub show different lists.
-- Existing Foundry nodes default to 'foundry' automatically.
ALTER TABLE gather_nodes ADD COLUMN venue VARCHAR(16) NOT NULL DEFAULT 'foundry';

-- New ore items hauled out of the service tunnels.
INSERT INTO items (code, name, category, tier, base_value, descr) VALUES
  ('tunnel_scrap',   'Tunnel Scrap',   'raw', 1,  10, 'Compacted junk torn out of the old service tunnels.'),
  ('conductive_ore', 'Conductive Ore', 'raw', 2,  28, 'Veined with something that still carries a charge.'),
  ('rare_isotope',   'Rare Isotope',   'raw', 4, 150, 'Faintly warm. Definitely not regulation. Worth a fortune.');

-- Mining nodes (gated by Drone Piloting; rendered at the Transit Hub).
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'mine_scrap','Strip the Service Tunnels','drone',0,id,1,3,6,'transit','Send a rig down to claw out compacted scrap.'
  FROM items WHERE code='tunnel_scrap';
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'mine_ore','Drill the Conductive Seams','drone',30,id,1,2,12,'transit','Deeper veins, live current, a better haul.'
  FROM items WHERE code='conductive_ore';
INSERT INTO gather_nodes (code,name,skill_code,skill_req,item_id,yield_min,yield_max,xp_reward,venue,descr)
  SELECT 'mine_isotope','Crack the Sealed Vault','drone',70,id,1,1,30,'transit','Whatever they buried down here, it pays.'
  FROM items WHERE code='rare_isotope';
