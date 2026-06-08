CREATE TABLE players (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(32) UNIQUE NOT NULL,
  pass_hash     VARCHAR(255) NOT NULL,
  level         INT NOT NULL DEFAULT 1,
  creds_pocket  BIGINT NOT NULL DEFAULT 500,
  creds_bank    BIGINT NOT NULL DEFAULT 0,
  shards        INT NOT NULL DEFAULT 0,
  integrity     INT NOT NULL DEFAULT 15,
  integrity_max INT NOT NULL DEFAULT 15,
  xp            INT NOT NULL DEFAULT 0,
  xp_next       INT NOT NULL DEFAULT 300,
  `signal`      INT NOT NULL DEFAULT 10,
  signal_max    INT NOT NULL DEFAULT 10,
  cycles        INT NOT NULL DEFAULT 50,
  cycles_max    INT NOT NULL DEFAULT 1500,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE skills (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(32) UNIQUE NOT NULL,
  name      VARCHAR(64) NOT NULL,
  max_pts   INT NOT NULL DEFAULT 1000
);

CREATE TABLE player_skills (
  player_id INT NOT NULL,
  skill_id  INT NOT NULL,
  points    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (player_id, skill_id),
  FOREIGN KEY (player_id) REFERENCES players(id),
  FOREIGN KEY (skill_id)  REFERENCES skills(id)
);

INSERT INTO skills (code, name) VALUES
  ('netrun','Netrunning 101'),
  ('hydro','Hydroponics 101'),
  ('scav','Scavenging 101'),
  ('combat','Combat Sim 101'),
  ('chem','Streetchem 101'),
  ('hack','Cryptocracking 101'),
  ('fab','Fabrication 101'),
  ('drone','Drone Piloting 101');
