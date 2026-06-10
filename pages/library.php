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

/* ----- Blacksmith catalog ----- */
$BS_WEAPONS = [
  ['mono_blade',     '&#9876;',  'Mono Blade',        'weapon', 12, 0, 400,    'Monomolecular edge — cuts through light armor plating.',  'common'],
  ['plasma_pistol',  '&#128299;','Plasma Pistol',     'weapon', 16, 0, 650,    'Compact plasma rounds. Reliable at close range.',         'common'],
  ['shock_baton',    '&#9889;',  'Shock Baton',       'weapon', 10, 2, 350,    'Stun-grade pulse discharge. Doubles as a riot tool.',      'common'],
  ['arc_rifle',      '&#128299;','Arc Rifle',         'weapon', 22, 0, 900,    'Charged particle bursts. Effective vs shielded targets.',  'uncommon'],
  ['neuro_injector', '&#128296;','Neuro Injector',    'weapon', 8,  4, 500,    'Toxin-loaded spike. Disrupts neural pathways on impact.', 'uncommon'],
  ['void_blade',     '&#9876;',  'Void Blade',        'weapon', 28, 0, 1400,   'Phase-shifted edge — cuts through most barrier fields.',  'rare'],
  ['scatter_cannon', '&#128299;','Scatter Cannon',    'weapon', 30, 0, 1800,   'Wide spread burst pattern. Devastating at close range.',  'rare'],
  ['synapse_spike',  '&#128296;','Synapse Spike',     'weapon', 18, 0, 1100,   'Jams cortical implants on critical hits.',                'uncommon'],
  ['corrosive_dart', '&#128296;','Corrosive Dart',    'weapon', 14, 0, 700,    'Micro-needle loaded with industrial-grade acid.',         'common'],
  ['heavy_maul',     '&#9876;',  'Heavy Maul',        'weapon', 35, 0, 2200,   'Two-handed hydraulic hammer. Destroys cover.',            'rare'],
  ['data_lance',     '&#9876;',  'Data Lance',        'weapon', 20, 2, 1300,   'Carbon-ceramic spike loaded with intrusion code.',        'uncommon'],
  ['pulse_smg',      '&#128299;','Pulse SMG',         'weapon', 18, 0, 950,    'High-ROF electromagnetic bursts. Drains shield stacks.',  'uncommon'],
  ['ceramic_blade',  '&#9876;',  'Ceramic Blade',     'weapon', 9,  1, 300,    'Heat-treated ceramic edge. Quiet, light, cheap.',         'common'],
  ['gauss_rifle',    '&#128299;','Gauss Rifle',       'weapon', 40, 0, 3500,   'Electromagnetic rail accelerator. Long range, brutal.',   'elite'],
  ['hyper_katana',   '&#9876;',  'Hyper Katana',      'weapon', 45, 0, 4200,   'Vibro-edged titanium blade tuned to 12kHz resonance.',   'elite'],
  ['nano_swarm',     '&#128296;','Nano Swarm',        'weapon', 25, 0, 2000,   'Micro-bots dispersed on impact — dissolve soft tissue.',  'rare'],
  ['thermal_lance',  '&#9876;',  'Thermal Lance',     'weapon', 32, 0, 2600,   'Plasma-focused cutting beam at melee range.',             'rare'],
  ['ghost_pistol',   '&#128299;','Ghost Pistol',      'weapon', 20, 3, 1600,   'Subsonic rounds — no muzzle flash, no heat signature.',   'rare'],
  ['breacher_axe',   '&#9876;',  'Breacher Axe',      'weapon', 26, 0, 1700,   'Hydraulic spike-axe designed for armored door breach.',   'uncommon'],
  ['taser_web',      '&#9889;',  'Taser Web',         'weapon', 15, 0, 800,    'Filament net discharged at 50,000V. Immobilizes targets.','uncommon'],
  ['entropy_knife',  '&#9876;',  'Entropy Knife',     'weapon', 16, 1, 850,    'Quantum-edge blade — decays chemical bonds on contact.',  'uncommon'],
  ['sonic_driver',   '&#128299;','Sonic Driver',      'weapon', 22, 0, 1200,   'Focused directional sound weapon. Shatters internals.',   'rare'],
  ['smart_carbine',  '&#128299;','Smart Carbine',     'weapon', 38, 0, 3000,   'Target-lock AI integrated into the stock and barrel.',    'elite'],
  ['void_cannon',    '&#128299;','Void Cannon',       'weapon', 55, 0, 6000,   'Annihilation-class weapon. Banned in six city-states.',   'legendary'],
  ['hex_blade',      '&#9876;',  'Hex Blade',         'weapon', 50, 5, 7500,   'Cursed edge forged in an illegal foundry. Whispers.',     'legendary'],
];
$BS_ARMOR = [
  ['combat_vest',    '&#128737;','Combat Vest',       'armor', 0, 12, 400,    'Standard-issue polymer composite. Stops ballistics.',      'common'],
  ['riot_shell',     '&#128737;','Riot Shell',        'armor', 0, 18, 650,    'Full-body riot plate. Heavy but reliable.',                 'common'],
  ['exo_frame',      '&#128737;','Exo Frame',         'armor', 0, 28, 1200,   'Powered exoskeletal shell. Absorbs kinetic impact.',       'uncommon'],
  ['camo_wraps',     '&#128737;','Camo Wraps',        'armor', 0, 8,  280,    'Adaptive-pattern light wrap. Harder to hit.',              'common'],
  ['neural_weave',   '&#128737;','Neural Weave',      'armor', 2, 14, 700,    'Conductive mesh that anticipates incoming strikes.',       'uncommon'],
  ['ablative_coat',  '&#128737;','Ablative Coat',     'armor', 0, 22, 950,    'Sacrificial layers burn away on impact. Single-use effect.','uncommon'],
  ['shadow_suit',    '&#128737;','Shadow Suit',       'armor', 0, 16, 800,    'Stealth-optimized flexweave. Blocks thermal imaging.',     'uncommon'],
  ['pulse_shield',   '&#128737;','Pulse Shield',      'armor', 0, 35, 2400,   'Electrostatic barrier shell. Regenerates between hits.',   'rare'],
  ['nano_skin',      '&#128737;','Nano Skin',         'armor', 3, 20, 1800,   'Living mesh that redistributes impact force.',             'rare'],
  ['juggernaut_plate','&#128737;','Juggernaut Plate', 'armor', 0, 45, 4000,   'Military-grade ceramic-titanium plate. Near-impenetrable.','elite'],
  ['reflex_suit',    '&#128737;','Reflex Suit',       'armor', 4, 18, 1500,   'Myomer-lined suit that augments reaction speed.',          'rare'],
  ['subdermal_mesh', '&#128737;','Subdermal Mesh',    'armor', 0, 25, 1600,   'Embedded under-skin armor weave. Invisible to scanners.',  'rare'],
  ['ghost_cloak',    '&#128737;','Ghost Cloak',       'armor', 0, 30, 2200,   'Phase-shifting coat — partial optical camouflage.',        'rare'],
  ['reactor_jacket', '&#128737;','Reactor Jacket',    'armor', 0, 38, 3000,   'Fusion-cell powered shield emitters in the shoulder pads.','elite'],
  ['circuit_shroud', '&#128737;','Circuit Shroud',    'armor', 5, 24, 2000,   'EMP-hardened longcoat with embedded counter-intrusion.',   'rare'],
  ['void_carapace',  '&#128737;','Void Carapace',     'armor', 0, 60, 7000,   'Salvaged from a crashed warship. Absorbs almost anything.','legendary'],
  ['chrome_shell',   '&#128737;','Chrome Shell',      'armor', 0, 42, 3600,   'Mirror-polished deflection plating. Bounces energy beams.','elite'],
  ['biomech_skin',   '&#128737;','Biomech Skin',      'armor', 6, 28, 2800,   'Genetically-engineered symbiotic armor organism.',         'elite'],
  ['quantum_vest',   '&#128737;','Quantum Vest',      'armor', 0, 50, 5500,   'Quantum-locked protection field. Probability-based defense.','legendary'],
  ['kinetic_weave',  '&#128737;','Kinetic Weave',     'armor', 0, 20, 1100,   'Dissipates kinetic energy across the entire suit surface.','uncommon'],
  ['ferro_coat',     '&#128737;','Ferro Coat',        'armor', 0, 15, 750,    'Ferromagnetic fiber coat. Deflects shrapnel and debris.',  'common'],
  ['carbon_bodysuit','&#128737;','Carbon Bodysuit',   'armor', 2, 22, 1300,   'Full-body carbon-nanotube weave. Light and protective.',   'uncommon'],
  ['aegis_frame',    '&#128737;','Aegis Frame',       'armor', 0, 55, 6500,   'Corporate elite bodyguard issue. Rarely leaves the vault.','legendary'],
  ['surge_plate',    '&#128737;','Surge Plate',       'armor', 0, 32, 2500,   'Capacitor-fed active armor — discharges on impact.',      'rare'],
  ['phantom_wrap',   '&#128737;','Phantom Wrap',      'armor', 0, 26, 1900,   'Chameleon fabric that shifts pattern to match surroundings.','rare'],
];

