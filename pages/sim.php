<?php /* pages/sim.php — The Firewall: Combat Sim (PvE drones).
   Fights used to auto-resolve in one POST (pick a stance, read the log).
   Rebuilt as INTERACTIVE turn-based combat on combat_engine.php (pure logic,
   no DB/session — proven by a headless CLI harness): each round the enemy
   telegraphs its next move and the player answers with Strike / Guard /
   Feint / Burst / Stim / Flee. Fight HP runs at integrity × CB_HP_SCALE
   (integrity_max never grows in this game, so without headroom every fight
   was binary chip-proof-or-dead-in-two); real integrity damage on exit is
   scaled back down. Same session/AJAX architecture as mining/transit. */
require_once __DIR__ . '/../combat_engine.php';

$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
unset($_SESSION['sim_log'], $_SESSION['sim_tactic']); // retired auto-resolver leftovers

const HEAL_PER_KIT = 10;
const ATK_BASE     = 4;
const ATK_PER_LVL  = 2;
const DRIVE_COST   = 15; // Drive (cycles) burned per engage

// Combat skill gates which enemy tier you may engage (skill_defs: "Unlocks
// higher-tier Combat Sim opponents per level") and sharpens telegraph reads.
$TIER_COMBAT_REQ = [1 => 0, 2 => 50, 3 => 150, 4 => 300, 5 => 450, 6 => 650, 7 => 850];

$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

