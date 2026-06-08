<?php /* pages/home.php — Hideout: player stats dashboard */
$role = $player['role'] ?? 'member';
?>
<div class="panel">
  <h2><?= e($player['username']) ?>'s Hideout</h2>
  <p class="muted">You don't own a flat. You squat in a dead server closet in The Stacks. Cozy.</p>
  <p>Welcome back to the Sprawl. Your rig is online and your creds are (mostly) intact.</p>

  <h3>Personal Stats</h3>
  <table>
    <tr><th>Handle</th><td><?= e($player['username']) ?></td>
        <th>Role</th><td><?= $role === 'member' ? 'Member' : e(role_label($role)) ?></td></tr>
    <tr><th>Level</th><td><?= (int)$player['level'] ?></td>
        <th>XP</th><td><?= number_format($player['xp']) ?> / <?= number_format($player['xp_next']) ?></td></tr>
    <tr><th>Creds (pocket)</th><td><?= number_format($player['creds_pocket']) ?></td>
        <th>Creds (bank)</th><td><?= number_format($player['creds_bank']) ?></td></tr>
    <tr><th>Shards</th><td><?= number_format($player['shards']) ?></td>
        <th>Jacked in</th><td><?= e($player['created_at']) ?></td></tr>
  </table>

  <h3>Condition</h3>
  <table>
    <tr><th>Integrity</th><td><?= (int)$player['integrity'] ?> / <?= (int)$player['integrity_max'] ?></td>
        <th>Signal</th><td><?= (int)$player['signal'] ?> / <?= (int)$player['signal_max'] ?></td></tr>
    <tr><th>Cycles</th><td><?= number_format($player['cycles']) ?> / <?= number_format($player['cycles_max']) ?></td>
        <th></th><td></td></tr>
  </table>
</div>
