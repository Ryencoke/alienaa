-- schema_updates.sql — Game Updates (managers post; everyone votes)
--   run in phpMyAdmin SQL tab

CREATE TABLE updates (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  author_id        INT NOT NULL,
  body             TEXT NOT NULL,
  credit           VARCHAR(64) NOT NULL DEFAULT '',
  credit_player_id INT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  FOREIGN KEY (author_id) REFERENCES players(id)
) ENGINE=InnoDB;

CREATE TABLE update_votes (
  update_id INT NOT NULL,
  player_id INT NOT NULL,
  value     TINYINT NOT NULL,   -- +1 or -1
  PRIMARY KEY (update_id, player_id),
  INDEX idx_update (update_id)
) ENGINE=InnoDB;
