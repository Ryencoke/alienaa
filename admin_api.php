<?php /* admin_api.php — player-handle autocomplete for the Admin panel (staff only). */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$p = current_player();
if (!$p || !in_array($p['role'] ?? 'member', ['admin','manager'], true)) { http_response_code(403); echo json_encode([]); exit; }

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }
$like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$st = db()->prepare('SELECT id, username FROM players WHERE username LIKE ? ORDER BY username LIMIT 10');
$st->execute([$like]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
