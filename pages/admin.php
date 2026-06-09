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

    if ($a === 'find' && $canAdmin) {
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

        $set = []; $params = [];
        foreach ($intf as $f) { $set[] = ($f === 'signal' ? '`signal`' : $f) . '=?'; $params[] = $new[$f]; }
        $set[] = 'role=?';      $params[] = $nr;
        $set[] = 'sub_until=?'; $params[] = ($sub === '' ? null : $sub);
        $params[] = $uid;
        $pdo->prepare('UPDATE players SET ' . implode(',', $set) . ' WHERE id=?')->execute($params);

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
    <?php if ($t): ?>
    <form method="post">
      <input type="hidden" name="action" value="save_player">
      <input type="hidden" name="uid" value="<?= (int)$t['id'] ?>">
      <p><b><?= e($t['username']) ?></b> &middot; id <?= (int)$t['id'] ?></p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px">
        <?php foreach (['level'=>'Level','creds_pocket'=>'Creds (pocket)','creds_bank'=>'Creds (bank)','shards'=>'Shards',
                        'integrity'=>'Integrity','integrity_max'=>'Integrity max','xp'=>'XP','xp_next'=>'XP next',
                        'signal'=>'Signal','signal_max'=>'Signal max','cycles'=>'Cycles','cycles_max'=>'Cycles max',
                        'loan'=>'Loan (owed)'] as $k=>$lbl): ?>
          <div><label><?= $lbl ?></label><input type="number" name="<?= $k ?>" value="<?= (int)($t[$k] ?? 0) ?>"></div>
        <?php endforeach; ?>
        <div><label>Role</label>
          <select name="role">
            <?php foreach (['member','chatmod','moderator','admin','manager'] as $rr): ?>
              <option value="<?= $rr ?>" <?= ($t['role'] ?? 'member') === $rr ? 'selected' : '' ?>><?= $rr ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label>Subscription until</label><input type="date" name="sub_until" value="<?= e($t['sub_until'] ?? '') ?>"></div>
      </div>
      <p><button type="submit">Save Player</button></p>
    </form>
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
?>
<div class="panel">
  <h2>&#128737; Staff Panel</h2>
  <p class="muted">Role: <b style="color:var(--accent)"><?= e(role_label($role) ?: 'Member') ?></b></p>
  <?= $flash ?>
</div>
<div class="staffgrid">
  <?php if ($canAdmin): ?>
  <a class="staffcard" href="index.php?p=admin&sec=editplayer"><span class="ic">&#128101;</span><h4>Players</h4><p>Search and edit player accounts &mdash; stats, role &amp; subscription.</p><span class="req">Requires: Admin+</span></a>
  <a class="staffcard" href="index.php?p=admin&sec=editlog"><span class="ic">&#128220;</span><h4>Player Edit Log</h4><p>Audit trail of who changed what, old &rarr; new.</p><span class="req">Requires: Admin+</span></a>
  <a class="staffcard" href="index.php?p=admin&sec=txlog"><span class="ic">&#128202;</span><h4>Transaction Log</h4><p>Cred transfers between players.</p><span class="req">Requires: Admin+</span></a>
  <a class="staffcard" href="index.php?p=admin&sec=updates"><span class="ic">&#128226;</span><h4>Manage Updates</h4><p>Post, edit, and delete game updates.</p><span class="req">Requires: Admin+</span></a>
  <?php endif; ?>
  <a class="staffcard" href="index.php?p=admin&sec=moderation"><span class="ic">&#128737;</span><h4>Moderation</h4><p>Delete chat messages, board posts &amp; topics.</p><span class="req">Requires: Mod+</span></a>
</div>
