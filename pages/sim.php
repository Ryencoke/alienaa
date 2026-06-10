<?php /* pages/sim.php — The Firewall: Combat Sim (PvE drones) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$fightOutcome = ''; // 'win' | 'loss' | ''

const HEAL_PER_KIT = 10;
const ATK_BASE     = 4;
const ATK_PER_LVL  = 2;
const ATK_PER_SKILL= 15;
const DEF_PER_SKILL= 30;

$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

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
  return ['loss', $dealt, $taken, max(0, $pHp)];
}

// Combat skill
$cs = $pdo->prepare("SELECT ps.points FROM player_skills ps
                     JOIN skills s ON s.id = ps.skill_id
                     WHERE s.code = 'combat' AND ps.player_id = ?");
$cs->execute([$pid]);
$combat = (int)($cs->fetchColumn() ?: 0);

// Gear bonuses (from Fabrication Lab)
$gearAtk = 0; $gearDef = 0;
try {
  $gq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $gq->execute(["equipped_weapon:{$pid}"]); $wid = (int)$gq->fetchColumn();
  if ($wid > 0) { $gq2 = $pdo->prepare('SELECT atk_bonus FROM player_gear WHERE id=? AND player_id=?'); $gq2->execute([$wid,$pid]); $gearAtk = (int)($gq2->fetchColumn() ?: 0); }
  $gq->execute(["equipped_armor:{$pid}"]); $aid = (int)$gq->fetchColumn();
  if ($aid > 0) { $gq2 = $pdo->prepare('SELECT def_bonus FROM player_gear WHERE id=? AND player_id=?'); $gq2->execute([$aid,$pid]); $gearDef = (int)($gq2->fetchColumn() ?: 0); }
} catch (Throwable $e) { $gearAtk = 0; $gearDef = 0; }

// Active lounge buffs (stored as "bonus|expiry_ts" in settings)
$buffAtk = 0; $buffDef = 0;
try {
  $bq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $bq->execute(["buff:atk:{$pid}"]); $bv = $bq->fetchColumn();
  if ($bv !== false && $bv !== '') { [$bon, $exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffAtk = (int)$bon; }
  $bq->execute(["buff:def:{$pid}"]); $bv = $bq->fetchColumn();
  if ($bv !== false && $bv !== '') { [$bon, $exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffDef = (int)$bon; }
} catch (Throwable $e) {}

/* ---------- action handling ---------- */
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

      $pAtk = ATK_BASE + (int)$player['level'] * ATK_PER_LVL + intdiv($combat, ATK_PER_SKILL) + $gearAtk + $buffAtk;
      $pDef = intdiv($combat, DEF_PER_SKILL) + $gearDef + $buffDef;
      [$outcome, $dealt, $taken, $endHp] = sim_fight((int)$player['integrity'], $pAtk, $pDef, $e);
      $fightOutcome = $outcome;

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
          $lootMsg = ' Salvaged 1 <b>' . e($ln->fetchColumn()) . '</b>.';
        }
      }
      $pdo->prepare('INSERT INTO combat_log (player_id, enemy_name, outcome, dmg_dealt, dmg_taken, creds_won, xp_won)
                     VALUES (?,?,?,?,?,?,?)')
          ->execute([$pid, $e['name'], $outcome, $dealt, $taken, $credsWon, $xpWon]);
      $pdo->commit();

      $lv = $xpWon > 0 ? grant_xp($pid, $xpWon) : 0;
      if ($outcome === 'win') {
        $msg = 'Neutralized: <b>' . e($e['name']) . '</b> &mdash; +'
             . number_format($credsWon) . ' creds, +' . $xpWon . ' XP.'
             . $lootMsg . ($lv ? ' <b>LEVEL UP (+' . $lv . ')!</b>' : '');
      } else {
        $msg = 'Flatlined by <b>' . e($e['name']) . '</b> &mdash; dealt '
             . $dealt . ', took ' . $taken . ' damage. Patch up before re-engaging.';
      }
    }
    elseif ($action === 'heal') {
      $pk = $pdo->query("SELECT id FROM items WHERE code = 'patch_kit'")->fetchColumn();
      if (!$pk) throw new RuntimeException('Patch Kits are not in the catalog yet.');
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE player_items SET qty = qty - 1 WHERE player_id = ? AND item_id = ? AND qty >= 1');
      $u->execute([$pid, $pk]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('No Patch Kits in your stash — craft one at the Foundry.'); }
      $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND item_id = ? AND qty = 0')->execute([$pid, $pk]);
      $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?) WHERE id = ?')
          ->execute([HEAL_PER_KIT, $pid]);
      $pdo->commit();
      $msg = 'Field Patch Kit applied. Health restored.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
  $player = current_player();
}

