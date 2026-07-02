<?php
// Fill these in with your host's database credentials.
define('DB_HOST', 'localhost');
define('DB_NAME', 'sprawl9');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Inactivity timeout in seconds (30 minutes)
define('SESSION_TIMEOUT', 1800);

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

// Harden session cookie before starting session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
session_name('s9sid');
session_start();

// Server-side inactivity timeout: clear login if idle too long
if (!empty($_SESSION['pid'])) {
  if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    unset($_SESSION['pid'], $_SESSION['real_pid'], $_SESSION['role_override'], $_SESSION['bj'], $_SESSION['vp']);
    $_SESSION['timed_out'] = 1;
  } else {
    $_SESSION['last_activity'] = time();
  }
}

function current_player() {
  if (empty($_SESSION['pid'])) return null;
  $stmt = db()->prepare('SELECT * FROM players WHERE id = ?');
  $stmt->execute([$_SESSION['pid']]);
  return $stmt->fetch() ?: null;
}

function require_login() {
  if (!current_player()) { header('Location: index.php?p=login'); exit; }
}

function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
