<?php /* pages/training.php — Neural Training Center (cyberpunk gym) */
/*
  Uses player_stats table (created by pvp.php). Drive (cycles) is spent per session.
  Gains are tiny and level/stat gated to not break early game.
  Cooldown tracked in settings table: training_at:{pid} = Unix timestamp of last session.
*/
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Ensure player_stats exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_stats (
    pid INT PRIMARY KEY, str_pts INT NOT NULL DEFAULT 5,
    spd_pts INT NOT NULL DEFAULT 5, end_pts INT NOT NULL DEFAULT 5,
    unspent INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB");
} catch (Throwable $e) {}

// Load / init stats
$stats = null;
try {
  $q = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $q->execute([$pid]); $stats = $q->fetch();
  if (!$stats) {
    $pdo->prepare('INSERT IGNORE INTO player_stats (pid) VALUES (?)')->execute([$pid]);
    $q->execute([$pid]); $stats = $q->fetch();
  }
} catch (Throwable $e) { $stats = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0]; }

define('TRAIN_DRIVE_COST', 10);    // Drive (cycles) per session
define('TRAIN_COOLDOWN', 1800);    // 30 min between sessions

// Training regimens
$REGIMENS = [
  'str' => [
    'label'    => 'Strength',
    'sub'      => 'Resistance Augmentation',
    'desc'     => 'Biofeedback harness resistance drills. Increases ATK power.',
    'icon'     => '&#9889;',
    'color'    => 'var(--neon2)',
    'stat_key' => 'str_pts',
    'effect'   => 'ATK',
  ],
  'spd' => [
    'label'    => 'Speed',
    'sub'      => 'Neural Reflex Boost',
    'desc'     => 'Reflex stimulator pod. Increases strike order and dodge chance.',
    'icon'     => '&#128168;',
    'color'    => '#e8a33d',
    'stat_key' => 'spd_pts',
    'effect'   => 'SPD',
  ],
  'end' => [
    'label'    => 'Endurance',
    'sub'      => 'Stress Response Protocol',
    'desc'     => 'Neural fatigue suppression. Increases max HP and DEF.',
    'icon'     => '&#128158;',
    'color'    => '#3bcf63',
    'stat_key' => 'end_pts',
    'effect'   => 'HP/DEF',
  ],
];

// Cooldown check
$lastTrain = 0;
try {
  $cq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $cq->execute(["training_at:{$pid}"]); $v = $cq->fetchColumn(); $lastTrain = $v !== false ? (int)$v : 0;
} catch (Throwable $e) {}
$cooldownLeft  = max(0, ($lastTrain + TRAIN_COOLDOWN) - time());
$canTrain      = $cooldownLeft === 0;
$driveOk       = (int)$player['cycles'] >= TRAIN_DRIVE_COST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'train') {
  $reg = $_POST['regimen'] ?? '';
  if (!isset($REGIMENS[$reg])) { $msg = 'Select a regimen.'; }
  elseif (!$canTrain)          { $msg = 'Cooldown active — wait '.ceil($cooldownLeft/60).' min.'; }
  elseif (!$driveOk)           { $msg = 'Not enough Drive (costs '.TRAIN_DRIVE_COST.' Drive).'; }
  else {
    try {
      $r      = $REGIMENS[$reg];
      $sk     = $r['stat_key'];
      $curVal = (int)($stats[$sk] ?? 5);

      // Diminishing returns: chance decreases as stat grows (no hard cap)
      $gainChance = max(3, 60 - ($curVal * 2)); // 60% at 0, decreasing, 3% floor
      $gained = 0;
      if (mt_rand(1, 100) <= $gainChance) {
        $pdo->prepare("UPDATE player_stats SET {$sk} = {$sk} + 1 WHERE pid=?")->execute([$pid]);
        $gained = 1;
        $q->execute([$pid]); $stats = $q->fetch();
      }

      // Deduct Drive
      $pdo->prepare('UPDATE players SET cycles = GREATEST(0, cycles - ?) WHERE id=?')->execute([TRAIN_DRIVE_COST, $pid]);
      $player = current_player();

      // Store cooldown
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["training_at:{$pid}", (string)time()]);
      $lastTrain    = time();
      $cooldownLeft = TRAIN_COOLDOWN;
      $canTrain     = false;

      if ($gained) {
        $msg = "+1 {$r['label']}! Your {$r['effect']} has increased.";
      } else {
        $msg = "Session complete. No breakthrough this time, but the grind continues.";
      }
    } catch (Throwable $ex) { $msg = 'Training error: '.$ex->getMessage(); }
  }
}
$player = current_player();
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--neon2),#e8a33d,var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#128168; Neural Training Center</h2>
    <p class="muted" style="margin:0;font-size:12px">Augmentation pods and biofeedback harnesses. Spend Drive for a chance to increase your combat stats. Gains are small — consistency is the edge.</p>
  </div>
