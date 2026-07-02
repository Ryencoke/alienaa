<?php /* pages/thenet.php — The Net: Grid Dive (Netrunning intrusion runs).
   The first real home for the Netrunning and Cryptocracking skills: a
   corporate grid rendered as a layered node graph — crack caches, crypto
   vaults (tier-gated by Cryptocracking), and the CORE while ICE sentinels
   ping-pong fixed walks and the TRACE clock climbs on every action. Loot
   only banks at an exit port; a panic eject keeps 40%; a completed trace
   dumps you (all unbanked loot gone) and burns Signal. Pure rules live in
   net_engine.php (headless-tested); this page wires Drive charges, Signal
   damage, and payouts. Same session/AJAX architecture as mining/transit/sim. */
require_once __DIR__ . '/../net_engine.php';

$pid = $_SESSION['pid'];
$pdo = db();

$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);
$skq = $pdo->prepare("SELECT s.code, ps.points FROM skills s JOIN player_skills ps ON ps.skill_id = s.id AND ps.player_id = ?");
$skq->execute([$pid]);
$skillPts = [];
foreach ($skq as $row) $skillPts[$row['code']] = (int)$row['points'];
$hackLv   = intdiv($skillPts['hack'] ?? 0, 100);
$netrunLv = intdiv($skillPts['netrun'] ?? 0, 100);

