<?php /* pages/stockex.php — Stock Exchange */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Auto-create tables
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS stocks (
    id INT AUTO_INCREMENT PRIMARY KEY, ticker VARCHAR(8) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL, category VARCHAR(40) NOT NULL DEFAULT 'general',
    price INT NOT NULL DEFAULT 100, prev_price INT NOT NULL DEFAULT 100,
    trend TINYINT NOT NULL DEFAULT 0,
    INDEX idx_ticker (ticker)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS stock_holdings (
    player_id INT NOT NULL, stock_id INT NOT NULL, shares INT NOT NULL DEFAULT 0,
    avg_buy_price INT NOT NULL DEFAULT 0,
    PRIMARY KEY (player_id, stock_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS stock_price_log (
    id INT AUTO_INCREMENT PRIMARY KEY, stock_id INT NOT NULL,
    price INT NOT NULL, recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_time (stock_id, recorded_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Seed stocks if empty
try {
  $count = (int)$pdo->query("SELECT COUNT(*) FROM stocks")->fetchColumn();
  if ($count === 0) {
    $seeds = [
      ['NXUS','Nexus Corp','tech',500],['ARMX','ArmaTech Industries','weapons',320],
      ['PHRS','Pharmasynth Co','pharma',180],['NRGY','NeonGrid Energy','energy',240],
      ['DATV','DataVault Ltd','tech',410],['SCRP','Scrapyard Holdings','manufacturing',90],
      ['CHEM','StreetChem Inc','pharma',150],['CRED','CreditFlow Bank','finance',600],
      ['INFX','Infect-X Security','security',280],['GRDX','Grid Exchange','finance',380],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO stocks (ticker,name,category,price,prev_price) VALUES (?,?,?,?,?)');
    foreach ($seeds as $s) $ins->execute([$s[0],$s[1],$s[2],$s[3],$s[3]]);
  }
} catch (Throwable $e) {}

// Simulate market fluctuation on each page load (max ±3%)
try {
  $allStocks = $pdo->query("SELECT id, price FROM stocks")->fetchAll();
  $upd = $pdo->prepare('UPDATE stocks SET prev_price=price, price=?, trend=SIGN(?-price) WHERE id=?');
  foreach ($allStocks as $s) {
    $pct   = (mt_rand(-30, 30) / 1000);
    $np    = max(5, (int)round($s['price'] * (1 + $pct)));
    $upd->execute([$np, $np, $s['id']]);
  }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'buy') {
      $sid   = (int)($_POST['stock_id'] ?? 0);
      $qty   = max(1, (int)($_POST['qty'] ?? 1));
      $qs    = $pdo->prepare('SELECT id,name,ticker,price FROM stocks WHERE id=?'); $qs->execute([$sid]); $stock = $qs->fetch();
      if (!$stock) throw new RuntimeException('Stock not found.');
      $cost  = (int)$stock['price'] * $qty;
      $fee   = (int)max(1, ceil($cost * 0.01)); // 1% brokerage fee
      $total = $cost + $fee;

      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$total, $pid, $total]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits in pocket (incl. 1% fee).'); }

      // Upsert holdings with weighted avg price
      $qh = $pdo->prepare('SELECT shares, avg_buy_price FROM stock_holdings WHERE player_id=? AND stock_id=?');
      $qh->execute([$pid, $sid]); $hold = $qh->fetch();
      if ($hold) {
        $newShares = (int)$hold['shares'] + $qty;
        $newAvg    = (int)round(((int)$hold['avg_buy_price'] * (int)$hold['shares'] + (int)$stock['price'] * $qty) / $newShares);
        $pdo->prepare('UPDATE stock_holdings SET shares=?, avg_buy_price=? WHERE player_id=? AND stock_id=?')->execute([$newShares, $newAvg, $pid, $sid]);
      } else {
        $pdo->prepare('INSERT INTO stock_holdings (player_id, stock_id, shares, avg_buy_price) VALUES (?,?,?,?)')->execute([$pid, $sid, $qty, (int)$stock['price']]);
      }
      $pdo->commit();
      $msg = 'Bought ' . $qty . 'x ' . $stock['ticker'] . ' for ' . number_format($total) . ' credits (incl. fee).';
      $player = current_player();

    } elseif ($act === 'sell') {
      $sid  = (int)($_POST['stock_id'] ?? 0);
      $qty  = max(1, (int)($_POST['qty'] ?? 1));
      $qh   = $pdo->prepare('SELECT h.shares, s.price, s.ticker, s.name FROM stock_holdings h JOIN stocks s ON s.id=h.stock_id WHERE h.player_id=? AND h.stock_id=?');
      $qh->execute([$pid, $sid]); $hold = $qh->fetch();
      if (!$hold || (int)$hold['shares'] < $qty) throw new RuntimeException('Not enough shares to sell.');

      $gross = (int)$hold['price'] * $qty;
      $fee   = (int)max(1, ceil($gross * 0.01));
      $net   = $gross - $fee;

      $pdo->beginTransaction();
      $newShares = (int)$hold['shares'] - $qty;
      if ($newShares <= 0) {
        $pdo->prepare('DELETE FROM stock_holdings WHERE player_id=? AND stock_id=?')->execute([$pid, $sid]);
      } else {
        $pdo->prepare('UPDATE stock_holdings SET shares=? WHERE player_id=? AND stock_id=?')->execute([$newShares, $pid, $sid]);
      }
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$net, $pid]);
      $pdo->commit();
      $msg = 'Sold ' . $qty . 'x ' . $hold['ticker'] . ' for ' . number_format($net) . ' credits (after fee).';
      $player = current_player();
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$tab = in_array($_GET['tab'] ?? '', ['market','portfolio']) ? $_GET['tab'] : 'market';
$stocks = $pdo->query("SELECT * FROM stocks ORDER BY name ASC")->fetchAll();
$portfolio = [];
try {
  $qp = $pdo->prepare("SELECT h.*, s.ticker, s.name, s.price, s.prev_price, s.trend, s.category FROM stock_holdings h JOIN stocks s ON s.id = h.stock_id WHERE h.player_id = ? ORDER BY s.name");
  $qp->execute([$pid]); $portfolio = $qp->fetchAll();
} catch (Throwable $e) {}

$catColors = ['tech'=>'var(--accent)','weapons'=>'var(--neon2)','pharma'=>'#3bcf63','energy'=>'#e8a33d','manufacturing'=>'#c9d1e0','finance'=>'#e8d44d','security'=>'var(--neon2)','general'=>'var(--muted)'];
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8d44d,var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h2 style="margin:0 0 2px">&#128200; Stock Exchange</h2>
        <p class="muted" style="margin:0;font-size:12px">Game-tied stocks. Prices shift every page load. 1% brokerage fee on all trades.</p>
      </div>
      <div style="font-size:12px">Pocket: <b style="color:var(--accent)"><?= number_format($player['creds_pocket']) ?> cr</b></div>
    </div>
  </div>
</div>

<div style="display:flex;gap:8px">
  <?php foreach (['market'=>'&#128202; Market','portfolio'=>'&#128218; My Portfolio ('.count($portfolio).')'] as $tid=>$tl): ?>
  <a href="index.php?p=stockex&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? '#e8d44d' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(232,212,77,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? '#e8d44d' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($tab === 'market'): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="display:grid;grid-template-columns:1fr 80px 80px 80px 130px;padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line);font-weight:700">
    <span>Company</span><span style="text-align:right">Price</span><span style="text-align:right">Change</span><span style="text-align:right">Prev</span><span></span>
  </div>
  <?php foreach ($stocks as $s):
    $diff    = (int)$s['price'] - (int)$s['prev_price'];
    $diffPct = $s['prev_price'] > 0 ? round($diff / $s['prev_price'] * 100, 1) : 0;
    $diffCol = $diff > 0 ? '#3bcf63' : ($diff < 0 ? 'var(--neon2)' : 'var(--muted)');
    $catCol  = $catColors[$s['category']] ?? 'var(--muted)';
    $hold    = null; foreach ($portfolio as $ph) { if ($ph['stock_id'] == $s['id']) { $hold = $ph; break; } }
  ?>
  <div style="border-bottom:1px solid rgba(255,255,255,.04);padding:10px 14px">
    <div style="display:grid;grid-template-columns:1fr 80px 80px 80px 130px;align-items:center;gap:4px">
      <div>
        <span style="font-family:'Orbitron',sans-serif;font-size:11px;font-weight:700;color:<?= $catCol ?>"><?= e($s['ticker']) ?></span>
        <span style="font-size:12px;color:var(--text);margin-left:6px"><?= e($s['name']) ?></span>
        <?php if ($hold): ?><span style="font-size:10px;color:var(--accent);margin-left:4px">(<?= number_format($hold['shares']) ?> owned)</span><?php endif; ?>
      </div>
      <div style="text-align:right;font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;color:var(--text)"><?= number_format($s['price']) ?></div>
      <div style="text-align:right;font-size:11px;font-weight:700;color:<?= $diffCol ?>"><?= $diff >= 0 ? '+' : '' ?><?= $diffPct ?>%</div>
      <div style="text-align:right;font-size:11px;color:var(--muted)"><?= number_format($s['prev_price']) ?></div>
      <div>
        <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
          <input type="hidden" name="action" value="buy">
          <input type="hidden" name="stock_id" value="<?= (int)$s['id'] ?>">
          <input type="number" name="qty" value="1" min="1" style="width:48px;padding:3px 5px;font-size:11px">
          <button type="submit" style="padding:4px 10px;font-size:11px;color:#3bcf63;border-color:rgba(59,207,99,.3);background:rgba(59,207,99,.08)">Buy</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: // portfolio tab ?>
<div class="panel">
  <?php if (empty($portfolio)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No holdings. Buy shares from the Market tab.</div>
  <?php else:
    $totalValue = 0; $totalCost = 0;
    foreach ($portfolio as $h) { $totalValue += (int)$h['price'] * (int)$h['shares']; $totalCost += (int)$h['avg_buy_price'] * (int)$h['shares']; }
    $totalPL = $totalValue - $totalCost;
    $plCol = $totalPL >= 0 ? '#3bcf63' : 'var(--neon2)';
  ?>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:var(--panel2);border:1px solid var(--line);border-radius:7px">
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:var(--accent)"><?= number_format($totalValue) ?> cr</div><div style="font-size:10px;color:var(--muted)">Portfolio Value</div></div>
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:<?= $plCol ?>"><?= $totalPL>=0?'+':'' ?><?= number_format($totalPL) ?> cr</div><div style="font-size:10px;color:var(--muted)">Unrealized P/L</div></div>
  </div>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($portfolio as $h):
      $currentVal = (int)$h['price'] * (int)$h['shares'];
      $costBasis  = (int)$h['avg_buy_price'] * (int)$h['shares'];
      $pl = $currentVal - $costBasis;
      $plCol = $pl >= 0 ? '#3bcf63' : 'var(--neon2)';
      $catCol = $catColors[$h['category']] ?? 'var(--muted)';
    ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:12px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <div>
          <span style="font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;color:<?= $catCol ?>"><?= e($h['ticker']) ?></span>
          <span style="font-size:13px;color:var(--text);margin-left:6px"><?= e($h['name']) ?></span>
          <div style="font-size:11px;color:var(--muted);margin-top:3px">
            <?= number_format($h['shares']) ?> shares &middot; avg <?= number_format($h['avg_buy_price']) ?> cr &middot; now <?= number_format($h['price']) ?> cr
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-weight:700;color:var(--text)"><?= number_format($currentVal) ?> cr</div>
          <div style="font-size:11px;color:<?= $plCol ?>"><?= $pl>=0?'+':'' ?><?= number_format($pl) ?> cr P/L</div>
        </div>
        <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
          <input type="hidden" name="action" value="sell">
          <input type="hidden" name="stock_id" value="<?= (int)$h['stock_id'] ?>">
          <input type="number" name="qty" value="1" min="1" max="<?= (int)$h['shares'] ?>" style="width:52px;padding:3px 6px;font-size:11px">
          <button type="submit" style="padding:4px 10px;font-size:11px;color:var(--neon2);border-color:rgba(255,45,149,.3);background:rgba(255,45,149,.08)">Sell</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
