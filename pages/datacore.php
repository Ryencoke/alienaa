<?php /* pages/datacore.php — Skillsoft Lab: burn cycles to level skills */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Ensure skill rows exist
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

// Strip "101" suffix from skill names in DB (runs once harmlessly)
try { $pdo->exec("UPDATE skills SET name = REPLACE(name, ' 101', '') WHERE name LIKE '% 101'"); } catch (Throwable $e) {}

$SKILLS_META = [
  'scav'   => ['icon'=>'&#128270;', 'name'=>'Scavenging',     'effect'=>'+yield & unlock higher-tier gather nodes per level',      'color'=>'var(--accent)'],
  'hydro'  => ['icon'=>'&#127807;', 'name'=>'Hydroponics',    'effect'=>'+crop yield & unlock hydrofarm growth vats per level',    'color'=>'#3bcf63'],
  'fab'    => ['icon'=>'&#9881;',   'name'=>'Fabrication',    'effect'=>'+crafting output & unlock advanced recipes per level',    'color'=>'var(--neon2)'],
  'combat' => ['icon'=>'&#9876;',   'name'=>'Combat',         'effect'=>'+damage & crit chance in combat sims per level',          'color'=>'#ff6b35'],
  'drone'  => ['icon'=>'&#129458;', 'name'=>'Drone Ops',      'effect'=>'+mining yield & unlock remote transit nodes per level',   'color'=>'#4d6be8'],
  'netrun' => ['icon'=>'&#128187;', 'name'=>'Netrunning',     'effect'=>'+hack success rate & unlock higher-tier net intrusions',  'color'=>'#a66de8'],
  'chem'   => ['icon'=>'&#9879;',   'name'=>'Streetchem',     'effect'=>'+stim potency & unlock compound synthesis recipes',       'color'=>'#e8d44d'],
  'hack'   => ['icon'=>'&#128272;', 'name'=>'Cryptocracking', 'effect'=>'+crypto yield & unlock encrypted vault targets',          'color'=>'#4de8b8'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $invest = array_map(static fn($v) => max(0, (int)$v), is_array($_POST['pts'] ?? null) ? $_POST['pts'] : []);
  $total  = array_sum($invest);
  try {
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
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$rows = $pdo->prepare('SELECT s.id, s.code, s.name, s.max_pts, ps.points
                       FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?
                       ORDER BY s.name');
$rows->execute([$pid]);
$skills = $rows->fetchAll();
?>
<div class="panel">
  <h2>Datacore &mdash; Skillsoft Lab</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">Jack a skillsoft into your cortex. Costs Drive. The deeper you go, the more the Sprawl opens up.</p>
  <?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>
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
