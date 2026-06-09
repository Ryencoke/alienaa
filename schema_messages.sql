-- schema_messages.sql — private messages between players
--   run in phpMyAdmin SQL tab

CREATE TABLE messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  from_id    INT NOT NULL,
  to_id      INT NOT NULL,
  body       TEXT NOT NULL,
  is_read    TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_to (to_id, is_read),
  INDEX idx_pair (from_id, to_id),
  FOREIGN KEY (from_id) REFERENCES players(id),
  FOREIGN KEY (to_id)   REFERENCES players(id)
) ENGINE=InnoDB;
