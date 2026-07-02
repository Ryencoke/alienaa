<?php /* pages/vats.php — The Hydrofarms: interactive grow bay (herbs feed Synthesis Den potions) */
$pid = $_SESSION['pid'];
$pdo = db();

// Schema
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_vats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    crop_code VARCHAR(30) NOT NULL,
    planted_at DATETIME NOT NULL,
    ready_at DATETIME NOT NULL,
    harvested TINYINT(1) NOT NULL DEFAULT 0,
    yield_qty INT NOT NULL DEFAULT 0,
    INDEX idx_player (player_id, harvested)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE player_vats ADD COLUMN yield_qty INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Drive (cycles) cost — planting and harvesting both draw on the player's Drive,
// independent of the per-crop credit cost.
define('VATS_PLANT_DRIVE', 5);
define('VATS_HARVEST_DRIVE', 1);

// Crop definitions — harvests yield HERBS (used at the Synthesis Den to brew potions)
$CROPS = [
  'nutriblast'  => ['name'=>'Nutriblast',  'herb'=>'Nutriblast Algae', 'icon'=>'&#127807;', 'mins'=>10, 'cost'=>15,  'yield_min'=>3, 'yield_max'=>6,  'col'=>'#3bcf63', 'style'=>'algae',   'desc'=>'Fast-grow algae. Quick herbs, small batch.'],
  'synth_kelp'  => ['name'=>'Synth Kelp',  'herb'=>'Kelp Frond',       'icon'=>'&#127804;', 'mins'=>20, 'cost'=>30,  'yield_min'=>3, 'yield_max'=>7,  'col'=>'#19f0c7', 'style'=>'kelp',    'desc'=>'Balanced growth cycle. Standard output.'],
  'hydro_fungi' => ['name'=>'Hydro Fungi', 'herb'=>'Fungal Cap',       'icon'=>'&#127812;', 'mins'=>45, 'cost'=>60,  'yield_min'=>4, 'yield_max'=>8,  'col'=>'#e8a33d', 'style'=>'fungi',   'desc'=>'Dense mycelium vat. Solid herb return.'],
  'bio_culture' => ['name'=>'Bio Culture', 'herb'=>'Bio-Culture Pod',  'icon'=>'&#129514;', 'mins'=>90, 'cost'=>120, 'yield_min'=>5, 'yield_max'=>10, 'col'=>'#a66de8', 'style'=>'culture', 'desc'=>'Full culture cycle. Best herbs per vat.'],
];

// Hydro skill level (determines max plots + yield bonus)
$hydroLevel = 1;
try {
  $hq = $pdo->prepare('SELECT ps.points, s.max_pts FROM player_skills ps JOIN skills s ON s.id=ps.skill_id WHERE ps.player_id=? AND s.code=?');
  $hq->execute([$pid, 'hydro']); $hr = $hq->fetch();
  if ($hr) $hydroLevel = max(1, min(10, (int)floor((int)$hr['points'] / max(1,(int)$hr['max_pts'] / 10)) + 1));
} catch (Throwable $e) {}
$maxPlots = 2 + (int)floor($hydroLevel / 2); // 2–7 plots
$MAX_SLOTS = 7;

function vats_load_herbs($pdo, $pid): array {
  try {
    $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $q->execute(["vat_herbs:{$pid}"]);
    $v = $q->fetchColumn();
    if ($v) return json_decode($v, true) ?: [];
  } catch (Throwable $e) {}
  return [];
}
// Row-locking read of the herb blob, mirroring synth.php's $synth_lock_blob.
// synth.php locks vat_herbs:{pid} FOR UPDATE when brewing; harvest must take
// the same lock before its read-modify-write or a concurrent brew (browser A)
// + harvest (browser B) can interleave and let the harvest clobber the brew's
// herb consumption (free potions / infinite herbs). INSERT IGNORE first so the
// row exists to lock even on a player's first-ever harvest.
function vats_lock_herbs($pdo, $pid): array {
  $pdo->prepare('INSERT IGNORE INTO settings (k,v) VALUES (?,?)')->execute(["vat_herbs:{$pid}", '{}']);
  $q = $pdo->prepare('SELECT v FROM settings WHERE k=? FOR UPDATE');
  $q->execute(["vat_herbs:{$pid}"]);
  return json_decode($q->fetchColumn() ?: '{}', true) ?: [];
}
function vats_save_herbs($pdo, $pid, array $h): void {
  $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
      ->execute(["vat_herbs:{$pid}", json_encode($h)]);
}
function vats_state($pdo, $pid, $CROPS, $maxPlots, $hydroLevel): array {
  $plots = [];
  try {
    $pq = $pdo->prepare('SELECT * FROM player_vats WHERE player_id=? AND harvested=0 ORDER BY planted_at ASC');
    $pq->execute([$pid]);
    foreach ($pq as $v) {
      $plots[] = ['id'=>(int)$v['id'], 'crop'=>$v['crop_code'],
                  'planted'=>strtotime($v['planted_at']), 'ready'=>strtotime($v['ready_at'])];
    }
  } catch (Throwable $e) {}
  $pl = current_player();
  return ['plots'=>$plots, 'herbs'=>vats_load_herbs($pdo,$pid), 'max_plots'=>$maxPlots,
          'hydro'=>$hydroLevel, 'now'=>time(),
          'creds'=>(int)$pl['creds_pocket'], 'drive'=>(int)$pl['cycles'], 'drive_max'=>(int)$pl['cycles_max']];
}

