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
  ['mono_blade',      '&#9876;',  'Mono Blade',        'weapon', 12, 0, 400,    'Monomolecular edge — cuts through light armor plating.',  'common'],
  ['plasma_pistol',   '&#128299;','Plasma Pistol',     'weapon', 16, 0, 650,    'Compact plasma rounds. Reliable at close range.',         'common'],
  ['shock_baton',     '&#9889;',  'Shock Baton',       'weapon', 10, 2, 350,    'Stun-grade pulse discharge. Doubles as a riot tool.',      'common'],
  ['arc_rifle',       '&#128299;','Arc Rifle',         'weapon', 22, 0, 900,    'Charged particle bursts. Effective vs shielded targets.',  'uncommon'],
  ['neuro_injector',  '&#128296;','Neuro Injector',    'weapon', 8,  4, 500,    'Toxin-loaded spike. Disrupts neural pathways on impact.', 'uncommon'],
  ['void_blade',      '&#9876;',  'Void Blade',        'weapon', 28, 0, 1400,   'Phase-shifted edge — cuts through most barrier fields.',  'rare'],
  ['scatter_cannon',  '&#128299;','Scatter Cannon',    'weapon', 30, 0, 1800,   'Wide spread burst pattern. Devastating at close range.', 'rare'],
  ['synapse_spike',   '&#128296;','Synapse Spike',     'weapon', 18, 0, 1100,   'Jams cortical implants on critical hits.',                'uncommon'],
  ['corrosive_dart',  '&#128296;','Corrosive Dart',    'weapon', 14, 0, 700,    'Micro-needle loaded with industrial-grade acid.',         'common'],
  ['heavy_maul',      '&#9876;',  'Heavy Maul',        'weapon', 35, 0, 2200,   'Two-handed hydraulic hammer. Destroys cover.',            'rare'],
  ['data_lance',      '&#9876;',  'Data Lance',        'weapon', 20, 2, 1300,   'Carbon-ceramic spike loaded with intrusion code.',        'uncommon'],
  ['pulse_smg',       '&#128299;','Pulse SMG',         'weapon', 18, 0, 950,    'High-ROF electromagnetic bursts. Drains shield stacks.',  'uncommon'],
  ['ceramic_blade',   '&#9876;',  'Ceramic Blade',     'weapon', 9,  1, 300,    'Heat-treated ceramic edge. Quiet, light, cheap.',         'common'],
  ['gauss_rifle',     '&#128299;','Gauss Rifle',       'weapon', 40, 0, 3500,   'Electromagnetic rail accelerator. Long range, brutal.',   'elite'],
  ['hyper_katana',    '&#9876;',  'Hyper Katana',      'weapon', 45, 0, 4200,   'Vibro-edged titanium blade tuned to 12kHz resonance.',   'elite'],
  ['nano_swarm',      '&#128296;','Nano Swarm',        'weapon', 25, 0, 2000,   'Micro-bots dispersed on impact — dissolve soft tissue.',  'rare'],
  ['thermal_lance',   '&#9876;',  'Thermal Lance',     'weapon', 32, 0, 2600,   'Plasma-focused cutting beam at melee range.',             'rare'],
  ['ghost_pistol',    '&#128299;','Ghost Pistol',      'weapon', 20, 3, 1600,   'Subsonic rounds — no muzzle flash, no heat signature.',   'rare'],
  ['breacher_axe',    '&#9876;',  'Breacher Axe',      'weapon', 26, 0, 1700,   'Hydraulic spike-axe designed for armored door breach.',   'uncommon'],
  ['taser_web',       '&#9889;',  'Taser Web',         'weapon', 15, 0, 800,    'Filament net discharged at 50,000V. Immobilizes targets.','uncommon'],
  ['entropy_knife',   '&#9876;',  'Entropy Knife',     'weapon', 16, 1, 850,    'Quantum-edge blade — decays chemical bonds on contact.', 'uncommon'],
  ['sonic_driver',    '&#128299;','Sonic Driver',      'weapon', 22, 0, 1200,   'Focused directional sound weapon. Shatters internals.',   'rare'],
  ['smart_carbine',   '&#128299;','Smart Carbine',     'weapon', 38, 0, 3000,   'Target-lock AI integrated into the stock and barrel.',    'elite'],
  ['void_cannon',     '&#128299;','Void Cannon',       'weapon', 55, 0, 6000,   'Annihilation-class weapon. Banned in six city-states.',   'legendary'],
  ['hex_blade',       '&#9876;',  'Hex Blade',         'weapon', 50, 5, 7500,   'Cursed edge forged in an illegal foundry. Whispers.',     'legendary'],
];
$BS_ARMOR = [
  ['combat_vest',     '&#128737;','Combat Vest',       'armor', 0, 12, 400,    'Standard-issue polymer composite. Stops ballistics.',     'common'],
  ['riot_shell',      '&#128737;','Riot Shell',        'armor', 0, 18, 650,    'Full-body riot plate. Heavy but reliable.',                'common'],
  ['exo_frame',       '&#128737;','Exo Frame',         'armor', 0, 28, 1200,   'Powered exoskeletal shell. Absorbs kinetic impact.',      'uncommon'],
  ['camo_wraps',      '&#128737;','Camo Wraps',        'armor', 0, 8,  280,    'Adaptive-pattern light wrap. Harder to hit.',             'common'],
  ['neural_weave',    '&#128737;','Neural Weave',      'armor', 2, 14, 700,    'Conductive mesh that anticipates incoming strikes.',      'uncommon'],
  ['ablative_coat',   '&#128737;','Ablative Coat',     'armor', 0, 22, 950,    'Sacrificial layers burn away on impact. Single-use effect.','uncommon'],
  ['shadow_suit',     '&#128737;','Shadow Suit',       'armor', 0, 16, 800,    'Stealth-optimized flexweave. Blocks thermal imaging.',    'uncommon'],
  ['pulse_shield',    '&#128737;','Pulse Shield',      'armor', 0, 35, 2400,   'Electrostatic barrier shell. Regenerates between hits.',  'rare'],
  ['nano_skin',       '&#128737;','Nano Skin',         'armor', 3, 20, 1800,   'Living mesh that redistributes impact force.',            'rare'],
  ['juggernaut_plate','&#128737;','Juggernaut Plate',  'armor', 0, 45, 4000,   'Military-grade ceramic-titanium plate. Near-impenetrable.','elite'],
  ['reflex_suit',     '&#128737;','Reflex Suit',       'armor', 4, 18, 1500,   'Myomer-lined suit that augments reaction speed.',         'rare'],
  ['subdermal_mesh',  '&#128737;','Subdermal Mesh',    'armor', 0, 25, 1600,   'Embedded under-skin armor weave. Invisible to scanners.', 'rare'],
  ['ghost_cloak',     '&#128737;','Ghost Cloak',       'armor', 0, 30, 2200,   'Phase-shifting coat — partial optical camouflage.',       'rare'],
  ['reactor_jacket',  '&#128737;','Reactor Jacket',    'armor', 0, 38, 3000,   'Fusion-cell powered shield emitters in the shoulder pads.','elite'],
  ['circuit_shroud',  '&#128737;','Circuit Shroud',    'armor', 5, 24, 2000,   'EMP-hardened longcoat with embedded counter-intrusion.', 'rare'],
  ['void_carapace',   '&#128737;','Void Carapace',     'armor', 0, 60, 7000,   'Salvaged from a crashed warship. Absorbs almost anything.','legendary'],
  ['chrome_shell',    '&#128737;','Chrome Shell',      'armor', 0, 42, 3600,   'Mirror-polished deflection plating. Bounces energy beams.','elite'],
  ['biomech_skin',    '&#128737;','Biomech Skin',      'armor', 6, 28, 2800,   'Genetically-engineered symbiotic armor organism.',        'elite'],
  ['quantum_vest',    '&#128737;','Quantum Vest',      'armor', 0, 50, 5500,   'Quantum-locked protection field. Probability-based defense.','legendary'],
  ['kinetic_weave',   '&#128737;','Kinetic Weave',     'armor', 0, 20, 1100,   'Dissipates kinetic energy across the entire suit surface.','uncommon'],
  ['ferro_coat',      '&#128737;','Ferro Coat',        'armor', 0, 15, 750,    'Ferromagnetic fiber coat. Deflects shrapnel and debris.',  'common'],
  ['carbon_bodysuit', '&#128737;','Carbon Bodysuit',   'armor', 2, 22, 1300,   'Full-body carbon-nanotube weave. Light and protective.',   'uncommon'],
  ['aegis_frame',     '&#128737;','Aegis Frame',       'armor', 0, 55, 6500,   'Corporate elite bodyguard issue. Rarely leaves the vault.','legendary'],
  ['surge_plate',     '&#128737;','Surge Plate',       'armor', 0, 32, 2500,   'Capacitor-fed active armor — discharges on impact.',      'rare'],
  ['phantom_wrap',    '&#128737;','Phantom Wrap',      'armor', 0, 26, 1900,   'Chameleon fabric that shifts pattern to match surroundings.','rare'],
];

