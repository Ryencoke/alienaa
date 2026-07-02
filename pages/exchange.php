<?php /* pages/exchange.php — The Exchange: Buy Shards + Subscription */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;
$today = date('Y-m-d');

const SUB_COST_30  = 30;
const SUB_COST_90  = 80;
const SUB_DAYS_30  = 30;
const SUB_DAYS_90  = 90;

$SHARD_PACKAGES = [
  ['id'=>'pack_10',  'shards'=>10,   'price'=>'$1.99',  'bonus'=>0,   'popular'=>false],
  ['id'=>'pack_50',  'shards'=>50,   'price'=>'$7.99',  'bonus'=>5,   'popular'=>false],
  ['id'=>'pack_100', 'shards'=>100,  'price'=>'$14.99', 'bonus'=>15,  'popular'=>true ],
  ['id'=>'pack_250', 'shards'=>250,  'price'=>'$29.99', 'bonus'=>50,  'popular'=>false],
  ['id'=>'pack_500', 'shards'=>500,  'price'=>'$49.99', 'bonus'=>125, 'popular'=>false],
  ['id'=>'pack_1k',  'shards'=>1000, 'price'=>'$89.99', 'bonus'=>300, 'popular'=>true ],
];

$SUB_PERKS = [
  '+25% XP from all combat and training',
  '+500 max Drive capacity',
  '&#9733; Subscriber badge on your handle',
  'Daily Vault gives a wider Shard range (0&ndash;10 vs 0&ndash;6)',
  'Access to subscriber-only Lounge tab',
  'Priority customer service queue',
  'Exclusive account themes &amp; accent colors',
  'Subscriber star in chat and profiles',
];

