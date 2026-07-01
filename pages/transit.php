<?php /* pages/transit.php — The Loading Docks: Transit Hub (cargo runs + mining)
   Cargo runs used to be a single click that resolved instantly, entirely
   server-decided with no player input beyond "go." Reworked into a 3-
   checkpoint run: the player picks an approach at each checkpoint (Push
   Through / Play It Safe / Scan & Reroute), and those choices actually shift
   the ambush odds and the payout for that leg — same session-stored
   multi-step pattern as daemon.php's blackjack, not a new architecture. */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$fxEvent = null; // ceremony payload for the client

$routes = [
  'short' => ['name'=>'Short Hop', 'sub'=>'Inner Stacks',    'cost'=>1, 'pay_min'=>20,  'pay_max'=>50,  'ambush'=>5,  'xp'=>5,  'icon'=>'&#128666;', 'col'=>'#3bcf63'],
  'mid'   => ['name'=>'Haul Run',  'sub'=>'Cross-Sprawl',    'cost'=>2, 'pay_min'=>60,  'pay_max'=>130, 'ambush'=>7,  'xp'=>9,  'icon'=>'&#128643;', 'col'=>'#e8a33d'],
  'long'  => ['name'=>'Outer Run', 'sub'=>'Outer Stacks',    'cost'=>4, 'pay_min'=>150, 'pay_max'=>320, 'ambush'=>10, 'xp'=>16, 'icon'=>'&#128740;', 'col'=>'#ff2d95'],
];
// ambush% above is now a PER-CHECKPOINT base rate (3 checkpoints per run),
// not the old one-shot whole-route rate — routes actually keep a similar
// overall risk across the full run, just spread across 3 decision points.
const RUN_CHECKPOINTS = 3;
const CHECKPOINT_ACTIONS = [
  'push' => ['label'=>'Push Through', 'risk_mult'=>1.5, 'pay_mult'=>1.35, 'drive_cost'=>0, 'desc'=>'Faster, louder, more likely to draw attention — but pays out more if it\'s clear.'],
  'safe' => ['label'=>'Play It Safe',  'risk_mult'=>0.6, 'pay_mult'=>1.0,  'drive_cost'=>0, 'desc'=>'Standard pace, standard cut. The reliable choice.'],
  'scan' => ['label'=>'Scan & Reroute','risk_mult'=>0.25,'pay_mult'=>0.9,  'drive_cost'=>2, 'desc'=>'Burns Drive to route around trouble. Safest option, smaller cut.'],
];

$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

