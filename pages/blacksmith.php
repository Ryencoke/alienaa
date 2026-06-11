<?php /* pages/blacksmith.php — The Forge: weapons and armor shop */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

// Ensure purchase table exists
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS blacksmith_owned (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    player_id  INT NOT NULL,
    item_code  VARCHAR(64) NOT NULL,
    bought_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_player_item (player_id, item_code),
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// Catalog  [code, name, icon, type, sub, atk, def, spd, price, desc]
$CATALOG = [
  // ==================== WEAPONS (25) ====================
  ['mono_blade',     'Mono-Edge Blade',     '&#9876;',  'weapon', 'Melee',   3,  0,  1,   800,  'Monomolecular edge. Whisper-quiet, lethal up close.'],
  ['ceramic_blade',  'Ceramic Blade',       '&#9876;',  'weapon', 'Melee',   2,  0,  3,   600,  'Heat-treated ceramic edge. Light, fast, disposable.'],
  ['corrosive_dart', 'Corrosive Dart Gun',  '&#128300;','weapon', 'Ranged',  4,  0,  2,  1800,  'Industrial acid in every round. Eats through light armor.'],
  ['plasma_pistol',  'Plasma Pistol',       '&#128299;','weapon', 'Ranged',  5,  0,  0,  2200,  'Single-shot plasma rounds. Standard Sprawl enforcer sidearm.'],
  ['shock_baton',    'Shock Baton',         '&#9889;',  'weapon', 'Melee',   4,  2,  0,  3500,  'Electromagnetic stunner. Crowd control with a nasty bite.'],
  ['entropy_knife',  'Entropy Knife',       '&#9876;',  'weapon', 'Stealth', 6,  0,  3,  4000,  'Quantum edge decays chemical bonds on contact. Banned.'],
  ['synapse_spike',  'Synapse Spike',       '&#128296;','weapon', 'Stealth', 7,  0,  1,  4200,  'Jams cortical implants on critical hits. Illegal citywide.'],
  ['neuro_injector', 'Neurotoxin Injector', '&#128300;','weapon', 'Stealth', 6,  0,  3,  5000,  'Fast-acting synthetic toxin. Hits before they feel it.'],
  ['pulse_smg',      'Pulse SMG',           '&#128299;','weapon', 'Ranged',  7,  0,  2,  5500,  'High-ROF electromagnetic burst. Drains shield stacks fast.'],
  ['data_lance',     'Data Lance',          '&#9876;',  'weapon', 'Melee',   8,  1,  1,  6500,  'Carbon-ceramic spike loaded with intrusion code. Smart kill.'],
  ['sonic_driver',   'Sonic Driver',        '&#128299;','weapon', 'Tech',    9,  0, -1,  7000,  'Focused directional sound cannon. Shatters internal organs.'],
  ['arc_rifle',      'Arc Rifle',           '&#127775;','weapon', 'Ranged',  9,  0, -1,  7500,  'High-voltage pulse weapon. Expensive, loud, devastating.'],
  ['ghost_pistol',   'Ghost Pistol',        '&#128299;','weapon', 'Stealth', 8,  1,  2,  9500,  'Subsonic rounds — no muzzle flash, no heat signature.'],
  ['void_blade',     'Void Blade',          '&#9876;',  'weapon', 'Melee',  11,  0,  2,  9000,  'Phase-shifted edge. Slips through most barrier fields.'],
  ['breacher_axe',   'Breacher Axe',        '&#9876;',  'weapon', 'Heavy',  11,  0, -1, 10000,  'Hydraulic spike-axe engineered for armored breach ops.'],
  ['taser_web',      'Taser Web Launcher',  '&#9889;',  'weapon', 'Tech',    6,  0,  0,  3800,  'Filament net at 50,000V. Immobilizes and burns through mesh.'],
  ['nano_swarm',     'Nano Swarm',          '&#128296;','weapon', 'Tech',   10,  0,  0,  8000,  'Micro-bots deployed on impact. Dissolve soft tissue from inside.'],
  ['thermal_lance',  'Thermal Lance',       '&#9876;',  'weapon', 'Heavy',  14,  0, -1, 15000,  'Plasma-focused cutting beam at melee range. Melts hardpoints.'],
  ['scatter_cannon', 'Scatter Cannon',      '&#128299;','weapon', 'Heavy',  13,  0, -2, 12000,  'Wide spread burst. Devastating in tight corridors.'],
  ['smart_carbine',  'Smart Carbine',       '&#128299;','weapon', 'Ranged', 16,  0,  0, 19000,  'Target-lock AI integrated into the stock and barrel assembly.'],
  ['gauss_rifle',    'Gauss Rifle',         '&#128299;','weapon', 'Heavy',  18,  0, -2, 22000,  'Electromagnetic rail accelerator. Long range, brutal velocity.'],
  ['hyper_katana',   'Hyper Katana',        '&#9876;',  'weapon', 'Melee',  20,  0,  3, 28000,  'Vibro-edged titanium blade tuned to 12kHz resonance frequency.'],
  ['void_cannon',    'Void Cannon',         '&#128299;','weapon', 'Heavy',  25,  0, -3, 35000,  'Annihilation-class weapon. Banned in six city-states.'],
  ['hex_blade',      'Hex Blade',           '&#9876;',  'weapon', 'Legendary',22, 2, 1, 45000,  'Cursed edge from an illegal foundry. It whispers back.'],
  ['ghost_katana',   'Ghost Katana',        '&#9876;',  'weapon', 'Legendary',24, 0, 4, 55000,  'Phased monomolecular edge. Passes through all known alloys.'],

  // ==================== ARMOR (25) ====================
  ['ferro_coat',      'Ferro Coat',         '&#129413;','armor', 'Light',    0,  5,  0,  1200,  'Ferromagnetic fiber coat. Deflects shrapnel and debris.'],
  ['combat_vest',     'Combat Vest',        '&#128737;','armor', 'Light',    0,  3,  1,   600,  'Lightweight ballistic weave. Move fast, take a hit.'],
  ['camo_wraps',      'Camo Wraps',         '&#129399;','armor', 'Stealth',  0,  4,  4,  4200,  'Adaptive camouflage fabric. Hard to spot, harder to hit.'],
  ['kinetic_weave',   'Kinetic Weave',      '&#128737;','armor', 'Medium',   0,  8,  1,  3500,  'Dissipates kinetic energy across the entire suit surface.'],
  ['ablative_coat',   'Ablative Coat',      '&#128737;','armor', 'Medium',   0,  8,  0,  3200,  'Sacrificial layers burn away on impact. Disposable protection.'],
  ['riot_shell',      'Riot Shell',         '&#128737;','armor', 'Medium',   0,  7,  0,  2800,  'Hardened polymer shell. Standard perimeter security issue.'],
  ['shadow_suit',     'Shadow Suit',        '&#128737;','armor', 'Stealth',  0,  6,  3,  5500,  'Stealth-optimized flexweave. Blocks thermal imaging.'],
  ['carbon_bodysuit', 'Carbon Bodysuit',    '&#128737;','armor', 'Medium',   1, 10,  1,  7000,  'Full-body carbon-nanotube weave. Light and highly protective.'],
  ['reflex_suit',     'Reflex Suit',        '&#128737;','armor', 'Tech',     2,  8,  2,  8500,  'Myomer-lined suit. Augments reaction speed under fire.'],
  ['exo_frame',       'Exo-Frame',          '&#129302;','armor', 'Heavy',    1, 12, -1,  6000,  'Powered exoskeletal rig. Near-impenetrable, slows footwork.'],
  ['neural_weave',    'Neural Weave',       '&#129504;','armor', 'Tech',     0,  9,  2,  9500,  'Bio-integrated smart armor. Predicts strikes before they land.'],
  ['nano_skin',       'Nano Skin',          '&#128737;','armor', 'Tech',     1,  9,  1, 11000,  'Living mesh that redistributes impact force over the body.'],
  ['phantom_wrap',    'Phantom Wrap',       '&#128737;','armor', 'Stealth',  0, 12,  2, 14000,  'Chameleon fabric shifts pattern to match surroundings.'],
  ['subdermal_mesh',  'Subdermal Mesh',     '&#128737;','armor', 'Stealth',  0, 11,  0, 12000,  'Embedded under-skin weave. Invisible to scanners.'],
  ['ghost_cloak',     'Ghost Cloak',        '&#128737;','armor', 'Stealth',  0, 13,  2, 16000,  'Phase-shifting coat — partial optical camouflage.'],
  ['circuit_shroud',  'Circuit Shroud',     '&#128737;','armor', 'Tech',     2, 10,  0, 13000,  'EMP-hardened longcoat with embedded counter-intrusion mesh.'],
  ['surge_plate',     'Surge Plate',        '&#128737;','armor', 'Heavy',    0, 14, -1, 17000,  'Capacitor-fed active armor — discharges on every solid impact.'],
  ['biomech_skin',    'Biomech Skin',       '&#128737;','armor', 'Legendary',3, 13,  1, 18000,  'Genetically-engineered symbiotic armor organism. It breathes.'],
  ['reactor_jacket',  'Reactor Jacket',     '&#128737;','armor', 'Heavy',    0, 17, -1, 22000,  'Fusion-cell powered shield emitters in the shoulder pads.'],
  ['chrome_shell',    'Chrome Shell',       '&#128737;','armor', 'Heavy',    0, 19, -1, 26000,  'Mirror-polished deflection plating. Bounces directed energy.'],
  ['juggernaut_plate','Juggernaut Plate',   '&#128737;','armor', 'Heavy',    0, 20, -3, 30000,  'Military-grade ceramic-titanium. Near-impenetrable citadel.'],
  ['pulse_shield',    'Pulse Shield Rig',   '&#128737;','armor', 'Heavy',    0, 16, -2, 20000,  'Electrostatic barrier shell. Regenerates between engagements.'],
  ['quantum_vest',    'Quantum Vest',       '&#128737;','armor', 'Legendary',0, 23,  1, 40000,  'Quantum-locked protection field. Probability-based defense grid.'],
  ['aegis_frame',     'Aegis Frame',        '&#128737;','armor', 'Legendary',0, 25,  0, 45000,  'Corporate elite bodyguard issue. Rarely leaves the vault.'],
  ['void_carapace',   'Void Carapace',      '&#128737;','armor', 'Legendary',0, 28, -2, 50000,  'Salvaged from a downed warship. Absorbs almost any impact.'],
];

// Rarity tier from sub-category / price — drives card + detail colors
function bs_tier($sub, $price): array {
  if ($sub === 'Legendary') return ['LEGENDARY', '#e8d44d'];
  if ($price >= 15000)      return ['EPIC',      '#ff2d95'];
  if ($price >= 6000)       return ['RARE',      '#19f0c7'];
  if ($price >= 2500)       return ['UNCOMMON',  '#4d9be8'];
  return                           ['COMMON',    '#9aa3b8'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
  $code = $_POST['item_code'] ?? '';
  $item = null;
  foreach ($CATALOG as $c) { if ($c[0] === $code) { $item = $c; break; } }
  try {
    if (!$item) throw new RuntimeException('Item not found.');
    $price = $item[8];
    // Charge first (atomic), then record ownership — all in one transaction.
    // (The old code inserted ownership and then crashed on PDO::rowCount(),
    //  handing out free gear; this also closes that hole.)
    $pdo->beginTransaction();
    $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
    $u->execute([$price, $pid, $price]);
    if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough creds. Need ' . number_format($price) . ' cr.'); }
    $ins = $pdo->prepare('INSERT IGNORE INTO blacksmith_owned (player_id, item_code) VALUES (?,?)');
    $ins->execute([$pid, $code]);
    if ($ins->rowCount() < 1) { $pdo->rollBack(); throw new RuntimeException('You already own that item.'); }
    $pdo->commit();
    $player = current_player();
    $msg = 'Forged: ' . e($item[1]) . '. Check your Inventory.';
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

$ownedQ = $pdo->prepare('SELECT item_code FROM blacksmith_owned WHERE player_id = ?');
$ownedQ->execute([$pid]);
$owned = array_flip($ownedQ->fetchAll(PDO::FETCH_COLUMN));

$tab = $_GET['tab'] ?? 'weapons';
if (!in_array($tab, ['weapons','armor'], true)) $tab = 'weapons';

$weapons = array_filter($CATALOG, fn($c) => $c[3] === 'weapon');
$armor   = array_filter($CATALOG, fn($c) => $c[3] === 'armor');
$items   = $tab === 'weapons' ? $weapons : $armor;
$pocket  = (int)$player['creds_pocket'];
?>
<style>
#forge-canvas{display:block;width:100%;height:120px;border-radius:9px 9px 0 0}
.bs-card{position:relative;overflow:hidden;cursor:pointer;transition:transform .12s,border-color .15s,box-shadow .15s}
.bs-card:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.4)}
.bs-card.selected{border-color:var(--tier-col)!important;box-shadow:0 0 14px var(--tier-glow)}
.bs-card.broke .sc-pr{color:var(--neon2)}
.bs-card.owned-card{opacity:.65}
.bs-tier{position:absolute;top:6px;right:6px;font-size:8px;font-weight:700;letter-spacing:.08em;padding:1px 6px;border-radius:8px;border:1px solid var(--tier-col);color:var(--tier-col);background:rgba(0,0,0,.35)}
.bs-card .sc-ic{transition:transform .15s,text-shadow .15s}
.bs-card:hover .sc-ic{transform:scale(1.12);text-shadow:0 0 12px var(--tier-col)}
.bs-statbar{height:5px;border-radius:3px;background:rgba(255,255,255,.07);overflow:hidden;flex:1}
.bs-statbar>div{height:100%;border-radius:3px;transition:width .3s ease}
#bs-detail{border:1px solid var(--tier-col,var(--line));box-shadow:0 0 18px var(--tier-glow,transparent);transition:border-color .2s,box-shadow .2s}
.bs-head h2{text-shadow:0 0 14px rgba(255,122,24,.35)}
</style>

<div class="panel bs-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="forge-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#9874; The Forge</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Forged in the underbelly. Tested in the Sprawl. No returns.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:10px;text-align:right">
      <div class="muted" style="font-size:10px;text-shadow:0 1px 4px #000">POCKET</div>
      <div id="bs-creds" style="font-size:19px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent);text-shadow:0 1px 6px #000"><?= number_format($pocket) ?> <span style="font-size:11px;font-weight:400">cr</span></div>
    </div>
    <button id="forge-mute" onclick="toggleForgeSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
  <?php if ($msg): ?><div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>" style="margin:10px 14px 0"><?= $msg ?></div><?php endif; ?>
  <div class="tabs" style="margin:12px 0 0;border-top:1px solid var(--line)">
    <a class="tab <?= $tab==='weapons'?'is-active':'' ?>" href="index.php?p=blacksmith&tab=weapons">&#9876; Weapons <span class="bz-count"><?= count($weapons) ?></span></a>
    <a class="tab <?= $tab==='armor'?'is-active':'' ?>"   href="index.php?p=blacksmith&tab=armor">&#128737; Armor <span class="bz-count"><?= count($armor) ?></span></a>
  </div>
</div>

<!-- Detail panel (JS-driven) -->
<div class="shop-detail" id="bs-detail" style="display:none">
  <div class="sd-head">
    <div class="sd-ic" id="bs-d-ic"></div>
    <div class="sd-info">
      <div class="sd-name" id="bs-d-name"></div>
      <div class="sd-type" id="bs-d-type"></div>
      <div class="sd-desc" id="bs-d-desc"></div>
    </div>
  </div>
  <div id="bs-d-stats" style="display:flex;flex-direction:column;gap:6px;margin:10px 0"></div>
  <div class="sd-footer">
    <div class="sd-price" id="bs-d-price"></div>
    <form method="post" id="bs-buy-form" style="margin:0">
      <input type="hidden" name="action" value="buy">
      <input type="hidden" name="item_code" id="bs-buy-code">
      <button type="submit" id="bs-buy-btn">&#9874; Forge It</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="shop-grid" id="bs-grid">
    <?php foreach ($items as $c):
      $isOwned = isset($owned[$c[0]]);
      [$tier, $tcol] = bs_tier($c[4], (int)$c[8]);
      $broke = !$isOwned && $pocket < (int)$c[8];
    ?>
    <div class="shop-card bs-card<?= $isOwned ? ' owned-card' : '' ?><?= $broke ? ' broke' : '' ?>"
         style="--tier-col:<?= $tcol ?>;--tier-glow:<?= $tcol ?>33"
         data-code="<?= e($c[0]) ?>"
         data-ic="<?= $c[2] ?>"
         data-name="<?= e($c[1]) ?>"
         data-type="<?= e(ucfirst($c[3]).' &mdash; '.$c[4]) ?>"
         data-desc="<?= e($c[9]) ?>"
         data-atk="<?= (int)$c[5] ?>"
         data-def="<?= (int)$c[6] ?>"
         data-spd="<?= (int)$c[7] ?>"
         data-price="<?= (int)$c[8] ?>"
         data-tier="<?= $tier ?>"
         data-tcol="<?= $tcol ?>"
         data-owned="<?= $isOwned ? '1' : '0' ?>">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $tcol ?>,transparent)"></div>
      <span class="bs-tier"><?= $tier ?></span>
      <div class="sc-ic"><?= $c[2] ?></div>
      <div class="sc-nm"><?= e($c[1]) ?></div>
      <div class="sc-sub muted"><?= e($c[4]) ?></div>
      <div class="sc-pr"><?= number_format($c[8]) ?> cr</div>
      <?php if ($isOwned): ?><div class="sc-owned">&#10003; Owned</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
'use strict';

/* ── Ambient forge scene ──────────────────────────────────────────────── */
var fc=document.getElementById('forge-canvas');
if(fc){
  var fctx=fc.getContext('2d');
  var FW=560, FH=120;
  var fdpr=Math.min(2,window.devicePixelRatio||1);
  fc.width=FW*fdpr; fc.height=FH*fdpr;
  fctx.scale(fdpr,fdpr);
  var embers=[], fsparks=[], nextSpark=0;

  function forgeLoop(t){
    if(!document.body.contains(fc)) return;
    requestAnimationFrame(forgeLoop);
    fctx.clearRect(0,0,FW,FH);
    // backdrop
    var bg=fctx.createLinearGradient(0,0,0,FH);
    bg.addColorStop(0,'#0c0a10'); bg.addColorStop(1,'#120c0a');
    fctx.fillStyle=bg; fctx.fillRect(0,0,FW,FH);

    // furnace mouth (left) with flicker
    var flick=.75+.25*Math.sin(t/90)+.08*Math.sin(t/37);
    var fg=fctx.createRadialGradient(70,FH-18,4,70,FH-18,84*flick);
    fg.addColorStop(0,'rgba(255,190,90,'+(.55*flick)+')');
    fg.addColorStop(.35,'rgba(255,110,30,'+(.30*flick)+')');
    fg.addColorStop(1,'rgba(255,60,10,0)');
    fctx.fillStyle=fg; fctx.fillRect(0,0,240,FH);
    // furnace arch silhouette
    fctx.fillStyle='#0a0809';
    fctx.beginPath();
    fctx.moveTo(20,FH); fctx.lineTo(20,52); fctx.quadraticCurveTo(70,16,120,52); fctx.lineTo(120,FH);
    fctx.lineTo(108,FH); fctx.lineTo(108,58); fctx.quadraticCurveTo(70,32,32,58); fctx.lineTo(32,FH);
    fctx.closePath(); fctx.fill();
    // inner glow core
    var core=fctx.createRadialGradient(70,FH-8,2,70,FH-8,40);
    core.addColorStop(0,'rgba(255,230,160,'+(.85*flick)+')');
    core.addColorStop(.5,'rgba(255,130,40,'+(.5*flick)+')');
    core.addColorStop(1,'rgba(255,60,10,0)');
    fctx.fillStyle=core;
    fctx.fillRect(32,58,76,FH-58);

    // anvil silhouette (center-right)
    var ax=330,ay=FH-30;
    fctx.fillStyle='#16121a';
    fctx.beginPath(); // horn + body
    fctx.moveTo(ax-44,ay); fctx.lineTo(ax+34,ay);
    fctx.quadraticCurveTo(ax+66,ay+2,ax+58,ay+12);
    fctx.lineTo(ax-44,ay+12); fctx.closePath(); fctx.fill();
    fctx.fillRect(ax-16,ay+12,28,8);   // waist
    fctx.fillRect(ax-26,ay+20,50,8);   // foot
    // rim light from furnace side
    fctx.fillStyle='rgba(255,140,50,'+(.30*flick)+')';
    fctx.fillRect(ax-44,ay,4,12);
    fctx.fillRect(ax-44,ay,78,1.5);

    // periodic spark burst on the anvil
    if(t>nextSpark){
      nextSpark=t+1800+Math.random()*2600;
      for(var i=0;i<10;i++){
        var a=-Math.PI*(.15+Math.random()*.7);
        var sp=1+Math.random()*2.4;
        fsparks.push({x:ax,y:ay,vx:Math.cos(a)*sp,vy:Math.sin(a)*sp,life:1,col:Math.random()<.3?'#fff3d0':'#ffb347'});
      }
    }
    for(var si=fsparks.length-1;si>=0;si--){
      var S=fsparks[si];
      S.x+=S.vx; S.y+=S.vy; S.vy+=.07; S.life-=.03;
      if(S.life<=0){fsparks.splice(si,1);continue;}
      fctx.globalAlpha=Math.max(0,S.life);
      fctx.fillStyle=S.col;
      fctx.fillRect(S.x,S.y,1.8,1.8);
    }
    fctx.globalAlpha=1;

    // rising embers
    if(Math.random()<.32) embers.push({x:40+Math.random()*70,y:FH-6,vy:.3+Math.random()*.5,dx:Math.random()*9,life:1,s:1+Math.random()*1.8});
    for(var ei=embers.length-1;ei>=0;ei--){
      var E=embers[ei];
      E.y-=E.vy; E.life-=.008;
      if(E.life<=0||E.y<-4){embers.splice(ei,1);continue;}
      fctx.globalAlpha=Math.max(0,E.life)*.8;
      fctx.fillStyle=E.life>.55?'#ffb347':'#a33c14';
      fctx.fillRect(E.x+Math.sin(t/600+E.dx)*5,E.y,E.s,E.s);
    }
    fctx.globalAlpha=1;

    // heat haze line + vignette
    fctx.fillStyle='rgba(0,0,0,.25)';
    fctx.fillRect(0,0,FW,14);
  }
  requestAnimationFrame(forgeLoop);
}

/* ── Detail panel ─────────────────────────────────────────────────────── */
var detail=document.getElementById('bs-detail');
var grid=document.getElementById('bs-grid');
if(grid&&detail){
  var sel=null;
  var MAXES={ATK:28,DEF:28,SPD:4};
  function statRow(label,val,col){
    if(val===0) return '';
    var pct=Math.min(100,Math.abs(val)/MAXES[label]*100);
    var barCol=val>0?col:'var(--neon2)';
    return '<div style="display:flex;align-items:center;gap:8px;font-size:11px">'
      +'<span style="width:30px;color:var(--muted)">'+label+'</span>'
      +'<div class="bs-statbar"><div style="width:'+pct+'%;background:'+barCol+'"></div></div>'
      +'<b style="width:34px;text-align:right;color:'+barCol+'">'+(val>0?'+':'')+val+'</b></div>';
  }
  grid.addEventListener('click',function(e){
    var card=e.target.closest('.bs-card'); if(!card) return;
    if(sel) sel.classList.remove('selected');
    card.classList.add('selected'); sel=card;
    var tcol=card.dataset.tcol||'#19f0c7';
    detail.style.setProperty('--tier-col',tcol);
    detail.style.setProperty('--tier-glow',tcol+'33');
    document.getElementById('bs-d-ic').innerHTML=card.dataset.ic;
    document.getElementById('bs-d-ic').style.textShadow='0 0 14px '+tcol;
    var nm=document.getElementById('bs-d-name');
    nm.textContent=card.dataset.name; nm.style.color=tcol;
    document.getElementById('bs-d-type').innerHTML=card.dataset.type
      +' &middot; <span style="color:'+tcol+';letter-spacing:.06em;font-size:10px">'+card.dataset.tier+'</span>';
    document.getElementById('bs-d-desc').textContent=card.dataset.desc;
    var atk=parseInt(card.dataset.atk),def=parseInt(card.dataset.def),spd=parseInt(card.dataset.spd);
    document.getElementById('bs-d-stats').innerHTML=
      statRow('ATK',atk,'#ff6b35')+statRow('DEF',def,'#4d9be8')+statRow('SPD',spd,'#3bcf63');
    var price=parseInt(card.dataset.price);
    document.getElementById('bs-d-price').textContent=price.toLocaleString()+' cr';
    document.getElementById('bs-buy-code').value=card.dataset.code;
    var btn=document.getElementById('bs-buy-btn');
    var form=document.getElementById('bs-buy-form');
    form.setAttribute('data-forgefx','1');
    form.setAttribute('data-forge-icon',card.dataset.ic);
    form.setAttribute('data-forge-name',card.dataset.name);
    form.setAttribute('data-forge-col',tcol);
    if(card.dataset.owned==='1'){
      btn.innerHTML='&#10003; Owned'; btn.disabled=true; btn.style.opacity='0.5';
      form.removeAttribute('data-forgefx');
    } else {
      btn.innerHTML='&#9874; Forge It'; btn.disabled=false; btn.style.opacity='1';
      btn.style.borderColor=tcol+'88'; btn.style.color=tcol;
    }
    detail.style.display='block';
    detail.scrollIntoView({behavior:'smooth',block:'nearest'});
  });
}
})();
</script>