// ── AJAX: vault crack (animated reveal needs the result without a reload) ──
if (!empty($_POST['exch_ajax'])) {
  header('Content-Type: application/json');
  try {
    if (($_POST['exch_action'] ?? '') !== 'pull') throw new RuntimeException('Unknown action.');
    $pl = current_player();
    if (($pl['shard_pull_at'] ?? null) === $today) throw new RuntimeException('Already cracked today. Back tomorrow.');
    $isSub = is_subscribed($pl);
    $got = random_int(0, $isSub ? 10 : 6);
    // Atomic daily gate — WHERE re-checks the date so parallel requests can't all award shards
    $u = $pdo->prepare('UPDATE players SET shards = shards + ?, shard_pull_at = ? WHERE id = ? AND (shard_pull_at IS NULL OR shard_pull_at <> ?)');
    $u->execute([$got, $today, (int)$pl['id'], $today]);
    if ($u->rowCount() !== 1) throw new RuntimeException('Already cracked today. Back tomorrow.');
    $pl = current_player();
    echo json_encode(['ok'=>true,'got'=>$got,'shards'=>(int)$pl['shards']]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {
    if ($a === 'subscribe30') {
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SUB_COST_30, $pid, SUB_COST_30]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Need ' . SUB_COST_30 . ' Shards to subscribe.');
      $cur   = $player['sub_until'] ?? null;
      $base  = ($cur && $cur >= $today) ? $cur : $today;
      $until = date('Y-m-d', strtotime("$base +" . SUB_DAYS_30 . " days"));
      $pdo->prepare('UPDATE players SET sub_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Subscribed for 30 days! Active until {$until}.";
    }
    elseif ($a === 'subscribe90') {
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SUB_COST_90, $pid, SUB_COST_90]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Need ' . SUB_COST_90 . ' Shards to subscribe.');
      $cur   = $player['sub_until'] ?? null;
      $base  = ($cur && $cur >= $today) ? $cur : $today;
      $until = date('Y-m-d', strtotime("$base +" . SUB_DAYS_90 . " days"));
      $pdo->prepare('UPDATE players SET sub_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Subscribed for 90 days! Active until {$until}.";
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); $msgErr = true; }
  $player = current_player();
}

$sub    = is_subscribed($player);
$pulled = (($player['shard_pull_at'] ?? null) === $today);
?>
<style>
#ex-canvas{display:block;width:100%;height:116px;border-radius:9px 9px 0 0}
#ex-head h2{text-shadow:0 0 16px rgba(25,240,199,.4)}
.ex-pack{position:relative;overflow:hidden;background:var(--panel2);border:2px solid var(--line);border-radius:10px;padding:14px;text-align:center;transition:transform .12s,border-color .15s,box-shadow .15s}
.ex-pack:hover{transform:translateY(-3px);border-color:rgba(25,240,199,.45);box-shadow:0 6px 18px rgba(0,0,0,.35),0 0 14px rgba(25,240,199,.1)}
.ex-pack::after{content:'';position:absolute;top:0;left:-70%;width:45%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.06),transparent);transform:skewX(-20deg);transition:left .5s ease;pointer-events:none}
.ex-pack:hover::after{left:130%}
.ex-pack.popular{border-color:rgba(25,240,199,.5);box-shadow:0 0 14px rgba(25,240,199,.12)}
@keyframes exPopPulse{0%,100%{box-shadow:0 0 10px rgba(25,240,199,.12)}50%{box-shadow:0 0 20px rgba(25,240,199,.28)}}
.ex-pack.popular{animation:exPopPulse 2.2s ease-in-out infinite}
.ex-shard-ic{display:inline-block;animation:exShardSpin 5s linear infinite}
@keyframes exShardSpin{0%{transform:rotateY(0)}100%{transform:rotateY(360deg)}}
.ex-plan{position:relative;background:var(--panel2);border:2px solid var(--line);border-radius:10px;padding:16px;text-align:center;transition:transform .12s,box-shadow .15s}
.ex-plan:hover{transform:translateY(-2px)}
.ex-plan.gold{border-color:rgba(232,212,77,.5);box-shadow:0 0 14px rgba(232,212,77,.1)}
.ex-perk{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--muted);animation:exPerkIn .3s ease-out backwards}
@keyframes exPerkIn{0%{opacity:0;transform:translateX(-8px)}100%{opacity:1;transform:none}}
#vault-canvas{display:block;margin:0 auto;cursor:pointer}
#vault-status{min-height:20px;text-align:center;font-size:12px;color:var(--muted);transition:opacity .3s}
</style>

<!-- Header -->
<div class="panel" id="ex-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="ex-canvas"></canvas>
    <div style="position:absolute;left:0;right:0;top:14px;text-align:center;pointer-events:none">
      <h2 style="margin:0">&#9670; The Exchange</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Shards are hard currency. Scarce, shiny, and the only thing the Grid truly respects.</p>
    </div>
    <button id="ex-mute" onclick="toggleExSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
  <?php if ($msg): ?><div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>" style="margin:10px 14px 0"><?= e($msg) ?></div><?php endif; ?>
  <div style="height:10px"></div>
</div>

<!-- ===================== DAILY VAULT ===================== -->
<div class="panel">
  <h3 style="margin-top:0;text-align:center">&#128273; Daily Shard Vault</h3>
  <p class="muted" style="font-size:12px;margin:0 0 10px;text-align:center">One crack per day &mdash; <b>0&ndash;<?= $sub ? '10' : '6' ?> Shards</b> inside<?= $sub ? ' (subscriber double range active)' : '. Subscribers get double the range' ?>. Resets at midnight server time.</p>
  <canvas id="vault-canvas" width="220" height="220"
          data-pulled="<?= $pulled ? '1' : '0' ?>"></canvas>
  <div id="vault-status"><?= $pulled ? 'Already cracked today. Come back tomorrow.' : 'Click the vault to crack it.' ?></div>
</div>

