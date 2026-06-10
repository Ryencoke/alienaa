<?php /* pages/weaponcraft.php — Fabrication Lab: craft weapons and armor from ore */

$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Schema
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_ore (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL,
    ore_type VARCHAR(32) NOT NULL, quantity INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_po (player_id, ore_type), KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_gear (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    player_id  INT NOT NULL,
    recipe_id  VARCHAR(32) NOT NULL,
    name       VARCHAR(64) NOT NULL,
    gear_type  ENUM(\'weapon\',\'armor\') NOT NULL,
    atk_bonus  INT NOT NULL DEFAULT 0,
    def_bonus  INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// Recipes: [id, name, type, atk_bonus, def_bonus, icon, color, desc, cost: ['ore'=>qty,...]]
$RECIPES = [
  // ── Weapons ──
  ['scrap_shiv',      'Scrap Shiv',           'weapon',  4,  0, '&#128299;', '#8a8fa8', 'Jagged junk metal sharpened on concrete. Crude but cuts.',           ['scrap'=>6]],
  ['scrap_cleaver',   'Scrap Cleaver',        'weapon',  6,  1, '&#9876;',   '#8a8fa8', 'Serrated scrap edge bolted to a broken pipe. Messy.',                ['scrap'=>8, 'copper'=>1]],
  ['junk_flail',      'Junk Flail',           'weapon',  7,  0, '&#9876;',   '#8a8fa8', 'Chain-linked metal scraps. Unpredictable swing arc.',                ['scrap'=>10]],
  ['copper_blade',    'Copper Blade',         'weapon', 12,  0, '&#9876;',   '#e8a33d', 'Copper-alloyed fighting blade. Reliable in a close fight.',          ['copper'=>8, 'scrap'=>4]],
  ['copper_knuckles', 'Copper Knuckles',      'weapon', 14,  0, '&#128299;', '#e8a33d', 'Cast copper knuckle dusters — quiet, personal, effective.',          ['copper'=>6, 'scrap'=>4]],
  ['copper_chain',    'Copper Chain',         'weapon', 16,  1, '&#9876;',   '#e8a33d', 'Heavy copper chain used for leverage and lethality.',                ['copper'=>9, 'scrap'=>5]],
  ['copper_halberd',  'Copper Halberd',       'weapon', 18,  0, '&#9874;',   '#e8a33d', 'Long-hafted copper pole-blade. Reach advantage in corridor fights.', ['copper'=>11, 'scrap'=>6]],
  ['iron_sword',      'Iron Machete',         'weapon', 22,  0, '&#9874;',   '#b0b8cc', 'Heavy-gauge iron blade. Cleaves through standard armor.',            ['iron'=>10, 'copper'=>5]],
  ['iron_staff',      'Iron Staff',           'weapon', 24,  1, '&#9876;',   '#b0b8cc', 'Solid iron fighting staff. Blocks as well as strikes.',              ['iron'=>8, 'copper'=>6]],
  ['iron_gauntlet',   'Iron Gauntlet',        'weapon', 26,  2, '&#128299;', '#b0b8cc', 'Reinforced iron punch-driver. Bone-shattering on impact.',           ['iron'=>10, 'copper'=>4]],
  ['iron_mauler',     'Iron Mauler',          'weapon', 30,  0, '&#9876;',   '#b0b8cc', 'Two-headed iron maul. Slow but devastating.',                        ['iron'=>13, 'copper'=>5]],
  ['titan_blade',     'Titan Blade',          'weapon', 36,  0, '&#128481;', '#19f0c7', 'Aerospace-grade titanium edge. Surgical strike capability.',         ['titanium'=>8, 'iron'=>4]],
  ['titan_spear',     'Titan Spear',          'weapon', 40,  0, '&#9874;',   '#19f0c7', 'Titanium-tipped combat spear. Penetrates most light armor.',         ['titanium'=>9, 'copper'=>3]],
  ['titan_shotgun',   'Titan Shotgun',        'weapon', 42,  0, '&#128299;', '#19f0c7', 'Titanium-frame breach-loader. Wide spread pattern.',                 ['titanium'=>10, 'iron'=>5]],
  ['titan_rapier',    'Titan Rapier',         'weapon', 45,  0, '&#9876;',   '#19f0c7', 'Flexible titanium thrusting blade. Precise and fast.',               ['titanium'=>11, 'iron'=>3]],
  ['nano_edge',       'Nano-Edge Katana',     'weapon', 55,  0, '&#128302;', '#ff2d95', 'Mono-molecular nano-carbon edge. Cuts through most plating.',        ['nanocarbon'=>6, 'titanium'=>5]],
  ['nano_sword',      'Nano Sword',           'weapon', 60,  0, '&#9876;',   '#ff2d95', 'Nano-forged carbon blade. Lighter than titanium, sharper than glass.',['nanocarbon'=>7, 'titanium'=>4]],
  ['nano_crossbow',   'Carbon Crossbow',      'weapon', 62,  0, '&#128299;', '#ff2d95', 'Nano-carbon limbs, near-silent discharge. Armor-piercing bolts.',     ['nanocarbon'=>7, 'iron'=>4]],
  ['nano_flail',      'Nano Flail',           'weapon', 65,  0, '&#9876;',   '#ff2d95', 'Nano-filament chain weapon. Wraps and cuts simultaneously.',          ['nanocarbon'=>8, 'iron'=>3]],
  ['nano_rifle',      'Nano Rifle',           'weapon', 68,  0, '&#128299;', '#ff2d95', 'Nano-carbon barrel with near-zero flex. Exceptional accuracy.',       ['nanocarbon'=>7, 'quantum'=>1]],
  ['quantum_saber',   'Quantum Saber',        'weapon', 78,  0, '&#128142;', '#a66de8', 'Quantum-lattice blade that disrupts defensive fields on contact.',   ['quantum'=>4, 'nanocarbon'=>3]],
  ['quantum_spear',   'Quantum Spear',        'weapon', 82,  0, '&#9874;',   '#a66de8', 'Quantum-resonance tip. Bypasses first layer of energy shielding.',   ['quantum'=>5, 'titanium'=>3]],
  ['quantum_rifle',   'Quantum Rifle',        'weapon', 85,  0, '&#128299;', '#a66de8', 'Accelerates projectiles through a quantum field lens.',               ['quantum'=>5, 'nanocarbon'=>2]],
  ['quantum_blade',   'Quantum Blade',        'weapon', 88,  0, '&#128481;', '#a66de8', 'Phase-locking blade — resonates at target matter frequency.',         ['quantum'=>6, 'nanocarbon'=>2]],
  ['quantum_canon',   'Quantum Canon',        'weapon', 92,  0, '&#128299;', '#a66de8', 'Fires a compressed quantum bolt. Obliterates soft targets.',          ['quantum'=>7, 'void'=>1]],
  ['void_reaper',     'Void Reaper',          'weapon',110,  0, '&#11088;',  '#e8d44d', 'Forged from void metal. Legends say it cuts through reality itself.', ['void'=>3, 'quantum'=>2]],
  ['void_lance',      'Void Lance',           'weapon',118,  0, '&#9874;',   '#e8d44d', 'Void-infused spear tip. Tears open spatial pockets on impact.',       ['void'=>4, 'nanocarbon'=>2]],
  ['void_hammer',     'Void Hammer',          'weapon',125,  0, '&#9876;',   '#e8d44d', 'Void-metal hammerhead. Warps local spacetime on each blow.',          ['void'=>4, 'quantum'=>2]],
  ['void_shard',      'Void Shard',           'weapon',130,  0, '&#11088;',  '#e8d44d', 'A crystallised fragment of void metal filed to a lethal point.',      ['void'=>5, 'quantum'=>1]],
  ['void_fist',       'Void Fist',            'weapon',135,  0, '&#128299;', '#e8d44d', 'Void-metal gauntlet. Punch through hardlight shields bare-handed.',   ['void'=>6, 'quantum'=>2]],
  ['void_cannon',     'Void Cannon',          'weapon',140,  0, '&#128299;', '#e8d44d', 'Annihilation-class weapon. Banned in six city-states.',               ['void'=>7, 'quantum'=>3]],

  // ── Armor ──
  ['scrap_vest',      'Scrap Vest',           'armor',  0,  6, '&#128737;', '#8a8fa8', 'Bolted-together salvage. Won\'t stop much, but it\'s something.',     ['scrap'=>8]],
  ['scrap_buckler',   'Scrap Buckler',        'armor',  0,  8, '&#128737;', '#8a8fa8', 'Sheet metal bolted into a rough breastplate. Dents instead of bleeds.',['scrap'=>10]],
  ['junk_plating',    'Junk Plating',         'armor',  0,  9, '&#128737;', '#8a8fa8', 'Layered junk scraps riveted over a cloth base. Better than nothing.',  ['scrap'=>12]],
  ['copper_mesh',     'Copper Mesh Vest',     'armor',  0, 15, '&#129683;', '#e8a33d', 'Woven copper mesh. Decent protection for early-grid runners.',         ['copper'=>10, 'scrap'=>5]],
  ['copper_jacket',   'Copper Jacket',        'armor',  0, 18, '&#128737;', '#e8a33d', 'Copper-thread reinforced jacket. Flexible and surprisingly tough.',    ['copper'=>11, 'scrap'=>6]],
  ['copper_shell',    'Copper Shell',         'armor',  0, 20, '&#128737;', '#e8a33d', 'Pressed copper sheets over a rigid frame. Holds its shape under fire.',['copper'=>12, 'scrap'=>5]],
  ['copper_guard',    'Copper Guard',         'armor',  0, 22, '&#128737;', '#e8a33d', 'Full copper plating system. Heavy but solid for the first tier.',      ['copper'=>14, 'scrap'=>4]],
  ['iron_plate',      'Iron Plate Carrier',   'armor',  0, 28, '&#129667;', '#b0b8cc', 'Full-coverage iron plating. Heavy but highly protective.',             ['iron'=>12, 'copper'=>6]],
  ['iron_coat',       'Iron Coat',            'armor',  0, 30, '&#128737;', '#b0b8cc', 'Heavy iron-reinforced longcoat. Slows movement but absorbs impacts.',  ['iron'=>12, 'copper'=>5]],
  ['iron_carapace',   'Iron Carapace',        'armor',  0, 32, '&#128737;', '#b0b8cc', 'Full-body iron shell assembly. The grid called it overkill. It lied.', ['iron'=>14, 'copper'=>5]],
  ['iron_bulwark',    'Iron Bulwark',         'armor',  0, 35, '&#128737;', '#b0b8cc', 'Reinforced iron layering with extra shoulder guards.',                 ['iron'=>14, 'copper'=>7]],
  ['titan_armor',     'Titan Exo-Shell',      'armor',  0, 45, '&#127760;', '#19f0c7', 'Lightweight titanium exoskeleton. Moves like mesh, stops like rock.',  ['titanium'=>10, 'iron'=>5]],
  ['titan_vest',      'Titan Vest',           'armor',  0, 48, '&#128737;', '#19f0c7', 'Titanium plate carrier. Light enough to sprint in, dense enough to tank.',['titanium'=>11, 'iron'=>5]],
  ['titan_shield',    'Titan Shield',         'armor',  0, 50, '&#128737;', '#19f0c7', 'Full-body titanium deflection shell. Elite early protection.',          ['titanium'=>12, 'iron'=>4]],
  ['titan_mantle',    'Titan Mantle',         'armor',  0, 52, '&#128737;', '#19f0c7', 'Layered titanium-fibre cloak-armour hybrid. Rare construction.',        ['titanium'=>13, 'iron'=>4]],
  ['titan_husk',      'Titan Husk',           'armor',  0, 55, '&#128737;', '#19f0c7', 'Full titanium carapace with nano-fibre inner lining.',                  ['titanium'=>14, 'nanocarbon'=>1]],
  ['nano_weave',      'Nano-Weave Suit',      'armor',  0, 68, '&#127951;', '#ff2d95', 'Nano-carbon composite weave. Near-impenetrable to standard rounds.',    ['nanocarbon'=>8, 'titanium'=>4]],
  ['nano_shell',      'Nano Shell',           'armor',  0, 70, '&#128737;', '#ff2d95', 'Nano-carbon outer shell over reactive foam interior.',                  ['nanocarbon'=>9, 'titanium'=>4]],
  ['nano_lattice',    'Nano Lattice',         'armor',  0, 72, '&#128737;', '#ff2d95', 'Lattice-woven nano-fibre. Distributes impact across the whole suit.',   ['nanocarbon'=>10, 'titanium'=>3]],
  ['nano_fortress',   'Nano Fortress',        'armor',  0, 75, '&#128737;', '#ff2d95', 'Dense nano-carbon fortress suit. Barely flexible but nearly impregnable.',['nanocarbon'=>12, 'quantum'=>1]],
  ['quantum_shell',   'Quantum Shell Armor',  'armor',  0, 95, '&#128083;', '#a66de8', 'Quantum-phased shell armor. Partially phase-shifts incoming damage.',    ['quantum'=>5, 'nanocarbon'=>4]],
  ['quantum_plate',   'Quantum Plate',        'armor',  0,100, '&#128737;', '#a66de8', 'Quantum-locked plate system. Probability-shifted to resist hits.',       ['quantum'=>6, 'nanocarbon'=>3]],
  ['quantum_barrier', 'Quantum Barrier',      'armor',  0,105, '&#128737;', '#a66de8', 'Generates a low-level quantum shield field around the wearer.',          ['quantum'=>7, 'nanocarbon'=>3]],
  ['quantum_shroud',  'Quantum Shroud',       'armor',  0,108, '&#128737;', '#a66de8', 'Quantum-threaded cloak. Near-invisible to targeting systems.',           ['quantum'=>7, 'void'=>1]],
  ['quantum_dome',    'Quantum Dome',         'armor',  0,112, '&#128737;', '#a66de8', 'Projected quantum field dome. Absorbs high-yield kinetic rounds.',       ['quantum'=>8, 'nanocarbon'=>2]],
  ['void_plate',      'Void Plate Aegis',     'armor',  0,130, '&#11088;',  '#e8d44d', 'Void metal armor that warps physics around your body.',                  ['void'=>4, 'quantum'=>3]],
  ['void_carapace',   'Void Carapace',        'armor',  0,135, '&#128737;', '#e8d44d', 'Full void-metal carapace. Incoming attacks partially phase through.',    ['void'=>5, 'quantum'=>2]],
  ['void_matrix',     'Void Matrix',          'armor',  0,140, '&#128737;', '#e8d44d', 'Void-lattice matrix suit. Bends physics to deflect anything.',           ['void'=>5, 'quantum'=>3]],
  ['void_bastion',    'Void Bastion',         'armor',  0,145, '&#128737;', '#e8d44d', 'Void-forge bastion plate. The heaviest defensive build in existence.',   ['void'=>6, 'quantum'=>2]],
  ['void_absolute',   'Void Absolute',        'armor',  0,150, '&#11088;',  '#e8d44d', 'Ultimate void-metal fortress. It doesn\'t stop attacks — it unmakes them.',['void'=>7, 'quantum'=>3]],
];

// Ore icons/names for display
$ORE_NAMES = [
  'scrap'=>['Junk Metal','&#129419;'],'copper'=>['Copper Wire','&#127312;'],
  'iron'=>['Iron Alloy','&#9760;'],'titanium'=>['Titanium Core','&#128311;'],
  'nanocarbon'=>['Nano-Carbon','&#128302;'],'quantum'=>['Quantum Crystal','&#128142;'],
  'void'=>['Void Metal','&#11088;'],
];

// Load player ore
$invQ = $pdo->prepare('SELECT ore_type, quantity FROM player_ore WHERE player_id = ?');
$invQ->execute([$pid]);
$oreInv = [];
foreach ($invQ as $r) $oreInv[$r['ore_type']] = (int)$r['quantity'];

// Load crafted gear
$gearQ = $pdo->prepare('SELECT * FROM player_gear WHERE player_id = ? ORDER BY created_at DESC');
$gearQ->execute([$pid]);
$myGear = $gearQ->fetchAll();

// Equipped gear IDs from settings
$equippedWeapon = 0; $equippedArmor = 0;
try {
  $eq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $eq->execute(["equipped_weapon:{$pid}"]); $equippedWeapon = (int)$eq->fetchColumn();
  $eq->execute(["equipped_armor:{$pid}"]);  $equippedArmor  = (int)$eq->fetchColumn();
} catch (Throwable $e) {}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'craft') {
      $rid = $_POST['recipe_id'] ?? '';
      $recipe = null;
      foreach ($RECIPES as $r) { if ($r[0] === $rid) { $recipe = $r; break; } }
      if (!$recipe) throw new RuntimeException('Unknown recipe.');

      $cost = $recipe[8];
      // Verify ore availability
      foreach ($cost as $ore => $need) {
        if (($oreInv[$ore] ?? 0) < $need)
          throw new RuntimeException('Need ' . $need . '× ' . ($ORE_NAMES[$ore][0] ?? $ore) . ' — you only have ' . ($oreInv[$ore] ?? 0) . '.');
      }
      // Deduct ore
      $pdo->beginTransaction();
      foreach ($cost as $ore => $need) {
        $pdo->prepare('UPDATE player_ore SET quantity = quantity - ? WHERE player_id = ? AND ore_type = ? AND quantity >= ?')
            ->execute([$need, $pid, $ore, $need]);
      }
      // Insert gear
      $pdo->prepare('INSERT INTO player_gear (player_id, recipe_id, name, gear_type, atk_bonus, def_bonus) VALUES (?,?,?,?,?,?)')
          ->execute([$pid, $recipe[0], $recipe[1], $recipe[2], $recipe[3], $recipe[4]]);
      $newGearId = (int)$pdo->lastInsertId();
      $pdo->commit();

      // Reload
      $gearQ->execute([$pid]);
      $myGear = $gearQ->fetchAll();
      $invQ->execute([$pid]); $oreInv = [];
      foreach ($invQ as $r) $oreInv[$r['ore_type']] = (int)$r['quantity'];

      $msg = 'Fabricated: ' . e($recipe[1]) . ($recipe[2]==='weapon' ? ' (+'.$recipe[3].' ATK)' : ' (+'.$recipe[4].' DEF)');

    } elseif ($action === 'equip') {
      $gid = (int)($_POST['gear_id'] ?? 0);
      // Verify player owns it
      $ownQ = $pdo->prepare('SELECT gear_type FROM player_gear WHERE id=? AND player_id=?');
      $ownQ->execute([$gid, $pid]);
      $gtype = $ownQ->fetchColumn();
      if (!$gtype) throw new RuntimeException('You do not own that item.');
      $sk = $gtype === 'weapon' ? "equipped_weapon:{$pid}" : "equipped_armor:{$pid}";
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$sk, $gid]);
      if ($gtype === 'weapon') $equippedWeapon = $gid; else $equippedArmor = $gid;
      $msg = 'Equipped.';

    } elseif ($action === 'unequip') {
      $slot = $_POST['slot'] ?? '';
      if (!in_array($slot, ['weapon','armor'], true)) throw new RuntimeException('Invalid slot.');
      $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["equipped_{$slot}:{$pid}"]);
      if ($slot === 'weapon') $equippedWeapon = 0; else $equippedArmor = 0;
      $msg = 'Unequipped.';

    } elseif ($action === 'salvage') {
      $gid = (int)($_POST['gear_id'] ?? 0);
      $ownQ = $pdo->prepare('SELECT recipe_id, name FROM player_gear WHERE id=? AND player_id=?');
      $ownQ->execute([$gid, $pid]);
      $gitem = $ownQ->fetch();
      if (!$gitem) throw new RuntimeException('Item not found.');
      // Can't salvage equipped items
      if ($gid === $equippedWeapon || $gid === $equippedArmor) throw new RuntimeException('Unequip the item before salvaging.');
      // Find recipe, return 40% of ore cost
      $recipe = null;
      foreach ($RECIPES as $r) { if ($r[0] === $gitem['recipe_id']) { $recipe = $r; break; } }
      $pdo->beginTransaction();
      $pdo->prepare('DELETE FROM player_gear WHERE id=? AND player_id=?')->execute([$gid, $pid]);
      if ($recipe) {
        foreach ($recipe[8] as $ore => $need) {
          $ret = max(1, (int)floor($need * 0.4));
          $pdo->prepare('INSERT INTO player_ore (player_id, ore_type, quantity) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')->execute([$pid, $ore, $ret]);
        }
      }
      $pdo->commit();
      $gearQ->execute([$pid]); $myGear = $gearQ->fetchAll();
      $invQ->execute([$pid]); $oreInv = [];
      foreach ($invQ as $r) $oreInv[$r['ore_type']] = (int)$r['quantity'];
      if ($gid === $equippedWeapon) $equippedWeapon = 0;
      if ($gid === $equippedArmor)  $equippedArmor  = 0;
      $msg = 'Salvaged ' . e($gitem['name']) . '. Some materials recovered.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

// Helper: can the player afford a recipe?
function canAfford($cost, $oreInv) {
  foreach ($cost as $ore => $need) { if (($oreInv[$ore] ?? 0) < $need) return false; }
  return true;
}
?>

<div class="panel">
  <h2>&#9874; Fabrication Lab</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">"Every weapon in the Sprawl started as raw junk. The rest is engineering."</p>
  <?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>
</div>

<!-- Ore Stockpile Summary -->
<div class="panel">
  <h3 style="margin-top:0">&#128219; Stockpile</h3>
  <?php if (empty(array_filter($oreInv))): ?>
    <p class="muted">No ore. Head to <a href="index.php?p=mining">The Sump</a> to mine some.</p>
  <?php else: ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($ORE_NAMES as $oid => [$oname, $oicon]):
      $qty = $oreInv[$oid] ?? 0; if (!$qty) continue; ?>
    <div style="background:var(--panel2);border:1px solid rgba(25,240,199,.2);border-radius:7px;padding:7px 12px;display:flex;align-items:center;gap:7px">
      <span style="font-size:18px"><?= $oicon ?></span>
      <div>
        <div style="font-size:11px;color:var(--muted)"><?= $oname ?></div>
        <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:var(--accent)">&times;<?= number_format($qty) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$wctab = in_array($_GET['tab'] ?? '', ['weapons','armor','arsenal']) ? $_GET['tab'] : 'weapons';
$wcounts = ['weapons'=>0,'armor'=>0,'arsenal'=>count($myGear)];
foreach ($RECIPES as $r) { if ($r[2]==='weapon') $wcounts['weapons']++; else $wcounts['armor']++; }
?>
<!-- Tab Nav -->
<div style="display:flex;gap:8px;flex-wrap:wrap">
  <?php foreach (['weapons'=>'&#128299; Weapons ('.$wcounts['weapons'].')','armor'=>'&#128737; Armor ('.$wcounts['armor'].')','arsenal'=>'&#127863; Your Arsenal ('.$wcounts['arsenal'].')'] as $tk=>$tl): ?>
  <a href="index.php?p=weaponcraft&tab=<?= $tk ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $wctab===$tk?'var(--accent)':'var(--line)' ?>;background:<?= $wctab===$tk?'rgba(25,240,199,.1)':'var(--panel2)' ?>;color:<?= $wctab===$tk?'var(--accent)':'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($wctab === 'weapons'): ?>
<div class="panel">
  <h3 style="margin-top:0">&#128299; Weapons</h3>
  <p class="muted" style="font-size:12px;margin-bottom:12px">Craft from ore mined at The Sump. Red ore cost = you need more.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
  <?php foreach ($RECIPES as $r): if ($r[2] !== 'weapon') continue; $affordable = canAfford($r[8], $oreInv); ?>
  <div style="border:1px solid <?= $affordable ? 'rgba(25,240,199,.25)' : 'var(--line)' ?>;border-radius:7px;padding:10px;background:var(--panel2);<?= !$affordable ? 'opacity:.7' : '' ?>">
    <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px">
      <span style="font-size:22px;flex:none"><?= $r[5] ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:13px;color:<?= e($r[6]) ?>"><?= e($r[1]) ?></div>
        <div style="font-size:11px;color:var(--muted);margin:2px 0 4px"><?= e($r[7]) ?></div>
        <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.2);border-radius:4px;padding:2px 8px;font-size:11px;color:var(--neon2);font-weight:700">+<?= $r[3] ?> ATK<?= $r[4]>0?' &nbsp;+'.e($r[4]).' DEF':'' ?></div>
      </div>
    </div>
    <div style="font-size:11px;margin-bottom:6px;display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach ($r[8] as $ore => $need): $ok=($oreInv[$ore]??0)>=$need; ?>
        <span style="background:<?= $ok?'rgba(25,240,199,.07)':'rgba(255,45,149,.07)' ?>;border:1px solid <?= $ok?'rgba(25,240,199,.2)':'rgba(255,45,149,.3)' ?>;border-radius:4px;padding:2px 7px;color:<?= $ok?'var(--text)':'var(--neon2)' ?>">
          <?= $ORE_NAMES[$ore][1] ?> <?= $need ?>× <?= $ORE_NAMES[$ore][0] ?>
        </span>
      <?php endforeach; ?>
    </div>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="craft">
      <input type="hidden" name="recipe_id" value="<?= e($r[0]) ?>">
      <button type="submit" <?= $affordable ? '' : 'disabled' ?> class="btn btn-sm <?= $affordable ? 'btn-primary' : 'btn-ghost' ?>">
        <?= $affordable ? '&#9874; Fabricate' : '&#128683; Need more ore' ?>
      </button>
    </form>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php elseif ($wctab === 'armor'): ?>
