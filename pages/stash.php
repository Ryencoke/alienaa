<?php /* pages/stash.php — inventory + equipment loadout */
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

function equipped_item($pdo, $id) {
  if (!$id) return null;
  $q = $pdo->prepare('SELECT id, name, atk, def FROM items WHERE id = ?');
  $q->execute([$id]); return $q->fetch() ?: null;
}
$ew = equipped_item($pdo, $player['equipped_weapon'] ?? null);
$ea = equipped_item($pdo, $player['equipped_armor'] ?? null);

$inv = $pdo->prepare(
  'SELECT i.id, i.name, i.category, i.tier, i.slot, i.atk, i.def, pi.qty
   FROM player_items pi JOIN items i ON i.id = pi.item_id
   WHERE pi.player_id = ? AND pi.qty > 0
   ORDER BY i.category, i.name');
$inv->execute([$pid]);
$inv = $inv->fetchAll();
?>
<div class="panel">
  <h2>Stash</h2>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p class="muted">Everything you're carrying. Anything listed on the Bazaar is in escrow, not here.</p>

  <h3>Loadout</h3>
  <table>
    <tr><th>Slot</th><th>Equipped</th><th>Bonus</th><th></th></tr>
    <tr>
      <td>Weapon</td>
      <td><?= $ew ? e($ew['name']) : '<span class="muted">&mdash;</span>' ?></td>
      <td class="muted"><?= $ew ? '+' . (int)$ew['atk'] . ' ATK' : '' ?></td>
      <td><?php if ($ew): ?><form method="post" style="margin:0"><input type="hidden" name="action" value="unequip"><input type="hidden" name="slot" value="weapon"><button>Unequip</button></form><?php endif; ?></td>
    </tr>
    <tr>
      <td>Armor</td>
      <td><?= $ea ? e($ea['name']) : '<span class="muted">&mdash;</span>' ?></td>
      <td class="muted"><?= $ea ? '+' . (int)$ea['def'] . ' DEF' : '' ?></td>
      <td><?php if ($ea): ?><form method="post" style="margin:0"><input type="hidden" name="action" value="unequip"><input type="hidden" name="slot" value="armor"><button>Unequip</button></form><?php endif; ?></td>
    </tr>
  </table>
</div>

<div class="panel">
  <h3>Inventory</h3>
  <?php if ($inv): ?>
  <table>
    <tr><th>Item</th><th>Category</th><th>Tier</th><th>Qty</th><th></th></tr>
    <?php foreach ($inv as $r):
      $isEquipped = (($r['slot'] === 'weapon' && $ew && $ew['id'] == $r['id'])
                  || ($r['slot'] === 'armor'  && $ea && $ea['id'] == $r['id'])); ?>
    <tr>
      <td><?= e($r['name']) ?><?php
        if ($r['slot'] === 'weapon') echo ' <span class="muted">(+'.(int)$r['atk'].' ATK)</span>';
        elseif ($r['slot'] === 'armor') echo ' <span class="muted">(+'.(int)$r['def'].' DEF)</span>'; ?></td>
      <td class="muted"><?= e($r['category']) ?></td>
      <td><?= (int)$r['tier'] ?></td>
      <td><?= (int)$r['qty'] ?></td>
      <td><?php if ($r['slot'] !== ''): if ($isEquipped): ?><span class="muted">equipped</span>
          <?php else: ?><form method="post" style="margin:0"><input type="hidden" name="action" value="equip"><input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>"><button>Equip</button></form><?php endif; endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">Empty. The Sprawl gave you nothing and you kept all of it.</p>
  <?php endif; ?>
</div>
