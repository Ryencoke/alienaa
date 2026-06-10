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

// Gather game-activity metrics for price drift
$gm = ['active'=>0, 'sales1h'=>0, 'avgDrivePct'=>0.5, 'bids1h'=>0, 'bankSum'=>0, 'lowDrive'=>0, 'combats1h'=>0];
try {
  $gm['active']      = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= NOW() - INTERVAL 30 MINUTE")->fetchColumn();
  $gm['avgDrivePct'] = max(0, min(1, (float)$pdo->query("SELECT COALESCE(AVG(cycles / GREATEST(cycles_max,1)),0.5) FROM players WHERE last_seen >= NOW() - INTERVAL 30 MINUTE")->fetchColumn()));
  try { $gm['sales1h'] = (int)$pdo->query("SELECT COUNT(*) FROM market_sales WHERE sold_at >= NOW() - INTERVAL 1 HOUR")->fetchColumn(); } catch(Throwable $e){}
  try { $gm['bids1h']  = (int)$pdo->query("SELECT COUNT(*) FROM auction_bids WHERE placed_at >= NOW() - INTERVAL 1 HOUR")->fetchColumn(); } catch(Throwable $e){}
  try { $gm['combats1h'] = (int)$pdo->query("SELECT COUNT(*) FROM combat_log WHERE created_at >= NOW() - INTERVAL 1 HOUR")->fetchColumn(); } catch(Throwable $e){}
  $gm['bankSum']     = (float)$pdo->query("SELECT COALESCE(SUM(creds_bank),0) FROM players")->fetchColumn();
  $gm['lowDrive']    = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE cycles < cycles_max * 0.2 AND last_seen >= NOW() - INTERVAL 30 MINUTE")->fetchColumn();
} catch (Throwable $e) {}
$actScore = min(1.0, $gm['active'] / 20.0);