<!-- ===================== BUY SHARDS ===================== -->
<div class="panel">
  <h3 style="margin-top:0;text-align:center;color:var(--accent)">&#9670; Buy Shards</h3>
  <p class="muted" style="text-align:center;font-size:13px;margin-bottom:16px">Purchase Shard packs to unlock subscriptions, premium features, and exclusive items.</p>

  <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:7px;padding:10px 14px;display:flex;align-items:center;gap:12px;margin-bottom:16px">
    <span style="font-size:22px">&#128274;</span>
    <div>
      <div style="font-weight:bold;font-size:13px;color:var(--neon2)">Payment Processing — Coming Soon</div>
      <div style="font-size:12px;color:var(--muted);margin-top:2px">Real-money purchases are not yet active. Contact staff or crack the daily Vault to earn Shards for now.</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">
    <?php foreach ($SHARD_PACKAGES as $pkg): ?>
    <div class="ex-pack<?= $pkg['popular'] ? ' popular' : '' ?>">
      <?php if ($pkg['popular']): ?>
        <div style="position:absolute;top:-1px;left:50%;transform:translate(-50%,-50%);background:var(--accent);color:#000;border-radius:20px;padding:2px 10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;letter-spacing:.5px;white-space:nowrap;z-index:1">BEST VALUE</div>
      <?php endif; ?>
      <div class="ex-shard-ic" style="font-size:26px;margin-bottom:6px;color:var(--accent);text-shadow:0 0 12px rgba(25,240,199,.5)">&#9670;</div>
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:var(--accent)"><?= number_format($pkg['shards']) ?></div>
      <?php if ($pkg['bonus'] > 0): ?>
        <div style="font-size:11px;color:#3bcf63;font-weight:bold;margin-top:2px">+<?= $pkg['bonus'] ?> bonus!</div>
      <?php endif; ?>
      <div style="font-size:16px;font-weight:bold;color:var(--text);margin-top:8px"><?= $pkg['price'] ?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:2px">
        <?php $total = $pkg['shards'] + $pkg['bonus']; ?>
        <?= $total ?> total &mdash; <?= number_format($total > 0 ? (float)ltrim($pkg['price'],'$') / $total * 100 : 0, 1) ?>&cent;/shard
      </div>
      <button disabled style="width:100%;margin-top:10px;opacity:.4;font-size:12px">Coming Soon</button>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:14px;margin-top:16px">
    <h3 style="margin:0 0 10px;font-size:13px;color:var(--neon2)">FAQ</h3>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:12px">
      <div><b style="color:var(--text)">Are Shards refundable?</b><br><span class="muted">No. All Shard purchases are final.</span></div>
      <div><b style="color:var(--text)">Do Shards expire?</b><br><span class="muted">No. Shards stay on your account indefinitely.</span></div>
      <div><b style="color:var(--text)">Can I trade Shards?</b><br><span class="muted">Shards cannot be traded between players.</span></div>
      <div><b style="color:var(--text)">How else can I get Shards?</b><br><span class="muted">Crack the daily Vault for 0&ndash;6 Shards per day (0&ndash;10 for subscribers).</span></div>
    </div>
  </div>
</div>

