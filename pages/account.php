<?php /* pages/account.php — your account summary */ ?>
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
    <tr><th>Jacked in since</th><td><?= e($player['created_at']) ?></td></tr>
  </table>
</div>
<div class="panel">
  <h3>Security</h3>
  <p class="flash">NODE OFFLINE &mdash; passkey change coming online soon.</p>
</div>