$skillPts = $skillName = [];
$sp = $pdo->prepare('SELECT s.code, s.name, ps.points FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?');
$sp->execute([$pid]);
foreach ($sp as $row) { $skillPts[$row['code']] = (int)$row['points']; $skillName[$row['code']] = $row['name']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'start_run') {
      if (!empty($_SESSION['transit_run'])) throw new RuntimeException('You already have a run in progress.');
      $key = $_POST['route'] ?? '';
      if (!isset($routes[$key])) throw new RuntimeException('No such route on the board.');
      $rt = $routes[$key];
      $u = $pdo->prepare('UPDATE players SET integrity = integrity - ? WHERE id = ? AND integrity >= ?');
      $u->execute([$rt['cost'], $pid, $rt['cost']]);
      if ($u->rowCount() !== 1) throw new RuntimeException("Too damaged for the {$rt['name']} — patch up first.");
      $_SESSION['transit_run'] = ['route' => $key, 'checkpoint' => 1, 'payout' => 0, 'log' => []];
      $player = current_player();
      $msg = "Convoy dispatched on the {$rt['name']}. First checkpoint ahead — pick your approach.";
      $msgType = 'ok';

    } elseif ($action === 'checkpoint_action') {
      $run = $_SESSION['transit_run'] ?? null;
      if (!$run) throw new RuntimeException('No run in progress — dispatch a convoy first.');
      $rt = $routes[$run['route']] ?? null;
      if (!$rt) { unset($_SESSION['transit_run']); throw new RuntimeException('Route data missing — run cancelled.'); }
      $choice = $_POST['choice'] ?? '';
      if (!isset(CHECKPOINT_ACTIONS[$choice])) throw new RuntimeException('Pick an approach for this checkpoint.');
      $ca = CHECKPOINT_ACTIONS[$choice];

      if ($ca['drive_cost'] > 0) {
        $dc = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
        $dc->execute([$ca['drive_cost'], $pid, $ca['drive_cost']]);
        if ($dc->rowCount() !== 1) throw new RuntimeException("Not enough Drive to scan ahead — needs {$ca['drive_cost']}.");
      }

      $checkpointAmbush = min(90, (int)round($rt['ambush'] * $ca['risk_mult']));
      $ambushed = random_int(1, 100) <= $checkpointAmbush;

      if ($ambushed) {
        $extra = random_int(1, $rt['cost'] + 2);
        $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id = ?')->execute([$extra, $pid]);
        unset($_SESSION['transit_run']);
        $player = current_player();
        $msg = "Ambushed at checkpoint {$run['checkpoint']} on the {$rt['name']}! Took {$extra} extra damage and lost the cargo.";
        $msgType = 'err';
        $fxEvent = ['t'=>'run','ok'=>false,'dmg'=>$extra,'route'=>$rt['name']];
      } else {
        $legPay = (int)round(random_int($rt['pay_min'], $rt['pay_max']) / RUN_CHECKPOINTS * $ca['pay_mult']);
        $run['payout'] += $legPay;
        $run['log'][] = ['cp' => $run['checkpoint'], 'choice' => $ca['label'], 'pay' => $legPay];
        if ($run['checkpoint'] >= RUN_CHECKPOINTS) {
          $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$run['payout'], $pid]);
          try { grant_xp($pid, $rt['xp']); } catch (Throwable $e) {}
          unset($_SESSION['transit_run']);
          $player = current_player();
          $msg = "Ran the {$rt['name']} clean — hauled in <b style=\"color:var(--accent)\">" . number_format($run['payout']) . " credits</b>.";
          $msgType = 'ok';
          $fxEvent = ['t'=>'run','ok'=>true,'pay'=>$run['payout'],'route'=>$rt['name']];
        } else {
          $run['checkpoint']++;
          $_SESSION['transit_run'] = $run;
          $msg = "Checkpoint clear (+" . number_format($legPay) . " credits so far). Checkpoint {$run['checkpoint']} ahead.";
          $msgType = 'ok';
        }
      }

    } elseif ($action === 'mine') {
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
$activeRun = $_SESSION['transit_run'] ?? null;
$activeRoute = $activeRun ? ($routes[$activeRun['route']] ?? null) : null;
if ($activeRun && !$activeRoute) { unset($_SESSION['transit_run']); $activeRun = null; } // stale/invalid route code
?>
<style>
#tr-canvas{display:block;width:100%;height:118px;border-radius:9px 9px 0 0}
#tr-head h2{text-shadow:0 0 14px rgba(232,163,61,.4)}
.tr-chip{display:inline-flex;flex-direction:column;align-items:center;padding:5px 13px;background:rgba(6,6,14,.78);border:1px solid var(--line);border-radius:8px;backdrop-filter:blur(3px)}
.tr-chip b{font-family:'Orbitron',sans-serif;font-size:14px}
.tr-chip span{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:1px}
.tr-route{position:relative;overflow:hidden;padding:14px 16px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:8px;transition:background .15s}
.tr-route:hover{background:rgba(255,255,255,.02)}
.tr-route::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--tr-col),transparent)}
.tr-risk{height:5px;border-radius:3px;background:rgba(255,255,255,.07);overflow:hidden}
.tr-risk>div{height:100%;border-radius:3px}
.tr-dig{transition:background .12s}
.tr-dig:hover{background:rgba(255,255,255,.02)}
.tr-go{width:100%;font-size:12px;padding:7px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent);transition:transform .08s}
.tr-go:active{transform:scale(.97)}
</style>