/* ── Dive AJAX ──────────────────────────────────────────────────────────── */
if (!empty($_POST['nd_ajax'])) {
  header('Content-Type: application/json');
  $act = $_POST['nd_action'] ?? '';
  $dive = $_SESSION['net_dive'] ?? null;
  if ($dive && (($dive['v'] ?? 0) !== 1)) { $dive = null; unset($_SESSION['net_dive']); }
  try {
    $pl = current_player(); $plid = (int)$pl['id'];

    if ($act === 'start') {
      if ($dive) { // resume instead of double-charging
        echo json_encode(['ok'=>true,'state'=>nd_to_client($dive),'drive'=>(int)$pl['cycles']]); exit;
      }
      // Clamp the requested depth to what Netrunning unlocks (nd_gen re-clamps
      // too, but read the real cost off the clamped tier so the Drive charge
      // and the generated grid always agree).
      $reqTier = max(1, min(3, (int)($_POST['tier'] ?? 1)));
      $tier    = min($reqTier, net_max_tier($netrunLv));
      $cost    = net_tier_cfg($tier)['drive'];
      $dc = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $dc->execute([$cost, $plid, $cost]);
      if ($dc->rowCount() !== 1) throw new RuntimeException('Need ' . $cost . ' Drive to jack in at that depth.');
      $dive = nd_gen($hackLv, $netrunLv, $tier);
      if (!$dive) { // vanishingly rare: refund and bail cleanly
        $pdo->prepare('UPDATE players SET cycles = LEAST(cycles_max, cycles + ?) WHERE id = ?')->execute([$cost, $plid]);
        throw new RuntimeException('The grid rejected the handshake — Drive refunded, try again.');
      }
      $_SESSION['net_dive'] = $dive;
      $pl = current_player();
      echo json_encode(['ok'=>true,'state'=>nd_to_client($dive),'drive'=>(int)$pl['cycles']]); exit;
    }

    if (!$dive) throw new RuntimeException('No active dive.');

    if ($act === 'act') {
      $a = $_POST['a'] ?? '';
      $arg = (int)($_POST['node'] ?? -1);
      $r = nd_step($dive, $a, $arg);
      if (!$r['ok']) { echo json_encode(['ok'=>false,'err'=>$r['err']]); exit; }
      $st = $r['st'];

      if (!$st['over']) {
        $_SESSION['net_dive'] = $st;
        echo json_encode(['ok'=>true,'events'=>$r['events'],'state'=>nd_to_client($st)]); exit;
      }

      // ── Settle. Session keeps the PRE-action dive until the commit lands,
      // so a failed settle can simply be retried — and payouts can never
      // double-grant because the dive leaves the session in the same request.
      $outcome = $st['outcome']; // banked | ejected | dumped
      $creds = (int)$st['loot'];
      $xp = 0; $shard = 0; $sigLoss = 0;
      if ($outcome === 'banked' || $outcome === 'ejected') $xp = nd_xp($st);
      if ($outcome === 'ejected') $sigLoss = ND_SIGNAL_EJECT;
      if ($outcome === 'dumped')  $sigLoss = ND_SIGNAL_DUMP;
      // The core's shard bounty only pays if you walked it OUT (banked).
      if ($outcome === 'banked' && $st['cracked']['core'] > 0 && random_int(1, 100) <= nd_shard_pct($st)) $shard = 1;

      $pdo->beginTransaction();
      if ($creds > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$creds, $plid]);
      if ($shard > 0) $pdo->prepare('UPDATE players SET shards = shards + ? WHERE id = ?')->execute([$shard, $plid]);
      if ($sigLoss > 0) $pdo->prepare('UPDATE players SET `signal` = GREATEST(0, `signal` - ?) WHERE id = ?')->execute([$sigLoss, $plid]);
      $pdo->commit();
      if ($xp > 0) { try { grant_xp($plid, $xp); } catch (Throwable $e) {} }
      // Contracts: any settled dive counts as a run; a banked core also ticks
      // the core-raid contract (dumped dives — loot lost — count for neither).
      if ($outcome === 'banked' || $outcome === 'ejected') {
        contract_record($pdo, $plid, 'net_dive');
        if ($outcome === 'banked' && $st['cracked']['core'] > 0) contract_record($pdo, $plid, 'net_core');
      }
      unset($_SESSION['net_dive']);
      $pl = current_player();
      echo json_encode(['ok'=>true,'events'=>$r['events'],'state'=>nd_to_client($st),
        'settle'=>[
          'outcome'=>$outcome,'creds'=>$creds,'xp'=>$xp,'shard'=>$shard,
          'signal_lost'=>$sigLoss,'signal'=>(int)$pl['signal'],
          'cracked'=>$st['cracked'],'corp'=>$st['corp'],
        ]]); exit;
    }

    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

$activeDive = $_SESSION['net_dive'] ?? null;
if ($activeDive && (($activeDive['v'] ?? 0) !== 1)) { unset($_SESSION['net_dive']); $activeDive = null; }
$maxTier = net_max_tier($netrunLv);
$ndClientCfg = [
  'state' => $activeDive ? nd_to_client($activeDive) : null,
  'drive' => (int)$player['cycles'],
  'maxTier' => $maxTier,
];
// Per-tier display data for the lobby picker.
$tierCards = [];
foreach (NET_TIERS as $t => $c) {
  $vmin = $c['vault'][1][0]; $vmax = end($c['vault'])[1];
  $tierCards[$t] = [
    'name' => $c['name'], 'req' => $c['req'], 'drive' => $c['drive'], 'trace' => $c['trace'],
    'ice' => $c['ice'], 'core' => $c['core'], 'vlo' => $vmin, 'vhi' => $vmax,
    'maxVaultTier' => count($c['vault']),
    'unlocked' => $t <= $maxTier, 'afford' => (int)$player['cycles'] >= $c['drive'],
  ];
}
?>
<style>
#nd-head h2{text-shadow:0 0 14px rgba(157,107,255,.45)}
#nd-headcv{display:block;width:100%;height:96px;border-radius:9px 9px 0 0}
.nd-chip{display:inline-flex;flex-direction:column;align-items:center;padding:5px 13px;background:rgba(6,6,14,.78);border:1px solid var(--line);border-radius:8px;backdrop-filter:blur(3px)}
.nd-chip b{font-family:'Orbitron',sans-serif;font-size:14px}
.nd-chip span{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:1px}
#nd-canvas{display:block;background:#04040c;border-radius:10px;border:1px solid rgba(157,107,255,.25);box-shadow:0 0 30px rgba(157,107,255,.06),inset 0 0 60px rgba(0,0,0,.6);max-width:100%;height:auto;cursor:pointer}
.nd-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:var(--text);border-radius:6px;padding:8px 14px;cursor:pointer;font-size:12px;line-height:1.3;transition:background .1s,transform .06s}
.nd-btn:active:not(:disabled){background:rgba(157,107,255,.18);border-color:#9d6bff;transform:scale(.95)}
.nd-btn:disabled{opacity:.38;cursor:not-allowed}
#nd-trace-track{height:12px;border-radius:6px;background:#080812;overflow:hidden}
#nd-trace{height:100%;border-radius:6px;background:linear-gradient(90deg,#3bcf63,#e8a33d,#ff2d95);transition:width .35s ease}
#nd-feed{max-height:120px;overflow-y:auto;font-size:12px;display:flex;flex-direction:column;gap:3px}
#nd-feed div{animation:ndIn .25s ease-out backwards}
@keyframes ndIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1}}
.nd-hud{display:inline-flex;flex-direction:column;align-items:center;padding:4px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:7px;min-width:60px}
.nd-hud b{font-family:'Orbitron',sans-serif;font-size:14px}
.nd-hud span{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em}
</style>