/* ----- Consumables ----- */
$CONSUMABLES = [
  ['stim_pack',    '&#128138;','Stim Pack',     '+25 Health. Quick combat patch.',            200,  'consumable'],
  ['medkit',       '&#128138;','Medkit',        '+75 Health. Full trauma kit.',               650,  'consumable'],
  ['neural_boost', '&#9889;',  'Neural Boost',  '+30 Signal. Temporary cortex overclocking.',    300,  'consumable'],
  ['cycle_chip',   '&#128296;','Cycle Chip',    '+20 Drive. Cooldown recovery module.',         280,  'consumable'],
  ['regen_serum',  '&#128138;','Regen Serum',   '+50 Health. Bio-regenerative compound.',     450,  'consumable'],
  ['overclock_tab','&#9889;',  'Overclock Tab', '+50 Signal. Neural stimulant tablet.',          500,  'consumable'],
  ['signal_jammer','&#128296;','Signal Jammer', 'Collectible. Disrupts nearby comms.',           800,  'collectible'],
  ['data_chip',    '&#128190;','Data Chip',     'Collectible. Encrypted data fragment.',         600,  'collectible'],
  ['grid_key',     '&#128273;','Grid Key',      'Collectible. Unlock unknown grid nodes.',      1200,  'collectible'],
  ['holo_badge',   '&#127894;','Holo Badge',    'Collectible. Counterfeit identity chip.',      1500,  'collectible'],
  ['neural_shard', '&#128296;','Neural Shard',  'Collectible. Cortex implant fragment.',        2200,  'collectible'],
  ['ghost_token',  '&#128121;','Ghost Token',   'Collectible. Proof of a completed shadow run.',3500,  'collectible'],
];

