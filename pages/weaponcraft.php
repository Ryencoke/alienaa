<?php /* pages/weaponcraft.php — Fabrication Lab: jury-rigged prototype gear built from mined ore.
   Everything here is homebrew — one-off builds that were never sold anywhere, distinct from
   The Blacksmith's catalog gear. Equipping a build (not crafting it) is level-gated per tier. */

$pid = $_SESSION['pid'];
$pdo = db();

// Schema
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_ore (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL,
    ore_type VARCHAR(32) NOT NULL, quantity INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_po (player_id, ore_type), KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}
ensure_player_gear_table($pdo);

// Material tiers — mirrors The Sump's mining depths 1-5. Equipping a build requires
// the player to have reached the tier's level; crafting itself only costs ore, so you
// can stockpile ahead of your own level (or donate to a Syndicate) without being blocked.
$TIERS = [
  'scrap'      => ['label'=>'Scrap',        'level_req'=>1,  'col'=>'#8a8fa8'],
  'copper'     => ['label'=>'Copper',       'level_req'=>6,  'col'=>'#e8a33d'],
  'iron'       => ['label'=>'Iron',         'level_req'=>12, 'col'=>'#b0b8cc'],
  'titanium'   => ['label'=>'Titanium',     'level_req'=>20, 'col'=>'#19f0c7'],
  'nanocarbon' => ['label'=>'Nano-Carbon',  'level_req'=>30, 'col'=>'#ff2d95'],
  'quantum'    => ['label'=>'Quantum',      'level_req'=>42, 'col'=>'#a66de8'],
  'void'       => ['label'=>'Void',         'level_req'=>58, 'col'=>'#e8d44d'],
];