<div class="panel" id="nd-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="nd-headcv"></canvas>
    <div style="position:absolute;left:16px;top:10px;pointer-events:none">
      <h2 style="margin:0">&#128225; The Net</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Grid dives against corporate ICE. The trace never stops climbing.</p>
    </div>
    <div style="position:absolute;right:14px;top:12px;display:flex;gap:8px">
      <div class="nd-chip"><b id="nd-sig-chip" style="color:#4de8e8"><?= (int)$player['signal'] ?></b><span>Signal</span></div>
      <div class="nd-chip"><b id="nd-drive-chip" style="color:#e8a33d"><?= (int)$player['cycles'] ?></b><span>Drive</span></div>
      <div class="nd-chip"><b style="color:#a66de8"><?= $netrunLv ?></b><span>Netrun</span></div>
      <div class="nd-chip"><b style="color:#4de8b8"><?= $hackLv ?></b><span>Crypto</span></div>
    </div>
  </div>
</div>

<!-- ══ Lobby ══ -->
<div id="nd-lobby" <?= $activeDive ? 'style="display:none"' : '' ?>>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line)">
    <div style="font-size:13px;font-weight:700">&#128273; Grid Dive</div>
    <div style="font-size:11px;color:var(--muted);margin-top:1px">
      Route through a corporate grid, crack what you can, and get OUT. Every action feeds the trace &mdash; hit the ceiling and you're dumped
      with nothing and the burn hits your Signal. Loot only banks at an <b style="color:#3bcf63">exit port</b>. Deeper grids pay more and bite harder.
    </div>
  </div>
  <div style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;font-size:12px">
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:10px 12px">
      <b style="color:#e8a33d">&#128190; Caches</b> <span class="muted">quick 1-turn credit grabs.</span><br>
      <b style="color:#9d6bff">&#128274; Crypto Vaults</b> <span class="muted">2 turns, tier-gated by <b style="color:#4de8b8">Cryptocracking</b> (yours: level <?= $hackLv ?>; +4%/level yield) &mdash; deeper grids hold higher-tier vaults.</span><br>
      <b style="color:#ff2d95">&#9679; The Core</b> <span class="muted">3 turns, deep in the grid &mdash; bank it for a <?= min(55, 35 + 2 * min(10, $hackLv)) ?>% Shard bounty.</span>
    </div>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:10px 12px">
      <b style="color:#e23b3b">&#9650; ICE sentinels</b> <span class="muted">patrol fixed walks, one step per action of yours. A clash costs 25% of your unbanked loot and +25 trace.</span><br>
      <b style="color:#4de8e8">&#127787; Cloak</b> <span class="muted">lets ICE pass through you for one turn &mdash; <b><?= min(4, 1 + intdiv($netrunLv, 2)) ?> charge<?= min(4, 1 + intdiv($netrunLv, 2)) === 1 ? '' : 's' ?></b> per dive from <b style="color:#a66de8">Netrunning</b>.</span><br>
      <b style="color:#e8a33d">&#9889; Eject</b> <span class="muted">anywhere keeps 40% of loot but stings your Signal.</span>
    </div>
  </div>
  <div style="padding:4px 16px 6px;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em">Choose a dive depth</div>
  <div style="padding:0 16px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px">
    <?php foreach ($tierCards as $t => $tc):
      $tcol = [1=>'#4de8e8', 2=>'#9d6bff', 3=>'#ff2d95'][$t];
    ?>
    <div style="position:relative;background:var(--panel2);border:1px solid <?= $tc['unlocked'] ? $tcol.'55' : 'var(--line)' ?>;border-radius:8px;padding:12px;<?= $tc['unlocked'] ? '' : 'opacity:.55' ?>">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <div style="font-weight:700;font-size:13px;color:<?= $tcol ?>">T<?= $t ?> &middot; <?= e($tc['name']) ?></div>
        <span style="font-size:10px;color:var(--muted)">Netrun <?= $tc['req'] ?>+</span>
      </div>
      <div style="font-size:11px;color:var(--muted);line-height:1.7;margin-bottom:10px">
        Core <b style="color:#ff2d95"><?= number_format($tc['core'][0]) ?>&ndash;<?= number_format($tc['core'][1]) ?> cr</b> &middot;
        vaults to <b style="color:#9d6bff">T<?= $tc['maxVaultTier'] ?></b> (need Crypto <?= $tc['maxVaultTier'] ?>)<br>
        <b style="color:#e23b3b"><?= $tc['ice'][0] ?>&ndash;<?= $tc['ice'][1] ?> ICE</b> &middot;
        trace ceiling <b><?= $tc['trace'] ?></b> &middot;
        entry <b style="color:#e8a33d"><?= $tc['drive'] ?> Drive</b>
      </div>
      <?php if (!$tc['unlocked']): ?>
        <div style="text-align:center;font-size:11px;color:var(--muted);font-style:italic;padding:6px;border:1px solid var(--line);border-radius:5px">Locked &mdash; needs Netrunning <?= $tc['req'] ?> (<a href="index.php?p=datacore&act=lab" style="color:#a66de8">train</a>)</div>
      <?php else: ?>
        <button type="button" class="nd-btn" onclick="ndStart(<?= $t ?>)" style="width:100%;background:<?= $tcol ?>1a;border-color:<?= $tcol ?>73;color:<?= $tcol ?>" <?= $tc['afford'] ? '' : 'disabled' ?>>
          <?= $tc['afford'] ? 'Jack In &mdash; '.$tc['drive'].' Drive' : 'Need '.$tc['drive'].' Drive' ?>
        </button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<!-- ══ Active dive ══ -->
