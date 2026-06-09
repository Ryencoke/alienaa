<?php /* pages/sim.php — The Firewall: Combat Sim (PvE drones) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Tunables — adjust to taste.
const HEAL_PER_KIT = 10;            // integrity restored per Field Patch Kit
const ATK_BASE     = 4;             // base attack
const ATK_PER_LVL  = 2;             // attack gained per level
const ATK_PER_SKILL= 15;            // combat points per +1 attack
const DEF_PER_SKILL= 30;            // combat points per +1 defense

// Ensure skill rows exist (we read the `combat` skill).
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

// XP grant + level-up (guarded so it coexists with foundry.php's copy).
if (!function_exists('grant_xp')) {
  function grant_xp($pid, $amount) {
    $pdo = db();
    $r = $pdo->prepare('SELECT level, xp, xp_next FROM players WHERE id = ?');
    $r->execute([$pid]); $p = $r->fetch();
    $level = (int)$p['level']; $xp = (int)$p['xp'] + $amount; $next = (int)$p['xp_next'];
    $gained = 0;
    while ($xp >= $next && $level < 999) { $xp -= $next; $level++; $next = (int)round($next * 1.5); $gained++; }
    $pdo->prepare('UPDATE players SET level = ?, xp = ?, xp_next = ? WHERE id = ?')
        ->execute([$level, $xp, $next, $pid]);
    return $gained;
  }
}

// Resolve a whole fight server-side. Returns [outcome, dmg_dealt, dmg_taken, player_hp_left].
function sim_fight($pHp, $pAtk, $pDef, $e) {
  $eHp = (int)$e['hp']; $dealt = 0; $taken = 0;
  for ($i = 0; $i < 200; $i++) {
    $d = max(1, (int)round(($pAtk - (int)$e['defense']) * random_int(85, 115) / 100));
    $eHp -= $d; $dealt += $d;
    if ($eHp <= 0) return ['win', $dealt, $taken, $pHp];
    $ed = max(1, (int)round(((int)$e['attack'] - $pDef) * random_int(85, 115) / 100));
    $pHp -= $ed; $taken += $ed;
    if ($pHp <= 0) return ['loss', $dealt, $taken, 0];
  }
  return ['loss', $dealt, $taken, max(0, $pHp)]; // stalemate counts as a loss
}

// Read combat skill (for power calc).
$cs = $pdo->prepare("SELECT ps.points FROM player_skills ps
                     JOIN skills s ON s.id = ps.skill_id
                     WHERE s.code = 'combat' AND ps.player_id = ?");
$cs->execute([$pid]);
$combat = (int)($cs->fetchColumn() ?: 0);

// Equipped gear bonuses (only count if the player still owns the item).
$gearAtk = 0; $gearDef = 0;
try {
  if (!empty($player['equipped_weapon'])) {
    $gq = $pdo->prepare('SELECT i.atk FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0');
    $gq->execute([$pid, $player['equipped_weapon']]); $gearAtk = (int)$gq->fetchColumn();
  }
  if (!empty($player['equipped_armor'])) {
    $gq = $pdo->prepare('SELECT i.def FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0');
    $gq->execute([$pid, $player['equipped_armor']]); $gearDef = (int)$gq->fetchColumn();
  }
} catch (Throwable $e) { $gearAtk = 0; $gearDef = 0; }

/* ---------- action handling (inline, no redirect) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'engage') {
      if ((int)$player['integrity'] <= 0) throw new RuntimeException('Flatlined — patch up before you fight again.');

      $eq = $pdo->prepare('SELECT * FROM enemies WHERE code = ?');
      $eq->execute([$_POST['enemy'] ?? '']);
      $e = $eq->fetch();
      if (!$e) throw new RuntimeException('No such target on the deck.');
      if ((int)$player['level'] < (int)$e['level_req'])
        throw new RuntimeException("You're not jacked up enough for that — needs level {$e['level_req']}.");

      $pAtk = ATK_BASE + (int)$player['level'] * ATK_PER_LVL + intdiv($combat, ATK_PER_SKILL) + $gearAtk;
      $pDef = intdiv($combat, DEF_PER_SKILL) + $gearDef;
      [$outcome, $dealt, $taken, $endHp] = sim_fight((int)$player['integrity'], $pAtk, $pDef, $e);

      $credsWon = 0; $xpWon = 0; $lootMsg = '';
      $pdo->beginTransaction();
      $pdo->prepare('UPDATE players SET integrity = ? WHERE id = ?')->execute([$endHp, $pid]);

      if ($outcome === 'win') {
        $credsWon = random_int((int)$e['creds_min'], (int)$e['creds_max']);
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$credsWon, $pid]);
        $xpWon = (int)$e['xp_reward'];
        if ($e['loot_item_id'] && random_int(1, 100) <= (int)$e['loot_chance']) {
          $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,1)
                         ON DUPLICATE KEY UPDATE qty = qty + 1')->execute([$pid, $e['loot_item_id']]);
          $ln = $pdo->prepare('SELECT name FROM items WHERE id = ?'); $ln->execute([$e['loot_item_id']]);
          $lootMsg = ' Salvaged 1 ' . $ln->fetchColumn() . '.';
        }
      }

      $pdo->prepare('INSERT INTO combat_log (player_id, enemy_name, outcome, dmg_dealt, dmg_taken, creds_won, xp_won)
                     VALUES (?,?,?,?,?,?,?)')
          ->execute([$pid, $e['name'], $outcome, $dealt, $taken, $credsWon, $xpWon]);
      $pdo->commit();

      $lv = $xpWon > 0 ? grant_xp($pid, $xpWon) : 0;
      if ($outcome === 'win') {
        $msg = "You wrecked the {$e['name']}! +" . number_format($credsWon) . " creds, +{$xpWon} XP."
             . $lootMsg . ($lv ? " LEVEL UP (+{$lv})!" : '');
      } else {
        $msg = "The {$e['name']} dragged you out half-dead. Flatlined — slap on a Patch Kit.";
      }
    }

    elseif ($action === 'heal') {
      $pk = $pdo->query("SELECT id FROM items WHERE code = 'patch_kit'")->fetchColumn();
      if (!$pk) throw new RuntimeException('Patch Kits are not in the catalog yet.');
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE player_items SET qty = qty - 1
                          WHERE player_id = ? AND item_id = ? AND qty >= 1');
      $u->execute([$pid, $pk]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('No Patch Kits in your stash — craft one at the Foundry.'); }
      $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND item_id = ? AND qty = 0')->execute([$pid, $pk]);
      $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?) WHERE id = ?')
          ->execute([HEAL_PER_KIT, $pid]);
      $pdo->commit();
      $msg = 'Slapped on a Field Patch Kit. Integrity restored.';
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
  $player = current_player(); // refresh integrity / creds / level
}

/* ---------- data for rendering ---------- */
$pAtk = ATK_BASE + (int)$player['level'] * ATK_PER_LVL + intdiv($combat, ATK_PER_SKILL) + $gearAtk;
$pDef = intdiv($combat, DEF_PER_SKILL) + $gearDef;

