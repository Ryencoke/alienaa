-- schema_boards_threads.sql — threaded replies, vote scores, view counts
--   run in phpMyAdmin SQL tab (or mysql CLI)

ALTER TABLE posts  ADD COLUMN parent_id INT NULL;
ALTER TABLE posts  ADD INDEX idx_parent (parent_id);
ALTER TABLE topics ADD COLUMN views INT NOT NULL DEFAULT 0;

CREATE TABLE post_votes (
  post_id   INT NOT NULL,
  player_id INT NOT NULL,
  value     TINYINT NOT NULL,   -- +1 or -1
  PRIMARY KEY (post_id, player_id),
  INDEX idx_post (post_id)
) ENGINE=InnoDB;
