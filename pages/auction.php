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
      $pdo->prepare("UPDATE auction_listings SET status='ended' WHERE id=?")->execute([$el['id']]);
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
      $dec = $pdo->prepare('UPDATE player_items SET qty=qty-? WHERE player_id=? AND item_id=? AND qty>=?');
      $dec->execute([$qty, $pid, $selItemId, $qty]);
      if ($dec->rowCount() !== 1) throw new RuntimeException('Not enough in inventory.');
      $pdo->prepare('DELETE FROM player_items WHERE player_id=? AND item_id=? AND qty<=0')->execute([$pid, $selItemId]);
      if ($itemName === '') throw new RuntimeException('Select an item from your inventory.');
      if (strlen($itemName) > 100) throw new RuntimeException('Item name too long.');
      $feePct  = $AUCTION_LISTING_FEES[$hours] ?? 0.05;
      $listFee = (int)ceil($startPx * $feePct);
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$listFee, $pid, $listFee]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits. Listing fee: ' . number_format($listFee) . ' cr.');
      $endsAt = date('Y-m-d H:i:s', time() + $hours * 3600);
      // Add item_qty column if missing (silent)
      try { $pdo->exec('ALTER TABLE auction_listings ADD COLUMN item_qty INT NOT NULL DEFAULT 1'); } catch (Throwable $e) {}
      $pdo->prepare('INSERT INTO auction_listings (seller_id, item_id, item_name, item_qty, starting_price, ends_at) VALUES (?,?,?,?,?,?)')->execute([$pid, $selItemId, $itemName, $qty, $startPx, $endsAt]);
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
      if ((int)$row['seller_id'] === $pid) { $pdo->rollBack(); throw new RuntimeException("You can't bid on your own auction."); }
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
    $msg = $ex->getMessage();
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

// ── History ────────────────────────────────────────────────────────────────
$historyAll = []; $historyMine = [];
if ($tab === 'history') {
  try { $historyAll = $pdo->query("SELECT al.*, p.username AS seller_name, b.username AS bidder_name FROM auction_listings al JOIN players p ON p.id=al.seller_id LEFT JOIN players b ON b.id=al.bidder_id WHERE al.status IN ('ended','cancelled') ORDER BY al.created_at DESC LIMIT 60")->fetchAll(); } catch (Throwable $e) {}
}
if ($tab === 'myhistory') {
  try { $q2=$pdo->prepare("SELECT al.*, b.username AS bidder_name FROM auction_listings al LEFT JOIN players b ON b.id=al.bidder_id WHERE al.seller_id=? AND al.status IN ('ended','cancelled') ORDER BY al.created_at DESC LIMIT 60"); $q2->execute([$pid]); $historyMine=$q2->fetchAll(); } catch (Throwable $e) {}
}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--neon2),var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h2 style="margin:0 0 2px">&#127748; The Black Market</h2>
        <p class="muted" style="margin:0;font-size:12px">Anonymous. Ruthless. Best prices in the Sprawl.</p>
      </div>
      <div style="font-size:12px">Pocket: <b style="color:var(--accent)"><?= number_format($player['creds_pocket']) ?> cr</b></div>
    </div>
  </div>
</div>

<!-- Fees notice -->
<div style="background:rgba(25,240,199,.04);border:1px solid rgba(25,240,199,.12);border-radius:6px;padding:9px 14px;font-size:11px;color:var(--muted);display:flex;flex-wrap:wrap;gap:12px">
  <span>&#9888; <b style="color:var(--text)">1% auctioneer fee</b> deducted from sale proceeds</span>
  <span>&#9888; <b style="color:var(--text)">2.5% cancellation penalty</b> after the 5-minute free window</span>
  <span>&#9888; Outbid deposits are automatically refunded to your pocket</span>
</div>

