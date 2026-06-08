-- schema_chat.sql — Public Channel chat
--   mysql -u <user> -p sprawl9 < schema_chat.sql

CREATE TABLE chat_messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  player_id  INT NOT NULL,
  body       VARCHAR(240) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_id (id),
  FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB;
