<?php /* pages/city.php — The Sprawl: district map hub */
$DISTRICTS = [
  ['The Bazaar',        '&#128722;', '#19f0c7', 'Commerce heart of the Sprawl.', [
    ['bazaar', 'Open Market'], ['ledger&act=bank', 'Bank'], ['auction', 'Black Market Auctions'],
    ['accord', 'Commerce Accord'], ['trade', 'Secure Trade Post'], ['generalstore', 'The Supply Node'],
  ]],
  ['The Exchange Block', '&#128200;', '#e8d44d', 'Where credits become more credits.', [
    ['exchange', 'The Exchange'], ['bonds', 'Credit Bonds'], ['stockex', 'Stock Exchange'],
  ]],
  ['Neon Strip',        '&#127864;', '#ff2d95', 'Vice, stims, and bad decisions.', [
    ['lounge', 'The Static Lounge'], ['synth', 'Synthesis Den'],
  ]],
  ['The Undervolt',     '&#127920;', '#a66de8', 'The house always wins down here.', [
    ['daemon', 'The Lucky Daemon'],
  ]],
  ['The Forge Quarter', '&#9874;',   '#ff6b35', 'Industry, ore, and hot metal.', [
    ['blacksmith', 'The Blacksmith'], ['apartments', 'Apartment Complex'],
    ['mining', 'The Sump (Mining)'], ['weaponcraft', 'Fabrication Lab'],
  ]],
  ['The Firewall',      '&#128737;', '#e23b3b', 'Simulated violence, real rewards.', [
    ['sim', 'Combat Simulator'],
  ]],
  ['The Loading Docks', '&#128643;', '#e8a33d', 'Cargo in, cargo out, ambushes between.', [
    ['transit', 'Transit Hub'],
  ]],
  ['The Datacore',      '&#129504;', '#4d6be8', 'Knowledge is loaded, not learned.', [
    ['datacore&act=lab', 'Skillsoft Lab'], ['library', 'The Library'],
  ]],
  ['Foundry Sector',    '&#9881;',   '#8fa3c8', 'Scrap goes in. Gear comes out.', [
    ['foundry', 'Fabrication Bay'],
  ]],
  ['The Hydrofarms',    '&#127807;', '#3bcf63', 'Herbs under glass and growlight.', [
    ['vats', 'Grow Vats'],
  ]],
  ['The Stacks',        '&#127968;', '#6fb3e0', 'Home, such as it is.', [
    ['home', 'Your Hideout'], ['guilds', 'Syndicates'], ['guilds&tab=search', 'Syndicate Directory'],
  ]],
  ['The Grid Authority','&#127963;', '#e8d44d', 'Bureaucracy with teeth.', [
    ['cityhall', 'Admin Spire'], ['registry', 'ID Registry'], ['tickets', 'Customer Service'],
    ['awards', 'Grid Rankings'], ['welfare', 'Subsistence Terminal'], ['jail', 'Confinement Grid'],
  ]],
  ['Combat Zone',       '&#9876;',   '#ff2d95', 'Ghost versus ghost. No referees.', [
    ['pvp', 'Combat Arena'], ['training', 'Neural Training'],
  ]],
];
?>
<style>
#city-canvas{display:block;width:100%;height:200px;border-radius:9px 9px 0 0;cursor:default}
.cty-card{position:relative;overflow:hidden;background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:14px 16px;transition:transform .12s,border-color .15s,box-shadow .15s;animation:ctyIn .3s ease-out backwards}
@keyframes ctyIn{0%{opacity:0;transform:translateY(8px)}100%{opacity:1;transform:none}}
.cty-card:hover{transform:translateY(-2px);border-color:var(--cty-col);box-shadow:0 4px 14px rgba(0,0,0,.3),0 0 12px var(--cty-glow)}
.cty-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cty-col),transparent)}
.cty-head{display:flex;align-items:center;gap:9px;margin-bottom:3px}
.cty-head .ic{font-size:19px;transition:transform .15s,text-shadow .15s}
.cty-card:hover .cty-head .ic{transform:scale(1.12);text-shadow:0 0 12px var(--cty-col)}
.cty-card h4{margin:0;font-size:13px;color:var(--cty-col)}
.cty-sub{font-size:10px;color:var(--muted);margin:0 0 8px;font-style:italic}
.cty-card ul{list-style:none;margin:0;padding:0}
.cty-card li{padding:3px 0;font-size:12px}
.cty-card li a{color:var(--text);text-decoration:none;transition:color .12s,padding-left .12s;display:inline-block}
.cty-card li a:hover{color:var(--cty-col);padding-left:5px}
.cty-card li a::before{content:'\203A\00a0';color:var(--cty-col);opacity:.6}
</style>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="city-canvas"></canvas>
    <div style="position:absolute;left:0;right:0;bottom:12px;text-align:center;pointer-events:none">
      <p class="muted" style="margin:0;font-size:11px;text-shadow:0 1px 4px #000">Thirteen districts, none of them safe. Pick a node and jack in.</p>
    </div>
  </div>
