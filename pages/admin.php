<?php /* pages/admin.php — staff admin panel */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$role = $player['role'] ?? 'member';
$canMod   = in_array($role, ['chatmod','moderator','admin','manager'], true);
$canAdmin = in_array($role, ['admin','manager'], true);

if (!$canMod) { echo '<div class="panel"><h2>Staff Admin</h2><p class="muted">Staff access only.</p></div>'; return; }

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
      $iv = function ($k) { return max(0, (int)($_POST[$k] ?? 0)); };
      $pdo->prepare('UPDATE players SET level=?, creds_pocket=?, creds_bank=?, shards=?, integrity=?, integrity_max=?,
                       xp=?, xp_next=?, `signal`=?, signal_max=?, cycles=?, cycles_max=? WHERE id=?')
          ->execute([$iv('level'),$iv('creds_pocket'),$iv('creds_bank'),$iv('shards'),$iv('integrity'),$iv('integrity_max'),
                     $iv('xp'),$iv('xp_next'),$iv('signal'),$iv('signal_max'),$iv('cycles'),$iv('cycles_max'),$uid]);
      $nr = $_POST['role'] ?? '';
      if (in_array($nr, ['member','chatmod','moderator','admin','manager'], true))
        $pdo->prepare('UPDATE players SET role=? WHERE id=?')->execute([$nr, $uid]);
      $editId = $uid; $msg = 'Player updated.';
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
?>
<div class="panel">
  <h2>Staff Admin</h2>
  <p class="muted">Signed in as <b><?= e(role_label($role) ?: 'Member') ?></b>. Handle with care.</p>
  <?= $flash ?>
</div>

<?php if ($canAdmin): ?>
<div class="panel">
  <h3>Edit Player</h3>
  <form method="post" style="margin-bottom:10px">
    <input type="hidden" name="action" value="find">
    <label>Find by handle</label>
    <p style="display:flex;gap:6px"><input type="text" name="handle" maxlength="32" style="max-width:220px"><button type="submit">Find</button></p>
  </form>
  <?php
    $t = null;
    if ($editId) { $ep = $pdo->prepare('SELECT * FROM players WHERE id=?'); $ep->execute([$editId]); $t = $ep->fetch(); }
    if ($t):
  ?>
  <form method="post">
    <input type="hidden" name="action" value="save_player">
    <input type="hidden" name="uid" value="<?= (int)$t['id'] ?>">
    <p><b><?= e($t['username']) ?></b> &middot; id <?= (int)$t['id'] ?></p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px">
      <?php foreach (['level'=>'Level','creds_pocket'=>'Creds (pocket)','creds_bank'=>'Creds (bank)','shards'=>'Shards',
                      'integrity'=>'Integrity','integrity_max'=>'Integrity max','xp'=>'XP','xp_next'=>'XP next',
                      'signal'=>'Signal','signal_max'=>'Signal max','cycles'=>'Cycles','cycles_max'=>'Cycles max'] as $k=>$lbl): ?>
        <div><label><?= $lbl ?></label><input type="number" name="<?= $k ?>" value="<?= (int)$t[$k] ?>"></div>
      <?php endforeach; ?>
      <div><label>Role</label>
        <select name="role">
          <?php foreach (['member','chatmod','moderator','admin','manager'] as $rr): ?>
            <option value="<?= $rr ?>" <?= ($t['role'] ?? 'member') === $rr ? 'selected' : '' ?>><?= $rr ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <p><button type="submit">Save Player</button></p>
  </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Transaction Log</h3>
  <?php
    $tx = [];
    try {
      $tx = $pdo->query("SELECT t.*, f.username AS fn, r.username AS rn
                         FROM tx_log t LEFT JOIN players f ON f.id=t.from_id LEFT JOIN players r ON r.id=t.to_id
                         ORDER BY t.id DESC LIMIT 40")->fetchAll();
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
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h3>Moderation</h3>
  <p class="muted">Recent chat</p>
  <table>
    <tr><th>When</th><th>Who</th><th>Message</th><th></th></tr>
    <?php foreach ($pdo->query("SELECT c.id,c.body,c.created_at,p.username FROM chat_messages c JOIN players p ON p.id=c.player_id ORDER BY c.id DESC LIMIT 15") as $c): ?>
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
</div>

<?php if ($canAdmin): ?>
<div class="panel">
  <h3>Manage Updates</h3>
  <?php foreach ($pdo->query("SELECT id,body,created_at FROM updates ORDER BY id DESC LIMIT 15") as $u): ?>
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
<?php endif; ?>
