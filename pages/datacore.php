<?php /* pages/datacore.php — Skillsoft Lab: burn cycles to level skills */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Ensure skill rows exist
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

// Strip "101" suffix from skill names in DB (runs once harmlessly)
try { $pdo->exec("UPDATE skills SET name = REPLACE(name, ' 101', '') WHERE name LIKE '% 101'"); } catch (Throwable $e) {}

$SKILLS_META = skill_defs(); // shared with the Library

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $invest = array_map(static fn($v) => max(0, (int)$v), is_array($_POST['pts'] ?? null) ? $_POST['pts'] : []);
  try {
    // Clamp each requested amount to what the skill can actually still accept,
    // so a forged POST can never be charged Drive for points LEAST() below would
    // silently drop (skill ids not owned by this player clamp to 0/remain unset).
    if ($invest) {
      $ids = array_map('intval', array_keys($invest));
      $capQ = $pdo->prepare('SELECT s.id, GREATEST(0, s.max_pts - ps.points) AS remaining
                              FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?
                              WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
      $capQ->execute(array_merge([$pid], $ids));
      $remainingBySkill = [];
      foreach ($capQ->fetchAll() as $r) $remainingBySkill[(int)$r['id']] = (int)$r['remaining'];
      foreach ($invest as $sid => $pts) {
        $invest[$sid] = max(0, min($pts, $remainingBySkill[(int)$sid] ?? 0));
      }
    }
    $total = array_sum($invest);
    if ($total <= 0)                        throw new RuntimeException('Move a slider to allocate Drive.');
    if ($total * 5 > $player['cycles'])    throw new RuntimeException('Not enough Drive. Need '.number_format($total * 5).' Drive for '.number_format($total).' cycles.');
    $burn = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
    $burn->execute([$total * 5, $pid, $total * 5]);
    if ($burn->rowCount() !== 1) throw new RuntimeException('Not enough Drive.');
    foreach ($invest as $sid => $pts) {
      if ($pts <= 0) continue;
      $pdo->prepare('UPDATE player_skills ps JOIN skills s ON s.id = ps.skill_id
                     SET ps.points = LEAST(s.max_pts, ps.points + ?)
                     WHERE ps.player_id = ? AND ps.skill_id = ?')
          ->execute([$pts, $pid, (int)$sid]);
    }
    $msg = "Burned " . number_format($total * 5) . " Drive for " . number_format($total) . " cycles. Skillsofts updated.";
    $player = current_player();
  } catch (Throwable $ex) { $msg = $ex->getMessage(); $msgErr = true; }
}

$rows = $pdo->prepare('SELECT s.id, s.code, s.name, s.max_pts, ps.points
                       FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?
                       ORDER BY s.name');
$rows->execute([$pid]);
$skills = $rows->fetchAll();
?>
<?= scene_header('dc-canvas', '&#129504;', 'Datacore &mdash; Skillsoft Lab',
      'Jack a skillsoft into your cortex. Costs Drive. The deeper you go, the more the Sprawl opens up.', 'pulse', '#a66de8') ?>
<?= scene_header_js() ?>
<div class="panel">
  <?php if ($msg): ?><div class="flash <?= !empty($msgErr) ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div><?php endif; ?>
  <div style="text-align:center;margin:10px 0">
    <span class="muted" style="font-size:12px">Available Drive:&nbsp;</span>
    <span style="font-family:'Orbitron',sans-serif;font-weight:bold;color:var(--accent);font-size:1.3rem"><?= number_format($player['cycles']) ?></span>
    <span class="muted" style="font-size:11px"> / <?= number_format($player['cycles_max']) ?></span>
  </div>
</div>

<form method="post">
<div class="skill-grid">
<?php foreach ($skills as $sk):
  $meta   = $SKILLS_META[$sk['code']] ?? ['icon'=>'&#127288;','name'=>$sk['name'],'effect'=>'','color'=>'var(--accent)'];
  $pts    = (int)$sk['points'];
  $max    = (int)$sk['max_pts'];
  $pct    = $max > 0 ? min(100, round($pts / $max * 100)) : 0;
  $level  = $max > 0 ? min(10, (int)floor($pts / ($max / 10)) + 1) : 1;
  $mastered = $pts >= $max;
?>
<div class="skill-card<?= $mastered ? ' mastered' : '' ?>">
  <div class="sk-head">
    <span class="sk-ic"><?= $meta['icon'] ?></span>
    <div style="flex:1;min-width:0">
      <div class="sk-name"><?= e($meta['name']) ?></div>
    </div>
    <?php if ($mastered): ?><span class="skill-mastered-badge">MASTERED</span><?php endif; ?>
  </div>

  <div class="sk-lvl">
    <span>Level <b id="sk-lv-<?= $sk['id'] ?>" style="color:<?= $meta['color'] ?>"><?= $level ?></b> / 10</span>
    <span class="muted"><?= number_format($pts) ?> / <?= number_format($max) ?> pts</span>
  </div>
  <div class="sk-track">
    <div class="sk-fill" id="sk-fill-<?= $sk['id'] ?>" style="width:<?= $pct ?>%"></div>
    <div class="sk-preview" id="sk-prev-<?= $sk['id'] ?>" style="width:<?= $pct ?>%"></div>
  </div>
  <div class="sk-effect"><?= e($meta['effect']) ?></div>

  <?php if (!$mastered): ?>
  <?php $remaining = $max - $pts; ?>
  <div class="sk-input-row" style="flex-direction:column;align-items:stretch;gap:4px">
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted)">
      <span>Burn Drive</span>
      <span id="sk-cost-<?= $sk['id'] ?>" style="color:var(--accent);font-weight:700">0 cycles</span>
    </div>
    <input type="range" class="sk-qty" name="pts[<?= (int)$sk['id'] ?>]"
           min="0" max="<?= $remaining ?>" value="0" step="1"
           data-id="<?= $sk['id'] ?>" data-pts="<?= $pts ?>" data-max="<?= $max ?>"
           style="width:100%;accent-color:var(--accent)">
    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--muted)">
      <span>0</span><span><?= number_format($remaining) ?> max</span>
    </div>
  </div>
  <?php else: ?>
  <p style="font-size:11px;color:#ffe066;text-align:center;margin:8px 0 0">Maximum knowledge loaded &#127891;</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div style="text-align:center;margin:14px 0">
  <div style="font-size:13px;margin-bottom:8px;color:var(--muted)">
    Total to burn: <b id="sk-total" style="color:var(--accent)">0</b> Drive
  </div>
  <button type="submit" id="sk-submit" disabled style="opacity:.4">Burn Drive</button>