<!-- Tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap">
  <?php $tabs=['browse'=>'&#128269; Live ('.count($active).')','mine'=>'&#128230; My Listings ('.count($myListings).')','create'=>'&#43; Post Listing','history'=>'&#128196; History','myhistory'=>'&#128203; My History']; foreach ($tabs as $tid=>$tl): ?>
  <a href="index.php?p=auction&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? 'var(--neon2)' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(255,45,149,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? 'var(--neon2)' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ===================== BROWSE ===================== -->
<?php if ($tab === 'browse'):
  $browseList = array_filter($active, fn($r) => (int)$r['seller_id'] !== $pid);
?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($browseList)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">No active auctions. Be the first to post.</div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:2fr 120px 120px 110px 100px;padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:700;align-items:center">
    <span>Item</span><span style="text-align:right">Starting</span><span style="text-align:right">Top Bid</span><span style="text-align:center">Ends</span><span></span>
  </div>
  <?php foreach ($browseList as $row):
    $secsLeft = (int)$row['secs_left'];
    $ended    = $secsLeft <= 0;
    $tLeft    = $ended ? 'Ended' : ($secsLeft < 3600 ? round($secsLeft/60).'m' : round($secsLeft/3600,1).'h');
    $topBid   = (int)$row['current_bid'] > 0 ? (int)$row['current_bid'] : (int)$row['starting_price'];
    $minBid   = max((int)$row['starting_price'], (int)$row['current_bid'] + 1);
    $iTop     = (int)$row['bidder_id'] === $pid;
  ?>
  <div style="border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="display:grid;grid-template-columns:2fr 120px 120px 110px 100px;padding:10px 14px;align-items:center;<?= $iTop ? 'background:rgba(25,240,199,.04)' : '' ?>">
      <div>
        <div style="font-weight:700;font-size:13px"><?= e($row['item_name']) ?></div>
        <div style="font-size:10px;color:var(--muted)">by <?= e($row['seller_name']) ?> &middot; <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count']!==1?'s':'' ?></div>
        <?php if ($iTop): ?><div style="font-size:10px;color:var(--accent)">&#10003; You're leading</div><?php endif; ?>
      </div>
      <div style="text-align:right;font-size:12px;color:var(--muted)"><?= number_format($row['starting_price']) ?> cr</div>
      <div style="text-align:right;font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:var(--accent)"><?= number_format($topBid) ?> <span style="font-size:10px;font-weight:400;color:var(--muted)">cr</span></div>
      <div style="text-align:center;font-size:12px;color:<?= $secsLeft < 3600 && !$ended ? 'var(--neon2)' : 'var(--muted)' ?>" data-ends="<?= (int)$row['ends_unix'] ?>" class="auction-timer"><?= $tLeft ?></div>
      <div>
        <?php if (!$ended): ?>
        <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
          <input type="hidden" name="action" value="bid">
          <input type="hidden" name="listing_id" value="<?= (int)$row['id'] ?>">
          <input type="number" name="bid_amount" min="<?= $minBid ?>" value="<?= $minBid ?>" style="width:70px;padding:4px 5px;font-size:11px">
          <button type="submit" style="padding:4px 8px;font-size:11px;background:rgba(25,240,199,.1);border-color:rgba(25,240,199,.3);color:var(--accent)">Bid</button>
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
    $graceLeft  = max(0, AUCTION_GRACE_SEC - (int)(time() - strtotime($row['created_at'])));
    $inGrace    = $graceLeft > 0;
    $canCancel  = (int)$row['bid_count'] === 0;
    $penalty    = (int)ceil((int)$row['starting_price'] * AUCTION_CANCEL_PCT);
    $tLeft      = $secsLeft <= 0 ? 'Ended' : ($secsLeft < 3600 ? round($secsLeft/60).'m' : round($secsLeft/3600,1).'h');
  ?>
  <div style="border-bottom:1px solid var(--line);padding:12px 14px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-weight:700;font-size:13px"><?= e($row['item_name']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">
          Start: <?= number_format($row['starting_price']) ?> cr &middot;
          Top Bid: <b style="color:var(--accent)"><?= (int)$row['current_bid'] > 0 ? number_format($row['current_bid']).' cr' : 'none' ?></b> &middot;
          <?= (int)$row['bid_count'] ?> bid<?= $row['bid_count']!==1?'s':'' ?>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">Ends in: <b><?= $tLeft ?></b></div>
        <?php if ($inGrace): ?>
          <div style="font-size:11px;color:#3bcf63;margin-top:2px">&#9989; Free cancellation window: <?= round($graceLeft/60,1) ?> min left</div>
        <?php elseif ($canCancel): ?>
          <div style="font-size:11px;color:var(--neon2);margin-top:2px">&#9888; Cancellation penalty: <?= number_format($penalty) ?> cr (2.5%)</div>
        <?php endif; ?>
      </div>
      <?php if ($canCancel): ?>
      <form method="post" style="margin:0">
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
  <div style="display:grid;grid-template-columns:2fr 100px 100px 80px 90px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center">
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
  <div style="display:grid;grid-template-columns:2fr 100px 110px 90px 80px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center">
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
  <form method="post" style="max-width:440px">
    <input type="hidden" name="action" value="create">
    <div class="field">
      <span>Item</span>
      <?php if (!empty($playerInvAuction)): ?>
      <select name="item_id" id="ap-item">
        <option value="">-- Select from inventory --</option>
        <?php foreach ($playerInvAuction as $pi): ?>
        <option value="<?= (int)$pi['item_id'] ?>" data-max="<?= (int)$pi['qty'] ?>"><?= e($pi['name']) ?> &times;<?= (int)$pi['qty'] ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="hidden" name="item_id" value="0">
      <input type="text" name="item_name" maxlength="100" placeholder="No items in inventory — enter listing name">
      <?php endif; ?>
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
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:10px;margin:12px 0;font-size:12px;color:var(--muted)" id="ap-preview">
      Listing fee: calculating...
    </div>
    <button type="submit">&#9993; Post Auction Listing</button>
  </form>
  <script>
  (function(){
    var s=document.getElementById('ap-start'),d=document.getElementById('ap-dur'),qi=document.getElementById('ap-qty'),it=document.getElementById('ap-item');
    var fees={1:0.02,6:0.03,12:0.04,24:0.05,48:0.07};
    function upd(){
      var a=parseInt(s.value)||0;var h=parseInt(d.value)||24;var pct=fees[h]||0.05;
      var fee=Math.max(1,Math.ceil(a*pct));
      document.getElementById('ap-preview').innerHTML='Listing fee: <b style="color:var(--neon2)">'+fee.toLocaleString()+' cr</b> ('+(pct*100)+'% of starting price) — charged now from your pocket.';
    }
    if(it) it.addEventListener('change',function(){ var opt=it.options[it.selectedIndex]; if(opt&&opt.dataset.max){ qi.max=opt.dataset.max; if(parseInt(qi.value)>parseInt(opt.dataset.max)) qi.value=opt.dataset.max; }});
    s.addEventListener('input',upd);d.addEventListener('change',upd);upd();
  })();
  </script>
</div>
<?php endif; ?>

<script>
// Live countdown timers
(function(){
  function tick(){
    document.querySelectorAll('.auction-timer').forEach(function(el){
      var ends=parseInt(el.dataset.ends)||0;
      var left=ends-Math.floor(Date.now()/1000);
      if(left<=0){el.textContent='Ended';return;}
      var h=Math.floor(left/3600),m=Math.floor((left%3600)/60),s=left%60;
      if(h>0) el.textContent=h+'h '+m+'m';
      else if(m>0) el.textContent=m+'m '+s+'s';
      else el.textContent=s+'s';
    });
  }
  tick(); setInterval(tick,1000);
})();
</script>
