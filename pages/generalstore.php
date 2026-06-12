<?php /* pages/generalstore.php — The Supply Node: consumables and gear */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

// Ensure inventory table exists (player_general_items)
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_general_items (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    item_id   VARCHAR(64) NOT NULL,
    quantity  INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_pi (player_id, item_id),
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// Catalog  [id, name, icon, desc, price, effect, amount] — shared with the Library
// effect: 'integrity' | 'signal' | 'cycles' | null (collectible)
$CATALOG = generalstore_catalog();

// Effect theming
$FX = [
  'integrity' => ['label'=>'Health', 'col'=>'#3bcf63'],
  'signal'    => ['label'=>'Signal', 'col'=>'#ff2d95'],
  'cycles'    => ['label'=>'Drive',  'col'=>'#e8a33d'],
  ''          => ['label'=>'Utility','col'=>'#8fa3c8'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
  $iid = $_POST['item_id'] ?? '';
  $item = null;
  foreach ($CATALOG as $c) { if ($c[0] === $iid) { $item = $c; break; } }
  try {
    if (!$item) throw new RuntimeException('Item not found.');
    $price = $item[4];
    $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
    $u->execute([$price, $pid, $price]);
    if ($u->rowCount() !== 1) throw new RuntimeException('Not enough creds. Need ' . number_format($price) . ' &#9670;');
    $effect = $item[5]; $amount = (int)$item[6];
    if ($effect) {
      // Apply stat up to its max
      $cols = ['integrity'=>['integrity','integrity_max'],'signal'=>['`signal`','signal_max'],'cycles'=>['cycles','cycles_max']];
      if (isset($cols[$effect])) {
        [$col, $maxCol] = $cols[$effect];
        $pdo->prepare("UPDATE players SET {$col} = LEAST({$maxCol}, {$col} + ?) WHERE id = ?")->execute([$amount, $pid]);
      }
    } else {
      // Collectible — add to player_general_items
      $pdo->prepare('INSERT INTO player_general_items (player_id, item_id, quantity) VALUES (?,?,1)
                     ON DUPLICATE KEY UPDATE quantity = quantity + 1')->execute([$pid, $iid]);
    }
    $player = current_player();
    $msg = 'Dispensed: ' . e($item[1]) . ($effect ? '. Effect applied.' : '. Added to stash.');
  } catch (Throwable $ex) { $msg = $ex->getMessage(); $msgErr = true; }
}

// Load inventory quantities
$invQ = $pdo->prepare('SELECT item_id, quantity FROM player_general_items WHERE player_id = ?');
$invQ->execute([$pid]);
$inv = [];
foreach ($invQ as $r) $inv[$r['item_id']] = (int)$r['quantity'];

$pocket = (int)$player['creds_pocket'];
$nRestore = count(array_filter($CATALOG, fn($c) => $c[5] !== null));
$nUtility = count($CATALOG) - $nRestore;
?>
<style>
#store-canvas{display:block;width:100%;height:110px;border-radius:9px 9px 0 0}
.gs-chip{padding:6px 14px;border-radius:20px;font-size:12px;cursor:pointer;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s;user-select:none}
.gs-chip.on{border-color:var(--accent);background:rgba(25,240,199,.1);color:var(--accent);box-shadow:0 0 10px rgba(25,240,199,.12)}
.gs-card{position:relative;overflow:hidden;background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:12px;margin:0;transition:transform .12s,border-color .15s,box-shadow .15s,opacity .2s}
.gs-card:hover{transform:translateY(-2px);border-color:var(--fx-col);box-shadow:0 4px 16px rgba(0,0,0,.35),0 0 12px var(--fx-glow)}
.gs-card .gs-ic{transition:transform .15s,text-shadow .15s}
.gs-card:hover .gs-ic{transform:scale(1.12);text-shadow:0 0 12px var(--fx-col)}
.gs-card.hide{display:none}
.gs-fxpill{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.05em;padding:1px 7px;border-radius:9px;border:1px solid var(--fx-col);color:var(--fx-col);background:rgba(0,0,0,.3)}
.gs-card.broke .gs-price{color:var(--neon2)}
@keyframes gsCardIn{0%{opacity:0;transform:translateY(8px)}100%{opacity:1;transform:none}}
.gs-card.fadein{animation:gsCardIn .22s ease-out backwards}
#gs-head h2{text-shadow:0 0 14px rgba(25,240,199,.3)}
</style>

<div class="panel" id="gs-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="store-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:24px;pointer-events:none">
      <h2 style="margin:0">&#127978; The Supply Node</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">"We stock what the Sprawl needs. Mostly."</p>
    </div>
    <div style="position:absolute;right:14px;bottom:24px;text-align:right">
      <div class="muted" style="font-size:10px;text-shadow:0 1px 4px #000">POCKET</div>
      <div style="font-size:19px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent);text-shadow:0 1px 6px #000"><?= number_format($pocket) ?> <span style="font-size:11px;font-weight:400">cr</span></div>
    </div>
    <button id="store-mute" onclick="toggleStoreSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
  <?php if ($msg): ?><div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>" style="margin:10px 14px 0"><?= $msg ?></div><?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;padding:12px 14px">
    <span class="gs-chip on" data-filter="all">All <span style="opacity:.6">(<?= count($CATALOG) ?>)</span></span>
    <span class="gs-chip" data-filter="restore">&#10084; Restoratives <span style="opacity:.6">(<?= $nRestore ?>)</span></span>
    <span class="gs-chip" data-filter="utility">&#128295; Utility <span style="opacity:.6">(<?= $nUtility ?>)</span></span>
  </div>
</div>

<div class="shop-grid" id="gs-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
<?php foreach ($CATALOG as $c): [$iid,$name,$icon,$desc,$price,$effect,$amount] = $c;
  $fx = $FX[$effect ?? ''] ?? $FX[''];
  $broke = $pocket < $price;
?>
<div class="gs-card<?= $broke ? ' broke' : '' ?>" data-cat="<?= $effect ? 'restore' : 'utility' ?>"
     style="--fx-col:<?= $fx['col'] ?>;--fx-glow:<?= $fx['col'] ?>22">
  <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $fx['col'] ?>,transparent)"></div>
  <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px">
    <span class="gs-ic" style="font-size:28px;flex:none"><?= $icon ?></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:bold;font-size:13px;color:<?= $fx['col'] ?>"><?= e($name) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= e($desc) ?></div>
      <div style="margin-top:5px;display:flex;gap:5px;align-items:center;flex-wrap:wrap">
        <?php if ($effect && $amount > 0): ?>
          <span class="gs-fxpill">+<?= $amount ?> <?= $fx['label'] ?></span>
        <?php else: ?>
          <span class="gs-fxpill">UTILITY</span>
          <?php if (isset($inv[$iid]) && $inv[$iid] > 0): ?>
          <span style="font-size:10px;color:var(--muted)">stash &times;<?= $inv[$iid] ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between">
    <span class="gs-price" style="font-weight:bold;color:var(--accent);font-size:13px"><?= number_format($price) ?> cr</span>
    <form method="post" style="margin:0" data-storefx="1"
          data-store-icon="<?= $icon ?>" data-store-col="<?= $fx['col'] ?>"
          data-store-label="<?= $effect && $amount>0 ? '+'.$amount.' '.$fx['label'] : 'Added to stash' ?>">
      <input type="hidden" name="action" value="buy">
      <input type="hidden" name="item_id" value="<?= e($iid) ?>">
      <button type="submit" <?= $broke ? 'disabled style="opacity:.4"' : 'style="background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)"' ?>>Buy</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>

<p class="muted" style="text-align:center;margin-top:14px"><a href="index.php?p=city">&larr; Back to The Sprawl</a></p>

<script>
(function(){
'use strict';

/* ── Neon storefront header ───────────────────────────────────────────── */
var sc=document.getElementById('store-canvas');
if(sc){
  var c=sc.getContext('2d');
  var SW=560, SH=110;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  sc.width=SW*dpr; sc.height=SH*dpr;
  c.scale(dpr,dpr);

  var TICKER='  FRESH STOCK DAILY  ///  NO REFUNDS  ///  PATCH KITS BACK IN STOCK  ///  REACTOR BREW 2-FOR-1 NEVER  ///  SHOPLIFTERS WILL BE UPLOADED  ///';
  var tickerX=0;
  var SIGN='SUPPLY NODE';
  var flicker=[]; for(var i=0;i<SIGN.length;i++) flicker.push(1);
  var buzzUntil=0, buzzLetter=-1;
  // shelf product dots (deterministic)
  var dots=[];
  (function(){
    var seed=7;
    function rnd(){ seed=(seed*16807)%2147483647; return seed/2147483647; }
    for(var s2=0;s2<2;s2++) for(var d2=0;d2<26;d2++){
      dots.push({x:24+d2*20+rnd()*6, y:58+s2*22+rnd()*3, col:['#3bcf63','#ff2d95','#e8a33d','#4d9be8','#a66de8'][Math.floor(rnd()*5)], s:2+rnd()*2.4});
    }
  })();

  function storeLoop(t){
    if(!document.body.contains(sc)) return;
    requestAnimationFrame(storeLoop);
    c.clearRect(0,0,SW,SH);
    var bg=c.createLinearGradient(0,0,0,SH);
    bg.addColorStop(0,'#0a0a14'); bg.addColorStop(1,'#0d0d1c');
    c.fillStyle=bg; c.fillRect(0,0,SW,SH);

    // shelves
    c.fillStyle='rgba(255,255,255,.05)';
    c.fillRect(16,66,SW-260,2); c.fillRect(16,88,SW-260,2);
    for(var di=0;di<dots.length;di++){
      var D=dots[di];
      c.fillStyle=D.col; c.globalAlpha=.5+.2*Math.sin(t/900+di);
      c.fillRect(D.x,D.y,D.s,D.s*1.6);
    }
    c.globalAlpha=1;

    // neon sign with per-letter flicker
    if(t>buzzUntil&&Math.random()<.012){ buzzLetter=Math.floor(Math.random()*SIGN.length); buzzUntil=t+120+Math.random()*420; }
    c.font='700 26px Orbitron, monospace';
    c.textAlign='left'; c.textBaseline='top';
    var x=SW-262, y=14;
    for(var li=0;li<SIGN.length;li++){
      var on=!(li===buzzLetter&&t<buzzUntil&&Math.random()<.75);
      var a=on?(.85+.15*Math.sin(t/300+li)):.18;
      c.shadowColor='#19f0c7'; c.shadowBlur=on?14:2;
      c.fillStyle='rgba(25,240,199,'+a+')';
      c.fillText(SIGN[li],x,y);
      x+=c.measureText(SIGN[li]).width+2;
    }
    c.shadowBlur=0;

    // scan sweep
    var sx=((t/26)%(SW+160))-160;
    var sweep=c.createLinearGradient(sx,0,sx+160,0);
    sweep.addColorStop(0,'rgba(255,255,255,0)');
    sweep.addColorStop(.5,'rgba(255,255,255,.025)');
    sweep.addColorStop(1,'rgba(255,255,255,0)');
    c.fillStyle=sweep; c.fillRect(0,0,SW,SH-16);

    // LED ticker strip
    c.fillStyle='#05050c'; c.fillRect(0,SH-16,SW,16);
    c.fillStyle='rgba(232,163,61,.12)'; c.fillRect(0,SH-16,SW,1);
    c.font='700 10px monospace'; c.textBaseline='middle';
    c.fillStyle='#e8a33d';
    tickerX-=0.6;
    var tw=c.measureText(TICKER).width;
    if(tickerX<-tw) tickerX+=tw;
    c.fillText(TICKER,tickerX,SH-8);
    c.fillText(TICKER,tickerX+tw,SH-8);
  }
  requestAnimationFrame(storeLoop);
}

/* ── Category filter chips ────────────────────────────────────────────── */
var grid=document.getElementById('gs-grid');
document.querySelectorAll('.gs-chip').forEach(function(chip){
  chip.addEventListener('click',function(){
    document.querySelectorAll('.gs-chip').forEach(function(ch){ch.classList.remove('on');});
    chip.classList.add('on');
    var f=chip.dataset.filter;
    var idx=0;
    grid.querySelectorAll('.gs-card').forEach(function(card){
      var show=f==='all'||card.dataset.cat===f;
      card.classList.toggle('hide',!show);
      card.classList.remove('fadein');
      if(show){ void card.offsetWidth; card.style.animationDelay=(idx*28)+'ms'; card.classList.add('fadein'); idx++; }
    });
  });
});
})();
</script>

<script>
/* Dispense FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._storeFxBound) return;
  window._storeFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#storefx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(4,4,10,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#storefx.show{opacity:1}'
    +'.sfx-machine{position:relative;width:150px;height:130px}'
    +'.sfx-slot{position:absolute;left:50%;top:18px;transform:translateX(-50%);width:104px;height:12px;'
    +'background:#05050c;border:1px solid rgba(255,255,255,.25);border-radius:6px;'
    +'box-shadow:inset 0 2px 6px #000,0 0 14px var(--sfx-col-a)}'
    +'.sfx-item{position:absolute;left:50%;top:26px;transform:translateX(-50%);font-size:34px;opacity:0;'
    +'animation:sfxDrop .65s .15s cubic-bezier(.3,1.6,.5,1) forwards;text-shadow:0 0 16px var(--sfx-col)}'
    +'@keyframes sfxDrop{0%{opacity:0;transform:translate(-50%,-12px) scale(.5)}45%{opacity:1;transform:translate(-50%,44px) scale(1.05)}'
    +'70%{transform:translate(-50%,36px)}100%{opacity:1;transform:translate(-50%,42px) scale(1)}}'
    +'.sfx-label{position:absolute;left:50%;top:104px;transform:translateX(-50%);white-space:nowrap;'
    +'font-size:13px;font-weight:700;color:var(--sfx-col);text-shadow:0 0 12px var(--sfx-col);opacity:0;'
    +'animation:sfxLbl .3s .8s forwards}'
    +'@keyframes sfxLbl{0%{opacity:0;transform:translate(-50%,6px)}100%{opacity:1;transform:translate(-50%,0)}}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('storeMuted')==='1';
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
  window.toggleStoreSound=function(){
    muted=!muted; localStorage.setItem('storeMuted',muted?'1':'0');
    var b=document.getElementById('store-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) sfx(660,.08,'sine',.05);
  };
  (function(){ var b=document.getElementById('store-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; })();

  function hexA(hex,a){
    if(hex.charAt(0)!=='#') return hex;
    var n=parseInt(hex.slice(1),16);
    return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';
  }

  function dispense(icon,label,col){
    var old=document.getElementById('storefx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='storefx';
    o.style.setProperty('--sfx-col',col);
    o.style.setProperty('--sfx-col-a',hexA(col,.4));
    o.innerHTML='<div class="sfx-machine"><div class="sfx-slot"></div>'
      +'<div class="sfx-item">'+icon+'</div>'
      +'<div class="sfx-label">'+label+'</div></div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    sfx(880,.07,'square',.035,440);                          // vend beep
    setTimeout(function(){sfx(120,.1,'square',.05);},560);   // thunk
    setTimeout(function(){sfx(659,.09,'sine',.04);setTimeout(function(){sfx(988,.14,'sine',.04);},80);},820); // chime
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},1800);
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute||!f.getAttribute('data-storefx')) return;
    dispense(f.getAttribute('data-store-icon')||'&#128230;',
             f.getAttribute('data-store-label')||'Dispensed',
             f.getAttribute('data-store-col')||'#19f0c7');
  },true);
})();
</script>
