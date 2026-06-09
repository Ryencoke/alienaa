-- schema_settings.sql — key/value settings (e.g. the global staff note)
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(48) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB;