// Blueprints. Every name here is a one-off homebrew build — deliberately distinct from
// anything The Blacksmith sells. Keys: id,name,type,atk,def,icon,tier,desc,cost.
$RECIPES = [
  // ══════════ WEAPONS ══════════
  ['id'=>'scrap_pike',    'name'=>'Rebar Pike',           'type'=>'weapon','atk'=>5,  'def'=>0,'icon'=>'&#9874;',  'tier'=>'scrap',     'desc'=>'Sharpened rebar welded to a length of scaffold pipe.',              'cost'=>['scrap'=>8]],
  ['id'=>'scrap_knux',    'name'=>'Pipe Knuckles',        'type'=>'weapon','atk'=>7,  'def'=>0,'icon'=>'&#128299;','tier'=>'scrap',     'desc'=>'Cast from melted plumbing. Ugly. Works.',                           'cost'=>['scrap'=>10]],
  ['id'=>'scrap_hook',    'name'=>'Salvage Hook',         'type'=>'weapon','atk'=>8,  'def'=>1,'icon'=>'&#9876;',  'tier'=>'scrap',     'desc'=>'Scrapyard cargo hook, reground to a killing edge.',                 'cost'=>['scrap'=>12]],

  ['id'=>'cu_dagger',     'name'=>'Wireframe Dagger',     'type'=>'weapon','atk'=>13, 'def'=>0,'icon'=>'&#128299;','tier'=>'copper',    'desc'=>'Copper wire braided over a filed-down blade core.',                 'cost'=>['copper'=>9,'scrap'=>5]],
  ['id'=>'cu_bat',        'name'=>'Coil-Wrapped Bat',     'type'=>'weapon','atk'=>15, 'def'=>0,'icon'=>'&#9876;',  'tier'=>'copper',    'desc'=>'Induction coil wound around a bat core. Hits harder than it should.','cost'=>['copper'=>10,'scrap'=>6]],
  ['id'=>'cu_spike',      'name'=>'Conduit Spike',        'type'=>'weapon','atk'=>17, 'def'=>1,'icon'=>'&#128299;','tier'=>'copper',    'desc'=>'Live-conduit spike, insulated just enough to hold.',                'cost'=>['copper'=>11,'scrap'=>5]],
  ['id'=>'cu_pole',       'name'=>'Live-Wire Halberd',    'type'=>'weapon','atk'=>19, 'def'=>0,'icon'=>'&#9874;',  'tier'=>'copper',    'desc'=>'Reach weapon rigged from stripped power lines and pipe.',           'cost'=>['copper'=>13,'scrap'=>7]],

  ['id'=>'fe_cleaver',    'name'=>'Foundry Cleaver',      'type'=>'weapon','atk'=>24, 'def'=>0,'icon'=>'&#9876;',  'tier'=>'iron',      'desc'=>'Slag-forged in a backstreet foundry, edge tempered by hand.',       'cost'=>['iron'=>11,'copper'=>6]],
  ['id'=>'fe_baton',      'name'=>'Riot Pipe',            'type'=>'weapon','atk'=>26, 'def'=>1,'icon'=>'&#9874;',  'tier'=>'iron',      'desc'=>'Salvaged riot-gear pipe, weighted core added by hand.',             'cost'=>['iron'=>10,'copper'=>7]],
  ['id'=>'fe_claw',       'name'=>'Grip-Claw Gauntlet',   'type'=>'weapon','atk'=>28, 'def'=>2,'icon'=>'&#128299;','tier'=>'iron',      'desc'=>'Loader-claw fingers cut down and fitted to a glove.',               'cost'=>['iron'=>12,'copper'=>5]],
  ['id'=>'fe_maul',       'name'=>'Junction Maul',        'type'=>'weapon','atk'=>32, 'def'=>0,'icon'=>'&#9876;',  'tier'=>'iron',      'desc'=>'Transformer-junction housing repurposed as a maul head.',           'cost'=>['iron'=>15,'copper'=>6]],

  ['id'=>'ti_edge',       'name'=>'Featherweight Edge',   'type'=>'weapon','atk'=>38, 'def'=>0,'icon'=>'&#128481;','tier'=>'titanium',  'desc'=>'Aerospace scrap ground paper-thin. Almost too light to trust.',     'cost'=>['titanium'=>9,'iron'=>5]],
  ['id'=>'ti_lance',      'name'=>'Skeleton Lance',       'type'=>'weapon','atk'=>42, 'def'=>0,'icon'=>'&#9874;',  'tier'=>'titanium',  'desc'=>'Hollow-frame lance, drilled for weight without losing bite.',       'cost'=>['titanium'=>10,'copper'=>4]],
  ['id'=>'ti_scattergun', 'name'=>'Patchwork Scattergun', 'type'=>'weapon','atk'=>44, 'def'=>0,'icon'=>'&#128299;','tier'=>'titanium',  'desc'=>'Three mismatched barrels, one trigger. Don\'t ask how it\'s legal.', 'cost'=>['titanium'=>11,'iron'=>6]],
  ['id'=>'ti_needle',     'name'=>'Airframe Needle',      'type'=>'weapon','atk'=>47, 'def'=>0,'icon'=>'&#9876;',  'tier'=>'titanium',  'desc'=>'Fuselage strut reforged into a single killing point.',              'cost'=>['titanium'=>12,'iron'=>4]],

  ['id'=>'nc_razor',      'name'=>'Filament Razor',       'type'=>'weapon','atk'=>58, 'def'=>0,'icon'=>'&#128302;','tier'=>'nanocarbon','desc'=>'Woven carbon filament, hand-drawn to a monomolecular edge.',        'cost'=>['nanocarbon'=>7,'titanium'=>6]],
  ['id'=>'nc_whip',       'name'=>'Threadwhip',           'type'=>'weapon','atk'=>62, 'def'=>0,'icon'=>'&#9876;',  'tier'=>'nanocarbon','desc'=>'Segmented carbon thread — flexible until it isn\'t.',               'cost'=>['nanocarbon'=>8,'titanium'=>5]],
  ['id'=>'nc_bow',        'name'=>'Tension Bow',          'type'=>'weapon','atk'=>64, 'def'=>0,'icon'=>'&#128299;','tier'=>'nanocarbon','desc'=>'Carbon-limb recurve, drawn tighter than any factory rig allows.',    'cost'=>['nanocarbon'=>8,'iron'=>5]],
  ['id'=>'nc_chain',      'name'=>'Lash-Chain Rig',       'type'=>'weapon','atk'=>67, 'def'=>0,'icon'=>'&#9876;',  'tier'=>'nanocarbon','desc'=>'Carbon-link chain rig that wraps and cuts in one motion.',          'cost'=>['nanocarbon'=>9,'iron'=>4]],
  ['id'=>'nc_carbine',    'name'=>'Ghostframe Carbine',   'type'=>'weapon','atk'=>70, 'def'=>0,'icon'=>'&#128299;','tier'=>'nanocarbon','desc'=>'Printed carbon receiver, untraceable serial, no manufacturer.',      'cost'=>['nanocarbon'=>8,'quantum'=>1]],

  ['id'=>'qm_dirk',       'name'=>'Phase Dirk',           'type'=>'weapon','atk'=>80, 'def'=>0,'icon'=>'&#128142;','tier'=>'quantum',   'desc'=>'Crystal-lattice edge that flickers half a phase out of sync.',      'cost'=>['quantum'=>5,'nanocarbon'=>4]],
  ['id'=>'qm_pike',       'name'=>'Flux Pike',            'type'=>'weapon','atk'=>84, 'def'=>0,'icon'=>'&#9874;',  'tier'=>'quantum',   'desc'=>'Field-unstable pike tip, rebuilt from a containment breach.',        'cost'=>['quantum'=>6,'titanium'=>4]],
  ['id'=>'qm_cannon',     'name'=>'Collapsar Cannon',     'type'=>'weapon','atk'=>87, 'def'=>0,'icon'=>'&#128299;','tier'=>'quantum',   'desc'=>'Hand-wound crystal coil. Fires whatever it can compress.',           'cost'=>['quantum'=>6,'nanocarbon'=>3]],
  ['id'=>'qm_blade',      'name'=>'Entangled Edge',       'type'=>'weapon','atk'=>90, 'def'=>0,'icon'=>'&#128481;','tier'=>'quantum',   'desc'=>'A blade paired with itself across a fold that shouldn\'t exist.',    'cost'=>['quantum'=>7,'nanocarbon'=>3]],
  ['id'=>'qm_lance',      'name'=>'Probability Lance',    'type'=>'weapon','atk'=>94, 'def'=>0,'icon'=>'&#9874;',  'tier'=>'quantum',   'desc'=>'Strikes where you were about to be, not where you are.',            'cost'=>['quantum'=>8,'void'=>1]],

  ['id'=>'vd_ripper',     'name'=>'Blackout Ripper',      'type'=>'weapon','atk'=>112,'def'=>0,'icon'=>'&#11088;', 'tier'=>'void',      'desc'=>'Salvaged void-metal shard, jury-rigged to an old grip.',             'cost'=>['void'=>4,'quantum'=>3]],
  ['id'=>'vd_pike',       'name'=>'Eventhorizon Pike',    'type'=>'weapon','atk'=>120,'def'=>0,'icon'=>'&#9874;',  'tier'=>'void',      'desc'=>'The tip bends light. Nobody who built this fully understands why.',  'cost'=>['void'=>5,'nanocarbon'=>3]],
  ['id'=>'vd_hammer',     'name'=>'Singularity Hammer',   'type'=>'weapon','atk'=>127,'def'=>0,'icon'=>'&#9876;',  'tier'=>'void',      'desc'=>'A hammerhead that seems to weigh more the harder it swings.',        'cost'=>['void'=>5,'quantum'=>3]],
  ['id'=>'vd_shiv',       'name'=>'Null Shiv',            'type'=>'weapon','atk'=>132,'def'=>0,'icon'=>'&#11088;', 'tier'=>'void',      'desc'=>'A shard that doesn\'t reflect light. Or much of anything else.',     'cost'=>['void'=>6,'quantum'=>2]],
  ['id'=>'vd_fist',       'name'=>'Wraith Fist',          'type'=>'weapon','atk'=>137,'def'=>0,'icon'=>'&#128299;','tier'=>'void',      'desc'=>'Void-metal knuckle housing. Punches through hardlight bare-handed.', 'cost'=>['void'=>7,'quantum'=>3]],
  ['id'=>'vd_cannon',     'name'=>'Oblivion Driver',      'type'=>'weapon','atk'=>142,'def'=>0,'icon'=>'&#128299;','tier'=>'void',      'desc'=>'Homebrew mass-driver. Every barrel that came before it failed.',     'cost'=>['void'=>8,'quantum'=>4]],

  // ══════════ ARMOR ══════════
  ['id'=>'scrap_pad',     'name'=>'Bin-Lid Chestplate',   'type'=>'armor', 'atk'=>0,'def'=>7,  'icon'=>'&#128737;','tier'=>'scrap',     'desc'=>'Dumpster lids, wired together with baling wire.',                   'cost'=>['scrap'=>10]],
  ['id'=>'scrap_wrap',    'name'=>'Scrap Wraps',          'type'=>'armor', 'atk'=>0,'def'=>9,  'icon'=>'&#129683;','tier'=>'scrap',     'desc'=>'Layered sheet metal strips, wrapped and riveted by hand.',          'cost'=>['scrap'=>12]],
  ['id'=>'scrap_shell',   'name'=>'Dumpster Shell',       'type'=>'armor', 'atk'=>0,'def'=>10, 'icon'=>'&#128737;','tier'=>'scrap',     'desc'=>'A dented panel, bent to fit a torso. Surprisingly solid.',          'cost'=>['scrap'=>14]],

  ['id'=>'cu_weave',      'name'=>'Coil-Weave Vest',      'type'=>'armor', 'atk'=>0,'def'=>16, 'icon'=>'&#129683;','tier'=>'copper',    'desc'=>'Copper coil woven through a salvaged flak liner.',                  'cost'=>['copper'=>12,'scrap'=>6]],
  ['id'=>'cu_plate',      'name'=>'Circuit Plate',        'type'=>'armor', 'atk'=>0,'def'=>19, 'icon'=>'&#128737;','tier'=>'copper',    'desc'=>'Stripped circuit boards laminated into a rigid plate.',             'cost'=>['copper'=>13,'scrap'=>7]],
  ['id'=>'cu_liner',      'name'=>'Conduit-Lined Coat',   'type'=>'armor', 'atk'=>0,'def'=>21, 'icon'=>'&#128737;','tier'=>'copper',    'desc'=>'A street coat lined with flattened conduit pipe.',                  'cost'=>['copper'=>14,'scrap'=>6]],
  ['id'=>'cu_guard',      'name'=>'Grounded Guard',       'type'=>'armor', 'atk'=>0,'def'=>23, 'icon'=>'&#128737;','tier'=>'copper',    'desc'=>'Full copper wrap, deliberately earthed against shock rounds.',      'cost'=>['copper'=>16,'scrap'=>5]],

  ['id'=>'fe_rig',        'name'=>'Foundry Rig',          'type'=>'armor', 'atk'=>0,'def'=>29, 'icon'=>'&#129667;','tier'=>'iron',      'desc'=>'Foundry apron reinforced with cast-off plate.',                     'cost'=>['iron'=>14,'copper'=>7]],
  ['id'=>'fe_coat',       'name'=>'Rebar-Lined Coat',     'type'=>'armor', 'atk'=>0,'def'=>31, 'icon'=>'&#128737;','tier'=>'iron',      'desc'=>'Rebar segments sewn into a heavy longcoat lining.',                 'cost'=>['iron'=>14,'copper'=>6]],
  ['id'=>'fe_shell',      'name'=>'Pressed Shell Plate',  'type'=>'armor', 'atk'=>0,'def'=>33, 'icon'=>'&#128737;','tier'=>'iron',      'desc'=>'Hydraulic-pressed iron shell, hand-fitted joint by joint.',         'cost'=>['iron'=>16,'copper'=>6]],
  ['id'=>'fe_bulwark',    'name'=>'Junction Bulwark',     'type'=>'armor', 'atk'=>0,'def'=>36, 'icon'=>'&#128737;','tier'=>'iron',      'desc'=>'Substation housing cut down into wearable plating.',                'cost'=>['iron'=>17,'copper'=>8]],

  ['id'=>'ti_frame',      'name'=>'Skeleton Frame Suit',  'type'=>'armor', 'atk'=>0,'def'=>46, 'icon'=>'&#127760;','tier'=>'titanium',  'desc'=>'A titanium exo-skeleton with the panels stripped for weight.',      'cost'=>['titanium'=>12,'iron'=>6]],
  ['id'=>'ti_mesh',       'name'=>'Airframe Mesh',        'type'=>'armor', 'atk'=>0,'def'=>49, 'icon'=>'&#129683;','tier'=>'titanium',  'desc'=>'Fuselage mesh cut and reshaped into wearable panels.',              'cost'=>['titanium'=>13,'iron'=>5]],
  ['id'=>'ti_shell',      'name'=>'Featherweight Shell',  'type'=>'armor', 'atk'=>0,'def'=>51, 'icon'=>'&#128737;','tier'=>'titanium',  'desc'=>'Impossibly light for what it stops. Hand-tuned, not tested.',       'cost'=>['titanium'=>14,'iron'=>5]],
  ['id'=>'ti_cowl',       'name'=>'Patchwork Cowl',       'type'=>'armor', 'atk'=>0,'def'=>53, 'icon'=>'&#128737;','tier'=>'titanium',  'desc'=>'Titanium plates stitched over a stealth-market undersuit.',         'cost'=>['titanium'=>15,'iron'=>4]],

  ['id'=>'nc_weave',      'name'=>'Filament Weave Suit',  'type'=>'armor', 'atk'=>0,'def'=>69, 'icon'=>'&#127951;','tier'=>'nanocarbon','desc'=>'Hand-loomed carbon filament. No two seams are the same.',           'cost'=>['nanocarbon'=>10,'titanium'=>5]],
  ['id'=>'nc_shell',      'name'=>'Threadmail Shell',     'type'=>'armor', 'atk'=>0,'def'=>71, 'icon'=>'&#128737;','tier'=>'nanocarbon','desc'=>'Carbon thread knotted into an improvised mail weave.',              'cost'=>['nanocarbon'=>11,'titanium'=>4]],
  ['id'=>'nc_lattice',    'name'=>'Tension Lattice',      'type'=>'armor', 'atk'=>0,'def'=>73, 'icon'=>'&#128737;','tier'=>'nanocarbon','desc'=>'A pre-stressed carbon lattice that stiffens on impact.',            'cost'=>['nanocarbon'=>12,'titanium'=>4]],
  ['id'=>'nc_husk',       'name'=>'Ghostframe Husk',      'type'=>'armor', 'atk'=>0,'def'=>76, 'icon'=>'&#128737;','tier'=>'nanocarbon','desc'=>'Printed carbon shell, no maker\'s mark, no serial.',                'cost'=>['nanocarbon'=>13,'quantum'=>1]],
  ['id'=>'nc_cloak',      'name'=>'Lashweave Cloak',      'type'=>'armor', 'atk'=>0,'def'=>78, 'icon'=>'&#128737;','tier'=>'nanocarbon','desc'=>'Loose carbon-thread cloak, tightens under sudden force.',           'cost'=>['nanocarbon'=>14,'titanium'=>3]],

  ['id'=>'qm_shroud',     'name'=>'Phase Shroud',         'type'=>'armor', 'atk'=>0,'def'=>96, 'icon'=>'&#128083;','tier'=>'quantum',   'desc'=>'Crystal-threaded shroud that\'s never quite fully there.',          'cost'=>['quantum'=>6,'nanocarbon'=>5]],
  ['id'=>'qm_plate',      'name'=>'Flux Plate',           'type'=>'armor', 'atk'=>0,'def'=>101,'icon'=>'&#128737;','tier'=>'quantum',   'desc'=>'Field-unstable plating, rebuilt from a containment breach.',        'cost'=>['quantum'=>7,'nanocarbon'=>4]],
  ['id'=>'qm_shell',      'name'=>'Entangled Shell',      'type'=>'armor', 'atk'=>0,'def'=>106,'icon'=>'&#128737;','tier'=>'quantum',   'desc'=>'Paired with a shell that took the hit somewhere else.',             'cost'=>['quantum'=>8,'nanocarbon'=>4]],
  ['id'=>'qm_dome',       'name'=>'Collapsar Dome',       'type'=>'armor', 'atk'=>0,'def'=>109,'icon'=>'&#128737;','tier'=>'quantum',   'desc'=>'Hand-wound crystal coil projects a compressive field.',             'cost'=>['quantum'=>9,'nanocarbon'=>3]],
  ['id'=>'qm_cloak',      'name'=>'Probability Cloak',    'type'=>'armor', 'atk'=>0,'def'=>113,'icon'=>'&#128737;','tier'=>'quantum',   'desc'=>'Rarely where the incoming round expects it to be.',                 'cost'=>['quantum'=>9,'void'=>1]],

  ['id'=>'vd_carapace',   'name'=>'Blackout Carapace',    'type'=>'armor', 'atk'=>0,'def'=>131,'icon'=>'&#11088;', 'tier'=>'void',      'desc'=>'Salvaged void-metal plate, jury-rigged to a harness.',              'cost'=>['void'=>5,'quantum'=>4]],
  ['id'=>'vd_plate',      'name'=>'Eventhorizon Plate',   'type'=>'armor', 'atk'=>0,'def'=>136,'icon'=>'&#128737;','tier'=>'void',      'desc'=>'Bends light around the wearer. Nobody fully understands why.',      'cost'=>['void'=>6,'quantum'=>3]],
  ['id'=>'vd_bastion',    'name'=>'Singularity Bastion',  'type'=>'armor', 'atk'=>0,'def'=>141,'icon'=>'&#128737;','tier'=>'void',      'desc'=>'Seems to weigh more the harder it gets hit. Unclear why.',          'cost'=>['void'=>7,'quantum'=>3]],
  ['id'=>'vd_shroud',     'name'=>'Null Shroud',          'type'=>'armor', 'atk'=>0,'def'=>146,'icon'=>'&#11088;', 'tier'=>'void',      'desc'=>'Doesn\'t reflect light. Or much of anything else, really.',         'cost'=>['void'=>7,'quantum'=>4]],
  ['id'=>'vd_aegis',      'name'=>'Wraith Aegis',         'type'=>'armor', 'atk'=>0,'def'=>151,'icon'=>'&#128737;','tier'=>'void',      'desc'=>'Void-metal housing. Every prototype before it failed testing.',     'cost'=>['void'=>8,'quantum'=>4]],
];
$RECIPES_BY_ID = [];
foreach ($RECIPES as $r) $RECIPES_BY_ID[$r['id']] = $r;

