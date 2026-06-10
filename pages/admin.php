<?php /* pages/admin.php — staff admin hub (sub-pages via ?sec=) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$role = $player['role'] ?? 'member';
$canMod   = in_array($role, ['chatmod','moderator','admin','manager'], true);
$canAdmin = in_array($role, ['admin','manager'], true);

if (!$canMod) { echo '<div class="panel"><h2>Staff Admin</h2><p class="muted">Staff access only.</p></div>'; return; }

$sec    = $_GET['sec'] ?? '';
$editId = (int)($_GET['u'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {

    if ($a === 'impersonate' && $canAdmin) {
      $tgt = trim($_POST['imp_handle'] ?? '');
      $iq  = $pdo->prepare('SELECT id, username, role FROM players WHERE username=?'); $iq->execute([$tgt]); $ti = $iq->fetch();
      if (!$ti) throw new RuntimeException('Ghost not found: ' . htmlspecialchars($tgt, ENT_QUOTES));
      if (in_array($ti['role'] ?? '', ['admin','manager'], true) && $role !== 'manager') throw new RuntimeException('Cannot impersonate staff.');
      $_SESSION['real_pid'] = $pid;
      $_SESSION['pid'] = (int)$ti['id'];
      if (!headers_sent()) header('Location: index.php?p=home'); exit;

    } elseif ($a === 'role_preview' && $canAdmin) {
      $rv = $_POST['preview_role'] ?? '';
      $allowedRoles = ['','member','chatmod','moderator','admin','manager'];
      if (!in_array($rv, $allowedRoles, true)) throw new RuntimeException('Invalid role.');
      if ($rv === '') { unset($_SESSION['role_override']); $msg = 'Role preview cleared.'; }
      else { $_SESSION['role_override'] = $rv; $msg = 'Viewing as: '.$rv; }

    } elseif ($a === 'jail_player' && $canAdmin) {
      $uid    = (int)($_POST['jail_uid'] ?? 0);
      $reason = trim($_POST['jail_reason'] ?? '');
      $days   = max(1, min(365, (int)($_POST['jail_days'] ?? 1)));
      if (!$reason) throw new RuntimeException('Reason required.');
      try { $pdo->exec("CREATE TABLE IF NOT EXISTS jail_records (id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, reason VARCHAR(500) NOT NULL, days INT NOT NULL DEFAULT 1, jailed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, release_at DATETIME NOT NULL, jailed_by INT NOT NULL, status ENUM('active','released') NOT NULL DEFAULT 'active', released_early_by INT NULL, released_at DATETIME NULL, INDEX idx_player (player_id)) ENGINE=InnoDB"); } catch (Throwable $e) {}
      $pdo->prepare("UPDATE jail_records SET status='released' WHERE player_id=? AND status='active'")->execute([$uid]);
      $pdo->prepare("INSERT INTO jail_records (player_id, reason, days, jailed_at, release_at, jailed_by) VALUES (?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL ? DAY),?)")->execute([$uid, $reason, $days, $days, $pid]);
      $editId = $uid; $msg = "Player jailed for {$days} day(s).";

    } elseif ($a === 'unjail_player' && $canAdmin) {
      $uid = (int)($_POST['unjail_uid'] ?? 0);
      $pdo->prepare("UPDATE jail_records SET status='released', released_early_by=?, released_at=NOW() WHERE player_id=? AND status='active'")->execute([$pid, $uid]);
      $editId = $uid; $msg = 'Player released from jail.';

    } elseif ($a === 'find' && $canAdmin) {
      $h = trim($_POST['handle'] ?? '');
      $r = $pdo->prepare('SELECT id FROM players WHERE username = ?'); $r->execute([$h]);
      $editId = (int)$r->fetchColumn();
      if (!$editId) $msg = 'No ghost with that handle.';
    }
    elseif ($a === 'save_player' && $canAdmin) {
      $uid = (int)($_POST['uid'] ?? 0);
      $old = $pdo->prepare('SELECT * FROM players WHERE id=?'); $old->execute([$uid]); $old = $old->fetch();
      if ($old) {
        $intf = ['level','creds_pocket','creds_bank','shards','integrity','integrity_max','xp','xp_next','signal','signal_max','cycles','cycles_max','loan'];
        $new = [];
        foreach ($intf as $f) $new[$f] = max(0, (int)($_POST[$f] ?? (int)($old[$f] ?? 0)));
        $nr = $_POST['role'] ?? $old['role'];
        if (!in_array($nr, ['member','chatmod','moderator','admin','manager'], true)) $nr = $old['role'];
        $sub = trim($_POST['sub_until'] ?? '');
        if ($sub !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sub)) $sub = (string)($old['sub_until'] ?? '');
        $emailEdit = trim($_POST['edit_email'] ?? '');
        if ($emailEdit !== '' && !filter_var($emailEdit, FILTER_VALIDATE_EMAIL)) $emailEdit = '';

        $set = []; $params = [];
        foreach ($intf as $f) { $set[] = ($f === 'signal' ? '`signal`' : $f) . '=?'; $params[] = $new[$f]; }
        $set[] = 'role=?';      $params[] = $nr;
        $set[] = 'sub_until=?'; $params[] = ($sub === '' ? null : $sub);
        // Add email column if not exists, then include it
        try { $pdo->exec('ALTER TABLE players ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL'); } catch (Throwable $e) {}
        if ($emailEdit !== '') { $set[] = 'email=?'; $params[] = $emailEdit; }
        $params[] = $uid;
        $pdo->prepare('UPDATE players SET ' . implode(',', $set) . ' WHERE id=?')->execute($params);

        // Update PvP stats
        $pvpFields = ['str_pts','spd_pts','end_pts','unspent'];
        try {
          $pvpNew = []; foreach ($pvpFields as $f) $pvpNew[$f] = max(0, (int)($_POST[$f] ?? 5));
          $pdo->prepare('INSERT INTO player_stats (pid, str_pts, spd_pts, end_pts, unspent) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE str_pts=VALUES(str_pts), spd_pts=VALUES(spd_pts), end_pts=VALUES(end_pts), unspent=VALUES(unspent)')->execute([$uid, $pvpNew['str_pts'], $pvpNew['spd_pts'], $pvpNew['end_pts'], $pvpNew['unspent']]);
        } catch (Throwable $e) {}

        $logp = $pdo->prepare('INSERT INTO admin_log (admin_id, target_id, field, old_value, new_value) VALUES (?,?,?,?,?)');
        $log = function ($f, $ov, $nv) use ($logp, $pid, $uid) {
          if ((string)$ov !== (string)$nv) { try { $logp->execute([$pid, $uid, $f, (string)$ov, (string)$nv]); } catch (Throwable $e) {} }
        };
        foreach ($intf as $f) $log($f, $old[$f] ?? '', $new[$f]);
        $log('role', $old['role'] ?? '', $nr);
        $log('sub_until', $old['sub_until'] ?? '', $sub);

        $editId = $uid; $msg = 'Player updated.';
      } else { $msg = 'No such player.'; }
    }
    elseif ($a === 'del_chat' && $canMod) {
      $pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]); $msg = 'Chat message deleted.';
    }
    elseif ($a === 'del_post' && $canMod) {
      $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]); $msg = 'Post deleted.';
    }
    elseif ($a === 'del_topic' && $canMod) {
      $tid = (int)($_POST['id'] ?? 0);
      $pdo->prepare('DELETE FROM posts WHERE topic_id=?')->execute([$tid]);
      $pdo->prepare('DELETE FROM topics WHERE id=?')->execute([$tid]); $msg = 'Topic deleted.';
    }
    elseif ($a === 'del_update' && $canAdmin) {
      $uidd = (int)($_POST['id'] ?? 0);
      $pdo->prepare('DELETE FROM updates WHERE id=?')->execute([$uidd]);
      try { $pdo->prepare('DELETE FROM update_votes WHERE update_id=?')->execute([$uidd]); } catch (Throwable $e) {}
      $msg = 'Update deleted.';
    }
    elseif ($a === 'edit_update' && $canAdmin) {
      $uidd = (int)($_POST['id'] ?? 0); $b = trim($_POST['body'] ?? '');
      if ($b !== '') { $pdo->prepare('UPDATE updates SET body=? WHERE id=?')->execute([$b, $uidd]); $msg = 'Update edited.'; }
    }

  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$flash = $msg ? '<div class="flash">'.e($msg).'</div>' : '';
$back  = '<p class="muted"><a href="index.php?p=admin">&laquo; Admin</a></p>';

/* ============================ EDIT PLAYERS ============================ */
if ($sec === 'editplayer' && $canAdmin) {
  $t = null;
  if ($editId) { $ep = $pdo->prepare('SELECT * FROM players WHERE id=?'); $ep->execute([$editId]); $t = $ep->fetch(); }
  ?>
  <div class="panel">
    <h2>Edit Players</h2>
    <?= $back ?><?= $flash ?>
    <form method="post" style="margin-bottom:10px">
      <input type="hidden" name="action" value="find">
      <label>Find by handle</label>
      <div style="position:relative;max-width:240px">
        <p style="display:flex;gap:6px;margin:0"><input type="text" name="handle" id="adminFind" autocomplete="off" maxlength="32"><button type="submit">Find</button></p>
        <div id="adminAC" style="position:absolute;left:0;right:42px;top:34px;background:var(--panel);border:1px solid var(--accent);border-radius:4px;z-index:20;display:none;max-height:200px;overflow:auto"></div>
      </div>
    </form>
    <script>
    (function(){
      var inp=document.getElementById('adminFind'), box=document.getElementById('adminAC'); if(!inp) return;
      var t;
      inp.addEventListener('input',function(){
        clearTimeout(t); var q=inp.value.trim();
        if(q.length<1){ box.style.display='none'; return; }
        t=setTimeout(function(){
          fetch('admin_api.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(list){
            if(!list||!list.length){ box.style.display='none'; return; }
            box.innerHTML='';
            list.forEach(function(u){ var a=document.createElement('a'); a.href='index.php?p=admin&sec=editplayer&u='+u.id;
              a.textContent=u.username; a.style.display='block'; a.style.padding='5px 8px'; a.style.fontSize='12px'; box.appendChild(a); });
            box.style.display='block';
          }).catch(function(){});
        },150);
      });
      document.addEventListener('click',function(e){ if(box && !box.contains(e.target) && e.target!==inp) box.style.display='none'; });
    })();
    </script>
    <?php if ($t):
      // Load PvP stats
      $pvpS = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];
      try { $pq = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $pq->execute([(int)$t['id']]); $ps = $pq->fetch(); if ($ps) $pvpS = $ps; } catch (Throwable $e) {}
      // Load jail status
      $curJail = null;
      try { $jq = $pdo->prepare("SELECT j.*, s.username AS staff_name FROM jail_records j JOIN players s ON s.id=j.jailed_by WHERE j.player_id=? AND j.status='active' AND j.release_at>NOW() LIMIT 1"); $jq->execute([(int)$t['id']]); $curJail = $jq->fetch() ?: null; } catch (Throwable $e) {}
      // Load syndicate
      $syndName = null;
      try { $sq = $pdo->prepare('SELECT s.name FROM syndicate_members m JOIN syndicates s ON s.id=m.syndicate_id WHERE m.player_id=? LIMIT 1'); $sq->execute([(int)$t['id']]); $syndName = $sq->fetchColumn() ?: null; } catch (Throwable $e) {}
    ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:16px">
      <div style="font-size:18px;font-weight:700;color:var(--accent)"><?= e($t['username']) ?></div>
      <div style="font-size:11px;color:var(--muted)">ID: <?= (int)$t['id'] ?> &middot; Level <?= (int)$t['level'] ?> &middot; Joined: <?= e(date('M j, Y', strtotime($t['created_at'] ?? 'now'))) ?></div>
      <?php if ($syndName): ?><div style="font-size:11px;color:var(--accent);margin-top:3px">Syndicate: <?= e($syndName) ?></div><?php endif; ?>
      <?php if ($curJail): ?><div style="font-size:11px;color:var(--neon2);margin-top:3px">&#128274; JAILED — <?= e($curJail['reason']) ?> &middot; <?= ceil((strtotime($curJail['release_at'])-time())/86400) ?>d left</div><?php endif; ?>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="save_player">
      <input type="hidden" name="uid" value="<?= (int)$t['id'] ?>">

      <h4 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Economy &amp; Core Stats</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:16px">
        <?php foreach (['level'=>'Level','creds_pocket'=>'Credits (pocket)','creds_bank'=>'Credits (bank)','shards'=>'Shards',
                        'integrity'=>'Health','integrity_max'=>'Health max','xp'=>'XP','xp_next'=>'XP next',
                        'signal'=>'Signal','signal_max'=>'Signal max','cycles'=>'Drive','cycles_max'=>'Drive max',
                        'loan'=>'Loan (owed)'] as $k=>$lbl): ?>
          <div><label style="font-size:11px"><?= $lbl ?></label><input type="number" name="<?= $k ?>" value="<?= (int)($t[$k] ?? 0) ?>"></div>
        <?php endforeach; ?>
        <div>
          <label style="font-size:11px">Email</label>
          <input type="email" name="edit_email" value="<?= e($t['email'] ?? '') ?>" placeholder="(optional)">
        </div>
        <div><label style="font-size:11px">Role</label>
          <select name="role">
            <?php foreach (['member','chatmod','moderator','admin','manager'] as $rr): ?>
              <option value="<?= $rr ?>" <?= ($t['role'] ?? 'member') === $rr ? 'selected' : '' ?>><?= $rr ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label style="font-size:11px">Subscription until</label><input type="date" name="sub_until" value="<?= e($t['sub_until'] ?? '') ?>"></div>
      </div>

      <h4 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Combat Stats (PvP)</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:16px">
        <div><label style="font-size:11px">Strength</label><input type="number" name="str_pts" min="1" value="<?= (int)$pvpS['str_pts'] ?>"></div>
        <div><label style="font-size:11px">Speed</label><input type="number" name="spd_pts" min="1" value="<?= (int)$pvpS['spd_pts'] ?>"></div>
        <div><label style="font-size:11px">Endurance</label><input type="number" name="end_pts" min="1" value="<?= (int)$pvpS['end_pts'] ?>"></div>
        <div><label style="font-size:11px">Unspent Points</label><input type="number" name="unspent" min="0" value="<?= (int)$pvpS['unspent'] ?>"></div>
      </div>

      <p><button type="submit">Save Player</button></p>
    </form>

    <!-- Jail Controls -->
    <div style="background:rgba(255,45,149,.04);border:1px solid rgba(255,45,149,.2);border-radius:8px;padding:14px;margin-top:14px">
      <h4 style="margin:0 0 12px;color:var(--neon2)">&#128274; Jail Controls</h4>
      <?php if ($curJail): ?>
        <p style="font-size:12px;color:var(--muted);margin-bottom:10px">Currently jailed by <?= e($curJail['staff_name']) ?> &middot; Reason: <?= e($curJail['reason']) ?> &middot; Releases: <?= e(date('M j, Y', strtotime($curJail['release_at']))) ?></p>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="unjail_player">
          <input type="hidden" name="unjail_uid" value="<?= (int)$t['id'] ?>">
          <button type="submit" style="background:rgba(59,207,99,.08);border-color:rgba(59,207,99,.3);color:#3bcf63">Release from Jail</button>
        </form>
      <?php else: ?>
        <form method="post" style="display:grid;grid-template-columns:1fr 1fr 80px auto;gap:8px;align-items:end">
          <input type="hidden" name="action" value="jail_player">
          <input type="hidden" name="jail_uid" value="<?= (int)$t['id'] ?>">
          <div><label style="font-size:11px">Reason</label><input type="text" name="jail_reason" maxlength="500" placeholder="Reason for detention" style="width:100%"></div>
          <div><label style="font-size:11px">Days</label><input type="number" name="jail_days" min="1" max="365" value="1" style="width:100%"></div>
          <div><button type="submit" style="background:rgba(255,45,149,.1);border-color:rgba(255,45,149,.4);color:var(--neon2);width:100%">Jail</button></div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ TRANSACTION LOG ============================ */