// Self-healing seed for tiers 5-7 — RETUNED for interactive combat. The old
// values (attack 30-105) were mathematically unwinnable at any gear: nothing
// in the game raises integrity_max (15), so attack past ~12 one-shot through
// everything. Calibrated by the CLI harness: on-tier fights are winnable with
// telegraph-reading (T7 apex ~78% for a skilled endgame player, ~1% for
// button-mashing), one-tier-up is a real challenge (~59% vs ~1%).
try {
  $newTiers = [
    // code, name, tier, level_req, hp, attack, defense, creds_min, creds_max, xp, descr
    ['drone_infiltrator', 'Ghost Protocol Infiltrator', 5, 15, 160, 24, 9,  400,  800, 180, 'Stealth-cloaked breach unit. Hits before you see it coming.'],
    ['drone_warden',      'Blacksite Warden',           5, 16, 190, 26, 10, 480,  950, 210, 'Guards a site that officially does not exist.'],
    ['drone_aegis',       'Rogue AEGIS Construct',      6, 22, 280, 32, 13, 900, 1800, 380, 'A defense AI that stopped taking orders from anyone.'],
    ['drone_hex',         'Hex-Compiled Wraith',        6, 23, 310, 34, 14, 1000,2000, 420, 'Code that shouldn\'t run, running anyway.'],
    ['drone_kingpin',     'Sprawl Kingpin\'s Enforcer',  7, 32, 420, 42, 20, 2200,4200, 750, 'The last thing between you and the throne room.'],
    ['drone_apex',        'Apex Threat: BLACKOUT',      7, 34, 500, 46, 24, 2600,5000, 820, 'Citywide kill order. You are the only one dumb enough to answer it.'],
  ];
  $ins = $pdo->prepare('INSERT IGNORE INTO enemies (code,name,tier,level_req,hp,attack,defense,creds_min,creds_max,xp_reward,descr)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
  foreach ($newTiers as $nt) $ins->execute($nt);
  // Idempotent retune migration for installs that seeded the old numbers.
  $fix = $pdo->prepare('UPDATE enemies SET hp=?, attack=?, defense=? WHERE code=? AND (hp<>? OR attack<>? OR defense<>?)');
  foreach ($newTiers as $nt) $fix->execute([$nt[4], $nt[5], $nt[6], $nt[0], $nt[4], $nt[5], $nt[6]]);
} catch (Throwable $e) {}

// Combat skill points
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
$_simIsMerchant = is_merchant($player);

// Territory perk: each district the player's syndicate holds adds +2% ATK/DEF
// (capped +10%), same edge that applies in the Arena.
$simTerrMult = syn_territory_combat_mult($pdo, $pid);
$pAtk = (int)round((ATK_BASE + (int)$player['level'] * ATK_PER_LVL + $gearAtk + $buffAtk) * $simTerrMult);
$pDef = (int)round(($gearDef + $buffDef) * $simTerrMult);

function sim_patch_kit_id(PDO $pdo): int {
  return (int)($pdo->query("SELECT id FROM items WHERE code = 'patch_kit'")->fetchColumn() ?: 0);
}
function sim_patch_count(PDO $pdo, int $pid): int {
  $pc = $pdo->prepare("SELECT COALESCE(pi.qty,0) FROM player_items pi JOIN items i ON i.id = pi.item_id
                       WHERE pi.player_id = ? AND i.code = 'patch_kit'");
  $pc->execute([$pid]);
  return (int)($pc->fetchColumn() ?: 0);
}

/* ── Fight AJAX ─────────────────────────────────────────────────────────── */
if (!empty($_POST['cb_ajax'])) {
  header('Content-Type: application/json');
  $act = $_POST['cb_action'] ?? '';
  $fight = $_SESSION['sim_fight'] ?? null;
  if ($fight && (($fight['v'] ?? 0) !== 1)) { $fight = null; unset($_SESSION['sim_fight']); }
  try {
    $pl = current_player(); $plid = (int)$pl['id'];
    if (is_merchant($pl)) throw new RuntimeException('Commerce Accord active — combat is locked until ' . ($pl['merchant_until'] ?? '') . '.');

    if ($act === 'engage') {
      if ($fight) { // resume instead of double-charging (second tab / refresh)
        echo json_encode(['ok'=>true,'state'=>cb_to_client($fight),'drive'=>(int)$pl['cycles']]); exit;
      }
      if ((int)$pl['integrity'] <= 0) throw new RuntimeException('Flatlined — patch up before you fight again.');
      $eq = $pdo->prepare('SELECT * FROM enemies WHERE code = ?');
      $eq->execute([$_POST['enemy'] ?? '']);
      $e = $eq->fetch();
      if (!$e) throw new RuntimeException('No such target on the deck.');
      if ((int)$pl['level'] < (int)$e['level_req'])
        throw new RuntimeException("You're not jacked up enough for that — needs level {$e['level_req']}.");
      $tierReq = $TIER_COMBAT_REQ[(int)$e['tier']] ?? 0;
      if ($combat < $tierReq)
        throw new RuntimeException("Combat skill too low for that tier — needs {$tierReq} Combat skill points (you have {$combat}).");
      $dc = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $dc->execute([DRIVE_COST, $plid, DRIVE_COST]);
      if ($dc->rowCount() !== 1) throw new RuntimeException('Not enough Drive to engage — costs ' . DRIVE_COST . ' Drive.');

      $fight = cb_start([
        'hp'  => max(5, (int)$pl['integrity']) * CB_HP_SCALE,
        'atk' => $pAtk, 'def' => $pDef,
        'combat_lv' => intdiv($combat, 100),
        'kits' => min(9, sim_patch_count($pdo, $plid)),
      ], $e);
      // Reward payload rides along in the session (engine passes unknown keys through).
      $fight['reward'] = [
        'name' => $e['name'], 'creds_min' => (int)$e['creds_min'], 'creds_max' => (int)$e['creds_max'],
        'xp' => (int)$e['xp_reward'], 'loot_item_id' => $e['loot_item_id'] ? (int)$e['loot_item_id'] : 0,
        'loot_chance' => (int)$e['loot_chance'],
      ];
      $_SESSION['sim_fight'] = $fight;
      $pl = current_player();
      echo json_encode(['ok'=>true,'state'=>cb_to_client($fight),'drive'=>(int)$pl['cycles']]); exit;
    }

    if (!$fight) throw new RuntimeException('No active fight.');

    if ($act === 'round') {
      $a = $_POST['act'] ?? '';
      $r = cb_round($fight, $a);
      if (!$r['ok']) { echo json_encode(['ok'=>false,'err'=>$r['err']]); exit; }

      // A stim round consumed a kit inside the engine — mirror it atomically
      // in the stash BEFORE persisting; if the deduction loses, the round
      // never happened.
      if ($a === 'stim') {
        $pkId = sim_patch_kit_id($pdo);
        $u = $pkId ? $pdo->prepare('UPDATE player_items SET qty = qty - 1 WHERE player_id = ? AND item_id = ? AND qty >= 1') : null;
        if ($u) $u->execute([$plid, $pkId]);
        if (!$u || $u->rowCount() !== 1) { echo json_encode(['ok'=>false,'err'=>'No Patch Kits in your stash.']); exit; }
        try { $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND item_id = ? AND qty = 0')->execute([$plid, $pkId]); } catch (Throwable $e2) {}
      }

      $st = $r['st'];
      if (!$st['over']) {
        $_SESSION['sim_fight'] = $st;
        echo json_encode(['ok'=>true,'events'=>$r['events'],'state'=>cb_to_client($st)]); exit;
      }

      // ── Settle. On any failure the session keeps the PRE-round fight so the
      // final blow can simply be repeated — rewards can never double-grant
      // because the fight leaves the session in the same request that commits.
      $rw = $fight['reward'] ?? ['name'=>$st['ename'],'creds_min'=>0,'creds_max'=>0,'xp'=>0,'loot_item_id'=>0,'loot_chance'=>0];
      $outcome  = $st['outcome']; // win | loss | draw | fled
      $realLoss = (int)ceil($st['taken'] / CB_HP_SCALE);
      $credsWon = 0; $xpWon = 0; $lootName = null;
      $pdo->beginTransaction();
      if ($realLoss > 0)
        $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id = ?')->execute([$realLoss, $plid]);
      if ($outcome === 'win') {
        $credsWon = random_int($rw['creds_min'], max($rw['creds_min'], $rw['creds_max']));
        if ($credsWon > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$credsWon, $plid]);
        $xpWon = $rw['xp'];
        if ($rw['loot_item_id'] && random_int(1, 100) <= $rw['loot_chance']) {
          $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,1)
                         ON DUPLICATE KEY UPDATE qty = qty + 1')->execute([$plid, $rw['loot_item_id']]);
          $ln = $pdo->prepare('SELECT name FROM items WHERE id = ?'); $ln->execute([$rw['loot_item_id']]);
          $lootName = (string)$ln->fetchColumn();
        }
      }
      $pdo->prepare('INSERT INTO combat_log (player_id, enemy_name, outcome, dmg_dealt, dmg_taken, creds_won, xp_won)
                     VALUES (?,?,?,?,?,?,?)')
          ->execute([$plid, $rw['name'], $outcome, (int)$st['dealt'], (int)$st['taken'], $credsWon, $xpWon]);
      $pdo->commit();
      $xpRes = $xpWon > 0 ? grant_battle_xp($plid, $xpWon) : ['kept'=>0,'donated'=>0,'levels'=>0,'guild_levels'=>0];
      if ($outcome === 'win') contract_record($pdo, $plid, 'pve_win');
      unset($_SESSION['sim_fight']);
      $pl = current_player();
      echo json_encode(['ok'=>true,'events'=>$r['events'],'state'=>cb_to_client($st),
        'settle'=>[
          'outcome'=>$outcome,'creds'=>$credsWon,'xp'=>$xpWon,'loot'=>$lootName,
          'levelup'=>(int)$xpRes['levels'],'donated'=>(int)$xpRes['donated'],
          'guild_levelup'=>(int)$xpRes['guild_levels'],
          'hp_lost'=>$realLoss,'hp'=>(int)$pl['integrity'],
          'dealt'=>(int)$st['dealt'],'taken'=>(int)$st['taken'],'enemy'=>$rw['name'],
        ]]); exit;
    }

    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

/* ── Non-fight actions (heal) ───────────────────────────────────────────── */
$fxEvent = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'heal') {
      $pk = sim_patch_kit_id($pdo);
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
$enemies = $pdo->query('SELECT e.*, i.name AS loot_name
                        FROM enemies e LEFT JOIN items i ON i.id = e.loot_item_id
                        ORDER BY e.tier, e.level_req')->fetchAll();

$patchCount = sim_patch_count($pdo, $pid);

$rl = $pdo->prepare('SELECT * FROM combat_log WHERE player_id = ? ORDER BY fought_at DESC LIMIT 8');
$rl->execute([$pid]);
$recent = $rl->fetchAll();

$byTier = [];
foreach ($enemies as $enemy) { $byTier[(int)$enemy['tier']][] = $enemy; }
ksort($byTier);

$tierMeta = [
  1 => ['name' => 'Tier I — Scrap Drones',         'color' => 'var(--muted)',  'bg' => 'rgba(93,102,128,.12)',   'border' => 'rgba(93,102,128,.3)'],
  2 => ['name' => 'Tier II — Security Units',       'color' => 'var(--accent)', 'bg' => 'rgba(25,240,199,.08)',   'border' => 'rgba(25,240,199,.25)'],
  3 => ['name' => 'Tier III — Corporate Enforcers', 'color' => '#e8a33d',       'bg' => 'rgba(232,163,61,.08)',   'border' => 'rgba(232,163,61,.25)'],
  4 => ['name' => 'Tier IV — Elite Threats',        'color' => 'var(--neon2)',  'bg' => 'rgba(255,45,149,.1)',    'border' => 'rgba(255,45,149,.3)'],
  5 => ['name' => 'Tier V — Blacksite Ops',         'color' => '#9d6bff',       'bg' => 'rgba(157,107,255,.08)',  'border' => 'rgba(157,107,255,.28)'],
  6 => ['name' => 'Tier VI — Rogue Constructs',     'color' => '#4de8b8',       'bg' => 'rgba(77,232,184,.08)',   'border' => 'rgba(77,232,184,.28)'],
  7 => ['name' => 'Tier VII — Apex Threats',        'color' => '#ffffff',       'bg' => 'rgba(255,255,255,.06)',  'border' => 'rgba(255,255,255,.25)'],
];

$ip = (int)$player['integrity_max'] > 0 ? min(100, round((int)$player['integrity'] / (int)$player['integrity_max'] * 100)) : 0;
$flatlined = (int)$player['integrity'] <= 0;

$activeFight = $_SESSION['sim_fight'] ?? null;
if ($activeFight && (($activeFight['v'] ?? 0) !== 1)) { unset($_SESSION['sim_fight']); $activeFight = null; }
$cbClientCfg = [
  'state' => $activeFight ? cb_to_client($activeFight) : null,
  'drive' => (int)$player['cycles'],
];
?>

<?php if ($_simIsMerchant): ?>
<div class="flash flash-err">&#9878; Commerce Accord active — combat is locked until <?= e($_simMerchantUntil) ?>.</div>
<?php endif; ?>
<style>
#sim-canvas{display:block;width:100%;height:104px;border-radius:9px 9px 0 0}
#sim-head h2{text-shadow:0 0 14px rgba(255,45,149,.4)}
.cb-bar{height:12px;border-radius:6px;background:#080812;overflow:hidden}
.cb-bar>div{height:100%;border-radius:6px;transition:width .35s ease}
.cb-act{flex:1;min-width:86px;padding:9px 6px;border-radius:7px;border:1px solid var(--line);background:var(--panel2);color:var(--text);cursor:pointer;font-size:12px;line-height:1.35;transition:transform .07s,border-color .15s,background .15s}
.cb-act:hover:not(:disabled){border-color:var(--accent);background:rgba(25,240,199,.06)}
.cb-act:active:not(:disabled){transform:scale(.96)}
.cb-act:disabled{opacity:.38;cursor:not-allowed}
.cb-act b{display:block;font-size:13px}
.cb-act span{color:var(--muted);font-size:10px}
#cb-tele{border-radius:8px;padding:10px 14px;text-align:center;font-family:'Orbitron',sans-serif;font-size:13px;letter-spacing:.08em;transition:background .2s,border-color .2s}
@keyframes cbShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-7px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(3px)}}
#cb-panel.shake{animation:cbShake .4s ease-out}
@keyframes cbFlashRed{0%{box-shadow:inset 0 0 60px rgba(255,45,149,.5)}100%{}}
#cb-panel.hitflash{animation:cbFlashRed .45s ease-out}
@keyframes cbEnemyHit{0%{filter:brightness(2.4) saturate(2)}100%{}}
#cb-foe-card.hit{animation:cbEnemyHit .35s ease-out}
@keyframes cbPulseGreen{0%{box-shadow:0 0 0 0 rgba(59,207,99,.5)}100%{box-shadow:0 0 22px 8px rgba(59,207,99,0)}}
#cb-you-card.heal{animation:cbPulseGreen .6s ease-out}
#cb-feed{max-height:150px;overflow-y:auto;font-size:12px;display:flex;flex-direction:column;gap:3px}
#cb-feed div{animation:cbIn .25s ease-out backwards}
@keyframes cbIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1}}
.cb-badge{display:inline-block;border-radius:4px;padding:2px 8px;font-size:10px;font-family:'Orbitron',sans-serif;letter-spacing:.06em}
#sim-log-table tbody tr{transition:background .12s}
</style>

<!-- Header -->
<div class="panel" id="sim-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="sim-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#128737; Combat Sim</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">The Firewall. Read the tells, pick your moment, walk out with the creds.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:10px;text-align:right;font-size:11px;color:var(--muted)">
      <div>ATK <b style="font-family:'Orbitron',sans-serif;color:var(--neon2)"><?= (int)$pAtk ?></b> &nbsp; DEF <b style="font-family:'Orbitron',sans-serif;color:var(--accent)"><?= (int)$pDef ?></b></div>
      <div style="margin-top:2px">HP <b id="sim-hp-chip" style="font-family:'Orbitron',sans-serif;color:<?= $flatlined ? 'var(--neon2)' : '#3bcf63' ?>"><?= (int)$player['integrity'] ?></b><span style="opacity:.6">/<?= (int)$player['integrity_max'] ?></span></div>
    </div>
    <button id="sim-mute" onclick="toggleSimSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash flash-ok"><?= $msg ?></div>
<?php endif; ?>

<!-- ══ ACTIVE FIGHT ══ -->
<div id="cb-panel" class="panel" <?= $activeFight ? '' : 'style="display:none"' ?>>
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <div>
      <div style="font-size:14px;font-weight:700" id="cb-foe-name">&mdash;</div>
      <div style="font-size:11px;color:var(--muted)"><span id="cb-foe-meta"></span></div>
    </div>
    <div style="font-size:11px;color:var(--muted)">Round <b id="cb-round" style="color:var(--text)">1</b>/<span id="cb-cap">25</span></div>
  </div>

  <div id="cb-foe-card" style="background:var(--panel2);border:1px solid rgba(255,45,149,.25);border-radius:8px;padding:10px 12px;margin-bottom:10px">
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:5px">
      <span>Enemy Integrity</span><span><b id="cb-ehp" style="color:var(--neon2)">0</b> / <span id="cb-emax">0</span></span>
    </div>
    <div class="cb-bar"><div id="cb-ehp-bar" style="width:100%;background:linear-gradient(90deg,var(--neon2),#ff7070)"></div></div>
    <div id="cb-badges" style="margin-top:7px;min-height:18px"></div>
  </div>

  <div id="cb-tele" style="background:rgba(255,255,255,.03);border:1px solid var(--line);margin-bottom:10px">&mdash;</div>

  <div id="cb-you-card" style="background:var(--panel2);border:1px solid rgba(25,240,199,.25);border-radius:8px;padding:10px 12px;margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:5px">
      <span>Construct Integrity</span><span><b id="cb-php" style="color:var(--accent)">0</b> / <span id="cb-pmax">0</span></span>
    </div>
    <div class="cb-bar"><div id="cb-php-bar" style="width:100%;background:linear-gradient(90deg,var(--accent),#3bcf63)"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin:8px 0 5px">
      <span>Stamina</span><span><b id="cb-stam" style="color:#e8a33d">100</b> / 100</span>
    </div>
    <div class="cb-bar" style="height:8px"><div id="cb-stam-bar" style="width:100%;background:linear-gradient(90deg,#e8a33d,#ffce6b)"></div></div>
  </div>

  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
    <button type="button" class="cb-act" data-act="strike" onclick="cbAct('strike')"><b>&#9876; Strike</b><span>15 stam &middot; interrupts charges</span></button>
    <button type="button" class="cb-act" data-act="guard"  onclick="cbAct('guard')"><b>&#128737; Guard</b><span>+25 stam &middot; staggers heavies</span></button>
    <button type="button" class="cb-act" data-act="feint"  onclick="cbAct('feint')"><b>&#127917; Feint</b><span>10 stam &middot; opens guards</span></button>
    <button type="button" class="cb-act" data-act="burst"  onclick="cbAct('burst')"><b>&#128165; Burst</b><span>35 stam &middot; huge on openings</span></button>
    <button type="button" class="cb-act" data-act="stim"   onclick="cbAct('stim')"><b>&#128137; Stim</b><span id="cb-stim-sub">uses a Patch Kit</span></button>
    <button type="button" class="cb-act" data-act="flee"   onclick="cbAct('flee')" style="border-color:rgba(255,45,149,.3);color:var(--neon2)"><b>&#127939; Flee</b><span>eat a parting shot</span></button>
  </div>

  <div id="cb-feed" style="background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:7px;padding:8px 10px;margin-bottom:8px"></div>
  <details style="font-size:11px;color:var(--muted)">
    <summary style="cursor:pointer">How to read the tells</summary>
    <div style="margin-top:6px;line-height:1.7">
      <b style="color:#ff9090">Heavy wind-up</b> &rarr; Guard it (staggers them &mdash; then Burst).&nbsp;
      <b style="color:#e8a33d">Quick jab</b> &rarr; trade with Strike, or Guard if hurting.&nbsp;
      <b style="color:var(--accent)">Raised guard</b> &rarr; Feint to open them, then Strike/Burst hits &times;1.5.&nbsp;
      <b style="color:#9d6bff">Core charge</b> &rarr; Strike to interrupt &mdash; or Guard and brace for the unleash.&nbsp;
      Stim on safe rounds (their guard/charge/stagger). Tells aren't always honest &mdash; higher tiers bluff; Combat skill sharpens your read (<span id="cb-conf">~88%</span>).
      Keys: <b>1-6</b>.
    </div>
  </details>
</div>

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
      <button type="submit" style="width:100%" <?= $patchCount < 1 ? 'disabled' : '' ?>>Use Patch Kit <span class="muted">(+<?= HEAL_PER_KIT ?> Health)</span></button>
    </form>
    <p class="muted" style="font-size:11px;text-align:center;margin:7px 0 0">
      <?= $patchCount ?> kit<?= $patchCount !== 1 ? 's' : '' ?> in stash &mdash;
      <a href="index.php?p=foundry">craft</a> or <a href="index.php?p=generalstore">buy</a>
      &middot; kits also power mid-fight <b style="color:#3bcf63">Stims</b>
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
        <span>Combat Skill</span><span style="color:var(--text)"><?= number_format($combat) ?> pts <span class="muted">(tiers + read clarity)</span></span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span>Drive</span><span id="sim-drive-line" style="color:<?= (int)$player['cycles'] < DRIVE_COST ? 'var(--neon2)' : 'var(--text)' ?>"><?= number_format($player['cycles']) ?> <span class="muted">(&minus;<?= DRIVE_COST ?>/engage)</span></span>
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
<div id="cb-roster" <?= $activeFight ? 'style="display:none"' : '' ?>>
<?php foreach ($byTier as $tier => $tierEnemies):
  $tm = $tierMeta[$tier] ?? ['name' => "Tier {$tier}", 'color' => 'var(--muted)', 'bg' => 'rgba(93,102,128,.1)', 'border' => 'rgba(93,102,128,.25)'];
?>
<div class="panel">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--line)">
    <span style="background:<?= $tm['bg'] ?>;border:1px solid <?= $tm['border'] ?>;color:<?= $tm['color'] ?>;border-radius:4px;padding:3px 10px;font-size:11px;font-family:'Orbitron',sans-serif;letter-spacing:.5px;font-weight:700">T<?= $tier ?></span>
    <span style="color:<?= $tm['color'] ?>;font-size:13px;font-weight:bold"><?= e($tm['name']) ?></span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:10px">
  <?php
  $tierReq = $TIER_COMBAT_REQ[$tier] ?? 0;
  foreach ($tierEnemies as $e):
    $lvlLocked = (int)$player['level'] < (int)$e['level_req'];
    $skillLocked = $combat < $tierReq;
    $locked   = $lvlLocked || $skillLocked;
    $canFight = !$locked && !$flatlined;
  ?>
  <div style="background:var(--panel2);border:1px solid <?= $canFight ? $tm['border'] : 'var(--line)' ?>;border-radius:7px;padding:12px;opacity:<?= $locked ? '.5' : '1' ?>;display:flex;flex-direction:column;gap:0">

    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
      <div>
        <div style="font-weight:bold;font-size:13px;color:<?= $canFight ? $tm['color'] : 'var(--muted)' ?>"><?= e($e['name']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= e($e['descr']) ?></div>
      </div>
      <?php if ($locked): ?>
        <span style="background:rgba(93,102,128,.15);border:1px solid rgba(93,102,128,.3);color:var(--muted);border-radius:4px;padding:2px 7px;font-size:10px;white-space:nowrap;flex:none;margin-left:8px"><?= $lvlLocked ? 'Lv '.(int)$e['level_req'].'+' : 'Combat '.$tierReq.'+' ?></span>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px">
      <span style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.2);color:var(--neon2);border-radius:4px;padding:2px 7px;font-size:11px">&#128143; <?= (int)$e['hp'] ?> HP</span>
      <span style="background:rgba(255,100,100,.08);border:1px solid rgba(255,100,100,.2);color:#ff9090;border-radius:4px;padding:2px 7px;font-size:11px">&#9876; <?= (int)$e['attack'] ?> ATK</span>
      <?php if ((int)$e['defense'] > 0): ?>
      <span style="background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.15);color:var(--accent);border-radius:4px;padding:2px 7px;font-size:11px">&#128737; <?= (int)$e['defense'] ?> DEF</span>
      <?php endif; ?>
      <span style="background:rgba(157,107,255,.07);border:1px solid rgba(157,107,255,.2);color:#9d6bff;border-radius:4px;padding:2px 7px;font-size:11px">&#127917; <?= ucfirst(cb_persona($e['code'])) ?></span>
    </div>

    <div style="font-size:11px;color:var(--muted);margin-bottom:10px;display:flex;flex-wrap:wrap;gap:8px">
      <span>&#9733; <?= (int)$e['xp_reward'] ?> XP</span>
      <span>&#9670; <?= number_format($e['creds_min']) ?>–<?= number_format($e['creds_max']) ?> cr</span>
      <?php if ($e['loot_name'] && (int)$e['loot_chance'] > 0): ?>
        <span style="color:var(--accent)">&#127381; <?= (int)$e['loot_chance'] ?>% <?= e($e['loot_name']) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($lvlLocked): ?>
      <div style="text-align:center;padding:7px;border:1px solid var(--line);border-radius:5px;color:var(--muted);font-size:12px;margin-top:auto">Requires Level <?= (int)$e['level_req'] ?></div>
    <?php elseif ($skillLocked): ?>
      <div style="text-align:center;padding:7px;border:1px solid var(--line);border-radius:5px;color:var(--muted);font-size:12px;margin-top:auto">Requires <?= $tierReq ?> Combat skill (<a href="index.php?p=datacore&act=lab">train</a>)</div>
    <?php elseif ($flatlined): ?>
      <div style="text-align:center;padding:7px;background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:5px;color:var(--neon2);font-size:12px;margin-top:auto">Flatlined — patch up first</div>
    <?php else: ?>
      <button type="button" onclick="cbEngage('<?= e($e['code']) ?>')" style="width:100%;margin-top:auto;background:rgba(59,207,99,.1);border-color:rgba(59,207,99,.4);color:#3bcf63" <?= (int)$player['cycles'] < DRIVE_COST ? 'disabled' : '' ?>>Engage <span style="opacity:.75">(&minus;<?= DRIVE_COST ?> Drive)</span></button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<noscript><div class="flash flash-err">Interactive fights need JavaScript.</div></noscript>

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
      $oc = $r['outcome'];
      $ocCol = $oc === 'win' ? ['rgba(25,240,199,.1)','rgba(25,240,199,.3)','var(--accent)']
             : ($oc === 'fled' || $oc === 'draw' ? ['rgba(232,163,61,.1)','rgba(232,163,61,.3)','#e8a33d']
             : ['rgba(255,45,149,.1)','rgba(255,45,149,.3)','var(--neon2)']);
      $ago = time() - strtotime($r['fought_at']);
      if ($ago < 60)       $ts = 'just now';
      elseif ($ago < 3600) $ts = intdiv($ago, 60) . 'm ago';
      elseif ($ago < 86400)$ts = intdiv($ago, 3600) . 'h ago';
      else                 $ts = date('M j', strtotime($r['fought_at']));
    ?>
    <tr style="border-bottom:1px solid rgba(36,31,58,.5)" onmouseover="this.style.background='var(--panel2)'" onmouseout="this.style.background=''">
      <td style="padding:8px 10px;font-weight:500"><?= e($r['enemy_name']) ?></td>
      <td style="padding:8px 10px;text-align:center">
        <span style="background:<?= $ocCol[0] ?>;border:1px solid <?= $ocCol[1] ?>;color:<?= $ocCol[2] ?>;border-radius:4px;padding:2px 8px;font-size:11px;font-family:'Orbitron',sans-serif;letter-spacing:.5px"><?= strtoupper(e($oc)) ?></span>
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
    block:function(){ tone(300,.07,'square',.035); },
    stim:function(){ tone(500,.1,'sine',.045); setTimeout(function(){tone(750,.12,'sine',.045);},90); },
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
    if(Math.random()<.012) glitch=4;
    if(glitch>0){
      glitch--;
      var gy2=10+Math.floor(Math.random()*4)*24;
      c.fillStyle='rgba(255,255,255,.12)';
      c.fillRect(Math.random()*SW,gy2,36,18);
    }
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
    var sx2=((t/22)%(SW+200))-100;
    var beam=c.createLinearGradient(sx2-50,0,sx2+50,0);
    beam.addColorStop(0,'rgba(25,240,199,0)'); beam.addColorStop(.5,'rgba(25,240,199,.05)'); beam.addColorStop(1,'rgba(25,240,199,0)');
    c.fillStyle=beam; c.fillRect(0,0,SW,SH);
    c.fillStyle='rgba(255,45,149,.08)'; c.fillRect(0,SH-6,SW,6);
  }
  requestAnimationFrame(sLoop);
}
})();
</script>

