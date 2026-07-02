<?php /* pages/transit.php — The Loading Docks: The Switchyard + tunnel mining.
   Cargo runs were a 3-checkpoint pick-a-button flow (and before that a single
   click). Rebuilt as The Switchyard: a top-down, fully-visible container yard
   where the player pilots a cargo loader from the depot to a district
   terminal, scooping freight crates while patrol drones sweep fixed ping-pong
   beats — one drone step per player action, two while overloaded. Pure game
   rules live in transit_engine.php (no DB/session there) so the whole rule
   set is exercised by a headless CLI harness; this page wires the engine to
   the session, Drive charges, payouts, and the shared freight-demand pool
   (settings key 'transit_demand' — a server-wide, player-depleted market:
   every delivery softens the route it lands at, lifts the other two, and
   everything drifts back toward 1.00 over real hours). Same server-
   authoritative session/AJAX architecture as mining.php. */
require_once __DIR__ . '/../transit_engine.php';

$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$fxEvent = null; // ceremony payload for the client (tunnel mining)
unset($_SESSION['transit_run']); // retired checkpoint-run state, pre-Switchyard

$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

$skillPts = $skillName = [];
$sp = $pdo->prepare('SELECT s.code, s.name, ps.points FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?');
$sp->execute([$pid]);
foreach ($sp as $row) { $skillPts[$row['code']] = (int)$row['points']; $skillName[$row['code']] = $row['name']; }
$skillLv = [];
foreach ($skillPts as $c => $p) $skillLv[$c] = intdiv($p, 100);

// Read the shared demand pool with lazy drift applied (read-only — the pool is
// only ever WRITTEN under a FOR UPDATE lock inside the delivery transaction).
function ty_demand_now(PDO $pdo): array {
  $d = [];
  try {
    $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $q->execute(['transit_demand']);
    $d = json_decode((string)$q->fetchColumn(), true) ?: [];
  } catch (Throwable $e) {}
  return ty_demand_drift($d, time());
}

