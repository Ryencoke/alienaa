<?php /* pages/profile.php — public player profile (resilient to missing optional tables) */
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$pq = $pdo->prepare('SELECT * FROM players WHERE id = ?');
$pq->execute([$id]);
$prof = $pq->fetch();

if (!$prof) {
  echo '<div class="panel"><h2>Profile</h2><p class="muted">No such ghost on the Grid.</p></div>';
  return;
}

$role = $prof['role'] ?? 'member';
$ccol = $prof['chat_color'] ?? '#c9d1e0';
$bio  = $prof['bio'] ?? '';
$col  = chat_color($role, $ccol);
$rlbl = role_label($role);
$isMe = ((int)$prof['id'] === (int)($_SESSION['pid'] ?? 0));

// Combat record (combat_log may not exist if that migration wasn't run)
$rec = ['win' => 0, 'loss' => 0];
try {
  $rq = $pdo->prepare('SELECT outcome, COUNT(*) c FROM combat_log WHERE player_id = ? GROUP BY outcome');
  $rq->execute([$id]);
  foreach ($rq as $r) { if (isset($rec[$r['outcome']])) $rec[$r['outcome']] = (int)$r['c']; }
} catch (Throwable $e) { /* table missing — leave zeros */ }

// Board post count (posts may not exist)
$postCount = 0;
try {
  $pc = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?');
  $pc->execute([$id]);
  $postCount = (int)$pc->fetchColumn();
} catch (Throwable $e) { /* table missing — leave zero */ }
?>
<div class="panel">
  <h2>Profile</h2>
  <p style="text-align:center;margin:0">
    <b style="color:<?= e($col) ?>;font-size:17px"><?= e($prof['username']) ?></b>
    <?php if (is_subscribed($prof)): ?> <span title="Subscriber" style="color:#e8d44d">&#9733;</span><?php endif; ?>
    <?php if ($role !== 'member'): ?> <span class="muted">[<?= e($rlbl) ?>]</span><?php endif; ?>
  </p>
  <?php if (trim($bio) !== ''): ?>
    <p class="muted" style="text-align:center;font-style:italic">&ldquo;<?= e($bio) ?>&rdquo;</p>
  <?php endif; ?>
  <?php if ($isMe): ?>
    <p style="text-align:center"><a href="index.php?p=account">Edit your profile &amp; settings</a></p>
  <?php else: ?>
    <p style="text-align:center"><a href="index.php?p=messages&u=<?= (int)$prof['id'] ?>">Send Message</a></p>
  <?php endif; ?>

  <h3>General</h3>
  <table>
    <tr><th>Ghost ID</th><td><?= (int)$prof['id'] ?></td>
        <th>Level</th><td><?= (int)$prof['level'] ?></td></tr>
    <tr><th>Role</th><td><?= $role === 'member' ? 'Member' : e($rlbl) ?></td>
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
