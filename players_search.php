<?php /* players_search.php — autocomplete for username fields; also supports ?lookup= for player confirmation info */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
$player = current_player();
if (!$player) { echo '[]'; exit; }
$db = db();

// Lookup mode: return single player details for trade confirmation
if (isset($_GET['lookup'])) {
  $lq = trim($_GET['lookup'] ?? '');
  if ($lq === '') { echo 'null'; exit; }
  try {
    $st = null;
    if (ctype_digit($lq)) {
      $st = $db->prepare('SELECT id, username, level, role FROM players WHERE id = ? AND id != ? LIMIT 1');
      $st->execute([(int)$lq, $player['id']]);
    } else {
      $st = $db->prepare('SELECT id, username, level, role FROM players WHERE username = ? AND id != ? LIMIT 1');
      $st->execute([$lq, $player['id']]);
    }
    $row = $st->fetch();
    if (!$row) { echo 'null'; exit; }
    echo json_encode(['id'=>(int)$row['id'],'username'=>$row['username'],'level'=>(int)$row['level'],'role'=>$row['role']]);
  } catch (Throwable $e) { echo 'null'; }
  exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo '[]'; exit; }
// Match either a username prefix or an id prefix (so "1" finds ids 1, 10, 11, 100...
// as well as usernames starting with "1"), not just an exact id match.
// Escape LIKE metacharacters so a query like "%" can't enumerate all players
// (and a trailing backslash can't error). Same escaping as admin_api.php.
$like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$st = $db->prepare('SELECT id, username FROM players WHERE id != ? AND (username LIKE ? OR CAST(id AS CHAR) LIKE ?) ORDER BY username LIMIT 10');
$st->execute([$player['id'], $like, $like]);
$results = array_map(fn($r) => ['id' => (int)$r['id'], 'username' => $r['username']], $st->fetchAll());
echo json_encode(array_values($results));
