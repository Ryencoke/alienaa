<?php /* pages/transit.php — The Loading Docks: Transit Hub (cargo runs + mining) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Cargo routes: travel gated by Integrity (cost), pays creds, risks an ambush.
$routes = [
  'short' => ['name'=>'Short Hop — Inner Stacks', 'cost'=>1, 'pay_min'=>20,  'pay_max'=>50,  'ambush'=>12, 'xp'=>5],
  'mid'   => ['name'=>'Cross-Sprawl Haul',        'cost'=>2, 'pay_min'=>60,  'pay_max'=>130, 'ambush'=>18, 'xp'=>9],
  'long'  => ['name'=>'Outer Stacks Run',         'cost'=>4, 'pay_min'=>150, 'pay_max'=>320, 'ambush'=>25, 'xp'=>16],
];

// Make sure skill rows exist (we read the `drone` skill for mining).
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

// XP grant + level-up (guarded so it coexists with the other pages' copies).
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

// Current skill points + names (for the mining gate).
$skillPts = $skillName = [];
$sp = $pdo->prepare('SELECT s.code, s.name, ps.points
                     FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?');
$sp->execute([$pid]);
foreach ($sp as $row) { $skillPts[$row['code']] = (int)$row['points']; $skillName[$row['code']] = $row['name']; }

/* ---------- action handling (inline, no redirect) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'run') {
      $key = $_POST['route'] ?? '';
      if (!isset($routes[$key])) throw new RuntimeException('No such route on the board.');
      $rt = $routes[$key];

      $pdo->beginTransaction();
      // Spend Integrity on the run (fuel, wear, risk). Blocks if too banged up.
      $u = $pdo->prepare('UPDATE players SET integrity = integrity - ? WHERE id = ? AND integrity >= ?');
      $u->execute([$rt['cost'], $pid, $rt['cost']]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("Too banged up for the {$rt['name']} — patch up first."); }

      if (random_int(1, 100) <= $rt['ambush']) {
        $extra = random_int(1, $rt['cost'] + 2);
        $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id = ?')->execute([$extra, $pid]);
        $pdo->commit();
        $msg = "Ambushed on the {$rt['name']}! Took {$extra} extra Health and lost the cargo.";
      } else {
        $pay = random_int($rt['pay_min'], $rt['pay_max']);
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$pay, $pid]);
        $pdo->commit();
        $lv = grant_xp($pid, $rt['xp']);
        $msg = "Ran the {$rt['name']} clean — hauled in " . number_format($pay) . " creds, +{$rt['xp']} XP."
             . ($lv ? " LEVEL UP (+{$lv})!" : '');
      }
    }

    elseif ($action === 'mine') {
      $g = $pdo->prepare("SELECT g.*, i.name AS item_name
                          FROM gather_nodes g JOIN items i ON i.id = g.item_id
                          WHERE g.code = ? AND g.venue = 'transit'");
      $g->execute([$_POST['node'] ?? '']);
      $node = $g->fetch();
      if (!$node) throw new RuntimeException('No such dig site.');
      if (($skillPts[$node['skill_code']] ?? 0) < $node['skill_req'])
        throw new RuntimeException("Locked — needs {$skillName[$node['skill_code']]} {$node['skill_req']}.");

      $yield = random_int((int)$node['yield_min'], (int)$node['yield_max']);
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
          ->execute([$pid, $node['item_id'], $yield]);
      $lv = grant_xp($pid, (int)$node['xp_reward']);
      $msg = "Mined {$yield} &times; {$node['item_name']}. +{$node['xp_reward']} XP"
           . ($lv ? " &mdash; LEVEL UP (+{$lv})!" : ".");
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
  $player = current_player(); // refresh integrity / creds / level
}

/* ---------- data for rendering ---------- */
$mines = $pdo->query("SELECT g.*, i.name AS item_name
                      FROM gather_nodes g JOIN items i ON i.id = g.item_id
                      WHERE g.venue = 'transit'
                      ORDER BY g.skill_req")->fetchAll();
$drone = (int)($skillPts['drone'] ?? 0);
?>
<div class="panel">
  <h2>Transit Hub <span class="muted">&mdash; The Loading Docks</span></h2>
  <p class="muted">Run cargo for creds, or send a rig into the tunnels for ore. Both bite back.</p>
  <?php if ($msg): ?><div class="flash flash-ok"><?= $msg /* may contain entities; only dynamic parts are admin item names */ ?></div><?php endif; ?>
  <p>
    Health: <b><?= (int)$player['integrity'] ?> / <?= (int)$player['integrity_max'] ?></b> &middot;
    Drone Piloting: <b><?= $drone ?></b>
    <span class="muted">(heal at the <a href="index.php?p=sim">Combat Sim</a>, train at the <a href="index.php?p=datacore&act=lab">Datacore</a>)</span>
  </p>
</div>

<div class="panel">
  <h3>Cargo Runs</h3>
  <p class="muted">Each run spends Health and pays creds &mdash; unless you get jumped on the way.</p>
  <table>
    <tr><th>Route</th><th>Costs</th><th>Pays</th><th>Ambush</th><th></th></tr>
    <?php foreach ($routes as $k => $rt):
      $canRun = (int)$player['integrity'] >= $rt['cost']; ?>
    <tr>
      <td><?= e($rt['name']) ?></td>
      <td class="muted"><?= (int)$rt['cost'] ?> Health</td>
      <td><?= number_format($rt['pay_min']) ?>&ndash;<?= number_format($rt['pay_max']) ?> creds</td>
      <td class="muted"><?= (int)$rt['ambush'] ?>%</td>
      <td>
        <?php if ($canRun): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="run">
            <input type="hidden" name="route" value="<?= e($k) ?>">
            <button type="submit">Run</button>
          </form>
        <?php else: ?>
          <span class="muted">Too low</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <h3>Tunnel Mining</h3>
  <p class="muted">Pilot a mining rig into the service tunnels. Gated by Drone Piloting. Ore sells well at the <a href="index.php?p=bazaar">Bazaar</a>.</p>
  <table>
    <tr><th>Dig Site</th><th>Yields</th><th>Requires</th><th></th></tr>
    <?php foreach ($mines as $m):
      $have = $skillPts[$m['skill_code']] ?? 0;
      $unlocked = $have >= (int)$m['skill_req']; ?>
    <tr>
      <td><?= e($m['name']) ?><br><span class="muted" style="font-size:11px"><?= e($m['descr']) ?></span></td>
      <td><?= (int)$m['yield_min'] ?>&ndash;<?= (int)$m['yield_max'] ?> <?= e($m['item_name']) ?></td>
      <td class="muted"><?= e($skillName[$m['skill_code']] ?? $m['skill_code']) ?> <?= (int)$m['skill_req'] ?></td>
      <td>
        <?php if ($unlocked): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="mine">
            <input type="hidden" name="node" value="<?= e($m['code']) ?>">
            <button type="submit">Mine</button>
          </form>
        <?php else: ?>
          <span class="muted">Locked (you have <?= (int)$have ?>)</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