</div>

<div class="districts" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px">
  <?php foreach ($DISTRICTS as $di => [$dName, $dIcon, $dCol, $dSub, $dLinks]): ?>
  <div class="cty-card" style="--cty-col:<?= $dCol ?>;--cty-glow:<?= $dCol ?>22;animation-delay:<?= min(12, $di) * 40 ?>ms">
    <div class="cty-head"><span class="ic"><?= $dIcon ?></span><h4><?= $dName ?></h4></div>
    <p class="cty-sub"><?= e($dSub) ?></p>
    <ul>
      <?php foreach ($dLinks as [$dUrl, $dLabel]): ?>
      <li><a href="index.php?p=<?= $dUrl ?>"><?= e($dLabel) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
'use strict';
/* ── The Sprawl: living skyline ── */
var cv=document.getElementById('city-canvas');
if(!cv) return;
var c=cv.getContext('2d');
var W=720, H=200;
var dpr=Math.min(2,window.devicePixelRatio||1);
cv.width=W*dpr; cv.height=H*dpr;
c.scale(dpr,dpr);

function cssVar(n,fb){
  try{ var v=getComputedStyle(document.documentElement).getPropertyValue(n).trim(); return v||fb; }catch(e){ return fb; }
}
var ACCENT=cssVar('--accent','#19f0c7'), NEON2=cssVar('--neon2','#ff2d95');

// deterministic building field (two depth layers) + arcology in the middle
var s=13; function rnd(){ s=(s*16807)%2147483647; return s/2147483647; }
function makeLayer(n,minH,maxH,wMin,wMax){
  var out=[],x=-10;
  while(x<W+10&&out.length<n){
    var bw=wMin+rnd()*(wMax-wMin), bh=minH+rnd()*(maxH-minH);
    out.push({x:x,w:bw,h:bh,seed:rnd()});
    x+=bw+4+rnd()*14;
  }
  return out;
}
var back=makeLayer(26,40,90,24,46);
var front=makeLayer(18,70,140,34,64);
var cars=[]; // flying traffic
for(var i=0;i<5;i++) cars.push({x:Math.random()*W,y:26+Math.random()*70,v:(.7+Math.random()*1.4)*(Math.random()<.5?-1:1),col:Math.random()<.5?ACCENT:NEON2});

function winLit(b,wx,wy,t){
  // stable per-window flicker
  var h2=Math.sin(b.seed*9999+wx*37+wy*91)*43758.55;
  var r=h2-Math.floor(h2);
  return ((t/1400+r*6)%7)<(3+r*2.6);
}

