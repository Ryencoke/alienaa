<?php /* pages/account.php — account summary + chat settings */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'chatcolor') {
  $c = $_POST['chat_color'] ?? '';
  if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
    $pdo->prepare('UPDATE players SET chat_color = ? WHERE id = ?')->execute([$c, $pid]);
    $msg = 'Chat color updated.';
    $player = current_player();
  } else {
    $msg = 'Pick a valid color.';
  }
}

$role = $player['role'] ?? 'member';
?>
<div class="panel">
  <h2>Account &mdash; <?= e($player['username']) ?></h2>
  <p class="muted">Your ghost's vitals, as far as the Grid is willing to admit it knows you.</p>
  <table>
    <tr><th>Handle</th><td><?= e($player['username']) ?></td></tr>
    <tr><th>Level</th><td><?= (int)$player['level'] ?></td></tr>
    <tr><th>XP</th><td><?= number_format($player['xp']) ?> / <?= number_format($player['xp_next']) ?></td></tr>
    <tr><th>Creds (pocket)</th><td><?= number_format($player['creds_pocket']) ?></td></tr>
    <tr><th>Creds (bank)</th><td><?= number_format($player['creds_bank']) ?></td></tr>
    <tr><th>Shards</th><td><?= number_format($player['shards']) ?></td></tr>
    <tr><th>Role</th><td><?= $role === 'member' ? 'Member' : e(role_label($role)) ?></td></tr>
    <tr><th>Jacked in since</th><td><?= e($player['created_at']) ?></td></tr>
  </table>
</div>

<div class="panel">
  <h3>Chat Settings</h3>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($role !== 'member'): ?>
    <p>Your name shows in
      <b style="color:<?= e(chat_color($role, '')) ?>"><?= e(role_label($role)) ?></b>
      staff color &mdash; it overrides any personal color.</p>
    <p class="muted"><b style="color:<?= e(chat_color($role, '')) ?>"><?= e($player['username']) ?>:</b> sample message</p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="action" value="chatcolor">
      <label>Your chat name color</label>
      <p><input type="color" name="chat_color" value="<?= e($player['chat_color'] ?? '#c9d1e0') ?>"
         style="width:64px;height:34px;padding:2px"></p>
      <p class="muted"><b style="color:<?= e(chat_color('member', $player['chat_color'] ?? '')) ?>"><?= e($player['username']) ?>:</b> sample message</p>
      <p><button type="submit">Save Color</button></p>
    </form>
  <?php endif; ?>
</div>