<div id="nd-active" <?= $activeDive ? '' : 'style="display:none"' ?>>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <div style="font-size:13px;font-weight:700" id="nd-title">&#128225; Diving&hellip;</div>
      <div style="font-size:11px;color:var(--muted)" id="nd-sub">Click a linked node to move. Bank at a green port.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <div class="nd-hud"><b id="nd-loot" style="color:#3bcf63">0</b><span>Unbanked cr</span></div>
      <div class="nd-hud"><b id="nd-cloaks" style="color:#4de8e8">0</b><span>Cloaks</span></div>
      <div class="nd-hud"><b id="nd-moves" style="color:var(--text)">0</b><span>Actions</span></div>
    </div>
  </div>
  <div style="padding:12px 14px;text-align:center">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
      <span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;flex:none">Trace</span>
      <div id="nd-trace-track" style="flex:1"><div id="nd-trace" style="width:0%"></div></div>
      <b id="nd-trace-pct" style="font-family:'Orbitron',sans-serif;font-size:13px;color:#3bcf63;flex:none">0%</b>
    </div>
    <canvas id="nd-canvas" width="520" height="380"></canvas>
    <div id="nd-msg" style="min-height:20px;font-size:12px;margin-top:6px;transition:opacity .3s"></div>
    <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:6px">
      <button type="button" class="nd-btn" id="nd-crack-btn" onclick="ndAct('crack')" title="Crack the node you're on (C)">&#128273; Crack</button>
      <button type="button" class="nd-btn" id="nd-cloak-btn" onclick="ndAct('cloak')" title="ICE passes through you this turn (K)">&#127787; Cloak</button>
      <button type="button" class="nd-btn" onclick="ndAct('hold')" title="Wait a beat — patrols still tick (Space)">&#9208; Hold</button>
      <button type="button" class="nd-btn" id="nd-jack-btn" onclick="ndAct('jackout')" style="border-color:rgba(59,207,99,.35);color:#3bcf63" title="Bank everything — only at a port (J)">&#9989; Jack Out</button>
      <button type="button" class="nd-btn" onclick="ndEject()" style="border-color:rgba(255,45,149,.3);color:var(--neon2)" title="Panic exit — keep 40% (E)">&#9889; Eject</button>
    </div>
    <div id="nd-feed" style="background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:7px;padding:8px 10px;margin-top:10px;text-align:left"></div>
    <div style="font-size:10px;color:var(--muted);margin-top:7px">Click linked nodes to move &middot; C crack &middot; K cloak &middot; Space hold &middot; J jack out &middot; E eject &middot; red chevrons = ICE's next step</div>
  </div>
</div>
</div>
<noscript><div class="flash flash-err">The Net needs JavaScript.</div></noscript>