// Ore icons/names for display
$ORE_NAMES = [
  'scrap'=>['Junk Metal','&#129419;'],'copper'=>['Copper Wire','&#127312;'],
  'iron'=>['Iron Alloy','&#9760;'],'titanium'=>['Titanium Core','&#128311;'],
  'nanocarbon'=>['Nano-Carbon','&#128302;'],'quantum'=>['Quantum Crystal','&#128142;'],
  'void'=>['Void Metal','&#11088;'],
];

$myLevel = (int)($player['level'] ?? 1);

function wc_load_ore($pdo, $pid): array {
  $q = $pdo->prepare('SELECT ore_type, quantity FROM player_ore WHERE player_id = ?');
  $q->execute([$pid]);
  $out = [];
  foreach ($q as $r) $out[$r['ore_type']] = (int)$r['quantity'];
  return $out;
}
function wc_can_afford($cost, $oreInv) {
  foreach ($cost as $ore => $need) { if (($oreInv[$ore] ?? 0) < $need) return false; }
  return true;
}

// ── AJAX: crafting (the interactive part — mirrors vats.php's AJAX pattern) ────────────
if (!empty($_POST['wc_ajax'])) {
  header('Content-Type: application/json');
  $wcAct = $_POST['wc_action'] ?? '';
  try {
    if ($wcAct === 'craft') {
      $rid = $_POST['recipe_id'] ?? '';
      $recipe = $RECIPES_BY_ID[$rid] ?? null;
      if (!$recipe) throw new RuntimeException('Unknown blueprint.');
      $oreInv = wc_load_ore($pdo, $pid);
      $cost = $recipe['cost'];
      foreach ($cost as $ore => $need) {
        if (($oreInv[$ore] ?? 0) < $need) throw new RuntimeException('Need '.$need.'× '.($ORE_NAMES[$ore][0] ?? $ore).' — you only have '.($oreInv[$ore] ?? 0).'.');
      }
      $pdo->beginTransaction();
      foreach ($cost as $ore => $need) {
        $du = $pdo->prepare('UPDATE player_ore SET quantity = quantity - ? WHERE player_id = ? AND ore_type = ? AND quantity >= ?');
        $du->execute([$need, $pid, $ore, $need]);
        if ($du->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Stock changed — try again.'); }
      }
      $pdo->prepare('INSERT INTO player_gear (player_id, recipe_id, name, gear_type, atk_bonus, def_bonus) VALUES (?,?,?,?,?,?)')
          ->execute([$pid, $recipe['id'], $recipe['name'], $recipe['type'], $recipe['atk'], $recipe['def']]);
      $pdo->commit();
      $oreInv = wc_load_ore($pdo, $pid);
      echo json_encode(['ok'=>true, 'ore'=>$oreInv, 'item'=>[
        'name'=>$recipe['name'],'icon'=>$recipe['icon'],'col'=>$TIERS[$recipe['tier']]['col'],
        'atk'=>$recipe['atk'],'def'=>$recipe['def'],'type'=>$recipe['type'],
      ], 'msg'=>$recipe['name'].' fabricated.']);
      exit;
    }
    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

// ── Regular POST handlers: equip / unequip / salvage (unchanged flow, level-gated equip) ──
$msg = '';
$oreInv = wc_load_ore($pdo, $pid);
$gearQ = $pdo->prepare('SELECT * FROM player_gear WHERE player_id = ? ORDER BY created_at DESC');
$gearQ->execute([$pid]);
$myGear = $gearQ->fetchAll();
$equippedWeapon = 0; $equippedArmor = 0;
try {
  $eq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $eq->execute(["equipped_weapon:{$pid}"]); $equippedWeapon = (int)$eq->fetchColumn();
  $eq->execute(["equipped_armor:{$pid}"]);  $equippedArmor  = (int)$eq->fetchColumn();
} catch (Throwable $e) {}

$msgErr = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['wc_ajax'])) {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'equip') {
      $gid = (int)($_POST['gear_id'] ?? 0);
      $ownQ = $pdo->prepare('SELECT gear_type, recipe_id FROM player_gear WHERE id=? AND player_id=?');
      $ownQ->execute([$gid, $pid]);
      $owned = $ownQ->fetch();
      if (!$owned) throw new RuntimeException('You do not own that item.');
      $recipe = $RECIPES_BY_ID[$owned['recipe_id']] ?? null;
      if ($recipe) {
        $reqLevel = $TIERS[$recipe['tier']]['level_req'] ?? 1;
        if ($myLevel < $reqLevel) throw new RuntimeException('Requires Level '.$reqLevel.' to equip.');
      }
      $gtype = $owned['gear_type'];
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
      $ownQ = $pdo->prepare('SELECT recipe_id, name, loan_id FROM player_gear WHERE id=? AND player_id=?');
      $ownQ->execute([$gid, $pid]);
      $gitem = $ownQ->fetch();
      if (!$gitem) throw new RuntimeException('Item not found.');
      if ((int)($gitem['loan_id'] ?? 0) > 0) throw new RuntimeException('This is on loan from your Syndicate — return it from the Armoury instead of salvaging.');
      if ($gid === $equippedWeapon || $gid === $equippedArmor) throw new RuntimeException('Unequip the item before salvaging.');
      $recipe = $RECIPES_BY_ID[$gitem['recipe_id']] ?? null;
      $pdo->beginTransaction();
      $del = $pdo->prepare('DELETE FROM player_gear WHERE id=? AND player_id=?');
      $del->execute([$gid, $pid]);
      if ($del->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Item already salvaged.'); }
      if ($recipe) {
        foreach ($recipe['cost'] as $ore => $need) {
          $ret = max(1, (int)floor($need * 0.4));
          $pdo->prepare('INSERT INTO player_ore (player_id, ore_type, quantity) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')->execute([$pid, $ore, $ret]);
        }
      }
      $pdo->commit();
      if ($gid === $equippedWeapon) $equippedWeapon = 0;
      if ($gid === $equippedArmor)  $equippedArmor  = 0;
      $msg = 'Salvaged ' . e($gitem['name']) . '. Some materials recovered.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
  // Reload after any mutation
  $oreInv = wc_load_ore($pdo, $pid);
  $gearQ->execute([$pid]); $myGear = $gearQ->fetchAll();
}

$wctab = in_array($_GET['tab'] ?? '', ['weapons','armor','arsenal']) ? $_GET['tab'] : 'weapons';
$wcounts = ['weapons'=>0,'armor'=>0,'arsenal'=>count($myGear)];
foreach ($RECIPES as $r) { if ($r['type']==='weapon') $wcounts['weapons']++; else $wcounts['armor']++; }
$recipesJson = json_encode($RECIPES);
$tiersJson = json_encode($TIERS);
$oreJson = json_encode($oreInv);
$oreNamesJson = json_encode($ORE_NAMES);
?>

<?= scene_header('wc-canvas', '&#9874;', 'Fabrication Lab',
      '"Every build here is a one-off. Nobody sells this — you make it or you don\'t have it."', 'blueprint', '#19f0c7') ?>
<?= scene_header_js() ?>
<?php if ($msg): ?><div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div><?php endif; ?>

<style>
.wc-lvl-pill{display:inline-flex;align-items:center;gap:5px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;color:var(--accent)}
.wc-card{position:relative;overflow:hidden;background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:8px 9px;cursor:pointer;transition:border-color .15s,transform .12s,box-shadow .15s}
.wc-card:hover{transform:translateY(-1px)}
.wc-card.sel{border-color:var(--wc-col,var(--accent));box-shadow:0 0 14px var(--wc-glow,rgba(25,240,199,.15))}
.wc-card.broke{opacity:.55}
.wc-chip{display:inline-block;font-size:10px;border-radius:4px;padding:2px 7px;margin:1px 3px 1px 0}
#wc-layout{align-items:start}
#wc-bay{position:sticky;top:10px}
#wc-bay-canvas{display:block;width:100%;height:92px;border-radius:8px 8px 0 0;background:linear-gradient(180deg,#05050d,#090914)}
.wc-gear-card{background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:12px}
.wc-gear-card.equipped{border-color:rgba(25,240,199,.5);box-shadow:0 0 12px rgba(25,240,199,.1)}
.wc-gear-card.locked{opacity:.65}
@keyframes wcPop{0%{transform:scale(.5);opacity:0}70%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}
.wc-pop{animation:wcPop .3s ease-out}
</style>

<!-- Player level + Ore Stockpile -->
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px">
    <h3 style="margin:0">&#128219; Stockpile</h3>
    <span class="wc-lvl-pill">&#11088; Level <?= $myLevel ?></span>
  </div>
  <?php if (empty(array_filter($oreInv))): ?>
    <p class="muted">No ore. Head to <a href="index.php?p=mining">The Sump</a> to mine some.</p>
  <?php else: ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px" id="wc-ore-row">
    <?php foreach ($ORE_NAMES as $oid => [$oname, $oicon]):
      $qty = $oreInv[$oid] ?? 0; if (!$qty) continue; ?>
    <div class="wc-ore-box" data-ore="<?= $oid ?>" style="background:var(--panel2);border:1px solid rgba(25,240,199,.2);border-radius:7px;padding:7px 12px;display:flex;align-items:center;gap:7px">
      <span style="font-size:18px"><?= $oicon ?></span>
      <div>
        <div style="font-size:11px;color:var(--muted)"><?= $oname ?></div>
        <div class="wc-ore-qty" style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:var(--accent)">&times;<?= number_format($qty) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Tab Nav -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach (['weapons'=>'&#128299; Weapons ('.$wcounts['weapons'].')','armor'=>'&#128737; Armor ('.$wcounts['armor'].')','arsenal'=>'&#127863; Your Arsenal ('.$wcounts['arsenal'].')'] as $tk=>$tl): ?>
  <a href="index.php?p=weaponcraft&tab=<?= $tk ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $wctab===$tk?'var(--accent)':'var(--line)' ?>;background:<?= $wctab===$tk?'rgba(25,240,199,.1)':'var(--panel2)' ?>;color:<?= $wctab===$tk?'var(--accent)':'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($wctab === 'weapons' || $wctab === 'armor'):
  $wantType = $wctab === 'weapons' ? 'weapon' : 'armor';
?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px" id="wc-layout">
  <div class="panel" style="margin:0">
    <h3 style="margin-top:0"><?= $wctab === 'weapons' ? '&#128299; Weapon Blueprints' : '&#128737; Armor Blueprints' ?></h3>
    <p class="muted" style="font-size:12px;margin-bottom:12px">Pick a blueprint, then fabricate it in the bay on the right. Ore mined at <a href="index.php?p=mining">The Sump</a>.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:8px" id="wc-grid">
    <?php foreach ($RECIPES as $r): if ($r['type'] !== $wantType) continue;
      $tier = $TIERS[$r['tier']]; $affordable = wc_can_afford($r['cost'], $oreInv);
      $costParts = [];
      foreach ($r['cost'] as $ore => $need) { $ok = ($oreInv[$ore] ?? 0) >= $need; $costParts[] = "<span class='wc-chip' style='background:".($ok?'rgba(25,240,199,.07)':'rgba(255,45,149,.07)')."; border:1px solid ".($ok?'rgba(25,240,199,.2)':'rgba(255,45,149,.3)')."; color:".($ok?'var(--text)':'var(--neon2)')."'>".$ORE_NAMES[$ore][1]." ".$need."&times; ".$ORE_NAMES[$ore][0]."</span>"; }
    ?>
    <div class="wc-card <?= $affordable ? '' : 'broke' ?>" data-id="<?= e($r['id']) ?>" style="--wc-col:<?= $tier['col'] ?>;--wc-glow:<?= $tier['col'] ?>33">
      <div style="display:flex;align-items:center;gap:7px">
        <span style="font-size:17px;flex:none"><?= $r['icon'] ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:12px;color:<?= $tier['col'] ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['name']) ?></div>
          <div style="font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Lv<?= $tier['level_req'] ?>+ to equip</div>
        </div>
      </div>
      <div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:3px">
        <?php if ($r['atk'] > 0): ?><span class="wc-chip" style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.2);color:var(--neon2);font-weight:700">+<?= $r['atk'] ?> ATK</span><?php endif; ?>
        <?php if ($r['def'] > 0): ?><span class="wc-chip" style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);color:var(--accent);font-weight:700">+<?= $r['def'] ?> DEF</span><?php endif; ?>
        <?= implode(' ', $costParts) ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- Fabrication Bay -->
  <div class="panel" id="wc-bay" style="margin:0;padding:0;overflow:hidden">
    <canvas id="wc-bay-canvas"></canvas>
    <div style="padding:14px">
      <h3 style="margin:0 0 8px;font-size:13px">&#9874; Fabrication Bay</h3>
      <div id="wc-bay-empty" class="muted" style="font-size:12px;text-align:center;padding:20px 0">Select a blueprint to begin.</div>
      <div id="wc-bay-selected" style="display:none">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span id="wc-bay-icon" style="font-size:26px"></span>
          <div>
            <div id="wc-bay-name" style="font-weight:700;font-size:14px"></div>
            <div id="wc-bay-tier" class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.05em"></div>
          </div>
        </div>
        <div id="wc-bay-desc" class="muted" style="font-size:12px;margin-bottom:8px"></div>
        <div id="wc-bay-stats" style="margin-bottom:8px"></div>
        <div id="wc-bay-cost" style="font-size:11px;margin-bottom:12px"></div>
        <button id="wc-bay-btn" type="button" style="width:100%">&#9874; Begin Fabrication</button>
        <div id="wc-bay-msg" class="muted" style="font-size:11px;text-align:center;margin-top:8px;min-height:14px"></div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($wctab === 'arsenal'): ?>

