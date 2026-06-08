-- schema_casino.sql — The Lucky Daemon (casino). Standalone; no item/skill deps.
--   mysql -u <user> -p sprawl9 < schema_casino.sql

-- Bet history — powers the "recent plays" feed and gives you an audit trail.
CREATE TABLE casino_log (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NOT NULL,
  game      VARCHAR(16) NOT NULL,          -- dice / slots
  bet       BIGINT NOT NULL,
  detail    VARCHAR(64) NOT NULL,          -- e.g. "rolled 9 (High)" or "$ # 7"
  payout    BIGINT NOT NULL,               -- gross creds returned (0 = lost)
  net       BIGINT NOT NULL,               -- payout - bet (negative = net loss)
  played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_player (player_id, played_at),
  FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB;
