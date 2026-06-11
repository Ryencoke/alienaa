<?php /* pages/friends.php — Friends list */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS friends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL, friend_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pair (player_id, friend_id), KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    type VARCHAR(40) NOT NULL DEFAULT "info",
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player_read (player_id, is_read)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
  header('Content-Type: application/json');
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'add') {
      $fid = (int)($_POST['friend_id'] ?? 0);
      if ($fid === (int)$pid) throw new RuntimeException("Can't friend yourself.");
      $chk = $pdo->prepare('SELECT username FROM players WHERE id = ?'); $chk->execute([$fid]);
      $tgt = $chk->fetch(); if (!$tgt) throw new RuntimeException('Player not found.');
      $pdo->prepare('INSERT IGNORE INTO friends (player_id, friend_id) VALUES (?,?)')->execute([$pid, $fid]);
      // Notification for the added player
      $myName = $pdo->prepare('SELECT username FROM players WHERE id=?'); $myName->execute([$pid]);
      $me = $myName->fetchColumn();
      $pdo->prepare('INSERT INTO player_notifications (player_id,type,body) VALUES (?,?,?)')->execute([$fid, 'friend_add', e($me).' added you as a friend.']);
      echo json_encode(['ok'=>true,'msg'=>'Friend added.']);
    } elseif ($act === 'remove') {
      $fid = (int)($_POST['friend_id'] ?? 0);
      $pdo->prepare('DELETE FROM friends WHERE player_id = ? AND friend_id = ?')->execute([$pid, $fid]);
      echo json_encode(['ok'=>true,'msg'=>'Friend removed.']);
    } else {
      echo json_encode(['ok'=>false,'msg'=>'Unknown action.']);
    }
  } catch (Throwable $ex) {
    echo json_encode(['ok'=>false,'msg'=>$ex->getMessage()]);
  }
  exit;
}

// Normal POST fallback (profile.php add button)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'add') {
      $fid = (int)($_POST['friend_id'] ?? 0);
      if ($fid === (int)$pid) throw new RuntimeException("Can't friend yourself.");
      $chk = $pdo->prepare('SELECT username FROM players WHERE id = ?'); $chk->execute([$fid]);
      $tgt = $chk->fetch(); if (!$tgt) throw new RuntimeException('Player not found.');
      $pdo->prepare('INSERT IGNORE INTO friends (player_id, friend_id) VALUES (?,?)')->execute([$pid, $fid]);
      $myName = $pdo->prepare('SELECT username FROM players WHERE id=?'); $myName->execute([$pid]);
      $me = $myName->fetchColumn();
      $pdo->prepare('INSERT INTO player_notifications (player_id,type,body) VALUES (?,?,?)')->execute([$fid, 'friend_add', htmlspecialchars($me, ENT_QUOTES).' added you as a friend.']);
      $msg = 'Friend added.';
    } elseif ($act === 'remove') {
      $fid = (int)($_POST['friend_id'] ?? 0);
      $pdo->prepare('DELETE FROM friends WHERE player_id = ? AND friend_id = ?')->execute([$pid, $fid]);
      $msg = 'Friend removed.';
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$friends = $pdo->prepare(
  'SELECT p.id, p.username, p.level, p.role, p.chat_color, p.last_seen,
          (p.last_seen >= (NOW() - INTERVAL 5 MINUTE)) AS online
   FROM friends f JOIN players p ON p.id = f.friend_id
   WHERE f.player_id = ? ORDER BY online DESC, p.username ASC'
);
$friends->execute([$pid]);
$friends = $friends->fetchAll();

// People who have added you
$addedBy = [];
try {
  $abq = $pdo->prepare(
    'SELECT p.id, p.username, p.level, p.role, p.chat_color,
            (p.last_seen >= (NOW() - INTERVAL 5 MINUTE)) AS online
     FROM friends f JOIN players p ON p.id = f.player_id
     WHERE f.friend_id = ? ORDER BY p.username ASC'
  );
  $abq->execute([$pid]); $addedBy = $abq->fetchAll();
} catch (Throwable $e) {}

$searchResults = [];
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  if (ctype_digit($q)) {
    $sr = $pdo->prepare('SELECT p.id, p.username, p.level FROM players p WHERE p.id = ? AND p.id != ?');
    $sr->execute([(int)$q, $pid]); $searchResults = $sr->fetchAll();
  }
  if (empty($searchResults)) {
    $sr = $pdo->prepare('SELECT p.id, p.username, p.level FROM players p WHERE p.username LIKE ? AND p.id != ? ORDER BY p.username LIMIT 20');
    $sr->execute(['%' . $q . '%', $pid]); $searchResults = $sr->fetchAll();
  }
}

$friendIds = array_column($friends, 'id');
?>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),var(--neon2),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#128101; Friends</h2>
    <p class="muted" style="margin:0;font-size:12px">Your crew on the Grid. Friends show in your online list.</p>
  </div>
</div>

<?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>

<div id="friends-flash" style="display:none;background:rgba(25,240,199,.07);border:1px solid rgba(25,240,199,.2);border-radius:6px;padding:9px 14px;font-size:13px"></div>

