<?php /* pages/auction.php — Black Market Auctions */
/*
  Schema (run once):
  CREATE TABLE IF NOT EXISTS auction_listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    item_id INT NOT NULL,
    player_item_id INT NULL,
    item_name VARCHAR(120) NOT NULL DEFAULT '',
    starting_price INT NOT NULL DEFAULT 1,
    current_bid INT NOT NULL DEFAULT 0,
    bidder_id INT NULL,
    bid_count INT NOT NULL DEFAULT 0,
    ends_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','ended','cancelled') NOT NULL DEFAULT 'active',
    INDEX idx_status (status),
    INDEX idx_seller (seller_id),
    INDEX idx_ends (ends_at)
  ) ENGINE=InnoDB;

  CREATE TABLE IF NOT EXISTS auction_bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    bidder_id INT NOT NULL,
    amount INT NOT NULL,
    placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_listing (listing_id)
  ) ENGINE=InnoDB;
*/
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

define('AUCTION_FEE_PCT',     0.01);   // 1% auctioneer fee on successful sale
// Listing fee by duration (% of starting price)
$AUCTION_LISTING_FEES = [1=>0.02, 6=>0.03, 12=>0.04, 24=>0.05, 48=>0.07];
define('AUCTION_CANCEL_PCT',  0.025);  // 2.5% penalty fee to pull listing after 5-minute grace
define('AUCTION_GRACE_SEC',   300);    // 5-minute free edit/cancel window

