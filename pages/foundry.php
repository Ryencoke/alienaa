<?php /* pages/foundry.php — Foundry Sector: Scrap Run minigame + Fabrication */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;
$runResult = null;
$craftedFx = null; // ceremony payload after a successful craft

// Ensure skill rows exist
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);


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
      $foundryBonus = 0;
      try { $fbq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $fbq->execute(["apt_foundry_bonus:{$pid}"]); $foundryBonus=(int)$fbq->fetchColumn(); } catch(Throwable $e){}
      $pdo->beginTransaction();

      // Claim the cooldown FIRST, atomically — the page-top $cdLeft read is
      // stale, so without this two parallel scrap_run POSTs both pass the
      // check and each grant a full run inside the same 20-minute window.
      $cdTok = (time() + FOUNDRY_CD) . '.' . mt_rand(100000, 999999);
      $cdClaim = $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE v = IF(CAST(v AS UNSIGNED) <= ' . time() . ', VALUES(v), v)');
      $cdClaim->execute([$cdKey, $cdTok]);
      $cdChk = $pdo->prepare('SELECT v FROM settings WHERE k=?');
      $cdChk->execute([$cdKey]);
      if ($cdChk->fetchColumn() !== $cdTok) { $pdo->rollBack(); throw new RuntimeException('Still on cooldown. Try again shortly.'); }

      foreach ($picks as $slot) {
        // Rarity roll
        $roll = random_int(1, 100);
        if ($roll <= 8)       { $rarity = 'jackpot';  $mult = 3; }
        elseif ($roll <= 22)  { $rarity = 'bonus';    $mult = 2; }
        else                  { $rarity = 'common';   $mult = 1; }

        // Pick item from pool
        $n = $pool[array_rand($pool)];
        $qty = (int)ceil(random_int((int)$n['yield_min'], (int)$n['yield_max']) * $mult * (1 + $foundryBonus / 100));
        $xp  = (int)$n['xp_reward'];

        $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
            ->execute([$pid, (int)$n['item_id'], $qty]);
        $totalXp += $xp;
        $runResult[] = ['name' => $n['item_name'], 'qty' => $qty, 'rarity' => $rarity, 'slot' => $slot, 'xp' => $xp];
      }
      // (Cooldown was already claimed atomically above, in the same transaction.)
      $pdo->commit();
      $cdLeft = FOUNDRY_CD;
      $player = current_player();

      $items = implode(', ', array_map(fn($r) => $r['qty'] . 'x ' . $r['name'], $runResult));
      $msg = "Run complete: {$items}.";
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

      $msg = "Fabricated {$rec['out_qty']} &times; {$rec['out_name']}.";
      $craftedFx = ['qty'=>(int)$rec['out_qty'],'item'=>$rec['out_name']];
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
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

<style>
#fd-canvas{display:block;width:100%;height:112px;border-radius:9px 9px 0 0}
#fd-head h2{text-shadow:0 0 14px rgba(232,163,61,.4)}
.crate-face:active{transform:scale(.95)}
@keyframes fdCrateIn{0%{opacity:0;transform:translateY(12px) scale(.8)}60%{transform:translateY(-2px) scale(1.04)}100%{opacity:1;transform:none}}
.fd-result{animation:fdCrateIn .4s cubic-bezier(.2,1.4,.4,1) backwards}
.fd-recipe{transition:transform .12s,box-shadow .15s}
.fd-recipe:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.3)}
</style>

<!-- Header -->
<div class="panel" id="fd-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="fd-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#9881; Foundry Sector</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Pull raw stock from the Sprawl's wreckage, then bolt it into something worth creds.</p>
    </div>
    <button id="fd-mute" onclick="toggleFdSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;padding:10px 14px">
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
<?php if ($msg && !$runResult): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= $msgErr ? e($msg) : $msg ?></div>
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
    <?php $fdRi = 0; foreach ($runResult as $result):
      $rs = $rarityStyle[$result['rarity']] ?? $rarityStyle['common'];
    ?>
    <div class="fd-result" style="animation-delay:<?= ($fdRi++) * 180 ?>ms;background:<?= $rs['bg'] ?>;border:2px solid <?= $rs['border'] ?>;border-radius:8px;padding:14px;text-align:center;position:relative">
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
      if(!document.body.contains(el)){ clearInterval(iv); return; }
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
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px">
  <?php foreach ($recipes as $rc):
    $have    = $skillPts[$rc['skill_code']] ?? 0;
    $unlocked = $have >= (int)$rc['skill_req'];
    $ings    = $inputsByRecipe[$rc['id']] ?? [];
    $canAfford = true;
    foreach ($ings as $ing) { if (($invMap[(int)$ing['item_id']] ?? 0) < (int)$ing['qty']) $canAfford = false; }
    $craftable = $unlocked && $canAfford;
  ?>
  <div class="fd-recipe" style="background:var(--panel2);border:1px solid <?= $craftable ? 'rgba(25,240,199,.25)' : 'var(--line)' ?>;border-radius:7px;padding:12px;opacity:<?= $unlocked ? '1' : '.55' ?>">
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
        <form method="post" style="margin:0" data-fdfx="craft" data-fd-item="<?= e($rc['out_name']) ?>" data-fd-qty="<?= (int)$rc['out_qty'] ?>">
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

