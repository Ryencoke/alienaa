<?php /* pages/foundry.php — Foundry Sector: gather + craft, gated by Datacore skills */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Make sure this player has a row for every skill (mirrors datacore.php).
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

// XP grant + level-up loop. Returns number of levels gained.
if (!function_exists('grant_xp')) {
  function grant_xp($pid, $amount) {
    $pdo = db();
    $r = $pdo->prepare('SELECT level, xp, xp_next FROM players WHERE id = ?');
    $r->execute([$pid]); $p = $r->fetch();
    $level = (int)$p['level']; $xp = (int)$p['xp'] + $amount; $next = (int)$p['xp_next'];
    $gained = 0;
    while ($xp >= $next && $level < 999) {
      $xp -= $next; $level++; $next = (int)round($next * 1.5); $gained++;
    }
    $pdo->prepare('UPDATE players SET level = ?, xp = ?, xp_next = ? WHERE id = ?')
        ->execute([$level, $xp, $next, $pid]);
    return $gained;
  }
}

// Current skill points + friendly names for gating.
$skillPts = $skillName = [];
$sp = $pdo->prepare('SELECT s.code, s.name, ps.points
                     FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?');
$sp->execute([$pid]);
foreach ($sp as $row) { $skillPts[$row['code']] = (int)$row['points']; $skillName[$row['code']] = $row['name']; }

/* ---------- action handling (inline, no redirect — matches ledger/datacore) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'gather') {
      $g = $pdo->prepare('SELECT g.*, i.name AS item_name
                          FROM gather_nodes g JOIN items i ON i.id = g.item_id WHERE g.code = ?');
      $g->execute([$_POST['node'] ?? '']);
      $node = $g->fetch();
      if (!$node) throw new RuntimeException('No such node.');
      if (($skillPts[$node['skill_code']] ?? 0) < $node['skill_req'])
        throw new RuntimeException("Locked — needs {$skillName[$node['skill_code']]} {$node['skill_req']}.");

      $yield = random_int((int)$node['yield_min'], (int)$node['yield_max']);
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
          ->execute([$pid, $node['item_id'], $yield]);
      $lv = grant_xp($pid, (int)$node['xp_reward']);
      $msg = "Scavenged {$yield} &times; {$node['item_name']}. +{$node['xp_reward']} XP"
           . ($lv ? " &mdash; LEVEL UP (+{$lv})!" : ".") ;
    }

    elseif ($action === 'craft') {
      $r = $pdo->prepare('SELECT r.*, i.name AS out_name
                          FROM recipes r JOIN items i ON i.id = r.out_item_id WHERE r.code = ?');
      $r->execute([$_POST['recipe'] ?? '']);
      $rec = $r->fetch();
      if (!$rec) throw new RuntimeException('No such recipe.');
      if (($skillPts[$rec['skill_code']] ?? 0) < $rec['skill_req'])
        throw new RuntimeException("Locked — needs {$skillName[$rec['skill_code']]} {$rec['skill_req']}.");

      $in = $pdo->prepare('SELECT ri.item_id, ri.qty, it.name
                           FROM recipe_inputs ri JOIN items it ON it.id = ri.item_id WHERE ri.recipe_id = ?');
      $in->execute([$rec['id']]);
      $inputs = $in->fetchAll();

      $pdo->beginTransaction();
      foreach ($inputs as $ing) {
        $u = $pdo->prepare('UPDATE player_items SET qty = qty - ?
                            WHERE player_id = ? AND item_id = ? AND qty >= ?');
        $u->execute([$ing['qty'], $pid, $ing['item_id'], $ing['qty']]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("Not enough {$ing['name']} (need {$ing['qty']})."); }
      }
      $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND qty = 0')->execute([$pid]);
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
          ->execute([$pid, $rec['out_item_id'], $rec['out_qty']]);
      $pdo->commit();

      $lv = grant_xp($pid, (int)$rec['xp_reward']);
      $msg = "Fabricated {$rec['out_qty']} &times; {$rec['out_name']}. +{$rec['xp_reward']} XP"
           . ($lv ? " &mdash; LEVEL UP (+{$lv})!" : ".");
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
  $player = current_player(); // refresh level/xp for this render
}

/* ---------- data for rendering ---------- */
$nodes = $pdo->query("SELECT g.*, i.name AS item_name
                      FROM gather_nodes g JOIN items i ON i.id = g.item_id
                      WHERE g.venue = 'foundry'
                      ORDER BY g.skill_code, g.skill_req")->fetchAll();

$recipes = $pdo->query('SELECT r.*, i.name AS out_name
                        FROM recipes r JOIN items i ON i.id = r.out_item_id
                        ORDER BY r.skill_req')->fetchAll();

$inputsByRecipe = [];
foreach ($pdo->query('SELECT ri.recipe_id, ri.qty, it.name, it.id AS item_id
                      FROM recipe_inputs ri JOIN items it ON it.id = ri.item_id') as $row) {
  $inputsByRecipe[$row['recipe_id']][] = $row;
}

$invMap = [];
$im = $pdo->prepare('SELECT item_id, qty FROM player_items WHERE player_id = ?');
$im->execute([$pid]);
foreach ($im as $row) $invMap[(int)$row['item_id']] = (int)$row['qty'];
?>
<div class="panel">
  <h2>Foundry Sector &mdash; Fabrication Bay</h2>
  <p class="muted">Pull raw stock out of the Sprawl, then bolt it into something worth creds.</p>
  <?php if ($msg): ?><div class="flash"><?= $msg /* pre-built, already escaped/entity-safe */ ?></div><?php endif; ?>
  <p class="muted">Your skills &mdash;
    Scavenging: <b><?= (int)($skillPts['scav'] ?? 0) ?></b> &middot;
    Hydroponics: <b><?= (int)($skillPts['hydro'] ?? 0) ?></b> &middot;
    Fabrication: <b><?= (int)($skillPts['fab'] ?? 0) ?></b>
    &nbsp;(<a href="index.php?p=datacore&act=lab">train at the Datacore</a>)
  </p>
</div>

<div class="panel">
  <h3>Scavenge</h3>
  <table>
    <tr><th>Node</th><th>Yields</th><th>Requires</th><th></th></tr>
    <?php foreach ($nodes as $n):
      $have = $skillPts[$n['skill_code']] ?? 0;
      $unlocked = $have >= (int)$n['skill_req']; ?>
    <tr>
      <td><?= e($n['name']) ?><br><span class="muted" style="font-size:11px"><?= e($n['descr']) ?></span></td>
      <td><?= (int)$n['yield_min'] ?>&ndash;<?= (int)$n['yield_max'] ?> <?= e($n['item_name']) ?></td>
      <td class="muted"><?= e($skillName[$n['skill_code']] ?? $n['skill_code']) ?> <?= (int)$n['skill_req'] ?></td>
      <td>
        <?php if ($unlocked): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="gather">
            <input type="hidden" name="node" value="<?= e($n['code']) ?>">
            <button type="submit">Gather</button>
          </form>
        <?php else: ?>
          <span class="muted">Locked (you have <?= (int)$have ?>)</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <h3>Fabricate</h3>
  <table>
    <tr><th>Recipe</th><th>Needs</th><th>Requires</th><th></th></tr>
    <?php foreach ($recipes as $rc):
      $have = $skillPts[$rc['skill_code']] ?? 0;
      $unlocked = $have >= (int)$rc['skill_req'];
      $ings = $inputsByRecipe[$rc['id']] ?? [];
      $canAfford = true;
      foreach ($ings as $ing) { if (($invMap[(int)$ing['item_id']] ?? 0) < (int)$ing['qty']) $canAfford = false; }
    ?>
    <tr>
      <td><?= e($rc['out_name']) ?><?= $rc['out_qty'] > 1 ? ' &times;'.(int)$rc['out_qty'] : '' ?>
          <br><span class="muted" style="font-size:11px"><?= e($rc['descr']) ?></span></td>
      <td>
        <?php foreach ($ings as $ing):
          $own = $invMap[(int)$ing['item_id']] ?? 0;
          $short = $own < (int)$ing['qty']; ?>
          <span style="<?= $short ? 'color:var(--neon2)' : '' ?>">
            <?= (int)$ing['qty'] ?> <?= e($ing['name']) ?> <span class="muted">(have <?= (int)$own ?>)</span>
          </span><br>
        <?php endforeach; ?>
      </td>
      <td class="muted"><?= e($skillName[$rc['skill_code']] ?? $rc['skill_code']) ?> <?= (int)$rc['skill_req'] ?></td>
      <td>
        <?php if (!$unlocked): ?>
          <span class="muted">Locked (you have <?= (int)$have ?>)</span>
        <?php elseif (!$canAfford): ?>
          <span class="muted">Missing materials</span>
        <?php else: ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="craft">
            <input type="hidden" name="recipe" value="<?= e($rc['code']) ?>">
            <button type="submit">Craft</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
