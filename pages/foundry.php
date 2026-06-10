<?php /* pages/foundry.php — Foundry Sector: Scrap Run minigame + Fabrication */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$runResult = null;

// Ensure skill rows exist
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

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

// Skills
$skillPts = $skillName = [];
$sp = $pdo->prepare('SELECT s.code, s.name, ps.points
                     FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?');
$sp->execute([$pid]);
foreach ($sp as $row) { $skillPts[$row['code']] = (int)$row['points']; $skillName[$row['code']] = $row['name']; }

// Loot pool: items available to find in scrap crates (based on gather nodes)
$lootPool = [];
try {
  $lq = $pdo->query("SELECT g.code AS node_code, g.skill_code, g.skill_req, g.yield_min, g.yield_max, g.xp_reward, g.name AS node_name,
                            i.id AS item_id, i.code AS item_code, i.name AS item_name
                     FROM gather_nodes g JOIN items i ON i.id = g.item_id
                     ORDER BY g.skill_req");
  $lootPool = $lq->fetchAll();
} catch (Throwable $e) {}

// Build available pool per player skill
$available = [];
foreach ($lootPool as $n) {
  if (($skillPts[$n['skill_code']] ?? 0) >= (int)$n['skill_req']) {
    $available[] = $n;
  }
}

// Cooldown check
define('FOUNDRY_CD', 20 * 60); // 20 minutes
$cdKey = "foundry_run_cd:{$pid}";
$cdLeft = 0;
try {
  $q = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $q->execute([$cdKey]);
  $cdVal = $q->fetchColumn();
  if ($cdVal !== false && $cdVal !== '') $cdLeft = max(0, (int)$cdVal - time());
} catch (Throwable $e) {}

/* ---------- action handling ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'scrap_run') {
      if ($cdLeft > 0) throw new RuntimeException('Still on cooldown. Try again in ' . ceil($cdLeft / 60) . ' minute' . ($cdLeft >= 120 ? 's' : '') . '.');
      if (empty($available)) throw new RuntimeException('No materials unlocked yet. Train Scavenging or Hydroponics at the Datacore.');

      $picks = array_filter(array_map('intval', (array)($_POST['crates'] ?? [])));
      $picks = array_slice(array_values(array_unique($picks)), 0, 3);
      if (count($picks) < 1) throw new RuntimeException('Select at least one crate.');

      $pool = array_values($available);
      $runResult = [];
      $totalXp = 0;
      $pdo->beginTransaction();

      foreach ($picks as $slot) {
        // Rarity roll
        $roll = random_int(1, 100);
        if ($roll <= 8)       { $rarity = 'jackpot';  $mult = 3; }
        elseif ($roll <= 22)  { $rarity = 'bonus';    $mult = 2; }
        else                  { $rarity = 'common';   $mult = 1; }

        // Pick item from pool
        $n = $pool[array_rand($pool)];
        $qty = random_int((int)$n['yield_min'], (int)$n['yield_max']) * $mult;
        $xp  = (int)$n['xp_reward'];

        $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
            ->execute([$pid, (int)$n['item_id'], $qty]);
        $totalXp += $xp;
        $runResult[] = ['name' => $n['item_name'], 'qty' => $qty, 'rarity' => $rarity, 'slot' => $slot, 'xp' => $xp];
      }
      $pdo->commit();

      $lv = grant_xp($pid, $totalXp);
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
          ->execute([$cdKey, (string)(time() + FOUNDRY_CD)]);
      $cdLeft = FOUNDRY_CD;
      $player = current_player();

      $items = implode(', ', array_map(fn($r) => $r['qty'] . 'x ' . $r['name'], $runResult));
      $msg = "Run complete: {$items}. +{$totalXp} XP." . ($lv ? ' LEVEL UP (+' . $lv . ')!' : '');
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
      $msg = "Fabricated {$rec['out_qty']} &times; {$rec['out_name']}. +{$rec['xp_reward']} XP" . ($lv ? " &mdash; LEVEL UP (+{$lv})!" : '.');
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

/* ---------- data for rendering ---------- */
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

$cdMins = $cdLeft > 0 ? ceil($cdLeft / 60) : 0;
$cdSecs = $cdLeft > 0 ? ($cdLeft % 60) : 0;

// Rarity display config
$rarityStyle = [
  'jackpot' => ['bg' => 'rgba(232,163,61,.12)', 'border' => 'rgba(232,163,61,.5)', 'color' => '#e8a33d', 'label' => 'JACKPOT'],
  'bonus'   => ['bg' => 'rgba(25,240,199,.1)',  'border' => 'rgba(25,240,199,.4)', 'color' => 'var(--accent)', 'label' => 'BONUS'],
  'common'  => ['bg' => 'var(--panel2)',         'border' => 'var(--line)',          'color' => 'var(--text)',   'label' => ''],
];
?>

<!-- Header -->
<div class="panel">
  <h2>&#9881; Foundry Sector <span style="color:var(--muted);font-size:13px;font-weight:400;font-family:inherit">&mdash; Fabrication Bay</span></h2>
  <p class="muted" style="text-align:center;margin-bottom:10px">Pull raw stock from the Sprawl's wreckage, then bolt it into something worth creds.</p>
  <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center">
    <span style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);border-radius:20px;padding:4px 14px;font-size:12px">
      &#128295; Scavenging: <b style="color:var(--accent)"><?= number_format($skillPts['scav'] ?? 0) ?></b>
    </span>
    <span style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);border-radius:20px;padding:4px 14px;font-size:12px">
      &#127807; Hydroponics: <b style="color:var(--accent)"><?= number_format($skillPts['hydro'] ?? 0) ?></b>
    </span>
    <span style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);border-radius:20px;padding:4px 14px;font-size:12px">
      &#9881; Fabrication: <b style="color:var(--accent)"><?= number_format($skillPts['fab'] ?? 0) ?></b>
    </span>
    <a href="index.php?p=datacore&act=lab" style="border:1px dashed var(--line);border-radius:20px;padding:4px 14px;font-size:12px;color:var(--muted)">&#127891; Train skills</a>
  </div>