</div>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Status Bar -->
<div style="display:flex;gap:12px;flex-wrap:wrap">
  <div class="panel" style="flex:1;min-width:140px;text-align:center;padding:12px">
    <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:#e8a33d"><?= number_format($player['cycles']) ?></div>
    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Drive Available</div>
    <div style="font-size:10px;color:var(--muted);margin-top:2px">Costs <?= TRAIN_DRIVE_COST ?> per session</div>
  </div>
  <div class="panel" style="flex:1;min-width:140px;text-align:center;padding:12px">
    <?php if ($canTrain): ?>
      <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:#3bcf63">READY</div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Session Available</div>
    <?php else: ?>
      <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--neon2)" id="train-cd"><?= gmdate('i:s', $cooldownLeft) ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Cooldown</div>
    <?php endif; ?>
  </div>
  <div class="panel" style="flex:1;min-width:140px;text-align:center;padding:12px">
    <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:var(--accent)"><?= (int)$player['level'] ?></div>
    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Level</div>
    <div style="font-size:10px;color:var(--muted);margin-top:2px">No stat cap</div>
  </div>
</div>

<!-- Regimen Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
<?php foreach ($REGIMENS as $regKey => $reg):
  $sk = $reg['stat_key'];
  $curStat = (int)($stats[$sk] ?? 5);
  $gainChance = max(3, 60 - ($curStat * 2));
  $active = $canTrain && $driveOk;
?>
<div class="panel" style="border:1px solid rgba(255,255,255,.1);background:var(--panel)">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <div style="font-size:28px"><?= $reg['icon'] ?></div>
    <div>
      <div style="font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:<?= $reg['color'] ?>"><?= $reg['label'] ?></div>
      <div style="font-size:10px;color:var(--muted)"><?= $reg['sub'] ?></div>
    </div>
  </div>
  <p style="font-size:12px;color:var(--muted);margin:0 0 12px;line-height:1.5"><?= $reg['desc'] ?></p>

  <div style="background:rgba(0,0,0,.2);border-radius:6px;padding:8px;margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
      <span style="color:var(--muted)">Current</span>
      <span style="font-weight:700;color:<?= $reg['color'] ?>"><?= $curStat ?> pts</span>
    </div>
    <div style="height:4px;background:rgba(0,0,0,.4);border-radius:2px;overflow:hidden">
      <div style="width:<?= min(100, $curStat/max($curStat,50)*100) ?>%;height:100%;background:<?= $reg['color'] ?>;border-radius:2px"></div>
    </div>
    <div style="font-size:10px;color:var(--muted);margin-top:5px">Breakthrough chance: ~<?= $gainChance ?>%</div>
  </div>

  <form method="post">
    <input type="hidden" name="action" value="train">
    <input type="hidden" name="regimen" value="<?= $regKey ?>">
    <button type="submit" style="width:100%;padding:9px;font-size:12px;<?= $active ? '' : 'opacity:.5;cursor:not-allowed' ?>" <?= $active ? '' : 'disabled' ?>>
      <?= $reg['icon'] ?> Train <?= $reg['label'] ?> &mdash; -<?= TRAIN_DRIVE_COST ?> Drive
    </button>
  </form>
</div>
<?php endforeach; ?>
</div>

<!-- Current Combat Stats Summary -->
<div class="panel">
  <h3 style="margin-top:0">&#128202; Your Stats</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px">
    <?php foreach ($REGIMENS as $regKey => $reg):
      $sk = $reg['stat_key'];
      $curStat = (int)($stats[$sk] ?? 5);
    ?>
    <div style="text-align:center;padding:10px;background:var(--panel2);border:1px solid var(--line);border-radius:7px">
      <div style="font-size:20px;margin-bottom:4px"><?= $reg['icon'] ?></div>
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:<?= $reg['color'] ?>"><?= $curStat ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase"><?= $reg['label'] ?></div>
      <div style="font-size:10px;color:var(--muted)">no cap</div>
    </div>
    <?php endforeach; ?>
    <?php if ((int)($stats['unspent'] ?? 0) > 0): ?>
    <div style="text-align:center;padding:10px;background:rgba(232,163,61,.05);border:1px solid rgba(232,163,61,.3);border-radius:7px">
      <div style="font-size:20px;margin-bottom:4px">&#11088;</div>
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:#e8a33d"><?= (int)$stats['unspent'] ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase">Unspent</div>
      <a href="index.php?p=pvp&tab=stats" style="font-size:10px;color:#e8a33d">Spend &rarr;</a>
    </div>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:11px;margin:12px 0 0">Training gives small stat gains. <a href="index.php?p=pvp&tab=stats">Spend level-up points</a> at the Combat Arena for larger increases.</p>
</div>

<script>
(function(){
  var cd = document.getElementById('train-cd');
  if (!cd) return;
  var secs = <?= max(0, $cooldownLeft) ?>;
  function tick() {
    if (secs <= 0) { location.reload(); return; }
    secs--;
    var m = Math.floor(secs/60), s = secs%60;
    cd.textContent = (m<10?'0':'')+m+':'+(s<10?'0':'')+s;
    setTimeout(tick, 1000);
  }
  if (secs > 0) setTimeout(tick, 1000);
})();
</script>