$enemies = $pdo->query('SELECT e.*, i.name AS loot_name
                        FROM enemies e LEFT JOIN items i ON i.id = e.loot_item_id
                        ORDER BY e.tier, e.level_req')->fetchAll();

$pc = $pdo->prepare("SELECT COALESCE(pi.qty,0) FROM player_items pi JOIN items i ON i.id = pi.item_id
                     WHERE pi.player_id = ? AND i.code = 'patch_kit'");
$pc->execute([$pid]);
$patchCount = (int)($pc->fetchColumn() ?: 0);

$rl = $pdo->prepare('SELECT * FROM combat_log WHERE player_id = ? ORDER BY fought_at DESC LIMIT 6');
$rl->execute([$pid]);
$recent = $rl->fetchAll();
?>
<div class="panel">
  <h2>Combat Sim <span class="muted">&mdash; The Firewall</span></h2>
  <p class="muted">Jack into the Sim and fight for rep. Drones today, other ghosts tomorrow.</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>
    Integrity: <b><?= (int)$player['integrity'] ?> / <?= (int)$player['integrity_max'] ?></b> &middot;
    Attack: <b><?= (int)$pAtk ?></b> &middot; Defense: <b><?= (int)$pDef ?></b> &middot;
    Combat skill: <b><?= (int)$combat ?></b>
    <?php if ($gearAtk || $gearDef): ?>&middot; Gear: <b>+<?= (int)$gearAtk ?> ATK / +<?= (int)$gearDef ?> DEF</b><?php endif; ?>
    <span class="muted">(<a href="index.php?p=stash">loadout</a> &middot; train at the <a href="index.php?p=datacore&act=lab">Datacore</a>)</span>
  </p>
  <form method="post" style="margin:0">
    <input type="hidden" name="action" value="heal">
    <button type="submit">Use Field Patch Kit (+<?= HEAL_PER_KIT ?> Integrity)</button>
    <span class="muted">&nbsp;you have <?= (int)$patchCount ?> &mdash; craft more at the <a href="index.php?p=foundry">Foundry</a></span>
  </form>
</div>

<div class="panel">
  <h3>Drone Roster</h3>
  <table>
    <tr><th>Target</th><th>HP / Atk</th><th>Reward</th><th>Needs</th><th></th></tr>
    <?php foreach ($enemies as $e):
      $locked = (int)$player['level'] < (int)$e['level_req'];
      $flatlined = (int)$player['integrity'] <= 0; ?>
    <tr>
      <td><?= e($e['name']) ?><br><span class="muted" style="font-size:11px"><?= e($e['descr']) ?></span></td>
      <td><?= (int)$e['hp'] ?> hp / <?= (int)$e['attack'] ?> atk</td>
      <td><?= number_format($e['creds_min']) ?>&ndash;<?= number_format($e['creds_max']) ?> creds, <?= (int)$e['xp_reward'] ?> XP
        <?php if ($e['loot_name']): ?><br><span class="muted"><?= (int)$e['loot_chance'] ?>% <?= e($e['loot_name']) ?></span><?php endif; ?>
      </td>
      <td class="muted">Lv <?= (int)$e['level_req'] ?></td>
      <td>
        <?php if ($locked): ?>
          <span class="muted">Locked</span>
        <?php elseif ($flatlined): ?>
          <span class="muted">Flatlined</span>
        <?php else: ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="engage">
            <input type="hidden" name="enemy" value="<?= e($e['code']) ?>">
            <button type="submit">Engage</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($recent): ?>
<div class="panel">
  <h3>Recent Fights</h3>
  <table>
    <tr><th>Target</th><th>Result</th><th>Dealt / Taken</th><th>Creds</th><th>XP</th><th>When</th></tr>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td><?= e($r['enemy_name']) ?></td>
      <td><?= $r['outcome'] === 'win' ? 'WIN' : 'loss' ?></td>
      <td><?= (int)$r['dmg_dealt'] ?> / <?= (int)$r['dmg_taken'] ?></td>
      <td><?= number_format($r['creds_won']) ?></td>
      <td><?= (int)$r['xp_won'] ?></td>
      <td class="muted"><?= e($r['fought_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