if ($sec === 'txlog' && $canAdmin) {
  ?>
  <div class="panel">
    <h2>Transaction Log</h2>
    <?= $back ?><?= $flash ?>
    <?php
      $tx = [];
      try {
        $tx = $pdo->query("SELECT t.*, f.username AS fn, r.username AS rn
                           FROM tx_log t LEFT JOIN players f ON f.id=t.from_id LEFT JOIN players r ON r.id=t.to_id
                           ORDER BY t.id DESC LIMIT 100")->fetchAll();
      } catch (Throwable $e) { echo '<p class="muted">Run schema_txlog.sql to enable the transaction log.</p>'; }
    ?>
    <?php if ($tx): ?>
    <table>
      <tr><th>When</th><th>Kind</th><th>From</th><th>To</th><th>Amount</th><th>Note</th></tr>
      <?php foreach ($tx as $r): ?>
      <tr><td class="muted"><?= e($r['created_at']) ?></td><td><?= e($r['kind']) ?></td>
          <td><?= e($r['fn'] ?? '—') ?></td><td><?= e($r['rn'] ?? '—') ?></td>
          <td><?= number_format($r['amount']) ?></td><td class="muted"><?= e($r['note']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php elseif (empty($tx)): ?><p class="muted">No transactions logged yet.</p><?php endif; ?>
  </div>
  <?php return;
}

/* ============================ PLAYER EDIT LOG ============================ */
if ($sec === 'editlog' && $canAdmin) {
  ?>
  <div class="panel">
    <h2>Player Edit Log</h2>
    <?= $back ?><?= $flash ?>
    <?php
      $alog = [];
      try {
        $alog = $pdo->query("SELECT l.*, a.username AS an, t.username AS tn
                             FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id LEFT JOIN players t ON t.id=l.target_id
                             ORDER BY l.id DESC LIMIT 100")->fetchAll();
      } catch (Throwable $e) { echo '<p class="muted">Run schema_admin_profile.sql to enable the edit log.</p>'; }
    ?>
    <?php if ($alog): ?>
    <table>
      <tr><th>When</th><th>Admin</th><th>Player</th><th>Field</th><th>Change</th></tr>
      <?php foreach ($alog as $l): ?>
      <tr>
        <td class="muted" style="font-size:11px"><?= e($l['created_at']) ?></td>
        <td><?= e($l['an'] ?? '—') ?></td>
        <td><?= e($l['tn'] ?? '—') ?></td>
        <td><?= e($l['field']) ?></td>
        <td><span class="muted"><?= $l['old_value'] === '' ? '&empty;' : e($l['old_value']) ?></span>
            &rarr; <b style="color:var(--accent)"><?= $l['new_value'] === '' ? '&empty;' : e($l['new_value']) ?></b></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php elseif (empty($alog)): ?><p class="muted">No edits logged yet.</p><?php endif; ?>
  </div>
  <?php return;
}

/* ============================ MODERATION ============================ */
if ($sec === 'moderation') {
  ?>
  <div class="panel">
    <h2>Moderation Tools</h2>
    <?= $back ?><?= $flash ?>
    <p class="muted">Recent chat</p>
    <table>
      <tr><th>When</th><th>Who</th><th>Message</th><th></th></tr>
      <?php foreach ($pdo->query("SELECT c.id,c.body,c.created_at,p.username FROM chat_messages c JOIN players p ON p.id=c.player_id ORDER BY c.id DESC LIMIT 20") as $c): ?>
      <tr><td class="muted"><?= e($c['created_at']) ?></td><td><?= e($c['username']) ?></td><td><?= e(mb_substr($c['body'],0,60)) ?></td>
          <td><form method="post" style="margin:0"><input type="hidden" name="action" value="del_chat"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button>Delete</button></form></td></tr>
      <?php endforeach; ?>
    </table>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
      <form method="post"><input type="hidden" name="action" value="del_post"><label>Delete board post by ID</label>
        <p style="display:flex;gap:6px"><input type="number" name="id"><button>Delete</button></p></form>
      <form method="post"><input type="hidden" name="action" value="del_topic"><label>Delete board topic by ID</label>
        <p style="display:flex;gap:6px"><input type="number" name="id"><button>Delete</button></p></form>
    </div>
    <p class="muted" style="font-size:11px;margin-top:8px">Tip: you can also delete a post directly from a thread on the <a href="index.php?p=boards">Message Boards</a>.</p>
  </div>
  <?php return;
}

/* ============================ IP LOG ============================ */
if ($sec === 'iplog') {
  $filterIp     = trim($_GET['ip'] ?? '');
  $filterPlayer = trim($_GET['player'] ?? '');
  $filterAction = $_GET['act'] ?? '';
  $page = max(1, (int)($_GET['pg'] ?? 1));
  $perPage = 50;
  $offset = ($page - 1) * $perPage;

  $where = []; $params = [];
  if ($filterIp !== '')     { $where[] = 'l.ip LIKE ?';              $params[] = '%'.$filterIp.'%'; }
  if ($filterPlayer !== '') { $where[] = 'p.username LIKE ?';        $params[] = '%'.$filterPlayer.'%'; }
  if ($filterAction !== '') { $where[] = 'l.action = ?';             $params[] = $filterAction; }
  $wq = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $rows = []; $total = 0;
  try {
    $countParams = $params;
    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM ip_log l LEFT JOIN players p ON p.id=l.player_id $wq")->execute($countParams) ? $pdo->prepare("SELECT COUNT(*) FROM ip_log l LEFT JOIN players p ON p.id=l.player_id $wq")->execute($countParams) : 0;
    $cq = $pdo->prepare("SELECT COUNT(*) FROM ip_log l LEFT JOIN players p ON p.id=l.player_id $wq");
    $cq->execute($params); $total = (int)$cq->fetchColumn();
    $lq = $pdo->prepare("SELECT l.*, p.username FROM ip_log l LEFT JOIN players p ON p.id=l.player_id $wq ORDER BY l.id DESC LIMIT $perPage OFFSET $offset");
    $lq->execute($params); $rows = $lq->fetchAll();
  } catch (Throwable $e) {
    echo '<div class="panel"><h2>&#127758; IP &amp; Access Log</h2>'.$back.'<p class="muted">Run <code>schema_iplog.sql</code> to enable this feature.</p></div>';
    return;
  }

  $pages = max(1, (int)ceil($total / $perPage));
  $qBase = 'index.php?p=admin&sec=iplog'.($filterIp?'&ip='.urlencode($filterIp):'').($filterPlayer?'&player='.urlencode($filterPlayer):'').($filterAction?'&act='.urlencode($filterAction):'');

  $actionColors = ['login'=>'#3bcf63','fail'=>'#ff2d95','register'=>'#19f0c7','logout'=>'#8a8fa8'];
  ?>
  <div class="panel">
    <h2>&#127758; IP &amp; Access Log</h2>
    <?= $back ?><?= $flash ?>

    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end">
      <input type="hidden" name="p" value="admin">
      <input type="hidden" name="sec" value="iplog">
      <div><label style="font-size:11px">Filter IP</label><br><input type="text" name="ip" value="<?= e($filterIp) ?>" placeholder="e.g. 192.168." style="width:140px"></div>
      <div><label style="font-size:11px">Filter Player</label><br><input type="text" name="player" value="<?= e($filterPlayer) ?>" placeholder="username..." style="width:140px"></div>
      <div><label style="font-size:11px">Action</label><br>
        <select name="act">
          <option value="">All</option>
          <?php foreach (['login','fail','register','logout'] as $a): ?>
            <option value="<?= $a ?>" <?= $filterAction===$a?'selected':'' ?>><?= ucfirst($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button type="submit">Filter</button> <?php if ($filterIp||$filterPlayer||$filterAction): ?><a href="index.php?p=admin&sec=iplog" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?></div>
    </form>

    <div class="muted" style="font-size:11px;margin-bottom:8px"><?= number_format($total) ?> entr<?= $total===1?'y':'ies' ?> &middot; page <?= $page ?> of <?= $pages ?></div>

    <?php if ($rows): ?>
    <div style="overflow-x:auto">
    <table>
      <tr><th>When</th><th>Action</th><th>Player</th><th>IP</th><th>User Agent</th></tr>
      <?php foreach ($rows as $r):
        $acol = $actionColors[$r['action']] ?? '#8a8fa8';
      ?>
      <tr>
        <td class="muted" style="font-size:11px;white-space:nowrap"><?= e($r['created_at']) ?></td>
        <td><span style="color:<?= $acol ?>;font-weight:600;font-size:12px"><?= e(strtoupper($r['action'])) ?></span></td>
        <td><?php if ($r['player_id']): ?>
          <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$r['player_id'] ?>" style="font-size:12px"><?= e($r['username'] ?? '#'.(int)$r['player_id']) ?></a>
        <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td style="font-family:monospace;font-size:12px">
          <a href="index.php?p=admin&sec=iplog&ip=<?= urlencode($r['ip']) ?>" style="color:var(--text)"><?= e($r['ip']) ?></a>
        </td>
        <td class="muted" style="font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['user_agent']) ?>"><?= e(mb_substr($r['user_agent'],0,80)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap">
      <?php for ($i = max(1,$page-3); $i <= min($pages,$page+3); $i++): ?>
        <a href="<?= $qBase ?>&pg=<?= $i ?>" class="btn btn-ghost btn-sm" <?= $i===$page?'style="color:var(--accent);font-weight:700"':'' ?>><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= $qBase ?>&pg=<?= $pages ?>" class="btn btn-ghost btn-sm">Last &raquo;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <p class="muted" style="text-align:center;padding:24px 0">No log entries yet. Entries appear after players log in.</p>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ MAINTENANCE (stub) ============================ */
if ($sec === 'maintenance' && $canAdmin) {
  ?>
  <div class="panel">
    <h2>&#9881; Maintenance</h2>
    <?= $back ?>
    <p class="muted">Maintenance tools will be added here. Manage the database directly or via your hosting control panel.</p>
  </div>
  <?php return;
}

/* ============================ MANAGE UPDATES ============================ */
if ($sec === 'updates' && $canAdmin) {
  ?>
  <div class="panel">
    <h2>Manage Updates</h2>
    <?= $back ?><?= $flash ?>
    <?php foreach ($pdo->query("SELECT id,body,created_at FROM updates ORDER BY id DESC LIMIT 25") as $u): ?>
    <div style="border-bottom:1px solid var(--line);padding:8px 0">
      <div class="muted" style="font-size:11px"><?= e($u['created_at']) ?> &middot; #<?= (int)$u['id'] ?></div>
      <form method="post" style="display:flex;gap:6px;align-items:flex-start">
        <input type="hidden" name="action" value="edit_update">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <textarea name="body" style="min-height:40px"><?= e($u['body']) ?></textarea>
        <button type="submit">Save</button>
      </form>
      <form method="post" style="margin-top:4px"><input type="hidden" name="action" value="del_update"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button>Delete</button></form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php return;
}

/* ============================ HUB ============================ */
// Live stats for the hub
$hubStats = [];
try {
  $hs = db()->query("SELECT
    COUNT(*) AS total,
    SUM(last_seen >= (NOW() - INTERVAL 5 MINUTE)) AS online_now,
    SUM(sub_until >= CURDATE()) AS subs,
    SUM(creds_pocket) AS total_pocket,
    SUM(creds_bank) AS total_bank,
    SUM(loan) AS total_loans,
    AVG(level) AS avg_level,
    MAX(level) AS max_level,
    SUM(created_at >= (NOW() - INTERVAL 24 HOUR)) AS new_today,
    SUM(created_at >= (NOW() - INTERVAL 7 DAY)) AS new_week
  FROM players")->fetch();
  $hubStats = $hs;
} catch (Throwable $e) {}

$openReports = 0;
try { $openReports = (int)db()->query("SELECT COUNT(*) FROM reports WHERE status='open'")->fetchColumn(); } catch (Throwable $e) {}
$recentBans = [];
try { $recentBans = db()->query("SELECT username, role FROM players WHERE role='banned' ORDER BY id DESC LIMIT 5")->fetchAll(); } catch (Throwable $e) {}
?>

<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="margin:0">&#128737; Staff Panel</h2>
      <p class="muted" style="margin:4px 0 0">Role: <b style="color:var(--accent)"><?= e(role_label($role) ?: 'Member') ?></b></p>
    </div>
    <div class="muted" style="font-size:11px">Sprawl-9 &mdash; <?= date('M j, Y g:i a') ?></div>
  </div>
  <?= $flash ?>
</div>

<?php if ($canAdmin && $hubStats): ?>
<div class="panel">
  <h3 style="margin-bottom:12px">&#128202; Server Overview</h3>
  <div class="admin-stats-row">
    <div class="admin-stat-box">
      <div class="asb-val"><?= number_format($hubStats['total']) ?></div>
      <div class="asb-lbl">Total Players</div>
      <div class="asb-sub">+<?= (int)$hubStats['new_today'] ?> today &middot; +<?= (int)$hubStats['new_week'] ?> this week</div>
    </div>
    <div class="admin-stat-box">
      <div class="asb-val" style="color:#3bcf63"><?= number_format($hubStats['online_now']) ?></div>
      <div class="asb-lbl">Online Now</div>
      <div class="asb-sub">Last 5 minutes</div>
    </div>
    <div class="admin-stat-box">
      <div class="asb-val" style="color:#e8d44d"><?= number_format($hubStats['subs']) ?></div>
      <div class="asb-lbl">Subscribers</div>
      <div class="asb-sub">Active subscriptions</div>
    </div>
    <div class="admin-stat-box">
      <div class="asb-val"><?= number_format($hubStats['total_pocket'] + $hubStats['total_bank']) ?></div>
      <div class="asb-lbl">Total Creds</div>
      <div class="asb-sub">Pocket + bank</div>
    </div>
    <div class="admin-stat-box">
      <div class="asb-val" style="color:var(--neon2)"><?= number_format($hubStats['total_loans']) ?></div>
      <div class="asb-lbl">Outstanding Loans</div>
      <div class="asb-sub">Across all players</div>
    </div>
    <div class="admin-stat-box">
      <div class="asb-val"><?= round($hubStats['avg_level'] ?? 0, 1) ?></div>
      <div class="asb-lbl">Avg Level</div>
      <div class="asb-sub">Max: <?= (int)$hubStats['max_level'] ?></div>
    </div>
    <?php if ($openReports): ?>
    <div class="admin-stat-box" style="border-color:var(--neon2)">
      <div class="asb-val" style="color:var(--neon2)"><?= $openReports ?></div>
      <div class="asb-lbl">Open Reports</div>
      <div class="asb-sub">Needs attention</div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="staffgrid">
  <?php if ($canAdmin): ?>
  <a class="staffcard" href="index.php?p=admin&sec=editplayer">
    <span class="ic">&#128101;</span><h4>Players</h4>
    <p>Search and edit player accounts &mdash; stats, role &amp; subscription.</p>
    <span class="req">Admin+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=txlog">
    <span class="ic">&#128202;</span><h4>Economy</h4>
    <p>View cred transfer logs and monitor the economic health of the Sprawl.</p>
    <span class="req">Admin+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=updates">
    <span class="ic">&#128226;</span><h4>Announcements</h4>
    <p>Post, edit, and delete game update announcements.</p>
    <span class="req">Admin+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=editlog">
    <span class="ic">&#128220;</span><h4>Edit Log</h4>
    <p>Audit trail of all admin account changes &mdash; who changed what.</p>
    <span class="req">Admin+</span>
  </a>
  <?php endif; ?>
  <a class="staffcard" href="index.php?p=admin&sec=moderation">
    <span class="ic">&#128737;</span><h4>Moderation</h4>
    <p>Delete chat messages, board posts and topics.</p>
    <?php if ($openReports): ?><p style="color:var(--neon2);font-weight:bold">&#9888; <?= $openReports ?> open report(s)</p><?php endif; ?>
    <span class="req">Mod+</span>
  </a>
  <?php if (in_array($role, ['moderator','admin','manager'], true)): ?>
  <a class="staffcard" href="index.php?p=admin&sec=iplog">
    <span class="ic">&#127758;</span><h4>IP &amp; Access Log</h4>
    <p>Track logins, IP addresses, and flag suspicious session activity.</p>
    <span class="req">Mod+</span>
  </a>
  <?php endif; ?>
  <?php if ($canAdmin): ?>
  <a class="staffcard" href="index.php?p=jail">
    <span class="ic">&#128274;</span><h4>Confinement Grid</h4>
    <p>Jail players for violations. Set reason and duration. Release early.</p>
    <span class="req">Admin+</span>
  </a>
  <a class="staffcard" href="index.php?p=cityhall">
    <span class="ic">&#127963;</span><h4>City Hall</h4>
    <p>Staff roster, subscriber list, jail records, and game rules.</p>
    <span class="req">Admin+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=maintenance">
    <span class="ic">&#9881;</span><h4>Maintenance</h4>
    <p>Server flags, maintenance mode toggle, and cache controls.</p>
    <span class="req">Admin+</span>
  </a>
  <?php endif; ?>
</div>

<?php if ($canAdmin): ?>
<div class="panel" style="border:1px solid rgba(255,45,149,.2);background:rgba(255,45,149,.03)">
  <h3 style="margin-top:0;color:var(--neon2)">&#128737; Impersonation &amp; View Tools</h3>
  <p class="muted" style="font-size:12px;margin-bottom:14px">Impersonate a player to see the game exactly as they do. Your session is flagged — a stop banner is always shown. You can also preview how the UI looks for a specific staff role.</p>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;flex-wrap:wrap">
    <div>
      <h4 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">Impersonate Player</h4>
      <form method="post" style="display:flex;gap:8px">
        <input type="hidden" name="action" value="impersonate">
        <input type="text" name="imp_handle" placeholder="Ghost's handle" style="flex:1">
        <button type="submit" style="background:rgba(255,45,149,.1);border-color:rgba(255,45,149,.4);color:var(--neon2)">Jack In</button>
      </form>
    </div>
    <div>
      <h4 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">View as Role <?= !empty($_SESSION['role_override']) ? '(<b style="color:var(--accent)">'.e($_SESSION['role_override']).'</b>)' : '' ?></h4>
      <form method="post" style="display:flex;gap:8px">
        <input type="hidden" name="action" value="role_preview">
        <select name="preview_role" style="flex:1">
          <option value="">— clear override —</option>
          <?php foreach (['member','chatmod','moderator','admin','manager'] as $rr): ?>
          <option value="<?= $rr ?>" <?= ($_SESSION['role_override'] ?? '') === $rr ? 'selected' : '' ?>><?= ucfirst($rr) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Apply</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

