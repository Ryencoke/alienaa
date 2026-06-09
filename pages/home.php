<?php /* pages/home.php — Hideout: player stats dashboard */
$role = $player['role'] ?? 'member';
?>
<div class="panel">
  <h2><?= e($player['username']) ?>'s Hideout</h2>
  <p class="muted">You don't own a flat. You squat in a dead server closet in The Stacks. Cozy.</p>
  <p>Welcome back to the Sprawl. Your rig is online and your creds are (mostly) intact.</p>
</div>

<div class="panel">
  <h3>Status</h3>
  <div class="stat-grid">
    <div class="stat-card">
      <div class="val"><?= (int)$player['level'] ?></div>
      <div class="lbl">Level</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($player['xp']) ?></div>
      <div class="lbl">XP &nbsp;<span class="muted">/ <?= number_format($player['xp_next']) ?></span></div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($player['creds_pocket']) ?></div>
      <div class="lbl">Creds (Pocket)</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($player['creds_bank']) ?></div>
      <div class="lbl">Creds (Bank)</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($player['shards']) ?></div>
      <div class="lbl">Shards</div>
    </div>
    <div class="stat-card">
      <div class="val" style="font-size:1rem"><?= $role === 'member' ? 'Member' : e(role_label($role)) ?></div>
      <div class="lbl">Role
        <?php $fl = country_flag($player['country'] ?? ''); if ($fl) echo ' &nbsp;' . $fl; ?>
        <?php if (is_subscribed($player)) echo ' <span title="Subscriber" style="color:#e8d44d">&#9733;</span>'; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <h3>Condition</h3>
  <div class="stat-grid">
    <div class="stat-card">
      <div class="val"><?= (int)$player['integrity'] ?> <span class="muted" style="font-size:1rem">/ <?= (int)$player['integrity_max'] ?></span></div>
      <div class="lbl">Integrity</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= (int)$player['signal'] ?> <span class="muted" style="font-size:1rem">/ <?= (int)$player['signal_max'] ?></span></div>
      <div class="lbl">Signal</div>
    </div>
    <div class="stat-card">
      <div class="val"><?= number_format($player['cycles']) ?> <span class="muted" style="font-size:1rem">/ <?= number_format($player['cycles_max']) ?></span></div>
      <div class="lbl">Cycles</div>
    </div>
    <div class="stat-card">
      <div class="val" style="font-size:.9rem;color:var(--muted)"><?= e($player['created_at']) ?></div>
      <div class="lbl">Jacked in since</div>
    </div>
  </div>
</div>