<script>
/* ══ Interactive fight client ══ */
(function(){
'use strict';
var CB = <?= json_encode($cbClientCfg) ?>;
var state=CB.state||null;
var busy=false, ending=false;

var TELE={
  heavy:  {txt:'&#9888; WINDING UP A HEAVY STRIKE', col:'#ff9090', bg:'rgba(255,100,100,.08)', bd:'rgba(255,100,100,.35)'},
  quick:  {txt:'&#9889; COILING FOR A QUICK JAB',   col:'#e8a33d', bg:'rgba(232,163,61,.08)',  bd:'rgba(232,163,61,.35)'},
  guard:  {txt:'&#128737; RAISING ITS GUARD',       col:'#19f0c7', bg:'rgba(25,240,199,.06)',  bd:'rgba(25,240,199,.3)'},
  charge: {txt:'&#9762; CHARGING ITS CORE WEAPON',  col:'#9d6bff', bg:'rgba(157,107,255,.1)',  bd:'rgba(157,107,255,.4)'},
  unleash:{txt:'&#9762;&#9762; UNLEASHING — BRACE!',col:'#ff2d95', bg:'rgba(255,45,149,.14)',  bd:'rgba(255,45,149,.55)'},
  stagger:{txt:'&#128171; STAGGERED — IT\'S OPEN!', col:'#3bcf63', bg:'rgba(59,207,99,.1)',    bd:'rgba(59,207,99,.45)'}
};
var PERSONA={brawler:'Brawler — swings first, thinks later',sentinel:'Sentinel — turtles behind its guard',stalker:'Stalker — fast jabs, hard to pin',juggernaut:'Juggernaut — loves charging its core'};

function el(id){ return document.getElementById(id); }
function feed(html,col){
  var f=el('cb-feed'); if(!f) return;
  var d=document.createElement('div');
  d.innerHTML=html; if(col) d.style.color=col;
  f.insertBefore(d,f.firstChild);
  while(f.children.length>16) f.removeChild(f.lastChild);
}

function render(){
  if(!state) return;
  el('cb-foe-name').textContent=state.ename;
  el('cb-foe-meta').textContent='Tier '+state.tier+' · '+(PERSONA[state.persona]||state.persona);
  el('cb-round').textContent=state.round;
  el('cb-cap').textContent=state.cap;
  el('cb-ehp').textContent=state.ehp;
  el('cb-emax').textContent=state.emax;
  el('cb-ehp-bar').style.width=Math.max(0,Math.min(100,state.ehp/state.emax*100))+'%';
  el('cb-php').textContent=state.php;
  el('cb-pmax').textContent=state.pstart;
  el('cb-php-bar').style.width=Math.max(0,Math.min(100,state.php/state.pstart*100))+'%';
  el('cb-stam').textContent=state.stam;
  el('cb-stam-bar').style.width=state.stam+'%';
  el('cb-conf').textContent='~'+state.telepct+'%';
  var t=TELE[state.tele]||TELE.quick;
  var tb=el('cb-tele');
  tb.innerHTML=t.txt+' <span style="font-size:10px;color:var(--muted);letter-spacing:0">(read ~'+state.telepct+'%)</span>';
  tb.style.color=t.col; tb.style.background=t.bg; tb.style.borderColor=t.bd;
  var badges='';
  if(state.charge>0) badges+='<span class="cb-badge" style="background:rgba(157,107,255,.12);border:1px solid rgba(157,107,255,.4);color:#9d6bff">CHARGED</span> ';
  if(state.estag>0)  badges+='<span class="cb-badge" style="background:rgba(59,207,99,.12);border:1px solid rgba(59,207,99,.4);color:#3bcf63">STAGGERED</span> ';
  if(state.popen>0)  badges+='<span class="cb-badge" style="background:rgba(25,240,199,.1);border:1px solid rgba(25,240,199,.4);color:var(--accent)">OPENED — next hit &times;1.5</span>';
  el('cb-badges').innerHTML=badges;
  // buttons
  document.querySelectorAll('.cb-act').forEach(function(b){
    var a=b.getAttribute('data-act');
    if(a==='flee'){ b.disabled=false; return; }
    if(a==='stim'){
      var can=state.kits>0&&state.stims_used<state.stim_max&&state.php<state.pstart;
      b.disabled=!can;
      el('cb-stim-sub').textContent=state.kits+' kit'+(state.kits===1?'':'s')+' · '+(state.stim_max-state.stims_used)+' use'+((state.stim_max-state.stims_used)===1?'':'s')+' left';
      return;
    }
    b.disabled=(state.costs[a]||0)>state.stam;
  });
}

function showRoster(){
  state=null; ending=false;
  el('cb-panel').style.display='none';
  var r=el('cb-roster'); if(r) r.style.display='';
}
function showFight(){
  el('cb-panel').style.display='';
  var r=el('cb-roster'); if(r) r.style.display='none';
}

function cbPost(data,cb){
  if(busy) return;
  busy=true;
  data.cb_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;feed('Network error','var(--neon2)');});
}

