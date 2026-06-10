<?php /* pages/transit.php — The Loading Docks: Transit Hub (cargo runs + mining) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

$routes = [
  'short' => ['name'=>'Short Hop', 'sub'=>'Inner Stacks',    'cost'=>1, 'pay_min'=>20,  'pay_max'=>50,  'ambush'=>12, 'xp'=>5,  'icon'=>'&#128666;'],
  'mid'   => ['name'=>'Haul Run',  'sub'=>'Cross-Sprawl',    'cost'=>2, 'pay_min'=>60,  'pay_max'=>130, 'ambush'=>18, 'xp'=>9,  'icon'=>'&#128643;'],
  'long'  => ['name'=>'Outer Run', 'sub'=>'Outer Stacks',    'cost'=>4, 'pay_min'=>150, 'pay_max'=>320, 'ambush'=>25, 'xp'=>16, 'icon'=>'&#128740;'],
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
    if ($action === 'run') {
      $key = $_POST['route'] ?? '';
      if (!isset($routes[$key])) throw new RuntimeException('No such route on the board.');
      $rt = $routes[$key];
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET integrity = integrity - ? WHERE id = ? AND integrity >= ?');
      $u->execute([$rt['cost'], $pid, $rt['cost']]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("Too damaged for the {$rt['name']} — patch up first."); }
      if (random_int(1, 100) <= $rt['ambush']) {
        $extra = random_int(1, $rt['cost'] + 2);
        $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id = ?')->execute([$extra, $pid]);
        $pdo->commit();
        $msg = "Ambushed on the {$rt['name']}! Took {$extra} extra damage and lost the cargo.";
        $msgType = 'err';
      } else {
        $pay = random_int($rt['pay_min'], $rt['pay_max']);
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$pay, $pid]);
        $pdo->commit();
        $msg = "Ran the {$rt['name']} clean — hauled in <b style=\"color:var(--accent)\">" . number_format($pay) . " cr</b>.";
        $msgType = 'ok';
      }
    } elseif ($action === 'mine') {
      $g = $pdo->prepare("SELECT g.*, i.name AS item_name FROM gather_nodes g JOIN items i ON i.id = g.item_id WHERE g.code = ? AND g.venue = 'transit'");
      $g->execute([$_POST['node'] ?? '']);
      $node = $g->fetch();
      if (!$node) throw new RuntimeException('No such dig site.');
      if (($skillPts[$node['skill_code']] ?? 0) < $node['skill_req'])
        throw new RuntimeException("Locked — needs {$skillName[$node['skill_code']]} {$node['skill_req']}.");
      $yield = random_int((int)$node['yield_min'], (int)$node['yield_max']);
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')->execute([$pid, $node['item_id'], $yield]);
      $msg = "Extracted <b style=\"color:var(--accent)\">{$yield}&times; {$node['item_name']}</b> from the tunnels.";
      $msgType = 'ok';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
    $msgType = 'err';
  }
  $player = current_player();
}

$mines = $pdo->query("SELECT g.*, i.name AS item_name FROM gather_nodes g JOIN items i ON i.id = g.item_id WHERE g.venue = 'transit' ORDER BY g.skill_req")->fetchAll();
$drone = (int)($skillPts['drone'] ?? 0);
$msgType = $msgType ?? 'ok';
?>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8a33d,var(--neon2),transparent)"></div>
  <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0 0 2px">&#128643; The Loading Docks</h2>
      <p class="muted" style="margin:0;font-size:12px">Run cargo for credits or mine the service tunnels. Both bite back.</p>
    </div>
    <div style="display:flex;gap:14px;font-size:12px">
      <div>Health: <b style="color:<?= (int)$player['integrity'] < 3 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= (int)$player['integrity'] ?></b><span class="muted"> / <?= (int)$player['integrity_max'] ?></span></div>
      <div>Drone: <b style="color:var(--text)"><?= $drone ?></b></div>
    </div>
  </div>
</div>

<?php if ($msg): ?>
<div style="background:<?= $msgType==='err'?'rgba(226,59,59,.07)':'rgba(25,240,199,.07)' ?>;border:1px solid <?= $msgType==='err'?'rgba(226,59,59,.25)':'rgba(25,240,199,.2)' ?>;border-radius:6px;padding:10px 14px;font-size:13px"><?= $msg ?></div>
<?php endif; ?>

<!-- Cargo Runs -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-size:13px;font-weight:700">&#128230; Cargo Runs</div>
      <div style="font-size:11px;color:var(--muted);margin-top:1px">Spend Health, earn credits. Ambush risk increases with distance.</div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0;border-top:none">
    <?php foreach ($routes as $k => $rt):
      $canRun = (int)$player['integrity'] >= $rt['cost'];
      $ambushColor = $rt['ambush'] <= 15 ? '#3bcf63' : ($rt['ambush'] <= 22 ? '#e8a33d' : 'var(--neon2)');
    ?>
    <div style="padding:14px 16px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:8px;<?= !$canRun ? 'opacity:.5' : '' ?>">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:22px"><?= $rt['icon'] ?></span>
        <div>
          <div style="font-weight:700;font-size:13px"><?= $rt['name'] ?></div>
          <div style="font-size:11px;color:var(--muted)"><?= $rt['sub'] ?></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px">
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px">
          <div style="color:var(--muted)">Payout</div>
          <div style="color:#3bcf63;font-weight:700"><?= number_format($rt['pay_min']) ?>–<?= number_format($rt['pay_max']) ?> cr</div>
        </div>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px">
          <div style="color:var(--muted)">Cost</div>
          <div style="color:#e8a33d;font-weight:700"><?= $rt['cost'] ?> Health</div>
        </div>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px">
          <div style="color:var(--muted)">Ambush</div>
          <div style="color:<?= $ambushColor ?>;font-weight:700"><?= $rt['ambush'] ?>%</div>
        </div>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:4px;padding:5px 8px">
          <div style="color:var(--muted)">XP</div>
          <div style="color:var(--accent);font-weight:700">+<?= $rt['xp'] ?></div>
        </div>
      </div>
      <?php if ($canRun): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="route" value="<?= e($k) ?>">
        <button type="submit" style="width:100%;font-size:12px;padding:7px">Run Route</button>
      </form>
      <?php else: ?>
      <div style="text-align:center;font-size:11px;color:var(--muted);padding:6px;border:1px solid var(--line);border-radius:5px;font-style:italic">Need <?= $rt['cost'] ?> Health</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

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
  <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:12px;flex-wrap:wrap;<?= !$unlocked ? 'opacity:.5' : '' ?>">
    <div style="font-size:22px;flex:none">&#9954;</div>
    <div style="flex:1;min-width:140px">
      <div style="font-weight:700;font-size:13px"><?= e($m['name']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= e($m['descr']) ?></div>
      <div style="margin-top:4px;display:flex;gap:10px;font-size:11px">
        <span>Yields: <b style="color:var(--accent)"><?= (int)$m['yield_min'] ?>–<?= (int)$m['yield_max'] ?> <?= e($m['item_name']) ?></b></span>
        <span style="color:var(--muted)">Req: <b style="color:<?= $unlocked ? 'var(--text)' : 'var(--neon2)' ?>"><?= e($skillName[$m['skill_code']] ?? $m['skill_code']) ?> <?= (int)$m['skill_req'] ?></b> (you: <?= (int)$have ?>)</span>
      </div>
    </div>
    <?php if ($unlocked): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="mine">
      <input type="hidden" name="node" value="<?= e($m['code']) ?>">
      <button type="submit" style="font-size:12px;padding:6px 16px">&#9954; Mine</button>
    </form>
    <?php else: ?>
    <div style="font-size:11px;color:var(--muted);font-style:italic;padding:5px 12px;border:1px solid var(--line);border-radius:5px">Locked</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
