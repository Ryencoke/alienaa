<?php /* pages/vats.php — The Hydrofarms: Grow Vats */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Schema
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_vats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    crop_code VARCHAR(30) NOT NULL,
    planted_at DATETIME NOT NULL,
    ready_at DATETIME NOT NULL,
    harvested TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_player (player_id, harvested)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Crop definitions
$CROPS = [
  'nutriblast' => ['name'=>'Nutriblast',  'icon'=>'&#127807;', 'mins'=>10,  'cost'=>15,  'yield_min'=>8,   'yield_max'=>20,  'desc'=>'Fast-grow algae. Low yield, low wait.'],
  'synth_kelp' => ['name'=>'Synth Kelp',  'icon'=>'&#127804;', 'mins'=>20,  'cost'=>30,  'yield_min'=>20,  'yield_max'=>45,  'desc'=>'Balanced growth cycle. Standard output.'],
  'hydro_fungi' => ['name'=>'Hydro Fungi','icon'=>'&#127812;', 'mins'=>45,  'cost'=>60,  'yield_min'=>50,  'yield_max'=>100, 'desc'=>'Dense mycelium vat. Solid Drive return.'],
  'bio_culture' => ['name'=>'Bio Culture','icon'=>'&#129514;', 'mins'=>90,  'cost'=>120, 'yield_min'=>110, 'yield_max'=>200, 'desc'=>'Full culture cycle. Best Drive per vat.'],
];

// Hydro skill level (determines max plots)
$hydroLevel = 1;
try {
  $hq = $pdo->prepare('SELECT ps.points, s.max_pts FROM player_skills ps JOIN skills s ON s.id=ps.skill_id WHERE ps.player_id=? AND s.code=?');
  $hq->execute([$pid, 'hydro']); $hr = $hq->fetch();
  if ($hr) $hydroLevel = max(1, min(10, (int)floor((int)$hr['points'] / ((int)$hr['max_pts'] / 10)) + 1));
} catch (Throwable $e) {}

$maxPlots = 2 + (int)floor($hydroLevel / 2); // 2–7 plots

// Load active plots
$plots = [];
try {
  $pq = $pdo->prepare('SELECT * FROM player_vats WHERE player_id=? AND harvested=0 ORDER BY planted_at ASC');
  $pq->execute([$pid]); $plots = $pq->fetchAll();
} catch (Throwable $e) {}

$usedPlots = count($plots);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'plant') {
      $cropCode = $_POST['crop'] ?? '';
      if (!isset($CROPS[$cropCode])) throw new RuntimeException('Unknown crop.');
      if ($usedPlots >= $maxPlots) throw new RuntimeException('All ' . $maxPlots . ' plots occupied. Harvest first.');
      $crop = $CROPS[$cropCode];
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$crop['cost'], $pid, $crop['cost']]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits. Need ' . number_format($crop['cost']) . ' cr.');
      $readyAt = date('Y-m-d H:i:s', time() + $crop['mins'] * 60);
      $pdo->prepare('INSERT INTO player_vats (player_id, crop_code, planted_at, ready_at) VALUES (?,?,NOW(),?)')->execute([$pid, $cropCode, $readyAt]);
      $player = current_player();
      $msg = $crop['name'] . ' planted. Ready in ' . $crop['mins'] . ' minutes.';
      // Refresh plots
      $pq->execute([$pid]); $plots = $pq->fetchAll(); $usedPlots = count($plots);

    } elseif ($act === 'harvest') {
      $vatId = (int)($_POST['vat_id'] ?? 0);
      $pdo->beginTransaction();
      $vq = $pdo->prepare('SELECT * FROM player_vats WHERE id=? AND player_id=? AND harvested=0 FOR UPDATE');
      $vq->execute([$vatId, $pid]); $vat = $vq->fetch();
      if (!$vat) throw new RuntimeException('Plot not found.');
      if (strtotime($vat['ready_at']) > time()) throw new RuntimeException('Not ready yet.');
      $crop = $CROPS[$vat['crop_code']] ?? null;
      if (!$crop) throw new RuntimeException('Crop data missing.');
      // Yield: base range + hydro skill bonus (10% per level)
      $yieldBase = mt_rand($crop['yield_min'], $crop['yield_max']);
      $yieldBonus = (int)round($yieldBase * ($hydroLevel - 1) * 0.10);
      $yield = $yieldBase + $yieldBonus;
      $pdo->prepare('UPDATE players SET cycles = LEAST(cycles_max, cycles + ?) WHERE id=?')->execute([$yield, $pid]);
      $pdo->prepare('UPDATE player_vats SET harvested=1 WHERE id=?')->execute([$vatId]);
      $pdo->commit();
      $player = current_player();
      $msg = 'Harvested ' . $crop['name'] . '. +'  . $yield . ' Drive' . ($yieldBonus > 0 ? ' (+'.$yieldBonus.' hydro bonus)' : '') . '.';
      $pq->execute([$pid]); $plots = $pq->fetchAll(); $usedPlots = count($plots);
    }
  } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); $msg = $ex->getMessage(); }
}

