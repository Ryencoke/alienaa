<?php /* pages/library.php — Item Library: catalog of all in-game items */
$pdo = db();

$tab = $_GET['tab'] ?? 'weapons';
$validTabs = ['weapons','armor','consumables','materials','skills'];
if (!in_array($tab, $validTabs, true)) $tab = 'weapons';

/* ----- Load DB items ----- */
$dbItems = [];
try {
  $q = $pdo->query("SELECT id, name, category, slot, atk, def, tier, descr FROM items ORDER BY name");
  foreach ($q as $r) $dbItems[] = $r;
} catch (Throwable $e) {}

/* ----- The Forge catalog (sourced from the live blacksmith catalog) ----- */
// Library rarity mirrors blacksmith's bs_tier(): Legendary / EPIC>=15k /
// RARE>=6k / UNCOMMON>=2.5k / else COMMON (EPIC shown as "elite" here).
$lib_rarity = function ($sub, $price) {
  if ($sub === 'Legendary') return 'legendary';
  if ($price >= 15000)      return 'elite';
  if ($price >= 6000)       return 'rare';
  if ($price >= 2500)       return 'uncommon';
  return 'common';
};
$BS_WEAPONS = []; $BS_ARMOR = [];
foreach (blacksmith_catalog() as $c) {
  // render row: [code, icon, name, sub-type, atk, def, price, desc, rarity]
  $row = [$c[0], $c[2], $c[1], $c[4], (int)$c[5], (int)$c[6], (int)$c[8], $c[9], $lib_rarity($c[4], (int)$c[8])];
  if ($c[3] === 'weapon') $BS_WEAPONS[] = $row; else $BS_ARMOR[] = $row;
}

/* ----- Consumables (sourced from the live General Store catalog) ----- */
$CONSUMABLES = [];
$lib_fx_label = ['integrity'=>'Health','signal'=>'Signal','cycles'=>'Drive'];
foreach (generalstore_catalog() as $g) {
  // generalstore row: [id, name, icon, desc, price, effect, amount]
  $type = $g[5] ? 'consumable' : 'collectible';
  $desc = ($g[5] && (int)$g[6] > 0 ? '+' . (int)$g[6] . ' ' . ($lib_fx_label[$g[5]] ?? '') . '. ' : '') . $g[3];
  $CONSUMABLES[] = [$g[0], $g[2], $g[1], $desc, (int)$g[4], $type];
}

/* ----- Skills (sourced from the live skill defs, keyed to skills.code) ----- */
$SKILLS = [];
foreach (skill_defs() as $code => $d) {
  $SKILLS[] = [$code, $d['icon'], $d['name'], $d['effect'], $d['color']];
}

/* ----- Rarity helpers ----- */
function lib_rarity_color($r) {
  $m=['common'=>'#8a8fa8','uncommon'=>'#3bcf63','rare'=>'#4d6be8','elite'=>'#ff2d95','legendary'=>'#e8a33d'];
  return $m[$r] ?? '#8a8fa8';
}
function lib_rarity_class($r) { return 'lib-rarity-'.($r ?: 'common'); }
?>

<style>
#lib-canvas{display:block;width:100%;height:100px;border-radius:9px 9px 0 0}
#lib-head h2{text-shadow:0 0 14px rgba(25,240,199,.35)}
</style>
<div class="panel" id="lib-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="lib-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#128218; The Library</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">All items, weapons, armor, and skills available in The Sprawl.</p>
    </div>
  </div>
  <div class="tabs" style="margin:0 0 16px;border-top:1px solid var(--line)">
    <a class="tab <?= $tab==='weapons'?'is-active':'' ?>"     href="index.php?p=library&tab=weapons">&#9876; Weapons</a>
    <a class="tab <?= $tab==='armor'?'is-active':'' ?>"       href="index.php?p=library&tab=armor">&#128737; Armor</a>
    <a class="tab <?= $tab==='consumables'?'is-active':'' ?>" href="index.php?p=library&tab=consumables">&#128138; Consumables</a>
    <a class="tab <?= $tab==='materials'?'is-active':'' ?>"   href="index.php?p=library&tab=materials">&#128230; Materials</a>
    <a class="tab <?= $tab==='skills'?'is-active':'' ?>"      href="index.php?p=library&tab=skills">&#127891; Skills</a>
  </div>
