-- schema_admin_profile.sql — admin edit log + profile country/signature
--   run in phpMyAdmin SQL tab

ALTER TABLE players ADD COLUMN country   VARCHAR(2)   NOT NULL DEFAULT '';
ALTER TABLE players ADD COLUMN signature VARCHAR(255) NOT NULL DEFAULT '';

CREATE TABLE admin_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT NOT NULL,
  target_id  INT NOT NULL,
  field      VARCHAR(32) NOT NULL,
  old_value  VARCHAR(255) NOT NULL DEFAULT '',
  new_value  VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_target (target_id, created_at),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;
