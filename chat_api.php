<?php /* chat_api.php — JSON endpoint for the Public Channel (outside the router). */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
csrf_guard();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$player = current_player();
if (!$player) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$pdo    = db();
$pdo->prepare('UPDATE players SET last_seen = NOW() WHERE id = ?')->execute([$player['id']]);
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

// List recent messages (oldest-first). Optional &n= up to 100.
$n = min(100, max(10, (int)($_GET['n'] ?? 30)));
$rows = $pdo->query('SELECT c.id, c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color, p.sub_until
                     FROM chat_messages c JOIN players p ON p.id = c.player_id
                     ORDER BY c.id DESC LIMIT ' . (int)$n)->fetchAll();
$msgs = [];
foreach (array_reverse($rows) as $r) {
  $msgs[] = [
    'id'       => (int)$r['uid'],
    'time'     => date('H:i:s', strtotime($r['created_at'])),
    'username' => $r['username'],
    'color'    => chat_color($r['role'], $r['chat_color']),  // body text color (includes custom)
    'name_color' => chat_color($r['role'], ''),               // username: role-based only
    'sub'      => (!empty($r['sub_until']) && $r['sub_until'] >= date('Y-m-d')),
    'html'     => bbcode($r['body']),     // escaped + whitelisted BBCode = safe innerHTML
  ];
}
echo json_encode(['ok' => true, 'messages' => $msgs]);
