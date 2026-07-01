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

try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS chat_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    player_id  INT          NOT NULL,
    room       VARCHAR(40)  NOT NULL DEFAULT \'public\',
    body       VARCHAR(240) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player (player_id),
    INDEX idx_room_id (room, id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  try { $pdo->exec("ALTER TABLE chat_messages ADD COLUMN room VARCHAR(40) NOT NULL DEFAULT 'public' AFTER player_id"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE chat_messages ADD INDEX idx_room_id (room, id)"); } catch (Throwable $e) {}
} catch (Throwable $e) {}

// Valid rooms + access check
function resolve_room(string $raw, array $player, $pdo): string {
  if (in_array($raw, ['public','trading','help'], true)) return $raw;
  if (preg_match('/^syn_(\d+)$/', $raw, $m)) {
    $synId = (int)$m[1];
    $q = $pdo->prepare('SELECT 1 FROM syndicate_members WHERE syndicate_id=? AND player_id=?');
    $q->execute([$synId, $player['id']]);
    if ($q->fetchColumn()) return $raw;
  }
  return 'public';
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$room   = resolve_room((string)($_POST['room'] ?? $_GET['room'] ?? 'public'), $player, $pdo);

if ($action === 'say' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = trim($_POST['body'] ?? '');
  if ($body === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
  if (mb_strlen($body) > 240) $body = mb_substr($body, 0, 240);

  $last = $pdo->prepare('SELECT created_at FROM chat_messages WHERE player_id=? AND room=? ORDER BY id DESC LIMIT 1');
  $last->execute([$player['id'], $room]);
  $t = $last->fetchColumn();
  if ($t && (time() - strtotime($t)) < 1) { echo json_encode(['ok' => false, 'error' => 'slow down']); exit; }

  $pdo->prepare('INSERT INTO chat_messages (player_id, room, body, created_at) VALUES (?,?,?,NOW())')
      ->execute([$player['id'], $room, $body]);
  echo json_encode(['ok' => true]); exit;
}

if ($action === 'active') {
  // Presence heartbeat — this action is only ever polled by pages/chat.php's own
  // JS loop (the sitewide quickchat sidebar preview only ever calls action=list),
  // so writing presence here reflects who actually has the room open right now,
  // not who has recently posted a message in it.
  try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS chat_presence (
      room VARCHAR(40) NOT NULL, player_id INT NOT NULL,
      last_ping DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (room, player_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->prepare('INSERT INTO chat_presence (room,player_id,last_ping) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE last_ping=NOW()')->execute([$room, $player['id']]);
    $pdo->exec('DELETE FROM chat_presence WHERE last_ping < NOW() - INTERVAL 1 HOUR'); // opportunistic cleanup
  } catch (Throwable $e) {}

  $aq = $pdo->prepare('SELECT p.id, p.username, p.role, p.chat_color FROM chat_presence cp JOIN players p ON p.id=cp.player_id WHERE cp.room=? AND cp.last_ping >= NOW() - INTERVAL 20 SECOND ORDER BY p.username');
  $aq->execute([$room]);
  $active = [];
  foreach ($aq as $r) $active[] = ['id'=>(int)$r['id'],'name'=>$r['username'],'color'=>chat_color($r['role'],''),'title'=>role_label($r['role'])];
  echo json_encode(['ok'=>true,'active'=>$active]); exit;
}

// List recent messages (oldest-first). Optional &n= up to 100.
$n = min(150, max(10, (int)($_GET['n'] ?? 50)));
$rows = $pdo->prepare('SELECT c.id, c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color FROM chat_messages c JOIN players p ON p.id = c.player_id WHERE c.room=? ORDER BY c.id DESC LIMIT ' . (int)$n);
$rows->execute([$room]); $rows = $rows->fetchAll();
$msgs = [];
foreach (array_reverse($rows) as $r) {
  $msgs[] = [
    'id'         => (int)$r['uid'],
    'time'       => date('H:i', strtotime($r['created_at'])),
    'username'   => $r['username'],
    'name_color' => chat_color($r['role'], ''),             // username: role color only
    'color'      => chat_color($r['role'], $r['chat_color']), // text: custom or role color
    'html'       => bbcode($r['body']),
  ];
}
echo json_encode(['ok' => true, 'messages' => $msgs]);
