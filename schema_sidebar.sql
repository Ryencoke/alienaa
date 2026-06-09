-- schema_sidebar.sql — customizable sidebar quick links
ALTER TABLE players ADD COLUMN IF NOT EXISTS sidebar VARCHAR(255) NOT NULL DEFAULT '';
