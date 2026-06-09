<?php /* pages/profile.php — public player profile */
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

$rec = ['win' => 0, 'loss' => 0];
try {
  $rq = $pdo->prepare('SELECT outcome, COUNT(*) c FROM combat_log WHERE player_id = ? GROUP BY outcome');
  $rq->execute([$id]);
  foreach ($rq as $r) { if (isset($rec[$r['outcome']])) $rec[$r['outcome']] = (int)$r['c']; }
} catch (Throwable $e) {}

$postCount = 0;
try {
  $pc = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?');
  $pc->execute([$id]);
  $postCount = (int)$pc->fetchColumn();
} catch (Throwable $e) {}
?>
<div class="panel">
  <h2>Profile</h2>
  <div class="profile-hero">
    <div class="profile-name" style="color:<?= e($col) ?>">
      <?= e($prof['username']) ?>
      <?php $fl = country_flag($prof['country'] ?? ''); if ($fl): ?>
        <span style="font-size:16px" title="<?= e($prof['country']) ?>"><?= $fl ?></span>
      <?php endif; ?>
      <?php if (is_subscribed($prof)): ?>
        <span title="Subscriber" style="color:#e8d44d">&#9733;</span>
      <?php endif; ?>
    </div>
    <?php if ($role !== 'member'): ?>
      <div style="margin-top:4px"><span class="muted">[<?= e($rlbl) ?>]</span></div>
    <?php endif; ?>
    <?php if (trim($bio) !== ''): ?>
      <p class="profile-bio">&ldquo;<?= e($bio) ?>&rdquo;</p>
    <?php endif; ?>
    <div style="margin-top:12px">
      <?php if ($isMe): ?>
        <a href="index.php?p=account" class="btn btn-ghost btn-sm">Edit Profile &amp; Settings</a>
      <?php else: ?>
        <a href="index.php?p=messages&u=<?= (int)$prof['id'] ?>" class="btn btn-ghost btn-sm">Send Message</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel">
  <h3>General</h3>
  <div class="stat-grid">
    <div class="stat-card">
      <div class="val"><?= (int)$prof['level'] ?></div>
      <div class="lbl">Level</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($prof['xp']) ?></div>
      <div class="lbl">XP</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= $role === 'member' ? 'Member' : e($rlbl) ?></div>
      <div class="lbl">Role</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($postCount) ?></div>
      <div class="lbl">Board Posts</div>
    </div>
  </div>
  <p class="muted" style="font-size:12px;margin:0">Ghost ID #<?= (int)$prof['id'] ?> &middot; Jacked in since <?= e($prof['created_at']) ?></p>
</div>

<div class="panel">
  <h3>Combat Record</h3>
  <div class="stat-grid">
    <div class="stat-card">
      <div class="val" style="color:var(--accent)"><?= (int)$rec['win'] ?></div>
      <div class="lbl">Wins</div>
    </div>
    <div class="stat-card">
      <div class="val" style="color:var(--neon2)"><?= (int)$rec['loss'] ?></div>
      <div class="lbl">Losses</div>
    </div>
  </div>
</div>