<script>
/* ══ The Net client ══ */
(function(){
'use strict';
var ND = <?= json_encode($ndClientCfg) ?>;

var canvas=document.getElementById('nd-canvas');
if(!canvas) return;
var ctx=canvas.getContext('2d');
var W=520,H=380;
var dpr=Math.min(2,window.devicePixelRatio||1);
canvas.width=W*dpr; canvas.height=H*dpr;
canvas.style.width='min(100%, '+W+'px)';
ctx.scale(dpr,dpr);

var state=ND.state||null;
var busy=false, ending=false, msgTimer=null;
var pAnim=null; // lerped player marker {x,y}
var iceAnims=[];
var pulses=[]; // {x,y,age,col} crack/clash ripples

/* sounds — tiny local kit, bound once */
if(!window._ndFxBound){
  window._ndFxBound=true;
  var ac=null;
  window.ndTone=function(f,d,ty,v,sl){
    try{
      ac=ac||new (window.AudioContext||window.webkitAudioContext)();
      var o=ac.createOscillator(),g=ac.createGain();
      o.type=ty||'sine'; o.frequency.value=f;
      if(sl) o.frequency.exponentialRampToValueAtTime(sl,ac.currentTime+d);
      g.gain.value=v||.045;
      g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+d);
      o.connect(g); g.connect(ac.destination);
      o.start(); o.stop(ac.currentTime+d);
    }catch(e){}
  };
}

function nodeXY(n){ return [46+n.layer*107, 190+n.y*74]; }
function nodeById(id){ for(var i=0;i<state.nodes.length;i++) if(state.nodes[i].id===id) return state.nodes[i]; return null; }

function showMsg(t,col){
  var el=document.getElementById('nd-msg'); if(!el) return;
  el.textContent=t; el.style.color=col||'var(--muted)'; el.style.opacity='1';
  if(msgTimer) clearTimeout(msgTimer);
  msgTimer=setTimeout(function(){el.style.opacity='0';},2600);
}
function feed(html,col){
  var f=document.getElementById('nd-feed'); if(!f) return;
  var d=document.createElement('div');
  d.innerHTML=html; if(col) d.style.color=col;
  f.insertBefore(d,f.firstChild);
  while(f.children.length>12) f.removeChild(f.lastChild);
}

function syncAnims(){
  if(!state) return;
  var p=nodeById(state.pos); var xy=nodeXY(p);
  pAnim={x:xy[0],y:xy[1]};
  iceAnims=[];
  for(var i=0;i<state.ice.length;i++){
    var c=nodeXY(nodeById(state.ice[i].at));
    iceAnims.push({x:c[0],y:c[1]});
  }
}
if(state) syncAnims();

function hud(){
  if(!state) return;
  document.getElementById('nd-title').innerHTML='&#128225; Dive: '+state.corp+' <span style="font-size:10px;color:var(--muted);font-weight:400">· T'+(state.tier||1)+' '+(state.tier_name||'')+'</span>';
  document.getElementById('nd-loot').textContent=Number(state.loot).toLocaleString('en-US');
  document.getElementById('nd-cloaks').textContent=state.cloaks;
  document.getElementById('nd-moves').textContent=state.moves;
  var pct=Math.round(state.trace/state.trace_max*100);
  document.getElementById('nd-trace').style.width=pct+'%';
  var tp=document.getElementById('nd-trace-pct');
  tp.textContent=pct+'%';
  tp.style.color=pct<50?'#3bcf63':(pct<80?'#e8a33d':'#ff2d95');
  var here=nodeById(state.pos);
  var crackable=here&&!here.cracked&&(here.type==='cache'||here.type==='vault'||here.type==='core')&&!(here.type==='vault'&&state.hack<here.tier);
  document.getElementById('nd-crack-btn').disabled=!crackable;
  document.getElementById('nd-cloak-btn').disabled=state.cloaks<=0;
  document.getElementById('nd-jack-btn').disabled=!(here&&here.port);
  var sub=document.getElementById('nd-sub');
  if(here&&here.port) sub.innerHTML='On an <b style="color:#3bcf63">exit port</b> — Jack Out banks '+Number(state.loot).toLocaleString('en-US')+' cr.';
  else if(crackable) sub.innerHTML='Crackable node — '+(here.need-here.prog)+' turn'+((here.need-here.prog)===1?'':'s')+' of work left.';
  else sub.textContent='Click a linked node to move. Bank at a green port.';
}

var TYPECOL={cache:'#e8a33d',vault:'#9d6bff',core:'#ff2d95',relay:'#5d6680',entry:'#19f0c7'};
function draw(t){
  if(!document.body.contains(canvas)) return;
  requestAnimationFrame(draw);
  ctx.clearRect(0,0,W,H);
  if(!state) return;

  // backdrop grid
  ctx.strokeStyle='rgba(157,107,255,.05)';
  for(var gx=0;gx<W;gx+=26){ ctx.beginPath(); ctx.moveTo(gx+.5,0); ctx.lineTo(gx+.5,H); ctx.stroke(); }
  for(var gy=0;gy<H;gy+=26){ ctx.beginPath(); ctx.moveTo(0,gy+.5); ctx.lineTo(W,gy+.5); ctx.stroke(); }

  // edges
  var here=nodeById(state.pos);
  for(var a=0;a<state.nodes.length;a++){
    var na=state.nodes[a], nbs=state.adj[na.id]||[];
    var axy=nodeXY(na);
    for(var b2=0;b2<nbs.length;b2++){
      if(nbs[b2]<na.id) continue; // draw each edge once
      var nb=nodeById(nbs[b2]); var bxy=nodeXY(nb);
      var linked=(na.id===state.pos||nb.id===state.pos);
      ctx.strokeStyle=linked?'rgba(25,240,199,.45)':'rgba(157,107,255,.18)';
      ctx.lineWidth=linked?2:1;
      ctx.beginPath(); ctx.moveTo(axy[0],axy[1]); ctx.lineTo(bxy[0],bxy[1]); ctx.stroke();
    }
  }
  ctx.lineWidth=1;

  // ICE patrol walks (dotted)
  ctx.save();
  ctx.setLineDash([3,5]);
  ctx.strokeStyle='rgba(226,59,59,.3)';
  ctx.lineWidth=1.6;
  for(var ii=0;ii<state.ice.length;ii++){
    var path=state.ice[ii].path;
    ctx.beginPath();
    for(var p2=0;p2<path.length;p2++){
      var pxy=nodeXY(nodeById(path[p2]));
      if(p2===0) ctx.moveTo(pxy[0],pxy[1]); else ctx.lineTo(pxy[0],pxy[1]);
    }
    ctx.stroke();
  }
  ctx.restore();

  // nodes
  ctx.textAlign='center';
  for(var i2=0;i2<state.nodes.length;i2++){
    var n=state.nodes[i2]; var xy=nodeXY(n);
    var col=TYPECOL[n.type]||'#5d6680';
    var locked=(n.type==='vault'&&state.hack<n.tier);
    var pulse=.5+.5*Math.sin(t/420+n.id);
    // port halo
    if(n.port){
      ctx.strokeStyle='rgba(59,207,99,'+(.4+.35*pulse)+')';
      ctx.beginPath(); ctx.arc(xy[0],xy[1],21,0,Math.PI*2); ctx.stroke();
    }
    // body
    ctx.fillStyle=n.cracked?'rgba(93,102,128,.15)':(col+'22');
    ctx.beginPath(); ctx.arc(xy[0],xy[1],16,0,Math.PI*2); ctx.fill();
    ctx.strokeStyle=n.cracked?'rgba(93,102,128,.4)':col;
    if(n.type==='core'&&!n.cracked){ ctx.shadowColor=col; ctx.shadowBlur=10+8*pulse; }
    ctx.beginPath(); ctx.arc(xy[0],xy[1],16,0,Math.PI*2); ctx.stroke();
    ctx.shadowBlur=0;
    // glyph
    ctx.fillStyle=n.cracked?'rgba(93,102,128,.6)':col;
    ctx.font='11px sans-serif';
    var glyph=n.type==='cache'?'▤':(n.type==='vault'?(locked?'🔒':'⬢'):(n.type==='core'?'◉':(n.type==='entry'?'IN':(n.port?'⬤':'·'))));
    ctx.fillText(n.cracked?'✓':glyph,xy[0],xy[1]+4);
    // value / tier tag
    if(!n.cracked&&n.val>0){
      ctx.font='bold 9px sans-serif';
      ctx.fillStyle=col;
      ctx.fillText(n.val+(n.type==='vault'?' ·T'+n.tier:''),xy[0],xy[1]-21);
    }
    if(!n.cracked&&n.prog>0){
      ctx.font='9px sans-serif'; ctx.fillStyle='#e8a33d';
      ctx.fillText(n.prog+'/'+n.need,xy[0],xy[1]+28);
    }
  }

  // ICE markers (lerped) + next-step chevrons
  for(var i3=0;i3<state.ice.length;i3++){
    var ic=state.ice[i3];
    var cur=nodeXY(nodeById(ic.at));
    var ia=iceAnims[i3]||{x:cur[0],y:cur[1]};
    ia.x+=(cur[0]-ia.x)*.25; ia.y+=(cur[1]-ia.y)*.25;
    iceAnims[i3]=ia;
    var nx=nodeXY(nodeById(ic.next));
    ctx.fillStyle='rgba(226,59,59,.55)';
    ctx.beginPath(); ctx.arc(nx[0],nx[1],3.5,0,Math.PI*2); ctx.fill();
    ctx.save();
    ctx.translate(ia.x,ia.y-24);
    ctx.fillStyle='#e23b3b';
    ctx.shadowColor='#e23b3b'; ctx.shadowBlur=8;
    ctx.beginPath(); ctx.moveTo(0,-7); ctx.lineTo(6,4); ctx.lineTo(-6,4); ctx.closePath(); ctx.fill();
    ctx.restore();
    ctx.shadowBlur=0;
  }

  // player marker (lerped halo)
  if(pAnim&&here){
    var hxy=nodeXY(here);
    pAnim.x+=(hxy[0]-pAnim.x)*.3; pAnim.y+=(hxy[1]-pAnim.y)*.3;
    ctx.strokeStyle='rgba(25,240,199,'+(.6+.3*Math.sin(t/220))+')';
    ctx.lineWidth=2.5;
    ctx.beginPath(); ctx.arc(pAnim.x,pAnim.y,12,0,Math.PI*2); ctx.stroke();
    ctx.lineWidth=1;
  }

  // ripples
  for(var r2=pulses.length-1;r2>=0;r2--){
    var P=pulses[r2];
    P.age+=1/60;
    if(P.age>0.8){ pulses.splice(r2,1); continue; }
    ctx.globalAlpha=Math.max(0,1-P.age/0.8);
    ctx.strokeStyle=P.col;
    ctx.beginPath(); ctx.arc(P.x,P.y,16+P.age*46,0,Math.PI*2); ctx.stroke();
    ctx.globalAlpha=1;
  }
}
requestAnimationFrame(draw);

function ndPost(data,cb){
  if(busy) return;
  busy=true;
  data.nd_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;showMsg('Network error','var(--neon2)');});
}