// Market fluctuation driven by game activity — log price every ~5 minutes
try {
  $allStocks = $pdo->query("SELECT id, ticker, price FROM stocks")->fetchAll();
  $upd = $pdo->prepare('UPDATE stocks SET prev_price=price, price=?, trend=SIGN(?-price) WHERE id=?');
  $logPrice = $pdo->prepare('INSERT INTO stock_price_log (stock_id, price) VALUES (?,?)');
  $lastLog  = (int)($pdo->query("SELECT MAX(UNIX_TIMESTAMP(recorded_at)) FROM stock_price_log")->fetchColumn() ?: 0);
  $doLog    = (time() - $lastLog) >= 300;
  if ($doLog) foreach ($allStocks as $s) {
    $base = (mt_rand(-8, 8) / 1000); // ±0.8% base noise
    $boost = 0;
    switch ($s['ticker']) {
      case 'NXUS': case 'DATV': $boost = ($actScore - 0.5) * 0.025; break;   // tech: rises with player activity
      case 'ARMX':              $boost = ($gm['combats1h'] > 0 ? 0.015 : -0.005) + (mt_rand(-5,10)/1000); break; // weapons: combat-linked
      case 'CHEM': case 'PHRS': $boost = ($gm['combats1h'] > 0 ? 0.01 : 0) + ($actScore - 0.4) * 0.015; break;  // pharma/chem: conflict
      case 'NRGY':              $boost = (1 - $gm['avgDrivePct']) * 0.03 - 0.005; break;  // energy: rises when drive is low
      case 'GRDX':              $boost = min(0.04, $gm['sales1h'] * 0.004 + $gm['bids1h'] * 0.002); break;       // grid exchange: trading volume
      case 'CRED':              $boost = 0.002 + min(0.008, $gm['bankSum'] / 50000000 * 0.01); break;             // bank: steady + bank balance
      case 'INFX':              $boost = ($gm['combats1h'] > 5 ? 0.02 : -0.003) + (mt_rand(-8,8)/1000); break;   // security: crime waves
      case 'SCRP':              $boost = ($actScore > 0.5 ? 0.01 : -0.01) + (mt_rand(-15,20)/1000); break;       // scraps: boom/bust
      default:                  $boost = ($actScore - 0.5) * 0.01;
    }
    $pct = $base + $boost;
    $np  = max(5, (int)round($s['price'] * (1 + $pct)));
    $upd->execute([$np, $np, $s['id']]);
    if ($doLog) $logPrice->execute([$s['id'], $np]);
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

$stockInfo = [
  'NXUS' => ['desc'=>'Nexus Corp controls most of the grid infrastructure. Prices rise with tech demand and server activity. Sensitive to Datacore/hacking news.',       'trend'=>'Volatile — spikes during high network activity'],
  'ARMX' => ['desc'=>'ArmaTech Industries manufactures weapons and combat gear. Demand surges after PvP events and conflict cycles in the Sprawl.',                    'trend'=>'Cyclical — rises after conflict, dips in peace'],
  'PHRS' => ['desc'=>'Pharmasynth Co produces stims and biochem compounds. Stock climbs when Synthesis Den activity is high and compound supplies run low.',            'trend'=>'Moderate growth — stable base with demand spikes'],
  'NRGY' => ['desc'=>'NeonGrid Energy powers the whole city. Prices track server load and Drive usage across the grid. Low volatility, steady returns.',                'trend'=>'Low volatility — consistent dividend payer'],
  'DATV' => ['desc'=>'DataVault Ltd secures encrypted data caches. Rises when hacking/netrunning activity increases and breach attempts spike.',                       'trend'=>'Volatile — correlates with hack/intrusion events'],
  'SCRP' => ['desc'=>'Scrapyard Holdings runs salvage operations in the outer sectors. Cheap stock with sudden spikes when rare materials surface.',                   'trend'=>'Low base, high spike potential — boom or bust'],
  'CHEM' => ['desc'=>'StreetChem Inc distributes chemicals and combat stims. Steady performer that rises when conflict in the Sprawl is high.',                         'trend'=>'Moderate — follows conflict and synth activity'],
  'CRED' => ['desc'=>'CreditFlow Bank holds the debt of half the city. Stable but slow-growing. The safest store of value on the exchange.',                           'trend'=>'Very stable — slow upward drift over time'],
  'INFX' => ['desc'=>'Infect-X Security contracts city defense and prison systems. Profits spike when crime rates rise and incarceration increases.',                   'trend'=>'Spikes during crime waves and conflict cycles'],
  'GRDX' => ['desc'=>'Grid Exchange is the exchange itself — meta stock. Rises with overall trading volume and market activity. Self-referential.',                    'trend'=>'Moderate — follows overall market volume'],
];

// Load up to 7 days of price history per stock
$priceHistory = [];
try {
  $hq = $pdo->query("SELECT stock_id, price, recorded_at FROM stock_price_log WHERE recorded_at >= NOW() - INTERVAL 7 DAY ORDER BY recorded_at ASC");
  foreach ($hq as $row) { $priceHistory[$row['stock_id']][] = (int)$row['price']; }
} catch (Throwable $e) {}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8d44d,var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h2 style="margin:0 0 2px">&#128200; Stock Exchange</h2>
        <p class="muted" style="margin:0;font-size:12px">Game-tied stocks. Prices update every 5 minutes. 1% brokerage fee on all trades.</p>
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
    $info    = $stockInfo[$s['ticker']] ?? ['desc'=>'','trend'=>''];
    $hist    = $priceHistory[$s['id']] ?? [];
  ?>
  <div style="border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="display:grid;grid-template-columns:1fr 80px 80px 80px 130px;align-items:center;gap:4px;padding:10px 14px;cursor:pointer" onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='none'?'block':'none'">
      <div>
        <span style="font-family:'Orbitron',sans-serif;font-size:11px;font-weight:700;color:<?= $catCol ?>"><?= e($s['ticker']) ?></span>
        <span style="font-size:12px;color:var(--text);margin-left:6px"><?= e($s['name']) ?></span>
        <?php if ($hold): ?><span style="font-size:10px;color:var(--accent);margin-left:4px">(<?= number_format($hold['shares']) ?> owned)</span><?php endif; ?>
        <span style="font-size:10px;color:var(--muted);margin-left:4px">&#9660;</span>
      </div>
      <div style="text-align:right;font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;color:var(--text)"><?= number_format($s['price']) ?></div>
      <div style="text-align:right;font-size:11px;font-weight:700;color:<?= $diffCol ?>"><?= $diff >= 0 ? '+' : '' ?><?= $diffPct ?>%</div>
      <div style="text-align:right;font-size:11px;color:var(--muted)"><?= number_format($s['prev_price']) ?></div>
      <div onclick="event.stopPropagation()">
        <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
          <input type="hidden" name="action" value="buy">
          <input type="hidden" name="stock_id" value="<?= (int)$s['id'] ?>">
          <input type="number" name="qty" value="1" min="1" style="width:48px;padding:3px 5px;font-size:11px">
          <button type="submit" style="padding:4px 10px;font-size:11px;color:#3bcf63;border-color:rgba(59,207,99,.3);background:rgba(59,207,99,.08)">Buy</button>
        </form>
      </div>
    </div>
    <!-- Detail panel -->
    <div style="display:none;padding:12px 14px 14px;background:var(--panel2);border-top:1px solid rgba(255,255,255,.04)">
      <div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start">
        <div>
          <div style="font-size:12px;color:var(--text);margin-bottom:6px;line-height:1.5"><?= e($info['desc']) ?></div>
          <?php if ($info['trend']): ?>
          <div style="font-size:11px"><span style="color:var(--muted)">Trend: </span><span style="color:#e8d44d"><?= e($info['trend']) ?></span></div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">Category: <span style="color:<?= $catCol ?>"><?= ucfirst($s['category']) ?></span></div>
        </div>
        <?php if (count($hist) >= 2): ?>
        <div>
          <div style="font-size:10px;color:var(--muted);margin-bottom:4px;text-align:center">7-Day Price</div>
          <?php
            $mn = min($hist); $mx = max($hist); $rng = max(1, $mx - $mn);
            $w = 120; $h = 40; $pts = '';
            $n = count($hist);
            for ($xi = 0; $xi < $n; $xi++) {
              $x = (int)round($xi / max(1, $n-1) * ($w-2)) + 1;
              $y = (int)round($h - 2 - ($hist[$xi] - $mn) / $rng * ($h-4));
              $pts .= ($xi===0?'M':'L') . "{$x},{$y} ";
            }
            $trend_color = $hist[count($hist)-1] >= $hist[0] ? '#3bcf63' : 'var(--neon2)';
          ?>
          <svg width="<?= $w ?>" height="<?= $h ?>" style="display:block;overflow:visible">
            <path d="<?= trim($pts) ?>" fill="none" stroke="<?= $trend_color ?>" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
          </svg>
          <div style="font-size:9px;color:var(--muted);text-align:center"><?= number_format($hist[0]) ?> → <?= number_format($hist[count($hist)-1]) ?></div>
        </div>
        <?php else: ?>
        <div style="font-size:10px;color:var(--muted);text-align:center;padding:8px">Not enough<br>history yet</div>
        <?php endif; ?>
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
  <div style="display:flex;flex-direction:column;gap:10px">
    <?php foreach ($portfolio as $h):
      $currentVal = (int)$h['price'] * (int)$h['shares'];
      $costBasis  = (int)$h['avg_buy_price'] * (int)$h['shares'];
      $pl = $currentVal - $costBasis;
      $plCol = $pl >= 0 ? '#3bcf63' : 'var(--neon2)';
      $catCol = $catColors[$h['category']] ?? 'var(--muted)';
      $hist = $priceHistory[$h['stock_id']] ?? [];
      $plPct = $costBasis > 0 ? round(($pl / $costBasis) * 100, 1) : 0;
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $pl >= 0 ? 'rgba(59,207,99,.2)' : 'rgba(255,45,149,.2)' ?>;border-radius:8px;padding:14px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div style="flex:1;min-width:160px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:<?= $catCol ?>"><?= e($h['ticker']) ?></span>
            <span style="font-size:12px;color:var(--text)"><?= e($h['name']) ?></span>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px"><?= number_format($h['shares']) ?> shares &middot; avg buy <b style="color:#e8d44d"><?= number_format($h['avg_buy_price']) ?></b> cr &middot; now <b style="color:var(--text)"><?= number_format($h['price']) ?></b> cr</div>
          <div style="display:flex;align-items:center;gap:16px">
            <div>
              <div style="font-size:14px;font-weight:700;color:var(--text)"><?= number_format($currentVal) ?> cr</div>
              <div style="font-size:11px;color:<?= $plCol ?>"><?= $pl>=0?'+':'' ?><?= number_format($pl) ?> cr &nbsp;<span style="opacity:.7">(<?= $pl>=0?'+':'' ?><?= $plPct ?>%)</span></div>
            </div>
            <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
              <input type="hidden" name="action" value="sell">
              <input type="hidden" name="stock_id" value="<?= (int)$h['stock_id'] ?>">
              <input type="number" name="qty" value="1" min="1" max="<?= (int)$h['shares'] ?>" style="width:52px;padding:3px 6px;font-size:11px">
              <button type="submit" style="padding:4px 10px;font-size:11px;color:var(--neon2);border-color:rgba(255,45,149,.3);background:rgba(255,45,149,.08)">Sell</button>
            </form>
          </div>
        </div>
        <?php if (count($hist) >= 2):
          $buyPx = (int)$h['avg_buy_price'];
          $allPts = array_merge($hist, [$buyPx]);
          $mn = min($allPts); $mx = max($allPts); $rng = max(1, $mx - $mn);
          $cw = 150; $ch = 52;
          $pts = ''; $n = count($hist);
          for ($xi = 0; $xi < $n; $xi++) {
            $x = (int)round($xi / max(1,$n-1) * ($cw-2)) + 1;
            $y = (int)round($ch - 2 - ($hist[$xi] - $mn) / $rng * ($ch-4));
            $pts .= ($xi===0?'M':'L')."{$x},{$y} ";
          }
          $buyY = (int)round($ch - 2 - ($buyPx - $mn) / $rng * ($ch-4));
          $trendCol = $hist[count($hist)-1] >= $hist[0] ? '#3bcf63' : 'var(--neon2)';
        ?>
        <div style="flex:none;text-align:right">
          <svg width="<?= $cw ?>" height="<?= $ch ?>" style="display:block;overflow:visible;border-radius:4px">
            <rect width="<?= $cw ?>" height="<?= $ch ?>" fill="rgba(0,0,0,.2)" rx="3"/>
            <line x1="1" y1="<?= $buyY ?>" x2="<?= $cw-1 ?>" y2="<?= $buyY ?>" stroke="#e8d44d" stroke-width="1" stroke-dasharray="3 2" opacity="0.7"/>
            <path d="<?= trim($pts) ?>" fill="none" stroke="<?= $trendCol ?>" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
          </svg>
          <div style="font-size:9px;color:var(--muted);margin-top:3px">
            <span style="color:#e8d44d">&#9135; cost basis</span>
            &nbsp;
            <span style="color:<?= $trendCol ?>">&#9135; price</span>
          </div>
        </div>
        <?php else: ?>
        <div style="flex:none;width:150px;height:52px;display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--muted);border:1px solid var(--line);border-radius:4px">No history yet</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