</div>

<?php if ($tab === 'weapons' || $tab === 'armor'):
  $items = $tab === 'weapons' ? $BS_WEAPONS : $BS_ARMOR;
  $statLabel = $tab === 'weapons' ? 'ATK' : 'DEF';
  $statKey   = $tab === 'weapons' ? 'atk' : 'def';
?>
<div class="panel">
  <!-- Filter toolbar -->
  <div class="lib-toolbar">
    <div class="lib-toolbar-row">
      <div class="lib-search-wrap">
        <input type="text" id="libSearch" placeholder="&#128269; Search by name..." class="lib-search-input">
      </div>
      <div class="lib-sort-wrap">
        <select id="libSort" class="lib-sort-select">
          <option value="default">Sort: Default</option>
          <option value="price_asc">Price: Low &rarr; High</option>
          <option value="price_desc">Price: High &rarr; Low</option>
          <option value="stat_desc"><?= $statLabel ?>: High &rarr; Low</option>
          <option value="stat_asc"><?= $statLabel ?>: Low &rarr; High</option>
          <option value="name_asc">Name: A &rarr; Z</option>
          <option value="name_desc">Name: Z &rarr; A</option>
        </select>
      </div>
    </div>
    <div class="lib-rarity-filters">
      <button class="lib-rbtn active" data-rarity="all">All</button>
      <?php foreach (['common','uncommon','rare','elite','legendary'] as $r): ?>
        <button class="lib-rbtn" data-rarity="<?= $r ?>" style="--rc:<?= lib_rarity_color($r) ?>"><?= ucfirst($r) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="lib-result-count" id="libCount"></div>

  <div class="lib-grid" id="libGrid">
    <?php foreach ($items as $w):
      [$code,$icon,$name,$type,$atk,$def,$price,$desc,$rarity] = $w;
      $statVal = $tab === 'weapons' ? $atk : $def;
    ?>
    <div class="lib-card <?= lib_rarity_class($rarity) ?> lib-expandable"
         data-rarity="<?= $rarity ?>"
         data-price="<?= $price ?>"
         data-stat="<?= $statVal ?>"
         data-name="<?= strtolower(e($name)) ?>"
         style="cursor:pointer">
      <div class="lib-card-head">
        <div class="lib-icon"><?= $icon ?></div>
        <div style="flex:1;min-width:0">
          <div class="lib-name"><?= e($name) ?></div>
          <div class="lib-cat" style="color:<?= lib_rarity_color($rarity) ?>"><?= ucfirst($rarity) ?> &mdash; <?= ucfirst($type) ?></div>
        </div>
        <span class="lib-expand-arrow" style="color:var(--muted);font-size:11px;flex:none;align-self:center;transition:transform .2s">&#9660;</span>
      </div>
      <div class="lib-desc"><?= e($desc) ?></div>
      <div class="lib-stats">
        <?php if ($atk): ?><span class="lib-stat">&#9876; +<?= $atk ?> ATK</span><?php endif; ?>
        <?php if ($def): ?><span class="lib-stat def">&#128737; +<?= $def ?> DEF</span><?php endif; ?>
        <span class="lib-stat price">&#9733; <?= number_format($price) ?> cr</span>
      </div>
      <div class="lib-detail" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:12px">
        <div style="color:var(--muted);margin-bottom:6px"><?= e($desc) ?></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
          <?php if ($atk): ?><span style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.2);color:var(--neon2);padding:2px 8px;border-radius:4px;font-size:11px">&#9876; <?= $atk ?> ATK</span><?php endif; ?>
          <?php if ($def): ?><span style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);color:var(--accent);padding:2px 8px;border-radius:4px;font-size:11px">&#128737; <?= $def ?> DEF</span><?php endif; ?>
          <span style="background:rgba(232,212,77,.08);border:1px solid rgba(232,212,77,.2);color:#e8d44d;padding:2px 8px;border-radius:4px;font-size:11px">&#9670; <?= number_format($price) ?> cr</span>
        </div>
        <div style="font-size:11px">
          <span style="color:var(--muted)">Buy at: </span>
          <a href="index.php?p=blacksmith&tab=<?= $tab === 'weapons' ? 'weapons' : 'armor' ?>" style="color:var(--accent)">&#128296; The Forge</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" id="libEmpty" style="text-align:center;padding:32px 0;display:none">No items match your filters.</p>
