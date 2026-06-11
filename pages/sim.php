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

// grant_xp() lives in lib.php (shared, concurrency-safe)

function sim_fight($pHp, $pAtk, $pDef, $e, $tacticAtk = 1.0, $tacticDef = 1.0) {
  $eHp = (int)$e['hp']; $dealt = 0; $taken = 0;
  $pAtkF = max(1, (int)round($pAtk * $tacticAtk));
  $pDefF = max(0, (int)round($pDef * $tacticDef));
  $log = [];
  for ($i = 0; $i < 50; $i++) {
    $d = max(1, (int)round(max(1, $pAtkF - (int)$e['defense']) * random_int(85, 115) / 100));
    $eHp -= $d; $dealt += $d;
    $ed = max(1, (int)round(max(1, (int)$e['attack'] - $pDefF) * random_int(85, 115) / 100));
    $pHp -= $ed; $taken += $ed;
    $log[] = ['r'=>$i+1,'d'=>$d,'e'=>$ed,'php'=>max(0,$pHp),'ehp'=>max(0,$eHp)];
    if ($eHp <= 0) return ['win',  $dealt, $taken, max(0,$pHp), $log];
    if ($pHp <= 0) return ['loss', $dealt, $taken, 0,           $log];
  }
  return ['loss', $dealt, $taken, max(0, $pHp), $log];
}

