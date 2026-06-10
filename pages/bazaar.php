<?php /* pages/bazaar.php — The Bazaar: player-driven marketplace (tabs: Browse / Sell / My Listings) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'list') {
      $item_id = (int)($_POST['item_id']    ?? 0);
      $qty     = (int)($_POST['qty']        ?? 0);
      $price   = (int)($_POST['unit_price'] ?? 0);

      if ($item_id <= 0 || $qty <= 0) throw new RuntimeException('Pick an item and a quantity.');
      if ($price <= 0)                throw new RuntimeException('Set a price above zero.');
      if ($price > 1000000000000)     throw new RuntimeException('That price is delusional.');
      $fee = (int)ceil($qty * $price * 0.02);

      $pdo->beginTransaction();
      $pull = $pdo->prepare('UPDATE player_items SET qty = qty - ? WHERE player_id = ? AND item_id = ? AND qty >= ?');
      $pull->execute([$qty, $pid, $item_id, $qty]);
      if ($pull->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("You don't have that many to list."); }

      if ($fee > 0) {
        $cf = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
        $cf->execute([$fee, $pid, $fee]);
        if ($cf->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException("You can't cover the " . number_format($fee) . " creds listing fee."); }
      }

      $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND item_id = ? AND qty = 0')->execute([$pid, $item_id]);
      $pdo->prepare('INSERT INTO market_listings (seller_id, item_id, qty, unit_price) VALUES (?,?,?,?)')->execute([$pid, $item_id, $qty, $price]);
      $pdo->commit();
      $msg = "Listed {$qty} unit(s) at " . number_format($price) . " creds each. Fee: " . number_format($fee) . " creds.";
    }

    elseif ($action === 'buy') {
      $listing_id = (int)($_POST['listing_id'] ?? 0);
      $pdo->beginTransaction();
      $L = $pdo->prepare('SELECT * FROM market_listings WHERE id = ? FOR UPDATE');
      $L->execute([$listing_id]); $listing = $L->fetch();
      if (!$listing)                     { $pdo->rollBack(); throw new RuntimeException('That listing is gone.'); }
      if ($listing['seller_id'] == $pid) { $pdo->rollBack(); throw new RuntimeException("That's your own listing."); }

      $want = isset($_POST['all']) ? (int)$listing['qty'] : (int)($_POST['qty'] ?? 0);
      if ($want <= 0 || $want > $listing['qty']) $want = (int)$listing['qty'];
      $total = $want * (int)$listing['unit_price'];

      $pay = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $pay->execute([$total, $pid, $total]);
      if ($pay->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }

      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$total, $listing['seller_id']]);
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')->execute([$pid, $listing['item_id'], $want]);

      if ($want >= (int)$listing['qty']) {
        $pdo->prepare('DELETE FROM market_listings WHERE id = ?')->execute([$listing_id]);
      } else {
        $pdo->prepare('UPDATE market_listings SET qty = qty - ? WHERE id = ?')->execute([$want, $listing_id]);
      }
      try { $pdo->prepare('INSERT INTO market_sales (item_id, qty, unit_price, seller_id, buyer_id) VALUES (?,?,?,?,?)')->execute([$listing['item_id'], $want, $listing['unit_price'], $listing['seller_id'], $pid]); } catch (Throwable $e) {}
      $pdo->commit();
      $msg = "Bought {$want} unit(s) for " . number_format($total) . " creds.";
    }

    elseif ($action === 'cancel') {
      $listing_id = (int)($_POST['listing_id'] ?? 0);
      $pdo->beginTransaction();
      $L = $pdo->prepare('SELECT * FROM market_listings WHERE id = ? AND seller_id = ? FOR UPDATE');
      $L->execute([$listing_id, $pid]); $listing = $L->fetch();
      if (!$listing) { $pdo->rollBack(); throw new RuntimeException('No such listing of yours.'); }
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)')->execute([$pid, $listing['item_id'], $listing['qty']]);
      $pdo->prepare('DELETE FROM market_listings WHERE id = ?')->execute([$listing_id]);
      $pdo->commit();
      $msg = "Cancelled — {$listing['qty']} unit(s) returned to your stash.";
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgType = 'err';
  }
  $player = current_player();
}

$tab = $_GET['tab'] ?? 'browse';
if (!in_array($tab, ['browse','sell','listings'], true)) $tab = 'browse';

$sorts = ['item_asc'=>'i.name ASC','price_asc'=>'l.unit_price ASC','price_desc'=>'l.unit_price DESC','qty_desc'=>'l.qty DESC','new'=>'l.created_at DESC'];
$sort  = $_GET['sort'] ?? 'price_asc';
if (!isset($sorts[$sort])) $sort = 'price_asc';
$order = $sorts[$sort];

$cats = $pdo->query("SELECT DISTINCT i.category FROM market_listings l JOIN items i ON i.id = l.item_id ORDER BY i.category")->fetchAll(PDO::FETCH_COLUMN);
$cat  = $_GET['cat'] ?? '';
$where = ''; $params = [];
if ($cat !== '' && in_array($cat, $cats, true)) { $where = 'WHERE i.category = ?'; $params[] = $cat; }

$lq = $pdo->prepare('SELECT l.id, l.qty, l.unit_price, l.seller_id, i.id AS item_id, i.name AS item_name, i.category, p.username AS seller,
       (SELECT ROUND(AVG(unit_price)) FROM market_sales s WHERE s.item_id = i.id) AS avg_sale
       FROM market_listings l JOIN items i ON i.id = l.item_id JOIN players p ON p.id = l.seller_id
       ' . $where . ' ORDER BY ' . $order);
$lq->execute($params); $listings = $lq->fetchAll();

$mine = $pdo->prepare('SELECT l.id, l.qty, l.unit_price, i.name AS item_name, i.category FROM market_listings l JOIN items i ON i.id = l.item_id WHERE l.seller_id = ? ORDER BY l.created_at DESC');
$mine->execute([$pid]); $mine = $mine->fetchAll();

$inv = $pdo->prepare('SELECT pi.item_id, pi.qty, i.name, i.category FROM player_items pi JOIN items i ON i.id = pi.item_id WHERE pi.player_id = ? AND pi.qty > 0 ORDER BY i.name');
$inv->execute([$pid]); $inv = $inv->fetchAll();

function bsort_link($key, $label, $cur, $tab, $cat) {
  $active = $cur === $key;
  $cls = $active ? ' style="color:var(--accent);font-weight:600"' : '';
  $catq = $cat !== '' ? '&cat='.urlencode($cat) : '';
  return '<a href="index.php?p=bazaar&tab='.$tab.'&sort='.$key.$catq.'"'.$cls.'>'.$label.($active?' &#9662;':'').'</a>';
}
?>

<div class="panel">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="margin:0">&#128722; The Bazaar</h2>
      <p class="muted" style="margin:4px 0 0">Open market &mdash; player to player. The Grid takes 2%.</p>
    </div>
    <div style="text-align:right;font-size:13px">
      <div class="muted">Pocket</div>
      <div style="color:var(--accent);font-size:20px;font-weight:700"><?= number_format($player['creds_pocket']) ?> <span style="font-size:12px;font-weight:400">cr</span></div>
    </div>
  </div>
  <?php if ($msg): ?><div class="flash flash-<?= $msgType ?>" style="margin-top:12px"><?= e($msg) ?></div><?php endif; ?>
  <div class="tabs" style="margin:14px -14px -14px;border-top:1px solid var(--line)">
    <a class="tab <?= $tab==='browse'?'is-active':'' ?>" href="index.php?p=bazaar&tab=browse">
      &#128269; Browse<?php if(count($listings)): ?> <span class="bz-count"><?= count($listings) ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $tab==='sell'?'is-active':'' ?>" href="index.php?p=bazaar&tab=sell">&#128722; Sell</a>
    <a class="tab <?= $tab==='listings'?'is-active':'' ?>" href="index.php?p=bazaar&tab=listings">
      &#128203; My Listings<?php if(count($mine)): ?> <span class="bz-count"><?= count($mine) ?></span><?php endif; ?>
    </a>
  </div>
</div>

<?php if ($tab === 'browse'): ?>
<div class="panel">
  <div class="bz-toolbar">
    <div class="bz-cats">
      <a class="bz-cat-btn <?= $cat==='' ? 'active' : '' ?>" href="index.php?p=bazaar&tab=browse&sort=<?= e($sort) ?>">All</a>
      <?php foreach ($cats as $c): ?>
        <a class="bz-cat-btn <?= $cat===$c ? 'active' : '' ?>" href="index.php?p=bazaar&tab=browse&sort=<?= e($sort) ?>&cat=<?= urlencode($c) ?>"><?= e(ucfirst($c)) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="bz-sorts">
      <span class="muted" style="font-size:11px">Sort:</span>
      <?= bsort_link('price_asc',  'Price&#9650;', $sort, 'browse', $cat) ?>
      <?= bsort_link('price_desc', 'Price&#9660;', $sort, 'browse', $cat) ?>
      <?= bsort_link('item_asc',   'Name',         $sort, 'browse', $cat) ?>
      <?= bsort_link('qty_desc',   'Qty',          $sort, 'browse', $cat) ?>
      <?= bsort_link('new',        'Newest',       $sort, 'browse', $cat) ?>
    </div>
  </div>

  <?php if ($listings): ?>
  <div class="bz-table-wrap">
    <table class="bz-table">
      <thead><tr>
        <th>Item</th><th>Cat</th><th>Qty</th><th>Unit</th><th>Mkt Avg</th><th>Seller</th><th>Total</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($listings as $l): $isOwn = ($l['seller_id'] == $pid); ?>
      <tr<?= $isOwn ? ' class="bz-own"' : '' ?>>
        <td class="bz-item"><?= e($l['item_name']) ?></td>
        <td><span class="bz-badge"><?= e(ucfirst($l['category'])) ?></span></td>
        <td><?= number_format($l['qty']) ?></td>
        <td style="color:var(--accent);font-weight:600"><?= number_format($l['unit_price']) ?> cr</td>
        <td class="muted"><?= $l['avg_sale'] !== null ? number_format($l['avg_sale']).' cr' : '&mdash;' ?></td>
        <td><a href="index.php?p=profile&id=<?= (int)$l['seller_id'] ?>" class="muted"><?= e($l['seller']) ?></a></td>
        <td class="muted"><?= number_format($l['qty'] * $l['unit_price']) ?> cr</td>
        <td>
          <?php if ($isOwn): ?>
            <a href="index.php?p=bazaar&tab=listings" class="muted" style="font-size:11px">your listing</a>
          <?php else: ?>
            <form method="post" style="display:flex;gap:4px;align-items:center;margin:0">
              <input type="hidden" name="action" value="buy">
              <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
              <input type="number" name="qty" min="1" max="<?= (int)$l['qty'] ?>" value="1" style="width:56px;padding:4px 6px;font-size:12px">
              <button type="submit" class="btn-sm">Buy</button>
              <button type="submit" name="all" value="1" class="btn-sm btn-ghost" title="Buy entire stack">All</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:40px 0;font-size:15px">&#128684; The market is dead quiet.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'sell'): ?>
<div class="panel">
  <h3>List an Item for Sale</h3>
  <?php if ($inv): ?>
  <p class="muted" style="margin-bottom:16px">Listed items go into escrow. A <b>2% listing fee</b> on total asking price is charged immediately.</p>
  <form method="post" style="max-width:360px">
    <input type="hidden" name="action" value="list">
    <div class="field">
      <span>Item to sell</span>
      <select name="item_id" id="bzItem">
        <?php foreach ($inv as $it): ?>
          <option value="<?= (int)$it['item_id'] ?>" data-max="<?= (int)$it['qty'] ?>"><?= e($it['name']) ?> &nbsp;(have <?= (int)$it['qty'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="field">
        <span>Quantity</span>
        <div class="num-wrap">
          <input type="number" name="qty" id="bzQty" min="1" value="1" style="min-width:70px;max-width:100px">
          <button type="button" class="fill-max" id="bzMaxQty">Max</button>
        </div>
      </div>
      <div class="field">
        <span>Price per unit (cr)</span>
        <input type="number" name="unit_price" id="bzPrice" min="1" value="100">
      </div>
    </div>
    <div style="background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:6px;padding:12px;margin:12px 0;font-size:13px">
      <div style="display:flex;justify-content:space-between"><span class="muted">Total value</span><b id="bzTotal">&mdash;</b></div>
      <div style="display:flex;justify-content:space-between;margin-top:4px"><span class="muted">2% listing fee</span><b id="bzFee" style="color:var(--neon2)">&mdash;</b></div>
      <div style="display:flex;justify-content:space-between;margin-top:4px;padding-top:4px;border-top:1px solid rgba(25,240,199,.15)"><span>You receive</span><b id="bzNet" style="color:var(--accent)">&mdash;</b></div>
    </div>
    <button type="submit" class="btn">Place Listing</button>
  </form>
  <script>
  (function(){
    var item=document.getElementById('bzItem'),q=document.getElementById('bzQty'),
        p=document.getElementById('bzPrice'),t=document.getElementById('bzTotal'),
        f=document.getElementById('bzFee'),n=document.getElementById('bzNet');
    if(!item) return;
    function fmt(v){ return v.toLocaleString('en-US')+' cr'; }
    function getMax(){ return parseInt((item.options[item.selectedIndex]||{}).getAttribute&&item.options[item.selectedIndex].getAttribute('data-max'),10)||9999; }
    function upd(){
      var tot=Math.max(0,(parseInt(q.value,10)||0)*(parseInt(p.value,10)||0));
      var fee=Math.ceil(tot*0.02);
      t.textContent=fmt(tot); f.textContent=fmt(fee); n.textContent=fmt(tot-fee);
      q.max=getMax();
    }
    var maxBtn=document.getElementById('bzMaxQty');
    if(maxBtn) maxBtn.addEventListener('click',function(){ q.value=getMax(); upd(); });
    item.addEventListener('change',function(){ q.value=1; upd(); }); q.addEventListener('input',upd); p.addEventListener('input',upd); upd();
  })();
  </script>
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:40px 0">&#128230; Your stash is empty &mdash; nothing to sell.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'listings'): ?>
<div class="panel">
  <h3>Your Active Listings</h3>
  <p class="muted" style="margin-bottom:12px">These items are held in escrow while listed. Cancelling returns them to your stash.</p>
  <?php if ($mine): ?>
  <div class="bz-table-wrap">
    <table class="bz-table">
      <thead><tr><th>Item</th><th>Category</th><th>Qty</th><th>Unit Price</th><th>Total Value</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($mine as $l): ?>
      <tr>
        <td class="bz-item"><?= e($l['item_name']) ?></td>
        <td><span class="bz-badge"><?= e(ucfirst($l['category'])) ?></span></td>
        <td><?= number_format($l['qty']) ?></td>
        <td style="color:var(--accent);font-weight:600"><?= number_format($l['unit_price']) ?> cr</td>
        <td class="muted"><?= number_format($l['qty'] * $l['unit_price']) ?> cr</td>
        <td>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
            <button type="submit" class="btn-sm btn-ghost">Cancel</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="font-size:11px;margin-top:10px">Listing fees are non-refundable on cancellation.</p>
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:40px 0">&#128203; No active listings.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