</div>

<!-- Flash -->
<?php if ($msg && !$runResult): ?><div class="flash flash-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($msg && !$runResult && stripos($msg,'locked') !== false || (!$runResult && $msg && strpos($msg,'Missing') !== false)): ?>
  <div class="flash flash-err"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ===================== SCRAP RUN MINIGAME ===================== -->
<div class="panel">
  <h3 style="margin-top:0">&#128738; Scrap Run</h3>
  <p class="muted" style="font-size:13px;margin-bottom:14px">Eight crates pulled from the wreckage. You can open up to <b>3</b> of them. Some are stuffed — some are traps. Choose well.</p>

  <?php if ($runResult): ?>
  <!-- ---- Run results ---- -->
  <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(25,240,199,.05);border:1px solid rgba(25,240,199,.3);border-radius:7px;margin-bottom:14px">
    <span style="font-size:28px">&#128738;</span>
    <div>
      <div style="font-family:'Orbitron',sans-serif;font-size:12px;color:var(--accent);letter-spacing:.5px;margin-bottom:3px">RUN COMPLETE</div>
      <div style="font-size:13px"><?= $msg ?></div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:14px">
    <?php foreach ($runResult as $result):
      $rs = $rarityStyle[$result['rarity']] ?? $rarityStyle['common'];
    ?>
    <div style="background:<?= $rs['bg'] ?>;border:2px solid <?= $rs['border'] ?>;border-radius:8px;padding:14px;text-align:center;position:relative">
      <?php if ($rs['label']): ?>
        <div style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:<?= $rs['border'] ?>;color:<?= $rs['color'] ?>;border-radius:20px;padding:1px 10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;letter-spacing:.5px;white-space:nowrap"><?= $rs['label'] ?></div>
      <?php endif; ?>
      <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Crate #<?= str_pad($result['slot'], 2, '0', STR_PAD_LEFT) ?></div>
      <div style="font-size:28px;margin-bottom:6px">&#128230;</div>
      <div style="font-weight:bold;font-size:14px;color:<?= $rs['color'] ?>"><?= e($result['name']) ?></div>
      <div style="font-size:22px;font-family:'Orbitron',sans-serif;font-weight:700;color:<?= $rs['color'] ?>;margin:4px 0">&times;<?= $result['qty'] ?></div>
      <div style="font-size:11px;color:var(--muted)">+<?= $result['xp'] ?> XP</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($cdLeft > 0): ?>
  <!-- ---- On cooldown ---- -->
  <div style="text-align:center;padding:20px;border:1px solid var(--line);border-radius:7px;background:var(--panel2)">
    <div style="font-size:32px;margin-bottom:8px">&#128337;</div>
    <div style="font-family:'Orbitron',sans-serif;font-size:13px;color:var(--muted);letter-spacing:.5px;margin-bottom:6px">NEXT RUN IN</div>
    <div id="foundry-cd" style="font-family:'Orbitron',sans-serif;font-size:28px;color:var(--accent)"><?= $cdMins ?>:<?= str_pad($cdSecs, 2, '0', STR_PAD_LEFT) ?></div>
    <p class="muted" style="font-size:11px;margin-top:8px">The Sprawl needs time to re-fill the scrapyards.</p>
  </div>
  <script>
  (function(){
    var el=document.getElementById('foundry-cd'); if(!el) return;
    var left=<?= (int)$cdLeft ?>;
    var iv=setInterval(function(){
      left--; if(left<=0){ clearInterval(iv); el.textContent='Ready'; el.style.color='var(--accent)'; return; }
      var m=Math.floor(left/60), s=left%60;
      el.textContent=m+':'+(s<10?'0':'')+s;
    },1000);
  })();
  </script>

  <?php elseif (empty($available)): ?>
  <!-- ---- No skills ---- -->
  <div style="text-align:center;padding:20px;border:1px dashed var(--line);border-radius:7px">
    <p class="muted">No scavengeable materials unlocked yet. Train <a href="index.php?p=datacore&act=lab">Scavenging or Hydroponics</a> first.</p>
  </div>

  <?php else: ?>
  <!-- ---- Select crates ---- -->
  <p style="font-size:12px;color:var(--muted);margin-bottom:12px">Select 1&ndash;3 crates, then crack them open.</p>
  <form method="post" id="scrapform">
    <input type="hidden" name="action" value="scrap_run">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px" id="crate-grid">
      <?php for ($i = 1; $i <= 8; $i++): ?>
      <label class="crate-card" for="crate<?= $i ?>">
        <input type="checkbox" name="crates[]" value="<?= $i ?>" id="crate<?= $i ?>" class="crate-cb" style="display:none">
        <div class="crate-face" style="background:var(--panel2);border:2px solid var(--line);border-radius:8px;padding:14px 8px;text-align:center;cursor:pointer;transition:all .15s;user-select:none">
          <div style="font-size:11px;color:var(--muted);font-family:'Orbitron',sans-serif;margin-bottom:6px"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?></div>
          <div style="font-size:26px;margin-bottom:4px">&#128230;</div>
          <div style="font-size:10px;color:var(--muted)">???</div>
        </div>
      </label>
      <?php endfor; ?>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
      <button type="submit" id="run-btn" disabled style="opacity:.4">&#128738; Open Crates</button>
      <span class="muted" style="font-size:12px" id="pick-count">0 / 3 selected</span>
    </div>
  </form>
  <style>
    .crate-card input:checked + .crate-face {
      border-color: var(--accent);
      background: rgba(25,240,199,.08);
      box-shadow: 0 0 12px rgba(25,240,199,.2);
    }
    .crate-face:hover { border-color: rgba(25,240,199,.4); }
  </style>
  <script>
  (function(){
    var cbs=document.querySelectorAll('.crate-cb'),
        btn=document.getElementById('run-btn'),
        cnt=document.getElementById('pick-count');
    function upd(){
      var n=0; cbs.forEach(function(c){ if(c.checked) n++; });
      if(cnt) cnt.textContent=n+' / 3 selected';
      if(btn){ btn.disabled=n<1; btn.style.opacity=n<1?'.4':'1'; }
    }
    cbs.forEach(function(cb){
      cb.addEventListener('change',function(){
        var checked=document.querySelectorAll('.crate-cb:checked');
        if(checked.length>3){ this.checked=false; }
        upd();
      });
    });
    upd();
  })();
  </script>
  <?php endif; ?>