var EVTXT={
  interrupt:['&#9876; You smash the wind-up — charge INTERRUPTED','#19f0c7'],
  charge:['&#9762; It finished charging. BRACE for the unleash.','#9d6bff'],
  unleash:['&#9762; CORE UNLEASHED','#ff2d95'],
  stagger:['&#128171; Your guard STAGGERS it — it\'s wide open','#3bcf63'],
  open:['&#127917; Feint lands — its guard is OPEN (next hit &times;1.5)','#19f0c7'],
  openhit:['&#128165; You exploit the opening','#19f0c7'],
  counter:['It counter-jabs through your swing','#e8a33d'],
  draw:['Both constructs disengage — the sim times out. No payout.','#e8a33d'],
  fled:['You jack out and run.','#e8a33d']
};
function animate(events,done){
  var i=0;
  (function next(){
    if(i>=events.length){ if(done) done(); return; }
    var ev=events[i++]; var panel=el('cb-panel');
    if(ev.t==='pdmg'){
      var fc=el('cb-foe-card');
      fc.classList.remove('hit'); void fc.offsetWidth; fc.classList.add('hit');
      window.simFX&&window.simFX.hit();
      feed('You '+(ev.act==='burst'?'BURST for':ev.act==='feint'?'jab for':'strike for')+' <b style="color:#19f0c7">'+ev.v+'</b>');
    } else if(ev.t==='edmg'){
      panel.classList.remove('hitflash'); panel.classList.remove('shake'); void panel.offsetWidth;
      panel.classList.add(ev.blocked?'hitflash':'shake');
      if(ev.blocked){ window.simFX&&window.simFX.block(); feed('You block — <b style="color:#ff9090">'+ev.v+'</b> chips through','var(--muted)'); }
      else { window.simFX&&window.simFX.hit(); feed('It '+(ev.act==='unleash'?'UNLEASHES on you for':ev.act==='heavy'?'lands a HEAVY for':'hits for')+' <b style="color:#ff9090">'+ev.v+'</b>'+(ev.note?' ('+ev.note+')':'')); }
    } else if(ev.t==='stim'){
      var yc=el('cb-you-card');
      yc.classList.remove('heal'); void yc.offsetWidth; yc.classList.add('heal');
      window.simFX&&window.simFX.stim();
      feed('&#128137; Stim hits — <b style="color:#3bcf63">+'+ev.v+'</b> construct integrity');
    } else if(ev.t==='regen'){
      feed('+'+ev.v+' stamina','var(--muted)');
    } else if(EVTXT[ev.t]){
      feed(EVTXT[ev.t][0],EVTXT[ev.t][1]);
    }
    setTimeout(next, ev.t==='pdmg'||ev.t==='edmg'?380:220);
  })();
}