<script>
/* Forge purchase FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._forgeFxBound) return;
  window._forgeFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#forgefx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(5,3,2,.62);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#forgefx.show{opacity:1}'
    +'.ffx-stage{position:relative;width:170px;height:150px}'
    +'.ffx-anvil{position:absolute;left:50%;bottom:18px;transform:translateX(-50%);width:104px;height:14px;'
    +'background:#1b1620;border-radius:3px 8px 3px 3px;box-shadow:0 14px 0 -4px #15111c,0 22px 0 -6px #1b1620,0 0 18px rgba(255,120,30,.25)}'
    +'.ffx-item{position:absolute;left:50%;bottom:36px;transform:translateX(-50%);font-size:34px;'
    +'color:#ffb347;text-shadow:0 0 18px #ff7a18,0 0 36px #ff7a1888;animation:ffxHeat 1.5s ease-in-out}'
    +'@keyframes ffxHeat{0%,70%{filter:brightness(1.4) saturate(1.4)}100%{filter:none}}'
    +'.ffx-hammer{position:absolute;left:50%;bottom:64px;font-size:30px;transform-origin:85% 85%;'
    +'animation:ffxSwing .42s ease-in 3}'
    +'@keyframes ffxSwing{0%{transform:rotate(-72deg)}55%{transform:rotate(8deg)}70%{transform:rotate(2deg)}100%{transform:rotate(-72deg)}}'
    +'.ffx-spark{position:absolute;width:3px;height:3px;border-radius:50%;pointer-events:none}'
    +'.ffx-name{position:absolute;left:50%;top:100%;transform:translateX(-50%);white-space:nowrap;font-size:12px;'
    +'font-weight:700;opacity:0;animation:ffxName .3s 1.35s forwards}'
    +'@keyframes ffxName{to{opacity:1}}'
    +'.ffx-flash{position:absolute;inset:-30px;border-radius:50%;opacity:0}';
  document.head.appendChild(css);

  var fac=null, fmuted=localStorage.getItem('forgeMuted')==='1';
  function fsfx(freq,dur,type,vol,slide){
    if(fmuted) return;
    try{
      fac=fac||new (window.AudioContext||window.webkitAudioContext)();
      var o=fac.createOscillator(),g=fac.createGain();
      o.type=type||'sine'; o.frequency.value=freq;
      if(slide) o.frequency.exponentialRampToValueAtTime(slide,fac.currentTime+dur);
      g.gain.value=vol||.05;
      g.gain.exponentialRampToValueAtTime(.0001,fac.currentTime+dur);
      o.connect(g); g.connect(fac.destination);
      o.start(); o.stop(fac.currentTime+dur);
    }catch(e){}
  }
  function clang(){ fsfx(170,.16,'square',.05); fsfx(1900+Math.random()*500,.07,'sine',.04); }
  window.toggleForgeSound=function(){
    fmuted=!fmuted; localStorage.setItem('forgeMuted',fmuted?'1':'0');
    var b=document.getElementById('forge-mute'); if(b) b.innerHTML=fmuted?'&#128263;':'&#128266;';
    if(!fmuted) fsfx(660,.08,'sine',.05);
  };
  (function(){ var b=document.getElementById('forge-mute'); if(b) b.innerHTML=fmuted?'&#128263;':'&#128266;'; })();

  function sparkBurst(stage){
    for(var i=0;i<10;i++){
      var s=document.createElement('div'); s.className='ffx-spark';
      s.style.left='50%'; s.style.bottom='40px';
      s.style.background=Math.random()<.3?'#fff3d0':'#ffb347';
      s.style.boxShadow='0 0 6px #ff7a18';
      stage.appendChild(s);
      var a=-Math.PI*(.1+Math.random()*.8), sp=40+Math.random()*55;
      var dx=Math.cos(a)*sp, dy=Math.sin(a)*sp;
      s.animate([{transform:'translate(0,0)',opacity:1},{transform:'translate('+dx+'px,'+(-dy)+'px)',opacity:0}],
        {duration:420+Math.random()*220,easing:'cubic-bezier(.1,.6,.4,1)'});
      setTimeout(function(el){return function(){if(el.parentNode)el.remove();};}(s),700);
    }
  }

  function forgeOverlay(icon,name,col){
    var old=document.getElementById('forgefx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='forgefx';
    o.innerHTML='<div class="ffx-stage">'
      +'<div class="ffx-item">'+icon+'</div>'
      +'<div class="ffx-hammer">&#128296;</div>'
      +'<div class="ffx-anvil"></div>'
      +'<div class="ffx-name" style="color:'+col+';text-shadow:0 0 12px '+col+'">FORGED &mdash; '+name+'</div>'
      +'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    var stage=o.querySelector('.ffx-stage');
    // three hammer strikes ≈ keyframe impact at ~55% of each .42s swing
    [230,650,1070].forEach(function(ms){ setTimeout(function(){ clang(); sparkBurst(stage); },ms); });
    setTimeout(function(){ // final shimmer: item cools into tier color
      var it=o.querySelector('.ffx-item');
      if(it){ it.style.color=col; it.style.textShadow='0 0 18px '+col; }
      fsfx(523,.1,'sine',.045); setTimeout(function(){fsfx(784,.16,'sine',.045);},90);
    },1500);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},2300);
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute||!f.getAttribute('data-forgefx')) return;
    forgeOverlay(f.getAttribute('data-forge-icon')||'&#9876;',
                 f.getAttribute('data-forge-name')||'',
                 f.getAttribute('data-forge-col')||'#19f0c7');
  },true);
})();
</script>