<div class="panel">
  <h3 style="margin-top:0">&#128737; Armor</h3>
  <p class="muted" style="font-size:12px;margin-bottom:12px">Higher DEF means more survivability in combat. Red ore cost = you need more.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
  <?php foreach ($RECIPES as $r): if ($r[2] !== 'armor') continue; $affordable = canAfford($r[8], $oreInv); ?>
  <div style="border:1px solid <?= $affordable ? 'rgba(25,240,199,.25)' : 'var(--line)' ?>;border-radius:7px;padding:10px;background:var(--panel2);<?= !$affordable ? 'opacity:.7' : '' ?>">
    <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px">
      <span style="font-size:22px;flex:none"><?= $r[5] ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:13px;color:<?= e($r[6]) ?>"><?= e($r[1]) ?></div>
        <div style="font-size:11px;color:var(--muted);margin:2px 0 4px"><?= e($r[7]) ?></div>
        <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);border-radius:4px;padding:2px 8px;font-size:11px;color:var(--accent);font-weight:700">+<?= $r[4] ?> DEF<?= $r[3]>0?' &nbsp;+'.e($r[3]).' ATK':'' ?></div>
      </div>
    </div>
    <div style="font-size:11px;margin-bottom:6px;display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach ($r[8] as $ore => $need): $ok=($oreInv[$ore]??0)>=$need; ?>
        <span style="background:<?= $ok?'rgba(25,240,199,.07)':'rgba(255,45,149,.07)' ?>;border:1px solid <?= $ok?'rgba(25,240,199,.2)':'rgba(255,45,149,.3)' ?>;border-radius:4px;padding:2px 7px;color:<?= $ok?'var(--text)':'var(--neon2)' ?>">
          <?= $ORE_NAMES[$ore][1] ?> <?= $need ?>× <?= $ORE_NAMES[$ore][0] ?>
        </span>
      <?php endforeach; ?>
    </div>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="craft">
      <input type="hidden" name="recipe_id" value="<?= e($r[0]) ?>">
      <button type="submit" <?= $affordable ? '' : 'disabled' ?> class="btn btn-sm <?= $affordable ? 'btn-primary' : 'btn-ghost' ?>">
        <?= $affordable ? '&#9874; Fabricate' : '&#128683; Need more ore' ?>
      </button>
    </form>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php elseif ($wctab === 'arsenal'): ?>