/* ---------- display data ---------- */
$pAtk = ATK_BASE + (int)$player['level'] * ATK_PER_LVL + intdiv($combat, ATK_PER_SKILL) + $gearAtk + $buffAtk;
$pDef = intdiv($combat, DEF_PER_SKILL) + $gearDef + $buffDef;

$enemies = $pdo->query('SELECT e.*, i.name AS loot_name
                        FROM enemies e LEFT JOIN items i ON i.id = e.loot_item_id
                        ORDER BY e.tier, e.level_req')->fetchAll();

$pc = $pdo->prepare("SELECT COALESCE(pi.qty,0) FROM player_items pi JOIN items i ON i.id = pi.item_id
                     WHERE pi.player_id = ? AND i.code = 'patch_kit'");
$pc->execute([$pid]);
$patchCount = (int)($pc->fetchColumn() ?: 0);

$rl = $pdo->prepare('SELECT * FROM combat_log WHERE player_id = ? ORDER BY fought_at DESC LIMIT 8');
$rl->execute([$pid]);
$recent = $rl->fetchAll();

// Group enemies by tier
$byTier = [];
foreach ($enemies as $enemy) { $byTier[(int)$enemy['tier']][] = $enemy; }
ksort($byTier);

$tierMeta = [
  1 => ['name' => 'Tier I — Scrap Drones',         'color' => 'var(--muted)',  'bg' => 'rgba(93,102,128,.12)',   'border' => 'rgba(93,102,128,.3)'],
  2 => ['name' => 'Tier II — Security Units',       'color' => 'var(--accent)', 'bg' => 'rgba(25,240,199,.08)',   'border' => 'rgba(25,240,199,.25)'],
  3 => ['name' => 'Tier III — Corporate Enforcers', 'color' => '#e8a33d',       'bg' => 'rgba(232,163,61,.08)',   'border' => 'rgba(232,163,61,.25)'],
  4 => ['name' => 'Tier IV — Elite Threats',        'color' => 'var(--neon2)',  'bg' => 'rgba(255,45,149,.1)',    'border' => 'rgba(255,45,149,.3)'],
];

$ip = (int)$player['integrity_max'] > 0 ? min(100, round((int)$player['integrity'] / (int)$player['integrity_max'] * 100)) : 0;
$flatlined = (int)$player['integrity'] <= 0;
?>

<!-- Header -->
<div class="panel">
  <h2>&#128737; Combat Sim <span style="color:var(--muted);font-size:13px;font-weight:400;font-family:inherit">&mdash; The Firewall</span></h2>
  <p class="muted" style="text-align:center;margin-bottom:0">Jack into the Sim and fight for rep. Drones today, other ghosts tomorrow.</p>
</div>

<!-- Fight result -->
<?php if ($msg): ?>
<div class="panel" style="border-color:<?= $fightOutcome === 'win' ? 'rgba(25,240,199,.45)' : ($fightOutcome === 'loss' ? 'rgba(255,45,149,.45)' : 'var(--line)') ?>;background:<?= $fightOutcome === 'win' ? 'rgba(25,240,199,.05)' : ($fightOutcome === 'loss' ? 'rgba(255,45,149,.05)' : 'var(--panel)') ?>">
  <div style="display:flex;align-items:center;gap:14px">
    <span style="font-size:32px;flex:none"><?= $fightOutcome === 'win' ? '&#128994;' : ($fightOutcome === 'loss' ? '&#128308;' : '&#9432;') ?></span>
    <div>
      <div style="font-family:'Orbitron',sans-serif;font-size:12px;letter-spacing:.8px;margin-bottom:5px;color:<?= $fightOutcome === 'win' ? 'var(--accent)' : ($fightOutcome === 'loss' ? 'var(--neon2)' : 'var(--text)') ?>">
        <?= $fightOutcome === 'win' ? 'TARGET NEUTRALIZED' : ($fightOutcome === 'loss' ? 'FLATLINED' : 'SYSTEM') ?>
      </div>
      <div style="font-size:13px"><?= $msg ?></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Stats: integrity + combat -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:14px">

  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:12px">&#10084;&#65039; Health</h3>
    <div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:5px">
        <span>Hull Status</span>
        <span style="color:<?= $ip < 30 ? 'var(--neon2)' : 'var(--accent)' ?>;font-weight:bold"><?= (int)$player['integrity'] ?> / <?= (int)$player['integrity_max'] ?></span>
      </div>
      <div style="background:#080812;border-radius:5px;height:10px;overflow:hidden">
        <div style="width:<?= $ip ?>%;height:100%;background:<?= $ip < 30 ? 'linear-gradient(90deg,var(--neon2),#ff7070)' : 'linear-gradient(90deg,var(--accent),var(--neon2))' ?>;border-radius:5px;transition:width .4s ease"></div>
      </div>
      <div style="font-size:10px;color:var(--muted);text-align:right;margin-top:3px"><?= $ip ?>% health</div>
    </div>
    <?php if ($flatlined): ?>
      <div style="text-align:center;padding:7px;background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3);border-radius:5px;color:var(--neon2);font-size:12px;margin-bottom:10px">&#9888; FLATLINED — patch up to re-engage</div>
    <?php endif; ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="heal">
      <button type="submit" style="width:100%" <?= $patchCount < 1 ? 'disabled' : '' ?>>&#129657; Use Patch Kit <span class="muted">(+<?= HEAL_PER_KIT ?> Health)</span></button>
    </form>
    <p class="muted" style="font-size:11px;text-align:center;margin:7px 0 0">
      <?= $patchCount ?> kit<?= $patchCount !== 1 ? 's' : '' ?> in stash &mdash;
      <a href="index.php?p=foundry">craft</a> or <a href="index.php?p=generalstore">buy</a>
    </p>
  </div>

  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:12px">&#9876; Combat Stats</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
      <div style="background:var(--panel2);border:1px solid rgba(255,45,149,.2);border-radius:6px;padding:12px;text-align:center">
        <div style="font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;color:var(--neon2)"><?= (int)$pAtk ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Attack</div>
      </div>
      <div style="background:var(--panel2);border:1px solid rgba(25,240,199,.2);border-radius:6px;padding:12px;text-align:center">
        <div style="font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;color:var(--accent)"><?= (int)$pDef ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Defense</div>
      </div>
    </div>
    <div style="font-size:12px;color:var(--muted);display:flex;flex-direction:column;gap:5px">
      <div style="display:flex;justify-content:space-between">
        <span>Combat Skill</span><span style="color:var(--text)"><?= number_format($combat) ?> pts</span>
      </div>
      <?php if ($gearAtk || $gearDef): ?>
      <div style="display:flex;justify-content:space-between">
        <span>Gear Bonus</span>
        <span style="color:var(--text)">
          <?php if ($gearAtk): ?><span style="color:var(--neon2)">+<?= (int)$gearAtk ?> ATK</span><?php endif; ?>
          <?php if ($gearAtk && $gearDef): ?> / <?php endif; ?>
          <?php if ($gearDef): ?><span style="color:var(--accent)">+<?= (int)$gearDef ?> DEF</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if ($buffAtk || $buffDef): ?>
      <div style="display:flex;justify-content:space-between">
        <span style="color:var(--neon2)">&#9889; Buff Active</span>
        <span>
          <?php if ($buffAtk): ?><span style="color:var(--neon2)">+<?= (int)$buffAtk ?> ATK</span><?php endif; ?>
          <?php if ($buffAtk && $buffDef): ?> / <?php endif; ?>
          <?php if ($buffDef): ?><span style="color:var(--accent)">+<?= (int)$buffDef ?> DEF</span><?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
    </div>
    <p class="muted" style="font-size:11px;text-align:center;margin:10px 0 0">
      <a href="index.php?p=stash">&#9876; loadout</a> &middot;
      <a href="index.php?p=datacore&act=lab">&#127891; train</a>
      <?php if (!$buffAtk && !$buffDef): ?>&middot; <a href="index.php?p=lounge">&#9889; buffs</a><?php endif; ?>
    </p>
  </div>

</div>

<!-- Enemy roster by tier -->
<?php foreach ($byTier as $tier => $tierEnemies):
  $tm = $tierMeta[$tier] ?? ['name' => "Tier {$tier}", 'color' => 'var(--muted)', 'bg' => 'rgba(93,102,128,.1)', 'border' => 'rgba(93,102,128,.25)'];
?>
<div class="panel">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--line)">
    <span style="background:<?= $tm['bg'] ?>;border:1px solid <?= $tm['border'] ?>;color:<?= $tm['color'] ?>;border-radius:4px;padding:3px 10px;font-size:11px;font-family:'Orbitron',sans-serif;letter-spacing:.5px;font-weight:700">T<?= $tier ?></span>
    <span style="color:<?= $tm['color'] ?>;font-size:13px;font-weight:bold"><?= e($tm['name']) ?></span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:10px">
  <?php foreach ($tierEnemies as $e):
    $locked   = (int)$player['level'] < (int)$e['level_req'];
    $canFight = !$locked && !$flatlined;
  ?>
  <div style="background:var(--panel2);border:1px solid <?= $canFight ? $tm['border'] : 'var(--line)' ?>;border-radius:7px;padding:12px;opacity:<?= $locked ? '.5' : '1' ?>;display:flex;flex-direction:column;gap:0">

    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
      <div>
        <div style="font-weight:bold;font-size:13px;color:<?= $canFight ? $tm['color'] : 'var(--muted)' ?>"><?= e($e['name']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= e($e['descr']) ?></div>
      </div>
      <?php if ($locked): ?>
        <span style="background:rgba(93,102,128,.15);border:1px solid rgba(93,102,128,.3);color:var(--muted);border-radius:4px;padding:2px 7px;font-size:10px;white-space:nowrap;flex:none;margin-left:8px">Lv <?= (int)$e['level_req'] ?>+</span>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px">
      <span style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.2);color:var(--neon2);border-radius:4px;padding:2px 7px;font-size:11px">&#128143; <?= (int)$e['hp'] ?> HP</span>
      <span style="background:rgba(255,100,100,.08);border:1px solid rgba(255,100,100,.2);color:#ff9090;border-radius:4px;padding:2px 7px;font-size:11px">&#9876; <?= (int)$e['attack'] ?> ATK</span>
      <?php if ((int)$e['defense'] > 0): ?>
      <span style="background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.15);color:var(--accent);border-radius:4px;padding:2px 7px;font-size:11px">&#128737; <?= (int)$e['defense'] ?> DEF</span>
      <?php endif; ?>
    </div>

    <div style="font-size:11px;color:var(--muted);margin-bottom:10px;display:flex;flex-wrap:wrap;gap:8px">
      <span>&#9733; <?= (int)$e['xp_reward'] ?> XP</span>
      <span>&#9670; <?= number_format($e['creds_min']) ?>–<?= number_format($e['creds_max']) ?> cr</span>
      <?php if ($e['loot_name'] && (int)$e['loot_chance'] > 0): ?>
        <span style="color:var(--accent)">&#127381; <?= (int)$e['loot_chance'] ?>% <?= e($e['loot_name']) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($locked): ?>
      <div style="text-align:center;padding:7px;border:1px solid var(--line);border-radius:5px;color:var(--muted);font-size:12px;margin-top:auto">Requires Level <?= (int)$e['level_req'] ?></div>
    <?php elseif ($flatlined): ?>
      <div style="text-align:center;padding:7px;background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:5px;color:var(--neon2);font-size:12px;margin-top:auto">Flatlined — patch up first</div>
    <?php else: ?>
      <form method="post" style="margin:0;margin-top:auto">
        <input type="hidden" name="action" value="engage">
        <input type="hidden" name="enemy" value="<?= e($e['code']) ?>">
        <button type="submit" style="width:100%;background:<?= $tm['bg'] ?>;border-color:<?= $tm['border'] ?>;color:<?= $tm['color'] ?>">&#9889; Engage</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<!-- Recent Fights -->
<?php if ($recent): ?>
<div class="panel">
  <h3>&#128202; Recent Fights</h3>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="border-bottom:1px solid var(--line)">
        <th style="text-align:left;padding:7px 10px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Target</th>
        <th style="text-align:center;padding:7px 10px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Result</th>
        <th style="text-align:center;padding:7px 10px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Dmg Out / In</th>
        <th style="text-align:right;padding:7px 10px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">Creds</th>
        <th style="text-align:right;padding:7px 10px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">XP</th>
        <th style="text-align:right;padding:7px 10px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px">When</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($recent as $r):
      $won = $r['outcome'] === 'win';
      $ago = time() - strtotime($r['fought_at']);
      if ($ago < 60)       $ts = 'just now';
      elseif ($ago < 3600) $ts = intdiv($ago, 60) . 'm ago';
      elseif ($ago < 86400)$ts = intdiv($ago, 3600) . 'h ago';
      else                 $ts = date('M j', strtotime($r['fought_at']));
    ?>
    <tr style="border-bottom:1px solid rgba(36,31,58,.5)" onmouseover="this.style.background='var(--panel2)'" onmouseout="this.style.background=''">
      <td style="padding:8px 10px;font-weight:500"><?= e($r['enemy_name']) ?></td>
      <td style="padding:8px 10px;text-align:center">
        <span style="background:<?= $won ? 'rgba(25,240,199,.1)' : 'rgba(255,45,149,.1)' ?>;border:1px solid <?= $won ? 'rgba(25,240,199,.3)' : 'rgba(255,45,149,.3)' ?>;color:<?= $won ? 'var(--accent)' : 'var(--neon2)' ?>;border-radius:4px;padding:2px 8px;font-size:11px;font-family:'Orbitron',sans-serif;letter-spacing:.5px"><?= $won ? 'WIN' : 'LOSS' ?></span>
      </td>
      <td style="padding:8px 10px;text-align:center;font-size:12px">
        <span style="color:var(--accent)">+<?= (int)$r['dmg_dealt'] ?></span>
        <span style="color:var(--muted)"> / </span>
        <span style="color:var(--neon2)">-<?= (int)$r['dmg_taken'] ?></span>
      </td>
      <td style="padding:8px 10px;text-align:right;color:<?= $r['creds_won'] > 0 ? 'var(--accent)' : 'var(--muted)' ?>"><?= $r['creds_won'] > 0 ? '+'.number_format($r['creds_won']) : '—' ?></td>
      <td style="padding:8px 10px;text-align:right;color:<?= $r['xp_won'] > 0 ? '#e8a33d' : 'var(--muted)' ?>"><?= $r['xp_won'] > 0 ? '+'.(int)$r['xp_won'] : '—' ?></td>
      <td style="padding:8px 10px;text-align:right;color:var(--muted);font-size:12px"><?= e($ts) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