// Recent harvests (last 10)
$history = [];
try {
  $hq2 = $pdo->prepare('SELECT * FROM player_vats WHERE player_id=? AND harvested=1 ORDER BY ready_at DESC LIMIT 10');
  $hq2->execute([$pid]); $history = $hq2->fetchAll();
} catch (Throwable $e) {}
?>
<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#3bcf63,var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <div>
        <h2 style="margin:0 0 2px">&#127807; Grow Vats</h2>
        <p class="muted" style="margin:0;font-size:12px">Hydroponic cultivation. Plant crops, harvest Drive. Yield scales with your Hydroponics skill.</p>
      </div>
      <div style="font-size:12px;text-align:right">
        <div>Drive: <b style="color:#e8a33d"><?= number_format($player['cycles']) ?> / <?= number_format($player['cycles_max']) ?></b></div>
        <div style="color:var(--muted)">Plots: <?= $usedPlots ?> / <?= $maxPlots ?> &middot; Hydro Lv<?= $hydroLevel ?></div>
      </div>
    </div>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= str_contains($msg,'Not enough')||str_contains($msg,'occupied')||str_contains($msg,'Not ready')||str_contains($msg,'Unknown') ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- ACTIVE PLOTS -->
<div>
  <div class="panel" style="padding:14px 16px">
    <h3 style="margin:0 0 12px">&#128200; Active Plots (<?= $usedPlots ?> / <?= $maxPlots ?>)</h3>

    <?php if (empty($plots)): ?>
    <div style="text-align:center;padding:24px 0;color:var(--muted)">
      <div style="font-size:32px;margin-bottom:8px">&#127807;</div>
      <div>All plots empty. Plant something to generate Drive.</div>
    </div>
    <?php else: ?>
    <?php foreach ($plots as $vat):
      $crop = $CROPS[$vat['crop_code']] ?? ['name'=>'Unknown','icon'=>'?'];
      $readyTs = strtotime($vat['ready_at']);
      $secsLeft = max(0, $readyTs - time());
      $isReady = $secsLeft === 0;
      $totalSecs = max(1, $readyTs - strtotime($vat['planted_at']));
      $pct = min(100, (int)round((time() - strtotime($vat['planted_at'])) / $totalSecs * 100));
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $isReady ? 'rgba(59,207,99,.4)' : 'var(--line)' ?>;border-radius:7px;padding:12px;margin-bottom:8px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="font-size:22px;flex:none"><?= $crop['icon'] ?></span>
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px"><?= e($crop['name']) ?></div>
          <div style="font-size:11px;color:<?= $isReady ? '#3bcf63' : 'var(--muted)' ?>">
            <?php if ($isReady): ?>&#9989; Ready to harvest!
            <?php else: ?><span class="vat-timer" data-ready="<?= $readyTs ?>">--:--</span> remaining<?php endif; ?>
          </div>
        </div>
        <?php if ($isReady): ?>
        <form method="post" style="margin:0;flex:none">
          <input type="hidden" name="action" value="harvest">
          <input type="hidden" name="vat_id" value="<?= (int)$vat['id'] ?>">
          <button type="submit" style="padding:6px 14px;background:rgba(59,207,99,.1);border-color:rgba(59,207,99,.3);color:#3bcf63;font-size:12px">Harvest</button>
        </form>
        <?php endif; ?>
      </div>
      <div style="height:4px;background:rgba(0,0,0,.3);border-radius:2px;overflow:hidden">
        <div style="width:<?= $pct ?>%;height:100%;background:<?= $isReady ? '#3bcf63' : 'var(--accent)' ?>;border-radius:2px;transition:width .5s"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($usedPlots < $maxPlots): ?>
    <div style="margin-top:4px;font-size:11px;color:var(--accent)"><?= $maxPlots - $usedPlots ?> empty plot<?= ($maxPlots-$usedPlots)!==1?'s':'' ?> available</div>
    <?php endif; ?>
  </div>
