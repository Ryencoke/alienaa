<?php /* pages/profile.php — public player profile */
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$pq = $pdo->prepare('SELECT id, username, level, xp, xp_next, role, chat_color, bio, created_at
                     FROM players WHERE id = ?');
$pq->execute([$id]);
$prof = $pq->fetch();

if (!$prof) {
  echo '<div class="panel"><h2>Profile</h2><p class="muted">No such ghost on the Grid.</p></div>';
  return;
}

// Combat record (from the Sim log)
$rec = ['win' => 0, 'loss' => 0];
$rq = $pdo->prepare('SELECT outcome, COUNT(*) c FROM combat_log WHERE player_id = ? GROUP BY outcome');
$rq->execute([$id]);
foreach ($rq as $r) { if (isset($rec[$r['outcome']])) $rec[$r['outcome']] = (int)$r['c']; }

// Board post count
$pc = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?');
$pc->execute([$id]);
$postCount = (int)$pc->fetchColumn();

$col   = chat_color($prof['role'], $prof['chat_color']);
$role  = $prof['role'];
$isMe  = ((int)$prof['id'] === (int)$_SESSION['pid']);
?>
<div class="panel">
  <h2>Profile</h2>
  <p style="text-align:center;margin:0">
    <b style="color:<?= e($col) ?>;font-size:17px"><?= e($prof['username']) ?></b>
    <?php if ($role !== 'member'): ?> <span class="muted">[<?= e(role_label($role)) ?>]</span><?php endif; ?>
  </p>
  <?php if (trim($prof['bio']) !== ''): ?>
    <p class="muted" style="text-align:center;font-style:italic">&ldquo;<?= e($prof['bio']) ?>&rdquo;</p>
  <?php endif; ?>
  <?php if ($isMe): ?>
    <p style="text-align:center"><a href="index.php?p=account">Edit your profile &amp; settings</a></p>
  <?php endif; ?>

  <h3>General</h3>
  <table>
    <tr><th>Ghost ID</th><td><?= (int)$prof['id'] ?></td>
        <th>Level</th><td><?= (int)$prof['level'] ?></td></tr>
    <tr><th>Role</th><td><?= $role === 'member' ? 'Member' : e(role_label($role)) ?></td>
        <th>XP</th><td><?= number_format($prof['xp']) ?> / <?= number_format($prof['xp_next']) ?></td></tr>
    <tr><th>Jacked in since</th><td><?= e($prof['created_at']) ?></td>
        <th>Board posts</th><td><?= number_format($postCount) ?></td></tr>
  </table>

  <h3>Combat Record</h3>
  <table>
    <tr><th>Wins</th><td><?= (int)$rec['win'] ?></td>
        <th>Losses</th><td><?= (int)$rec['loss'] ?></td></tr>
  </table>
</div>