// Auto-setup tables if missing
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS auction_listings (
    id INT AUTO_INCREMENT PRIMARY KEY, seller_id INT NOT NULL, item_id INT NOT NULL DEFAULT 0,
    player_item_id INT NULL, item_name VARCHAR(120) NOT NULL DEFAULT '',
    starting_price INT NOT NULL DEFAULT 1, current_bid INT NOT NULL DEFAULT 0,
    bidder_id INT NULL, bid_count INT NOT NULL DEFAULT 0, ends_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','ended','cancelled') NOT NULL DEFAULT 'active',
    INDEX idx_status (status), INDEX idx_seller (seller_id), INDEX idx_ends (ends_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS auction_bids (
    id INT AUTO_INCREMENT PRIMARY KEY, listing_id INT NOT NULL, bidder_id INT NOT NULL,
    amount INT NOT NULL, placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_listing (listing_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Auto-resolve ended auctions
try {
  $ended = $pdo->query("SELECT * FROM auction_listings WHERE status='active' AND ends_at <= NOW()")->fetchAll();
  foreach ($ended as $el) {
    $pdo->beginTransaction();
    try {
      // Claim the listing first — only the request that wins this flip pays out,
      // so two tabs resolving the same auction can't double-credit the seller.
      $claim = $pdo->prepare("UPDATE auction_listings SET status='ended' WHERE id=? AND status='active'");
      $claim->execute([$el['id']]);
      if ($claim->rowCount() !== 1) { $pdo->rollBack(); continue; }
      $iqty = max(1, (int)($el['item_qty'] ?? 1));
      if ((int)$el['bidder_id'] && (int)$el['current_bid'] > 0) {
        $fee      = (int)max(1, ceil($el['current_bid'] * AUCTION_FEE_PCT));
        $proceeds = $el['current_bid'] - $fee;
        // Give item to winner
        if ((int)$el['item_id'] > 0) {
          $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')
              ->execute([(int)$el['bidder_id'], (int)$el['item_id'], $iqty]);
        }
        // Give credits to seller (minus fee)
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$proceeds, $el['seller_id']]);
      } else {
        // Return item to seller if no bids
        if ((int)$el['item_id'] > 0) {
          $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')
              ->execute([(int)$el['seller_id'], (int)$el['item_id'], $iqty]);
        }
      }
      $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); }
  }
} catch (Throwable $e) {}

$tab = in_array($_GET['tab'] ?? '', ['browse','mine','create','history','myhistory']) ? $_GET['tab'] : 'browse';

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {

    if ($act === 'create') {
      $selItemId = (int)($_POST['item_id'] ?? 0);
      $itemName  = trim($_POST['item_name'] ?? '');
      $startPx   = max(1, (int)($_POST['start_price'] ?? 1));
      $hoursRaw  = (int)($_POST['duration'] ?? 24);
      $hours     = in_array($hoursRaw, [1,6,12,24,48]) ? $hoursRaw : 24;
      $qty       = max(1, min(99, (int)($_POST['qty'] ?? 1)));
      // Listings must be backed by a real inventory item — otherwise the winner pays for nothing.
      if ($selItemId <= 0) throw new RuntimeException('Select an item from your inventory.');
      $iq = $pdo->prepare('SELECT i.name,pi.qty FROM player_items pi JOIN items i ON i.id=pi.item_id WHERE pi.player_id=? AND pi.item_id=? AND pi.qty>0');
      $iq->execute([$pid, $selItemId]); $invRow = $iq->fetch();
      if (!$invRow) throw new RuntimeException('Item not found in inventory.');
      if ((int)$invRow['qty'] < $qty) throw new RuntimeException('Not enough in inventory (you have '.(int)$invRow['qty'].').');
      $itemName = $invRow['name'];
      if ($itemName === '') throw new RuntimeException('Select an item from your inventory.');
      if (strlen($itemName) > 100) throw new RuntimeException('Item name too long.');
      $feePct  = $AUCTION_LISTING_FEES[$hours] ?? 0.05;
      $listFee = (int)ceil($startPx * $feePct);
      // Add item_qty column if missing (silent) — must run BEFORE the transaction
      // (ALTER implicitly commits in MySQL and would break the rollback below)
      try { $pdo->exec('ALTER TABLE auction_listings ADD COLUMN item_qty INT NOT NULL DEFAULT 1'); } catch (Throwable $e) {}
      // Fee, item escrow, and listing land or fail together — without this, a
      // failed fee charge would still have destroyed the seller's items.
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$listFee, $pid, $listFee]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits. Listing fee: ' . number_format($listFee) . ' cr.');
      $dec = $pdo->prepare('UPDATE player_items SET qty=qty-? WHERE player_id=? AND item_id=? AND qty>=?');
      $dec->execute([$qty, $pid, $selItemId, $qty]);
      if ($dec->rowCount() !== 1) throw new RuntimeException('Not enough in inventory.');
      $pdo->prepare('DELETE FROM player_items WHERE player_id=? AND item_id=? AND qty<=0')->execute([$pid, $selItemId]);
      $endsAt = date('Y-m-d H:i:s', time() + $hours * 3600);
      $pdo->prepare('INSERT INTO auction_listings (seller_id, item_id, item_name, item_qty, starting_price, ends_at) VALUES (?,?,?,?,?,?)')->execute([$pid, $selItemId, $itemName, $qty, $startPx, $endsAt]);
      $pdo->commit();
      $msg = 'Auction created! Listing ' . $qty . '× ' . $itemName . '. Fee: ' . number_format($listFee) . ' cr (' . round($feePct*100) . '%).';
      $tab = 'browse';

    } elseif ($act === 'bid') {
      $listId = (int)($_POST['listing_id'] ?? 0);
      $bidAmt = (int)($_POST['bid_amount'] ?? 0);
      // Lock the listing row so concurrent bids serialize — otherwise two bids can
      // both refund the same old bidder and strand one bidder's escrowed credits.
      $pdo->beginTransaction();
      $q = $pdo->prepare("SELECT * FROM auction_listings WHERE id=? AND status='active' AND ends_at > NOW() FOR UPDATE");
      $q->execute([$listId]); $row = $q->fetch();
      if (!$row) { $pdo->rollBack(); throw new RuntimeException('Auction not found or already ended.'); }
      if ((int)$row['seller_id'] === (int)$pid) { $pdo->rollBack(); throw new RuntimeException("You can't bid on your own auction."); }
      $minBid = max((int)$row['starting_price'], (int)$row['current_bid'] + 1);
      if ($bidAmt < $minBid) { $pdo->rollBack(); throw new RuntimeException('Minimum bid is ' . number_format($minBid) . ' credits.'); }
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$bidAmt, $pid, $bidAmt]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits.'); }
      // Refund previous highest bidder
      if ((int)$row['bidder_id'] && (int)$row['current_bid'] > 0) {
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([(int)$row['current_bid'], (int)$row['bidder_id']]);
      }
      $pdo->prepare('UPDATE auction_listings SET current_bid=?, bidder_id=?, bid_count=bid_count+1 WHERE id=?')->execute([$bidAmt, $pid, $listId]);
      $pdo->prepare('INSERT INTO auction_bids (listing_id, bidder_id, amount) VALUES (?,?,?)')->execute([$listId, $pid, $bidAmt]);
      $pdo->commit();
      $player = current_player();
      $msg = 'Bid of ' . number_format($bidAmt) . ' credits placed.';

    } elseif ($act === 'cancel') {
      $listId = (int)($_POST['listing_id'] ?? 0);
      $q = $pdo->prepare("SELECT * FROM auction_listings WHERE id=? AND seller_id=? AND status='active'");
      $q->execute([$listId, $pid]); $row = $q->fetch();
      if (!$row) throw new RuntimeException('Listing not found.');
      if ((int)$row['bid_count'] > 0) throw new RuntimeException('Cannot cancel — bids have been placed.');
      $gracePassed = (time() - strtotime($row['created_at'])) > AUCTION_GRACE_SEC;
      $penalty = 0;
      $pdo->beginTransaction();
      $flip = $pdo->prepare("UPDATE auction_listings SET status='cancelled' WHERE id=? AND seller_id=? AND status='active' AND bid_count=0");
      $flip->execute([$listId, $pid]);
      if ($flip->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Listing not found.'); }
      if ($gracePassed) {
        $penalty = (int)ceil((int)$row['starting_price'] * AUCTION_CANCEL_PCT);
        $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
        $u->execute([$penalty, $pid, $penalty]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits to pay the ' . number_format($penalty) . ' cr cancellation penalty.'); }
      }
      // Return the listed item — it was deducted from inventory at listing time
      if ((int)$row['item_id'] > 0) {
        $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')
            ->execute([$pid, (int)$row['item_id'], max(1, (int)($row['item_qty'] ?? 1))]);
      }
      $pdo->commit();
      $msg = 'Auction cancelled.' . ($penalty > 0 ? ' Penalty: ' . number_format($penalty) . ' cr.' : ' No penalty (within 5-minute window).');
      $tab = 'mine';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

// ── Fetch listings ─────────────────────────────────────────────────────────
$active = [];
try {
  $active = $pdo->query("SELECT al.*, p.username AS seller_name,
    UNIX_TIMESTAMP(al.ends_at) AS ends_unix,
    (UNIX_TIMESTAMP(al.ends_at) - UNIX_TIMESTAMP()) AS secs_left
    FROM auction_listings al JOIN players p ON p.id = al.seller_id
    WHERE al.status = 'active' ORDER BY al.ends_at ASC LIMIT 100")->fetchAll();
} catch (Throwable $e) {}

$myListings = [];
try {
  $q = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(ends_at) AS ends_unix, (UNIX_TIMESTAMP(ends_at) - UNIX_TIMESTAMP()) AS secs_left FROM auction_listings WHERE seller_id=? AND status='active' ORDER BY ends_at ASC");
  $q->execute([$pid]); $myListings = $q->fetchAll();
} catch (Throwable $e) {}
$playerInvAuction = [];
try { $piq = $pdo->prepare('SELECT pi.item_id,pi.qty,i.name FROM player_items pi JOIN items i ON i.id=pi.item_id WHERE pi.player_id=? AND pi.qty>0 ORDER BY i.name'); $piq->execute([$pid]); $playerInvAuction = $piq->fetchAll(); } catch (Throwable $e) {}

// Soonest-closing auction for the "closing soon" strip
$soonest = $active[0] ?? null;

// ── History ────────────────────────────────────────────────────────────────
$historyAll = []; $historyMine = [];
if ($tab === 'history') {
  try { $historyAll = $pdo->query("SELECT al.*, p.username AS seller_name, b.username AS bidder_name FROM auction_listings al JOIN players p ON p.id=al.seller_id LEFT JOIN players b ON b.id=al.bidder_id WHERE al.status IN ('ended','cancelled') ORDER BY al.created_at DESC LIMIT 60")->fetchAll(); } catch (Throwable $e) {}
}
if ($tab === 'myhistory') {
  try { $q2=$pdo->prepare("SELECT al.*, b.username AS bidder_name FROM auction_listings al LEFT JOIN players b ON b.id=al.bidder_id WHERE al.seller_id=? AND al.status IN ('ended','cancelled') ORDER BY al.created_at DESC LIMIT 60"); $q2->execute([$pid]); $historyMine=$q2->fetchAll(); } catch (Throwable $e) {}
}
?>
<style>
#au-canvas{display:block;width:100%;height:112px;border-radius:9px 9px 0 0}
#au-head h2{text-shadow:0 0 16px rgba(255,45,149,.45)}
.au-tab{padding:7px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s}
.au-tab.on{border-color:var(--neon2);background:rgba(255,45,149,.1);color:var(--neon2);box-shadow:0 0 10px rgba(255,45,149,.14)}
.au-row{transition:background .12s}
.au-row:hover{background:rgba(255,45,149,.03)}
.au-row.ending{box-shadow:inset 3px 0 0 var(--neon2)}
@keyframes auUrgent{0%,100%{opacity:1}50%{opacity:.45}}
.au-timer.urgent{color:var(--neon2)!important;font-weight:700;animation:auUrgent 1s ease-in-out infinite}
.au-hot{display:inline-block;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;border:1px solid rgba(255,107,53,.5);color:#ff6b35;background:rgba(255,107,53,.08);margin-left:6px}
.au-lead{display:inline-block;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;border:1px solid rgba(59,207,99,.5);color:#3bcf63;background:rgba(59,207,99,.08);margin-left:6px}
.au-quick{font-size:9px;padding:2px 7px;border-radius:9px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.04);color:var(--muted);cursor:pointer;transition:border-color .12s,color .12s}
.au-quick:hover{border-color:var(--accent);color:var(--accent)}
#au-soonest{background:#08050b;border:1px solid rgba(255,45,149,.25);border-radius:7px;padding:8px 14px;display:flex;align-items:center;gap:10px;font-size:12px}
@keyframes auLive{0%,100%{opacity:1}50%{opacity:.25}}
.au-livedot{width:7px;height:7px;border-radius:50%;background:var(--neon2);box-shadow:0 0 8px var(--neon2);animation:auLive 1.3s ease-in-out infinite}
.au-grace-bar{height:3px;border-radius:2px;background:rgba(255,255,255,.08);overflow:hidden;margin-top:6px}
.au-grace-bar>div{height:100%;background:#3bcf63;transition:width 1s linear}
</style>

<!-- Header -->
<div class="panel" id="au-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="au-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:12px;pointer-events:none">
      <h2 style="margin:0">&#127748; The Black Market</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Anonymous. Ruthless. Best prices in the Sprawl.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:12px;text-align:right">
      <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;font-size:10px;color:var(--neon2);font-weight:700;letter-spacing:.08em"><span class="au-livedot"></span> <?= count($active) ?> LIVE</div>
      <div style="font-size:17px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent);text-shadow:0 1px 6px #000;margin-top:2px"><?= number_format($player['creds_pocket']) ?> <span style="font-size:11px;font-weight:400">cr</span></div>
    </div>
    <button id="au-mute" onclick="toggleAuSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
</div>

<?php if ($soonest): ?>
<div id="au-soonest">
  <span class="au-livedot" style="flex:none"></span>
  <span style="color:var(--muted);flex:none;text-transform:uppercase;letter-spacing:.08em;font-size:10px;font-weight:700">Closing soon</span>
  <b style="color:var(--text)"><?= e($soonest['item_name']) ?></b>
  <span style="color:var(--muted)">top bid <b style="color:var(--accent)"><?= number_format(max((int)$soonest['current_bid'],(int)$soonest['starting_price'])) ?> cr</b></span>
  <span class="auction-timer" data-ends="<?= (int)$soonest['ends_unix'] ?>" style="margin-left:auto;font-family:'Orbitron',sans-serif;font-weight:700;color:var(--neon2)">--</span>
</div>
<?php endif; ?>

<!-- Fees notice -->
<div style="background:rgba(25,240,199,.04);border:1px solid rgba(25,240,199,.12);border-radius:6px;padding:9px 14px;font-size:11px;color:var(--muted);display:flex;flex-wrap:wrap;gap:12px">
  <span>&#9888; <b style="color:var(--text)">1% auctioneer fee</b> deducted from sale proceeds</span>
  <span>&#9888; <b style="color:var(--text)">2.5% cancellation penalty</b> after the 5-minute free window</span>
  <span>&#9888; Outbid deposits are automatically refunded to your pocket</span>
</div>

<!-- Tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap">
  <?php $tabs=['browse'=>'&#128269; Live ('.count($active).')','mine'=>'&#128230; My Listings ('.count($myListings).')','create'=>'&#43; Post Listing','history'=>'&#128196; History','myhistory'=>'&#128203; My History']; foreach ($tabs as $tid=>$tl): ?>
  <a href="index.php?p=auction&tab=<?= $tid ?>" class="au-tab <?= $tab===$tid ? 'on' : '' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ===================== BROWSE ===================== -->
<?php if ($tab === 'browse'):
  // Show ALL active listings (own ones get a "your listing" cell instead of a
  // bid form). Filtering own listings out made the Live tab look empty whenever
  // you were the only seller.
  $browseList = $active;
?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($browseList)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">No active auctions. Be the first to post.</div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:2fr 110px 120px 100px 190px;padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:700;align-items:center">
    <span>Item</span><span style="text-align:right">Starting</span><span style="text-align:right">Top Bid</span><span style="text-align:center">Ends</span><span></span>
  </div>
  <?php foreach ($browseList as $row):
    $secsLeft = (int)$row['secs_left'];
    $ended    = $secsLeft <= 0;
    $tLeft    = $ended ? 'Ended' : ($secsLeft < 3600 ? round($secsLeft/60).'m' : round($secsLeft/3600,1).'h');
    $topBid   = (int)$row['current_bid'] > 0 ? (int)$row['current_bid'] : (int)$row['starting_price'];
    $minBid   = max((int)$row['starting_price'], (int)$row['current_bid'] + 1);
    $iTop     = (int)$row['bidder_id'] === (int)$pid;
    $isOwn    = (int)$row['seller_id'] === (int)$pid;
    $hot      = (int)$row['bid_count'] >= 5;
    $ending   = !$ended && $secsLeft < 300;
  ?>
  <div class="au-row<?= $ending ? ' ending' : '' ?>" style="border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="display:grid;grid-template-columns:2fr 110px 120px 100px 190px;padding:10px 14px;align-items:center;<?= $iTop ? 'background:rgba(25,240,199,.04)' : '' ?>">
      <div>
        <div style="font-weight:700;font-size:13px"><?= e($row['item_name']) ?><?= ((int)($row['item_qty'] ?? 1)) > 1 ? ' <span style="color:var(--muted);font-weight:400">×'.(int)$row['item_qty'].'</span>' : '' ?><?php if ($hot): ?><span class="au-hot">&#128293; HOT</span><?php endif; ?><?php if ($iTop): ?><span class="au-lead">&#10003; LEADING</span><?php endif; ?></div>
        <div style="font-size:10px;color:var(--muted)">by <?= e($row['seller_name']) ?> &middot; <span data-bidcount><?= (int)$row['bid_count'] ?></span> bid<?= $row['bid_count']!==1?'s':'' ?></div>
      </div>
      <div style="text-align:right;font-size:12px;color:var(--muted)"><?= number_format($row['starting_price']) ?> cr</div>
      <div style="text-align:right;font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:var(--accent)"><?= number_format($topBid) ?> <span style="font-size:10px;font-weight:400;color:var(--muted)">cr</span></div>
      <div style="text-align:center;font-size:12px;color:var(--muted)" data-ends="<?= (int)$row['ends_unix'] ?>" class="auction-timer"><?= $tLeft ?></div>
      <div>
        <?php if ($isOwn): ?>
          <a href="index.php?p=auction&tab=mine" class="muted" style="font-size:11px;text-decoration:underline">your listing &rarr;</a>
        <?php elseif (!$ended): ?>
        <form method="post" style="margin:0;display:flex;gap:4px;align-items:center;flex-wrap:wrap" data-aufx="bid" data-au-name="<?= e($row['item_name']) ?>">
          <input type="hidden" name="action" value="bid">
          <input type="hidden" name="listing_id" value="<?= (int)$row['id'] ?>">
          <input type="number" name="bid_amount" min="<?= $minBid ?>" value="<?= $minBid ?>" class="au-bidinput" style="width:74px;padding:4px 5px;font-size:11px">
          <button type="submit" style="padding:4px 9px;font-size:11px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)">&#9889; Bid</button>
          <span style="display:flex;gap:3px;flex-basis:100%;margin-top:3px">
            <button type="button" class="au-quick" data-q="min" data-min="<?= $minBid ?>">min</button>
            <button type="button" class="au-quick" data-q="pct" data-base="<?= $topBid ?>" data-pct="5">+5%</button>
            <button type="button" class="au-quick" data-q="pct" data-base="<?= $topBid ?>" data-pct="10">+10%</button>
          </span>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===================== MY LISTINGS ===================== -->
<?php elseif ($tab === 'mine'): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($myListings)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No active listings. Post one from the Create tab.</div>
  <?php else: ?>
  <?php foreach ($myListings as $row):
    $secsLeft   = (int)$row['secs_left'];
    $createdTs  = strtotime($row['created_at']);
    $graceLeft  = max(0, AUCTION_GRACE_SEC - (int)(time() - $createdTs));
    $inGrace    = $graceLeft > 0;
    $canCancel  = (int)$row['bid_count'] === 0;
    $penalty    = (int)ceil((int)$row['starting_price'] * AUCTION_CANCEL_PCT);
    $tLeft      = $secsLeft <= 0 ? 'Ended' : ($secsLeft < 3600 ? round($secsLeft/60).'m' : round($secsLeft/3600,1).'h');
  ?>
  <div style="border-bottom:1px solid var(--line);padding:12px 14px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:220px">
        <div style="font-weight:700;font-size:13px"><?= e($row['item_name']) ?><?= ((int)($row['item_qty'] ?? 1)) > 1 ? ' <span style="color:var(--muted);font-weight:400">×'.(int)$row['item_qty'].'</span>' : '' ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">
          Start: <?= number_format($row['starting_price']) ?> cr &middot;
          Top Bid: <b style="color:var(--accent)"><?= (int)$row['current_bid'] > 0 ? number_format($row['current_bid']).' cr' : 'none' ?></b> &middot;
          <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count']!==1?'s':'' ?> &middot;
          Ends in <b class="auction-timer" data-ends="<?= (int)$row['ends_unix'] ?>"><?= $tLeft ?></b>
        </div>
        <?php if ($inGrace): ?>
          <div style="font-size:11px;color:#3bcf63;margin-top:4px">&#9989; Free cancellation: <b class="au-grace" data-grace-end="<?= $createdTs + AUCTION_GRACE_SEC ?>"><?= round($graceLeft/60,1) ?> min</b> left</div>
          <div class="au-grace-bar"><div class="au-grace-fill" data-grace-start="<?= $createdTs ?>" data-grace-end="<?= $createdTs + AUCTION_GRACE_SEC ?>" style="width:<?= round($graceLeft/AUCTION_GRACE_SEC*100) ?>%"></div></div>
        <?php elseif ($canCancel): ?>
          <div style="font-size:11px;color:var(--neon2);margin-top:2px">&#9888; Cancellation penalty: <?= number_format($penalty) ?> cr (2.5%)</div>
        <?php endif; ?>
      </div>
      <?php if ($canCancel): ?>
      <form method="post" style="margin:0" data-aufx="cancel" data-au-name="<?= e($row['item_name']) ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="listing_id" value="<?= (int)$row['id'] ?>">
        <button type="submit" style="font-size:11px;background:rgba(255,45,149,.08);border-color:rgba(255,45,149,.25);color:var(--neon2);padding:5px 12px" onclick="return confirm('Cancel this auction?')">Cancel</button>
      </form>
      <?php else: ?>
        <span style="font-size:11px;color:var(--muted);font-style:italic">Bids placed — cannot cancel</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===================== HISTORY ===================== -->
<?php elseif ($tab === 'history'): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($historyAll)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">No completed auctions yet.</div>
  <?php else: ?>
  <div style="padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:700;display:grid;grid-template-columns:2fr 100px 100px 80px 90px">
    <span>Item</span><span style="text-align:right">Start</span><span style="text-align:right">Final</span><span style="text-align:center">Status</span><span style="text-align:right">Date</span>
  </div>
  <?php foreach ($historyAll as $row):
    $sold = ($row['status']==='ended' && (int)$row['bid_count'] > 0);
  ?>
  <div class="au-row" style="display:grid;grid-template-columns:2fr 100px 100px 80px 90px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center">
    <div>
      <div style="font-weight:600"><?= e($row['item_name']) ?></div>
      <div style="font-size:10px;color:var(--muted)">Seller: <?= e($row['seller_name']) ?><?= $sold && $row['bidder_name'] ? ' · Buyer: '.e($row['bidder_name']) : '' ?></div>
    </div>
    <div style="text-align:right;color:var(--muted);font-size:12px"><?= number_format($row['starting_price']) ?> cr</div>
    <div style="text-align:right;font-weight:700;color:<?= $sold ? 'var(--accent)' : 'var(--muted)' ?>">
      <?= $sold ? number_format($row['current_bid']).' cr' : '—' ?>
    </div>
    <div style="text-align:center">
      <?php if ($row['status']==='cancelled'): ?><span style="font-size:10px;color:var(--neon2)">Cancelled</span>
      <?php elseif ($sold): ?><span style="font-size:10px;color:var(--accent)">Sold</span>
      <?php else: ?><span style="font-size:10px;color:var(--muted)">No bids</span><?php endif; ?>
    </div>
    <div style="text-align:right;font-size:11px;color:var(--muted)"><?= e(date('M j, Y', strtotime($row['created_at']))) ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===================== MY HISTORY ===================== -->
<?php elseif ($tab === 'myhistory'): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($historyMine)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">No auction history yet. Your completed and cancelled listings will appear here.</div>
  <?php else: ?>
  <div style="padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:700;display:grid;grid-template-columns:2fr 100px 110px 90px 80px">
    <span>Item</span><span style="text-align:right">Starting</span><span style="text-align:right">Final Bid</span><span style="text-align:right">Date</span><span style="text-align:center">Status</span>
  </div>
  <?php foreach ($historyMine as $row):
    $sold = ($row['status']==='ended' && (int)$row['bid_count'] > 0);
    $fee  = $sold ? (int)max(1, ceil($row['current_bid'] * AUCTION_FEE_PCT)) : 0;
  ?>
  <div class="au-row" style="display:grid;grid-template-columns:2fr 100px 110px 90px 80px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center">
    <div>
      <div style="font-weight:600"><?= e($row['item_name']) ?></div>
      <?php if ($sold): ?><div style="font-size:10px;color:var(--muted)">Buyer: <?= e($row['bidder_name'] ?? '?') ?> · You received: <b style="color:#3bcf63"><?= number_format($row['current_bid'] - $fee) ?> cr</b> (after 1% fee)</div><?php endif; ?>
    </div>
    <div style="text-align:right;color:var(--muted)"><?= number_format($row['starting_price']) ?> cr</div>
    <div style="text-align:right;font-weight:700;color:<?= $sold ? 'var(--accent)' : 'var(--muted)' ?>"><?= $sold ? number_format($row['current_bid']).' cr' : '—' ?></div>
    <div style="text-align:right;font-size:11px;color:var(--muted)"><?= e(date('M j, Y', strtotime($row['created_at']))) ?></div>
    <div style="text-align:center">
      <?php if ($row['status']==='cancelled'): ?><span style="font-size:10px;color:var(--neon2)">Cancelled</span>
      <?php elseif ($sold): ?><span style="font-size:10px;color:var(--accent)">Sold</span>
      <?php else: ?><span style="font-size:10px;color:var(--muted)">No bids</span><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===================== CREATE ===================== -->
<?php elseif ($tab === 'create'): ?>
<div class="panel">
  <p class="muted" style="font-size:12px;margin-top:0;margin-bottom:14px">
    Listing fee scales with duration and is charged upfront. Fee is lost if no one bids. 5-minute free cancel window — 2.5% penalty after. 1% auctioneer fee deducted from sale proceeds.
  </p>
  <?php if (!empty($playerInvAuction)): ?>
  <form method="post" style="max-width:440px" data-aufx="post">
    <input type="hidden" name="action" value="create">
    <div class="field">
      <span>Item</span>
      <select name="item_id" id="ap-item">
        <option value="">-- Select from inventory --</option>
        <?php foreach ($playerInvAuction as $pi): ?>
        <option value="<?= (int)$pi['item_id'] ?>" data-max="<?= (int)$pi['qty'] ?>" data-name="<?= e($pi['name']) ?>"><?= e($pi['name']) ?> &times;<?= (int)$pi['qty'] ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">
      <div class="field">
        <span>Quantity</span>
        <input type="number" name="qty" id="ap-qty" min="1" max="99" value="1">
      </div>
      <div class="field">
        <span>Starting Bid (cr)</span>
        <input type="number" name="start_price" min="1" value="100" id="ap-start">
      </div>
    </div>
    <div class="field" style="margin-top:10px">
      <span>Duration</span>
      <select name="duration" id="ap-dur">
        <option value="1">1 Hour — 2% fee</option>
        <option value="6">6 Hours — 3% fee</option>
        <option value="12">12 Hours — 4% fee</option>
        <option value="24" selected>24 Hours — 5% fee</option>
        <option value="48">48 Hours — 7% fee</option>
      </select>
    </div>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:12px;margin:12px 0;font-size:12px" id="ap-preview">
      Listing fee: calculating...
    </div>
    <button type="submit" style="border-color:rgba(255,45,149,.4);color:var(--neon2)">&#9993; Post Auction Listing</button>
  </form>
  <script>
  (function(){
    var s=document.getElementById('ap-start'),d=document.getElementById('ap-dur'),qi=document.getElementById('ap-qty'),it=document.getElementById('ap-item');
    var fees={1:0.02,6:0.03,12:0.04,24:0.05,48:0.07};
    function upd(){
      var a=parseInt(s.value)||0;var h=parseInt(d.value)||24;var pct=fees[h]||0.05;
      var fee=Math.max(1,Math.ceil(a*pct));
      var net=Math.max(0,a-Math.max(1,Math.ceil(a*0.01)));
      document.getElementById('ap-preview').innerHTML=
        '<div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Listing fee ('+(pct*100)+'%, charged now)</span><b style="color:var(--neon2)">'+fee.toLocaleString()+' cr</b></div>'
        +'<div style="display:flex;justify-content:space-between;margin-top:4px"><span style="color:var(--muted)">If it sells at the starting bid (after 1%)</span><b style="color:#3bcf63">'+net.toLocaleString()+' cr</b></div>';
    }
    if(it) it.addEventListener('change',function(){ var opt=it.options[it.selectedIndex]; if(opt&&opt.dataset.max){ qi.max=opt.dataset.max; if(parseInt(qi.value)>parseInt(opt.dataset.max)) qi.value=opt.dataset.max; }});
    s.addEventListener('input',upd);d.addEventListener('change',upd);upd();
  })();
  </script>
  <?php else: ?>
  <div style="padding:28px;text-align:center;color:var(--muted)">
    <div style="font-size:28px;margin-bottom:8px">&#128230;</div>
    Your stash is empty — auctions must be backed by a real inventory item.<br>
    <span style="font-size:12px">Hit the <a href="index.php?p=mining" style="color:var(--accent)">Sump</a> or the <a href="index.php?p=foundry" style="color:var(--accent)">Foundry</a> to find something worth selling.</span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function(){
'use strict';

/* ── Back-alley header: rain, neon, fog ───────────────────────────────── */
var auc=document.getElementById('au-canvas');
if(auc){
  var c=auc.getContext('2d');
  var AW=560, AH=112;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  auc.width=AW*dpr; auc.height=AH*dpr;
  c.scale(dpr,dpr);

  var rain=[];
  for(var i=0;i<70;i++) rain.push({x:Math.random()*AW,y:Math.random()*AH,v:2.4+Math.random()*2.6,l:7+Math.random()*9,a:.08+Math.random()*.2});
  var fog=[{x:80,v:.07,r:90},{x:340,v:-.05,r:120},{x:500,v:.04,r:80}];
  var buzzUntil=0;

  function auLoop(t){
    if(!document.body.contains(auc)) return;
    requestAnimationFrame(auLoop);
    c.clearRect(0,0,AW,AH);
    var bg=c.createLinearGradient(0,0,0,AH);
    bg.addColorStop(0,'#08060c'); bg.addColorStop(1,'#0d0812');
    c.fillStyle=bg; c.fillRect(0,0,AW,AH);

    // distant alley wall slats
    c.fillStyle='rgba(255,255,255,.025)';
    for(var w=0;w<7;w++) c.fillRect(20+w*82,18,1.5,AH-38);

    // neon sign: 黑市 + AUCTION, magenta with flicker
    var on=t>buzzUntil;
    if(on&&Math.random()<.01) buzzUntil=t+90+Math.random()*380;
    var fl=on?(.8+.2*Math.sin(t/340)):.18;
    c.shadowColor='#ff2d95'; c.shadowBlur=16*fl;
    c.fillStyle='rgba(255,45,149,'+fl+')';
    c.font='700 25px Orbitron, monospace'; c.textAlign='right'; c.textBaseline='top';
    c.fillText('黑市',AW-24,12);
    c.font='700 12px Orbitron, monospace';
    c.fillStyle='rgba(255,45,149,'+(fl*.85)+')';
    c.shadowBlur=9*fl;
    c.fillText('A U C T I O N',AW-24,44);
    c.shadowBlur=0;

    // hanging cage lamp + cone of light (center-left)
    var sway=Math.sin(t/1700)*3;
    c.strokeStyle='rgba(255,255,255,.18)';
    c.beginPath(); c.moveTo(150,0); c.lineTo(150+sway,22); c.stroke();
    c.fillStyle='#e8d44d';
    c.shadowColor='#e8d44d'; c.shadowBlur=10;
    c.beginPath(); c.arc(150+sway,25,3.4,0,Math.PI*2); c.fill();
    c.shadowBlur=0;
    var cone=c.createLinearGradient(0,25,0,AH);
    cone.addColorStop(0,'rgba(232,212,77,.10)'); cone.addColorStop(1,'rgba(232,212,77,0)');
    c.fillStyle=cone;
    c.beginPath(); c.moveTo(150+sway,25); c.lineTo(108+sway*2,AH); c.lineTo(192+sway*2,AH); c.closePath(); c.fill();
    // podium silhouette under the lamp
    c.fillStyle='#060409';
    c.fillRect(132,AH-30,36,14); c.fillRect(140,AH-44,20,14);

    // fog banks
    for(var fi=0;fi<fog.length;fi++){
      var F=fog[fi];
      F.x+=F.v; if(F.x>AW+F.r) F.x=-F.r; if(F.x<-F.r) F.x=AW+F.r;
      var fg2=c.createRadialGradient(F.x,AH-8,4,F.x,AH-8,F.r);
      fg2.addColorStop(0,'rgba(180,160,200,.05)'); fg2.addColorStop(1,'rgba(180,160,200,0)');
      c.fillStyle=fg2; c.fillRect(F.x-F.r,AH-60,F.r*2,60);
    }

    // rain
    c.strokeStyle='rgba(170,190,230,1)'; c.lineWidth=1;
    for(var ri=0;ri<rain.length;ri++){
      var R=rain[ri];
      R.y+=R.v; R.x-=R.v*.18;
      if(R.y>AH){ R.y=-R.l; R.x=Math.random()*(AW+30); }
      c.globalAlpha=R.a;
      c.beginPath(); c.moveTo(R.x,R.y); c.lineTo(R.x+R.l*.18,R.y+R.l); c.stroke();
    }
    c.globalAlpha=1;

    // ground sheen
    c.fillStyle='rgba(255,45,149,.04)';
    c.fillRect(0,AH-10,AW,10);
  }
  requestAnimationFrame(auLoop);
}

/* ── Quick-bid chips ──────────────────────────────────────────────────── */
document.querySelectorAll('.au-quick').forEach(function(btn){
  btn.addEventListener('click',function(){
    var form=btn.closest('form'); if(!form) return;
    var input=form.querySelector('.au-bidinput'); if(!input) return;
    if(btn.dataset.q==='min'){ input.value=parseInt(btn.dataset.min,10)||1; }
    else {
      var base=parseInt(btn.dataset.base,10)||0, pct=parseInt(btn.dataset.pct,10)||5;
      var v=Math.ceil(base*(1+pct/100));
      var min=parseInt(input.getAttribute('min'),10)||1;
      input.value=Math.max(min,v);
    }
    input.focus();
  });
});

/* ── Live countdowns: urgency + grace bars ────────────────────────────── */
function fmtLeft(left){
  var h=Math.floor(left/3600),m=Math.floor((left%3600)/60),s=left%60;
  if(h>0) return h+'h '+m+'m';
  if(m>0) return m+'m '+s+'s';
  return s+'s';
}
function tick(){
  var now=Math.floor(Date.now()/1000);
  document.querySelectorAll('.auction-timer').forEach(function(el){
    var ends=parseInt(el.dataset.ends)||0;
    var left=ends-now;
    if(left<=0){ el.textContent='Ended'; el.classList.remove('urgent'); return; }
    el.textContent=fmtLeft(left);
    el.classList.toggle('urgent',left<300);
  });
  document.querySelectorAll('.au-grace').forEach(function(el){
    var end=parseInt(el.dataset.graceEnd)||0;
    var left=end-now;
    el.textContent=left>0?fmtLeft(left):'expired';
  });
  document.querySelectorAll('.au-grace-fill').forEach(function(el){
    var st=parseInt(el.dataset.graceStart)||0, en=parseInt(el.dataset.graceEnd)||0;
    var pct=en>st?Math.max(0,Math.min(100,(en-now)/(en-st)*100)):0;
    el.style.width=pct+'%';
  });
}
tick(); var tickIv=setInterval(function(){
  if(!document.body.contains(document.getElementById('au-head'))){ clearInterval(tickIv); return; }
  tick();
},1000);
})();
</script>

<script>
/* Gavel FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._auFxBound) return;
  window._auFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#aufx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(6,3,8,.6);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#aufx.show{opacity:1}'
    +'.aufx-stage{position:relative;width:180px;height:140px}'
    +'.aufx-block{position:absolute;left:50%;bottom:34px;transform:translateX(-50%);width:84px;height:12px;'
    +'background:#241a2c;border-radius:3px;box-shadow:0 5px 0 -2px #1a1322,0 0 16px var(--au-col-a)}'
    +'.aufx-gavel{position:absolute;left:50%;bottom:48px;margin-left:-8px;font-size:34px;transform-origin:80% 90%;'
    +'transform:rotate(-65deg);animation:aufxSwing .34s .12s cubic-bezier(.5,0,.8,.4) forwards}'
    +'@keyframes aufxSwing{to{transform:rotate(6deg)}}'
    +'.aufx-ring{position:absolute;left:50%;bottom:40px;width:8px;height:8px;border:2px solid var(--au-col);'
    +'border-radius:50%;transform:translateX(-50%);opacity:0;animation:aufxRing .55s .42s ease-out forwards}'
    +'@keyframes aufxRing{0%{opacity:.9;width:8px;height:8px}100%{opacity:0;width:170px;height:170px;bottom:-40px}}'
    +'.aufx-label{position:absolute;left:50%;top:100%;transform:translateX(-50%);white-space:nowrap;text-align:center;'
    +'font-size:13px;font-weight:900;letter-spacing:.14em;color:var(--au-col);text-shadow:0 0 12px var(--au-col);'
    +'opacity:0;animation:aufxLbl .25s .5s forwards}'
    +'@keyframes aufxLbl{to{opacity:1}}'
    +'.aufx-sub{display:block;font-size:10px;font-weight:600;letter-spacing:.04em;color:var(--text);opacity:.75;margin-top:3px}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('auctMuted')==='1';
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
  window.toggleAuSound=function(){
    muted=!muted; localStorage.setItem('auctMuted',muted?'1':'0');
    var b=document.getElementById('au-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) sfx(660,.08,'sine',.05);
  };
  (function(){ var b=document.getElementById('au-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; })();

  function gavel(label,sub,col){
    var old=document.getElementById('aufx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='aufx';
    o.style.setProperty('--au-col',col);
    o.style.setProperty('--au-col-a',col+'44');
    o.innerHTML='<div class="aufx-stage">'
      +'<div class="aufx-gavel">&#128296;</div>'
      +'<div class="aufx-block"></div>'
      +'<div class="aufx-ring"></div>'
      +'<div class="aufx-label">'+label+(sub?'<span class="aufx-sub">'+sub+'</span>':'')+'</div>'
      +'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    setTimeout(function(){ sfx(180,.1,'square',.06); sfx(2400,.05,'sine',.04); },440); // crack
    setTimeout(function(){ sfx(523,.09,'sine',.04); setTimeout(function(){sfx(784,.14,'sine',.04);},80); },600);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},1800);
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute) return;
    var kind=f.getAttribute('data-aufx');
    if(!kind) return;
    var name=f.getAttribute('data-au-name')||'';
    if(kind==='bid'){
      var amt=parseInt((f.querySelector('input[name=bid_amount]')||{}).value,10)||0;
      gavel('BID PLACED',name+' — '+amt.toLocaleString('en-US')+' cr','#ff2d95');
    } else if(kind==='post'){
      var sel=f.querySelector('select[name=item_id]');
      var nm=sel&&sel.options[sel.selectedIndex]?(sel.options[sel.selectedIndex].getAttribute('data-name')||''):'';
      gavel('ON THE BLOCK',nm,'#e8a33d');
    } else if(kind==='cancel'){
      gavel('WITHDRAWN',name,'#8fa3c8');
    }
  },true);
})();
</script>