/* ----- Consumables from General Store ----- */
$CONSUMABLES = [
  ['stim_pack',       '&#128138;','Stim Pack',         '+25 Integrity. Quick combat patch.',           200,  'consumable'],
  ['medkit',          '&#128138;','Medkit',            '+75 Integrity. Full trauma kit.',              650,  'consumable'],
  ['neural_boost',    '&#9889;',  'Neural Boost',      '+30 Signal. Temporary cortex overclocking.',   300,  'consumable'],
  ['cycle_chip',      '&#128296;','Cycle Chip',        '+20 Cycles. Cooldown recovery module.',        280,  'consumable'],
  ['regen_serum',     '&#128138;','Regen Serum',       '+50 Integrity. Bio-regenerative compound.',    450,  'consumable'],
  ['overclock_tab',   '&#9889;',  'Overclock Tab',     '+50 Signal. Neural stimulant tablet.',         500,  'consumable'],
  ['signal_jammer',   '&#128296;','Signal Jammer',     'Collectible. Disrupts nearby comms.',          800,  'collectible'],
  ['data_chip',       '&#128190;','Data Chip',         'Collectible. Encrypted data fragment.',        600,  'collectible'],
  ['grid_key',        '&#128273;','Grid Key',          'Collectible. Unlock unknown grid nodes.',     1200,  'collectible'],
  ['holo_badge',      '&#127894;','Holo Badge',        'Collectible. Counterfeit identity chip.',     1500,  'collectible'],
  ['neural_shard',    '&#128296;','Neural Shard',      'Collectible. Cortex implant fragment.',       2200,  'collectible'],
  ['ghost_token',     '&#128121;','Ghost Token',       'Collectible. Proof of a completed shadow run.',3500,  'collectible'],
];

