<?php /* chat_api.php — JSON endpoint for the Public Channel (lives OUTSIDE the router). */
require __DIR__ . '/config.php';   // starts session; provides db(), current_player()
header('Content-Type: application/json');

$player = current_player();
if (!$player) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$pdo    = db();
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'say' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = trim($_POST['body'] ?? '');
  if ($body === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
  if (mb_strlen($body) > 240) $body = mb_substr($body, 0, 240);

  // Light flood guard: ~1 message per second per player.
  $last = $pdo->prepare('SELECT created_at FROM chat_messages WHERE player_id = ? ORDER BY id DESC LIMIT 1');
  $last->execute([$player['id']]);
  $t = $last->fetchColumn();
  if ($t && (time() - strtotime($t)) < 1) { echo json_encode(['ok' => false, 'error' => 'slow down']); exit; }

  $pdo->prepare('INSERT INTO chat_messages (player_id, body, created_at) VALUES (?,?,NOW())')
      ->execute([$player['id'], $body]);
  echo json_encode(['ok' => true]); exit;
}

// Default: list the most recent messages (oldest-first for display).
$rows = $pdo->query('SELECT c.id, c.body, p.username
                     FROM chat_messages c JOIN players p ON p.id = c.player_id
                     ORDER BY c.id DESC LIMIT 30')->fetchAll();
echo json_encode(['ok' => true, 'messages' => array_reverse($rows)]);
