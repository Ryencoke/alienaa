<?php /* pages/chat.php — full Public Channel (server-rendered; no-JS fallback) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'say') {
  $body = trim($_POST['body'] ?? '');
  if ($body === '') {
    $msg = 'Say something first.';
  } else {
    if (mb_strlen($body) > 240) $body = mb_substr($body, 0, 240);
    $pdo->prepare('INSERT INTO chat_messages (player_id, body, created_at) VALUES (?,?,NOW())')
        ->execute([$pid, $body]);
  }
}

$rows = array_reverse($pdo->query(
  'SELECT c.id, c.body, c.created_at, p.username
   FROM chat_messages c JOIN players p ON p.id = c.player_id
   ORDER BY c.id DESC LIMIT 100')->fetchAll());
?>
<div class="panel">
  <h2>Public Channel</h2>
  <p class="muted">The whole Sprawl is listening. Mind your signal.</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>

  <div style="max-height:480px;overflow-y:auto;border:1px solid var(--line);padding:8px;border-radius:4px">
    <?php if ($rows): foreach ($rows as $r): ?>
      <div><b style="color:var(--neon)"><?= e($r['username']) ?>:</b>
        <?= e($r['body']) ?>
        <span class="muted" style="font-size:10px"><?= e($r['created_at']) ?></span></div>
    <?php endforeach; else: ?>
      <p class="muted">Dead air. Say something.</p>
    <?php endif; ?>
  </div>

  <form method="post" style="margin-top:10px;display:flex;gap:6px">
    <input type="hidden" name="action" value="say">
    <input type="text" name="body" maxlength="240" autocomplete="off" placeholder="say something..." style="flex:1">
    <button type="submit">Say</button>
  </form>
</div>