</div>

<?php elseif ($tab === 'consumables'): ?>
<div class="panel">
  <div class="lib-toolbar">
    <div class="lib-toolbar-row">
      <div class="lib-search-wrap">
        <input type="text" id="libSearch" placeholder="&#128269; Search by name..." class="lib-search-input">
      </div>
      <div class="lib-sort-wrap">
        <select id="libSort" class="lib-sort-select">
          <option value="default">Sort: Default</option>
          <option value="price_asc">Price: Low &rarr; High</option>
          <option value="price_desc">Price: High &rarr; Low</option>
          <option value="name_asc">Name: A &rarr; Z</option>
        </select>
      </div>
    </div>
    <div class="lib-rarity-filters">
      <button class="lib-rbtn active" data-rarity="all">All</button>
      <button class="lib-rbtn" data-rarity="consumable" style="--rc:var(--accent)">Consumable</button>
      <button class="lib-rbtn" data-rarity="collectible" style="--rc:var(--neon2)">Collectible</button>
    </div>
  </div>

  <div class="lib-result-count" id="libCount"></div>

  <div class="lib-grid" id="libGrid">
    <?php foreach ($CONSUMABLES as $c): [$code,$icon,$name,$desc,$price,$type] = $c; ?>
    <div class="lib-card"
         style="border-left:3px solid <?= $type==='consumable' ? 'var(--accent)' : 'var(--neon2)' ?>"
         data-rarity="<?= $type ?>"
         data-price="<?= $price ?>"
         data-stat="0"
         data-name="<?= strtolower(e($name)) ?>">
      <div class="lib-card-head">
        <div class="lib-icon"><?= $icon ?></div>
        <div>
          <div class="lib-name"><?= e($name) ?></div>
          <div class="lib-cat" style="color:<?= $type==='consumable' ? 'var(--accent)' : 'var(--neon2)' ?>"><?= ucfirst($type) ?></div>
        </div>
      </div>
      <div class="lib-desc"><?= e($desc) ?></div>
      <div class="lib-stats"><span class="lib-stat price">&#9733; <?= number_format($price) ?> cr</span></div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" id="libEmpty" style="text-align:center;padding:32px 0;display:none">No items match your filters.</p>
</div>