<!-- ===================== SUBSCRIPTION ===================== -->
<div class="panel">
  <h3 style="margin-top:0;text-align:center;color:#e8d44d">&#9733; Subscriber Benefits</h3>
  <?php if ($sub): ?>
    <div style="background:rgba(59,207,99,.06);border:1px solid rgba(59,207,99,.3);border-radius:7px;padding:10px 14px;text-align:center;margin-bottom:14px">
      <b style="color:#3bcf63">&#10003; You are subscribed</b> &mdash; active until <b><?= e($player['sub_until']) ?></b>
      <br><span class="muted" style="font-size:12px">Purchase again to extend. Days stack on your current sub.</span>
    </div>
  <?php endif; ?>

  <div style="background:rgba(232,212,77,.06);border:1px solid rgba(232,212,77,.2);border-radius:7px;padding:14px;margin-bottom:14px">
    <div style="font-weight:bold;font-size:13px;color:#e8d44d;text-align:center;margin-bottom:10px">Subscriber Perks</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px">
      <?php foreach ($SUB_PERKS as $pi => $perk): ?>
        <div class="ex-perk" style="animation-delay:<?= $pi * 60 ?>ms">
          <span style="color:#3bcf63;flex:none">&#10003;</span>
          <span><?= $perk ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <?php
    $plans = [
      ['action'=>'subscribe30', 'label'=>'30 Days', 'cost'=>SUB_COST_30, 'days'=>SUB_DAYS_30, 'badge'=>''],
      ['action'=>'subscribe90', 'label'=>'90 Days', 'cost'=>SUB_COST_90, 'days'=>SUB_DAYS_90, 'badge'=>'Best Value'],
    ];
    foreach ($plans as $plan):
      $perDay = round($plan['cost'] / $plan['days'], 1);
      $canBuy = (int)$player['shards'] >= $plan['cost'];
    ?>
    <div class="ex-plan<?= $plan['badge'] ? ' gold' : '' ?>">
      <?php if ($plan['badge']): ?>
        <div style="position:absolute;top:-1px;left:50%;transform:translate(-50%,-50%);background:rgba(232,212,77,.2);color:#e8d44d;border:1px solid rgba(232,212,77,.5);border-radius:20px;padding:2px 10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;letter-spacing:.5px;white-space:nowrap;backdrop-filter:blur(2px)"><?= e($plan['badge']) ?></div>
      <?php endif; ?>
      <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:var(--text)"><?= $plan['label'] ?></div>
      <div style="font-family:'Orbitron',sans-serif;font-size:24px;font-weight:700;color:#e8d44d;margin:8px 0">&#9670; <?= $plan['cost'] ?></div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:12px">(<?= $perDay ?> &#9670; / day)</div>
      <form method="post" style="margin:0" <?= $canBuy ? 'data-exfx="sub" data-ex-days="'.$plan['days'].'"' : '' ?>>
        <input type="hidden" name="action" value="<?= $plan['action'] ?>">
        <button type="submit" <?= $canBuy ? '' : 'disabled' ?> style="width:100%;<?= !$canBuy ? 'opacity:.4' : 'border-color:rgba(232,212,77,.5);color:#e8d44d' ?>">
          <?= $sub ? 'Extend' : 'Subscribe' ?> &mdash; <?= $plan['cost'] ?> &#9670;
        </button>
      </form>
      <?php if (!$canBuy): ?><p class="muted" style="font-size:11px;margin:6px 0 0">You have <?= number_format($player['shards']) ?> &#9670; — need <?= $plan['cost'] - (int)$player['shards'] ?> more.</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
'use strict';

