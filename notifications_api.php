<?php /* notifications_api.php — JSON endpoint for the Hideout's live notification
   feed (poll for new items, dismiss one/all). Standalone like chat_api.php and
   players_search.php — called directly via fetch, not through index.php's
   router, so it never needs the AJAX gateway allowlist. */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
csrf_guard();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$player = current_player();
if (!$player) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$pdo = db();
$pid = (int)$player['id'];
ensure_player_notifications_table($pdo);

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'dismiss' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $nid = (int)($_POST['id'] ?? 0);
  if ($nid > 0) {
    try { $pdo->prepare('UPDATE player_notifications SET is_read=1 WHERE id=? AND player_id=?')->execute([$nid, $pid]); } catch (Throwable $e) {}
  }
  echo json_encode(['ok' => true]);
  exit;
}

if ($action === 'dismiss_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try { $pdo->prepare('UPDATE player_notifications SET is_read=1 WHERE player_id=?')->execute([$pid]); } catch (Throwable $e) {}
  echo json_encode(['ok' => true]);
  exit;
}

// list (default) — same seeding + fetch + "seen" marking as home.php's
// initial render, so polling this endpoint picks up new notifications
// without the player ever reloading the page.
seed_player_notifications($pdo, $pid);

$rows = [];
try {
  $nq = $pdo->prepare("SELECT id, type, body AS text, created_at AS ts FROM player_notifications WHERE player_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 20");
  $nq->execute([$pid]); $rows = $nq->fetchAll();
} catch (Throwable $e) {}
try { $pdo->prepare("UPDATE player_notifications SET is_seen=1 WHERE player_id=? AND is_seen=0")->execute([$pid]); } catch (Throwable $e) {}

$items = [];
foreach ($rows as $r) {
  $items[] = [
    'id'   => (int)$r['id'],
    'type' => $r['type'],
    'text' => $r['text'],
    'ts'   => fmt_game_time($r['ts']),
    'raw'  => false,
  ];
}

// Synthetic "unspent attribute points" entry — not a real row, mirrors
// home.php's own logic so the live-polled list matches the first paint.
try {
  $csq = $pdo->prepare('SELECT unspent FROM player_stats WHERE pid=?');
  $csq->execute([$pid]); $unspent = (int)($csq->fetchColumn() ?: 0);
  if ($unspent > 0) {
    array_unshift($items, [
      'id' => null, 'type' => 'levelup',
      'text' => "You have <b>{$unspent} unspent attribute point" . ($unspent !== 1 ? 's' : '') . "</b> from leveling up! <a href='index.php?p=pvp&tab=stats'>Spend Stats &rarr;</a>",
      'ts' => fmt_game_time(date('Y-m-d H:i:s')), 'raw' => true,
    ]);
  }
} catch (Throwable $e) {}

echo json_encode(['ok' => true, 'items' => $items]);
