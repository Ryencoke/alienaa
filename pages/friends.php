<?php /* pages/friends.php — Friends list */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Ensure table exists
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS friends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    friend_id  INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pair (player_id, friend_id),
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'add') {
      $fid = (int)($_POST['friend_id'] ?? 0);
      if ($fid === $pid) throw new RuntimeException("You can't friend yourself.");
      // Confirm target exists
      $chk = $pdo->prepare('SELECT id FROM players WHERE id = ?');
      $chk->execute([$fid]);
      if (!$chk->fetchColumn()) throw new RuntimeException('Player not found.');
      $pdo->prepare('INSERT IGNORE INTO friends (player_id, friend_id) VALUES (?,?)')->execute([$pid, $fid]);
      $msg = 'Friend added.';
    } elseif ($act === 'remove') {
      $fid = (int)($_POST['friend_id'] ?? 0);
      $pdo->prepare('DELETE FROM friends WHERE player_id = ? AND friend_id = ?')->execute([$pid, $fid]);
      $msg = 'Friend removed.';
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

// Load friend rows with online status
$friends = $pdo->prepare(
  'SELECT p.id, p.username, p.level, p.role, p.last_seen,
          (p.last_seen >= (NOW() - INTERVAL 5 MINUTE)) AS online
   FROM friends f JOIN players p ON p.id = f.friend_id
   WHERE f.player_id = ?
   ORDER BY online DESC, p.username ASC'
);
$friends->execute([$pid]);
$friends = $friends->fetchAll();

// Search
$searchResults = [];
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  $sr = $pdo->prepare(
    'SELECT p.id, p.username, p.level FROM players p
     WHERE p.username LIKE ? AND p.id != ?
     ORDER BY p.username LIMIT 20'
  );
  $sr->execute(['%' . $q . '%', $pid]);
  $searchResults = $sr->fetchAll();
}

$friendIds = array_column($friends, 'id');
?>

<div class="panel">
  <h2>&#128101; Friends List</h2>
  <?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>
  <p class="muted">Stay connected with your crew. Friends show in your Online sidebar.</p>
</div>

<!-- Search -->
<div class="panel">
  <h3>Find Players</h3>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="p" value="friends">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search username..." style="flex:1" autocomplete="off" maxlength="32">
    <button type="submit">Search</button>
  </form>
  <?php if ($q !== '' && empty($searchResults)): ?>
    <p class="muted" style="margin-top:10px">No players found matching &ldquo;<?= e($q) ?>&rdquo;.</p>
  <?php endif; ?>
  <?php if ($searchResults): ?>
    <div style="margin-top:12px">
      <?php foreach ($searchResults as $r): $alreadyFriend = in_array($r['id'], $friendIds, true); ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
          <span><a href="index.php?p=profile&id=<?= (int)$r['id'] ?>" style="color:var(--accent);font-weight:bold"><?= e($r['username']) ?></a>
            <span class="muted" style="font-size:11px"> Lv.<?= (int)$r['level'] ?></span></span>
          <?php if ($alreadyFriend): ?>
            <span class="muted" style="font-size:11px">Already friends</span>
          <?php else: ?>
            <form method="post" style="margin:0">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="friend_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit">+ Add</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Friends list -->
<div class="panel">
  <h3>Your Friends (<?= count($friends) ?>)</h3>
  <?php if (empty($friends)): ?>
    <p class="muted">No friends yet. Search for players above and add them.</p>
  <?php else: ?>
    <div class="friends-list">
      <?php foreach ($friends as $f): ?>
        <div class="friend-row">
          <span class="friend-dot <?= $f['online'] ? 'online' : 'offline' ?>"></span>
          <div class="friend-info">
            <a href="index.php?p=profile&id=<?= (int)$f['id'] ?>" style="color:<?= e(chat_color($f['role'],'')) ?>;font-weight:bold"><?= e($f['username']) ?></a>
            <span class="muted" style="font-size:11px"> Lv.<?= (int)$f['level'] ?></span>
          </div>
          <span class="friend-status <?= $f['online'] ? 'status-online' : 'status-offline' ?>">
            <?= $f['online'] ? 'Online' : 'Offline' ?>
          </span>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="friend_id" value="<?= (int)$f['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