/* ── Shard rain header ────────────────────────────────────────────────── */
var ec=document.getElementById('ex-canvas');
if(ec){
  var c=ec.getContext('2d');
  var EW=560, EH=116;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  ec.width=EW*dpr; ec.height=EH*dpr;
  c.scale(dpr,dpr);
  var shards=[];
  for(var i=0;i<16;i++) shards.push({
    x:Math.random()*EW, y:Math.random()*EH,
    s:3+Math.random()*7, r:Math.random()*Math.PI,
    vr:.004+Math.random()*.01, vy:.06+Math.random()*.16,
    col:Math.random()<.25?'#e8d44d':'#19f0c7', p:Math.random()*9
  });
  function diamond(x,y,s,r){
    c.save(); c.translate(x,y); c.rotate(r);
    c.beginPath(); c.moveTo(0,-s); c.lineTo(s*.62,0); c.lineTo(0,s); c.lineTo(-s*.62,0); c.closePath();
    c.restore();
  }
  function exLoop(t){
    if(!document.body.contains(ec)) return;
    requestAnimationFrame(exLoop);
    c.clearRect(0,0,EW,EH);
    var bg=c.createLinearGradient(0,0,0,EH);
    bg.addColorStop(0,'#0a0a16'); bg.addColorStop(1,'#100c1a');
    c.fillStyle=bg; c.fillRect(0,0,EW,EH);
    // soft center bloom
    var bloom=c.createRadialGradient(EW/2,EH*.4,10,EW/2,EH*.4,EW*.45);
    bloom.addColorStop(0,'rgba(25,240,199,.06)'); bloom.addColorStop(1,'rgba(25,240,199,0)');
    c.fillStyle=bloom; c.fillRect(0,0,EW,EH);
    for(var si=0;si<shards.length;si++){
      var S=shards[si];
      S.y+=S.vy; S.r+=S.vr;
      if(S.y>EH+10){ S.y=-10; S.x=Math.random()*EW; }
      var tw=.45+.45*Math.sin(t/600+S.p);
      // facet shimmer: width modulated by rotation for a "spinning gem" feel
      c.save(); c.translate(S.x,S.y); c.rotate(S.r);
      c.scale(Math.abs(Math.sin(t/900+S.p))*.7+.3,1);
      c.beginPath(); c.moveTo(0,-S.s); c.lineTo(S.s*.62,0); c.lineTo(0,S.s); c.lineTo(-S.s*.62,0); c.closePath();
      c.restore();
      c.shadowColor=S.col; c.shadowBlur=8*tw;
      c.fillStyle=S.col; c.globalAlpha=.28+.4*tw;
      c.fill();
      c.shadowBlur=0;
    }
    c.globalAlpha=1;
    // occasional sparkle
    if(Math.random()<.12){
      c.fillStyle='rgba(255,255,255,.85)';
      c.fillRect(Math.random()*EW,Math.random()*EH,1.6,1.6);
    }
  }
  requestAnimationFrame(exLoop);
}