/* ----- Skills from Datacore ----- */
$SKILLS = [
  ['combat',     '&#9876;', 'Combat',     'Increases attack damage in PvP and PvE encounters.',    'var(--neon2)'],
  ['hacking',    '&#128296;','Hacking',   'Grants access to locked grid nodes and better loot.',   'var(--accent)'],
  ['stealth',    '&#128121;','Stealth',   'Reduces chance of being detected in field operations.',  '#4d6be8'],
  ['endurance',  '&#128737;','Endurance', 'Raises maximum Integrity and reduces incoming damage.',  '#3bcf63'],
  ['tech',       '&#9881;',  'Tech',      'Improves crafting output and fabrication efficiency.',   '#e8a33d'],
  ['trading',    '&#128722;','Trading',   'Reduces Bazaar listing fees and improves sale prices.',  '#ffe14d'],
  ['medic',      '&#128138;','Medic',     'Increases healing from all consumables and regen.',     '#5fe0e0'],
  ['networking', '&#128225;','Networking','Reduces Signal ability cooldowns and improves range.',   '#9bff3d'],
];

/* ----- Rarity colors ----- */
function lib_rarity_color($r) {
  $m=['common'=>'#8a8fa8','uncommon'=>'#3bcf63','rare'=>'#4d6be8','elite'=>'#ff2d95','legendary'=>'#e8a33d'];
  return $m[$r] ?? '#8a8fa8';
}
function lib_rarity_class($r) {
  return 'lib-rarity-'.($r ?: 'common');
}
?>

<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <h2 style="margin:0">&#128218; The Library</h2>
      <p class="muted" style="margin:4px 0 0">All items, weapons, armor, and skills available in The Sprawl.</p>
    </div>
  </div>
  <div class="tabs" style="margin:14px -14px -14px;border-top:1px solid var(--line)">
    <a class="tab <?= $tab==='weapons'?'is-active':'' ?>" href="index.php?p=library&tab=weapons">&#9876; Weapons</a>
    <a class="tab <?= $tab==='armor'?'is-active':'' ?>"   href="index.php?p=library&tab=armor">&#128737; Armor</a>
    <a class="tab <?= $tab==='consumables'?'is-active':'' ?>" href="index.php?p=library&tab=consumables">&#128138; Consumables</a>
    <a class="tab <?= $tab==='materials'?'is-active':'' ?>" href="index.php?p=library&tab=materials">&#128230; Materials</a>
    <a class="tab <?= $tab==='skills'?'is-active':'' ?>"  href="index.php?p=library&tab=skills">&#127891; Skills</a>
  </div>
</div>