<div class="panel">
  <h3 style="margin-top:0">&#127863; Your Arsenal</h3>
  <p class="muted" style="font-size:12px;margin-bottom:12px">Equip one weapon and one armor at a time. Equipped gear boosts your Combat stats.</p>
  <?php if (empty($myGear)): ?>
    <p class="muted" style="text-align:center;padding:16px">No gear crafted yet. Head to Weapons or Armor tab to fabricate something.</p>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">
    <?php foreach ($myGear as $g):
      $isEquipped = ($g['gear_type']==='weapon' ? $equippedWeapon : $equippedArmor) === (int)$g['id'];
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $isEquipped ? 'rgba(25,240,199,.5)' : 'var(--line)' ?>;border-radius:8px;padding:12px;<?= $isEquipped ? 'box-shadow:0 0 12px rgba(25,240,199,.1)' : '' ?>">
      <?php if ($isEquipped): ?>
        <div style="font-size:10px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">&#9654; Equipped</div>
      <?php endif; ?>
      <div style="font-weight:700;font-size:13px;color:var(--text);margin-bottom:3px"><?= e($g['name']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:8px"><?= $g['gear_type'] === 'weapon' ? 'Weapon' : 'Armor' ?></div>
      <?php if ($g['atk_bonus'] > 0): ?>
        <span style="background:rgba(255,45,149,.1);border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700">+<?= $g['atk_bonus'] ?> ATK</span>
      <?php endif; ?>
      <?php if ($g['def_bonus'] > 0): ?>
        <span style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);color:var(--accent);border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700">+<?= $g['def_bonus'] ?> DEF</span>
      <?php endif; ?>
      <div style="display:flex;gap:6px;margin-top:10px">
        <?php if (!$isEquipped): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="equip">
            <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-sm btn-primary" type="submit">Equip</button>
          </form>
        <?php else: ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="unequip">
            <input type="hidden" name="slot" value="<?= e($g['gear_type']) ?>">
            <button class="btn btn-sm btn-ghost" type="submit">Unequip</button>
          </form>
        <?php endif; ?>
        <?php if (!$isEquipped): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Salvage this item for ~40% ore refund?')">
            <input type="hidden" name="action" value="salvage">
            <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">Salvage</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php endif; // end tab block ?>