</div>

<!-- PLANT NEW CROP -->
<div>
  <div class="panel" style="padding:14px 16px">
    <h3 style="margin:0 0 4px">&#127809; Plant Crop</h3>
    <p class="muted" style="font-size:12px;margin:0 0 12px">Yields Drive. Hydro skill Lv<?= $hydroLevel ?> adds <?= ($hydroLevel-1)*10 ?>% bonus yield.</p>

    <?php foreach ($CROPS as $key => $crop):
      $canPlant = $usedPlots < $maxPlots;
      $minYield = (int)round($crop['yield_min'] * (1 + ($hydroLevel-1)*0.10));
      $maxYield = (int)round($crop['yield_max'] * (1 + ($hydroLevel-1)*0.10));
    ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:11px 13px;margin-bottom:8px;display:flex;align-items:center;gap:12px">
      <span style="font-size:22px;flex:none"><?= $crop['icon'] ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:13px"><?= e($crop['name']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= e($crop['desc']) ?></div>
        <div style="font-size:11px;margin-top:4px">
          <span style="color:#e8a33d">+<?= $minYield ?>–<?= $maxYield ?> Drive</span>
          <span class="muted"> &middot; <?= $crop['mins'] ?> min &middot; <?= number_format($crop['cost']) ?> cr</span>
        </div>
      </div>
      <form method="post" style="margin:0;flex:none">
        <input type="hidden" name="action" value="plant">
        <input type="hidden" name="crop" value="<?= $key ?>">
        <button type="submit" <?= !$canPlant ? 'disabled style="opacity:.4"' : '' ?> style="font-size:11px;padding:5px 12px;background:rgba(59,207,99,.08);border-color:rgba(59,207,99,.25);color:#3bcf63">Plant</button>
      </form>
    </div>
    <?php endforeach; ?>

    <div style="font-size:11px;color:var(--muted);margin-top:6px">
      Pocket: <b style="color:var(--accent)"><?= number_format($player['creds_pocket']) ?> cr</b> &nbsp;&middot;&nbsp;
      Upgrade Hydro skill in the <a href="index.php?p=datacore&act=lab">Skillsoft Lab</a> for more plots and yield.
    </div>
  </div>

  <?php if (!empty($history)): ?>
  <div class="panel" style="padding:14px 16px;margin-top:0">
    <h3 style="margin:0 0 10px">&#128202; Recent Harvests</h3>
    <?php foreach ($history as $h):
      $hcrop = $CROPS[$h['crop_code']] ?? ['name'=>$h['crop_code'],'icon'=>'&#127807;'];
    ?>
    <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px">
      <span style="flex:none"><?= $hcrop['icon'] ?></span>
      <span style="flex:1"><?= e($hcrop['name']) ?></span>
      <span style="color:var(--muted);font-size:11px"><?= e(date('M j g:ia', strtotime($h['ready_at']))) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</div>

<script>
(function(){
  function fmtTime(secs){
    if(secs<=0) return 'Ready';
    var h=Math.floor(secs/3600),m=Math.floor((secs%3600)/60),s=secs%60;
    if(h>0) return h+'h '+m+'m';
    if(m>0) return m+'m '+s+'s';
    return s+'s';
  }
  function tickVats(){
    var now=Math.floor(Date.now()/1000);
    document.querySelectorAll('.vat-timer').forEach(function(el){
      var ready=parseInt(el.dataset.ready)||0;
      el.textContent=fmtTime(ready-now);
      if(ready<=now){ var card=el.closest('[style]'); if(card) card.style.borderColor='rgba(59,207,99,.4)'; }
    });
  }
  tickVats(); setInterval(tickVats,1000);
})();
</script>
