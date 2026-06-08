<?php /* pages/bazaar.php — The Bazaar: player-driven marketplace (true escrow) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

/* ---------- action handling (inline, no redirect — matches ledger/datacore) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'list') {
      $item_id = (int)($_POST['item_id']    ?? 0);
      $qty     = (int)($_POST['qty']        ?? 0);
      $price   = (int)($_POST['unit_price'] ?? 0);

      if ($item_id <= 0 || $qty <= 0)  throw new RuntimeException('Pick an item and a quantity.');
      if ($price <= 0)                 throw new RuntimeException('Set a price above zero.');
      if ($price > 1000000000000)      throw new RuntimeException('That price is delusional.');

      $pdo->beginTransaction();
      // Pull from inventory into escrow — conditional UPDATE blocks overselling.
      $pull = $pdo->prepare('UPDATE player_items SET qty = qty - ?
                             WHERE player_id = ? AND item_id = ? AND qty >= ?');
      $pull->execute([$qty, $pid, $item_id, $qty]);
      if ($pull->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("You don't have that many to list."); }

      $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND item_id = ? AND qty = 0')
          ->execute([$pid, $item_id]);
      $pdo->prepare('INSERT INTO market_listings (seller_id, item_id, qty, unit_price)
                     VALUES (?,?,?,?)')->execute([$pid, $item_id, $qty, $price]);
      $pdo->commit();
      $msg = "Listed {$qty} unit(s) at " . number_format($price) . " creds each.";
    }

    elseif ($action === 'buy') {
      $listing_id = (int)($_POST['listing_id'] ?? 0);

      $pdo->beginTransaction();
      $L = $pdo->prepare('SELECT * FROM market_listings WHERE id = ? FOR UPDATE');
      $L->execute([$listing_id]);
      $listing = $L->fetch();
      if (!$listing)                    { $pdo->rollBack(); throw new RuntimeException('That listing is gone.'); }
      if ($listing['seller_id'] == $pid){ $pdo->rollBack(); throw new RuntimeException("That's your own listing — cancel it instead."); }

      // Quantity wanted: explicit qty, or everything if the "All" button was used. Clamp to stock.
      $want = isset($_POST['all']) ? (int)$listing['qty'] : (int)($_POST['qty'] ?? 0);
      if ($want <= 0 || $want > $listing['qty']) $want = (int)$listing['qty'];
      $total = $want * (int)$listing['unit_price'];

      // Charge buyer from pocket — conditional UPDATE blocks overspend.
      $pay = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ?
                            WHERE id = ? AND creds_pocket >= ?');
      $pay->execute([$total, $pid, $total]);
      if ($pay->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket. Pull some from the Iron Ledger.'); }

      // Pay the seller.
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')
          ->execute([$total, $listing['seller_id']]);

      // Deliver goods to buyer.
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
          ->execute([$pid, $listing['item_id'], $want]);

      // Shrink or close the listing.
      if ($want >= (int)$listing['qty']) {
        $pdo->prepare('DELETE FROM market_listings WHERE id = ?')->execute([$listing_id]);
      } else {
        $pdo->prepare('UPDATE market_listings SET qty = qty - ? WHERE id = ?')
            ->execute([$want, $listing_id]);
      }

      // Log the sale for market-average pricing.
      $pdo->prepare('INSERT INTO market_sales (item_id, qty, unit_price, seller_id, buyer_id)
                     VALUES (?,?,?,?,?)')
          ->execute([$listing['item_id'], $want, $listing['unit_price'], $listing['seller_id'], $pid]);

      $pdo->commit();
      $msg = "Bought {$want} unit(s) for " . number_format($total) . " creds.";
    }

    elseif ($action === 'cancel') {
      $listing_id = (int)($_POST['listing_id'] ?? 0);

      $pdo->beginTransaction();
      $L = $pdo->prepare('SELECT * FROM market_listings WHERE id = ? AND seller_id = ? FOR UPDATE');
      $L->execute([$listing_id, $pid]);
      $listing = $L->fetch();
      if (!$listing) { $pdo->rollBack(); throw new RuntimeException('No such listing of yours.'); }

      // Return escrowed goods to the seller's stash.
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')
          ->execute([$pid, $listing['item_id'], $listing['qty']]);
      $pdo->prepare('DELETE FROM market_listings WHERE id = ?')->execute([$listing_id]);
      $pdo->commit();
      $msg = "Pulled your listing — {$listing['qty']} unit(s) back in your stash.";
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
  $player = current_player(); // refresh creds for this render
}

/* ---------- sort whitelist (never interpolate user input into SQL) ---------- */
$sorts = [
  'item_asc'   => 'i.name ASC',
  'price_asc'  => 'l.unit_price ASC',
  'price_desc' => 'l.unit_price DESC',
  'qty_desc'   => 'l.qty DESC',
  'new'        => 'l.created_at DESC',
];
$sort  = $_GET['sort'] ?? 'price_asc';
if (!isset($sorts[$sort])) $sort = 'price_asc';
$order = $sorts[$sort];

