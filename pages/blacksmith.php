<?php /* pages/blacksmith.php — The Forge: weapons and armor shop */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
  $code = $_POST['item_code'] ?? '';
  $item = null;
  foreach ($CATALOG as $c) { if ($c[0] === $code) { $item = $c; break; } }
  try {
    if (!$item) throw new RuntimeException('Item not found.');
    $price = $item[8];
    if ((int)$player['creds_pocket'] < $price) throw new RuntimeException('Not enough creds. Need ' . number_format($price) . ' cr.');
    $pdo->prepare('INSERT IGNORE INTO blacksmith_owned (player_id, item_code) VALUES (?,?)')->execute([$pid, $code]);
    if ($pdo->rowCount() < 1) throw new RuntimeException('You already own that item.');
    $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ?')->execute([$price, $pid]);
    $player = current_player();
    $msg = 'Purchased: ' . e($item[1]) . '. Check your Inventory.';
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$ownedQ = $pdo->prepare('SELECT item_code FROM blacksmith_owned WHERE player_id = ?');
$ownedQ->execute([$pid]);
$owned = array_flip($ownedQ->fetchAll(PDO::FETCH_COLUMN));

$tab = $_GET['tab'] ?? 'weapons';
if (!in_array($tab, ['weapons','armor'], true)) $tab = 'weapons';

$weapons = array_filter($CATALOG, fn($c) => $c[3] === 'weapon');
$armor   = array_filter($CATALOG, fn($c) => $c[3] === 'armor');
$items   = $tab === 'weapons' ? $weapons : $armor;
?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="margin:0">&#9874; The Blacksmith</h2>
      <p class="muted" style="margin:4px 0 0">Forged in the underbelly. Tested in the Sprawl. No returns.</p>
    </div>
    <div style="text-align:right">
      <div class="muted" style="font-size:11px">Pocket</div>
      <div id="bs-creds" style="font-size:20px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent)"><?= number_format($player['creds_pocket']) ?> <span style="font-size:12px;font-weight:400">cr</span></div>
    </div>
  </div>
  <?php if ($msg): ?><div class="flash flash-ok" style="margin-top:12px"><?= $msg ?></div><?php endif; ?>
  <div class="tabs" style="margin:14px -14px -14px;border-top:1px solid var(--line)">
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
  <div class="sd-stats" id="bs-d-stats"></div>
  <div class="sd-footer">
    <div class="sd-price" id="bs-d-price"></div>
    <form method="post" id="bs-buy-form" style="margin:0">
      <input type="hidden" name="action" value="buy">
      <input type="hidden" name="item_code" id="bs-buy-code">
      <button type="submit" id="bs-buy-btn">Buy</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="shop-grid" id="bs-grid">
    <?php foreach ($items as $c):
      $isOwned = isset($owned[$c[0]]);
    ?>
    <div class="shop-card<?= $isOwned ? ' owned-card' : '' ?>"
         data-code="<?= e($c[0]) ?>"
         data-ic="<?= $c[2] ?>"
         data-name="<?= e($c[1]) ?>"
         data-type="<?= e(ucfirst($c[3]).' &mdash; '.$c[4]) ?>"
         data-desc="<?= e($c[9]) ?>"
         data-atk="<?= (int)$c[5] ?>"
         data-def="<?= (int)$c[6] ?>"
         data-spd="<?= (int)$c[7] ?>"
         data-price="<?= (int)$c[8] ?>"
         data-owned="<?= $isOwned ? '1' : '0' ?>">
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
  var detail=document.getElementById('bs-detail');
  var grid=document.getElementById('bs-grid');
  if(!grid||!detail) return;
  var sel=null;
  function stat(label, val){
    if(val===0) return '';
    var cls=val>0?'sv-pos':'sv-neg';
    return '<div class="sd-stat"><span class="sl">'+label+':</span> <span class="'+cls+'">'+(val>0?'+':'')+val+'</span></div>';
  }
  grid.addEventListener('click',function(e){
    var card=e.target.closest('.shop-card'); if(!card) return;
    if(sel) sel.classList.remove('selected');
    card.classList.add('selected'); sel=card;
    document.getElementById('bs-d-ic').innerHTML=card.dataset.ic;
    document.getElementById('bs-d-name').textContent=card.dataset.name;
    document.getElementById('bs-d-type').innerHTML=card.dataset.type;
    document.getElementById('bs-d-desc').textContent=card.dataset.desc;
    var atk=parseInt(card.dataset.atk), def=parseInt(card.dataset.def), spd=parseInt(card.dataset.spd);
    document.getElementById('bs-d-stats').innerHTML=stat('ATK',atk)+stat('DEF',def)+stat('SPD',spd);
    var price=parseInt(card.dataset.price);
    document.getElementById('bs-d-price').textContent=price.toLocaleString()+' cr';
    document.getElementById('bs-buy-code').value=card.dataset.code;
    var btn=document.getElementById('bs-buy-btn');
    if(card.dataset.owned==='1'){
      btn.innerHTML='&#10003; Owned'; btn.disabled=true; btn.style.opacity='0.5';
    } else {
      btn.textContent='Buy'; btn.disabled=false; btn.style.opacity='1';
    }
    detail.style.display='block';
    detail.scrollIntoView({behavior:'smooth',block:'nearest'});
  });
})();
</script>
