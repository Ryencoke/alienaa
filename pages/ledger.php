<?php
$pid = $_SESSION['pid'];
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $amt = max(0, (int)($_POST['amount'] ?? 0));
  $action = $_POST['action'] ?? '';
  $pdo = db();
  if ($action==='deposit' && $amt > 0 && $amt <= $player['creds_pocket']) {
    $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-?, creds_bank=creds_bank+? WHERE id=?')
        ->execute([$amt,$amt,$pid]);
    $msg = number_format($amt).' creds stashed.';
  } elseif ($action==='withdraw' && $amt > 0 && $amt <= $player['creds_bank']) {
    $pdo->prepare('UPDATE players SET creds_bank=creds_bank-?, creds_pocket=creds_pocket+? WHERE id=?')
        ->execute([$amt,$amt,$pid]);
    $msg = number_format($amt).' creds pulled.';
  } else { $msg = 'Transaction rejected.'; }
  $player = current_player();
}
?>
<div class="panel">
  <h2>Iron Ledger Credit Union</h2>
  <p class="muted">Skimming a little off the top since the blackout.</p>
  <?php if($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Pocket: <b><?= number_format($player['creds_pocket']) ?></b> creds &nbsp;|&nbsp;
     Bank: <b><?= number_format($player['creds_bank']) ?></b> creds</p>

  <form method="post" style="margin-bottom:12px">
    <input type="hidden" name="action" value="deposit">
    <label>Deposit</label>
    <input type="number" name="amount" min="1" max="<?= $player['creds_pocket'] ?>">
    <button type="submit">Stash</button>
  </form>
  <form method="post">
    <input type="hidden" name="action" value="withdraw">
    <label>Withdraw</label>
    <input type="number" name="amount" min="1" max="<?= $player['creds_bank'] ?>">
    <button type="submit">Pull</button>
  </form>
</div>