</div>

<!-- ===================== FABRICATION ===================== -->
<div class="panel">
  <h3 style="margin-top:0">&#9965; Fabrication Bay</h3>
  <p class="muted" style="font-size:13px;margin-bottom:14px">Combine raw materials into gear and components. Check your <a href="index.php?p=stash">stash</a> for current counts.</p>
  <?php if ($msg && !$runResult && strpos($msg, 'Fabricated') !== false): ?>
    <div class="flash flash-ok"><?= $msg ?></div>
  <?php elseif ($msg && !$runResult && strpos($msg, 'Not enough') !== false): ?>
    <div class="flash flash-err"><?= e($msg) ?></div>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px">
  <?php foreach ($recipes as $rc):
    $have    = $skillPts[$rc['skill_code']] ?? 0;
    $unlocked = $have >= (int)$rc['skill_req'];
    $ings    = $inputsByRecipe[$rc['id']] ?? [];
    $canAfford = true;
    foreach ($ings as $ing) { if (($invMap[(int)$ing['item_id']] ?? 0) < (int)$ing['qty']) $canAfford = false; }
    $craftable = $unlocked && $canAfford;
  ?>
  <div style="background:var(--panel2);border:1px solid <?= $craftable ? 'rgba(25,240,199,.25)' : 'var(--line)' ?>;border-radius:7px;padding:12px;opacity:<?= $unlocked ? '1' : '.55' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
      <div>
        <div style="font-weight:bold;font-size:13px;color:<?= $craftable ? 'var(--accent)' : 'var(--muted)' ?>"><?= e($rc['out_name']) ?><?= $rc['out_qty'] > 1 ? ' <span style="color:var(--neon2)">×'.(int)$rc['out_qty'].'</span>' : '' ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= e($rc['descr']) ?></div>
      </div>
      <?php if (!$unlocked): ?>
        <span style="background:rgba(93,102,128,.15);border:1px solid rgba(93,102,128,.3);color:var(--muted);border-radius:4px;padding:2px 8px;font-size:10px;flex:none;margin-left:8px"><?= e($skillName[$rc['skill_code']] ?? $rc['skill_code']) ?> <?= (int)$rc['skill_req'] ?></span>
      <?php endif; ?>
    </div>

    <div style="margin-bottom:10px">
      <?php foreach ($ings as $ing):
        $own   = $invMap[(int)$ing['item_id']] ?? 0;
        $short = $own < (int)$ing['qty'];
        $pct   = $ing['qty'] > 0 ? min(100, round($own / $ing['qty'] * 100)) : 100;
      ?>
      <div style="margin-bottom:5px">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px">
          <span style="color:var(--muted)"><?= e($ing['name']) ?></span>
          <span style="color:<?= $short ? 'var(--neon2)' : 'var(--accent)' ?>;font-weight:bold"><?= (int)$own ?> / <?= (int)$ing['qty'] ?></span>
        </div>
        <div style="height:4px;background:#080812;border-radius:3px;overflow:hidden">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $short ? 'var(--neon2)' : 'var(--accent)' ?>;border-radius:3px"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:11px;color:var(--muted)">+<?= (int)$rc['xp_reward'] ?> XP &middot; needs <?= e($skillName[$rc['skill_code']] ?? $rc['skill_code']) ?> <?= (int)$rc['skill_req'] ?></span>
      <?php if (!$unlocked): ?>
        <span style="color:var(--muted);font-size:12px">Locked</span>
      <?php elseif (!$canAfford): ?>
        <span style="color:var(--neon2);font-size:12px">Missing materials</span>
      <?php else: ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="craft">
          <input type="hidden" name="recipe" value="<?= e($rc['code']) ?>">
          <button type="submit" style="background:rgba(25,240,199,.1);border-color:rgba(25,240,199,.3);color:var(--accent)">&#9881; Craft</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