<?php $fdHasJackpot = $runResult ? in_array('jackpot', array_column($runResult, 'rarity'), true) : false; ?>
<script>window._fdCrates = <?= json_encode($runResult ? ['n'=>count($runResult), 'jackpot'=>$fdHasJackpot] : null) ?>;</script>

<script>
/* Foundry FX kit — bound once; overlays on document.body survive AJAX swaps. */
(function(){
  if(window._fdFxBound) return;
  window._fdFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#fdfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(6,5,3,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#fdfx.show{opacity:1}'
    +'.fdfx-stage{position:relative;width:160px;height:120px;text-align:center}'
    +'.fdfx-gear{font-size:46px;display:inline-block;animation:fdfxSpin 1.1s linear infinite;'
    +'filter:drop-shadow(0 0 14px rgba(25,240,199,.5))}'
    +'@keyframes fdfxSpin{to{transform:rotate(360deg)}}'
    +'.fdfx-label{position:absolute;left:50%;top:100%;transform:translateX(-50%);white-space:nowrap;'
    +'font-size:13px;font-weight:900;letter-spacing:.1em;color:var(--accent);text-shadow:0 0 12px rgba(25,240,199,.6);'
    +'opacity:0;animation:fdfxLbl .3s .75s forwards}'
    +'@keyframes fdfxLbl{to{opacity:1}}'
    +'.fdfx-sub{display:block;font-size:10px;font-weight:600;color:var(--text);opacity:.75;margin-top:3px}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('foundryMuted')==='1';
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
  window.toggleFdSound=function(){
    muted=!muted; localStorage.setItem('foundryMuted',muted?'1':'0');
    var b=document.getElementById('fd-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };
  window.fdFX={
    tone:tone,
    crate:function(){ tone(150,.09,'square',.05); },
    chime:function(){ tone(523,.09,'sine',.05); setTimeout(function(){tone(784,.14,'sine',.05);},80); },
    fanfare:function(){ [523,659,784,1047].forEach(function(f,i){ setTimeout(function(){tone(f,.15,'sine',.055);},i*110); }); },
    weld:function(){ tone(180,.25,'sawtooth',.04,120); setTimeout(function(){tone(1800,.04,'sine',.025);},80); }
  };

  window.fdCraftOverlay=function(qty,item){
    var old=document.getElementById('fdfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='fdfx';
    o.innerHTML='<div class="fdfx-stage"><span class="fdfx-gear">&#9881;</span>'
      +'<div class="fdfx-label">FABRICATED<span class="fdfx-sub">'+qty+'× '+item+'</span></div></div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    window.fdFX.weld();
    setTimeout(window.fdFX.weld,350);
    var stage=o.querySelector('.fdfx-stage');
    var sparkIv=setInterval(function(){
      for(var i=0;i<4;i++){
        var s=document.createElement('div');
        s.style.cssText='position:absolute;left:50%;top:40px;width:3px;height:3px;border-radius:50%;background:'+(Math.random()<.4?'#fff3d0':'#ffb347')+';box-shadow:0 0 6px #ff7a18';
        stage.appendChild(s);
        var a=Math.random()*Math.PI*2, sp=24+Math.random()*40;
        s.animate([{transform:'translate(0,0)',opacity:1},{transform:'translate('+(Math.cos(a)*sp)+'px,'+(Math.sin(a)*sp+18)+'px)',opacity:0}],
          {duration:380+Math.random()*220,easing:'cubic-bezier(.1,.6,.4,1)'});
        setTimeout(function(el){return function(){el.remove();};}(s),650);
      }
    },140);
    setTimeout(function(){ clearInterval(sparkIv); window.fdFX.chime(); },850);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},1900);
  };

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute||f.getAttribute('data-fdfx')!=='craft') return;
    window.fdCraftOverlay(f.getAttribute('data-fd-qty')||'1', f.getAttribute('data-fd-item')||'');
  },true);
})();
</script>

