<?php /* pages/mining.php — The Sump: ore mining for crafting materials */

const MINE_DRIVE_COST = 12;
const MINE_COOLDOWN   = 1800; // 30 min in seconds

$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';


try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_ore (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    player_id  INT NOT NULL,
    ore_type   VARCHAR(32) NOT NULL,
    quantity   INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_po (player_id, ore_type),
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// Get Mining skill level from drone skill (points 0–1000, divided to 0–9 level)
$miningLevel = 0;
try {
  $pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points) SELECT ?, id, 0 FROM skills')->execute([$pid]);
  $sq = $pdo->prepare("SELECT FLOOR(ps.points / 100) FROM player_skills ps JOIN skills s ON s.id = ps.skill_id WHERE ps.player_id = ? AND s.code = 'drone'");
  $sq->execute([$pid]);
  $miningLevel = (int)($sq->fetchColumn() ?: 0);
} catch (Throwable $e) {}

// Ore catalog: [id, name, tier, min_mining_level, drop_weight, icon, color, desc]
$ORES = [
  ['scrap',     'Junk Metal',        1, 0, 80, '&#129419;', '#8a8fa8', 'Twisted scrap from derelict Grid zones. Low-grade but always useful.'],
  ['copper',    'Copper Wire',       1, 1, 60, '&#127312;', '#e8a33d', 'Basic conductive material. Backbone of entry-level fabrication.'],
  ['iron',      'Iron Alloy',        2, 3, 45, '&#9760;',   '#b0b8cc', 'Dense alloy salvaged from collapsed structures.'],
  ['titanium',  'Titanium Core',     3, 5, 28, '&#128311;', '#19f0c7', 'High-grade structural metal. Lightweight and extremely durable.'],
  ['nanocarbon','Nano-Carbon Fiber', 4, 6, 16, '&#128302;', '#ff2d95', 'Lab-engineered composite fiber. Powers advanced armor synthesis.'],
  ['quantum',   'Quantum Crystal',   5, 7,  8, '&#128142;', '#a66de8', 'Crystalline data-matrix ore. Unlocks cutting-edge weapon crafting.'],
  ['void',      'Void Metal',        6, 9,  3, '&#11088;',  '#e8d44d', 'Unstable ore from deep Grid anomalies. Near-mythical scarcity.'],
];
$oreMap = [];
foreach ($ORES as $o) $oreMap[$o[0]] = $o;

// Cooldown check
$miningAt = 0;
try {
  $mq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $mq->execute(['mining_at:' . $pid]);
  $miningAt = (int)($mq->fetchColumn() ?: 0);
} catch (Throwable $e) {}
$cooldownLeft = max(0, $miningAt + MINE_COOLDOWN - time());

