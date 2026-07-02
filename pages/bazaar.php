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
      // An empty/zero qty must NOT silently buy the whole stack — reject it.
      // (The "All" button posts ?all and covers intentional buy-everything.)
      if ($want <= 0) { $pdo->rollBack(); throw new RuntimeException('Enter a valid quantity.'); }
      if ($want > (int)$listing['qty']) $want = (int)$listing['qty'];
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

// Ensure market_sales table exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS market_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL, qty INT NOT NULL DEFAULT 1,
    unit_price BIGINT NOT NULL DEFAULT 0,
    seller_id INT NOT NULL, buyer_id INT NOT NULL,
    sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item (item_id), INDEX idx_sold (sold_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$tab = $_GET['tab'] ?? 'browse';
if (!in_array($tab, ['browse','sell','listings','sales'], true)) $tab = 'browse';

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

// Inventory for the Sell tab — includes market average so sellers can price against it
$inv = $pdo->prepare('SELECT pi.item_id, pi.qty, i.name, i.category,
       (SELECT ROUND(AVG(unit_price)) FROM market_sales s WHERE s.item_id = pi.item_id) AS avg_sale
       FROM player_items pi JOIN items i ON i.id = pi.item_id WHERE pi.player_id = ? AND pi.qty > 0 ORDER BY i.name');
$inv->execute([$pid]); $inv = $inv->fetchAll();

// Live ticker feed — most recent trades across the whole market
$tickerSales = [];
try {
  $tq = $pdo->query("SELECT ms.qty, ms.unit_price, i.name FROM market_sales ms JOIN items i ON i.id = ms.item_id ORDER BY ms.id DESC LIMIT 14");
  $tickerSales = $tq->fetchAll();
} catch (Throwable $e) {}
$tickerStr = '';
foreach ($tickerSales as $ts) $tickerStr .= '  '.$ts['name'].' ×'.(int)$ts['qty'].' @ '.number_format((int)$ts['unit_price']).'cr  ///';

// Category badge colors (stable per category name)
function bz_cat_col(string $c): string {
  static $palette = ['#19f0c7','#ff2d95','#e8a33d','#4d9be8','#a66de8','#3bcf63','#ff6b35'];
  return $palette[crc32($c) % count($palette)];
}

function bsort_link($key, $label, $cur, $tab, $cat) {
  $active = $cur === $key;
  $cls = $active ? ' style="color:var(--accent);font-weight:600"' : '';
  $catq = $cat !== '' ? '&cat='.urlencode($cat) : '';
  return '<a href="index.php?p=bazaar&tab='.$tab.'&sort='.$key.$catq.'"'.$cls.'>'.$label.($active?' &#9662;':'').'</a>';
}
?>
<style>
#bz-canvas{display:block;width:100%;height:108px;border-radius:9px 9px 0 0}
#bz-head h2{text-shadow:0 0 14px rgba(25,240,199,.3)}
.bz-table tbody tr{transition:background .12s}
.bz-table tbody tr:hover{background:rgba(25,240,199,.035)}
.bz-deal{display:inline-block;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:6px;letter-spacing:.03em}
.bz-deal.good{color:#3bcf63;border:1px solid rgba(59,207,99,.4);background:rgba(59,207,99,.07)}
.bz-deal.bad{color:var(--neon2);border:1px solid rgba(255,45,149,.35);background:rgba(255,45,149,.06)}
.bz-deal.par{color:var(--muted);border:1px solid var(--line)}
.bz-rowtotal{font-size:10px;color:var(--muted);white-space:nowrap}
#bz-search{background:var(--panel2);border:1px solid var(--line);border-radius:16px;color:var(--text);padding:5px 12px;font-size:12px;width:170px;transition:border-color .15s}
#bz-search:focus{border-color:var(--accent);outline:none;box-shadow:0 0 8px rgba(25,240,199,.15)}
.bz-avg-hint{font-size:11px;color:var(--muted);margin-top:4px}
.bz-avg-hint b{color:var(--accent);cursor:pointer;text-decoration:underline dotted}
</style>

<div class="panel" id="bz-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="bz-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:22px;pointer-events:none">
      <h2 style="margin:0">&#128722; The Bazaar</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Open market &mdash; player to player. The Grid takes 2%.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:22px;text-align:right">
      <div class="muted" style="font-size:10px;text-shadow:0 1px 4px #000">POCKET</div>
      <div style="font-size:19px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent);text-shadow:0 1px 6px #000"><?= number_format($player['creds_pocket']) ?> <span style="font-size:11px;font-weight:400">cr</span></div>
    </div>
    <button id="bz-mute" onclick="toggleBzSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
  <?php if ($msg): ?><div class="flash flash-<?= $msgType ?>" style="margin:10px 14px 0"><?= e($msg) ?></div><?php endif; ?>
  <div class="tabs" style="margin:12px 0 16px;border-top:1px solid var(--line)">
    <a class="tab <?= $tab==='browse'?'is-active':'' ?>" href="index.php?p=bazaar&tab=browse">
      &#128269; Browse<?php if(count($listings)): ?> <span class="bz-count"><?= count($listings) ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $tab==='sell'?'is-active':'' ?>" href="index.php?p=bazaar&tab=sell">&#128722; Sell</a>
    <a class="tab <?= $tab==='listings'?'is-active':'' ?>" href="index.php?p=bazaar&tab=listings">
      &#128203; My Listings<?php if(count($mine)): ?> <span class="bz-count"><?= count($mine) ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $tab==='sales'?'is-active':'' ?>" href="index.php?p=bazaar&tab=sales">&#128198; Recent Sales</a>
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
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <input type="text" id="bz-search" placeholder="&#128269; filter items / sellers..." data-no-counter autocomplete="off">
      <div class="bz-sorts">
        <span class="muted" style="font-size:11px">Sort:</span>
        <?= bsort_link('price_asc',  'Price&#9650;', $sort, 'browse', $cat) ?>
        <?= bsort_link('price_desc', 'Price&#9660;', $sort, 'browse', $cat) ?>
        <?= bsort_link('item_asc',   'Name',         $sort, 'browse', $cat) ?>
        <?= bsort_link('qty_desc',   'Qty',          $sort, 'browse', $cat) ?>
        <?= bsort_link('new',        'Newest',       $sort, 'browse', $cat) ?>
      </div>
    </div>
  </div>

  <?php if ($listings): ?>
  <div class="bz-table-wrap">
    <table class="bz-table">
      <thead><tr>
        <th>Item</th><th>Cat</th><th>Qty</th><th>Unit</th><th>Mkt Avg</th><th>Seller</th><th>Total</th><th></th>
      </tr></thead>
      <tbody id="bz-rows">
      <?php foreach ($listings as $l):
        $isOwn = ($l['seller_id'] == $pid);
        $ccol = bz_cat_col($l['category']);
        // deal indicator vs market average
        $deal = '';
        if ($l['avg_sale'] !== null && (int)$l['avg_sale'] > 0) {
          $pct = (int)round(((int)$l['unit_price'] - (int)$l['avg_sale']) / (int)$l['avg_sale'] * 100);
          if ($pct <= -10)     $deal = '<span class="bz-deal good" title="'.abs($pct).'% below market average">&#9660; '.abs($pct).'%</span>';
          elseif ($pct >= 10)  $deal = '<span class="bz-deal bad" title="'.$pct.'% above market average">&#9650; '.$pct.'%</span>';
          else                 $deal = '<span class="bz-deal par" title="Within 10% of market average">&asymp; mkt</span>';
        }
      ?>
      <tr<?= $isOwn ? ' class="bz-own"' : '' ?> data-search="<?= e(mb_strtolower($l['item_name'].' '.$l['seller'].' '.$l['category'])) ?>">
        <td class="bz-item"><?= e($l['item_name']) ?><?= $deal ?></td>
        <td><span class="bz-badge" style="border-color:<?= $ccol ?>55;color:<?= $ccol ?>"><?= e(ucfirst($l['category'])) ?></span></td>
        <td><?= number_format($l['qty']) ?></td>
        <td style="color:var(--accent);font-weight:600"><?= number_format($l['unit_price']) ?> cr</td>
        <td class="muted"><?= $l['avg_sale'] !== null ? number_format($l['avg_sale']).' cr' : '&mdash;' ?></td>
        <td><a href="index.php?p=profile&id=<?= (int)$l['seller_id'] ?>" class="muted"><?= e($l['seller']) ?></a></td>
        <td class="muted"><?= number_format($l['qty'] * $l['unit_price']) ?> cr</td>
        <td>
          <?php if ($isOwn): ?>
            <a href="index.php?p=bazaar&tab=listings" class="muted" style="font-size:11px">your listing</a>
          <?php else: ?>
            <form method="post" style="display:flex;gap:4px;align-items:center;margin:0" data-bzfx="buy"
                  data-bz-name="<?= e($l['item_name']) ?>" data-bz-unit="<?= (int)$l['unit_price'] ?>" data-bz-max="<?= (int)$l['qty'] ?>">
              <input type="hidden" name="action" value="buy">
              <input type="hidden" name="listing_id" value="<?= (int)$l['id'] ?>">
              <input type="number" name="qty" min="1" max="<?= (int)$l['qty'] ?>" value="1" class="bz-qty" data-unit="<?= (int)$l['unit_price'] ?>" style="width:56px;padding:4px 6px;font-size:12px">
              <span class="bz-rowtotal">= <?= number_format($l['unit_price']) ?> cr</span>
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
  <p id="bz-nores" class="muted" style="display:none;text-align:center;padding:24px 0">No listings match that filter.</p>
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:40px 0;font-size:15px">&#128684; The market is dead quiet.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'sell'): ?>
<div class="panel">
  <h3>List an Item for Sale</h3>
  <?php if ($inv): ?>
  <p class="muted" style="margin-bottom:16px">Listed items go into escrow. A <b>2% listing fee</b> on total asking price is charged immediately.</p>
  <form method="post" style="max-width:360px" data-bzfx="list">
    <input type="hidden" name="action" value="list">
    <div class="field">
      <span>Item to sell</span>
      <select name="item_id" id="bzItem">
        <?php foreach ($inv as $it): ?>
          <option value="<?= (int)$it['item_id'] ?>" data-max="<?= (int)$it['qty'] ?>" data-avg="<?= $it['avg_sale'] !== null ? (int)$it['avg_sale'] : '' ?>" data-name="<?= e($it['name']) ?>"><?= e($it['name']) ?> &nbsp;(have <?= (int)$it['qty'] ?>)</option>
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
        <div class="bz-avg-hint" id="bzAvgHint" style="display:none">Mkt avg: <b id="bzAvgVal" title="Click to use the market average">&mdash;</b></div>
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
        f=document.getElementById('bzFee'),n=document.getElementById('bzNet'),
        ah=document.getElementById('bzAvgHint'),av=document.getElementById('bzAvgVal');
    if(!item) return;
    function fmt(v){ return v.toLocaleString('en-US')+' cr'; }
    function opt(){ return item.options[item.selectedIndex]||null; }
    function getMax(){ var o=opt(); return o?parseInt(o.getAttribute('data-max'),10)||9999:9999; }
    function getAvg(){ var o=opt(); var a=o?o.getAttribute('data-avg'):''; return a?parseInt(a,10):0; }
    function upd(){
      var tot=Math.max(0,(parseInt(q.value,10)||0)*(parseInt(p.value,10)||0));
      var fee=Math.ceil(tot*0.02);
      t.textContent=fmt(tot); f.textContent=fmt(fee); n.textContent=fmt(tot-fee);
      q.max=getMax();
      var avg=getAvg();
      if(avg>0){ ah.style.display=''; av.textContent=avg.toLocaleString('en-US')+' cr'; }
      else ah.style.display='none';
    }
    var maxBtn=document.getElementById('bzMaxQty');
    if(maxBtn) maxBtn.addEventListener('click',function(){ q.value=getMax(); upd(); });
    if(av) av.addEventListener('click',function(){ var a=getAvg(); if(a>0){ p.value=a; upd(); } });
    item.addEventListener('change',function(){ q.value=1; upd(); }); q.addEventListener('input',upd); p.addEventListener('input',upd); upd();
  })();
  </script>
  <?php else: ?>
    <p class="muted" style="text-align:center;padding:40px 0">&#128230; Your stash is empty &mdash; nothing to sell.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'sales'): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700">&#128198; Recent Sales</div>
  <?php
    $recentSales = [];
    try {
      $rsq = $pdo->query("SELECT ms.id,ms.qty,ms.unit_price,ms.sold_at,i.name AS item_name,i.category,
        sb.username AS seller_name,sb.id AS seller_id,
        bu.username AS buyer_name,bu.id AS buyer_id
        FROM market_sales ms
        JOIN items i ON i.id=ms.item_id
        JOIN players sb ON sb.id=ms.seller_id
        JOIN players bu ON bu.id=ms.buyer_id
        ORDER BY ms.sold_at DESC LIMIT 60");
      $recentSales = $rsq->fetchAll();
    } catch (Throwable $e) {}
  ?>
  <?php if (empty($recentSales)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">No sales recorded yet.</div>
  <?php else: ?>
  <div style="padding:8px 14px;border-bottom:1px solid var(--line);display:grid;grid-template-columns:1fr 80px 100px 120px 90px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:700">
    <span>Item</span><span>Qty</span><span>Unit Price</span><span>Parties</span><span>Date</span>
  </div>
  <?php foreach ($recentSales as $rs): $ccol = bz_cat_col($rs['category']); ?>
  <div style="display:grid;grid-template-columns:1fr 80px 100px 120px 90px;padding:9px 14px;border-bottom:1px solid rgba(255,255,255,.04);align-items:center;font-size:12px">
    <div>
      <span style="font-weight:700"><?= e($rs['item_name']) ?></span>
      <span class="bz-badge" style="margin-left:6px;font-size:10px;border-color:<?= $ccol ?>55;color:<?= $ccol ?>"><?= e(ucfirst($rs['category'])) ?></span>
    </div>
    <span style="color:var(--muted)"><?= number_format($rs['qty']) ?></span>
    <span style="color:var(--accent);font-weight:600"><?= number_format($rs['unit_price']) ?> cr</span>
    <div style="font-size:11px;line-height:1.5">
      <div><span style="color:var(--muted)">S:</span> <a href="index.php?p=profile&id=<?= (int)$rs['seller_id'] ?>" style="color:var(--text)"><?= e($rs['seller_name']) ?></a></div>
      <div><span style="color:var(--muted)">B:</span> <a href="index.php?p=profile&id=<?= (int)$rs['buyer_id'] ?>" style="color:var(--text)"><?= e($rs['buyer_name']) ?></a></div>
    </div>
    <span style="color:var(--muted);font-size:11px"><?= e(date('M j, g:ia', strtotime($rs['sold_at']))) ?></span>
  </div>
  <?php endforeach; ?>
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
      <?php foreach ($mine as $l): $ccol = bz_cat_col($l['category']); ?>
      <tr>
        <td class="bz-item"><?= e($l['item_name']) ?></td>
        <td><span class="bz-badge" style="border-color:<?= $ccol ?>55;color:<?= $ccol ?>"><?= e(ucfirst($l['category'])) ?></span></td>
        <td><?= number_format($l['qty']) ?></td>
        <td style="color:var(--accent);font-weight:600"><?= number_format($l['unit_price']) ?> cr</td>
        <td class="muted"><?= number_format($l['qty'] * $l['unit_price']) ?> cr</td>
        <td>
          <form method="post" style="margin:0" data-bzfx="cancel" data-bz-name="<?= e($l['item_name']) ?>" data-bz-max="<?= (int)$l['qty'] ?>">
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

<script>
(function(){
'use strict';

/* ── Market street header ─────────────────────────────────────────────── */
var bc=document.getElementById('bz-canvas');
if(bc){
  var c=bc.getContext('2d');
  var BW=560, BH=108;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  bc.width=BW*dpr; bc.height=BH*dpr;
  c.scale(dpr,dpr);

  var TICKER=<?= json_encode($tickerStr) ?>;
  var tickerX=0;
  var AWN=['#19f0c7','#ff2d95','#e8a33d','#4d9be8','#a66de8'];
  var SIGNS=[{x:300,w:42,txt:'交易',col:'#ff2d95'},{x:354,w:36,txt:'MKT',col:'#19f0c7'},{x:402,w:30,txt:'&yen;$',col:'#e8a33d'}];
  // crowd silhouettes
  var crowd=[];
  for(var ci=0;ci<9;ci++) crowd.push({x:Math.random()*BW, v:(.12+Math.random()*.3)*(Math.random()<.5?-1:1), h:8+Math.random()*7, p:Math.random()*9});
  // rising credit glyphs
  var glyphs=[];

  function bzLoop(t){
    if(!document.body.contains(bc)) return;
    requestAnimationFrame(bzLoop);
    c.clearRect(0,0,BW,BH);
    var bg=c.createLinearGradient(0,0,0,BH);
    bg.addColorStop(0,'#0a0a14'); bg.addColorStop(1,'#10101e');
    c.fillStyle=bg; c.fillRect(0,0,BW,BH);

    // stall awnings — scalloped strips along the top
    for(var a=0;a<5;a++){
      var ax=20+a*108, acol=AWN[a%AWN.length];
      var wave=Math.sin(t/1000+a)*1.5;
      c.fillStyle=acol; c.globalAlpha=.22;
      c.beginPath();
      c.moveTo(ax,14);
      c.lineTo(ax+92,14);
      for(var s2=4;s2>=0;s2--){
        var sx2=ax+s2*18.4+9.2;
        c.quadraticCurveTo(sx2,30+wave,sx2-9.2,22+wave*.5);
      }
      c.closePath(); c.fill();
      c.globalAlpha=.5;
      c.fillRect(ax,12,92,2.5);
      c.globalAlpha=1;
      // stall leg posts
      c.fillStyle='rgba(255,255,255,.06)';
      c.fillRect(ax+2,14,1.5,BH-32); c.fillRect(ax+88,14,1.5,BH-32);
    }

    // hanging neon shop signs
    for(var si=0;si<SIGNS.length;si++){
      var S=SIGNS[si];
      var flick=(Math.random()<.015)?.25:(.8+.2*Math.sin(t/420+si*2));
      var sy=34+Math.sin(t/1400+si)*2;
      c.strokeStyle='rgba(255,255,255,.12)';
      c.beginPath(); c.moveTo(S.x+S.w/2,14); c.lineTo(S.x+S.w/2,sy); c.stroke();
      c.fillStyle='rgba(8,8,16,.9)';
      c.fillRect(S.x,sy,S.w,18);
      c.strokeStyle=S.col; c.globalAlpha=.5*flick;
      c.strokeRect(S.x+.5,sy+.5,S.w-1,17);
      c.globalAlpha=flick;
      c.shadowColor=S.col; c.shadowBlur=8*flick;
      c.font='700 11px monospace'; c.textAlign='center'; c.textBaseline='middle';
      c.fillStyle=S.col;
      c.fillText(S.txt.replace('&yen;','¥'),S.x+S.w/2,sy+9.5);
      c.shadowBlur=0; c.globalAlpha=1;
    }

    // rising credit glyphs
    if(Math.random()<.05) glyphs.push({x:30+Math.random()*(BW-80),y:BH-22,life:1,col:AWN[Math.floor(Math.random()*AWN.length)]});
    for(var gi=glyphs.length-1;gi>=0;gi--){
      var G=glyphs[gi];
      G.y-=.32; G.life-=.011;
      if(G.life<=0){glyphs.splice(gi,1);continue;}
      c.globalAlpha=Math.max(0,G.life)*.55;
      c.font='10px monospace'; c.textAlign='center';
      c.fillStyle=G.col;
      c.fillText('¤',G.x,G.y);
    }
    c.globalAlpha=1;

    // crowd silhouettes drifting along the walkway
    for(var ki=0;ki<crowd.length;ki++){
      var K=crowd[ki];
      K.x+=K.v;
      if(K.x<-10) K.x=BW+10; if(K.x>BW+10) K.x=-10;
      var bobb=Math.sin(t/240+K.p)*1.2;
      c.fillStyle='rgba(0,0,0,.55)';
      c.beginPath(); c.arc(K.x,BH-26-K.h+bobb,3.2,0,Math.PI*2); c.fill(); // head
      c.fillRect(K.x-2.6,BH-23-K.h+bobb,5.2,K.h); // body
    }

    // walkway + ticker strip
    c.fillStyle='rgba(255,255,255,.04)'; c.fillRect(0,BH-22,BW,1);
    c.fillStyle='#05050c'; c.fillRect(0,BH-16,BW,16);
    c.fillStyle='rgba(25,240,199,.12)'; c.fillRect(0,BH-16,BW,1);
    c.font='700 10px monospace'; c.textAlign='left'; c.textBaseline='middle';
    c.fillStyle='#19f0c7';
    tickerX-=0.65;
    var tw=c.measureText(TICKER).width;
    if(tw>0&&tickerX<-tw) tickerX+=tw;
    c.fillText(TICKER,tickerX,BH-8);
    c.fillText(TICKER,tickerX+tw,BH-8);
  }
  requestAnimationFrame(bzLoop);
}

/* ── Browse: live search + row totals ─────────────────────────────────── */
var search=document.getElementById('bz-search');
var rows=document.getElementById('bz-rows');
if(search&&rows){
  search.addEventListener('input',function(){
    var q=search.value.trim().toLowerCase();
    var shown=0;
    rows.querySelectorAll('tr').forEach(function(tr){
      var hit=!q||(tr.dataset.search||'').indexOf(q)!==-1;
      tr.style.display=hit?'':'none';
      if(hit) shown++;
    });
    var nores=document.getElementById('bz-nores');
    if(nores) nores.style.display=shown?'none':'';
  });
}
document.querySelectorAll('.bz-qty').forEach(function(inp){
  inp.addEventListener('input',function(){
    var unit=parseInt(inp.dataset.unit,10)||0;
    var q=Math.max(0,parseInt(inp.value,10)||0);
    var span=inp.parentNode.querySelector('.bz-rowtotal');
    if(span) span.textContent='= '+(unit*q).toLocaleString('en-US')+' cr';
  });
});
})();
</script>

<script>
/* Deal-stamp FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._bzFxBound) return;
  window._bzFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#bzfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(4,4,10,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#bzfx.show{opacity:1}'
    +'.bzfx-receipt{position:relative;min-width:210px;background:#0c0c18;border:1px solid rgba(255,255,255,.18);'
    +'border-radius:8px;padding:16px 20px 18px;box-shadow:0 0 30px rgba(0,0,0,.6),0 0 18px var(--bz-col-a);'
    +'animation:bzSlide .25s ease-out}'
    +'@keyframes bzSlide{0%{transform:translateY(14px);opacity:0}100%{transform:none;opacity:1}}'
    +'.bzfx-line{display:flex;justify-content:space-between;gap:18px;font-size:12px;padding:3px 0;color:var(--text)}'
    +'.bzfx-line span:first-child{color:#5d6680}'
    +'.bzfx-head{font-size:10px;letter-spacing:.18em;color:#5d6680;text-align:center;margin-bottom:8px;'
    +'border-bottom:1px dashed rgba(255,255,255,.15);padding-bottom:7px}'
    +'.bzfx-stamp{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%) rotate(-11deg) scale(2.4);'
    +'font-size:17px;font-weight:900;letter-spacing:.12em;color:var(--bz-col);border:3px solid var(--bz-col);'
    +'border-radius:6px;padding:4px 12px;opacity:0;text-shadow:0 0 10px var(--bz-col);white-space:nowrap;'
    +'box-shadow:0 0 14px var(--bz-col-a),inset 0 0 10px var(--bz-col-a);'
    +'animation:bzStamp .3s .42s cubic-bezier(.2,1.8,.4,1) forwards}'
    +'@keyframes bzStamp{0%{opacity:0;transform:translate(-50%,-50%) rotate(-11deg) scale(2.4)}'
    +'100%{opacity:1;transform:translate(-50%,-50%) rotate(-11deg) scale(1)}}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('bazaarMuted')==='1';
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
  function coins(){ sfx(1320,.06,'sine',.04); setTimeout(function(){sfx(1760,.09,'sine',.035);},70); }
  window.toggleBzSound=function(){
    muted=!muted; localStorage.setItem('bazaarMuted',muted?'1':'0');
    var b=document.getElementById('bz-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) sfx(660,.08,'sine',.05);
  };
  (function(){ var b=document.getElementById('bz-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; })();

  function hexA(hex,a){
    if(hex.charAt(0)!=='#') return hex;
    var n=parseInt(hex.slice(1),16);
    return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';
  }

  function receipt(stampTxt,col,lines){
    var old=document.getElementById('bzfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='bzfx';
    o.style.setProperty('--bz-col',col);
    o.style.setProperty('--bz-col-a',hexA(col,.35));
    var body='';
    lines.forEach(function(L){ body+='<div class="bzfx-line"><span>'+L[0]+'</span><b>'+L[1]+'</b></div>'; });
    o.innerHTML='<div class="bzfx-receipt"><div class="bzfx-head">GRID TRADE AUTHORITY</div>'+body
      +'<div class="bzfx-stamp">'+stampTxt+'</div></div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    coins();
    setTimeout(function(){ sfx(95,.12,'square',.05); },430); // stamp thud
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},1900);
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute) return;
    var kind=f.getAttribute('data-bzfx');
    if(!kind) return;
    var fmt=function(n){ return n.toLocaleString('en-US')+' cr'; };
    if(kind==='buy'){
      var max=parseInt(f.getAttribute('data-bz-max'),10)||1;
      var unit=parseInt(f.getAttribute('data-bz-unit'),10)||0;
      var qi=f.querySelector('input[name=qty]');
      var qty=(ev.submitter&&ev.submitter.name==='all')?max:Math.min(max,Math.max(1,parseInt(qi&&qi.value,10)||1));
      receipt('DEAL CLOSED','#3bcf63',[
        ['ITEM',f.getAttribute('data-bz-name')||''],
        ['QTY','×'+qty],
        ['TOTAL',fmt(unit*qty)]
      ]);
    } else if(kind==='list'){
      var sel=f.querySelector('select[name=item_id]');
      var nm=sel&&sel.options[sel.selectedIndex]?sel.options[sel.selectedIndex].getAttribute('data-name'):'';
      var q2=parseInt((f.querySelector('input[name=qty]')||{}).value,10)||1;
      var p2=parseInt((f.querySelector('input[name=unit_price]')||{}).value,10)||0;
      receipt('LISTED','#e8a33d',[
        ['ITEM',nm||''],
        ['QTY','×'+q2],
        ['ASKING',fmt(p2)+' /unit']
      ]);
    } else if(kind==='cancel'){
      receipt('RECALLED','#8fa3c8',[
        ['ITEM',f.getAttribute('data-bz-name')||''],
        ['QTY','×'+(parseInt(f.getAttribute('data-bz-max'),10)||1)],
        ['STATUS','returned to stash']
      ]);
    }
  },true);
})();
</script>
