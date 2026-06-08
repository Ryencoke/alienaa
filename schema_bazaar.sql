-- schema_bazaar.sql — Task 1: The Bazaar marketplace
-- Apply to the existing `sprawl9` database AFTER schema.sql.
--   mysql -u <user> -p sprawl9 < schema_bazaar.sql

-- Item catalog (definitions). Add rows as the game grows.
CREATE TABLE items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(48)  UNIQUE NOT NULL,
  name       VARCHAR(96)  NOT NULL,
  category   VARCHAR(32)  NOT NULL DEFAULT 'misc',  -- raw, component, gear, chem, data...
  tier       INT          NOT NULL DEFAULT 1,
  base_value BIGINT       NOT NULL DEFAULT 0,        -- reference value (vendor/floor price)
  descr      VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

-- What each player is carrying. One row per (player, item); qty 0 rows get pruned.
CREATE TABLE player_items (
  player_id INT NOT NULL,
  item_id   INT NOT NULL,
  qty       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, item_id),
  FOREIGN KEY (player_id) REFERENCES players(id),
  FOREIGN KEY (item_id)   REFERENCES items(id)
) ENGINE=InnoDB;

-- Active market listings. Listed qty is held HERE in escrow
-- (already removed from player_items at listing time).
CREATE TABLE market_listings (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  seller_id  INT    NOT NULL,
  item_id    INT    NOT NULL,
  qty        INT    NOT NULL,
  unit_price BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (seller_id) REFERENCES players(id),
  FOREIGN KEY (item_id)   REFERENCES items(id),
  INDEX idx_item   (item_id),
  INDEX idx_seller (seller_id)
) ENGINE=InnoDB;

-- Completed sales — powers the "market average" lookup and future price charts.
-- (Optional 4th table; remove it and the avg_sale subquery in bazaar.php if you
--  prefer to stay at exactly three tables.)
CREATE TABLE market_sales (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  item_id    INT    NOT NULL,
  qty        INT    NOT NULL,
  unit_price BIGINT NOT NULL,
  seller_id  INT    NOT NULL,
  buyer_id   INT    NOT NULL,
  sold_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(id),
  INDEX idx_item (item_id),
  INDEX idx_sold (sold_at)
) ENGINE=InnoDB;

-- Starter item catalog so the Bazaar (and later the Foundry) have things to trade.
INSERT INTO items (code, name, category, tier, base_value, descr) VALUES
  ('scrap_alloy',  'Scrap Alloy',         'raw',       1,   8, 'Stripped hull plating. The Sprawl runs on this.'),
  ('copper_wire',  'Salvaged Wire',       'raw',       1,   5, 'Frayed copper pulled from dead walls.'),
  ('circuit_dust', 'Circuit Dust',        'raw',       1,  12, 'Ground-up boards. Smells like ozone and regret.'),
  ('bio_gel',      'Bio-Gel Cartridge',   'raw',       2,  20, 'Hydrofarm runoff, mildly toxic, very useful.'),
  ('data_shard',   'Cracked Data Shard',  'data',      2,  35, 'Half-corrupted memory. Someone wants what is left.'),
  ('stim_patch',   'Streetchem Stim',     'chem',      2,  40, 'Makes the long nights shorter. Probably fine.'),
  ('power_cell',   'Recharged Power Cell','component', 3,  75, 'Holds a charge. Mostly.'),
  ('drone_frame',  'Junk Drone Frame',    'component', 3, 120, 'One bent rotor away from flying again.');

-- DEV ONLY: hand the test player some stock so you can list things immediately.
-- Replace 1 with your player id, then uncomment:
-- INSERT INTO player_items (player_id, item_id, qty)
--   SELECT 1, id, 25 FROM items
--   ON DUPLICATE KEY UPDATE qty = qty + 25;