<!-- Search -->
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;margin-bottom:10px">&#128269; Find Players</h3>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="p" value="friends">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Username or player ID..." style="flex:1;min-width:160px" autocomplete="off" data-no-counter>
    <button type="submit">Search</button>
  </form>
  <?php if ($q !== '' && empty($searchResults)): ?>
    <p class="muted" style="margin-top:10px;font-size:12px">No players found matching &ldquo;<?= e($q) ?>&rdquo;.</p>
  <?php endif; ?>
  <?php if ($searchResults): ?>
  <div style="margin-top:12px;display:flex;flex-direction:column;gap:4px">
    <?php foreach ($searchResults as $r): $alreadyFriend = in_array($r['id'], $friendIds, true); ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 10px;background:var(--panel2);border:1px solid var(--line);border-radius:5px">
      <span><a href="index.php?p=profile&id=<?= (int)$r['id'] ?>" style="color:var(--accent);font-weight:700"><?= e($r['username']) ?></a>
        <span class="muted" style="font-size:11px"> Lv.<?= (int)$r['level'] ?></span></span>
      <?php if ($alreadyFriend): ?>
        <span class="muted" style="font-size:11px">Already friends</span>
      <?php else: ?>
        <button class="btn btn-primary btn-sm fr-add-btn" data-id="<?= (int)$r['id'] ?>">+ Add</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Friends list -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700">Your Friends <span style="color:var(--muted);font-weight:400;font-size:12px">(<?= count($friends) ?>)</span></div>
  <?php if (empty($friends)): ?>
  <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No friends yet. Search above to find players.</div>
  <?php else: ?>
  <?php foreach ($friends as $f): ?>
  <div class="friend-row" id="fr-<?= (int)$f['id'] ?>">
    <span class="friend-dot <?= $f['online'] ? 'online' : 'offline' ?>"></span>
    <div class="friend-info">
      <a href="index.php?p=profile&id=<?= (int)$f['id'] ?>" style="color:<?= e(chat_color($f['role'],$f['chat_color']??'')) ?>;font-weight:700"><?= e($f['username']) ?></a>
      <span class="muted" style="font-size:11px"> Lv.<?= (int)$f['level'] ?></span>
    </div>
    <span class="friend-status <?= $f['online'] ? 'status-online' : 'status-offline' ?>"><?= $f['online'] ? 'Online' : 'Offline' ?></span>
    <div style="display:flex;gap:5px;align-items:center">
      <a href="index.php?p=messages&u=<?= (int)$f['id'] ?>" class="btn btn-ghost btn-sm" title="Message">&#9993;</a>
      <button class="btn btn-ghost btn-sm fr-remove-btn" data-id="<?= (int)$f['id'] ?>" style="color:var(--neon2);border-color:rgba(255,45,149,.25)" data-name="<?= e(htmlspecialchars($f['username'],ENT_QUOTES)) ?>">Remove</button>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Added by -->
<?php if (!empty($addedBy)): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700">&#128065; Players Who Added You <span style="color:var(--muted);font-weight:400;font-size:12px">(<?= count($addedBy) ?>)</span></div>
  <?php foreach ($addedBy as $f):
    $mutualFriend = in_array((int)$f['id'], $friendIds, true);
  ?>
  <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="friend-dot <?= $f['online'] ? 'online' : 'offline' ?>"></span>
    <div style="flex:1;min-width:100px">
      <a href="index.php?p=profile&id=<?= (int)$f['id'] ?>" style="color:<?= e(chat_color($f['role'],$f['chat_color']??'')) ?>;font-weight:700"><?= e($f['username']) ?></a>
      <span class="muted" style="font-size:11px"> Lv.<?= (int)$f['level'] ?></span>
    </div>
    <?php if ($mutualFriend): ?>
      <span style="font-size:11px;color:var(--accent)">&#10003; Mutual</span>
    <?php else: ?>
      <button class="btn btn-ghost btn-sm fr-add-btn" data-id="<?= (int)$f['id'] ?>" style="font-size:11px">+ Add Back</button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function(){
  function flash(msg,err){
    var el=document.getElementById('friends-flash');
    if(!el)return;
    el.textContent=msg;
    el.style.display='block';
    el.style.background=err?'rgba(226,59,59,.07)':'rgba(25,240,199,.07)';
    el.style.borderColor=err?'rgba(226,59,59,.25)':'rgba(25,240,199,.2)';
    clearTimeout(el._t);
    el._t=setTimeout(function(){ el.style.display='none'; },3000);
  }
  function ajax(action, fid, cb){
    var fd=new FormData(); fd.append('action',action); fd.append('friend_id',fid);
    fetch('index.php?p=friends',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(d){ cb(d); })
      .catch(function(){ flash('Network error.',true); });
  }
  document.addEventListener('click',function(e){
    var btn=e.target.closest('.fr-add-btn');
    if(btn){
      var fid=btn.getAttribute('data-id');
      btn.disabled=true; btn.textContent='Adding...';
      ajax('add',fid,function(d){
        if(d.ok){ flash(d.msg,false); btn.closest('.friend-row,div[style]').remove(); }
        else{ flash(d.msg,true); btn.disabled=false; btn.textContent='+ Add'; }
      });
    }
    var rbtn=e.target.closest('.fr-remove-btn');
    if(rbtn){
      var name=rbtn.getAttribute('data-name')||'this player';
      if(!confirm('Remove '+name+' from friends?')) return;
      var fid=rbtn.getAttribute('data-id');
      rbtn.disabled=true;
      ajax('remove',fid,function(d){
        if(d.ok){ flash(d.msg,false); var row=document.getElementById('fr-'+fid); if(row) row.remove(); }
        else{ flash(d.msg,true); rbtn.disabled=false; }
      });
    }
  });
})();
</script>