<div class="panel" id="tr-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="tr-canvas"></canvas>
    <div style="position:absolute;left:16px;top:10px;pointer-events:none">
      <h2 style="margin:0">&#128643; The Loading Docks</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Run cargo for credits or mine the service tunnels. Both bite back.</p>
    </div>
    <div style="position:absolute;right:14px;top:12px;display:flex;gap:8px">
      <div class="tr-chip"><b style="color:<?= (int)$player['integrity'] < 3 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= (int)$player['integrity'] ?></b><span>Health</span></div>
      <div class="tr-chip"><b style="color:#e8a33d"><?= (int)$player['cycles'] ?></b><span>Drive</span></div>
      <div class="tr-chip"><b style="color:#4d6be8"><?= $drone ?></b><span>Drone</span></div>
    </div>
    <button id="tr-mute" onclick="toggleTrSound()" title="Toggle sound" style="position:absolute;bottom:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgType==='err' ? 'flash-err' : 'flash-ok' ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- Cargo Runs -->
<?php if ($activeRun && $activeRoute): ?>
<div class="panel" style="padding:0;overflow:hidden" id="tr-run-active">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line)">
    <div style="font-size:13px;font-weight:700"><?= $activeRoute['icon'] ?> Convoy En Route &mdash; <?= e($activeRoute['name']) ?></div>
    <div style="font-size:11px;color:var(--muted);margin-top:1px">Checkpoint <?= (int)$activeRun['checkpoint'] ?> of <?= RUN_CHECKPOINTS ?> &middot; <?= number_format($activeRun['payout']) ?> credits secured so far</div>
  </div>
  <div style="padding:14px 16px">
    <div style="display:flex;gap:6px;margin-bottom:14px">
      <?php for ($cp = 1; $cp <= RUN_CHECKPOINTS; $cp++): ?>
      <div style="flex:1;height:6px;border-radius:3px;background:<?= $cp < $activeRun['checkpoint'] ? '#3bcf63' : ($cp === $activeRun['checkpoint'] ? $activeRoute['col'] : 'rgba(255,255,255,.08)') ?>"></div>
      <?php endfor; ?>
    </div>
    <?php if ($activeRun['log']): ?>
    <div style="font-size:11px;color:var(--muted);margin-bottom:14px">
      <?php foreach ($activeRun['log'] as $entry): ?>
      <div>Checkpoint <?= (int)$entry['cp'] ?>: <b style="color:var(--text)"><?= e($entry['choice']) ?></b> &mdash; clear, <span style="color:#3bcf63">+<?= number_format($entry['pay']) ?> credits</span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
      <?php foreach (CHECKPOINT_ACTIONS as $ck => $ca):
        $cpRisk = min(90, (int)round($activeRoute['ambush'] * $ca['risk_mult']));
        $riskCol = $cpRisk <= 5 ? '#3bcf63' : ($cpRisk <= 10 ? '#e8a33d' : 'var(--neon2)');
        $canAfford = $ca['drive_cost'] <= (int)$player['cycles'];
      ?>
      <form method="post" style="margin:0" data-trfx="checkpoint" data-tr-route="<?= e($activeRoute['name']) ?>" data-tr-col="<?= $activeRoute['col'] ?>">
        <input type="hidden" name="action" value="checkpoint_action">
        <input type="hidden" name="choice" value="<?= e($ck) ?>">
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:10px 12px;<?= !$canAfford ? 'opacity:.5' : '' ?>">
          <div style="font-weight:700;font-size:13px;margin-bottom:3px"><?= e($ca['label']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:8px;min-height:28px"><?= e($ca['desc']) ?></div>
          <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:8px">
            <span>Risk: <b style="color:<?= $riskCol ?>"><?= $cpRisk ?>%</b></span>
            <?php if ($ca['drive_cost'] > 0): ?><span style="color:#e8a33d">&minus;<?= $ca['drive_cost'] ?> Drive</span><?php endif; ?>
          </div>
          <button type="submit" class="tr-go" <?= !$canAfford ? 'disabled' : '' ?>><?= $canAfford ? 'Choose' : 'Not Enough Drive' ?></button>
        </div>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-size:13px;font-weight:700">&#128230; Cargo Runs</div>
      <div style="font-size:11px;color:var(--muted);margin-top:1px">Spend Health, earn credits over 3 checkpoints. Your approach at each one shifts the risk and the payout.</div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0;border-top:none">
    <?php foreach ($routes as $k => $rt):
      $canRun = (int)$player['integrity'] >= $rt['cost'];
      $ambushColor = $rt['ambush'] <= 6 ? '#3bcf63' : ($rt['ambush'] <= 8 ? '#e8a33d' : 'var(--neon2)');
    ?>
    <div class="tr-route" style="--tr-col:<?= $rt['col'] ?>;<?= !$canRun ? 'opacity:.5' : '' ?>">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:22px;text-shadow:0 0 10px <?= $rt['col'] ?>66"><?= $rt['icon'] ?></span>
        <div>
          <div style="font-weight:700;font-size:13px"><?= $rt['name'] ?></div>
          <div style="font-size:11px;color:var(--muted)"><?= $rt['sub'] ?></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px">
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px">
          <div style="color:var(--muted)">Payout</div>
          <div style="color:#3bcf63;font-weight:700"><?= number_format($rt['pay_min']) ?>–<?= number_format($rt['pay_max']) ?> credits</div>
        </div>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px">
          <div style="color:var(--muted)">Cost</div>
          <div style="color:#e8a33d;font-weight:700"><?= $rt['cost'] ?> Health</div>
        </div>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px;grid-column:span 2">
          <div style="display:flex;justify-content:space-between;color:var(--muted)"><span>Base risk / checkpoint</span><b style="color:<?= $ambushColor ?>"><?= $rt['ambush'] ?>%</b></div>
          <div class="tr-risk" style="margin-top:4px"><div style="width:<?= min(100, $rt['ambush'] * 8) ?>%;background:<?= $ambushColor ?>"></div></div>
        </div>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px;grid-column:span 2;display:flex;justify-content:space-between">
          <span style="color:var(--muted)">XP reward</span>
          <b style="color:var(--accent)">+<?= $rt['xp'] ?></b>
        </div>
      </div>
      <?php if ($canRun): ?>
      <form method="post" style="margin:0" data-trfx="start" data-tr-route="<?= e($rt['name']) ?>">
        <input type="hidden" name="action" value="start_run">
        <input type="hidden" name="route" value="<?= e($k) ?>">
        <button type="submit" class="tr-go">Dispatch Convoy</button>
      </form>
      <?php else: ?>
      <div style="text-align:center;font-size:11px;color:var(--muted);padding:6px;border:1px solid var(--line);border-radius:5px;font-style:italic">Need <?= $rt['cost'] ?> Health</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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
        <span>Yields: <b style="color:var(--accent)"><?= (int)$m['yield_min'] ?>–<?= (int)$m['yield_max'] ?> <?= e($m['item_name']) ?></b></span>
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
    var dist=ok?206:130; // ambush stops the cargo mid-route
    cargo.animate([{transform:'translateX(0)'},{transform:'translateX('+dist+'px)'}],
      {duration:ok?900:620,easing:ok?'ease-in-out':'ease-in',fill:'forwards'});
    setTimeout(function(){
      if(ok){
        window.trFX.chime();
        label.innerHTML='RUN COMPLETE<span class="trfx-sub">'+route+' — +'+amount.toLocaleString('en-US')+' credits</span>';
      } else {
        window.trFX.alarm();
        var boom=document.createElement('div');
        boom.className='trfx-boom';
        boom.style.left=(6+dist-4)+'px'; boom.style.top='40px';
        boom.style.animation='trfxBoom .55s ease-out forwards';
        o.querySelector('.trfx-stage').appendChild(boom);
        cargo.style.filter='grayscale(1) brightness(.6)';
        label.innerHTML='AMBUSHED<span class="trfx-sub">'+route+' — cargo lost, −'+amount+' HP</span>';
      }
      label.classList.add('show');
    },ok?920:640);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},2200);
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
if(ev&&window.trRunOverlay){
  if(ev.t==='run') window.trRunOverlay(!!ev.ok, ev.ok?ev.pay:ev.dmg, ev.route||'', null);
  else if(ev.t==='mine') window.trMineOverlay(ev.qty||1, ev.item||'', ev.drive||0);
}
})();
</script>
