-- schema_txlog.sql — transaction log (cred transfers, etc.) for the Admin panel
--   run in phpMyAdmin SQL tab

CREATE TABLE tx_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  from_id    INT NULL,
  to_id      INT NULL,
  kind       VARCHAR(24) NOT NULL,
  amount     BIGINT NOT NULL DEFAULT 0,
  note       VARCHAR(160) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB;