<div class="panel">
  <h3 style="margin-top:0">&#127863; Your Arsenal</h3>
  <p class="muted" style="font-size:12px;margin-bottom:12px">Equip one weapon and one armor at a time. Builds below your level can be owned and stockpiled, but not worn until you catch up.</p>
  <?php if (empty($myGear)): ?>
    <p class="muted" style="text-align:center;padding:16px">Nothing fabricated yet. Head to Weapons or Armor to build something.</p>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">
    <?php foreach ($myGear as $g):
      $isEquipped = ($g['gear_type']==='weapon' ? $equippedWeapon : $equippedArmor) === (int)$g['id'];
      $recipe = $RECIPES_BY_ID[$g['recipe_id']] ?? null;
      $tier = $recipe ? $TIERS[$recipe['tier']] : null;
      $reqLevel = $tier ? $tier['level_req'] : 1;
      $locked = $myLevel < $reqLevel;
    ?>
    <div class="wc-gear-card <?= $isEquipped ? 'equipped' : '' ?> <?= $locked ? 'locked' : '' ?>">
      <?php if ($isEquipped): ?>
        <div style="font-size:10px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">&#9654; Equipped</div>
      <?php endif; ?>
      <?php if ((int)($g['loan_id'] ?? 0) > 0): ?>
        <div style="font-size:10px;font-weight:700;color:#e8a33d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">&#9874; Guild Loan</div>
      <?php endif; ?>
      <?php if ($locked): ?>
        <div style="font-size:10px;font-weight:700;color:var(--neon2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">&#128274; Requires Level <?= $reqLevel ?></div>
      <?php endif; ?>
      <div style="font-weight:700;font-size:13px;color:var(--text);margin-bottom:3px"><?= e($g['name']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:8px"><?= $g['gear_type'] === 'weapon' ? 'Weapon' : 'Armor' ?><?= $tier ? ' &middot; '.e($tier['label']) : '' ?></div>
      <?php if ($g['atk_bonus'] > 0): ?>
        <span class="wc-chip" style="background:rgba(255,45,149,.1);border:1px solid rgba(255,45,149,.3);color:var(--neon2);font-weight:700">+<?= $g['atk_bonus'] ?> ATK</span>
      <?php endif; ?>
      <?php if ($g['def_bonus'] > 0): ?>
        <span class="wc-chip" style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);color:var(--accent);font-weight:700">+<?= $g['def_bonus'] ?> DEF</span>
      <?php endif; ?>
      <div style="display:flex;gap:6px;margin-top:10px">
        <?php if (!$isEquipped && !$locked): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="equip">
            <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-sm btn-primary" type="submit">Equip</button>
          </form>
        <?php elseif ($isEquipped): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="unequip">
            <input type="hidden" name="slot" value="<?= e($g['gear_type']) ?>">
            <button class="btn btn-sm btn-ghost" type="submit">Unequip</button>
          </form>
        <?php else: ?>
          <button class="btn btn-sm btn-ghost" type="button" disabled title="Requires Level <?= $reqLevel ?>">Locked</button>
        <?php endif; ?>
        <?php if (!$isEquipped && (int)($g['loan_id'] ?? 0) === 0): ?>
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