$lastHaul = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mine') {
  try {
    if ($cooldownLeft > 0) throw new RuntimeException('Drilling equipment still cooling down.');
    $fresh = current_player();
    if ((int)$fresh['cycles'] < MINE_DRIVE_COST)
      throw new RuntimeException('Not enough Drive. Need ' . MINE_DRIVE_COST . '.');

    // Build weighted pool of available ores
    $pool = [];
    foreach ($ORES as $ore) {
      if ($miningLevel >= $ore[3]) $pool[] = $ore;
    }
    if (empty($pool)) $pool = [$ORES[0]];

    // Weighted random selection
    $totalW = array_sum(array_column($pool, 4));
    $roll   = mt_rand(1, $totalW);
    $chosen = $pool[0];
    $cum    = 0;
    foreach ($pool as $ore) {
      $cum += $ore[4];
      if ($roll <= $cum) { $chosen = $ore; break; }
    }

    // Quantity: 1-3, bonus at higher skill tiers
    $qty = mt_rand(1, 2)
         + (int)($miningLevel >= 5)
         + (int)($miningLevel >= 10)
         + (int)($miningLevel >= 15);

    // Commit: deduct Drive, set cooldown, add ore, grant XP
    $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?')
        ->execute([MINE_DRIVE_COST, $pid, MINE_DRIVE_COST]);
    $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
        ->execute(['mining_at:' . $pid, time()]);
    $pdo->prepare('INSERT INTO player_ore (player_id, ore_type, quantity) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')
        ->execute([$pid, $chosen[0], $qty]);

    $player = current_player();
    $cooldownLeft = MINE_COOLDOWN;
    $lastHaul = ['ore' => $chosen, 'qty' => $qty];
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

// Load ore stockpile
$invQ = $pdo->prepare('SELECT ore_type, quantity FROM player_ore WHERE player_id = ? ORDER BY ore_type');
$invQ->execute([$pid]);
$oreInv = [];
foreach ($invQ as $r) $oreInv[$r['ore_type']] = (int)$r['quantity'];
$hasOre = !empty(array_filter($oreInv));
?>

<div class="panel">
  <h2>&#9935; The Sump &mdash; Ore Mining</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">"The Grid hides what the builders left behind. Drill deep enough and you'll find it."</p>

  <?php if ($msg): ?><div class="flash flash-err"><?= e($msg) ?></div><?php endif; ?>

  <?php if ($lastHaul): ?>
  <div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.3);border-radius:8px;padding:16px;text-align:center;margin-bottom:14px;animation:flashin .3s ease">
    <div style="font-size:36px;margin-bottom:6px"><?= $lastHaul['ore'][5] ?></div>
    <div style="font-family:'Orbitron',sans-serif;font-weight:700;color:var(--accent);font-size:16px">+<?= $lastHaul['qty'] ?>&times; <?= e($lastHaul['ore'][1]) ?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:5px"><?= e($lastHaul['ore'][7]) ?></div>
    <div style="font-size:11px;color:var(--neon2);margin-top:3px">Tier <?= $lastHaul['ore'][2] ?> ore secured.</div>
  </div>
  <?php endif; ?>

  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div style="display:flex;gap:18px;align-items:center">
      <div>
        <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.5px">Drive</div>
        <div style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:18px;color:#e8a33d"><?= number_format($player['cycles']) ?></div>
      </div>
      <div>
        <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.5px">Drone Lv</div>
        <div style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:18px;color:var(--accent)"><?= $miningLevel ?></div>
      </div>
      <div>
        <div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.5px">Cost</div>
        <div style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:18px;color:var(--text)"><?= MINE_DRIVE_COST ?> <span style="font-size:11px;color:var(--muted)">Drive</span></div>
      </div>
    </div>
    <div>
      <?php if ($cooldownLeft > 0): ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:10px 16px;text-align:center">
          <div style="font-size:11px;color:var(--muted);margin-bottom:3px">Equipment cooling down</div>
          <div id="mine-cd" style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:18px;color:var(--neon2)"></div>
        </div>
      <?php else: ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="mine">
          <button type="submit" class="btn btn-primary" <?= (int)$player['cycles'] < MINE_DRIVE_COST ? 'disabled style="opacity:.4"' : '' ?> style="font-size:14px;padding:10px 24px">
            &#9935; Drill (<?= MINE_DRIVE_COST ?> Drive)
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Ore Registry -->
<div class="panel">
  <h3 style="margin-top:0">&#128300; Ore Registry</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px">
    <?php foreach ($ORES as $ore):
      $unlocked = ($miningLevel >= $ore[3]);
      $inStock  = $oreInv[$ore[0]] ?? 0;
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $unlocked ? 'rgba(25,240,199,.2)' : 'var(--line)' ?>;border-radius:8px;padding:12px;position:relative;<?= !$unlocked ? 'opacity:.55;filter:grayscale(.5)' : '' ?>">
      <?php if ($inStock > 0): ?>
        <div style="position:absolute;top:8px;right:10px;background:var(--accent);color:#000;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700;font-family:'Orbitron',sans-serif">&times;<?= number_format($inStock) ?></div>
      <?php endif; ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="font-size:26px;flex:none"><?= $ore[5] ?></span>
        <div>
          <div style="font-weight:700;font-size:13px;color:<?= $unlocked ? e($ore[6]) : 'var(--muted)' ?>"><?= e($ore[1]) ?></div>
          <div style="font-size:10px;color:var(--muted);margin-top:1px">
            Tier <?= $ore[2] ?><?php if ($ore[3] > 0): ?> &middot; Mining Lv <?= $ore[3] ?>+<?php endif; ?>
          </div>
        </div>
      </div>
      <div style="font-size:11px;color:var(--muted);line-height:1.5"><?= e($ore[7]) ?></div>
      <?php if (!$unlocked): ?>
        <div style="margin-top:6px;font-size:10px;color:var(--neon2);display:flex;align-items:center;gap:4px">
          &#128274; Requires Mining Level <?= $ore[3] ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:11px;margin:10px 0 0">
    Level up Mining at the <a href="index.php?p=datacore">Skillsoft Lab</a>.
    Higher levels unlock rarer ores and larger hauls per drill.
  </p>
</div>

<?php if ($hasOre): ?>
<!-- Your Stockpile -->
<div class="panel">
  <h3 style="margin-top:0">&#128219; Your Stockpile</h3>
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <?php foreach ($ORES as $ore):
      $qty = $oreInv[$ore[0]] ?? 0;
      if ($qty <= 0) continue;
    ?>
    <div style="background:var(--panel2);border:1px solid rgba(25,240,199,.2);border-radius:8px;padding:10px 16px;display:flex;align-items:center;gap:10px;min-width:160px">
      <span style="font-size:24px"><?= $ore[5] ?></span>
      <div>
        <div style="font-weight:700;font-size:12px;color:<?= e($ore[6]) ?>"><?= e($ore[1]) ?></div>
        <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:var(--accent);">&times;<?= number_format($qty) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p style="margin:12px 0 0;font-size:13px">
    <a href="index.php?p=weaponcraft" style="color:var(--accent)">&#9874; Head to Fabrication Lab &rarr;</a>
    to turn your stockpile into weapons and armor.
  </p>
</div>
<?php endif; ?>

<p class="muted" style="text-align:center"><a href="index.php?p=city">&larr; Back to The Sprawl</a></p>

<?php if ($cooldownLeft > 0): ?>
<script>
(function(){
  var el=document.getElementById('mine-cd'); if(!el) return;
  var s=<?= (int)$cooldownLeft ?>;
  function fmt(n){ var m=Math.floor(n/60),ss=n%60; return m+':'+(ss<10?'0':'')+ss; }
  el.textContent=fmt(s);
  var iv=setInterval(function(){ s--; if(s<=0){ clearInterval(iv); window.location.reload(); return; } el.textContent=fmt(s); },1000);
})();
</script>
<?php endif; ?>