function endDive(){
  state=null; ending=false;
  document.getElementById('nd-active').style.display='none';
  document.getElementById('nd-lobby').style.display='';
}

function handleEvents(evts){
  for(var i=0;i<evts.length;i++){
    var ev=evts[i];
    if(ev.t==='cracked'){
      var n=nodeById(ev.node); if(n){ var xy=nodeXY(n); pulses.push({x:xy[0],y:xy[1],age:0,col:'#3bcf63'}); }
      window.ndTone&&window.ndTone(660,.09,'sine',.05); window.ndTone&&setTimeout(function(){window.ndTone(880,.12,'sine',.05);},80);
      feed((ev.type==='core'?'&#9679; CORE CRACKED':ev.type==='vault'?'&#128274; Vault cracked':'&#128190; Cache cracked')+' — <b style="color:#3bcf63">+'+ev.v+'</b> cr unbanked');
    } else if(ev.t==='crackprog'){
      window.ndTone&&window.ndTone(340,.06,'square',.03);
      feed('Cracking&hellip; '+ev.prog+'/'+ev.need,'var(--muted)');
    } else if(ev.t==='clash'){
      var hp=nodeById(state.pos); if(hp){ var xy2=nodeXY(hp); pulses.push({x:xy2[0],y:xy2[1],age:0,col:'#e23b3b'}); }
      window.ndTone&&window.ndTone(880,.13,'square',.05); window.ndTone&&setTimeout(function(){window.ndTone(620,.16,'square',.05);},140);
      feed('&#9650; ICE CLASH — lost <b style="color:#ff2d95">'+ev.lost+'</b> cr, trace +'+ev.trace);
    } else if(ev.t==='cloak'){
      window.ndTone&&window.ndTone(320,.2,'sawtooth',.04,90);
      feed('&#127787; Cloaked — ICE slides past ('+ev.left+' charge'+(ev.left===1?'':'s')+' left)','#4de8e8');
    }
  }
}