// Combat skill
$cs = $pdo->prepare("SELECT ps.points FROM player_skills ps
                     JOIN skills s ON s.id = ps.skill_id
                     WHERE s.code = 'combat' AND ps.player_id = ?");
$cs->execute([$pid]);
$combat = (int)($cs->fetchColumn() ?: 0);

// Gear bonuses (Fabrication Lab crafts or stash-equipped items)
$gearAtk = 0; $gearDef = 0;
if ($wg = equipped_gear($pdo, $pid, 'weapon')) $gearAtk = $wg['atk'];
if ($ag = equipped_gear($pdo, $pid, 'armor'))  $gearDef = $ag['def'];

// Active lounge buffs (stored as "bonus|expiry_ts" in settings)
$buffAtk = 0; $buffDef = 0;
try {
  $bq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $bq->execute(["buff:atk:{$pid}"]); $bv = $bq->fetchColumn();
  if ($bv !== false && $bv !== '') { [$bon, $exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffAtk = (int)$bon; }
  $bq->execute(["buff:def:{$pid}"]); $bv = $bq->fetchColumn();
  if ($bv !== false && $bv !== '') { [$bon, $exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffDef = (int)$bon; }
} catch (Throwable $e) {}

$_simMerchantUntil = $player['merchant_until'] ?? null;
$_simIsMerchant = !empty($_simMerchantUntil) && $_simMerchantUntil >= date('Y-m-d');

/* ---------- action handling ---------- */
$fxEvent = null; // ceremony payload for the client
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($_simIsMerchant) throw new RuntimeException('Commerce Accord active — combat is locked until ' . $_simMerchantUntil . '.');
    if ($action === 'engage') {
      if ((int)$player['integrity'] <= 0) throw new RuntimeException('Flatlined — patch up before you fight again.');
      $eq = $pdo->prepare('SELECT * FROM enemies WHERE code = ?');
      $eq->execute([$_POST['enemy'] ?? '']);
      $e = $eq->fetch();
      if (!$e) throw new RuntimeException('No such target on the deck.');
      if ((int)$player['level'] < (int)$e['level_req'])
        throw new RuntimeException("You're not jacked up enough for that — needs level {$e['level_req']}.");

      $tactic = $_POST['tactic'] ?? 'balanced';
      $tacticMods = [
        'aggressive' => [1.25, 0.80],
        'balanced'   => [1.00, 1.00],
        'defensive'  => [0.80, 1.25],
      ];
      [$tAtkM, $tDefM] = $tacticMods[$tactic] ?? [1.0, 1.0];

      $pAtk = ATK_BASE + (int)$player['level'] * ATK_PER_LVL + intdiv($combat, ATK_PER_SKILL) + $gearAtk + $buffAtk;
      $pDef = intdiv($combat, DEF_PER_SKILL) + $gearDef + $buffDef;
      [$outcome, $dealt, $taken, $endHp, $roundLog] = sim_fight((int)$player['integrity'], $pAtk, $pDef, $e, $tAtkM, $tDefM);
      $_SESSION['sim_log'] = array_slice($roundLog, 0, 15); // store up to 15 rounds
      $_SESSION['sim_tactic'] = $tactic;
      $fightOutcome = $outcome;

      $credsWon = 0; $xpWon = 0; $lootMsg = '';
      $pdo->beginTransaction();
      // Apply damage RELATIVELY with a liveness guard — the old absolute write
      // ($endHp from a stale read) let parallel engages each fight from full HP
      // and stack rewards while only the last HP write stuck.
      $hpLost = max(0, (int)$player['integrity'] - (int)$endHp);
      if ($hpLost > 0) {
        $hu = $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id = ? AND integrity > 0');
        $hu->execute([$hpLost, $pid]);
        if ($hu->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Flatlined — patch up before you fight again.'); }
      }
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
      $fxEvent = ['t'=>'fight','win'=>$outcome === 'win','enemy'=>$e['name'],
                  'creds'=>$credsWon,'xp'=>$xpWon,'dealt'=>$dealt,'taken'=>$taken,'levelup'=>$lv];
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
    $fxEvent = null;
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

<?php if ($_simIsMerchant): ?>
<div class="flash flash-err">&#9878; Commerce Accord active — combat is locked until <?= e($_simMerchantUntil) ?>.</div>
<?php endif; ?>
<style>
#sim-canvas{display:block;width:100%;height:104px;border-radius:9px 9px 0 0}
#sim-head h2{text-shadow:0 0 14px rgba(255,45,149,.4)}
#sim-log-table tbody tr{transition:background .12s}
#sim-log-table tbody tr:hover{background:rgba(255,255,255,.03)}
</style>

<!-- Header -->
<div class="panel" id="sim-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="sim-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#128737; Combat Sim</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">The Firewall. Jack in and fight for rep — drones today, other ghosts tomorrow.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:10px;text-align:right;font-size:11px;color:var(--muted)">
      <div>ATK <b style="font-family:'Orbitron',sans-serif;color:var(--neon2)"><?= (int)$pAtk ?></b> &nbsp; DEF <b style="font-family:'Orbitron',sans-serif;color:var(--accent)"><?= (int)$pDef ?></b></div>
      <div style="margin-top:2px">HP <b style="font-family:'Orbitron',sans-serif;color:<?= $flatlined ? 'var(--neon2)' : '#3bcf63' ?>"><?= (int)$player['integrity'] ?></b><span style="opacity:.6">/<?= (int)$player['integrity_max'] ?></span></div>
    </div>
    <button id="sim-mute" onclick="toggleSimSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
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

<?php if (!empty($_SESSION['sim_log'])): $slog = $_SESSION['sim_log']; $stac = $_SESSION['sim_tactic'] ?? 'balanced'; ?>
<div class="panel">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <h3 style="margin:0">&#128202; Combat Log</h3>
    <span style="font-size:11px;color:var(--muted);padding:2px 8px;border:1px solid var(--line);border-radius:10px"><?= ucfirst($stac) ?> Stance</span>
  </div>
  <div style="overflow-x:auto">
  <table id="sim-log-table" style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr style="border-bottom:1px solid var(--line)">
      <th style="text-align:center;padding:5px 8px;color:var(--muted);font-size:10px;text-transform:uppercase">#</th>
      <th style="text-align:center;padding:5px 8px;color:var(--neon2);font-size:10px;text-transform:uppercase">You Deal</th>
      <th style="text-align:center;padding:5px 8px;color:#ff9090;font-size:10px;text-transform:uppercase">Enemy Deals</th>
      <th style="text-align:center;padding:5px 8px;color:var(--accent);font-size:10px;text-transform:uppercase">Your HP</th>
      <th style="text-align:center;padding:5px 8px;color:var(--muted);font-size:10px;text-transform:uppercase">Enemy HP</th>
    </tr></thead>
    <tbody>
    <?php foreach ($slog as $row): ?>
    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
      <td style="text-align:center;padding:5px 8px;color:var(--muted)"><?= (int)$row['r'] ?></td>
      <td style="text-align:center;padding:5px 8px;color:var(--neon2);font-weight:600">-<?= (int)$row['d'] ?></td>
      <td style="text-align:center;padding:5px 8px;color:#ff9090;font-weight:600">-<?= (int)$row['e'] ?></td>
      <td style="text-align:center;padding:5px 8px;color:<?= (int)$row['php'] < 20 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= (int)$row['php'] ?></td>
      <td style="text-align:center;padding:5px 8px;color:var(--muted)"><?= (int)$row['ehp'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (count($slog) >= 15): ?><p class="muted" style="text-align:center;font-size:11px;margin:8px 0 0">Showing first 15 rounds.</p><?php endif; ?>
</div>
<?php unset($_SESSION['sim_log']); endif; ?>

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
        <div style="display:flex;gap:4px;margin-bottom:6px">
          <?php foreach (['aggressive'=>['&#128293;','ATK+25%/DEF-20%'],'balanced'=>['&#9876;','Standard'],'defensive'=>['&#128737;','DEF+25%/ATK-20%']] as $tk=>[$tic,$tl]): ?>
          <label style="flex:1;text-align:center;cursor:pointer">
            <input type="radio" name="tactic" value="<?= $tk ?>" <?= $tk==='balanced'?'checked':'' ?> style="display:none" class="tac-radio">
            <span class="tac-lbl" data-val="<?= $tk ?>" style="display:block;padding:4px 2px;border-radius:4px;border:1px solid var(--line);font-size:10px;line-height:1.3;color:var(--muted);transition:all .15s;cursor:pointer"><?= $tic ?><br><?= $tl ?></span>
          </label>
          <?php endforeach; ?>
        </div>
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

<script>
(function(){
  document.querySelectorAll('.tac-radio').forEach(function(r){
    r.addEventListener('change',function(){
      var form=r.closest('form');
      if(!form)return;
      form.querySelectorAll('.tac-lbl').forEach(function(l){
        l.style.borderColor='var(--line)';l.style.color='var(--muted)';l.style.background='';
      });
      var lbl=form.querySelector('.tac-lbl[data-val="'+r.value+'"]');
      if(lbl){lbl.style.borderColor='var(--accent)';lbl.style.color='var(--accent)';lbl.style.background='rgba(25,240,199,.08)';}
    });
    if(r.checked) r.dispatchEvent(new Event('change'));
  });
})();
</script>

<script>window._simFx = <?= json_encode($fxEvent) ?>;</script>

<script>
/* Combat Sim FX kit — bound once; overlays on document.body survive AJAX swaps. */
(function(){
  if(window._simFxBound) return;
  window._simFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#simfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(5,3,8,.6);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#simfx.show{opacity:1}'
    +'.smfx-stage{position:relative;width:240px;height:110px}'
    +'.smfx-you,.smfx-foe{position:absolute;top:24px;font-size:36px;transition:transform .12s,filter .3s,opacity .3s}'
    +'.smfx-you{left:24px;filter:drop-shadow(0 0 10px rgba(25,240,199,.6))}'
    +'.smfx-foe{right:24px;filter:drop-shadow(0 0 10px rgba(255,45,149,.6))}'
    +'.smfx-you.lunge{transform:translateX(26px)}'
    +'.smfx-foe.lunge{transform:translateX(-26px)}'
    +'.smfx-flash{animation:smfxFlash .2s ease-out}'
    +'@keyframes smfxFlash{0%{filter:brightness(3)}100%{}}'
    +'.smfx-ko{filter:grayscale(1) brightness(.45)!important;opacity:.55;transform:rotate(12deg) translateY(6px)!important}'
    +'.smfx-label{position:absolute;left:50%;top:102%;transform:translateX(-50%);white-space:nowrap;text-align:center;'
    +'font-size:14px;font-weight:900;letter-spacing:.12em;color:var(--smfx-col);text-shadow:0 0 12px var(--smfx-col);'
    +'opacity:0;transition:opacity .25s}'
    +'.smfx-label.show{opacity:1}'
    +'.smfx-sub{display:block;font-size:10px;font-weight:600;color:var(--text);opacity:.75;margin-top:3px;letter-spacing:.03em}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('simMuted')==='1';
  function tone(freq,dur,type,vol,slide){
    if(muted) return;
    try{
      ac=ac||new (window.AudioContext||window.webkitAudioContext)();
      var o=ac.createOscillator(),g=ac.createGain();
      o.type=type||'sine'; o.frequency.value=freq;
      if(slide) o.frequency.exponentialRampToValueAtTime(slide,ac.currentTime+dur);
      g.gain.value=vol||.05;
      g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+dur);
      o.connect(g); g.connect(ac.destination);
      o.start(); o.stop(ac.currentTime+dur);
    }catch(e){}
  }
  window.toggleSimSound=function(){
    muted=!muted; localStorage.setItem('simMuted',muted?'1':'0');
    var b=document.getElementById('sim-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };
  window.simFX={
    tone:tone,
    hit:function(){ tone(120,.08,'square',.05); tone(900,.04,'sine',.03); },
    win:function(){ [523,659,784].forEach(function(f,i){ setTimeout(function(){tone(f,.13,'sine',.05);},i*100); }); },
    lose:function(){ tone(330,.28,'sine',.05,130); setTimeout(function(){tone(100,.35,'sine',.05);},240); },
    levelup:function(){ [659,784,1047,1319].forEach(function(f,i){ setTimeout(function(){tone(f,.14,'sine',.055);},i*100); }); }
  };

  window.simFightOverlay=function(ev){
    var old=document.getElementById('simfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='simfx';
    o.style.setProperty('--smfx-col',ev.win?'#3bcf63':'#ff2d95');
    o.innerHTML='<div class="smfx-stage">'
      +'<span class="smfx-you">&#129464;</span>'
      +'<span class="smfx-foe">&#129302;</span>'
      +'<div class="smfx-label"></div>'
      +'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    var you=o.querySelector('.smfx-you'), foe=o.querySelector('.smfx-foe'), label=o.querySelector('.smfx-label');
    // exchange of blows
    var step=0;
    var iv=setInterval(function(){
      step++;
      var attacker=step%2===1?you:foe, victim=step%2===1?foe:you;
      attacker.classList.add('lunge');
      window.simFX.hit();
      setTimeout(function(){ attacker.classList.remove('lunge');
        victim.classList.remove('smfx-flash'); void victim.offsetWidth; victim.classList.add('smfx-flash');
      },110);
      if(step>=4){
        clearInterval(iv);
        setTimeout(function(){
          (ev.win?foe:you).classList.add('smfx-ko');
          if(ev.win){
            window.simFX.win();
            label.innerHTML='TARGET NEUTRALIZED<span class="smfx-sub">'+ev.enemy+' — +'+Number(ev.creds).toLocaleString('en-US')+' cr · +'+ev.xp+' XP'+(ev.levelup?' · LEVEL UP!':'')+'</span>';
            if(ev.levelup) setTimeout(window.simFX.levelup,400);
          } else {
            window.simFX.lose();
            label.innerHTML='FLATLINED<span class="smfx-sub">'+ev.enemy+' — dealt '+ev.dealt+', took '+ev.taken+'</span>';
          }
          label.classList.add('show');
        },200);
      }
    },260);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},2600);
  };
})();
</script>

<script>
(function(){
'use strict';
var mb=document.getElementById('sim-mute');
if(mb) mb.innerHTML=localStorage.getItem('simMuted')==='1'?'&#128263;':'&#128266;';

/* ── Firewall header: grid wall, patrol drones, scan beam ── */
var sc=document.getElementById('sim-canvas');
if(sc){
  var c=sc.getContext('2d');
  var SW=560, SH=104;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  sc.width=SW*dpr; sc.height=SH*dpr;
  c.scale(dpr,dpr);
  var drones=[];
  for(var i=0;i<5;i++) drones.push({x:Math.random()*SW,y:18+Math.random()*60,v:(.25+Math.random()*.4)*(Math.random()<.5?-1:1),p:Math.random()*9});
  var glitch=0;

  function sLoop(t){
    if(!document.body.contains(sc)) return;
    requestAnimationFrame(sLoop);
    c.clearRect(0,0,SW,SH);
    var bg=c.createLinearGradient(0,0,0,SH);
    bg.addColorStop(0,'#0a070e'); bg.addColorStop(1,'#0f0a14');
    c.fillStyle=bg; c.fillRect(0,0,SW,SH);

    // firewall grid blocks
    for(var gy=0;gy<4;gy++){
      for(var gx=0;gx<14;gx++){
        var bx=gx*42+((gy%2)?21:0), by=10+gy*24;
        var on=((t/1100+gx*1.3+gy*2.1)%7)<5.4;
        c.fillStyle=on?'rgba(255,45,149,.10)':'rgba(255,45,149,.03)';
        c.fillRect(bx,by,36,18);
        c.strokeStyle='rgba(255,45,149,.14)';
        c.strokeRect(bx+.5,by+.5,36,18);
      }
    }
    // occasional breach glitch
    if(Math.random()<.012) glitch=4;
    if(glitch>0){
      glitch--;
      var gy2=10+Math.floor(Math.random()*4)*24;
      c.fillStyle='rgba(255,255,255,.12)';
      c.fillRect(Math.random()*SW,gy2,36,18);
    }

    // patrol drones
    for(var di=0;di<drones.length;di++){
      var D=drones[di];
      D.x+=D.v;
      if(D.x<-12) D.x=SW+12; if(D.x>SW+12) D.x=-12;
      var bob=Math.sin(t/350+D.p)*2;
      c.save(); c.translate(D.x,D.y+bob);
      if(D.v<0) c.scale(-1,1);
      c.fillStyle='#1c1626';
      c.beginPath(); c.moveTo(-8,0); c.lineTo(6,-5); c.lineTo(6,5); c.closePath(); c.fill();
      c.strokeStyle='rgba(255,45,149,.5)'; c.stroke();
      c.fillStyle='rgba(255,45,149,'+(0.5+0.4*Math.sin(t/200+D.p))+')';
      c.beginPath(); c.arc(2,0,1.6,0,Math.PI*2); c.fill();
      c.restore();
    }

    // sweeping scan beam
    var sx2=((t/22)%(SW+200))-100;
    var beam=c.createLinearGradient(sx2-50,0,sx2+50,0);
    beam.addColorStop(0,'rgba(25,240,199,0)'); beam.addColorStop(.5,'rgba(25,240,199,.05)'); beam.addColorStop(1,'rgba(25,240,199,0)');
    c.fillStyle=beam; c.fillRect(0,0,SW,SH);

    // floor line
    c.fillStyle='rgba(255,45,149,.08)'; c.fillRect(0,SH-6,SW,6);
  }
  requestAnimationFrame(sLoop);
}

/* ── fight ceremony (consume once) ── */
var ev=window._simFx||null; window._simFx=null;
if(ev&&ev.t==='fight'&&window.simFightOverlay) window.simFightOverlay(ev);
})();
</script>
