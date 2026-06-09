<?php /* pages/stash.php — inventory (cards + tabs) + equipment loadout */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {
    if ($a === 'equip') {
      $iid = (int)($_POST['item_id'] ?? 0);
      $q = $pdo->prepare('SELECT i.slot FROM items i JOIN player_items pi ON pi.item_id = i.id AND pi.player_id = ?
                          WHERE i.id = ? AND pi.qty > 0');
      $q->execute([$pid, $iid]);
      $slot = $q->fetchColumn();
      $col = $slot === 'weapon' ? 'equipped_weapon' : ($slot === 'armor' ? 'equipped_armor' : null);
      if (!$col) throw new RuntimeException("You can't equip that.");
      $pdo->prepare("UPDATE players SET {$col} = ? WHERE id = ?")->execute([$iid, $pid]);
      $msg = 'Equipped.';
    } elseif ($a === 'unequip') {
      $slot = $_POST['slot'] ?? '';
      $col = $slot === 'weapon' ? 'equipped_weapon' : ($slot === 'armor' ? 'equipped_armor' : null);
      if ($col) { $pdo->prepare("UPDATE players SET {$col} = NULL WHERE id = ?")->execute([$pid]); $msg = 'Unequipped.'; }
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
$ew = equipped_item($pdo, $player['equipped_weapon'] ?? null);
$ea = equipped_item($pdo, $player['equipped_armor'] ?? null);
$gearAtk = $ew ? (int)$ew['atk'] : 0;
$gearDef = $ea ? (int)$ea['def'] : 0;

$inv = $pdo->prepare(
  'SELECT i.id, i.name, i.category, i.tier, i.slot, i.atk, i.def, i.descr, pi.qty
   FROM player_items pi JOIN items i ON i.id = pi.item_id
   WHERE pi.player_id = ? AND pi.qty > 0
   ORDER BY i.category, i.name');
$inv->execute([$pid]);
$inv = $inv->fetchAll();
$cats = []; foreach ($inv as $r) $cats[$r['category']] = true; $cats = array_keys($cats);
?>
<div class="panel">
  <h2>&#9876; Inventory</h2>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p class="muted">Everything you're carrying. Listed items are in Bazaar escrow.</p>
</div>

<div class="panel">
  <h3>Equipment Bonuses</h3>
  <p style="font-size:14px"><span style="color:var(--neon2)">&#9876; +<?= $gearAtk ?> ATK</span>
     &nbsp;&nbsp; <span style="color:var(--accent)">&#128737; +<?= $gearDef ?> DEF</span></p>
</div>

<div class="panel">
  <h3>Equipped</h3>
  <?php if ($ew || $ea): foreach ([['weapon',$ew],['armor',$ea]] as $pair): [$sn,$it] = $pair; if (!$it) continue; ?>
    <div class="itemcard">
      <div class="ic"><?= $sn === 'weapon' ? '&#9876;' : '&#128737;' ?></div>
      <div class="body"><div class="nm"><?= e($it['name']) ?> <span class="muted">(<?= ucfirst($sn) ?>)</span></div>
        <div class="st"><?= $sn === 'weapon' ? '+'.(int)$it['atk'].' ATK' : '+'.(int)$it['def'].' DEF' ?></div></div>
      <div class="act"><form method="post" style="margin:0"><input type="hidden" name="action" value="unequip"><input type="hidden" name="slot" value="<?= $sn ?>"><button>Unequip</button></form></div>
    </div>
  <?php endforeach; else: ?><p class="muted">Nothing equipped.</p><?php endif; ?>
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
      $isEq = (($r['slot'] === 'weapon' && $ew && $ew['id'] == $r['id']) || ($r['slot'] === 'armor' && $ea && $ea['id'] == $r['id'])); ?>
    <div class="itemcard" data-cat="<?= e($r['category']) ?>">
      <div class="ic"><?= item_icon($r['category'], $r['slot']) ?></div>
      <div class="body">
        <div class="nm"><?= e($r['name']) ?><?php if ($r['slot']): ?> <span class="muted">(<?= ucfirst($r['slot']) ?>)</span><?php endif; ?> <span class="muted">&times;<?= (int)$r['qty'] ?></span></div>
        <?php if ($r['descr']): ?><div class="muted" style="font-size:11px"><?= e($r['descr']) ?></div><?php endif; ?>
        <?php if ($r['slot'] === 'weapon'): ?><div class="st">+<?= (int)$r['atk'] ?> ATK</div>
        <?php elseif ($r['slot'] === 'armor'): ?><div class="st">+<?= (int)$r['def'] ?> DEF</div><?php endif; ?>
      </div>
      <div class="act">
        <?php if ($r['slot'] !== ''): if ($isEq): ?><span class="muted">equipped</span>
          <?php else: ?><form method="post" style="margin:0"><input type="hidden" name="action" value="equip"><input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>"><button>Equip</button></form><?php endif; endif; ?>
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
