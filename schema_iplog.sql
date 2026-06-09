-- ============================================================================
-- schema_iplog.sql — IP & Access Log for Sprawl-9.
-- Safe to run on a live DB: CREATE TABLE IF NOT EXISTS is idempotent.
-- Requires MariaDB 10.0.2+ (GoDaddy). Run in phpMyAdmin -> SQL tab.
-- ============================================================================

CREATE TABLE IF NOT EXISTS ip_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  player_id  INT NULL,
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  action     VARCHAR(16)  NOT NULL DEFAULT 'login',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_player  (player_id, created_at),
  INDEX idx_ip      (ip, created_at),
  INDEX idx_action  (action, created_at)
) ENGINE=InnoDB;