<script>
(function(){
'use strict';
var mb=document.getElementById('fd-mute');
if(mb) mb.innerHTML=localStorage.getItem('foundryMuted')==='1'?'&#128263;':'&#128266;';

/* Factory floor header */
var fc=document.getElementById('fd-canvas');
if(fc){
  var c=fc.getContext('2d');
  var FW=560, FH=112;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  fc.width=FW*dpr; fc.height=FH*dpr;
  c.scale(dpr,dpr);
  var crates=[]; for(var i=0;i<5;i++) crates.push({x:i*140+Math.random()*60});
  var sparks=[], nextWeld=900, steam=[];

  function fLoop(t){
    if(!document.body.contains(fc)) return;
    requestAnimationFrame(fLoop);
    c.clearRect(0,0,FW,FH);
    var bg=c.createLinearGradient(0,0,0,FH);
    bg.addColorStop(0,'#0c0a0e'); bg.addColorStop(1,'#100d12');
    c.fillStyle=bg; c.fillRect(0,0,FW,FH);

    // back wall pipes
    c.strokeStyle='rgba(255,255,255,.06)';
    [18,30].forEach(function(py){ c.beginPath(); c.moveTo(0,py); c.lineTo(FW,py); c.stroke(); });
    for(var v=70;v<FW;v+=120){ c.beginPath(); c.moveTo(v,18); c.lineTo(v,30); c.stroke(); }

    // steam vents
    if(Math.random()<.06) steam.push({x:90+Math.random()*340,y:FH-34,r:5,a:.13});
    for(var si2=steam.length-1;si2>=0;si2--){
      var S2=steam[si2];
      S2.y-=.35; S2.r+=.12; S2.a-=.0016;
      if(S2.a<=0){ steam.splice(si2,1); continue; }
      var sg=c.createRadialGradient(S2.x,S2.y,1,S2.x,S2.y,S2.r);
      sg.addColorStop(0,'rgba(200,200,220,'+S2.a+')'); sg.addColorStop(1,'rgba(200,200,220,0)');
      c.fillStyle=sg; c.beginPath(); c.arc(S2.x,S2.y,S2.r,0,Math.PI*2); c.fill();
    }

    // robotic welder arm (left)
    var wob=Math.sin(t/800)*.1;
    c.save(); c.translate(86,26);
    c.strokeStyle='#2a2a3e'; c.lineWidth=6; c.lineCap='round';
    c.beginPath(); c.moveTo(0,0); c.lineTo(26,22+wob*30); c.stroke();
    c.lineWidth=4;
    c.beginPath(); c.moveTo(26,22+wob*30); c.lineTo(40,46+wob*16); c.stroke();
    c.restore();
    c.lineWidth=1; c.lineCap='butt';

    // weld flash + sparks at the arm tip
    if(t>nextWeld){
      nextWeld=t+1400+Math.random()*2600;
      for(var i2=0;i2<9;i2++){
        var a=Math.random()*Math.PI, sp=.8+Math.random()*1.8;
        sparks.push({x:126,y:72,vx:Math.cos(a)*sp,vy:-Math.abs(Math.sin(a))*sp,life:1});
      }
    }
    for(var pi=sparks.length-1;pi>=0;pi--){
      var P=sparks[pi];
      P.x+=P.vx; P.y+=P.vy; P.vy+=.07; P.life-=.03;
      if(P.life<=0){ sparks.splice(pi,1); continue; }
      c.globalAlpha=Math.max(0,P.life);
      c.fillStyle=Math.random()<.4?'#fff3d0':'#ffb347';
      c.fillRect(P.x,P.y,1.8,1.8);
    }
    c.globalAlpha=1;
    if(sparks.length){
      c.fillStyle='rgba(255,180,80,.25)';
      c.beginPath(); c.arc(126,72,5+Math.random()*3,0,Math.PI*2); c.fill();
    }

    // conveyor belt
    c.fillStyle='#15131c'; c.fillRect(0,FH-30,FW,16);
    c.strokeStyle='rgba(255,255,255,.12)';
    c.strokeRect(-1,FH-30.5,FW+2,16);
    var beltOff=(t/24)%18;
    c.strokeStyle='rgba(255,255,255,.07)';
    for(var bx=-18+beltOff;bx<FW;bx+=18){ c.beginPath(); c.moveTo(bx,FH-30); c.lineTo(bx-8,FH-14); c.stroke(); }
    // crates riding the belt
    for(var ci=0;ci<crates.length;ci++){
      var K=crates[ci];
      K.x+=.55; if(K.x>FW+30) K.x=-40;
      c.fillStyle='#241d14';
      c.fillRect(K.x,FH-48,26,18);
      c.strokeStyle='rgba(232,163,61,.5)'; c.strokeRect(K.x+.5,FH-47.5,25,17);
      c.beginPath(); c.moveTo(K.x,FH-48); c.lineTo(K.x+26,FH-30); c.moveTo(K.x+26,FH-48); c.lineTo(K.x,FH-30); c.stroke();
    }
    // floor
    c.fillStyle='#0a090d'; c.fillRect(0,FH-14,FW,14);
    c.fillStyle='rgba(232,163,61,.12)'; c.fillRect(0,FH-14,FW,1.5);
  }
  requestAnimationFrame(fLoop);
}

/* crate-result reveal sounds (consume once) */
var crateFx=window._fdCrates||null; window._fdCrates=null;
if(crateFx&&window.fdFX){
  for(var ci2=0;ci2<crateFx.n;ci2++) setTimeout(window.fdFX.crate, 180*ci2+200);
  if(crateFx.jackpot) setTimeout(window.fdFX.fanfare, 180*crateFx.n+350);
  else setTimeout(window.fdFX.chime, 180*crateFx.n+300);
}
})();
</script>