/* ── Sound kit ────────────────────────────────────────────────────────── */
var ac=null, muted=localStorage.getItem('exchMuted')==='1';
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
window.toggleExSound=function(){
  muted=!muted; localStorage.setItem('exchMuted',muted?'1':'0');
  var b=document.getElementById('ex-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
  if(!muted) sfx(660,.08,'sine',.05);
};
(function(){ var b=document.getElementById('ex-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; })();

/* ── The Vault ────────────────────────────────────────────────────────── */
var vc=document.getElementById('vault-canvas');
if(vc){
  var v=vc.getContext('2d');
  var VW=220, VH=220, CX=VW/2, CY=VH/2;
  var vdpr=Math.min(2,window.devicePixelRatio||1);
  vc.width=VW*vdpr; vc.height=VH*vdpr;
  vc.style.width=VW+'px';
  v.scale(vdpr,vdpr);

  // phase: 'locked' | 'idle' | 'cracking' | 'opening' | 'open'
  var phase=vc.dataset.pulled==='1'?'locked':'idle';
  var dial=0;             // current dial angle
  var crackT0=0;          // crack start time
  var openT0=0;           // door-open start time
  var doorX=0;            // door slide offset
  var got=0, gotShown=false;
  var vparts=[];          // shard burst particles
  var lastTick=0;
  var CRACK_MS=2100;
  // spin plan: three turns, alternating direction (cumulative target angles)
  var SPINS=[Math.PI*4,-Math.PI*3,Math.PI*2.5];

  function ease(t){ return t<.5?2*t*t:1-Math.pow(-2*t+2,2)/2; }

  function vaultDraw(t){
    v.clearRect(0,0,VW,VH);

    // interior (revealed as the door slides)
    var glow=phase==='open'||phase==='opening';
    v.fillStyle='#060610';
    v.beginPath(); v.arc(CX,CY,86,0,Math.PI*2); v.fill();
    if(glow){
      var ig=v.createRadialGradient(CX,CY,4,CX,CY,86);
      var amt=got>0?.5:.12;
      ig.addColorStop(0,'rgba(25,240,199,'+amt+')');
      ig.addColorStop(1,'rgba(25,240,199,0)');
      v.fillStyle=ig;
      v.beginPath(); v.arc(CX,CY,86,0,Math.PI*2); v.fill();
      if(got>0&&phase==='open'){
        v.font='700 30px Orbitron, monospace'; v.textAlign='center'; v.textBaseline='middle';
        v.shadowColor='#19f0c7'; v.shadowBlur=16;
        v.fillStyle='#19f0c7';
        v.fillText('+'+got+' ◆',CX,CY);
        v.shadowBlur=0;
      } else if(got===0&&phase==='open'){
        v.font='700 15px Orbitron, monospace'; v.textAlign='center'; v.textBaseline='middle';
        v.fillStyle='rgba(255,255,255,.35)';
        v.fillText('EMPTY',CX,CY);
      }
    }

    // door (slides right while opening)
    v.save();
    v.beginPath(); v.rect(doorX-110+CX,0,VW,VH); v.clip(); // hide door part that slid past frame
    v.translate(doorX,0);

    // door body
    var dg=v.createRadialGradient(CX-20,CY-24,10,CX,CY,92);
    dg.addColorStop(0,'#2b2b3f'); dg.addColorStop(.7,'#181826'); dg.addColorStop(1,'#101018');
    v.fillStyle=dg;
    v.beginPath(); v.arc(CX,CY,86,0,Math.PI*2); v.fill();
    v.strokeStyle='rgba(255,255,255,.14)'; v.lineWidth=2;
    v.beginPath(); v.arc(CX,CY,86,0,Math.PI*2); v.stroke();

    // rim bolts
    for(var b=0;b<10;b++){
      var ba=b*Math.PI/5+.3;
      v.fillStyle='#3a3a52';
      v.beginPath(); v.arc(CX+Math.cos(ba)*74,CY+Math.sin(ba)*74,3.4,0,Math.PI*2); v.fill();
      v.fillStyle='rgba(255,255,255,.2)';
      v.beginPath(); v.arc(CX+Math.cos(ba)*74-1,CY+Math.sin(ba)*74-1,1.1,0,Math.PI*2); v.fill();
    }

    // combination dial
    var idleSway=phase==='idle'?Math.sin(t/1400)*.05:0;
    var a=dial+idleSway;
    v.save(); v.translate(CX,CY); v.rotate(a);
    v.fillStyle='#10101c';
    v.beginPath(); v.arc(0,0,46,0,Math.PI*2); v.fill();
    v.strokeStyle='rgba(255,255,255,.18)';
    v.beginPath(); v.arc(0,0,46,0,Math.PI*2); v.stroke();
    for(var tk=0;tk<24;tk++){
      var tka=tk*Math.PI/12;
      var len=tk%6===0?9:5;
      v.strokeStyle=tk%6===0?'rgba(25,240,199,.7)':'rgba(255,255,255,.3)';
      v.beginPath();
      v.moveTo(Math.cos(tka)*44,Math.sin(tka)*44);
      v.lineTo(Math.cos(tka)*(44-len),Math.sin(tka)*(44-len));
      v.stroke();
    }
    // 3-spoke wheel handle
    v.strokeStyle='#494962'; v.lineWidth=6; v.lineCap='round';
    for(var sp=0;sp<3;sp++){
      var spa=sp*Math.PI*2/3;
      v.beginPath(); v.moveTo(0,0); v.lineTo(Math.cos(spa)*32,Math.sin(spa)*32); v.stroke();
    }
    v.lineWidth=1; v.lineCap='butt';
    v.fillStyle='#5a5a78';
    v.beginPath(); v.arc(0,0,9,0,Math.PI*2); v.fill();
    v.fillStyle='rgba(255,255,255,.25)';
    v.beginPath(); v.arc(-2.5,-2.5,3,0,Math.PI*2); v.fill();
    v.restore();

    // pointer notch at 12 o'clock
    v.fillStyle='#19f0c7';
    v.beginPath(); v.moveTo(CX,CY-56); v.lineTo(CX-4,CY-48); v.lineTo(CX+4,CY-48); v.closePath(); v.fill();

    // locked overlay
    if(phase==='locked'){
      v.fillStyle='rgba(4,4,10,.55)';
      v.beginPath(); v.arc(CX,CY,86,0,Math.PI*2); v.fill();
      v.font='700 13px Orbitron, monospace'; v.textAlign='center'; v.textBaseline='middle';
      v.fillStyle='rgba(255,255,255,.5)';
      v.fillText('🔒 SEALED',CX,CY);
    }
    v.restore();

    // idle hint shimmer
    if(phase==='idle'){
      var hp=.4+.3*Math.sin(t/500);
      v.strokeStyle='rgba(25,240,199,'+hp+')'; v.lineWidth=1.5;
      v.beginPath(); v.arc(CX,CY,92,0,Math.PI*2); v.stroke();
      v.lineWidth=1;
    }

    // shard burst particles
    for(var pi=vparts.length-1;pi>=0;pi--){
      var P=vparts[pi];
      P.x+=P.vx; P.y+=P.vy; P.vy+=.08; P.r+=P.vr; P.life-=.012;
      if(P.life<=0){vparts.splice(pi,1);continue;}
      v.globalAlpha=Math.max(0,P.life);
      v.save(); v.translate(P.x,P.y); v.rotate(P.r);
      v.fillStyle=P.col;
      v.beginPath(); v.moveTo(0,-P.s); v.lineTo(P.s*.62,0); v.lineTo(0,P.s); v.lineTo(-P.s*.62,0); v.closePath(); v.fill();
      v.restore();
    }
    v.globalAlpha=1;
  }

  function vaultLoop(t){
    if(!document.body.contains(vc)) return;
    requestAnimationFrame(vaultLoop);

    if(phase==='cracking'){
      var el=t-crackT0;
      var per=CRACK_MS/SPINS.length;
      var seg=Math.min(SPINS.length-1,Math.floor(el/per));
      var segT=Math.min(1,(el-seg*per)/per);
      var base=0;
      for(var i2=0;i2<seg;i2++) base+=SPINS[i2];
      dial=base+SPINS[seg]*ease(segT);
      if(t-lastTick>90){ lastTick=t; sfx(900+Math.random()*500,.025,'square',.018); }
      if(el>=CRACK_MS){
        phase='opening'; openT0=t;
        sfx(140,.22,'square',.06); // heavy bolt release
        setTimeout(function(){ sfx(70,.3,'sine',.05); },150);
      }
    } else if(phase==='opening'){
      var ot=Math.min(1,(t-openT0)/700);
      doorX=ease(ot)*150;
      if(ot>=1){
        phase='open'; gotShown=true;
        if(got>0){
          sfx(659,.1,'sine',.05); setTimeout(function(){sfx(880,.1,'sine',.05);},90); setTimeout(function(){sfx(1175,.18,'sine',.05);},180);
          for(var n=0;n<Math.min(26,6+got*2);n++){
            var an=Math.random()*Math.PI*2, spd=1+Math.random()*2.6;
            vparts.push({x:CX,y:CY,vx:Math.cos(an)*spd,vy:Math.sin(an)*spd-1.8,
              s:3+Math.random()*4,r:Math.random()*Math.PI,vr:(Math.random()-.5)*.3,
              col:Math.random()<.2?'#e8d44d':'#19f0c7',life:1.1});
          }
          var st=document.getElementById('vault-status');
          if(st){ st.style.color='#19f0c7'; st.textContent='+'+got+' Shard'+(got===1?'':'s')+' secured. Vault resets at midnight.'; }
          var hs=document.getElementById('ex-shards');
          if(hs&&window._exNewTotal!==undefined) hs.textContent=Number(window._exNewTotal).toLocaleString('en-US');
          if(window.refreshState) window.refreshState();
        } else {
          sfx(180,.25,'sine',.04,90);
          var st2=document.getElementById('vault-status');
          if(st2){ st2.style.color='var(--muted)'; st2.textContent='Empty today. The Grid giveth and the Grid taketh. Back tomorrow.'; }
        }
      }
    }
    vaultDraw(t);
  }
  requestAnimationFrame(vaultLoop);

  var vbusy=false;
  vc.addEventListener('click',function(){
    if(phase!=='idle'||vbusy) return;
    vbusy=true;
    var st=document.getElementById('vault-status');
    if(st){ st.style.color='var(--muted)'; st.textContent='Cracking...'; }
    var fd=new FormData();
    fd.append('exch_ajax','1'); fd.append('exch_action','pull');
    fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        vbusy=false;
        if(!d.ok){
          phase='locked';
          if(st){ st.style.color='var(--neon2)'; st.textContent=d.err||'Error'; }
          return;
        }
        got=d.got; window._exNewTotal=d.shards;
        phase='cracking'; crackT0=performance.now(); lastTick=0;
      })
      .catch(function(){
        vbusy=false;
        if(st){ st.style.color='var(--neon2)'; st.textContent='Network error — try again.'; }
      });
  });
  vc.addEventListener('mousemove',function(){
    vc.style.cursor=phase==='idle'?'pointer':'default';
  });
}
})();
</script>

