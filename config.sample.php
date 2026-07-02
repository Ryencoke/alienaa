<?php
// -----------------------------------------------------------------------------
// config.sample.php  —  TEMPLATE. Copy this to config.php and fill in real values.
//   On each server:   cp config.sample.php config.php   then edit the 4 defines.
// config.php is gitignored so your real database password is never committed.
// -----------------------------------------------------------------------------

define('DB_HOST', 'localhost');          // GoDaddy: usually 'localhost'
define('DB_NAME', 'sprawl9');            // your database name
define('DB_USER', 'your_db_user');       // your database user
define('DB_PASS', 'your_db_password');   // your database password

// Idle auto-logout window, in seconds. index.php reads this (warns 5 min before,
// forces logout at the limit). Kept here so a fresh deploy from this sample
// doesn't fatal on the undefined constant. 1800 = 30 minutes.
define('SESSION_TIMEOUT', 1800);

// Shared secret for cron.php (optional scheduled bulk maintenance). Set this to
// a long random string and pass it as ?token=... The game self-heals lazily
// even without cron, so this is optional; leave it '' to disable cron.php.
define('CRON_TOKEN', '');

function db() {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
  }
  return $pdo;
}

session_start();

function current_player() {
  if (empty($_SESSION['pid'])) return null;
  $stmt = db()->prepare('SELECT * FROM players WHERE id = ?');
  $stmt->execute([$_SESSION['pid']]);
  return $stmt->fetch() ?: null;
}

function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