<script>
(function(){
'use strict';
var RECIPES=<?= $recipesJson ?>, TIERS=<?= $tiersJson ?>, ORE_NAMES=<?= $oreNamesJson ?>;
var ore=<?= $oreJson ?>;
var muted=localStorage.getItem('wcMuted')==='1';
var ac=null;
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

// ── Fabrication Bay canvas: idle sparks + build sequence ──
var canvas=document.getElementById('wc-bay-canvas');
var building=false, buildT0=0, BUILD_MS=1300;
if(canvas){
  var ctx=canvas.getContext('2d');
  var W=360,H=92,dpr=Math.min(2,window.devicePixelRatio||1);
  canvas.width=W*dpr; canvas.height=H*dpr; ctx.scale(dpr,dpr);
  var sparks=[];
  for(var i=0;i<14;i++) sparks.push({x:Math.random()*W,y:Math.random()*H,v:.15+Math.random()*.35,p:Math.random()*9});
  var burst=[];
  function loop(t){
    requestAnimationFrame(loop);
    ctx.clearRect(0,0,W,H);
    var bg=ctx.createLinearGradient(0,0,0,H);
    bg.addColorStop(0,'#0a0a14'); bg.addColorStop(1,'#12121f');
    ctx.fillStyle=bg; ctx.fillRect(0,0,W,H);
    // ambient drifting sparks
    sparks.forEach(function(s){
      s.y-=s.v; if(s.y<-3){s.y=H+3;s.x=Math.random()*W;}
      var a=.15+.15*Math.sin(t/700+s.p);
      ctx.fillStyle='rgba(25,240,199,'+a+')';
      ctx.fillRect(s.x,s.y,1.6,1.6);
    });
    // build progress
    if(building){
      var p=Math.min(1,(t-buildT0)/BUILD_MS);
      ctx.fillStyle='rgba(25,240,199,.12)';
      ctx.fillRect(0,H-10,W*p,10);
      ctx.fillStyle='rgba(25,240,199,.7)';
      ctx.fillRect(0,H-3,W*p,3);
      if(Math.random()<.5){
        burst.push({x:W*p+(Math.random()-.5)*10,y:H-14+(Math.random()-.5)*10,vx:(Math.random()-.5)*1.5,vy:-Math.random()*1.8,life:1});
      }
      if(p>=1) building=false;
    }
    burst.forEach(function(b){ b.x+=b.vx; b.y+=b.vy; b.life-=.03; });
    burst=burst.filter(function(b){ return b.life>0; });
    burst.forEach(function(b){
      ctx.fillStyle='rgba(255,255,255,'+Math.max(0,b.life)+')';
      ctx.fillRect(b.x,b.y,2,2);
    });
  }
  requestAnimationFrame(loop);
}

// ── Blueprint selection ──
var selId=null;
var cards=document.querySelectorAll('.wc-card');
var bayEmpty=document.getElementById('wc-bay-empty'), baySel=document.getElementById('wc-bay-selected');
var bayIcon=document.getElementById('wc-bay-icon'), bayName=document.getElementById('wc-bay-name'),
    bayTier=document.getElementById('wc-bay-tier'), bayDesc=document.getElementById('wc-bay-desc'),
    bayStats=document.getElementById('wc-bay-stats'), bayCost=document.getElementById('wc-bay-cost'),
    bayBtn=document.getElementById('wc-bay-btn'), bayMsg=document.getElementById('wc-bay-msg');

function findRecipe(id){ for(var i=0;i<RECIPES.length;i++) if(RECIPES[i].id===id) return RECIPES[i]; return null; }
function canAfford(cost){ for(var k in cost){ if((ore[k]||0)<cost[k]) return false; } return true; }

function selectCard(id){
  selId=id;
  cards.forEach(function(c){ c.classList.toggle('sel', c.dataset.id===id); });
  var r=findRecipe(id); if(!r||!bayBtn) return;
  var tier=TIERS[r.tier];
  bayEmpty.style.display='none'; baySel.style.display='block';
  bayIcon.innerHTML=r.icon; bayIcon.style.color=tier.col;
  bayName.textContent=r.name; bayName.style.color=tier.col;
  bayTier.textContent=tier.label+' · Lv'+tier.level_req+'+ to equip';
  bayDesc.textContent=r.desc;
  var statsHtml='';
  if(r.atk>0) statsHtml+='<span class="wc-chip" style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.2);color:var(--neon2);font-weight:700">+'+r.atk+' ATK</span>';
  if(r.def>0) statsHtml+='<span class="wc-chip" style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.2);color:var(--accent);font-weight:700">+'+r.def+' DEF</span>';
  bayStats.innerHTML=statsHtml;
  var costHtml='';
  for(var k in r.cost){
    var ok=(ore[k]||0)>=r.cost[k];
    var on=ORE_NAMES[k]||[k,''];
    costHtml+='<span class="wc-chip" style="background:'+(ok?'rgba(25,240,199,.07)':'rgba(255,45,149,.07)')+';border:1px solid '+(ok?'rgba(25,240,199,.2)':'rgba(255,45,149,.3)')+';color:'+(ok?'var(--text)':'var(--neon2)')+'">'+on[1]+' '+r.cost[k]+'&times; '+on[0]+'</span>';
  }
  bayCost.innerHTML=costHtml;
  var afford=canAfford(r.cost);
  bayBtn.disabled=!afford;
  bayBtn.textContent=afford?'⚔ Begin Fabrication':'Need more ore';
  bayMsg.textContent='';
}

cards.forEach(function(c){
  c.addEventListener('click',function(){ selectCard(c.dataset.id); });
});

function syncOreDom(){
  document.querySelectorAll('.wc-ore-box').forEach(function(box){
    var k=box.dataset.ore, q=ore[k]||0;
    var qe=box.querySelector('.wc-ore-qty');
    if(qe) qe.textContent='×'+q.toLocaleString('en-US');
    box.style.display=q>0?'':'none';
  });
  cards.forEach(function(c){
    var r=findRecipe(c.dataset.id);
    if(!r) return;
    c.classList.toggle('broke', !canAfford(r.cost));
  });
}

if(bayBtn){
  bayBtn.addEventListener('click',function(){
    if(!selId || bayBtn.disabled) return;
    var r=findRecipe(selId); if(!r) return;
    bayBtn.disabled=true; bayBtn.textContent='Fabricating…';
    building=true; buildT0=performance.now();
    sfx(220,.12,'square',.04); sfx(340,.1,'square',.03);
    setTimeout(function(){
      var fd=new FormData();
      fd.append('wc_ajax','1'); fd.append('wc_action','craft'); fd.append('recipe_id',selId);
      fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(res){return res.json();})
        .then(function(d){
          if(!d.ok){
            bayMsg.textContent=d.err||'Error'; bayMsg.style.color='var(--neon2)';
            bayBtn.disabled=false; bayBtn.textContent='⚔ Begin Fabrication';
            sfx(120,.15,'square',.04);
            return;
          }
          ore=d.ore; syncOreDom();
          bayMsg.textContent=d.msg; bayMsg.style.color='#3bcf63';
          sfx(520,.1,'square',.045); setTimeout(function(){sfx(760,.14,'square',.045);},90); setTimeout(function(){sfx(980,.16,'square',.04);},180);
          bayIcon.classList.remove('wc-pop'); void bayIcon.offsetWidth; bayIcon.classList.add('wc-pop');
          var afford=canAfford(r.cost);
          bayBtn.disabled=!afford;
          bayBtn.textContent=afford?'⚔ Begin Fabrication':'Need more ore';
        })
        .catch(function(){
          bayMsg.textContent='Network error'; bayMsg.style.color='var(--neon2)';
          bayBtn.disabled=false; bayBtn.textContent='⚔ Begin Fabrication';
        });
    }, BUILD_MS);
  });
}
})();
</script>
