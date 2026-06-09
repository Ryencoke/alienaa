<?php /* pages/exchange.php — The Exchange Block: Shards vault + Subscription */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$today = date('Y-m-d');
const SUB_COST = 30;   // Shards
const SUB_DAYS = 30;   // days

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {
    if ($a === 'pull') {
      if (($player['shard_pull_at'] ?? null) === $today) throw new RuntimeException('You already cracked the vault today. Back tomorrow.');
      $got = random_int(0, 6);
      $pdo->prepare('UPDATE players SET shards = shards + ?, shard_pull_at = ? WHERE id = ?')->execute([$got, $today, $pid]);
      $msg = $got > 0 ? "You scrounged {$got} Shard" . ($got === 1 ? '' : 's') . " from the vault." : 'The vault was picked clean today. Try again tomorrow.';
    }
    elseif ($a === 'subscribe') {
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SUB_COST, $pid, SUB_COST]);
      if ($u->rowCount() !== 1) throw new RuntimeException('You need ' . SUB_COST . ' Shards to subscribe.');
      $cur  = $player['sub_until'] ?? null;
      $base = ($cur && $cur >= $today) ? $cur : $today;        // stack if re-upping early
      $until = date('Y-m-d', strtotime("$base +" . SUB_DAYS . " days"));
      $pdo->prepare('UPDATE players SET sub_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Subscribed! Active until {$until}.";
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
  $player = current_player();
}

$sub    = is_subscribed($player);
$pulled = (($player['shard_pull_at'] ?? null) === $today);
?>
<div class="panel">
  <h2>The Exchange <span class="muted">&mdash; The Exchange Block</span></h2>
  <p class="muted">Shards are the Sprawl's hard currency &mdash; scarce, shiny, and the only thing the Grid truly respects.</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Shards: <b><?= number_format($player['shards']) ?></b> &middot;
     Subscription: <b><?= $sub ? 'active until ' . e($player['sub_until']) : 'none' ?></b></p>
</div>

<div class="panel">
  <h3>The Shard Vault</h3>
  <p class="muted">Crack the vault once a day for a random haul of <b>0&ndash;6 Shards</b>. That's your lot &mdash; come back tomorrow.</p>
  <?php if ($pulled): ?>
    <p class="muted">You've already cracked it today. Resets at midnight (server time).</p>
  <?php else: ?>
    <form method="post"><input type="hidden" name="action" value="pull"><button type="submit">Crack the Vault</button></form>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Subscription</h3>
  <p class="muted">Subscribe for <b><?= SUB_COST ?> Shards</b> &rarr; <b><?= SUB_DAYS ?> days</b>. Unlocks
     <a href="index.php?p=cityhall&sec=cryo">Cryogenic Storage</a> and a subscriber star on your name. Re-up early and the days stack.</p>
  <p class="muted" style="font-size:11px">(Buying Shards with real money will come later; for now the vault is the only source.)</p>
  <form method="post"><input type="hidden" name="action" value="subscribe"><button type="submit">Subscribe (<?= SUB_COST ?> Shards)</button></form>
</div>