/* ----- Skills ----- */
$SKILLS = [
  ['combat',     '&#9876;',  'Combat',     'Increases attack damage in PvP and PvE encounters.',   'var(--neon2)'],
  ['hacking',    '&#128296;','Hacking',    'Grants access to locked grid nodes and better loot.',  'var(--accent)'],
  ['stealth',    '&#128121;','Stealth',    'Reduces chance of being detected in field operations.', '#4d6be8'],
  ['endurance',  '&#128737;','Endurance',  'Raises maximum Health and reduces incoming damage.', '#3bcf63'],
  ['tech',       '&#9881;',  'Tech',       'Improves crafting output and fabrication efficiency.',  '#e8a33d'],
  ['trading',    '&#128722;','Trading',    'Reduces Bazaar listing fees and improves sale prices.', '#ffe14d'],
  ['medic',      '&#128138;','Medic',      'Increases healing from all consumables and regen.',    '#5fe0e0'],
  ['networking', '&#128225;','Networking', 'Reduces Signal ability cooldowns and improves range.',  '#9bff3d'],
];

/* ----- Rarity helpers ----- */
function lib_rarity_color($r) {
  $m=['common'=>'#8a8fa8','uncommon'=>'#3bcf63','rare'=>'#4d6be8','elite'=>'#ff2d95','legendary'=>'#e8a33d'];
  return $m[$r] ?? '#8a8fa8';
}
function lib_rarity_class($r) { return 'lib-rarity-'.($r ?: 'common'); }
?>

<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="margin:0">&#128218; The Library</h2>
      <p class="muted" style="margin:4px 0 0">All items, weapons, armor, and skills available in The Sprawl.</p>
    </div>
  </div>
  <div class="tabs" style="margin:14px -14px -14px;border-top:1px solid var(--line)">
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
          <span style="color:var(--muted)">Obtain at: </span>
          <a href="index.php?p=weaponcraft&tab=<?= $tab === 'weapons' ? 'weapons' : 'armor' ?>" style="color:var(--accent)">&#9881; Weaponcraft Lab</a>
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
