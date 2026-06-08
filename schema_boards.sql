-- schema_boards.sql — Message Boards (categories -> boards -> topics -> posts)
--   mysql -u <user> -p sprawl9 < schema_boards.sql

CREATE TABLE board_cats (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE boards (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  cat_id INT NOT NULL,
  name   VARCHAR(96) NOT NULL,
  descr  VARCHAR(255) NOT NULL DEFAULT '',
  sort   INT NOT NULL DEFAULT 0,
  FOREIGN KEY (cat_id) REFERENCES board_cats(id)
) ENGINE=InnoDB;

CREATE TABLE topics (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  board_id     INT NOT NULL,
  author_id    INT NOT NULL,
  title        VARCHAR(160) NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_post_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (board_id)  REFERENCES boards(id),
  FOREIGN KEY (author_id) REFERENCES players(id),
  INDEX idx_board (board_id, last_post_at)
) ENGINE=InnoDB;

CREATE TABLE posts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  topic_id   INT NOT NULL,
  author_id  INT NOT NULL,
  body       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (topic_id)  REFERENCES topics(id),
  FOREIGN KEY (author_id) REFERENCES players(id),
  INDEX idx_topic (topic_id, created_at)
) ENGINE=InnoDB;

INSERT INTO board_cats (id, name, sort) VALUES
  (1, 'Signal', 1),
  (2, 'Sprawl Talk', 2),
  (3, 'Off-Grid', 3);

INSERT INTO boards (cat_id, name, descr, sort) VALUES
  (1, 'Broadcasts',           'Official word from whoever is still in charge.', 1),
  (2, 'The Terminal',         'General chatter about life in the Sprawl.',      1),
  (2, 'New Ghosts',           'New to the Sprawl? Ask here. No question too dumb.', 2),
  (2, 'Bug Reports',          'Found a glitch in the Grid? Log it here.',       3),
  (2, 'Patch Notes & Ideas',  'Pitch features, tweaks, and improvements.',      4),
  (2, 'Black Market Chatter', 'Talk trades, prices, and Bazaar deals.',         5),
  (3, 'The Static Lounge',    'Off-topic. Anything and nothing.',               1),
  (3, 'Tech & Rigs',          'Hardware, software, and the rigs you run.',      2),
  (3, 'Media Static',         'Feeds, films, music, and games.',                3);