<?php elseif ($tab === 'materials'): ?>
<div class="panel">
  <h3 style="margin-bottom:12px">Crafting Materials</h3>
  <?php
  $mats = array_filter($dbItems, fn($i) => !in_array($i['slot'],['weapon','armor'],true) && !in_array($i['category'],['weapon','armor'],true));
  if ($mats):
  ?>
  <div class="lib-toolbar" style="margin-bottom:14px">
    <div class="lib-toolbar-row">
      <div class="lib-search-wrap">
        <input type="text" id="libSearch" placeholder="&#128269; Search by name..." class="lib-search-input">
      </div>
    </div>
  </div>
  <div class="lib-result-count" id="libCount"></div>
  <div class="lib-grid" id="libGrid">
    <?php foreach ($mats as $m):
      $icons = ['raw'=>'&#128296;','component'=>'&#128295;','chem'=>'&#128137;','data'=>'&#128190;','gear'=>'&#9881;','misc'=>'&#128230;'];
      $icon = $icons[$m['category']] ?? '&#128230;';
    ?>
    <div class="lib-card lib-rarity-common"
         data-rarity="common" data-price="0" data-stat="0"
         data-name="<?= strtolower(e($m['name'])) ?>">
      <div class="lib-card-head">
        <div class="lib-icon"><?= $icon ?></div>
        <div>
          <div class="lib-name"><?= e($m['name']) ?></div>
          <div class="lib-cat"><?= e(ucfirst($m['category'])) ?></div>
        </div>
      </div>
      <?php if ($m['descr']): ?><div class="lib-desc"><?= e($m['descr']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" id="libEmpty" style="text-align:center;padding:32px 0;display:none">No items match your filters.</p>
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:32px 0">No materials catalogued yet.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'skills'): ?>
<div class="panel">
  <h3 style="margin-bottom:4px">Skillsoft Modules</h3>
  <p class="muted" style="margin-bottom:16px">Train these at the <a href="index.php?p=datacore&act=lab">Skillsoft Lab</a>.</p>
  <div class="lib-grid">
    <?php foreach ($SKILLS as $sk): [$code,$icon,$name,$desc,$color] = $sk; ?>
    <div class="lib-card" style="border-left:3px solid <?= $color ?>">
      <div class="lib-card-head">
        <div class="lib-icon" style="color:<?= $color ?>"><?= $icon ?></div>
        <div>
          <div class="lib-name"><?= e($name) ?></div>
          <div class="lib-cat" style="color:<?= $color ?>">Active Skill</div>
        </div>
      </div>
      <div class="lib-desc"><?= e($desc) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var grid=document.getElementById('libGrid');
  var empty=document.getElementById('libEmpty');
  var countEl=document.getElementById('libCount');
  var searchEl=document.getElementById('libSearch');
  var sortEl=document.getElementById('libSort');
  if(!grid) return;

  var rarityOrder={common:0,uncommon:1,rare:2,elite:3,legendary:4};
  var activeRarity='all';

  function getCards(){ return Array.from(grid.querySelectorAll('.lib-card')); }

  function apply(){
    var q=(searchEl&&searchEl.value)?searchEl.value.toLowerCase().trim():'';
    var sort=sortEl?sortEl.value:'default';
    var cards=getCards();

    // Filter
    var visible=cards.filter(function(c){
      var r=c.getAttribute('data-rarity');
      var n=c.getAttribute('data-name')||'';
      if(activeRarity!=='all' && r!==activeRarity) return false;
      if(q && n.indexOf(q)===-1) return false;
      return true;
    });
    var hidden=cards.filter(function(c){ return visible.indexOf(c)===-1; });
    hidden.forEach(function(c){ c.style.display='none'; });

    // Sort visible cards
    visible.sort(function(a,b){
      var pa=parseInt(a.getAttribute('data-price'),10)||0;
      var pb=parseInt(b.getAttribute('data-price'),10)||0;
      var sa=parseInt(a.getAttribute('data-stat'),10)||0;
      var sb=parseInt(b.getAttribute('data-stat'),10)||0;
      var na=(a.getAttribute('data-name')||'');
      var nb=(b.getAttribute('data-name')||'');
      var ra=rarityOrder[a.getAttribute('data-rarity')]||0;
      var rb=rarityOrder[b.getAttribute('data-rarity')]||0;
      if(sort==='price_asc')  return pa-pb;
      if(sort==='price_desc') return pb-pa;
      if(sort==='stat_desc')  return sb-sa;
      if(sort==='stat_asc')   return sa-sb;
      if(sort==='name_asc')   return na<nb?-1:na>nb?1:0;
      if(sort==='name_desc')  return nb<na?-1:nb>na?1:0;
      // default: rarity order then name
      return ra!==rb ? ra-rb : (na<nb?-1:na>nb?1:0);
    });

    // Re-append in sorted order
    visible.forEach(function(c){ c.style.display=''; grid.appendChild(c); });

    // Count
    if(countEl) countEl.textContent=visible.length+' item'+(visible.length!==1?'s':'');
    if(empty) empty.style.display=visible.length===0?'':'none';
  }

  // Rarity buttons
  document.querySelectorAll('.lib-rbtn').forEach(function(btn){
    btn.addEventListener('click',function(){
      document.querySelectorAll('.lib-rbtn').forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      activeRarity=btn.getAttribute('data-rarity');
      apply();
    });
  });

  if(searchEl) searchEl.addEventListener('input',apply);
  if(sortEl)   sortEl.addEventListener('change',apply);

  apply(); // run on load to set count

  // Expandable cards
  document.addEventListener('click', function(ev) {
    var card = ev.target.closest('.lib-expandable');
    if (!card) return;
    // Don't toggle if clicking a link inside the detail
    if (ev.target.closest('.lib-detail a')) return;
    var detail = card.querySelector('.lib-detail');
    var arrow  = card.querySelector('.lib-expand-arrow');
    if (!detail) return;
    var open = detail.style.display !== 'none';
    detail.style.display = open ? 'none' : 'block';
    if (arrow) arrow.style.transform = open ? '' : 'rotate(180deg)';
  });
})();
</script>