<?php if ($tab === 'weapons'): ?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:6px">
    <h3 style="margin:0">Weapons <span class="muted" style="font-weight:400;font-size:13px"><?= count($BS_WEAPONS) ?> items</span></h3>
    <div style="font-size:11px;color:var(--muted)">
      <?php foreach (['common','uncommon','rare','elite','legendary'] as $r): ?>
        <span style="color:<?= lib_rarity_color($r) ?>;margin-right:10px">&#9632; <?= ucfirst($r) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="lib-grid">
    <?php foreach ($BS_WEAPONS as $w): [$code,$icon,$name,$type,$atk,$def,$price,$desc,$rarity] = $w; ?>
    <div class="lib-card <?= lib_rarity_class($rarity) ?>">
      <div class="lib-card-head">
        <div class="lib-icon"><?= $icon ?></div>
        <div>
          <div class="lib-name"><?= e($name) ?></div>
          <div class="lib-cat" style="color:<?= lib_rarity_color($rarity) ?>"><?= ucfirst($rarity) ?> &mdash; Weapon</div>
        </div>
      </div>
      <div class="lib-desc"><?= e($desc) ?></div>
      <div class="lib-stats">
        <?php if ($atk): ?><span class="lib-stat">&#9876; +<?= $atk ?> ATK</span><?php endif; ?>
        <?php if ($def): ?><span class="lib-stat def">&#128737; +<?= $def ?> DEF</span><?php endif; ?>
        <span class="lib-stat price">&#9733; <?= number_format($price) ?> cr</span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'armor'): ?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:6px">
    <h3 style="margin:0">Armor <span class="muted" style="font-weight:400;font-size:13px"><?= count($BS_ARMOR) ?> items</span></h3>
    <div style="font-size:11px;color:var(--muted)">
      <?php foreach (['common','uncommon','rare','elite','legendary'] as $r): ?>
        <span style="color:<?= lib_rarity_color($r) ?>;margin-right:10px">&#9632; <?= ucfirst($r) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="lib-grid">
    <?php foreach ($BS_ARMOR as $a): [$code,$icon,$name,$type,$atk,$def,$price,$desc,$rarity] = $a; ?>
    <div class="lib-card <?= lib_rarity_class($rarity) ?>">
      <div class="lib-card-head">
        <div class="lib-icon"><?= $icon ?></div>
        <div>
          <div class="lib-name"><?= e($name) ?></div>
          <div class="lib-cat" style="color:<?= lib_rarity_color($rarity) ?>"><?= ucfirst($rarity) ?> &mdash; Armor</div>
        </div>
      </div>
      <div class="lib-desc"><?= e($desc) ?></div>
      <div class="lib-stats">
        <?php if ($atk): ?><span class="lib-stat">&#9876; +<?= $atk ?> ATK</span><?php endif; ?>
        <?php if ($def): ?><span class="lib-stat def">&#128737; +<?= $def ?> DEF</span><?php endif; ?>
        <span class="lib-stat price">&#9733; <?= number_format($price) ?> cr</span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'consumables'): ?>
<div class="panel">
  <h3 style="margin-bottom:12px">Consumables &amp; Collectibles <span class="muted" style="font-weight:400;font-size:13px"><?= count($CONSUMABLES) ?> items</span></h3>
  <div class="lib-grid">
    <?php foreach ($CONSUMABLES as $c): [$code,$icon,$name,$desc,$price,$type] = $c; ?>
    <div class="lib-card" style="border-left:3px solid <?= $type==='consumable' ? 'var(--accent)' : 'var(--neon2)' ?>">
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
</div>

<?php elseif ($tab === 'materials'): ?>
<div class="panel">
  <h3 style="margin-bottom:12px">Crafting Materials</h3>
  <?php
  $mats = array_filter($dbItems, fn($i) => !in_array($i['slot'],['weapon','armor'],true) && !in_array($i['category'],['weapon','armor'],true));
  if ($mats):
  ?>
  <div class="lib-grid">
    <?php foreach ($mats as $m):
      $icons = ['raw'=>'&#128296;','component'=>'&#128295;','chem'=>'&#128137;','data'=>'&#128190;','gear'=>'&#9881;','misc'=>'&#128230;'];
      $icon = $icons[$m['category']] ?? '&#128230;';
    ?>
    <div class="lib-card lib-rarity-common">
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
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:32px 0">No materials catalogued yet.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'skills'): ?>
<div class="panel">
  <h3 style="margin-bottom:4px">Skillsoft Modules</h3>
  <p class="muted" style="margin-bottom:16px">Train these skills at the <a href="index.php?p=datacore&act=lab">Skillsoft Lab</a>.</p>
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
