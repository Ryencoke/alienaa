<?php /* boards_vote_api.php — JSON endpoint for real-time post upvote/downvote.
   Standalone like notifications_api.php/chat_api.php — called directly via
   fetch, not through index.php's router, so it never needs the AJAX gateway
   allowlist. Mirrors boards.php's own 'vote' POST handler exactly (same
   re-click-to-cancel behavior) — kept here so voting doesn't need a full
   main.center page swap to reflect. */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
csrf_guard();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$player = current_player();
if (!$player) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

$pdo = db();
$pid = (int)$player['id'];
$postId = (int)($_POST['post_id'] ?? 0);
$dir = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;

try {
  $chk = $pdo->prepare('SELECT id FROM posts WHERE id = ?'); $chk->execute([$postId]);
  if (!$chk->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'Post not found.']); exit; }

  $cur = $pdo->prepare('SELECT value FROM post_votes WHERE post_id = ? AND player_id = ?');
  $cur->execute([$postId, $pid]);
  $curVal = (int)($cur->fetchColumn() ?: 0);
  $newVal = ($curVal === $dir) ? 0 : $dir; // re-click same dir = cancel back to 0
  if ($newVal === 0) {
    $pdo->prepare('DELETE FROM post_votes WHERE post_id = ? AND player_id = ?')->execute([$postId, $pid]);
  } else {
    $pdo->prepare('INSERT INTO post_votes (post_id, player_id, value) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$postId, $pid, $newVal]);
  }
  $sq = $pdo->prepare('SELECT COALESCE(SUM(value),0) FROM post_votes WHERE post_id = ?');
  $sq->execute([$postId]);
  $score = (int)$sq->fetchColumn();
  echo json_encode(['ok' => true, 'score' => $score, 'myVote' => $newVal]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Vote failed.']);
}