</div>
</form>

<script>
(function(){
  var totalEl=document.getElementById('sk-total'), btn=document.getElementById('sk-submit');
  var playerDrive=<?= (int)$player['cycles'] ?>;
  function upd(){
    var t=0;
    document.querySelectorAll('.sk-qty').forEach(function(inp){
      var v=Math.max(0,parseInt(inp.value)||0);
      var id=inp.getAttribute('data-id');
      var pts=parseInt(inp.getAttribute('data-pts'));
      var max=parseInt(inp.getAttribute('data-max'));
      var pct=max>0?Math.min(100,Math.round((pts+v)/max*100)):0;
      var prev=document.getElementById('sk-prev-'+id);
      if(prev) prev.style.width=pct+'%';
      var cost=document.getElementById('sk-cost-'+id);
      if(cost) cost.textContent=v>0?'+'+v+' pts — '+(v*5)+' Drive':'0 pts';
      var lv=document.getElementById('sk-lv-'+id);
      if(lv && max>0){ var newLv=Math.min(10,Math.floor((pts+v)/(max/10))+1); lv.textContent=newLv; }
      t+=v;
    });
    var driveCost=t*5;
    totalEl.textContent=driveCost;
    var over=driveCost>playerDrive;
    btn.disabled=(t<1||over); btn.style.opacity=(t<1||over)?'0.4':'1';
    totalEl.style.color=over?'var(--neon2)':'var(--accent)';
  }
  document.querySelectorAll('.sk-qty').forEach(function(inp){
    inp.addEventListener('input',upd);
  });
  upd();
})();
</script>
