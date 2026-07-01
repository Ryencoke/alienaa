<?php /* pages/stash.php — inventory (cards + tabs) + equipment loadout */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {
    if ($a === 'equip') {
      // Gear can come from two places: the catalog (player_items, bought at
      // General Store/Bazaar) or forged/crafted gear (player_gear, one row
      // per unit — Blacksmith and the Fabrication Lab both write here). The
      // form tells us which table this id belongs to.
      $src = ($_POST['source'] ?? 'items') === 'gear' ? 'gear' : 'items';
      $iid = (int)($_POST['item_id'] ?? 0);
      if ($src === 'gear') {
        $q = $pdo->prepare('SELECT gear_type FROM player_gear WHERE id=? AND player_id=?');
        $q->execute([$iid, $pid]);
        $slot = $q->fetchColumn();
      } else {
        $q = $pdo->prepare('SELECT i.slot FROM items i JOIN player_items pi ON pi.item_id = i.id AND pi.player_id = ?
                            WHERE i.id = ? AND pi.qty > 0');
        $q->execute([$pid, $iid]);
        $slot = $q->fetchColumn();
      }
      if ($slot !== 'weapon' && $slot !== 'armor') throw new RuntimeException("You can't equip that.");
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
          ->execute(["equipped_{$slot}:{$pid}", $iid]);
      $msg = 'Equipped.';
    } elseif ($a === 'unequip') {
      $slot = $_POST['slot'] ?? '';
      if (in_array($slot, ['weapon','armor'], true)) {
        $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["equipped_{$slot}:{$pid}"]);
        $msg = 'Unequipped.';
      }
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
  $player = current_player();
}

if (!function_exists('item_icon')) {
  function item_icon($cat, $slot) {
    if ($slot === 'weapon') return '&#9876;';
    if ($slot === 'armor')  return '&#128737;';
    $m = ['raw'=>'&#128296;','component'=>'&#128295;','chem'=>'&#128137;','data'=>'&#128190;','gear'=>'&#9881;','misc'=>'&#128230;'];
    return $m[$cat] ?? '&#128230;';
  }
}
function equipped_item($pdo, $id) {
  if (!$id) return null;
  $q = $pdo->prepare('SELECT id, name, atk, def FROM items WHERE id = ?');
  $q->execute([$id]); return $q->fetch() ?: null;
}
// Equipped gear — check player_gear first, fall back to player_items. $ewSrc/
// $eaSrc record which table it actually resolved from, since a player_gear
// row and a catalog item can share the same numeric id — needed below to
// tell the two apart when marking a Backpack card "Equipped".
$ew = $ea = null; $ewSrc = $eaSrc = null; $gearAtk = $gearDef = 0;
try {
  $gq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $gq->execute(["equipped_weapon:{$pid}"]); $wid = (int)$gq->fetchColumn();
  if ($wid > 0) {
    $gq2 = $pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def,loan_id FROM player_gear WHERE id=? AND player_id=?');
    $gq2->execute([$wid,$pid]); $ew = $gq2->fetch() ?: null;
    if ($ew) $ewSrc = 'gear';
    if (!$ew) { $gq3 = $pdo->prepare('SELECT i.id,i.name,i.atk,0 AS def,0 AS loan_id FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0'); $gq3->execute([$pid,$wid]); $ew = $gq3->fetch() ?: null; if ($ew) $ewSrc = 'items'; }
    $gearAtk = $ew ? (int)$ew['atk'] : 0;
  }
  $gq->execute(["equipped_armor:{$pid}"]); $aid = (int)$gq->fetchColumn();
  if ($aid > 0) {
    $gq2 = $pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def,loan_id FROM player_gear WHERE id=? AND player_id=?');
    $gq2->execute([$aid,$pid]); $ea = $gq2->fetch() ?: null;
    if ($ea) $eaSrc = 'gear';
    if (!$ea) { $gq3 = $pdo->prepare('SELECT i.id,i.name,0 AS atk,i.def,0 AS loan_id FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0'); $gq3->execute([$pid,$aid]); $ea = $gq3->fetch() ?: null; if ($ea) $eaSrc = 'items'; }
    $gearDef = $ea ? (int)$ea['def'] : 0;
  }
} catch (Throwable $e) {}

$inv = $pdo->prepare(
  'SELECT i.id, i.name, i.category, i.tier, i.slot, i.atk, i.def, i.descr, pi.qty
   FROM player_items pi JOIN items i ON i.id = pi.item_id
   WHERE pi.player_id = ? AND pi.qty > 0
   ORDER BY i.category, i.name');
$inv->execute([$pid]);
$inv = $inv->fetchAll();
foreach ($inv as &$r) { $r['source'] = 'items'; $r['loan_id'] = 0; }
unset($r);

// Forged/crafted gear (Blacksmith + Fabrication Lab) — lives in its own
// table (one row per unit, so it can carry per-item bonuses and guild loan
// tracking that the catalog-based player_items can't represent) but belongs
// in the same Backpack list so it's equippable from here too, not just from
// whichever page happened to craft it.
try {
  $gq = $pdo->prepare('SELECT id, name, gear_type, atk_bonus, def_bonus, loan_id FROM player_gear WHERE player_id=? ORDER BY created_at DESC');
  $gq->execute([$pid]);
  foreach ($gq as $g) {
    $inv[] = [
      'id' => (int)$g['id'], 'name' => $g['name'], 'category' => 'gear', 'tier' => null,
      'slot' => $g['gear_type'], 'atk' => (int)$g['atk_bonus'], 'def' => (int)$g['def_bonus'],
      'descr' => '', 'qty' => 1, 'source' => 'gear', 'loan_id' => (int)$g['loan_id'],
    ];
  }
} catch (Throwable $e) {}

$cats = []; foreach ($inv as $r) $cats[$r['category']] = true; $cats = array_keys($cats);
?>
<?= scene_header('st-canvas', '&#9876;', 'Inventory',
      "Everything you're carrying. Listed items are in Bazaar escrow.", 'shelf', '#19f0c7',
      '<span style="font-size:11px;color:var(--muted)"><b style="font-family:\'Orbitron\',sans-serif;color:var(--accent)">' . count($inv) . '</b> ITEM TYPE' . (count($inv) !== 1 ? 'S' : '') . '</span>') ?>
<?= scene_header_js() ?>
<?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>

<div class="panel">
  <h3 style="margin-bottom:12px">&#9876; Active Loadout</h3>
  <?php if ($ew || $ea): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <?php foreach ([['weapon','&#9876;',$ew,$gearAtk,'ATK','rgba(255,45,149,.08)','rgba(255,45,149,.25)'],
                   ['armor','&#128737;',$ea,$gearDef,'DEF','rgba(25,240,199,.06)','rgba(25,240,199,.2)']] as [$slot,$icon,$it,$bonus,$stat,$bg,$bord]):
      if (!$it) continue; ?>
    <div style="flex:1;min-width:180px;background:<?= $bg ?>;border:1px solid <?= $bord ?>;border-radius:7px;padding:10px 13px;display:flex;align-items:center;gap:10px">
      <span style="font-size:22px"><?= $icon ?></span>
      <div style="flex:1">
        <div style="font-weight:700;font-size:13px"><?= e($it['name']) ?><?php if ((int)($it['loan_id'] ?? 0) > 0): ?> <span style="font-size:9px;font-weight:700;color:#e8a33d;text-transform:uppercase;letter-spacing:.4px;border:1px solid rgba(232,163,61,.4);border-radius:4px;padding:1px 5px;vertical-align:middle">&#9874; Guild Loan</span><?php endif; ?></div>
        <div style="font-size:11px;color:var(--muted)">+<?= $bonus ?> <?= $stat ?></div>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="unequip">
        <input type="hidden" name="slot" value="<?= $slot ?>">
        <button class="btn btn-sm btn-ghost" type="submit" style="font-size:10px;padding:3px 8px">Unequip</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="font-size:12px;color:var(--muted)"><span style="color:var(--neon2)">+<?= $gearAtk ?> ATK</span> &nbsp; <span style="color:var(--accent)">+<?= $gearDef ?> DEF</span> &mdash; total gear bonus</div>
  <?php else: ?>
  <p class="muted" style="font-size:13px">No gear equipped.</p>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Backpack</h3>
  <?php if ($inv): ?>
  <div class="tabs">
    <div class="tab active" data-cat="all">All</div>
    <?php foreach ($cats as $c): ?><div class="tab" data-cat="<?= e($c) ?>"><?= e(ucfirst($c)) ?></div><?php endforeach; ?>
  </div>
  <div id="invlist">
    <?php foreach ($inv as $r):
      $isEq = (($r['slot'] === 'weapon' && $ew && $ew['id'] == $r['id'] && $ewSrc === $r['source'])
            || ($r['slot'] === 'armor' && $ea && $ea['id'] == $r['id'] && $eaSrc === $r['source'])); ?>
    <div class="itemcard" data-cat="<?= e($r['category']) ?>">
      <div class="ic"><?= item_icon($r['category'], $r['slot']) ?></div>
      <div class="body">
        <div class="nm"><?= e($r['name']) ?><?php if ($r['slot']): ?> <span class="muted">(<?= ucfirst($r['slot']) ?>)</span><?php endif; ?> <span class="muted">&times;<?= (int)$r['qty'] ?></span><?php if ((int)($r['loan_id'] ?? 0) > 0): ?> <span style="font-size:9px;font-weight:700;color:#e8a33d;text-transform:uppercase;letter-spacing:.4px;border:1px solid rgba(232,163,61,.4);border-radius:4px;padding:1px 5px;vertical-align:middle">&#9874; Guild Loan</span><?php endif; ?></div>
        <?php if ($r['descr']): ?><div class="muted" style="font-size:11px"><?= e($r['descr']) ?></div><?php endif; ?>
        <?php if ($r['slot'] === 'weapon'): ?><div class="st">+<?= (int)$r['atk'] ?> ATK</div>
        <?php elseif ($r['slot'] === 'armor'): ?><div class="st">+<?= (int)$r['def'] ?> DEF</div><?php endif; ?>
      </div>
      <div class="act">
        <?php if (in_array($r['slot'], ['weapon','armor'], true) && !$isEq): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="equip">
            <input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="source" value="<?= e($r['source']) ?>">
            <button class="btn btn-sm btn-primary" type="submit">Equip</button>
          </form>
        <?php elseif ($isEq): ?>
          <span style="font-size:11px;color:var(--accent)">Equipped</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <script>
  (function(){
    var bar=document.querySelector('.tabs'); if(!bar) return;
    var list=document.getElementById('invlist');
    bar.addEventListener('click',function(e){
      var t=e.target.closest('.tab'); if(!t) return;
      bar.querySelectorAll('.tab').forEach(function(x){x.classList.remove('active');});
      t.classList.add('active');
      var cat=t.getAttribute('data-cat');
      list.querySelectorAll('.itemcard').forEach(function(c){
        c.style.display=(cat==='all'||c.getAttribute('data-cat')===cat)?'':'none';
      });
    });
  })();
  </script>
  <?php else: ?><p class="muted">Empty. The Sprawl gave you nothing and you kept all of it.</p><?php endif; ?>
</div>
