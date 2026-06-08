<?php /* pages/stash.php — your inventory (items NOT in Bazaar escrow) */
$pid = $_SESSION['pid'];
$inv = db()->prepare(
  'SELECT i.name, i.category, i.tier, pi.qty
   FROM player_items pi JOIN items i ON i.id = pi.item_id
   WHERE pi.player_id = ? AND pi.qty > 0
   ORDER BY i.category, i.name');
$inv->execute([$pid]);
$inv = $inv->fetchAll();
?>
<div class="panel">
  <h2>Stash</h2>
  <p class="muted">Everything you're carrying. Anything listed on the Bazaar is in escrow, not here.</p>
  <?php if ($inv): ?>
  <table>
    <tr><th>Item</th><th>Category</th><th>Tier</th><th>Qty</th></tr>
    <?php foreach ($inv as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td>
      <td class="muted"><?= e($r['category']) ?></td>
      <td><?= (int)$r['tier'] ?></td>
      <td><?= (int)$r['qty'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">Empty. The Sprawl gave you nothing and you kept all of it.</p>
  <?php endif; ?>
</div>