// ── AJAX ────────────────────────────────────────────────────────────────────
if (!empty($_POST['vat_ajax'])) {
  header('Content-Type: application/json');
  $act = $_POST['vat_action'] ?? '';
  try {
    if ($act === 'state') {
      echo json_encode(['ok'=>true,'state'=>vats_state($pdo,$pid,$CROPS,$maxPlots,$hydroLevel)]); exit;
    }

    if ($act === 'plant') {
      $cropCode = $_POST['crop'] ?? '';
      if (!isset($CROPS[$cropCode])) throw new RuntimeException('Unknown crop.');
      $cq = $pdo->prepare('SELECT COUNT(*) FROM player_vats WHERE player_id=? AND harvested=0');
      $cq->execute([$pid]);
      if ((int)$cq->fetchColumn() >= $maxPlots) throw new RuntimeException('All '.$maxPlots.' vats occupied. Harvest first.');
      $crop = $CROPS[$cropCode];
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$crop['cost'], $pid, $crop['cost']]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits. Need '.number_format($crop['cost']).' cr.'); }
      $d = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $d->execute([VATS_PLANT_DRIVE, $pid, VATS_PLANT_DRIVE]);
      if ($d->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough Drive. Need '.VATS_PLANT_DRIVE.'.'); }
      $readyAt = date('Y-m-d H:i:s', time() + $crop['mins'] * 60);
      $pdo->prepare('INSERT INTO player_vats (player_id, crop_code, planted_at, ready_at) VALUES (?,?,NOW(),?)')->execute([$pid, $cropCode, $readyAt]);
      $pdo->commit();
      echo json_encode(['ok'=>true,'state'=>vats_state($pdo,$pid,$CROPS,$maxPlots,$hydroLevel),
        'msg'=>$crop['name'].' seeded. Ready in '.$crop['mins'].' min — &minus;'.number_format($crop['cost']).' credits, &minus;'.VATS_PLANT_DRIVE.' Drive.']); exit;
    }

    if ($act === 'harvest') {
      $vatId = (int)($_POST['vat_id'] ?? 0);
      $pdo->beginTransaction();
      $vq = $pdo->prepare('SELECT * FROM player_vats WHERE id=? AND player_id=? AND harvested=0 FOR UPDATE');
      $vq->execute([$vatId, $pid]); $vat = $vq->fetch();
      if (!$vat) { $pdo->rollBack(); throw new RuntimeException('Vat not found.'); }
      if (strtotime($vat['ready_at']) > time()) { $pdo->rollBack(); throw new RuntimeException('Still growing.'); }
      $crop = $CROPS[$vat['crop_code']] ?? null;
      if (!$crop) { $pdo->rollBack(); throw new RuntimeException('Crop data missing.'); }
      $d = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $d->execute([VATS_HARVEST_DRIVE, $pid, VATS_HARVEST_DRIVE]);
      if ($d->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough Drive. Need '.VATS_HARVEST_DRIVE.'.'); }
      // Yield: base range + hydro skill bonus (10% per level above 1)
      $yieldBase  = mt_rand($crop['yield_min'], $crop['yield_max']);
      $yieldBonus = (int)round($yieldBase * ($hydroLevel - 1) * 0.10);
      $yield      = $yieldBase + $yieldBonus;
      $herbs = vats_lock_herbs($pdo, $pid);
      $herbs[$vat['crop_code']] = ($herbs[$vat['crop_code']] ?? 0) + $yield;
      vats_save_herbs($pdo, $pid, $herbs);
      $pdo->prepare('UPDATE player_vats SET harvested=1, yield_qty=? WHERE id=?')->execute([$yield, $vatId]);
      $pdo->commit();
      echo json_encode(['ok'=>true,'state'=>vats_state($pdo,$pid,$CROPS,$maxPlots,$hydroLevel),
        'gained'=>['crop'=>$vat['crop_code'],'qty'=>$yield,'bonus'=>$yieldBonus,'name'=>$crop['herb'],'col'=>$crop['col'],'drive'=>VATS_HARVEST_DRIVE],
        'msg'=>'Harvested '.$yield.'× '.$crop['herb'].($yieldBonus>0?' (+'.$yieldBonus.' hydro bonus)':'').' — &minus;'.VATS_HARVEST_DRIVE.' Drive.']); exit;
    }

    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

// ── Page state ──────────────────────────────────────────────────────────────
$initState = json_encode(vats_state($pdo,$pid,$CROPS,$maxPlots,$hydroLevel));
$cropsJson = json_encode($CROPS);

// Recent harvests (last 8)
$history = [];
try {
  $hq2 = $pdo->prepare('SELECT * FROM player_vats WHERE player_id=? AND harvested=1 ORDER BY ready_at DESC LIMIT 8');
  $hq2->execute([$pid]); $history = $hq2->fetchAll();
} catch (Throwable $e) {}

// All-time grow stats
$vatStats = ['planted' => 0, 'harvested' => 0, 'herbs' => 0];
try {
  $sq = $pdo->prepare('SELECT COUNT(*) planted, SUM(harvested) harvested, COALESCE(SUM(yield_qty),0) herbs FROM player_vats WHERE player_id=?');
  $sq->execute([$pid]); $sr = $sq->fetch();
  if ($sr) $vatStats = ['planted' => (int)$sr['planted'], 'harvested' => (int)$sr['harvested'], 'herbs' => (int)$sr['herbs']];
} catch (Throwable $e) {}
?>
<style>
#vat-wrap{max-width:600px;margin:0 auto}
#vat-stage{position:relative;display:inline-block;max-width:100%}
#vat-canvas{display:block;background:linear-gradient(180deg,#05050d,#090914);border-radius:10px;border:1px solid rgba(59,207,99,.18);box-shadow:0 0 30px rgba(59,207,99,.05),inset 0 0 60px rgba(0,0,0,.55);max-width:100%;height:auto;cursor:pointer;touch-action:manipulation}
#vat-scanlines{position:absolute;inset:0;border-radius:10px;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(255,255,255,.02) 0 1px,transparent 1px 3px)}
#vat-msg{min-height:20px;font-size:12px;text-align:center;transition:opacity .3s}
.crop-card{position:relative;overflow:hidden;background:var(--panel2);border:1px solid var(--line);border-radius:9px;padding:10px 12px;cursor:pointer;transition:border-color .15s,background .15s,transform .12s;user-select:none}
.crop-card:hover{transform:translateY(-2px)}
.crop-card.sel{border-color:var(--crop-col,#3bcf63);background:rgba(59,207,99,.05);box-shadow:0 0 14px var(--crop-glow,rgba(59,207,99,.15))}
.crop-card.broke{opacity:.45;cursor:not-allowed}
.herb-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600}
@keyframes herbPop{0%{transform:scale(.6);opacity:0}70%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}
.herb-chip.pop{animation:herbPop .28s ease-out backwards}
#vat-hud h2{text-shadow:0 0 14px rgba(59,207,99,.3)}
</style>

<div id="vat-wrap">

<!-- HUD -->
<div class="panel" id="vat-hud" style="padding:12px 16px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="margin:0 0 2px;font-size:17px">&#127807; The Hydrofarms &mdash; Grow Bay</h2>
      <p class="muted" style="margin:0;font-size:11px">Seed a vat, let it grow, pick the herbs. Brew them into potions at the <a href="index.php?p=synth" style="color:var(--accent)">Synthesis Den</a>.</p>
    </div>
    <div style="display:flex;gap:14px;font-size:11px;color:var(--muted);align-items:center">
      <div>HYDRO <b style="font-family:'Orbitron',sans-serif;color:#3bcf63">Lv <?= $hydroLevel ?></b></div>
      <div>VATS <b id="vhud-plots" style="font-family:'Orbitron',sans-serif;color:var(--text)">0/<?= $maxPlots ?></b></div>
      <button id="vat-mute" onclick="toggleVatSound()" title="Toggle sound" style="font-size:12px;padding:3px 8px;background:transparent;border:1px solid rgba(255,255,255,.15);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
    </div>
  </div>
</div>

<!-- Message -->
<div id="vat-msg" class="muted" style="margin:2px 0 6px">&nbsp;</div>

<!-- Grow bay canvas -->
<div style="text-align:center;margin-bottom:10px">
  <div id="vat-stage">
    <canvas id="vat-canvas"></canvas>
    <div id="vat-scanlines"></div>
  </div>
</div>

<!-- Seed selector -->
<div class="panel" style="padding:12px 14px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px">
    <h3 style="margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.5px">&#127809; Seed Stock <span class="muted" style="text-transform:none;letter-spacing:0;font-weight:400">— pick a strain, then click an empty vat</span></h3>
    <span style="font-size:10px;color:var(--muted)">Hydro Lv<?= $hydroLevel ?> = +<?= ($hydroLevel-1)*10 ?>% yield</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(125px,1fr));gap:8px">
    <?php foreach ($CROPS as $key => $crop):
      $minY=(int)round($crop['yield_min']*(1+($hydroLevel-1)*0.10));
      $maxY=(int)round($crop['yield_max']*(1+($hydroLevel-1)*0.10));
    ?>
    <div class="crop-card" data-crop="<?= $key ?>" data-cost="<?= (int)$crop['cost'] ?>" style="--crop-col:<?= $crop['col'] ?>;--crop-glow:<?= $crop['col'] ?>33">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $crop['col'] ?>,transparent)"></div>
      <div style="display:flex;align-items:center;gap:7px;margin-bottom:4px">
        <span style="font-size:18px"><?= $crop['icon'] ?></span>
        <b style="font-size:12px;color:<?= $crop['col'] ?>"><?= e($crop['name']) ?></b>
      </div>
      <div style="font-size:10px;color:var(--muted);line-height:1.45"><?= e($crop['desc']) ?></div>
      <div style="font-size:10px;margin-top:5px;line-height:1.5">
        <span style="color:<?= $crop['col'] ?>"><?= $minY ?>–<?= $maxY ?>× <?= e($crop['herb']) ?></span><br>
        <span class="muted"><?= $crop['mins'] ?> min &middot; <?= number_format($crop['cost']) ?> cr &middot; <?= VATS_PLANT_DRIVE ?> Drive</span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Herb satchel -->
<div class="panel" style="padding:12px 14px">
  <h3 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">&#129716; Herb Satchel</h3>
  <div id="vat-satchel" style="display:flex;flex-wrap:wrap;gap:7px">
    <span class="muted" style="font-size:12px">No herbs yet.</span>
  </div>
  <p style="margin:9px 0 0;font-size:11px"><a href="index.php?p=synth&tab=brew" style="color:var(--accent)">&#9879; Brew potions at the Synthesis Den &rarr;</a></p>
</div>

<?php if ($vatStats['planted'] > 0): ?>
<div class="panel" style="padding:12px 14px">
  <h3 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">&#128202; All-Time Grow Stats</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px">
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center">
      <div style="font-size:16px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--text)"><?= number_format($vatStats['planted']) ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase">Vats Planted</div>
    </div>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center">
      <div style="font-size:16px;font-weight:700;font-family:'Orbitron',sans-serif;color:#3bcf63"><?= number_format($vatStats['harvested']) ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase">Vats Harvested</div>
    </div>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center">
      <div style="font-size:16px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent)"><?= number_format($vatStats['herbs']) ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase">Total Herbs Picked</div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($history)): ?>
<div class="panel" style="padding:12px 14px">
  <h3 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">&#128202; Recent Harvests</h3>
  <?php foreach ($history as $h): $hc=$CROPS[$h['crop_code']]??['name'=>$h['crop_code'],'icon'=>'&#127807;','col'=>'#3bcf63']; ?>
  <div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px">
    <span style="flex:none"><?= $hc['icon'] ?></span>
    <span style="flex:1;color:<?= $hc['col'] ?>"><?= e($hc['name']) ?></span>
    <span style="font-family:'Orbitron',sans-serif;font-size:11px;color:var(--accent)">&times;<?= (int)$h['yield_qty'] ?></span>
    <span style="color:var(--muted);font-size:11px"><?= e(date('M j g:ia', strtotime($h['ready_at']))) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div>

<script>
(function(){
'use strict';

var COLS=4, SLOT_W=132, SLOT_H=158, PAD=14;
var canvas=document.getElementById('vat-canvas');
if(!canvas) return;
var ctx=canvas.getContext('2d');
var state=<?= $initState ?>;
var CROPS=<?= $cropsJson ?>;
var MAXSLOTS=<?= $MAX_SLOTS ?>;
var PLANT_DRIVE=<?= VATS_PLANT_DRIVE ?>, HARVEST_DRIVE=<?= VATS_HARVEST_DRIVE ?>;

var rows=Math.ceil(MAXSLOTS/COLS);
var W=COLS*SLOT_W+(COLS+1)*PAD, H=rows*SLOT_H+(rows+1)*PAD;
var dpr=Math.min(2,window.devicePixelRatio||1);
canvas.width=W*dpr; canvas.height=H*dpr;
canvas.style.width=W+'px';
ctx.scale(dpr,dpr);

var clockOff=state.now-Math.floor(Date.now()/1000); // server-client clock offset
var busy=false, msgTimer=null;
var selCrop=null;
var hoverSlot=-1;
var bubbles=[], particles=[], floaters=[];
var muted=localStorage.getItem('vatsMuted')==='1', ac=null;

function sfx(freq,dur,type,vol,slide){
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
function updateMute(){ var b=document.getElementById('vat-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; }
window.toggleVatSound=function(){ muted=!muted; localStorage.setItem('vatsMuted',muted?'1':'0'); updateMute(); if(!muted) sfx(660,.08,'sine',.05); };
updateMute();

function now(){ return Math.floor(Date.now()/1000)+clockOff; }
function h1(n){ n=(n^61)^(n>>16); n=n+(n<<3); n=n^(n>>4); n=n*668265261; n=n^(n>>15); return (n>>>0)/4294967295; }
function slotXY(i){ var c=i%COLS, r=Math.floor(i/COLS); return {x:PAD+c*(SLOT_W+PAD),y:PAD+r*(SLOT_H+PAD)}; }
function slotPlot(i){ return (state&&state.plots[i])?state.plots[i]:null; }

function showMsg(txt,col){
  var el=document.getElementById('vat-msg'); if(!el) return;
  el.style.opacity='1'; el.style.color=col||'#3bcf63'; el.textContent=txt;
  if(msgTimer) clearTimeout(msgTimer);
  msgTimer=setTimeout(function(){el.style.opacity='0';},2800);
}

// ── DOM sync ───────────────────────────────────────────────────────────────
function syncDom(){
  if(!state) return;
  var pe=document.getElementById('vhud-plots'); if(pe) pe.textContent=state.plots.length+'/'+state.max_plots;
  var sat=document.getElementById('vat-satchel');
  if(sat){
    var keys=Object.keys(state.herbs||{}).filter(function(k){return state.herbs[k]>0;});
    if(!keys.length){ sat.innerHTML='<span class="muted" style="font-size:12px">No herbs yet.</span>'; }
    else{
      var html='';
      keys.forEach(function(k){
        var c=CROPS[k]||{herb:k,col:'#3bcf63',icon:'&#127807;'};
        html+='<div class="herb-chip" style="color:'+c.col+';border-color:'+c.col+'40">'
             +'<span>'+c.icon+'</span><span style="color:var(--text)">'+c.herb+'</span>'
             +'<span style="font-family:Orbitron,sans-serif;font-size:11px">×'+state.herbs[k]+'</span></div>';
      });
      sat.innerHTML=html;
    }
  }
  // grey out unaffordable seed cards
  document.querySelectorAll('.crop-card').forEach(function(card){
    var cost=parseInt(card.dataset.cost,10)||0;
    card.classList.toggle('broke', state.creds<cost || state.drive<PLANT_DRIVE);
  });
}

// ── Drawing ────────────────────────────────────────────────────────────────
function rrect(x,y,w,h,r){
  ctx.beginPath();
  ctx.moveTo(x+r,y); ctx.arcTo(x+w,y,x+w,y+h,r); ctx.arcTo(x+w,y+h,x,y+h,r);
  ctx.arcTo(x,y+h,x,y,r); ctx.arcTo(x,y,x+w,y,r); ctx.closePath();
}

function drawPlant(cx,bottom,style,col,g,t,seed){
  // g = growth 0..1 ; t = time ms ; seed = per-vat hash
  if(g<=0.04){ // seed pellet
    ctx.fillStyle=col; ctx.globalAlpha=.8;
    ctx.beginPath(); ctx.arc(cx,bottom-3,2.5,0,Math.PI*2); ctx.fill();
    ctx.globalAlpha=1; return;
  }
  var sway=Math.sin(t/900+seed*9)*3*g;
  if(style==='algae'){
    for(var i=-2;i<=2;i++){
      var hgt=(34+h1(seed*31+i)*22)*g;
      var bx=cx+i*5;
      ctx.strokeStyle=col; ctx.globalAlpha=.5+.5*h1(seed*7+i); ctx.lineWidth=2;
      ctx.beginPath(); ctx.moveTo(bx,bottom);
      ctx.quadraticCurveTo(bx+Math.sin(t/700+i)*4*g, bottom-hgt*.55, bx+sway+Math.sin(t/500+i*2)*3, bottom-hgt);
      ctx.stroke();
    }
    ctx.globalAlpha=1; ctx.lineWidth=1;
  } else if(style==='kelp'){
    for(var k=-1;k<=1;k++){
      var kh=(42+h1(seed*13+k)*16)*g, kx=cx+k*8;
      ctx.strokeStyle=col; ctx.lineWidth=2.5; ctx.globalAlpha=.85;
      ctx.beginPath(); ctx.moveTo(kx,bottom);
      ctx.quadraticCurveTo(kx-6*g+sway,bottom-kh*.5,kx+sway+k*3,bottom-kh);
      ctx.stroke();
      for(var lf=1;lf<=3;lf++){ // frond blades
        var ly=bottom-kh*lf/3.2, lside=(lf%2?1:-1);
        ctx.fillStyle=col; ctx.globalAlpha=.4;
        ctx.beginPath();
        ctx.ellipse(kx+lside*5*g+sway*lf/3,ly,6*g,2.4*g,lside*.6,0,Math.PI*2);
        ctx.fill();
      }
    }
    ctx.globalAlpha=1; ctx.lineWidth=1;
  } else if(style==='fungi'){
    for(var m=0;m<3;m++){
      var mh=(16+h1(seed*17+m)*18)*g, mx=cx+(m-1)*11+sway*.4;
      var capR=(5+h1(seed*23+m)*4)*g;
      ctx.fillStyle='#d8d2c0'; ctx.globalAlpha=.7;
      ctx.fillRect(mx-1.5,bottom-mh,3,mh);
      ctx.fillStyle=col; ctx.globalAlpha=.95;
      ctx.beginPath(); ctx.arc(mx,bottom-mh,capR+2,Math.PI,0); ctx.fill();
      ctx.fillStyle='rgba(255,255,255,.35)';
      ctx.beginPath(); ctx.arc(mx-capR*.35,bottom-mh-capR*.35,capR*.25,0,Math.PI*2); ctx.fill();
    }
    ctx.globalAlpha=1;
  } else { // culture — drifting glowing orbs
    var n=2+Math.round(3*g);
    for(var o=0;o<n;o++){
      var or=(3+h1(seed*41+o)*4)*g;
      var oy=bottom-10-(h1(seed*43+o)*38)*g+Math.sin(t/800+o*2+seed*5)*4;
      var ox2=cx+(h1(seed*47+o)-.5)*30*g+Math.sin(t/1100+o)*3;
      ctx.shadowColor=col; ctx.shadowBlur=8;
      ctx.fillStyle=col; ctx.globalAlpha=.5+.3*Math.sin(t/600+o*3);
      ctx.beginPath(); ctx.arc(ox2,oy,or,0,Math.PI*2); ctx.fill();
    }
    ctx.shadowBlur=0; ctx.globalAlpha=1;
  }
}

function draw(t){
  ctx.clearRect(0,0,W,H);
  // floor strips
  for(var r=0;r<rows;r++){
    var fy=PAD+r*(SLOT_H+PAD)+SLOT_H-6;
    ctx.fillStyle='rgba(255,255,255,.025)';
    ctx.fillRect(PAD/2,fy+4,W-PAD,2);
  }

  for(var i=0;i<MAXSLOTS;i++){
    var p=slotXY(i), x=p.x, y=p.y;
    var plot=slotPlot(i);
    var locked=i>=state.max_plots;
    var gx=x+10, gy=y+8, gw=SLOT_W-20, gh=SLOT_H-38; // glass capsule bounds
    var crop=plot?(CROPS[plot.crop]||null):null;
    var col=crop?crop.col:'#3a3f55';
    var nw=now();
    var prog=0, ready=false;
    if(plot){
      var total=Math.max(1,plot.ready-plot.planted);
      prog=Math.min(1,(nw-plot.planted)/total);
      ready=nw>=plot.ready;
    }

    // pedestal
    ctx.fillStyle='#10101e';
    rrect(x+6,y+SLOT_H-26,SLOT_W-12,18,4); ctx.fill();
    ctx.fillStyle=locked?'rgba(255,255,255,.04)':(ready?col:'rgba(255,255,255,.10)');
    ctx.fillRect(x+14,y+SLOT_H-19,SLOT_W-28,2);

    // glass capsule
    ctx.fillStyle=locked?'rgba(8,8,16,.85)':'rgba(10,14,24,.85)';
    rrect(gx,gy,gw,gh,12); ctx.fill();

    if(!locked){
      // liquid
      var liqTop=gy+gh*0.30;
      ctx.save();
      rrect(gx+2,gy+2,gw-4,gh-4,10); ctx.clip();
      var lg=ctx.createLinearGradient(0,liqTop,0,gy+gh);
      var la=plot?.30:.10;
      lg.addColorStop(0,hexA(col,la*.7)); lg.addColorStop(1,hexA(col,la));
      ctx.fillStyle=lg;
      ctx.beginPath();
      ctx.moveTo(gx,liqTop+Math.sin(t/800+i)*2);
      for(var wx2=0;wx2<=gw;wx2+=8){
        ctx.lineTo(gx+wx2, liqTop+Math.sin(t/800+i+wx2/14)*2);
      }
      ctx.lineTo(gx+gw,gy+gh); ctx.lineTo(gx,gy+gh); ctx.closePath(); ctx.fill();
      // plant
      if(plot) drawPlant(gx+gw/2, gy+gh-8, crop.style, col, prog, t, plot.id%97/97+.07);
      ctx.restore();

      // ready halo / sparkles
      if(ready){
        var rp=.6+.4*Math.sin(t/300+i);
        ctx.shadowColor=col; ctx.shadowBlur=16*rp;
        ctx.strokeStyle=hexA(col,.65*rp); ctx.lineWidth=1.6;
        rrect(gx,gy,gw,gh,12); ctx.stroke();
        ctx.shadowBlur=0; ctx.lineWidth=1;
        if(((t/500+i)%3)<.3){
          ctx.fillStyle='rgba(255,255,255,.9)';
          ctx.fillRect(gx+8+h1(i*99+Math.floor(t/500))*(gw-16), gy+10+h1(i*55+Math.floor(t/500))*(gh*.4),1.8,1.8);
        }
      }
    }

    // glass outline + highlight
    ctx.strokeStyle=locked?'rgba(255,255,255,.08)':(hoverSlot===i?'rgba(255,255,255,.45)':'rgba(255,255,255,.16)');
    rrect(gx,gy,gw,gh,12); ctx.stroke();
    ctx.strokeStyle='rgba(255,255,255,.10)';
    ctx.beginPath(); ctx.moveTo(gx+7,gy+14); ctx.quadraticCurveTo(gx+4,gy+gh/2,gx+7,gy+gh-14); ctx.stroke();

    // selection hint on empty vats while a seed is selected
    if(!locked&&!plot&&selCrop){
      var sp=.5+.5*Math.sin(t/350);
      ctx.strokeStyle=hexA(CROPS[selCrop].col,.35+.3*sp);
      ctx.setLineDash([5,5]);
      rrect(gx+3,gy+3,gw-6,gh-6,9); ctx.stroke();
      ctx.setLineDash([]);
    }

    // label / countdown
    ctx.textAlign='center'; ctx.textBaseline='middle';
    if(locked){
      ctx.font='12px monospace'; ctx.fillStyle='rgba(255,255,255,.25)';
      ctx.fillText('🔒',x+SLOT_W/2,gy+gh/2);
      ctx.font='600 9px sans-serif'; ctx.fillStyle='rgba(255,255,255,.22)';
      ctx.fillText('HYDRO LV '+((i-1)*2<=0?2:(i-1)*2),x+SLOT_W/2,y+SLOT_H-10);
    } else if(!plot){
      var ep2=.35+.25*Math.sin(t/500+i);
      ctx.font='300 22px sans-serif'; ctx.fillStyle='rgba(255,255,255,'+ep2+')';
      ctx.fillText('+',x+SLOT_W/2,gy+gh/2);
      ctx.font='600 9px sans-serif'; ctx.fillStyle='rgba(255,255,255,.3)';
      ctx.fillText('EMPTY VAT',x+SLOT_W/2,y+SLOT_H-10);
    } else {
      ctx.font='600 9px sans-serif'; ctx.fillStyle=hexA(col,.9);
      var secs=Math.max(0,plot.ready-nw);
      var lbl=ready?'◈ READY — CLICK TO PICK':crop.name.toUpperCase()+' · '+fmtT(secs);
      ctx.fillText(lbl,x+SLOT_W/2,y+SLOT_H-10);
      // progress bar
      ctx.fillStyle='rgba(0,0,0,.45)';
      ctx.fillRect(gx,y+SLOT_H-22,gw,3);
      ctx.fillStyle=ready?col:hexA(col,.8);
      ctx.fillRect(gx,y+SLOT_H-22,gw*prog,3);
    }
  }

  // bubbles
  for(var bi=bubbles.length-1;bi>=0;bi--){
    var B=bubbles[bi];
    B.y-=B.v; B.x+=Math.sin(t/400+B.p)*0.18; B.life-=.004;
    if(B.life<=0||B.y<B.minY){ bubbles.splice(bi,1); continue; }
    ctx.strokeStyle='rgba(255,255,255,'+(0.22*B.life)+')';
    ctx.beginPath(); ctx.arc(B.x,B.y,B.r,0,Math.PI*2); ctx.stroke();
  }
  // spawn bubbles in planted vats
  if(Math.random()<.30&&state){
    for(var si=0;si<Math.min(state.plots.length,state.max_plots);si++){
      if(Math.random()<.35){
        var sp2=slotXY(si);
        bubbles.push({x:sp2.x+20+Math.random()*(SLOT_W-40), y:sp2.y+SLOT_H-44,
                      minY:sp2.y+8+(SLOT_H-38)*0.30, v:.25+Math.random()*.4, r:.8+Math.random()*1.8,
                      p:Math.random()*9, life:1});
      }
    }
  }

  // harvest particles
  for(var pi=particles.length-1;pi>=0;pi--){
    var P=particles[pi];
    P.x+=P.vx; P.y+=P.vy; P.vy+=.06; P.life-=.022;
    if(P.life<=0){ particles.splice(pi,1); continue; }
    ctx.globalAlpha=Math.max(0,P.life);
    ctx.fillStyle=P.col;
    ctx.fillRect(P.x,P.y,P.s,P.s);
  }
  ctx.globalAlpha=1;

  // floating texts
  for(var fi=floaters.length-1;fi>=0;fi--){
    var F=floaters[fi];
    F.dy-=.4; F.life-=.014;
    if(F.life<=0){ floaters.splice(fi,1); continue; }
    ctx.globalAlpha=Math.min(1,F.life*2);
    ctx.font='700 12px sans-serif'; ctx.textAlign='center';
    ctx.fillStyle='rgba(0,0,0,.6)'; ctx.fillText(F.txt,F.x+1,F.y+F.dy+1);
    ctx.fillStyle=F.col; ctx.fillText(F.txt,F.x,F.y+F.dy);
  }
  ctx.globalAlpha=1;
}

function hexA(hex,a){
  var n=parseInt(hex.slice(1),16);
  return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';
}
function fmtT(s){
  if(s<=0) return 'READY';
  var h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sc=s%60;
  if(h>0) return h+'h '+m+'m';
  if(m>0) return m+'m '+(sc<10?'0':'')+sc+'s';
  return sc+'s';
}

function loop(t){
  if(!document.body.contains(canvas)) return;
  requestAnimationFrame(loop);
  if(state) draw(t);
}
requestAnimationFrame(loop);

// ── AJAX ──────────────────────────────────────────────────────────────────
function vatPost(data,cb){
  if(busy) return;
  busy=true;
  data.vat_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;showMsg('Network error','var(--neon2)');});
}
function applyState(s){
  clockOff=s.now-Math.floor(Date.now()/1000);
  state=s; syncDom();
}

// ── Interaction ───────────────────────────────────────────────────────────
function eventSlot(e){
  var rect=canvas.getBoundingClientRect();
  var mx=(e.clientX-rect.left)*(W/rect.width);
  var my=(e.clientY-rect.top)*(H/rect.height);
  for(var i=0;i<MAXSLOTS;i++){
    var p=slotXY(i);
    if(mx>=p.x&&my>=p.y&&mx<p.x+SLOT_W&&my<p.y+SLOT_H) return i;
  }
  return -1;
}
canvas.addEventListener('mousemove',function(e){
  hoverSlot=eventSlot(e);
  canvas.style.cursor=hoverSlot>=0&&hoverSlot<state.max_plots?'pointer':'default';
});
canvas.addEventListener('mouseleave',function(){hoverSlot=-1;});
canvas.addEventListener('click',function(e){
  if(!state) return;
  var i=eventSlot(e);
  if(i<0) return;
  if(i>=state.max_plots){ showMsg('Vat locked — raise your Hydroponics skill at the Datacore.','var(--neon2)'); sfx(90,.07,'square',.03); return; }
  var plot=slotPlot(i);
  if(plot){
    var nw=now();
    if(nw>=plot.ready){
      if(state.drive<HARVEST_DRIVE){ showMsg('Not enough Drive to harvest.','var(--neon2)'); sfx(90,.07,'square',.03); return; }
      var crop=CROPS[plot.crop]||{};
      var p=slotXY(i);
      vatPost({vat_action:'harvest',vat_id:plot.id},function(d){
        if(!d.ok){ showMsg(d.err||'Error','var(--neon2)'); if(window.showToast) window.showToast(d.err||'Error','err'); return; }
        applyState(d.state);
        if(d.gained){
          var cx=p.x+SLOT_W/2, cy=p.y+SLOT_H/2;
          for(var n=0;n<18;n++){
            var a=Math.random()*Math.PI*2, sp=1+Math.random()*2.2;
            particles.push({x:cx,y:cy,vx:Math.cos(a)*sp,vy:Math.sin(a)*sp-1.4,
              col:n%3?d.gained.col:'#ffffff',s:1.6+Math.random()*2.6,life:1});
          }
          floaters.push({x:cx,y:p.y+34,dy:0,txt:'+'+d.gained.qty+' '+d.gained.name,col:d.gained.col,life:1});
          if(d.gained.bonus>0) floaters.push({x:cx,y:p.y+50,dy:0,txt:'+'+d.gained.bonus+' hydro bonus',col:'#e8d44d',life:.9});
          sfx(520,.1,'square',.045); setTimeout(function(){sfx(820,.14,'square',.045);},80);
          var chips=document.querySelectorAll('#vat-satchel .herb-chip');
          chips.forEach(function(c){c.classList.remove('pop');});
          if(chips.length) chips[chips.length-1].classList.add('pop');
        }
        // Same toast pattern the rest of the game uses for a confirmed action,
        // instead of only the small fade-out line under the header.
        if(d.msg){ showMsg(d.msg,'#3bcf63'); if(window.showToast) window.showToast(d.msg,'ok'); }
        if(window.refreshState) window.refreshState();
      });
    } else {
      showMsg((CROPS[plot.crop]||{}).name+' still growing — '+fmtT(plot.ready-nw)+' left.','rgba(255,255,255,.5)');
    }
    return;
  }
  // empty vat
  if(!selCrop){
    showMsg('Pick a strain from the Seed Stock first.','rgba(255,255,255,.5)');
    var card=document.querySelector('.crop-card');
    if(card){ card.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    return;
  }
  var cc=CROPS[selCrop];
  vatPost({vat_action:'plant',crop:selCrop},function(d){
    if(!d.ok){ showMsg(d.err||'Error','var(--neon2)'); if(window.showToast) window.showToast(d.err||'Error','err'); sfx(90,.07,'square',.03); return; }
    applyState(d.state);
    var p2=slotXY(i);
    for(var n=0;n<8;n++){
      particles.push({x:p2.x+SLOT_W/2+(Math.random()-.5)*20,y:p2.y+SLOT_H-46,
        vx:(Math.random()-.5)*1.2,vy:-Math.random()*1.4,col:cc.col,s:1.4+Math.random()*1.8,life:.8});
    }
    sfx(330,.1,'sine',.05,500);
    if(d.msg){ showMsg(d.msg,cc.col); if(window.showToast) window.showToast(d.msg,'ok'); }
    if(window.refreshState) window.refreshState();
  });
});

// seed card selection
document.querySelectorAll('.crop-card').forEach(function(card){
  card.addEventListener('click',function(){
    if(card.classList.contains('broke')){ showMsg('Not enough credits or Drive for that strain.','var(--neon2)'); return; }
    var was=card.classList.contains('sel');
    document.querySelectorAll('.crop-card').forEach(function(c){c.classList.remove('sel');});
    selCrop=was?null:card.dataset.crop;
    if(!was){ card.classList.add('sel'); showMsg((CROPS[selCrop]||{}).name+' selected — click an empty vat to seed it.', (CROPS[selCrop]||{}).col); sfx(440,.07,'sine',.04); }
  });
});

// periodic state refresh (other tabs / long timers)
setInterval(function(){
  if(!document.body.contains(canvas)) return;
  if(!busy) vatPost({vat_action:'state'},function(d){ if(d.ok) applyState(d.state); });
},30000);

syncDom();
})();
</script>