<script>
(function(){
'use strict';
/* ── Data archive header: server racks of glowing slabs + drifting glyphs ── */
var lc=document.getElementById('lib-canvas');
if(!lc) return;
var c=lc.getContext('2d');
var LW=560, LH=100;
var dpr=Math.min(2,window.devicePixelRatio||1);
lc.width=LW*dpr; lc.height=LH*dpr;
c.scale(dpr,dpr);
var GLYPHS='アイウエオ0123456789◆◈※'.split('');
var floats=[];
for(var i=0;i<14;i++) floats.push({x:Math.random()*LW,y:Math.random()*LH,v:.12+Math.random()*.25,g:GLYPHS[Math.floor(Math.random()*GLYPHS.length)],p:Math.random()*9});

function lLoop(t){
  if(!document.body.contains(lc)) return;
  requestAnimationFrame(lLoop);
  c.clearRect(0,0,LW,LH);
  var bg=c.createLinearGradient(0,0,0,LH);
  bg.addColorStop(0,'#090a10'); bg.addColorStop(1,'#0d0e16');
  c.fillStyle=bg; c.fillRect(0,0,LW,LH);

  // archive racks: columns of glowing data slabs
  for(var rx=0;rx<8;rx++){
    var colx=30+rx*68;
    c.fillStyle='#10101c';
    c.fillRect(colx,12,44,LH-22);
    c.strokeStyle='rgba(255,255,255,.07)';
    c.strokeRect(colx+.5,12.5,44,LH-22);
    for(var sy=0;sy<6;sy++){
      var lit=((t/900+rx*1.7+sy*1.1)%6)<3.8;
      var hue=(rx+sy)%3;
      var col=hue===0?'25,240,199':(hue===1?'232,212,77':'255,45,149');
      c.fillStyle='rgba('+col+','+(lit?(0.20+0.12*Math.sin(t/700+rx+sy)):0.05)+')';
      c.fillRect(colx+4,17+sy*12,36,8);
    }
  }

  // drifting index glyphs
  c.font='10px monospace'; c.textAlign='center';
  for(var fi=0;fi<floats.length;fi++){
    var F=floats[fi];
    F.y-=F.v;
    if(F.y<-6){ F.y=LH+6; F.x=Math.random()*LW; F.g=GLYPHS[Math.floor(Math.random()*GLYPHS.length)]; }
    c.fillStyle='rgba(25,240,199,'+(0.12+0.10*Math.sin(t/600+F.p))+')';
    c.fillText(F.g,F.x,F.y);
  }

  // reading-lamp sweep
  var lx2=((t/34)%(LW+240))-120;
  var lg2=c.createLinearGradient(lx2-70,0,lx2+70,0);
  lg2.addColorStop(0,'rgba(232,212,77,0)'); lg2.addColorStop(.5,'rgba(232,212,77,.05)'); lg2.addColorStop(1,'rgba(232,212,77,0)');
  c.fillStyle=lg2; c.fillRect(0,0,LW,LH);
}
requestAnimationFrame(lLoop);
})();
</script>