window.cbEngage=function(code){
  cbPost({cb_action:'engage',enemy:code},function(d){
    if(!d.ok){ alert(d.err||'Error'); return; }
    state=d.state;
    var f=el('cb-feed'); if(f) f.innerHTML='';
    if(d.drive!=null){ var dl=el('sim-drive-line'); if(dl) dl.firstChild&&(dl.childNodes[0].nodeValue=Number(d.drive).toLocaleString('en-US')+' '); }
    showFight();
    render();
    feed('Jacked in against <b>'+state.ename+'</b>. Watch its tells.','#19f0c7');
    window.simFX&&window.simFX.tone(180,.3,'sine',.06,70);
  });
};

window.cbAct=function(a){
  if(!state||busy||ending) return;
  cbPost({cb_action:'round',act:a},function(d){
    if(!d.ok){ feed(d.err||'Error','var(--neon2)'); return; }
    var prev=state;
    state=d.state;
    animate(d.events,function(){
      render();
      if(d.settle){
        ending=true;
        var s=d.settle;
        var hpEl=el('sim-hp-chip'); if(hpEl&&s.hp!=null){ hpEl.textContent=s.hp; if(s.hp<=0) hpEl.style.color='var(--neon2)'; }
        if(s.outcome==='win'){
          var extra=s.loot?' · salvaged '+s.loot:'';
          feed('<b style="color:#3bcf63">TARGET NEUTRALIZED</b> — +'+Number(s.creds).toLocaleString('en-US')+' cr · +'+s.xp+' XP'+(s.donated?' ('+s.donated+' XP to guild)':'')+extra+(s.hp_lost?' · &minus;'+s.hp_lost+' Health':''));
          window.simFightOverlay&&window.simFightOverlay({win:true,enemy:s.enemy,creds:s.creds,xp:s.xp,levelup:s.levelup,dealt:s.dealt,taken:s.taken});
        } else if(s.outcome==='loss'){
          feed('<b style="color:#ff2d95">FLATLINED</b> — &minus;'+s.hp_lost+' Health. Patch up before re-engaging.');
          window.simFightOverlay&&window.simFightOverlay({win:false,enemy:s.enemy,creds:0,xp:0,levelup:0,dealt:s.dealt,taken:s.taken});
        } else {
          feed(EVTXT[s.outcome==='draw'?'draw':'fled'][0]+(s.hp_lost?' (&minus;'+s.hp_lost+' Health)':''),'#e8a33d');
          window.simFX&&window.simFX.lose();
        }
        setTimeout(function(){
          // Roster Drive/HP states may have changed — a full swap re-render is
          // the honest refresh; fall back to just showing the roster.
          showRoster();
        }, s.outcome==='win'||s.outcome==='loss'?2700:1400);
      }
    });
  });
};

// keyboard 1-6 — bound once, guards against detached panel + form fields
if(!window._cbKeysBound){
  window._cbKeysBound=true;
  document.addEventListener('keydown',function(e){
    var p=document.getElementById('cb-panel');
    if(!p||!document.body.contains(p)||p.style.display==='none') return;
    if(/INPUT|TEXTAREA|SELECT/.test((e.target&&e.target.tagName)||'')) return;
    var map={'1':'strike','2':'guard','3':'feint','4':'burst','5':'stim','6':'flee'};
    if(map[e.key]){ e.preventDefault(); window.cbAct&&window.cbAct(map[e.key]); }
  });
}

if(state){ showFight(); render(); feed('Fight resumed. Watch its tells.','#19f0c7'); }
})();
</script>