window.ndStart=function(tier){
  ndPost({nd_action:'start',tier:tier||1},function(d){
    if(!d.ok){ alert(d.err||'Error'); return; }
    state=d.state;
    syncAnims();
    pulses=[]; ending=false;
    var f=document.getElementById('nd-feed'); if(f) f.innerHTML='';
    if(d.drive!=null) document.getElementById('nd-drive-chip').textContent=d.drive;
    document.getElementById('nd-lobby').style.display='none';
    document.getElementById('nd-active').style.display='';
    hud();
    window.ndTone&&window.ndTone(180,.3,'sine',.06,70);
    feed('Jacked into <b>'+state.corp+'</b> ('+(state.tier_name||'')+'). Find the loot, mind the trace.','#c4a2ff');
  });
};

window.ndAct=function(a,node){
  if(!state||busy||ending) return;
  var data={nd_action:'act',a:a};
  if(node!=null) data.node=node;
  ndPost(data,function(d){
    if(!d.ok){ showMsg(d.err||'Error','var(--neon2)'); return; }
    state=d.state;
    handleEvents(d.events);
    hud();
    if(d.settle){
      ending=true;
      var s=d.settle;
      var sig=document.getElementById('nd-sig-chip');
      if(sig&&s.signal!=null) sig.textContent=s.signal;
      if(s.outcome==='banked'){
        window.ndTone&&[523,659,784].forEach(function(f2,i2){setTimeout(function(){window.ndTone(f2,.13,'sine',.05);},i2*100);});
        feed('<b style="color:#3bcf63">DIVE BANKED</b> — +'+Number(s.creds).toLocaleString('en-US')+' cr · +'+s.xp+' XP'+(s.shard?' · <b style="color:#e8d44d">+1 ◆ SHARD</b>':''));
        showMsg('Banked '+Number(s.creds).toLocaleString('en-US')+' cr from '+s.corp+'.','#3bcf63');
      } else if(s.outcome==='ejected'){
        window.ndTone&&window.ndTone(330,.25,'sine',.05,140);
        feed('<b style="color:#e8a33d">EJECTED</b> — salvaged +'+Number(s.creds).toLocaleString('en-US')+' cr · +'+s.xp+' XP · &minus;'+s.signal_lost+' Signal');
      } else {
        window.ndTone&&window.ndTone(200,.4,'sawtooth',.05,60);
        feed('<b style="color:#ff2d95">TRACED &amp; DUMPED</b> — all unbanked loot gone · &minus;'+s.signal_lost+' Signal');
      }
      setTimeout(endDive, 2200);
    }
  });
};
window.ndEject=function(){
  if(!state||busy||ending) return;
  if(state.loot>0&&!confirm('Eject now? You keep 40% ('+Math.floor(state.loot*0.4).toLocaleString('en-US')+' cr) and take a Signal hit.')) return;
  window.ndAct('eject');
};