/* ---------- data for rendering ---------- */
$listings = $pdo->query(
  'SELECT l.id, l.qty, l.unit_price, l.seller_id,
          i.id AS item_id, i.name AS item_name,
          p.username AS seller,
          (SELECT ROUND(AVG(unit_price)) FROM market_sales s WHERE s.item_id = i.id) AS avg_sale
   FROM market_listings l
   JOIN items   i ON i.id = l.item_id
   JOIN players p ON p.id = l.seller_id
   ORDER BY ' . $order
)->fetchAll();

$mine = $pdo->prepare(
  'SELECT l.id, l.qty, l.unit_price, i.name AS item_name
   FROM market_listings l JOIN items i ON i.id = l.item_id
   WHERE l.seller_id = ? ORDER BY l.created_at DESC');
$mine->execute([$pid]);
$mine = $mine->fetchAll();

$inv = $pdo->prepare(
  'SELECT pi.item_id, pi.qty, i.name
   FROM player_items pi JOIN items i ON i.id = pi.item_id
   WHERE pi.player_id = ? AND pi.qty > 0 ORDER BY i.name');
$inv->execute([$pid]);
$inv = $inv->fetchAll();

function sort_link($key, $label, $cur) {
  $mark = ($cur === $key) ? ' &#9662;' : '';
  return '<a href="index.php?p=bazaar&sort=' . $key . '">' . e($label) . $mark . '</a>';
}
?>
<div class="panel">
  <h2>The Bazaar &mdash; Open Market</h2>
  <p class="muted">No refunds, no questions. The Grid takes its cut in silence.</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Pocket: <b><?= number_format($player['creds_pocket']) ?></b> creds</p>
</div>

<div class="panel">
  <h3>List an Item</h3>
  <?php if ($inv): ?>
  <form method="post">
    <input type="hidden" name="action" value="list">
    <p><label>Item</label>
      <select name="item_id" style="width:100%;background:#080812;border:1px solid var(--line);color:var(--text);padding:6px;border-radius:3px">
        <?php foreach ($inv as $it): ?>
          <option value="<?= (int)$it['item_id'] ?>"><?= e($it['name']) ?> (have <?= (int)$it['qty'] ?>)</option>
        <?php endforeach; ?>
      </select></p>
    <p><label>Quantity</label><input type="number" name="qty" min="1" value="1"></p>
    <p><label>Price per unit (creds)</label><input type="number" name="unit_price" min="1" value="100"></p>
    <p><button type="submit">List for Sale</button></p>
  </form>
  <?php else: ?>
    <p class="muted">Your stash is empty &mdash; nothing to sell. Go scavenge or craft something first.</p>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Market</h3>
  <p class="muted">Sort:
    <?= sort_link('item_asc',   'Item',     $sort) ?> &middot;
    <?= sort_link('price_asc',  'Price low',$sort) ?> &middot;
    <?= sort_link('price_desc', 'Price high',$sort) ?> &middot;
    <?= sort_link('qty_desc',   'Qty',      $sort) ?> &middot;
    <?= sort_link('new',        'Newest',   $sort) ?>
  </p>
  <?php if ($listings): ?>
  <table>
    <tr><th>Item</th><th>Qty</th><th>Unit</th><th>Mkt Avg</th><th>Seller</th><th>Total</th><th></th></tr>
    <?php foreach ($listings as $l): ?>
    <tr>
      <td><?= e($l['item_name']) ?></td>
      <td><?= (int)$l['qty'] ?></td>
      <td><?= number_format($l['unit_price']) ?></td>
      <td class="muted"><?= $l['avg_sale'] !== null ? number_format($l['avg_sale']) : '&mdash;' ?></td>
      <td><?= e($l['seller']) ?></td>
      <td><?= number_format($l['qty'] * $l['unit_price']) ?></td>
      <td>
        <?php if ($l['seller_id'] == $pid): ?>
          <span class="muted">yours</span>
        <?php else: ?>
          <form method="post" style="display:flex;gap:4px;align-items:center;margin:0">
            <input type="hidden" name="action" value="buy">
            <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
            <input type="number" name="qty" min="1" max="<?= (int)$l['qty'] ?>" value="1" style="width:64px">
            <button type="submit">Buy</button>
            <button type="submit" name="all" value="1" title="Buy the whole stack">All</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
    <p class="muted">The market is dead quiet. Be the first to list something.</p>
  <?php endif; ?>
</div>

<?php if ($mine): ?>
<div class="panel">
  <h3>Your Listings <span class="muted">(held in escrow)</span></h3>
  <table>
    <tr><th>Item</th><th>Qty</th><th>Unit</th><th></th></tr>
    <?php foreach ($mine as $l): ?>
    <tr>
      <td><?= e($l['item_name']) ?></td>
      <td><?= (int)$l['qty'] ?></td>
      <td><?= number_format($l['unit_price']) ?></td>
      <td>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
          <button type="submit">Cancel</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
