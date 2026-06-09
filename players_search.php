<?php /* players_search.php — autocomplete for username fields */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
$player = current_player();
if (!$player) { echo '[]'; exit; }
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo '[]'; exit; }
$st = db()->prepare('SELECT username FROM players WHERE username LIKE ? AND id != ? ORDER BY username LIMIT 10');
$st->execute([$q . '%', $player['id']]);
echo json_encode($st->fetchAll(PDO::FETCH_COLUMN));