// canvas click: adjacent node = move; own node = crack
if(!window._ndClickBound){
  window._ndClickBound=true;
  document.addEventListener('click',function(e){
    var cv=document.getElementById('nd-canvas');
    if(!cv||!document.body.contains(cv)||e.target!==cv) return;
    if(!state||busy||ending) return;
    var r=cv.getBoundingClientRect();
    var scale=(r.width>0)?(520/r.width):1;
    var mx=(e.clientX-r.left)*scale, my=(e.clientY-r.top)*scale;
    for(var i=0;i<state.nodes.length;i++){
      var n=state.nodes[i];
      var xy=[46+n.layer*107, 190+n.y*74];
      var dx=mx-xy[0], dy=my-xy[1];
      if(dx*dx+dy*dy<=22*22){
        if(n.id===state.pos){ window.ndAct('crack'); }
        else if((state.adj[state.pos]||[]).indexOf(n.id)>=0){ window.ndAct('move',n.id); }
        else showMsg('Not linked to your node.','var(--muted)');
        return;
      }
    }
  });
}
// keyboard
if(!window._ndKeysBound){
  window._ndKeysBound=true;
  document.addEventListener('keydown',function(e){
    var cv=document.getElementById('nd-canvas');
    if(!cv||!document.body.contains(cv)) return;
    if(/INPUT|TEXTAREA|SELECT/.test((e.target&&e.target.tagName)||'')) return;
    var act=document.getElementById('nd-active');
    if(!act||act.style.display==='none') return;
    var k=e.key.toLowerCase();
    if(k==='c'){ e.preventDefault(); window.ndAct('crack'); }
    else if(k==='k'){ e.preventDefault(); window.ndAct('cloak'); }
    else if(k===' '||k==='h'){ e.preventDefault(); window.ndAct('hold'); }
    else if(k==='j'){ e.preventDefault(); window.ndAct('jackout'); }
    else if(k==='e'){ e.preventDefault(); window.ndEject(); }
  });
}

/* header canvas: drifting packet lattice */
var hc=document.getElementById('nd-headcv');
if(hc){
  var c2=hc.getContext('2d');
  var HW=560, HH=96;
  var d2=Math.min(2,window.devicePixelRatio||1);
  hc.width=HW*d2; hc.height=HH*d2;
  c2.scale(d2,d2);
  var pk=[];
  for(var i4=0;i4<26;i4++) pk.push({x:Math.random()*HW,y:8+Math.random()*(HH-16),v:.3+Math.random()*.9,c:Math.random()<.25?'#ff2d95':(Math.random()<.5?'#9d6bff':'#4de8e8')});
  function hLoop(t){
    if(!document.body.contains(hc)) return;
    requestAnimationFrame(hLoop);
    c2.clearRect(0,0,HW,HH);
    var bg=c2.createLinearGradient(0,0,0,HH);
    bg.addColorStop(0,'#0a0714'); bg.addColorStop(1,'#0d0a16');
    c2.fillStyle=bg; c2.fillRect(0,0,HW,HH);
    c2.strokeStyle='rgba(157,107,255,.07)';
    for(var lx=0;lx<HW;lx+=40){ c2.beginPath(); c2.moveTo(lx+.5,0); c2.lineTo(lx+.5,HH); c2.stroke(); }
    for(var ly=0;ly<HH;ly+=24){ c2.beginPath(); c2.moveTo(0,ly+.5); c2.lineTo(HW,ly+.5); c2.stroke(); }
    for(var p3=0;p3<pk.length;p3++){
      var K=pk[p3];
      K.x+=K.v; if(K.x>HW+8) K.x=-8;
      c2.fillStyle=K.c;
      c2.globalAlpha=.5+.4*Math.sin(t/300+p3);
      c2.fillRect(K.x,K.y,6,2);
      c2.globalAlpha=1;
    }
  }
  requestAnimationFrame(hLoop);
}

if(state){ hud(); feed('Dive resumed — the trace kept its place.','#c4a2ff'); }
})();
</script>