// ── Switchyard AJAX ──────────────────────────────────────────────────────────
if (!empty($_POST['ty_ajax'])) {
  header('Content-Type: application/json');
  $act = $_POST['ty_action'] ?? '';
  $run = $_SESSION['transit_yard'] ?? null;
  if ($run && (($run['v'] ?? 0) !== 1)) { $run = null; unset($_SESSION['transit_yard']); } // stale shape from an older deploy
  try {
    $pl = current_player(); $plid = (int)$pl['id'];

    if ($act === 'start') {
      if ($run) { // resume instead of double-charging (e.g. two tabs)
        echo json_encode(['ok'=>true,'state'=>ty_to_client($run),
          'demand'=>round((float)(ty_demand_now($pdo)[$run['route']] ?? 1.0), 2),
          'drive'=>(int)$pl['cycles']]); exit;
      }
      $route = $_POST['route'] ?? '';
      if (!isset(TY_ROUTES[$route])) throw new RuntimeException('No such terminal on the manifest.');
      $dc = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $dc->execute([TY_START_DRIVE, $plid, TY_START_DRIVE]);
      if ($dc->rowCount() !== 1) throw new RuntimeException('Need ' . TY_START_DRIVE . ' Drive to clock on at the yard.');
      $new = ty_gen($skillLv['scav'] ?? 0, $skillLv['drone'] ?? 0, $skillLv['netrun'] ?? 0);
      if (!$new) { // vanishingly rare: refund and bail cleanly
        $pdo->prepare('UPDATE players SET cycles = LEAST(cycles_max, cycles + ?) WHERE id = ?')->execute([TY_START_DRIVE, $plid]);
        throw new RuntimeException('Yard dispatch glitched — Drive refunded, try again.');
      }
      $new['route'] = $route;
      $new['v'] = 1;
      $_SESSION['transit_yard'] = $new;
      $pl = current_player();
      echo json_encode(['ok'=>true,'state'=>ty_to_client($new),
        'demand'=>round((float)(ty_demand_now($pdo)[$route] ?? 1.0), 2),
        'drive'=>(int)$pl['cycles']]); exit;
    }

    if (!$run) throw new RuntimeException('No active run.');

    if ($act === 'abandon') {
      unset($_SESSION['transit_yard']);
      echo json_encode(['ok'=>true,'left'=>true]); exit;
    }

    if (in_array($act, ['move', 'dash', 'hold', 'jam'], true)) {
      $r = ty_step($run, $act, $_POST['dir'] ?? '');
      if (!$r['ok']) { echo json_encode(['ok'=>false,'err'=>$r['err']]); exit; }

      // Drive-priced actions: charge atomically BEFORE persisting the new
      // state — if the charge loses, the move simply never happened.
      if ($r['cost'] > 0) {
        $dc = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
        $dc->execute([$r['cost'], $plid, $r['cost']]);
        if ($dc->rowCount() !== 1) { echo json_encode(['ok'=>false,'err'=>"Need {$r['cost']} Drive for that."]); exit; }
      }

      if ($r['bust']) {
        $lostN = count($r['run']['carried']);
        $lostV = 0; foreach ($r['run']['carried'] as $c) $lostV += $c['v'];
        $pdo->prepare('UPDATE players SET integrity = GREATEST(1, integrity - ?) WHERE id = ?')->execute([$r['bust']['dmg'], $plid]);
        unset($_SESSION['transit_yard']);
        $pl = current_player();
        echo json_encode(['ok'=>true,'bust'=>['dmg'=>$r['bust']['dmg'],'reason'=>$r['bust']['reason'],
          'lost_n'=>$lostN,'lost_v'=>$lostV],'hp'=>(int)$pl['integrity'],'drive'=>(int)$pl['cycles']]); exit;
      }

      if ($r['delivered']) {
        $runF = $r['run'];
        $sum = 0; $tiers = 0; $t3 = 0; $n = count($runF['carried']);
        foreach ($runF['carried'] as $c) { $sum += (int)$c['v']; $tiers += (int)$c['t']; if ((int)$c['t'] === 3) $t3++; }
        // player_ore may not exist yet (mining.php creates it lazily) — ensure
        // it OUTSIDE the transaction (CREATE TABLE implicitly commits in MySQL).
        try { $pdo->exec('CREATE TABLE IF NOT EXISTS player_ore (
          id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, ore_type VARCHAR(32) NOT NULL,
          quantity INT NOT NULL DEFAULT 0, UNIQUE KEY uq_po (player_id, ore_type), KEY idx_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch (Throwable $e) {}

        $pdo->beginTransaction();
        // Lock the shared demand pool row (synth/vats blob-lock pattern) so
        // concurrent deliveries serialize: multiplier read, payout, and the
        // demand hit are one atomic unit.
        $pdo->prepare('INSERT IGNORE INTO settings (k,v) VALUES (?,?)')->execute(['transit_demand', '']);
        $dq = $pdo->prepare('SELECT v FROM settings WHERE k=? FOR UPDATE');
        $dq->execute(['transit_demand']);
        $dem = ty_demand_drift(json_decode((string)$dq->fetchColumn(), true) ?: [], time());
        $mult = (float)($dem[$runF['route']] ?? 1.0);
        $pay = (int)round($sum * $mult);
        $dem = ty_demand_apply($dem, $runF['route'], $n);
        $pdo->prepare('UPDATE settings SET v=? WHERE k=?')->execute([json_encode($dem), 'transit_demand']);
        if ($pay > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$pay, $plid]);
        // Volatile shipments sometimes hide forge ore (feeds the Blacksmith /
        // Fabrication economy, same player_ore pool as The Sump).
        $oreNames = ['copper' => 'Copper Wire', 'iron' => 'Iron Alloy'];
        $ore = [];
        for ($i = 0; $i < $t3; $i++) {
          if (mt_rand(1, 100) <= 40) { $ot = mt_rand(1, 100) <= 60 ? 'copper' : 'iron'; $ore[$ot] = ($ore[$ot] ?? 0) + 1; }
        }
        foreach ($ore as $ot => $q2) {
          $pdo->prepare('INSERT INTO player_ore (player_id, ore_type, quantity) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE quantity = quantity + ?')->execute([$plid, $ot, $q2, $q2]);
        }
        $pdo->commit();
        $xp = 3 * $n + 3 * max(0, $tiers - $n);
        if ($xp > 0) { try { grant_xp($plid, $xp); } catch (Throwable $e) {} }
        if ($n > 0) contract_record($pdo, $plid, 'cargo_delivered', $n);
        unset($_SESSION['transit_yard']);
        $pl = current_player();
        $oreOut = [];
        foreach ($ore as $ot => $q2) $oreOut[] = ['name' => $oreNames[$ot] ?? $ot, 'qty' => $q2];
        // demand_all: post-delivery drifted values so the lobby bars repaint live
        $demOut = [];
        foreach (TY_ROUTES as $rk => $_) $demOut[$rk] = round((float)($dem[$rk] ?? 1.0), 2);
        echo json_encode(['ok'=>true,'delivered'=>['pay'=>$pay,'mult'=>round($mult, 2),'base'=>$sum,
          'crates'=>$n,'xp'=>$xp,'ore'=>$oreOut,'route'=>$runF['route']],
          'demand_all'=>$demOut,'creds'=>(int)$pl['creds_pocket'],'drive'=>(int)$pl['cycles']]); exit;
      }

      $_SESSION['transit_yard'] = $r['run'];
      $pl = current_player();
      echo json_encode(['ok'=>true,'state'=>ty_to_client($r['run']),
        'picked'=>$r['picked'],'jammed'=>$r['jammed'],'drive'=>(int)$pl['cycles']]); exit;
    }

    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

// ── Tunnel mining (unchanged mechanic) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'mine') {
      $g = $pdo->prepare("SELECT g.*, i.name AS item_name FROM gather_nodes g JOIN items i ON i.id = g.item_id WHERE g.code = ? AND g.venue = 'transit'");
      $g->execute([$_POST['node'] ?? '']);
      $node = $g->fetch();
      if (!$node) throw new RuntimeException('No such dig site.');
      if (($skillPts[$node['skill_code']] ?? 0) < $node['skill_req'])
        throw new RuntimeException("Locked — needs {$skillName[$node['skill_code']]} {$node['skill_req']}.");
      // Drive cost per dig (scales with site tier) — keeps tunnel mining in line
      // with the Sump and Foundry instead of being free infinite farming.
      $driveCost = 5 + (int)$node['skill_req'];
      // Drive deduction and the item grant must commit-or-fail together — previously
      // these were two separate operations, so a crash between them could charge
      // Drive with no item granted.
      $pdo->beginTransaction();
      $dc = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $dc->execute([$driveCost, $pid, $driveCost]);
      if ($dc->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("Not enough Drive — a dig here costs {$driveCost} Drive."); }
      $yield = random_int((int)$node['yield_min'], (int)$node['yield_max']);
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')->execute([$pid, $node['item_id'], $yield]);
      $pdo->commit();
      $msg = "Extracted <b style=\"color:var(--accent)\">{$yield}&times; {$node['item_name']}</b> from the tunnels. (&minus;{$driveCost} Drive)";
      $msgType = 'ok';
      $fxEvent = ['t'=>'mine','qty'=>$yield,'item'=>$node['item_name'],'drive'=>$driveCost];
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
    $msgType = 'err';
    $fxEvent = null;
  }
  $player = current_player();
}

// gather_nodes ships in schema_foundry.sql — guard so the page still renders
// gracefully on an install that hasn't applied it (like foundry.php does)
$mines = [];
try { $mines = $pdo->query("SELECT g.*, i.name AS item_name FROM gather_nodes g JOIN items i ON i.id = g.item_id WHERE g.venue = 'transit' ORDER BY g.skill_req")->fetchAll(); } catch (Throwable $e) {}
$drone = (int)($skillPts['drone'] ?? 0);
$msgType = $msgType ?? 'ok';

$yardRun = $_SESSION['transit_yard'] ?? null;
if ($yardRun && (($yardRun['v'] ?? 0) !== 1)) { unset($_SESSION['transit_yard']); $yardRun = null; }
$demandNow = ty_demand_now($pdo);
$tyClientCfg = [
  'state'  => $yardRun ? ty_to_client($yardRun) : null,
  'routes' => TY_ROUTES,
  'demand' => array_intersect_key($demandNow, TY_ROUTES),
  'drive'  => (int)$player['cycles'],
  'startCost' => TY_START_DRIVE, 'dashCost' => TY_DASH_DRIVE, 'jamCost' => TY_JAM_DRIVE,
];
?>
<style>
#tr-canvas{display:block;width:100%;height:118px;border-radius:9px 9px 0 0}
#tr-head h2{text-shadow:0 0 14px rgba(232,163,61,.4)}
.tr-chip{display:inline-flex;flex-direction:column;align-items:center;padding:5px 13px;background:rgba(6,6,14,.78);border:1px solid var(--line);border-radius:8px;backdrop-filter:blur(3px)}
.tr-chip b{font-family:'Orbitron',sans-serif;font-size:14px}
.tr-chip span{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:1px}
.tr-dig{transition:background .12s}
.tr-dig:hover{background:rgba(255,255,255,.02)}
.tr-go{width:100%;font-size:12px;padding:7px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent);transition:transform .08s}
.tr-go:active{transform:scale(.97)}
.ty-route{position:relative;overflow:hidden;padding:14px 16px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:8px;transition:background .15s}
.ty-route:hover{background:rgba(255,255,255,.02)}
.ty-route::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--ty-col),transparent)}
.ty-demand-track{height:6px;border-radius:3px;background:rgba(255,255,255,.07);overflow:hidden}
.ty-demand-track>div{height:100%;border-radius:3px;transition:width .5s ease}
#ty-canvas{display:block;background:#04040a;border-radius:10px;touch-action:none;border:1px solid rgba(232,163,61,.22);box-shadow:0 0 30px rgba(232,163,61,.05),inset 0 0 60px rgba(0,0,0,.6);max-width:100%;height:auto}
.ty-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:var(--text);border-radius:6px;padding:8px 14px;cursor:pointer;font-size:13px;line-height:1;user-select:none;-webkit-user-select:none;transition:background .1s,transform .06s}
.ty-btn:active{background:rgba(232,163,61,.15);border-color:#e8a33d;transform:scale(.94)}
.ty-btn.on{background:rgba(232,163,61,.18);border-color:#e8a33d;color:#e8a33d}
.ty-btn:disabled{opacity:.4;cursor:not-allowed}
.ty-hud{display:inline-flex;flex-direction:column;align-items:center;padding:4px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:7px;min-width:64px}
.ty-hud b{font-family:'Orbitron',sans-serif;font-size:14px}
.ty-hud span{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em}
.ty-hud.warn{border-color:rgba(255,45,149,.5)}
.ty-hud.warn b{color:var(--neon2)}
#ty-msg{min-height:20px;font-size:12px;text-align:center;transition:opacity .3s}
</style>

<div class="panel" id="tr-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="tr-canvas"></canvas>
    <div style="position:absolute;left:16px;top:10px;pointer-events:none">
      <h2 style="margin:0">&#128643; The Loading Docks</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Work the switchyard for credits or mine the service tunnels. Both bite back.</p>
    </div>
    <div style="position:absolute;right:14px;top:12px;display:flex;gap:8px">
      <div class="tr-chip"><b id="tr-hp-chip" style="color:<?= (int)$player['integrity'] < 15 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= (int)$player['integrity'] ?></b><span>Health</span></div>
      <div class="tr-chip"><b id="tr-drive-chip" style="color:#e8a33d"><?= (int)$player['cycles'] ?></b><span>Drive</span></div>
      <div class="tr-chip"><b style="color:#4d6be8"><?= $drone ?></b><span>Drone</span></div>
    </div>
    <button id="tr-mute" onclick="toggleTrSound()" title="Toggle sound" style="position:absolute;bottom:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgType==='err' ? 'flash-err' : 'flash-ok' ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- ══ THE SWITCHYARD — lobby ══ -->
<div id="ty-lobby" <?= $yardRun ? 'style="display:none"' : '' ?>>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line)">
    <div style="font-size:13px;font-weight:700">&#128230; The Switchyard</div>
    <div style="font-size:11px;color:var(--muted);margin-top:1px">
      Pilot a loader through the container yard: scoop crates, dodge the patrol drones, deliver to a district terminal.
      Drones step once per move you make &mdash; <b style="color:var(--neon2)">twice</b> once you're overloaded. Get caught and the cargo's gone.
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0">
    <?php foreach (TY_ROUTES as $rk => $rt):
      $dm = (float)($demandNow[$rk] ?? 1.0);
      $dmPct = (int)round($dm * 100);
      $dmCol = $dm >= 1.1 ? '#3bcf63' : ($dm >= 0.9 ? '#e8a33d' : 'var(--neon2)');
    ?>
    <div class="ty-route" style="--ty-col:<?= $rt['col'] ?>">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:22px;text-shadow:0 0 10px <?= $rt['col'] ?>66"><?= $rt['icon'] ?></span>
        <div>
          <div style="font-weight:700;font-size:13px"><?= $rt['name'] ?></div>
          <div style="font-size:11px;color:var(--muted)"><?= $rt['sub'] ?></div>
        </div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:6px 8px;font-size:11px">
        <div style="display:flex;justify-content:space-between;color:var(--muted);margin-bottom:4px">
          <span>Freight demand</span><b class="ty-dm-pct" data-route="<?= $rk ?>" style="color:<?= $dmCol ?>">&times;<?= number_format($dm, 2) ?></b>
        </div>
        <div class="ty-demand-track"><div class="ty-dm-bar" data-route="<?= $rk ?>" style="width:<?= max(4, min(100, (int)round(($dm - 0.5) / 1.1 * 100))) ?>%;background:<?= $dmCol ?>"></div></div>
        <div style="color:var(--muted);margin-top:4px;font-size:10px">Crate values pay &times;demand. Every delivery citywide softens it; it recovers over hours.</div>
      </div>
      <button type="button" class="tr-go" onclick="tyStart('<?= $rk ?>')">Clock On &mdash; <?= TY_START_DRIVE ?> Drive</button>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="padding:9px 14px;border-top:1px solid var(--line);font-size:11px;color:var(--muted);display:flex;gap:16px;flex-wrap:wrap">
    <span>&#128230; 7 crates per yard &mdash; heavier hauls pay more, and slow you down</span>
    <span>&#9889; Dash: 2 tiles, <?= TY_DASH_DRIVE ?> Drive</span>
    <span>&#128225; Jammer: freeze drones, <?= TY_JAM_DRIVE ?> Drive, once per run (Netrunning extends it)</span>
    <span>&#9935; Volatile shipments can hide forge ore</span>
  </div>
</div>
</div>

<!-- ══ THE SWITCHYARD — active run ══ -->
<div id="ty-active" <?= $yardRun ? '' : 'style="display:none"' ?>>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <div style="font-size:13px;font-weight:700" id="ty-run-title">&#128230; The Switchyard</div>
      <div style="font-size:11px;color:var(--muted)" id="ty-run-sub">Reach the terminal. Watch the beats.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <div class="ty-hud"><b id="ty-hud-cargo" style="color:#3bcf63">0</b><span>Cargo cr</span></div>
      <div class="ty-hud" id="ty-hud-weight-box"><b id="ty-hud-weight" style="color:var(--text)">0/4</b><span>Weight</span></div>
      <div class="ty-hud"><b id="ty-hud-left" style="color:#e8a33d">7</b><span>Crates left</span></div>
      <div class="ty-hud"><b id="ty-hud-demand" style="color:var(--accent)">&times;1.00</b><span>Demand</span></div>
    </div>
  </div>
  <div style="padding:12px 14px;text-align:center">
    <canvas id="ty-canvas" width="520" height="440"></canvas>
    <div id="ty-msg" style="margin-top:6px"></div>
    <div style="display:flex;justify-content:center;align-items:center;gap:16px;margin-top:8px;flex-wrap:wrap">
      <div style="display:grid;grid-template-columns:repeat(3,44px);grid-template-rows:repeat(2,38px);gap:5px">
        <span></span><button type="button" class="ty-btn" onclick="tyGo('up')">&#9650;</button><span></span>
        <button type="button" class="ty-btn" onclick="tyGo('left')">&#9664;</button>
        <button type="button" class="ty-btn" onclick="tyGo('down')">&#9660;</button>
        <button type="button" class="ty-btn" onclick="tyGo('right')">&#9654;</button>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px">
        <button type="button" class="ty-btn" id="ty-hold-btn" onclick="tyAct('hold')" title="Stand still — patrols still tick (Space)">Hold</button>
        <button type="button" class="ty-btn" id="ty-dash-btn" onclick="tyDashToggle()" title="Next direction dashes 2 tiles (Shift+dir)">Dash &middot; <?= TY_DASH_DRIVE ?>&#9889;</button>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px">
        <button type="button" class="ty-btn" id="ty-jam-btn" onclick="tyAct('jam')" title="Freeze every drone for a few turns (J)">Jam &middot; <?= TY_JAM_DRIVE ?>&#9889;</button>
        <button type="button" class="ty-btn" onclick="tyAbandon()" style="color:var(--neon2)">Abandon</button>
      </div>
    </div>
    <div style="font-size:10px;color:var(--muted);margin-top:8px">WASD / arrows move &middot; Shift+dir dashes &middot; Space holds &middot; J jams &middot; drones step when you do</div>
  </div>
</div>
</div>
<noscript><div class="flash flash-err">The Switchyard needs JavaScript. Tunnel Mining below works without it.</div></noscript>

<!-- Tunnel Mining -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line)">
    <div style="font-size:13px;font-weight:700">&#9968; Tunnel Mining</div>
    <div style="font-size:11px;color:var(--muted);margin-top:1px">Pilot a rig into the service tunnels. Gated by Drone Piloting. Ore sells at the <a href="index.php?p=bazaar" style="color:var(--accent)">Bazaar</a>.</div>
  </div>
  <?php if (empty($mines)): ?>
  <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No dig sites registered.</div>
  <?php else: ?>
  <?php foreach ($mines as $m):
    $have = $skillPts[$m['skill_code']] ?? 0;
    $unlocked = $have >= (int)$m['skill_req'];
  ?>
  <div class="tr-dig" style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:12px;flex-wrap:wrap;<?= !$unlocked ? 'opacity:.5' : '' ?>">
    <div style="font-size:22px;flex:none">&#9954;</div>
    <div style="flex:1;min-width:140px">
      <div style="font-weight:700;font-size:13px"><?= e($m['name']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= e($m['descr']) ?></div>
      <div style="margin-top:4px;display:flex;gap:10px;font-size:11px;flex-wrap:wrap">
        <span>Yields: <b style="color:var(--accent)"><?= (int)$m['yield_min'] ?>&ndash;<?= (int)$m['yield_max'] ?> <?= e($m['item_name']) ?></b></span>
        <span style="color:var(--muted)">Cost: <b style="color:#e8a33d"><?= 5 + (int)$m['skill_req'] ?> Drive</b></span>
        <span style="color:var(--muted)">Req: <b style="color:<?= $unlocked ? 'var(--text)' : 'var(--neon2)' ?>"><?= e($skillName[$m['skill_code']] ?? $m['skill_code']) ?> <?= (int)$m['skill_req'] ?></b> (you: <?= (int)$have ?>)</span>
      </div>
    </div>
    <?php if ($unlocked): ?>
    <form method="post" style="margin:0" data-trfx="mine" data-tr-item="<?= e($m['item_name']) ?>">
      <input type="hidden" name="action" value="mine">
      <input type="hidden" name="node" value="<?= e($m['code']) ?>">
      <button type="submit" style="font-size:12px;padding:6px 16px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)">Mine</button>
    </form>
    <?php else: ?>
    <div style="font-size:11px;color:var(--muted);font-style:italic;padding:5px 12px;border:1px solid var(--line);border-radius:5px">Locked</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>window._trFx = <?= json_encode($fxEvent) ?>;</script>

<script>
/* Transit FX kit — bound once; overlays on document.body survive AJAX swaps. */
(function(){
  if(window._trFxBound) return;
  window._trFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#trfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(4,4,10,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#trfx.show{opacity:1}'
    +'.trfx-stage{position:relative;width:260px;height:110px}'
    +'.trfx-track{position:absolute;left:10px;right:10px;top:54px;height:3px;background:rgba(255,255,255,.15);border-radius:2px}'
    +'.trfx-track::before,.trfx-track::after{content:"";position:absolute;top:-5px;width:3px;height:13px;border-radius:2px;background:rgba(255,255,255,.3)}'
    +'.trfx-track::before{left:0}.trfx-track::after{right:0}'
    +'.trfx-cargo{position:absolute;top:38px;left:6px;font-size:24px;filter:drop-shadow(0 0 8px var(--tr-col))}'
    +'.trfx-label{position:absolute;left:50%;top:100%;transform:translateX(-50%);white-space:nowrap;text-align:center;'
    +'font-size:13px;font-weight:900;letter-spacing:.1em;color:var(--tr-col);text-shadow:0 0 12px var(--tr-col);'
    +'opacity:0;transition:opacity .25s}'
    +'.trfx-label.show{opacity:1}'
    +'.trfx-sub{display:block;font-size:10px;font-weight:600;color:var(--text);opacity:.75;margin-top:3px;letter-spacing:.03em}'
    +'.trfx-boom{position:absolute;width:10px;height:10px;border:3px solid var(--neon2);border-radius:50%;opacity:0}'
    +'@keyframes trfxBoom{0%{opacity:.9;width:10px;height:10px}100%{opacity:0;width:120px;height:120px}}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('transitMuted')==='1';
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
  window.toggleTrSound=function(){
    muted=!muted; localStorage.setItem('transitMuted',muted?'1':'0');
    var b=document.getElementById('tr-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };
  window.trFX={
    tone:tone,
    rumble:function(){ tone(70,.5,'square',.045,55); },
    chime:function(){ tone(523,.09,'sine',.05); setTimeout(function(){tone(784,.14,'sine',.05);},80); },
    alarm:function(){ tone(880,.13,'square',.05); setTimeout(function(){tone(620,.16,'square',.05);},150); },
    drill:function(){ tone(140,.3,'sawtooth',.04,90); },
    pick:function(){ tone(660,.05,'sine',.04); setTimeout(function(){tone(880,.07,'sine',.04);},45); },
    jam:function(){ tone(320,.25,'sawtooth',.04,90); },
    clatter:function(){ tone(900,.04,'square',.03); setTimeout(function(){tone(700,.04,'square',.03);},60); setTimeout(function(){tone(1100,.05,'square',.03);},120); }
  };

  window.trRunOverlay=function(ok,amount,route,col){
    var old=document.getElementById('trfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='trfx';
    o.style.setProperty('--tr-col',ok?'#3bcf63':'#ff2d95');
    o.innerHTML='<div class="trfx-stage">'
      +'<div class="trfx-track"></div>'
      +'<div class="trfx-cargo">&#128666;</div>'
      +'<div class="trfx-label"></div>'
      +'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    window.trFX.rumble();
    var cargo=o.querySelector('.trfx-cargo');
    var label=o.querySelector('.trfx-label');
    var dist=ok?206:130; // a bust stops the loader mid-route
    cargo.animate([{transform:'translateX(0)'},{transform:'translateX('+dist+'px)'}],
      {duration:ok?900:620,easing:ok?'ease-in-out':'ease-in',fill:'forwards'});
    setTimeout(function(){
      if(ok){
        window.trFX.chime();
        label.innerHTML='DELIVERY COMPLETE<span class="trfx-sub">'+route+' — +'+amount.toLocaleString('en-US')+' credits</span>';
      } else {
        window.trFX.alarm();
        var boom=document.createElement('div');
        boom.className='trfx-boom';
        boom.style.left=(6+dist-4)+'px'; boom.style.top='40px';
        boom.style.animation='trfxBoom .55s ease-out forwards';
        o.querySelector('.trfx-stage').appendChild(boom);
        cargo.style.filter='grayscale(1) brightness(.6)';
        label.innerHTML='BUSTED<span class="trfx-sub">'+route+'</span>';
      }
      label.classList.add('show');
    },ok?920:640);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},2400);
  };

  window.trMineOverlay=function(qty,item,drive){
    var old=document.getElementById('trfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='trfx';
    o.style.setProperty('--tr-col','#19f0c7');
    o.innerHTML='<div class="trfx-stage" style="height:90px">'
      +'<div class="trfx-cargo" style="left:50%;top:8px;transform:translateX(-50%);font-size:30px">&#9935;</div>'
      +'<div class="trfx-label" style="top:80%"></div>'
      +'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    window.trFX.drill();
    var pick=o.querySelector('.trfx-cargo');
    pick.animate([{transform:'translateX(-50%) rotate(-22deg)'},{transform:'translateX(-50%) rotate(14deg)'},{transform:'translateX(-50%) rotate(-22deg)'}],
      {duration:300,iterations:3});
    var stage=o.querySelector('.trfx-stage');
    setTimeout(function(){
      window.trFX.clatter();
      for(var i=0;i<10;i++){
        var s=document.createElement('div');
        s.style.cssText='position:absolute;left:50%;top:42px;width:5px;height:5px;border-radius:1px;background:'+(Math.random()<.3?'#dde2f0':'#8a8fa8');
        stage.appendChild(s);
        var a=Math.random()*Math.PI-Math.PI, sp=30+Math.random()*40;
        s.animate([{transform:'translate(0,0)',opacity:1},{transform:'translate('+(Math.cos(a)*sp)+'px,'+(Math.abs(Math.sin(a))*sp)+'px)',opacity:0}],
          {duration:480+Math.random()*200,easing:'cubic-bezier(.2,.6,.5,1)'});
        setTimeout(function(el){return function(){el.remove();};}(s),750);
      }
      var label=o.querySelector('.trfx-label');
      label.innerHTML='EXTRACTED<span class="trfx-sub">'+qty+'× '+item+' · −'+drive+' Drive</span>';
      label.classList.add('show');
      window.trFX.chime();
    },860);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},2100);
  };
})();
</script>

<script>
(function(){
'use strict';
var mb=document.getElementById('tr-mute');
if(mb) mb.innerHTML=localStorage.getItem('transitMuted')==='1'?'&#128263;':'&#128266;';

/* ── Rail yard header ── */
var tc=document.getElementById('tr-canvas');
if(tc){
  var c=tc.getContext('2d');
  var TW=560, TH=118;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  tc.width=TW*dpr; tc.height=TH*dpr;
  c.scale(dpr,dpr);
  // train state: x position; departs periodically
  var trainX=-260, trainV=0, nextTrain=1200;
  var signal=0; // 0 red, 1 amber, 2 green
  var fog=[{x:90,v:.05,r:80},{x:380,v:-.04,r:110}];

  function tLoop(t){
    if(!document.body.contains(tc)) return;
    requestAnimationFrame(tLoop);
    c.clearRect(0,0,TW,TH);
    var bg=c.createLinearGradient(0,0,0,TH);
    bg.addColorStop(0,'#0a0a12'); bg.addColorStop(1,'#0e0c14');
    c.fillStyle=bg; c.fillRect(0,0,TW,TH);

    // gantry + cables
    c.strokeStyle='rgba(255,255,255,.08)';
    c.beginPath(); c.moveTo(0,22); c.lineTo(TW,22); c.stroke();
    for(var g2=60;g2<TW;g2+=120){ c.beginPath(); c.moveTo(g2,22); c.lineTo(g2,58); c.stroke(); }

    // train scheduling
    if(trainV===0 && t>nextTrain){ trainV=3.2+Math.random()*1.6; trainX=-260; signal=2; window.trFX&&Math.random()<.7&&window.trFX.rumble(); }
    if(trainV>0){
      trainX+=trainV;
      if(trainX>TW+60){ trainV=0; nextTrain=t+6000+Math.random()*7000; signal=0; }
    }

    // maglev train
    if(trainV>0){
      var ty=58;
      // headlight cone
      var hl=c.createLinearGradient(trainX+236,ty+12,trainX+340,ty+12);
      hl.addColorStop(0,'rgba(232,212,77,.25)'); hl.addColorStop(1,'rgba(232,212,77,0)');
      c.fillStyle=hl;
      c.beginPath(); c.moveTo(trainX+236,ty+8); c.lineTo(trainX+340,ty-6); c.lineTo(trainX+340,ty+30); c.lineTo(trainX+236,ty+18); c.closePath(); c.fill();
      // body
      c.fillStyle='#1a1a2c';
      c.beginPath();
      c.moveTo(trainX,ty); c.lineTo(trainX+220,ty);
      c.quadraticCurveTo(trainX+244,ty+2,trainX+238,ty+24); c.lineTo(trainX,ty+24); c.closePath(); c.fill();
      c.strokeStyle='rgba(25,240,199,.35)'; c.strokeRect(trainX+.5,ty+.5,238,24);
      // windows
      for(var w2=0;w2<7;w2++){
        c.fillStyle='rgba(25,240,199,'+(0.35+0.25*Math.sin(t/300+w2))+')';
        c.fillRect(trainX+14+w2*30,ty+6,18,7);
      }
      // underglow
      c.fillStyle='rgba(25,240,199,.16)';
      c.fillRect(trainX+4,ty+26,230,2);
    }

    // platform edge + hazard stripe
    c.fillStyle='#11101a'; c.fillRect(0,TH-30,TW,30);
    c.save(); c.beginPath(); c.rect(0,TH-30,TW,5); c.clip();
    for(var hx=-10;hx<TW+10;hx+=18){
      c.fillStyle='rgba(232,163,61,.4)';
      c.beginPath(); c.moveTo(hx,TH-25); c.lineTo(hx+9,TH-30); c.lineTo(hx+15,TH-30); c.lineTo(hx+6,TH-25); c.closePath(); c.fill();
    }
    c.restore();
    // platform texture
    c.fillStyle='rgba(255,255,255,.03)';
    for(var px=20;px<TW;px+=46) c.fillRect(px,TH-18,26,1.5);

    // signal stack (right)
    var lights=[['#e23b3b',0],['#e8a33d',1],['#3bcf63',2]];
    c.fillStyle='#0a0a14'; c.fillRect(TW-34,30,14,44);
    c.strokeStyle='rgba(255,255,255,.15)'; c.strokeRect(TW-34.5,30.5,14,44);
    for(var li=0;li<3;li++){
      var on=signal===lights[li][1];
      c.fillStyle=on?lights[li][0]:'rgba(255,255,255,.07)';
      c.shadowColor=lights[li][0]; c.shadowBlur=on?8:0;
      c.beginPath(); c.arc(TW-27,38+li*14,4,0,Math.PI*2); c.fill();
    }
    c.shadowBlur=0;

    // fog
    for(var fi=0;fi<fog.length;fi++){
      var F=fog[fi];
      F.x+=F.v; if(F.x>TW+F.r) F.x=-F.r; if(F.x<-F.r) F.x=TW+F.r;
      var fg2=c.createRadialGradient(F.x,TH-26,4,F.x,TH-26,F.r);
      fg2.addColorStop(0,'rgba(170,170,200,.05)'); fg2.addColorStop(1,'rgba(170,170,200,0)');
      c.fillStyle=fg2; c.fillRect(F.x-F.r,TH-70,F.r*2,70);
    }
  }
  requestAnimationFrame(tLoop);
}

/* ── Result ceremonies (consume once) ── */
var ev=window._trFx||null;
window._trFx=null;
if(ev&&window.trMineOverlay){
  if(ev.t==='mine') window.trMineOverlay(ev.qty||1, ev.item||'', ev.drive||0);
}
})();
</script>

<script>
/* ══ THE SWITCHYARD client ══ */
(function(){
'use strict';
var TY = <?= json_encode($tyClientCfg) ?>;

var canvas=document.getElementById('ty-canvas');
if(!canvas) return;
var ctx=canvas.getContext('2d');
var TS=40, GW=13, GH=11;
var dpr=Math.min(2,window.devicePixelRatio||1);
canvas.width=GW*TS*dpr; canvas.height=GH*TS*dpr;
canvas.style.width='min(100%, '+(GW*TS)+'px)';
ctx.scale(dpr,dpr);

var state=TY.state||null;
var demand=TY.demand||{};
var busy=false, dashArmed=false, lastDir='right', gameOver=false;
var anim={x:state?state.px:0, y:state?state.py:0};
var droneAnims=[]; // lerped per drone index
var floaters=[];   // {x,y,txt,col,age}
var flash=0;       // white/red flash strength
var msgTimer=null;

function syncDroneAnims(){
  droneAnims=[];
  if(!state) return;
  for(var i=0;i<state.drones.length;i++) droneAnims.push({x:state.drones[i].x, y:state.drones[i].y});
}
syncDroneAnims();

function routeMeta(){ return (state&&TY.routes[state.route])||{name:'The Switchyard',col:'#e8a33d'}; }

function showMsg(t,col){
  var el=document.getElementById('ty-msg'); if(!el) return;
  el.textContent=t; el.style.color=col||'var(--muted)'; el.style.opacity='1';
  if(msgTimer) clearTimeout(msgTimer);
  msgTimer=setTimeout(function(){el.style.opacity='0';},2600);
}

function hud(){
  if(!state) return;
  var rm=routeMeta();
  var t=document.getElementById('ty-run-title');
  if(t) t.innerHTML='&#128230; Run to '+rm.name;
  var sub=document.getElementById('ty-run-sub');
  if(sub){
    sub.textContent = state.frozen>0
      ? 'Drones jammed for '+state.frozen+' more move'+(state.frozen===1?'':'s')+'.'
      : (state.weight>=state.ovl ? 'OVERLOADED — drones move twice per step!' : 'Reach the terminal. Watch the beats.');
    sub.style.color = state.weight>=state.ovl ? 'var(--neon2)' : (state.frozen>0?'#4de8e8':'var(--muted)');
  }
  var c=document.getElementById('ty-hud-cargo'); if(c) c.textContent=state.carried_val.toLocaleString('en-US');
  var w=document.getElementById('ty-hud-weight'); if(w) w.textContent=state.weight+'/'+state.ovl;
  var wb=document.getElementById('ty-hud-weight-box'); if(wb) wb.className='ty-hud'+(state.weight>=state.ovl?' warn':'');
  var l=document.getElementById('ty-hud-left'); if(l) l.textContent=state.left;
  var jb=document.getElementById('ty-jam-btn');
  if(jb){ jb.disabled=!!state.jam_used; jb.textContent=state.jam_used?'Jammer spent':'Jam · <?= TY_JAM_DRIVE ?>⚡'; }
}
function setDemandChip(mult){
  var d=document.getElementById('ty-hud-demand');
  if(d){ d.innerHTML='&times;'+Number(mult).toFixed(2); d.style.color = mult>=1.1?'#3bcf63':(mult>=0.9?'#e8a33d':'var(--neon2)'); }
}
function setDriveChip(v){
  var el=document.getElementById('tr-drive-chip'); if(el&&v!=null) el.textContent=v;
}
function updateLobbyDemand(all){
  if(!all) return;
  demand=all;
  document.querySelectorAll('.ty-dm-pct').forEach(function(el){
    var rk=el.getAttribute('data-route'), v=all[rk];
    if(v==null) return;
    el.innerHTML='&times;'+Number(v).toFixed(2);
    el.style.color = v>=1.1?'#3bcf63':(v>=0.9?'#e8a33d':'var(--neon2)');
  });
  document.querySelectorAll('.ty-dm-bar').forEach(function(el){
    var rk=el.getAttribute('data-route'), v=all[rk];
    if(v==null) return;
    el.style.width=Math.max(4,Math.min(100,Math.round((v-0.5)/1.1*100)))+'%';
    el.style.background = v>=1.1?'#3bcf63':(v>=0.9?'#e8a33d':'var(--neon2)');
  });
}

// ── Render loop ─────────────────────────────────────────────────────────────
function draw(t){
  if(!document.body.contains(canvas)) return;
  requestAnimationFrame(draw);
  ctx.clearRect(0,0,GW*TS,GH*TS);
  if(!state){ return; }
  var rm=routeMeta();

  // floor
  ctx.fillStyle='#07070f'; ctx.fillRect(0,0,GW*TS,GH*TS);
  ctx.strokeStyle='rgba(255,255,255,.03)';
  for(var gx=0;gx<=GW;gx++){ ctx.beginPath(); ctx.moveTo(gx*TS+.5,0); ctx.lineTo(gx*TS+.5,GH*TS); ctx.stroke(); }
  for(var gy=0;gy<=GH;gy++){ ctx.beginPath(); ctx.moveTo(0,gy*TS+.5); ctx.lineTo(GW*TS,gy*TS+.5); ctx.stroke(); }

  // patrol beats (dotted rails) under everything else
  ctx.save();
  ctx.setLineDash([3,5]);
  ctx.strokeStyle='rgba(226,59,59,.28)';
  ctx.lineWidth=2;
  for(var di=0;di<state.drones.length;di++){
    var p=state.drones[di].path;
    ctx.beginPath();
    ctx.moveTo(p[0][0]*TS+TS/2, p[0][1]*TS+TS/2);
    ctx.lineTo(p[p.length-1][0]*TS+TS/2, p[p.length-1][1]*TS+TS/2);
    ctx.stroke();
  }
  ctx.restore();

  // tiles
  for(var y=0;y<state.h;y++)for(var x=0;x<state.w;x++){
    var tt=state.grid[y][x], px=x*TS, py=y*TS;
    if(tt===0){
      // container stack
      var edge=(x===0||y===0||x===state.w-1||y===state.h-1);
      ctx.fillStyle=edge?'#0d0d18':'#141225';
      ctx.fillRect(px+1,py+1,TS-2,TS-2);
      if(!edge){
        ctx.fillStyle='rgba(232,163,61,.10)';
        ctx.fillRect(px+3,py+3,TS-6,5);
        ctx.strokeStyle='rgba(255,255,255,.10)';
        ctx.strokeRect(px+3.5,py+3.5,TS-7,TS-7);
        // corrugation
        ctx.strokeStyle='rgba(255,255,255,.04)';
        for(var cc=px+8;cc<px+TS-6;cc+=6){ ctx.beginPath(); ctx.moveTo(cc,py+10); ctx.lineTo(cc,py+TS-6); ctx.stroke(); }
      }
    } else if(tt===3){
      // terminal
      var pulse=.5+.5*Math.sin(t/400);
      ctx.fillStyle='rgba(59,207,99,'+(.10+.10*pulse)+')';
      ctx.fillRect(px+2,py+2,TS-4,TS-4);
      ctx.strokeStyle='rgba(59,207,99,'+(.5+.4*pulse)+')';
      ctx.strokeRect(px+4.5,py+4.5,TS-9,TS-9);
      ctx.fillStyle='#3bcf63'; ctx.font='9px sans-serif'; ctx.textAlign='center';
      ctx.fillText('DELIVER',px+TS/2,py+TS/2+3);
    } else if(tt===4){
      // depot pad
      ctx.strokeStyle='rgba(25,240,199,.35)';
      ctx.strokeRect(px+4.5,py+4.5,TS-9,TS-9);
      ctx.fillStyle='rgba(25,240,199,.55)'; ctx.font='8px sans-serif'; ctx.textAlign='center';
      ctx.fillText('DEPOT',px+TS/2,py+TS/2+3);
    }
  }

  // crates
  ctx.textAlign='center';
  for(var ci=0;ci<state.crates.length;ci++){
    var cr=state.crates[ci], cx=cr.x*TS, cy=cr.y*TS;
    var bob=Math.sin(t/500+cr.x*3+cr.y)*1.5;
    ctx.fillStyle='rgba(0,0,0,.5)';
    ctx.fillRect(cx+9,cy+TS-10,TS-18,4);
    ctx.fillStyle=cr.col+'33';
    ctx.fillRect(cx+8,cy+8+bob,TS-16,TS-18);
    ctx.strokeStyle=cr.col;
    ctx.strokeRect(cx+8.5,cy+8.5+bob,TS-17,TS-19);
    ctx.strokeStyle=cr.col+'88';
    ctx.beginPath(); ctx.moveTo(cx+8,cy+8+bob+(TS-18)/2); ctx.lineTo(cx+TS-8,cy+8+bob+(TS-18)/2); ctx.stroke();
    ctx.fillStyle=cr.col; ctx.font='bold 9px sans-serif';
    ctx.fillText(cr.v,cx+TS/2,cy+5+bob);
  }

  // drones (lerped)
  for(var d2=0;d2<state.drones.length;d2++){
    var dr=state.drones[d2];
    var da=droneAnims[d2]||{x:dr.x,y:dr.y};
    da.x+=(dr.x-da.x)*.25; da.y+=(dr.y-da.y)*.25;
    droneAnims[d2]=da;
    var dx=da.x*TS+TS/2, dy=da.y*TS+TS/2;
    var frozen=state.frozen>0;
    // next-step chevron
    if(!frozen){
      ctx.fillStyle='rgba(226,59,59,.45)';
      var nx2=dr.nx*TS+TS/2, ny2=dr.ny*TS+TS/2;
      ctx.beginPath(); ctx.arc(nx2,ny2,3.5,0,Math.PI*2); ctx.fill();
    }
    // body
    ctx.save();
    ctx.translate(dx,dy);
    var spin=frozen?0:t/600;
    ctx.rotate(spin%(Math.PI*2));
    ctx.fillStyle=frozen?'rgba(77,232,232,.25)':'rgba(226,59,59,.22)';
    ctx.beginPath(); ctx.arc(0,0,13,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle=frozen?'#4de8e8':'#e23b3b';
    ctx.lineWidth=2;
    ctx.strokeRect(-8,-8,16,16);
    ctx.restore();
    // eye
    ctx.fillStyle=frozen?'#4de8e8':'#ff5a5a';
    ctx.shadowColor=frozen?'#4de8e8':'#e23b3b'; ctx.shadowBlur=8;
    ctx.beginPath(); ctx.arc(dx,dy,3.5,0,Math.PI*2); ctx.fill();
    ctx.shadowBlur=0;
    if(frozen){
      ctx.strokeStyle='rgba(77,232,232,'+(.4+.3*Math.sin(t/200))+')';
      ctx.beginPath(); ctx.arc(dx,dy,16,0,Math.PI*2); ctx.stroke();
    }
  }

  // player loader (lerped)
  anim.x+=(state.px-anim.x)*.3; anim.y+=(state.py-anim.y)*.3;
  var lx=anim.x*TS, ly=anim.y*TS;
  var over=state.weight>=state.ovl;
  ctx.fillStyle='rgba(0,0,0,.5)'; ctx.fillRect(lx+9,ly+TS-9,TS-18,4);
  ctx.fillStyle=over?'rgba(255,45,149,.25)':'rgba(25,240,199,.2)';
  ctx.fillRect(lx+6,ly+6,TS-12,TS-12);
  ctx.strokeStyle=over?'#ff2d95':'#19f0c7';
  ctx.lineWidth=2;
  ctx.strokeRect(lx+6.5,ly+6.5,TS-13,TS-13);
  ctx.lineWidth=1;
  // cab light in facing direction
  var fo={up:[0,-1],down:[0,1],left:[-1,0],right:[1,0]}[lastDir]||[1,0];
  ctx.fillStyle=over?'#ff2d95':'#19f0c7';
  ctx.shadowColor=over?'#ff2d95':'#19f0c7'; ctx.shadowBlur=7;
  ctx.beginPath(); ctx.arc(lx+TS/2+fo[0]*9, ly+TS/2+fo[1]*9, 3, 0, Math.PI*2); ctx.fill();
  ctx.shadowBlur=0;
  // weight pips
  for(var wp=0;wp<state.weight;wp++){
    ctx.fillStyle=wp>=state.ovl-1?'#ff2d95':'#e8a33d';
    ctx.fillRect(lx+8+wp*5, ly+TS-13, 3, 5);
  }
  if(dashArmed){
    ctx.strokeStyle='rgba(232,163,61,'+(.5+.4*Math.sin(t/150))+')';
    ctx.strokeRect(lx+2.5,ly+2.5,TS-5,TS-5);
  }

  // floaters
  for(var fi2=floaters.length-1;fi2>=0;fi2--){
    var F=floaters[fi2];
    F.age+=1/60;
    if(F.age>1.2){ floaters.splice(fi2,1); continue; }
    ctx.globalAlpha=Math.max(0,1-F.age/1.2);
    ctx.fillStyle=F.col; ctx.font='bold 12px sans-serif'; ctx.textAlign='center';
    ctx.fillText(F.txt, F.x*TS+TS/2, F.y*TS+6-F.age*24);
    ctx.globalAlpha=1;
  }

  // flash
  if(flash>0){
    ctx.fillStyle='rgba(226,59,59,'+(flash*.4)+')';
    ctx.fillRect(0,0,GW*TS,GH*TS);
    flash=Math.max(0,flash-.05);
  }

  // route tint edge
  ctx.strokeStyle=rm.col+'44';
  ctx.strokeRect(1,1,GW*TS-2,GH*TS-2);
}
requestAnimationFrame(draw);

// ── AJAX ────────────────────────────────────────────────────────────────────
function tyPost(data,cb){
  if(busy) return;
  busy=true;
  data.ty_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;showMsg('Network error','var(--neon2)');});
}

function endRun(){
  state=null; gameOver=false;
  document.getElementById('ty-active').style.display='none';
  document.getElementById('ty-lobby').style.display='';
}

function applyState(d){
  state=d.state;
  if(droneAnims.length!==state.drones.length) syncDroneAnims();
  if(d.drive!=null) setDriveChip(d.drive);
  hud();
}

window.tyStart=function(route){
  tyPost({ty_action:'start',route:route},function(d){
    if(!d.ok){ showMsg(d.err||'Error','var(--neon2)'); if(d.err) alert(d.err); return; }
    state=d.state;
    anim={x:state.px,y:state.py};
    syncDroneAnims();
    dashArmed=false; floaters=[]; flash=0; lastDir='right';
    if(d.drive!=null) setDriveChip(d.drive);
    setDemandChip(d.demand!=null?d.demand:1);
    document.getElementById('ty-lobby').style.display='none';
    document.getElementById('ty-active').style.display='';
    hud();
    window.trFX&&window.trFX.rumble();
    showMsg('Clocked on. Deliver to '+routeMeta().name+'.','var(--accent)');
  });
};

function handleResult(d){
  if(!d.ok){
    if((d.err||'')==='Blocked.'){ window.trFX&&window.trFX.tone(90,.06,'square',.025); }
    else showMsg(d.err||'Error','var(--neon2)');
    return;
  }
  if(d.bust){
    flash=1; gameOver=true;
    window.trFX&&window.trFX.alarm();
    var hpEl=document.getElementById('tr-hp-chip');
    if(hpEl&&d.hp!=null){ hpEl.textContent=d.hp; if(d.hp<15) hpEl.style.color='var(--neon2)'; }
    if(d.drive!=null) setDriveChip(d.drive);
    var lostTxt=d.bust.lost_n>0?(d.bust.reason+' — '+d.bust.lost_n+' crate'+(d.bust.lost_n===1?'':'s')+' ('+d.bust.lost_v.toLocaleString('en-US')+' cr) lost, −'+d.bust.dmg+' HP'):(d.bust.reason+' — −'+d.bust.dmg+' HP');
    window.trRunOverlay&&window.trRunOverlay(false,d.bust.dmg,lostTxt,null);
    setTimeout(endRun,1200);
    return;
  }
  if(d.delivered){
    var dv=d.delivered;
    gameOver=true;
    window.trFX&&window.trFX.chime();
    updateLobbyDemand(d.demand_all);
    if(d.drive!=null) setDriveChip(d.drive);
    var rname=(TY.routes[dv.route]||{}).name||'the terminal';
    var extra=dv.crates+' crates ×'+dv.mult.toFixed(2)+' demand · +'+dv.xp+' XP';
    if(dv.ore&&dv.ore.length){ extra+=' · ore: '+dv.ore.map(function(o){return o.qty+'× '+o.name;}).join(', '); }
    window.trRunOverlay&&window.trRunOverlay(true,dv.pay,rname+' — '+extra,null);
    setTimeout(endRun,1600);
    return;
  }
  applyState(d);
  if(d.picked){
    floaters.push({x:d.picked.x,y:d.picked.y,txt:'+'+d.picked.v+' cr',col:'#3bcf63',age:0});
    window.trFX&&window.trFX.pick();
    if(state.weight>=state.ovl) showMsg('OVERLOADED — drones move twice per step!','var(--neon2)');
  }
  if(d.jammed){
    window.trFX&&window.trFX.jam();
    showMsg('Drones jammed for '+state.frozen+' moves.','#4de8e8');
  }
}

window.tyGo=function(dir){
  if(!state||busy||gameOver) return;
  lastDir=dir;
  var act=dashArmed?'dash':'move';
  dashArmed=false;
  var db=document.getElementById('ty-dash-btn'); if(db) db.classList.remove('on');
  tyPost({ty_action:act,dir:dir},handleResult);
};
window.tyAct=function(act){
  if(!state||busy||gameOver) return;
  tyPost({ty_action:act},handleResult);
};
window.tyDashToggle=function(){
  dashArmed=!dashArmed;
  var db=document.getElementById('ty-dash-btn'); if(db) db.classList.toggle('on',dashArmed);
};
window.tyAbandon=function(){
  if(!state||busy||gameOver) return;
  if(!confirm('Abandon the run? Carried crates are lost.')) return;
  tyPost({ty_action:'abandon'},function(d){
    if(d.ok){ showMsg('Run abandoned.','var(--muted)'); endRun(); }
  });
};

// keyboard — bound once, self-guarding against detached canvas + form fields
if(!window._tyKeysBound){
  window._tyKeysBound=true;
  document.addEventListener('keydown',function(e){
    var cv=document.getElementById('ty-canvas');
    if(!cv||!document.body.contains(cv)) return;
    if(/INPUT|TEXTAREA|SELECT/.test((e.target&&e.target.tagName)||'')) return;
    var act=document.getElementById('ty-active');
    if(!act||act.style.display==='none') return;
    var map={ArrowUp:'up',ArrowDown:'down',ArrowLeft:'left',ArrowRight:'right',w:'up',s:'down',a:'left',d:'right',W:'up',S:'down',A:'left',D:'right'};
    if(map[e.key]){
      e.preventDefault();
      if(e.shiftKey&&window.tyDashToggle&&!document.getElementById('ty-dash-btn').classList.contains('on')) window.tyDashToggle();
      window.tyGo(map[e.key]);
    } else if(e.key===' '||e.key==='.'){ e.preventDefault(); window.tyAct('hold'); }
    else if(e.key==='j'||e.key==='J'){ e.preventDefault(); window.tyAct('jam'); }
  });
}

if(state){
  hud();
  var dm=demand[state.route];
  setDemandChip(dm!=null?dm:1);
}
})();
</script>
