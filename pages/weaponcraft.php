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
// Moved to lib.php's weaponcraft_tiers() so library.php can label recipes too.
$TIERS = weaponcraft_tiers();

// Blueprints — moved to lib.php's weaponcraft_recipes() so library.php can
// also list them (previously only Blacksmith items showed up there; the
// whole Fabrication Lab catalog was invisible to it).
$RECIPES = weaponcraft_recipes();
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

// Fabrication skill — gates which tiers you can CRAFT (not just equip).
// "Based on study is what weapons/armor you can make": mirrors sim.php's
// Combat-skill tier gate ($TIER_COMBAT_REQ) point-for-point, so the two
// skill-gated systems in the game use the same investment curve.
$fq = $pdo->prepare("SELECT ps.points FROM player_skills ps
                     JOIN skills s ON s.id = ps.skill_id
                     WHERE s.code = 'fab' AND ps.player_id = ?");
$fq->execute([$pid]);
$fabSkill = (int)($fq->fetchColumn() ?: 0);
$TIER_FAB_REQ = [
  'scrap' => 0, 'copper' => 50, 'iron' => 150, 'titanium' => 300,
  'nanocarbon' => 450, 'quantum' => 650, 'void' => 850,
];

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
      $fabReq = $TIER_FAB_REQ[$recipe['tier']] ?? 0;
      if ($fabSkill < $fabReq) throw new RuntimeException('Requires '.$fabReq.' Fabrication skill to build this blueprint — study up at the Datacore.');
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

<!-- Ore Stockpile -->
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px">
    <h3 style="margin:0">&#128219; Stockpile</h3>
    <span class="wc-lvl-pill">&#9881; Fabrication <?= $fabSkill ?></span>
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
      $fabReq = $TIER_FAB_REQ[$r['tier']] ?? 0;
      $fabLocked = $fabSkill < $fabReq;
      $costParts = [];
      foreach ($r['cost'] as $ore => $need) { $ok = ($oreInv[$ore] ?? 0) >= $need; $costParts[] = "<span class='wc-chip' style='background:".($ok?'rgba(25,240,199,.07)':'rgba(255,45,149,.07)')."; border:1px solid ".($ok?'rgba(25,240,199,.2)':'rgba(255,45,149,.3)')."; color:".($ok?'var(--text)':'var(--neon2)')."'>".$ORE_NAMES[$ore][1]." ".$need."&times; ".$ORE_NAMES[$ore][0]."</span>"; }
    ?>
    <div class="wc-card <?= $affordable ? '' : 'broke' ?><?= $fabLocked ? ' broke' : '' ?>" data-id="<?= e($r['id']) ?>" data-fablocked="<?= $fabLocked ? '1' : '0' ?>" data-fabreq="<?= $fabReq ?>" style="--wc-col:<?= $tier['col'] ?>;--wc-glow:<?= $tier['col'] ?>33">
      <?php if ($fabLocked): ?><div style="position:absolute;top:6px;right:7px;font-size:9px;color:var(--neon2);font-weight:700">&#128274; FAB <?= $fabReq ?></div><?php endif; ?>
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
        <button id="wc-bay-btn" type="button" style="width:100%">Begin Fabrication</button>
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
var FAB_SKILL=<?= (int)$fabSkill ?>, TIER_FAB_REQ=<?= json_encode($TIER_FAB_REQ) ?>;
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
  var fabReq=TIER_FAB_REQ[r.tier]||0, fabLocked=FAB_SKILL<fabReq;
  bayBtn.disabled=!afford||fabLocked;
  bayBtn.textContent=fabLocked?('Requires Fabrication '+fabReq):(afford?'Begin Fabrication':'Need more ore');
  bayMsg.textContent=fabLocked?'Train Fabrication at the Datacore to unlock this blueprint.':'';
  bayMsg.style.color=fabLocked?'var(--neon2)':'';
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
            bayBtn.disabled=false; bayBtn.textContent='Begin Fabrication';
            sfx(120,.15,'square',.04);
            return;
          }
          ore=d.ore; syncOreDom();
          bayMsg.textContent=d.msg; bayMsg.style.color='#3bcf63';
          sfx(520,.1,'square',.045); setTimeout(function(){sfx(760,.14,'square',.045);},90); setTimeout(function(){sfx(980,.16,'square',.04);},180);
          bayIcon.classList.remove('wc-pop'); void bayIcon.offsetWidth; bayIcon.classList.add('wc-pop');
          var afford=canAfford(r.cost);
          bayBtn.disabled=!afford;
          bayBtn.textContent=afford?'Begin Fabrication':'Need more ore';
        })
        .catch(function(){
          bayMsg.textContent='Network error'; bayMsg.style.color='var(--neon2)';
          bayBtn.disabled=false; bayBtn.textContent='Begin Fabrication';
        });
    }, BUILD_MS);
  });
}
})();
</script>
