<?php /* players_search.php — autocomplete for username fields; also searches by numeric ID */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
$player = current_player();
if (!$player) { echo '[]'; exit; }
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo '[]'; exit; }
$db = db();
$results = [];
// If query is purely numeric, try searching by player ID first
if (ctype_digit($q)) {
  $st = $db->prepare('SELECT username FROM players WHERE id = ? AND id != ? LIMIT 1');
  $st->execute([(int)$q, $player['id']]);
  $results = $st->fetchAll(PDO::FETCH_COLUMN);
}
if (empty($results)) {
  $st = $db->prepare('SELECT username FROM players WHERE username LIKE ? AND id != ? ORDER BY username LIMIT 10');
  $st->execute([$q . '%', $player['id']]);
  $results = $st->fetchAll(PDO::FETCH_COLUMN);
}
echo json_encode(array_values($results));