function loop(t){
  if(!document.body.contains(cv)) return;
  requestAnimationFrame(loop);
  c.clearRect(0,0,W,H);
  var bg=c.createLinearGradient(0,0,0,H);
  bg.addColorStop(0,'#07070f'); bg.addColorStop(.6,'#0a0a14'); bg.addColorStop(1,'#0e0c16');
  c.fillStyle=bg; c.fillRect(0,0,W,H);

  // distant glow on the horizon
  var hg=c.createRadialGradient(W/2,H,20,W/2,H,W*.6);
  hg.addColorStop(0,'rgba(255,45,149,.06)'); hg.addColorStop(1,'rgba(255,45,149,0)');
  c.fillStyle=hg; c.fillRect(0,0,W,H);

  // back layer
  back.forEach(function(b){
    c.fillStyle='#0d0d18';
    c.fillRect(b.x,H-b.h,b.w,b.h);
    for(var wy=H-b.h+6;wy<H-8;wy+=11){
      for(var wx=b.x+4;wx<b.x+b.w-4;wx+=9){
        if(winLit(b,wx,wy,t)){
          c.fillStyle='rgba(120,140,190,.18)';
          c.fillRect(wx,wy,3,4);
        }
      }
    }
  });

  // central arcology
  var ax=W/2;
  c.fillStyle='#12121f';
  c.fillRect(ax-58,H-168,116,168);
  c.strokeStyle='rgba(25,240,199,.35)';
  c.strokeRect(ax-58.5,H-168.5,116,168);
  c.fillRect(ax-34,H-186,68,18);
  c.strokeRect(ax-34.5,H-186.5,68,18);
  // antenna + beacon
  c.strokeStyle='rgba(255,255,255,.3)';
  c.beginPath(); c.moveTo(ax,H-186); c.lineTo(ax,H-202); c.stroke();
  var bp=.5+.5*Math.sin(t/430);
  c.fillStyle=NEON2; c.shadowColor=NEON2; c.shadowBlur=10*bp;
  c.beginPath(); c.arc(ax,H-204,2.6+bp,0,Math.PI*2); c.fill();
  c.shadowBlur=0;
  // arcology windows
  for(var ay=H-160;ay<H-10;ay+=13){
    for(var ax2=ax-48;ax2<ax+44;ax2+=12){
      if(winLit({seed:.5},ax2,ay,t)){
        c.fillStyle=((ax2+ay)%3===0)?'rgba(255,45,149,.4)':'rgba(25,240,199,.35)';
        c.fillRect(ax2,ay,4,5);
      }
    }
  }

  // front layer
  front.forEach(function(b,bi){
    if(Math.abs((b.x+b.w/2)-ax)<70) return; // keep the arcology clear
    c.fillStyle='#10101c';
    c.fillRect(b.x,H-b.h,b.w,b.h);
    c.strokeStyle='rgba(255,255,255,.05)';
    c.strokeRect(b.x+.5,H-b.h+.5,b.w,b.h);
    for(var wy2=H-b.h+7;wy2<H-9;wy2+=12){
      for(var wx2=b.x+5;wx2<b.x+b.w-5;wx2+=10){
        if(winLit(b,wx2,wy2,t)){
          c.fillStyle=(bi%4===0)?'rgba(232,163,61,.3)':'rgba(25,240,199,.26)';
          c.fillRect(wx2,wy2,3.4,4.4);
        }
      }
    }
    // rooftop lamp on some
    if(b.seed>.6){
      c.fillStyle='rgba(226,59,59,'+(0.4+0.4*Math.sin(t/520+b.seed*9))+')';
      c.fillRect(b.x+b.w/2-1,H-b.h-3,2,2);
    }
  });

  // flying traffic with light trails
  cars.forEach(function(k){
    k.x+=k.v;
    if(k.x<-30){ k.x=W+30; k.y=26+Math.random()*70; }
    if(k.x>W+30){ k.x=-30; k.y=26+Math.random()*70; }
    var tg=c.createLinearGradient(k.x-k.v*14,k.y,k.x,k.y);
    tg.addColorStop(0,'rgba(0,0,0,0)');
    tg.addColorStop(1,k.col);
    c.strokeStyle=tg; c.lineWidth=1.4;
    c.beginPath(); c.moveTo(k.x-k.v*14,k.y); c.lineTo(k.x,k.y); c.stroke();
    c.lineWidth=1;
    c.fillStyle='#fff';
    c.fillRect(k.x-1,k.y-1,2,2);
  });

  // neon title
  var fl=.8+.2*Math.sin(t/420);
  c.shadowColor=ACCENT; c.shadowBlur=16*fl;
  c.fillStyle=ACCENT; c.globalAlpha=fl;
  c.font='700 26px Orbitron, Verdana, sans-serif';
  c.textAlign='center'; c.textBaseline='middle';
  c.fillText('T H E   S P R A W L',W/2,52);
  c.shadowBlur=7*fl; c.globalAlpha=fl*.9;
  c.fillStyle=NEON2; c.font='700 9px Verdana, sans-serif';
  c.fillText('S E C T O R   9   //   A R C O L O G Y',W/2,74);
  c.shadowBlur=0; c.globalAlpha=1;

  // street fog
  var fgr=c.createLinearGradient(0,H-26,0,H);
  fgr.addColorStop(0,'rgba(160,160,200,0)'); fgr.addColorStop(1,'rgba(160,160,200,.06)');
  c.fillStyle=fgr; c.fillRect(0,H-26,W,26);
}
requestAnimationFrame(loop);
})();
</script>