<script>
/* Subscribe FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._exFxBound) return;
  window._exFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#exfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(6,5,2,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#exfx.show{opacity:1}'
    +'.exfx-star{position:relative;font-size:64px;color:#e8d44d;text-shadow:0 0 24px #e8d44d,0 0 60px rgba(232,212,77,.6);'
    +'animation:exfxStar .55s cubic-bezier(.2,1.7,.4,1)}'
    +'@keyframes exfxStar{0%{transform:scale(0) rotate(-160deg);opacity:0}100%{transform:scale(1) rotate(0);opacity:1}}'
    +'.exfx-ring{position:absolute;left:50%;top:50%;width:10px;height:10px;border:2px solid #e8d44d;border-radius:50%;'
    +'transform:translate(-50%,-50%);animation:exfxRing .8s .1s ease-out forwards;opacity:.9}'
    +'@keyframes exfxRing{to{width:260px;height:260px;opacity:0}}'
    +'.exfx-label{position:absolute;left:50%;top:calc(50% + 56px);transform:translateX(-50%);white-space:nowrap;'
    +'font-size:14px;font-weight:700;letter-spacing:.12em;color:#e8d44d;text-shadow:0 0 12px #e8d44d;'
    +'opacity:0;animation:exfxLbl .3s .45s forwards}'
    +'@keyframes exfxLbl{to{opacity:1}}';
  document.head.appendChild(css);

  var ac=null;
  function sfx(freq,dur,vol){
    if(localStorage.getItem('exchMuted')==='1') return;
    try{
      ac=ac||new (window.AudioContext||window.webkitAudioContext)();
      var o=ac.createOscillator(),g=ac.createGain();
      o.type='sine'; o.frequency.value=freq;
      g.gain.value=vol||.05;
      g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+dur);
      o.connect(g); g.connect(ac.destination);
      o.start(); o.stop(ac.currentTime+dur);
    }catch(e){}
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute||f.getAttribute('data-exfx')!=='sub') return;
    var days=f.getAttribute('data-ex-days')||'30';
    var old=document.getElementById('exfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='exfx';
    o.innerHTML='<div class="exfx-star">&#9733;<div class="exfx-ring"></div>'
      +'<div class="exfx-label">SUBSCRIPTION +'+days+' DAYS</div></div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    sfx(523,.12,.05); setTimeout(function(){sfx(659,.12,.05);},110);
    setTimeout(function(){sfx(784,.12,.05);},220); setTimeout(function(){sfx(1047,.25,.05);},330);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},1900);
  },true);
})();
</script>
