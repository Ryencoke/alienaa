<?php /* pages/stockex.php — Stock Exchange */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

// Auto-create tables
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS stocks (
    id INT AUTO_INCREMENT PRIMARY KEY, ticker VARCHAR(8) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL, category VARCHAR(40) NOT NULL DEFAULT 'general',
    price INT NOT NULL DEFAULT 100, prev_price INT NOT NULL DEFAULT 100,
    trend TINYINT NOT NULL DEFAULT 0,
    INDEX idx_ticker (ticker)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try { $pdo->exec("ALTER TABLE stocks ADD COLUMN shares_available INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE stocks ADD COLUMN shares_total INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
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
  // Aggregate buy/sell volume — no player identity needed, this only powers
  // the detail page's volume chart/stats.
  $pdo->exec("CREATE TABLE IF NOT EXISTS stock_trade_log (
    id INT AUTO_INCREMENT PRIMARY KEY, stock_id INT NOT NULL,
    side ENUM('buy','sell') NOT NULL, qty INT NOT NULL, price INT NOT NULL,
    traded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_time (stock_id, traded_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Seed stocks if empty. Every ticker carries a flat 1,000,000-share float —
// scarcity was tuned much tighter at first (thin floats on riskier tickers)
// but that made "sold out" too easy to hit, so it's now a uniform large pool
// across the board; shares_available can still trend toward zero on a very
// popular ticker, it just takes real sustained demand to get there.
const SX_SHARE_FLOAT = 1000000;
// Long-run anchor prices — the original listing prices, single source of truth
// for both seeding a fresh install and the drift loop's mean reversion (which
// pulls each ticker gently back toward its anchor so a persistently positive
// driver compounds toward a bounded equilibrium instead of minting credits
// forever — sell payouts have no counterparty).
const SX_ANCHORS = ['NXUS'=>500,'ARMX'=>320,'PHRS'=>180,'NRGY'=>240,'DATV'=>410,
                    'SCRP'=>90,'CHEM'=>150,'CRED'=>600,'INFX'=>280,'GRDX'=>380];
try {
  $count = (int)$pdo->query("SELECT COUNT(*) FROM stocks")->fetchColumn();
  if ($count === 0) {
    $seeds = [
      ['NXUS','Nexus Corp','tech'],['ARMX','ArmaTech Industries','weapons'],
      ['PHRS','Pharmasynth Co','pharma'],['NRGY','NeonGrid Energy','energy'],
      ['DATV','DataVault Ltd','tech'],['SCRP','Scrapyard Holdings','manufacturing'],
      ['CHEM','StreetChem Inc','pharma'],['CRED','CreditFlow Bank','finance'],
      ['INFX','Infect-X Security','security'],['GRDX','Grid Exchange','finance'],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO stocks (ticker,name,category,price,prev_price,shares_total,shares_available) VALUES (?,?,?,?,?,?,?)');
    foreach ($seeds as $s) $ins->execute([$s[0],$s[1],$s[2],SX_ANCHORS[$s[0]],SX_ANCHORS[$s[0]],SX_SHARE_FLOAT,SX_SHARE_FLOAT]);
  }
  // Bump every existing ticker's float up to the new 1,000,000 flat size —
  // preserves how much has already been bought (shares_available only rises
  // by the same delta the total rose by, so no one's holdings look "returned").
  // This also covers rows from before shares_total existed at all (the
  // ALTER above defaults new columns to 0, which shares_total < 1,000,000
  // already catches) — no separate zero-backfill needed.
  $curTotals = $pdo->query("SELECT id, shares_total, shares_available FROM stocks WHERE shares_total < " . SX_SHARE_FLOAT)->fetchAll();
  if ($curTotals) {
    $bump = $pdo->prepare("UPDATE stocks SET shares_available = shares_available + ?, shares_total = ? WHERE id = ?");
    foreach ($curTotals as $ct) $bump->execute([SX_SHARE_FLOAT - (int)$ct['shares_total'], SX_SHARE_FLOAT, $ct['id']]);
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

// Deterministic per-ticker price driver: [$boost, $reasonText]. This is the
// single source of truth for "why is this stock moving" — used both by the
// price-drift loop below (which adds its own per-tick random jitter on top
// of this same $boost; a few tickers carry an extra bit of local jitter,
// preserved separately right where it's applied) and by the detail page's
// live explanation, so the mechanic and the explanation can never drift
// apart. Keep this in sync with the price-drift loop's jitter block if the
// per-ticker logic ever changes.
function sx_stock_boost(string $ticker, array $gm, float $actScore): array {
  switch ($ticker) {
    case 'NXUS': case 'DATV':
      $b = ($actScore - 0.5) * 0.025;
      $r = $actScore > 0.5
        ? 'Player activity is running high right now, and this stock tracks network and server load directly.'
        : 'Player activity is quiet right now, and demand for grid infrastructure follows the crowd.';
      return [$b, $r];
    case 'ARMX':
      $b = $gm['combats1h'] > 0 ? 0.015 : -0.005;
      $r = $gm['combats1h'] > 0
        ? 'Recent PvP activity in the last hour is driving demand for weapons and combat gear.'
        : 'No recent combat activity in the last hour to drive weapons demand.';
      return [$b, $r];
    case 'CHEM': case 'PHRS':
      $b = ($gm['combats1h'] > 0 ? 0.01 : 0) + ($actScore - 0.4) * 0.015;
      $r = $gm['combats1h'] > 0
        ? 'Conflict in the Sprawl over the last hour is pushing demand for stims and combat chemistry.'
        : 'Quiet streets right now mean quiet demand for stims and street chemistry.';
      return [$b, $r];
    case 'NRGY':
      $b = (1 - $gm['avgDrivePct']) * 0.03 - 0.005;
      $r = $gm['avgDrivePct'] < 0.5
        ? 'Average Drive reserves across active players are running low — energy demand is climbing with it.'
        : 'Drive reserves across active players are healthy right now, so energy demand is soft.';
      return [$b, $r];
    case 'GRDX':
      $b = min(0.04, $gm['sales1h'] * 0.004 + $gm['bids1h'] * 0.002);
      $r = ($gm['sales1h'] + $gm['bids1h']) > 0
        ? 'Overall market trading volume — bazaar sales plus auction bids — is elevated over the last hour.'
        : 'Market trading volume is quiet right now, so this exchange-tracking stock has little to feed on.';
      return [$b, $r];
    case 'CRED':
      // Two-sided: positive only when citywide banked wealth is above ~15M cr.
      // The old version was 0.002 + min(0.008, ...) — strictly positive every
      // tick, which (with no counterparty on sells) made buy-and-hold CRED an
      // unbounded credit printer.
      $b = min(0.008, $gm['bankSum'] / 50000000 * 0.01) - 0.003;
      $r = $b >= 0
        ? 'Citywide banked wealth is deep right now, buoying the bank\'s book — a slow, steady climb.'
        : 'Citywide banked wealth is thin right now, and the bank\'s book sags gently with it.';
      return [$b, $r];
    case 'INFX':
      $b = $gm['combats1h'] > 5 ? 0.02 : -0.003;
      $r = $gm['combats1h'] > 5
        ? 'A spike in combat activity over the last hour reads as a crime wave — security contracts are in demand.'
        : 'No crime wave right now, so security demand is soft.';
      return [$b, $r];
    case 'SCRP':
      $b = $actScore > 0.5 ? 0.01 : -0.01;
      $r = $actScore > 0.5
        ? 'High player activity means more salvage moving through the yards — still volatile either way.'
        : 'Player activity is low, so salvage flow has gone quiet — still volatile either way.';
      return [$b, $r];
    default:
      $b = ($actScore - 0.5) * 0.01;
      $r = $actScore > 0.5
        ? 'General player activity is elevated right now, giving this stock a mild lift.'
        : 'General player activity is quiet right now, giving this stock a mild drag.';
      return [$b, $r];
  }
}

// The actual raw game-economy numbers behind sx_stock_boost()'s prose
// explanation — same per-ticker mapping, just surfaced as real stats
// instead of a single sentence. Returns [['label'=>..,'value'=>..,'good'=>bool], ...].
// 'good' drives the stat's color (green if it's currently pushing the price
// up, muted if it's dragging it down) — deliberately mirrors the same
// condition each ticker's case in sx_stock_boost() branches on.
function sx_stock_factors(string $ticker, array $gm, float $actScore): array {
  $activePct = round($actScore * 100);
  switch ($ticker) {
    case 'NXUS': case 'DATV':
      return [
        ['label'=>'Active Players (30m)', 'value'=>number_format($gm['active']), 'good'=>$actScore > 0.5],
        ['label'=>'Network Load Index',   'value'=>$activePct.'%',               'good'=>$actScore > 0.5],
      ];
    case 'ARMX':
      return [['label'=>'PvP Fights (1h)', 'value'=>number_format($gm['combats1h']), 'good'=>$gm['combats1h'] > 0]];
    case 'CHEM': case 'PHRS':
      return [
        ['label'=>'PvP Fights (1h)',    'value'=>number_format($gm['combats1h']), 'good'=>$gm['combats1h'] > 0],
        ['label'=>'Player Activity',    'value'=>$activePct.'%',                  'good'=>$actScore > 0.4],
      ];
    case 'NRGY':
      $drivePct = round($gm['avgDrivePct'] * 100);
      return [['label'=>'Avg. Drive Reserves', 'value'=>$drivePct.'%', 'good'=>$gm['avgDrivePct'] < 0.5]];
    case 'GRDX':
      return [
        ['label'=>'Bazaar Sales (1h)',  'value'=>number_format($gm['sales1h']), 'good'=>$gm['sales1h'] > 0],
        ['label'=>'Auction Bids (1h)',  'value'=>number_format($gm['bids1h']),  'good'=>$gm['bids1h'] > 0],
      ];
    case 'CRED':
      return [['label'=>'Total Banked Wealth', 'value'=>number_format($gm['bankSum']).' cr', 'good'=>$gm['bankSum'] > 15000000]];
    case 'INFX':
      return [['label'=>'PvP Fights (1h)', 'value'=>number_format($gm['combats1h']), 'good'=>$gm['combats1h'] > 5]];
    case 'SCRP':
      return [['label'=>'Player Activity', 'value'=>$activePct.'%', 'good'=>$actScore > 0.5]];
    default:
      return [['label'=>'Player Activity', 'value'=>$activePct.'%', 'good'=>$actScore > 0.5]];
  }
}

// Market fluctuation driven by game activity — log price every ~5 minutes
try {
  $allStocks = $pdo->query("SELECT id, ticker, price FROM stocks")->fetchAll();
  $upd = $pdo->prepare('UPDATE stocks SET prev_price=price, price=? WHERE id=?');
  $logPrice = $pdo->prepare('INSERT INTO stock_price_log (stock_id, price) VALUES (?,?)');
  $lastLog  = (int)($pdo->query("SELECT MAX(UNIX_TIMESTAMP(recorded_at)) FROM stock_price_log")->fetchColumn() ?: 0);
  $doLog    = (time() - $lastLog) >= 300;
  if ($doLog) foreach ($allStocks as $s) {
    $base = (mt_rand(-8, 8) / 1000); // ±0.8% base noise
    [$boost, ] = sx_stock_boost($s['ticker'], $gm, $actScore);
    // A few tickers carry their own extra local jitter on top of the shared driver.
    switch ($s['ticker']) {
      case 'ARMX': $boost += (mt_rand(-5,10)/1000); break;   // weapons: combat-linked
      case 'INFX': $boost += (mt_rand(-8,8)/1000); break;    // security: crime waves
      case 'SCRP': $boost += (mt_rand(-15,20)/1000); break;  // scraps: boom/bust
    }
    $pct = $base + $boost;
    // Mean reversion toward the ticker's anchor price, proportional to how far
    // it has strayed. Self-limiting in both directions: a sustained positive
    // driver settles at a bounded premium over anchor (e.g. CRED's max
    // +0.5%/tick equilibrates ~25% above) rather than compounding forever,
    // and a crashed ticker gets an equivalent lift back up.
    $anchor = SX_ANCHORS[$s['ticker']] ?? 0;
    if ($anchor > 0) $pct -= 0.02 * (($s['price'] - $anchor) / $anchor);
    $np  = max(5, min(2000000000, (int)round($s['price'] * (1 + $pct))));
    $upd->execute([$np, $s['id']]);
    $logPrice->execute([$s['id'], $np]);
  }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'buy') {
      $sid   = (int)($_POST['stock_id'] ?? 0);
      $qty   = min(1000000, max(1, (int)($_POST['qty'] ?? 1)));
      $qs    = $pdo->prepare('SELECT id,name,ticker,price,shares_available FROM stocks WHERE id=?'); $qs->execute([$sid]); $stock = $qs->fetch();
      if (!$stock) throw new RuntimeException('Stock not found.');
      if ($qty > (int)$stock['shares_available']) throw new RuntimeException('Only ' . number_format((int)$stock['shares_available']) . ' shares of ' . $stock['ticker'] . ' available right now.');
      $cost  = (int)$stock['price'] * $qty;
      $fee   = (int)max(1, ceil($cost * 0.01)); // 1% brokerage fee
      $total = $cost + $fee;

      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$total, $pid, $total]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits in pocket (incl. 1% fee).'); }

      // Reserve the shares — atomically re-checks availability so two
      // simultaneous buyers can't both claim the last of a thin float.
      $rs = $pdo->prepare('UPDATE stocks SET shares_available = shares_available - ? WHERE id = ? AND shares_available >= ?');
      $rs->execute([$qty, $sid, $qty]);
      if ($rs->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough shares available — try a smaller quantity.'); }

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
      $pdo->prepare('INSERT INTO stock_trade_log (stock_id, side, qty, price) VALUES (?,?,?,?)')->execute([$sid, 'buy', $qty, (int)$stock['price']]);
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
      $dec = $pdo->prepare('UPDATE stock_holdings SET shares = shares - ? WHERE player_id=? AND stock_id=? AND shares >= ?');
      $dec->execute([$qty, $pid, $sid, $qty]);
      if ($dec->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough shares to sell.'); }
      $pdo->prepare('DELETE FROM stock_holdings WHERE player_id=? AND stock_id=? AND shares <= 0')->execute([$pid, $sid]);
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$net, $pid]);
      // Sold shares return to the available float — capped at the ticker's
      // total supply so it can never overshoot past what was ever issued.
      $pdo->prepare('UPDATE stocks SET shares_available = LEAST(shares_total, shares_available + ?) WHERE id=?')->execute([$qty, $sid]);
      $pdo->prepare('INSERT INTO stock_trade_log (stock_id, side, qty, price) VALUES (?,?,?,?)')->execute([$sid, 'sell', $qty, (int)$hold['price']]);
      $pdo->commit();
      $msg = 'Sold ' . $qty . 'x ' . $hold['ticker'] . ' for ' . number_format($net) . ' credits (after fee).';
      $player = current_player();
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

$tab = in_array($_GET['tab'] ?? '', ['market','portfolio','detail']) ? $_GET['tab'] : 'market';
$stocks = $pdo->query("SELECT * FROM stocks ORDER BY name ASC")->fetchAll();
$portfolio = [];
try {
  $qp = $pdo->prepare("SELECT h.*, s.ticker, s.name, s.price, s.prev_price, s.trend, s.category FROM stock_holdings h JOIN stocks s ON s.id = h.stock_id WHERE h.player_id = ? ORDER BY s.name");
  $qp->execute([$pid]); $portfolio = $qp->fetchAll();
} catch (Throwable $e) {}

$catColors = ['tech'=>'#19f0c7','weapons'=>'#ff2d95','pharma'=>'#3bcf63','energy'=>'#e8a33d','manufacturing'=>'#c9d1e0','finance'=>'#e8d44d','security'=>'#ff6b35','general'=>'#8fa3c8'];

$stockInfo = [
  'NXUS' => ['desc'=>'Nexus Corp controls most of the grid infrastructure. Prices rise with tech demand and server activity. Sensitive to Datacore/hacking news.',       'trend'=>'Volatile — spikes during high network activity'],
  'ARMX' => ['desc'=>'ArmaTech Industries manufactures weapons and combat gear. Demand surges after PvP events and conflict cycles in the Sprawl.',                    'trend'=>'Cyclical — rises after conflict, dips in peace'],
  'PHRS' => ['desc'=>'Pharmasynth Co produces stims and biochem compounds. Stock climbs when Synthesis Den activity is high and compound supplies run low.',            'trend'=>'Moderate growth — stable base with demand spikes'],
  'NRGY' => ['desc'=>'NeonGrid Energy powers the whole city. Prices track server load and Drive usage across the grid. Low volatility, steady returns.',                'trend'=>'Low volatility — consistent dividend payer'],
  'DATV' => ['desc'=>'DataVault Ltd secures encrypted data caches. Rises when hacking/netrunning activity increases and breach attempts spike.',                       'trend'=>'Volatile — correlates with hack/intrusion events'],
  'SCRP' => ['desc'=>'Scrapyard Holdings runs salvage operations in the outer sectors. Cheap stock with sudden spikes when rare materials surface.',                   'trend'=>'Low base, high spike potential — boom or bust'],
  'CHEM' => ['desc'=>'StreetChem Inc distributes chemicals and combat stims. Steady performer that rises when conflict in the Sprawl is high.',                         'trend'=>'Moderate — follows conflict and synth activity'],
  'CRED' => ['desc'=>'CreditFlow Bank holds the debt of half the city. Stable but slow-growing. The safest store of value on the exchange.',                           'trend'=>'Very stable — hugs its long-run value, drifting with citywide banked wealth'],
  'INFX' => ['desc'=>'Infect-X Security contracts city defense and prison systems. Profits spike when crime rates rise and incarceration increases.',                   'trend'=>'Spikes during crime waves and conflict cycles'],
  'GRDX' => ['desc'=>'Grid Exchange is the exchange itself — meta stock. Rises with overall trading volume and market activity. Self-referential.',                    'trend'=>'Moderate — follows overall market volume'],
];

// Load up to 7 days of price history per stock
$priceHistory = [];
try {
  $hq = $pdo->query("SELECT stock_id, price, recorded_at FROM stock_price_log WHERE recorded_at >= NOW() - INTERVAL 7 DAY ORDER BY recorded_at ASC");
  foreach ($hq as $row) { $priceHistory[$row['stock_id']][] = (int)$row['price']; }
} catch (Throwable $e) {}

// Downsample a price series to at most $max points (for inline sparklines)
function sx_downsample(array $h, int $max = 40): array {
  $n = count($h);
  if ($n <= $max) return $h;
  $out = [];
  for ($i = 0; $i < $max; $i++) $out[] = $h[(int)floor($i * ($n - 1) / ($max - 1))];
  return $out;
}
// Inline SVG sparkline path
function sx_sparkpath(array $h, int $w, int $ht): string {
  $mn = min($h); $mx = max($h); $rng = max(1, $mx - $mn);
  $n = count($h); $pts = '';
  for ($xi = 0; $xi < $n; $xi++) {
    $x = round($xi / max(1, $n - 1) * ($w - 2), 1) + 1;
    $y = round($ht - 2 - ($h[$xi] - $mn) / $rng * ($ht - 4), 1);
    $pts .= ($xi === 0 ? 'M' : 'L') . "{$x},{$y} ";
  }
  return trim($pts);
}

// Bucket ordered [['price'=>int,'recorded_at'=>string], ...] rows into daily
// OHLC candles. Bucketed in PHP (not SQL GROUP_CONCAT) since ~288 rows/day/
// stock would risk silently truncating past MySQL's default
// group_concat_max_len (1024 bytes) if done that way.
function sx_daily_candles(array $rows): array {
  $byDay = [];
  foreach ($rows as $r) {
    $d = substr($r['recorded_at'], 0, 10);
    $byDay[$d][] = (int)$r['price'];
  }
  $out = [];
  foreach ($byDay as $d => $prices) {
    $out[] = ['d' => $d, 'o' => $prices[0], 'h' => max($prices), 'l' => min($prices), 'c' => end($prices)];
  }
  return $out;
}

// Inline SVG candlestick chart, TradingView-style: right-side price axis
// with gridlines, a dashed current-price line, date ticks along the bottom,
// and a buy/sell volume strip underneath — same hand-rolled style as
// sx_sparkpath(), no charting library in this codebase.
// $volByDay: ['Y-m-d' => ['buy'=>int,'sell'=>int]]. $dateTicks: how many
// evenly-spaced date labels to draw along the bottom (0 = none, used for
// the small inline preview; the popup passes more).
function sx_render_candles(array $candles, array $volByDay, int $w, int $h, int $dateTicks = 0): string {
  $n = count($candles);
  if ($n < 2) return '';
  $axisW = 56; $plotW = $w - $axisW;
  $volH = 36; $dateH = $dateTicks > 0 ? 18 : 0;
  $chartH = $h - $volH - $dateH - 8;
  $allPrices = [];
  foreach ($candles as $c) { $allPrices[] = $c['h']; $allPrices[] = $c['l']; }
  $mn = min($allPrices); $mx = max($allPrices);
  $pad = max(1, ($mx - $mn) * 0.1); $mn -= $pad; $mx += $pad; // headroom so wicks never touch the frame edge
  $rng = max(1, $mx - $mn);
  $slot = $plotW / $n; $bodyW = max(2, min(12, $slot * 0.62));

  $maxVol = 1;
  foreach ($volByDay as $v) $maxVol = max($maxVol, (int)$v['buy'], (int)$v['sell']);

  $y = fn($p) => round($chartH - ($p - $mn) / $rng * $chartH, 1);
  $cx = fn($i) => round($i * $slot + $slot / 2, 1);

  $svg = '';

  // Horizontal price gridlines + right-axis labels (4 evenly spaced levels) —
  // drawn first, behind everything else, kept faint so they read as a grid
  // rather than competing with the candles for attention.
  $levels = 4;
  for ($li = 0; $li <= $levels; $li++) {
    $p = $mn + $rng * $li / $levels;
    $ly = $y($p);
    $svg .= '<line x1="0" y1="'.$ly.'" x2="'.$plotW.'" y2="'.$ly.'" stroke="rgba(255,255,255,.05)" stroke-width="1"/>';
    $svg .= '<text x="'.($plotW+7).'" y="'.($ly+3).'" font-size="9" fill="#6b7490" font-family="monospace">'.number_format((int)round($p)).'</text>';
  }

  // Candles + their per-day volume bars
  foreach ($candles as $i => $c) {
    $x = $cx($i);
    $col = $c['c'] >= $c['o'] ? '#3bcf63' : '#ff3b6f';
    $svg .= '<line x1="'.$x.'" y1="'.$y($c['h']).'" x2="'.$x.'" y2="'.$y($c['l']).'" stroke="'.$col.'" stroke-width="1.2"/>';
    $bodyTop = $y(max($c['o'], $c['c']));
    $bodyBot = $y(min($c['o'], $c['c']));
    $bodyH   = max(1.5, $bodyBot - $bodyTop);
    $svg .= '<rect x="'.round($x - $bodyW/2,1).'" y="'.$bodyTop.'" width="'.round($bodyW,1).'" height="'.$bodyH.'" fill="'.$col.'" rx="1"/>';

    $v = $volByDay[$c['d']] ?? ['buy'=>0,'sell'=>0];
    $buyH  = round(($v['buy']  / $maxVol) * ($volH/2 - 2), 1);
    $sellH = round(($v['sell'] / $maxVol) * ($volH/2 - 2), 1);
    $volBaseY = $chartH + 8 + $volH/2;
    if ($buyH  > 0) $svg .= '<rect x="'.round($x - $bodyW/2,1).'" y="'.round($volBaseY - $buyH,1).'" width="'.round($bodyW,1).'" height="'.$buyH.'" fill="#3bcf63" opacity="0.7" rx="1"/>';
    if ($sellH > 0) $svg .= '<rect x="'.round($x - $bodyW/2,1).'" y="'.$volBaseY.'" width="'.round($bodyW,1).'" height="'.$sellH.'" fill="#ff3b6f" opacity="0.7" rx="1"/>';
  }

  // Current price — dashed line + a small pinned label, drawn ON TOP of the
  // candles (unlike before) so it reads as an overlay marker, not another
  // competing shape at the same visual weight.
  $lastClose = end($candles)['c'];
  $cpy = $y($lastClose);
  $cpCol = $lastClose >= $candles[0]['o'] ? '#3bcf63' : '#ff3b6f';
  $svg .= '<line x1="0" y1="'.$cpy.'" x2="'.$plotW.'" y2="'.$cpy.'" stroke="'.$cpCol.'" stroke-width="1" stroke-dasharray="4 3" opacity="0.55"/>';
  $svg .= '<rect x="'.$plotW.'" y="'.($cpy-7).'" width="'.$axisW.'" height="14" rx="2" fill="'.$cpCol.'"/>';
  $svg .= '<text x="'.($plotW+6).'" y="'.($cpy+3).'" font-size="9" font-weight="700" fill="#05050c" font-family="monospace">'.number_format((int)$lastClose).'</text>';

  // Volume-section divider + baseline
  $volBaseline = $chartH + 8 + $volH/2;
  $svg .= '<line x1="0" y1="'.($chartH+2).'" x2="'.$plotW.'" y2="'.($chartH+2).'" stroke="rgba(232,212,77,.15)" stroke-width="1"/>';
  $svg .= '<line x1="0" y1="'.$volBaseline.'" x2="'.$plotW.'" y2="'.$volBaseline.'" stroke="rgba(255,255,255,.08)" stroke-width="1"/>';

  // Date ticks along the very bottom (popup view only — dateTicks=0 skips
  // this). The most recent date is always labeled separately (right-anchored
  // so it can't run past the chart edge); periodic ticks close enough to
  // collide with it are skipped rather than drawn on top of it.
  if ($dateTicks > 0) {
    $dateY = $chartH + $volH + $dateH;
    $step = max(1, (int)floor(($n - 1) / max(1, $dateTicks - 1)));
    $minGapPx = 30; // approx width of a "Mon DD" label at this font size
    for ($i = 0; $i < $n; $i += $step) {
      if ($cx($n - 1) - $cx($i) < $minGapPx) break; // too close to the final label — stop early
      $x = $cx($i);
      $svg .= '<text x="'.$x.'" y="'.($dateY-2).'" font-size="9" fill="#6b7490" font-family="monospace" text-anchor="middle">'.e(date('M j', strtotime($candles[$i]['d']))).'</text>';
    }
    $lastX = $cx($n - 1);
    $svg .= '<text x="'.$lastX.'" y="'.($dateY-2).'" font-size="9" fill="#6b7490" font-family="monospace" text-anchor="end">'.e(date('M j', strtotime(end($candles)['d']))).'</text>';
  }

  return $svg;
}

// Sprawl Composite index — average of each stock's history normalised to its start
$composite = [];
$histLens = array_map('count', array_filter($priceHistory, fn($h) => count($h) >= 2));
if (!empty($histLens)) {
  $cLen = min(120, min($histLens));
  if ($cLen >= 2) {
    for ($i = 0; $i < $cLen; $i++) {
      $sum = 0; $n2 = 0;
      foreach ($priceHistory as $h) {
        if (count($h) < 2) continue;
        $hh = sx_downsample($h, $cLen);
        if ($hh[0] > 0) { $sum += $hh[$i] / $hh[0]; $n2++; }
      }
      if ($n2 > 0) $composite[] = round($sum / $n2 * 100, 2);
    }
  }
}

// Ticker tape data (real prices + change)
$tickerData = [];
foreach ($stocks as $s) {
  $d = (int)$s['price'] - (int)$s['prev_price'];
  $tickerData[] = ['t'=>$s['ticker'], 'p'=>(int)$s['price'],
    'pct'=>$s['prev_price'] > 0 ? round($d / $s['prev_price'] * 100, 1) : 0];
}

// ── Detail page data (tab=detail&id=N) ──
$detailStock = null; $detailCandles = []; $detailVolByDay = []; $detailReason = ''; $detailFactors = []; $detailHold = null;
$detailVol24h = ['buy'=>0,'sell'=>0]; $detailVol7d = ['buy'=>0,'sell'=>0];
if ($tab === 'detail') {
  $did = (int)($_GET['id'] ?? 0);
  if ($did > 0) {
    $dq = $pdo->prepare('SELECT * FROM stocks WHERE id=?'); $dq->execute([$did]); $detailStock = $dq->fetch();
  }
  if (!$detailStock) { $tab = 'market'; } else {
    try {
      $pq = $pdo->prepare("SELECT price, recorded_at FROM stock_price_log WHERE stock_id=? AND recorded_at >= NOW() - INTERVAL 30 DAY ORDER BY recorded_at ASC");
      $pq->execute([$detailStock['id']]);
      $detailCandles = sx_daily_candles($pq->fetchAll());
    } catch (Throwable $e) {}
    try {
      $vq = $pdo->prepare("SELECT DATE(traded_at) d, side, SUM(qty) q FROM stock_trade_log WHERE stock_id=? AND traded_at >= NOW() - INTERVAL 30 DAY GROUP BY DATE(traded_at), side");
      $vq->execute([$detailStock['id']]);
      foreach ($vq as $row) {
        if (!isset($detailVolByDay[$row['d']])) $detailVolByDay[$row['d']] = ['buy'=>0,'sell'=>0];
        $detailVolByDay[$row['d']][$row['side']] = (int)$row['q'];
      }
      $v24 = $pdo->prepare("SELECT side, COALESCE(SUM(qty),0) q FROM stock_trade_log WHERE stock_id=? AND traded_at >= NOW() - INTERVAL 24 HOUR GROUP BY side");
      $v24->execute([$detailStock['id']]);
      foreach ($v24 as $row) $detailVol24h[$row['side']] = (int)$row['q'];
      $v7 = $pdo->prepare("SELECT side, COALESCE(SUM(qty),0) q FROM stock_trade_log WHERE stock_id=? AND traded_at >= NOW() - INTERVAL 7 DAY GROUP BY side");
      $v7->execute([$detailStock['id']]);
      foreach ($v7 as $row) $detailVol7d[$row['side']] = (int)$row['q'];
    } catch (Throwable $e) {}
    [, $detailReason] = sx_stock_boost($detailStock['ticker'], $gm, $actScore);
    $detailFactors = sx_stock_factors($detailStock['ticker'], $gm, $actScore);
    foreach ($portfolio as $ph) { if ($ph['stock_id'] == $detailStock['id']) { $detailHold = $ph; break; } }
  }
}
?>
<style>
#sx-canvas{display:block;width:100%;height:130px;border-radius:9px 9px 0 0}
#sx-head h2{text-shadow:0 0 14px rgba(232,212,77,.4)}
.sx-tab{padding:7px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s}
.sx-tab.on{border-color:#e8d44d;background:rgba(232,212,77,.1);color:#e8d44d;box-shadow:0 0 10px rgba(232,212,77,.14)}
.sx-row{transition:background .12s}
.sx-row:hover{background:rgba(232,212,77,.03)}
/* Fixed-width, monospaced so every row's badge starts/ends at the same x —
   the old variable-width inline-block pill inside a right-aligned column
   made the Change column look ragged/misaligned row to row. */
.sx-pill{display:inline-flex;align-items:center;justify-content:flex-end;gap:3px;width:64px;font-family:'Orbitron',sans-serif;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;box-sizing:border-box}
.sx-pill.up{color:#3bcf63;background:rgba(59,207,99,.1);border:1px solid rgba(59,207,99,.3)}
.sx-pill.down{color:var(--neon2);background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3)}
.sx-pill.flat{color:var(--muted);border:1px solid var(--line)}
@keyframes sxUp{0%{background:rgba(59,207,99,.14)}100%{background:transparent}}
@keyframes sxDown{0%{background:rgba(255,45,149,.10)}100%{background:transparent}}
.sx-row.just-up{animation:sxUp 1.6s ease-out}
.sx-row.just-down{animation:sxDown 1.6s ease-out}
.sx-cost{font-size:9px;color:var(--muted);margin-top:2px;white-space:nowrap}
.sx-holding{transition:transform .12s,box-shadow .15s}
.sx-holding:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.3)}
.sx-alloc{display:flex;height:10px;border-radius:5px;overflow:hidden;border:1px solid var(--line)}
.sx-alloc>div{height:100%}
/* Market list — column header + row grid share this template so nothing drifts */
.sx-grid{display:grid;grid-template-columns:1fr 80px 90px 74px 150px;align-items:center;gap:4px}
.sx-avail{font-size:10px;color:var(--muted);margin-top:2px}
.sx-avail b{color:var(--text)}
.sx-avail.sold-out{color:var(--neon2)}
/* Detail page — chart terminal look */
.sx-chart-panel{background:#08070d;border:1px solid rgba(232,212,77,.15);border-radius:8px;padding:14px}
.sx-stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.sx-stat{padding:10px 14px;border-right:1px solid var(--line);background:var(--panel2)}
.sx-stat:last-child{border-right:none}
.sx-stat .lbl{font-size:9px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:3px}
.sx-stat .val{font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:var(--text)}
.sx-order-panel{background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:14px;position:sticky;top:10px}
</style>

<!-- Header -->
<div class="panel" id="sx-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="sx-canvas"></canvas>
    <div style="position:absolute;left:16px;top:10px;pointer-events:none">
      <h2 style="margin:0">Stock Exchange</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Game-tied stocks. Prices update every 5 minutes. 1% brokerage fee on all trades.</p>
    </div>
    <?php if (count($composite) >= 2): ?>
    <div style="position:absolute;left:16px;bottom:22px;pointer-events:none">
      <span style="font-size:9px;letter-spacing:.12em;color:var(--muted)">SPRAWL COMPOSITE</span>
      <b style="font-family:'Orbitron',sans-serif;font-size:13px;color:<?= end($composite) >= 100 ? '#3bcf63' : 'var(--neon2)' ?>;margin-left:6px"><?= number_format(end($composite), 2) ?></b>
      <span style="font-size:10px;color:<?= end($composite) >= $composite[0] ? '#3bcf63' : 'var(--neon2)' ?>"><?= end($composite) >= $composite[0] ? '▲' : '▼' ?> <?= abs(round((end($composite) - $composite[0]) / max(.01, $composite[0]) * 100, 2)) ?>% / 7d</span>
    </div>
    <?php endif; ?>
    <button id="sx-mute" onclick="toggleSxSound()" title="Toggle sound" style="position:absolute;top:8px;right:14px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px">
  <?php foreach (['market'=>'&#128202; Market','portfolio'=>'&#128218; My Portfolio ('.count($portfolio).')'] as $tid=>$tl): ?>
  <a href="index.php?p=stockex&tab=<?= $tid ?>" class="sx-tab <?= $tab===$tid ? 'on' : '' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($tab === 'market'):
  $gainers = $stocks; usort($gainers, fn($a,$b) => (($b['prev_price']>0?($b['price']-$b['prev_price'])/$b['prev_price']:0)) <=> (($a['prev_price']>0?($a['price']-$a['prev_price'])/$a['prev_price']:0)));
  $topGainer = $gainers[0] ?? null; $topLoser = end($gainers) ?: null;
?>
<?php if ($topGainer && $topLoser): ?>
<div class="sx-stat-row" style="margin-bottom:14px">
  <div class="sx-stat">
    <div class="lbl">Top Gainer</div>
    <div class="val" style="color:#3bcf63"><?= e($topGainer['ticker']) ?> <span style="font-size:11px">&#9650;<?= abs(round(((int)$topGainer['price']-(int)$topGainer['prev_price'])/max(1,(int)$topGainer['prev_price'])*100,1)) ?>%</span></div>
  </div>
  <div class="sx-stat">
    <div class="lbl">Top Loser</div>
    <div class="val" style="color:var(--neon2)"><?= e($topLoser['ticker']) ?> <span style="font-size:11px">&#9660;<?= abs(round(((int)$topLoser['price']-(int)$topLoser['prev_price'])/max(1,(int)$topLoser['prev_price'])*100,1)) ?>%</span></div>
  </div>
  <div class="sx-stat">
    <div class="lbl">Listed Tickers</div>
    <div class="val"><?= count($stocks) ?></div>
  </div>
  <div class="sx-stat">
    <div class="lbl">Brokerage Fee</div>
    <div class="val" style="color:#e8d44d">1%</div>
  </div>
</div>
<?php endif; ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div class="sx-grid" style="padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line);font-weight:700">
    <span>Company</span><span style="text-align:center">7d</span><span style="text-align:right">Price</span><span style="text-align:right">Change</span><span></span>
  </div>
  <?php foreach ($stocks as $s):
    $diff    = (int)$s['price'] - (int)$s['prev_price'];
    $diffPct = $s['prev_price'] > 0 ? round($diff / $s['prev_price'] * 100, 1) : 0;
    $catCol  = $catColors[$s['category']] ?? '#8fa3c8';
    $hold    = null; foreach ($portfolio as $ph) { if ($ph['stock_id'] == $s['id']) { $hold = $ph; break; } }
    $hist    = $priceHistory[$s['id']] ?? [];
    $spark   = count($hist) >= 2 ? sx_downsample($hist, 40) : [];
    $sparkCol = $spark ? (end($spark) >= $spark[0] ? '#3bcf63' : '#ff2d95') : '#8fa3c8';
    $rowAnim = $diff > 0 ? ' just-up' : ($diff < 0 ? ' just-down' : '');
    $avail   = (int)$s['shares_available'];
    $soldOut = $avail <= 0;
  ?>
  <div style="border-bottom:1px solid rgba(255,255,255,.04)">
    <div class="sx-row sx-grid<?= $rowAnim ?>" data-sx-id="<?= (int)$s['id'] ?>" style="padding:10px 14px;cursor:pointer" onclick="window.location.href='index.php?p=stockex&tab=detail&id=<?= (int)$s['id'] ?>'">
      <div>
        <div><span style="font-family:'Orbitron',sans-serif;font-size:11px;font-weight:700;color:<?= $catCol ?>"><?= e($s['ticker']) ?></span>
        <span style="font-size:12px;color:var(--text);margin-left:6px"><?= e($s['name']) ?></span>
        <?php if ($hold): ?><span style="font-size:10px;color:var(--accent);margin-left:4px">(<?= number_format($hold['shares']) ?> owned)</span><?php endif; ?></div>
        <div class="sx-avail<?= $soldOut ? ' sold-out' : '' ?>"><?= $soldOut ? 'Sold out' : '<b>'.number_format($avail).'</b> available' ?></div>
      </div>
      <div style="text-align:center">
        <?php if ($spark): ?>
        <svg width="58" height="20" style="display:block;margin:0 auto"><path d="<?= sx_sparkpath($spark, 58, 20) ?>" fill="none" stroke="<?= $sparkCol ?>" stroke-width="1.3" stroke-linejoin="round"/></svg>
        <?php else: ?><span style="font-size:9px;color:var(--muted)">—</span><?php endif; ?>
      </div>
      <div class="sx-price-val" style="text-align:right;font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;color:var(--text)"><?= number_format($s['price']) ?></div>
      <div style="text-align:right">
        <span class="sx-pill sx-change-pill <?= $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat') ?>"><?= $diff > 0 ? '&#9650;' : ($diff < 0 ? '&#9660;' : '&bull;') ?> <?= number_format(abs($diffPct), 1) ?>%</span>
      </div>
      <div onclick="event.stopPropagation()">
        <?php if ($soldOut): ?>
        <span class="muted" style="font-size:11px">No shares left to buy</span>
        <?php else: ?>
        <form method="post" style="margin:0;display:flex;gap:4px;align-items:center;flex-wrap:wrap" data-sxfx="buy"
              data-sx-ticker="<?= e($s['ticker']) ?>" data-sx-price="<?= (int)$s['price'] ?>">
          <input type="hidden" name="action" value="buy">
          <input type="hidden" name="stock_id" value="<?= (int)$s['id'] ?>">
          <input type="number" name="qty" value="1" min="1" max="<?= $avail ?>" class="sx-qty" data-price="<?= (int)$s['price'] ?>" style="width:48px;padding:3px 5px;font-size:11px">
          <button type="submit" style="padding:4px 10px;font-size:11px;color:var(--accent);border-color:rgba(25,240,199,.35);background:rgba(25,240,199,.08)">Buy</button>
          <span class="sx-cost" style="flex-basis:100%">= <?= number_format((int)$s['price'] + max(1, (int)ceil($s['price'] * 0.01))) ?> credits w/fee</span>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($tab === 'detail' && $detailStock):
  $ds = $detailStock;
  $diff    = (int)$ds['price'] - (int)$ds['prev_price'];
  $diffPct = $ds['prev_price'] > 0 ? round($diff / $ds['prev_price'] * 100, 1) : 0;
  $catCol  = $catColors[$ds['category']] ?? '#8fa3c8';
  $info    = $stockInfo[$ds['ticker']] ?? ['desc'=>'','trend'=>''];
  $dayHigh = $dayLow = (int)$ds['price'];
  if (count($detailCandles)) { $last = end($detailCandles); $dayHigh = $last['h']; $dayLow = $last['l']; }
  $avail = (int)$ds['shares_available']; $total = (int)$ds['shares_total'];
  $availPct = $total > 0 ? round($avail / $total * 100) : 0;
?>
<div class="panel" id="sx-detail-root" data-sx-id="<?= (int)$ds['id'] ?>">
  <a href="index.php?p=stockex&tab=market" style="font-size:11px;color:var(--muted);text-decoration:none">&larr; Back to Market</a>
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-top:8px">
    <div>
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:<?= $catCol ?>"><?= e($ds['ticker']) ?></span>
        <span style="font-size:15px;color:var(--text)"><?= e($ds['name']) ?></span>
        <span style="font-size:10px;color:<?= $catCol ?>;border:1px solid <?= $catCol ?>;border-radius:10px;padding:1px 8px;text-transform:uppercase;letter-spacing:.05em"><?= ucfirst($ds['category']) ?></span>
      </div>
      <?php if ($detailHold): ?>
      <div style="font-size:11px;color:var(--muted);margin-top:4px"><span style="color:var(--accent)"><?= number_format($detailHold['shares']) ?> shares owned</span> &middot; avg buy <?= number_format($detailHold['avg_buy_price']) ?> credits</div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;flex:none">
      <div style="display:flex;align-items:baseline;justify-content:flex-end;gap:8px">
        <span class="sx-detail-price" style="font-family:'Orbitron',sans-serif;font-size:26px;font-weight:700;color:var(--text)"><?= number_format($ds['price']) ?></span>
        <span style="font-size:12px;font-weight:400;color:var(--muted)">credits</span>
      </div>
      <div style="margin-top:4px"><span class="sx-pill sx-detail-pill" style="width:auto;padding:3px 10px;<?= $diff > 0 ? 'color:#3bcf63;background:rgba(59,207,99,.1);border:1px solid rgba(59,207,99,.3)' : ($diff < 0 ? 'color:var(--neon2);background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3)' : 'color:var(--muted);border:1px solid var(--line)') ?>"><span class="sx-detail-arrow"><?= $diff > 0 ? '&#9650;' : ($diff < 0 ? '&#9660;' : '&bull;') ?></span> <span class="sx-detail-pct"><?= number_format(abs($diffPct),1) ?>%</span> since last tick</span></div>
    </div>
  </div>
</div>

<div class="sx-stat-row" style="margin:14px 0">
  <div class="sx-stat"><div class="lbl">Shares Available</div><div class="val sx-detail-avail" style="color:<?= $avail<=0?'var(--neon2)':(($availPct<20)?'#e8a33d':'var(--text)') ?>"><?= number_format($avail) ?></div></div>
  <div class="sx-stat"><div class="lbl">Total Float</div><div class="val"><?= number_format($total) ?></div></div>
  <div class="sx-stat"><div class="lbl">Today's Range</div><div class="val" style="font-size:12px"><?= number_format($dayLow) ?>&ndash;<?= number_format($dayHigh) ?></div></div>
  <div class="sx-stat"><div class="lbl">24h Volume</div><div class="val" style="font-size:12px"><span style="color:#3bcf63">&#9650;<?= number_format($detailVol24h['buy']) ?></span> / <span style="color:var(--neon2)">&#9660;<?= number_format($detailVol24h['sell']) ?></span></div></div>
  <div class="sx-stat"><div class="lbl">7d Volume</div><div class="val" style="font-size:12px"><span style="color:#3bcf63">&#9650;<?= number_format($detailVol7d['buy']) ?></span> / <span style="color:var(--neon2)">&#9660;<?= number_format($detailVol7d['sell']) ?></span></div></div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;align-items:start">
  <div>
    <div class="sx-chart-panel">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px">
        <div style="font-size:11px;color:#e8d44d;text-transform:uppercase;letter-spacing:.08em">30-Day Chart</div>
        <div style="font-size:10px;color:var(--muted)">&#9632;<span style="color:#3bcf63">&nbsp;up</span> &nbsp;&#9632;<span style="color:var(--neon2)">&nbsp;down</span> &nbsp;&middot;&nbsp; volume below</div>
      </div>
      <?php if (count($detailCandles) >= 2): ?>
      <div style="position:relative;cursor:zoom-in" onclick="document.getElementById('sxChartModal').classList.add('show')" title="Click to expand">
        <svg width="100%" viewBox="0 0 760 240" style="display:block;aspect-ratio:760/240">
          <?= sx_render_candles($detailCandles, $detailVolByDay, 760, 240) ?>
        </svg>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--muted);margin-top:4px">
        <span><?= e(date('M j', strtotime($detailCandles[0]['d']))) ?></span>
        <span style="cursor:pointer;color:var(--accent)" onclick="document.getElementById('sxChartModal').classList.add('show')">Click chart to expand &#8599;</span>
        <span><?= e(date('M j', strtotime(end($detailCandles)['d']))) ?></span>
      </div>
      <?php else: ?>
      <div style="padding:40px;text-align:center;color:var(--muted);font-size:12px">Not enough price history yet — check back after a few price updates.</div>
      <?php endif; ?>
    </div>

    <div class="panel" style="border:1px solid rgba(232,212,77,.25);background:rgba(232,212,77,.04);margin-top:14px">
      <div style="font-size:11px;color:#e8d44d;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Why it's moving right now</div>
      <p style="margin:0 0 <?= $detailFactors ? '10px' : '0' ?>;font-size:13px;line-height:1.6"><?= e($detailReason) ?></p>
      <?php if ($detailFactors): ?>
      <div style="display:flex;gap:16px;flex-wrap:wrap;border-top:1px dashed rgba(232,212,77,.2);padding-top:10px">
        <?php foreach ($detailFactors as $f): ?>
        <div>
          <div style="font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em"><?= e($f['label']) ?></div>
          <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:<?= $f['good'] ? '#3bcf63' : 'var(--muted)' ?>"><?= e($f['value']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:14px">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">About</div>
      <p style="margin:0 0 6px;font-size:13px;line-height:1.6"><?= e($info['desc']) ?></p>
      <?php if ($info['trend']): ?><div style="font-size:11px"><span style="color:var(--muted)">Trend: </span><span style="color:#e8d44d"><?= e($info['trend']) ?></span></div><?php endif; ?>
    </div>
  </div>

  <div class="sx-order-panel">
    <h3 style="margin:0 0 12px;font-size:13px">Trade <?= e($ds['ticker']) ?></h3>
    <div style="font-size:11px;font-weight:700;color:#3bcf63;margin-bottom:6px">Buy</div>
    <?php if ($avail <= 0): ?>
    <p class="muted" style="font-size:12px;margin:0 0 16px">No shares available to buy right now — check back after other players sell.</p>
    <?php else: ?>
    <form method="post" style="margin:0 0 16px;display:flex;gap:6px;align-items:center;flex-wrap:wrap" data-sxfx="buy"
          data-sx-ticker="<?= e($ds['ticker']) ?>" data-sx-price="<?= (int)$ds['price'] ?>">
      <input type="hidden" name="action" value="buy">
      <input type="hidden" name="stock_id" value="<?= (int)$ds['id'] ?>">
      <input type="number" name="qty" value="1" min="1" max="<?= $avail ?>" class="sx-qty" data-price="<?= (int)$ds['price'] ?>" style="width:100%">
      <button type="submit" style="width:100%;color:var(--accent);border-color:rgba(25,240,199,.35);background:rgba(25,240,199,.08)">Buy</button>
      <span class="sx-cost">= <?= number_format((int)$ds['price'] + max(1, (int)ceil($ds['price'] * 0.01))) ?> credits w/fee</span>
    </form>
    <?php endif; ?>
    <div style="font-size:11px;font-weight:700;color:var(--neon2);margin-bottom:6px">Sell</div>
    <?php if ($detailHold): ?>
    <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;flex-wrap:wrap" data-sxfx="sell"
          data-sx-ticker="<?= e($ds['ticker']) ?>" data-sx-price="<?= (int)$ds['price'] ?>">
      <input type="hidden" name="action" value="sell">
      <input type="hidden" name="stock_id" value="<?= (int)$ds['id'] ?>">
      <input type="number" name="qty" value="1" min="1" max="<?= (int)$detailHold['shares'] ?>" class="sx-qty sell" data-price="<?= (int)$ds['price'] ?>" style="width:100%">
      <button type="button" class="fill-max" style="font-size:10px;width:100%" onclick="var i=this.previousElementSibling;i.value=<?= (int)$detailHold['shares'] ?>;i.dispatchEvent(new Event('input'))">All</button>
      <button type="submit" style="width:100%;color:var(--neon2);border-color:rgba(255,45,149,.3);background:rgba(255,45,149,.08)">Sell</button>
      <span class="sx-cost">= <?= number_format((int)$ds['price'] - max(1, (int)ceil($ds['price'] * 0.01))) ?> credits after fee</span>
    </form>
    <?php else: ?>
    <p class="muted" style="font-size:12px;margin:0">You don't own any shares to sell.</p>
    <?php endif; ?>
  </div>
</div>

<?php if (count($detailCandles) >= 2): ?>
<div class="modal-bg" id="sxChartModal">
  <div class="modal" style="max-width:920px;width:94vw">
    <span class="x" onclick="document.getElementById('sxChartModal').classList.remove('show')">&times;</span>
    <h3 style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <span><?= e($ds['ticker']) ?> &mdash; 30-Day Chart</span>
      <span style="display:flex;gap:4px">
        <button type="button" id="sxZoomOut" style="font-size:12px;padding:4px 10px" title="Zoom out">&minus;</button>
        <button type="button" id="sxZoomReset" style="font-size:11px;padding:4px 10px" title="Reset view">Reset</button>
        <button type="button" id="sxZoomIn" style="font-size:12px;padding:4px 10px" title="Zoom in">+</button>
      </span>
    </h3>
    <div id="sxChartViewport" style="overflow:hidden;border-radius:6px;background:#08070d;aspect-ratio:900/420;cursor:grab">
      <svg id="sxChartSvg" width="100%" height="100%" viewBox="0 0 900 420" style="display:block;transform-origin:0 0">
        <?= sx_render_candles($detailCandles, $detailVolByDay, 900, 420, 8) ?>
      </svg>
    </div>
    <div style="font-size:10px;color:var(--muted);margin-top:8px">&#9632;<span style="color:#3bcf63">&nbsp;up</span> &nbsp;&#9632;<span style="color:var(--neon2)">&nbsp;down</span> &nbsp;&middot;&nbsp; volume &amp; dates below each candle &nbsp;&middot;&nbsp; scroll to zoom, drag to pan</div>
  </div>
</div>
<script>
(function(){
  var m=document.getElementById('sxChartModal');
  if(m) m.addEventListener('click',function(e){ if(e.target===this) m.classList.remove('show'); });

  /* Pan/zoom — a CSS transform on the SVG itself inside a clipped viewport,
     not a re-render of the underlying chart data. Simplest way to give a
     "move it around, zoom in/out" feel over a chart that's otherwise a
     fixed, server-rendered picture. */
  var vp=document.getElementById('sxChartViewport'), svg=document.getElementById('sxChartSvg');
  if(vp&&svg){
    var scale=1, tx=0, ty=0, MIN=1, MAX=6;
    function apply(){
      // clamp pan so the image can't be dragged fully out of view
      var w=vp.clientWidth, h=vp.clientHeight;
      var maxX=Math.max(0,(scale-1)*w), maxY=Math.max(0,(scale-1)*h);
      tx=Math.min(0,Math.max(-maxX,tx));
      ty=Math.min(0,Math.max(-maxY,ty));
      svg.style.transform='translate('+tx+'px,'+ty+'px) scale('+scale+')';
    }
    function zoomAt(factor,cx,cy){
      var newScale=Math.min(MAX,Math.max(MIN,scale*factor));
      if(newScale===scale) return;
      var ratio=newScale/scale;
      tx=cx-(cx-tx)*ratio; ty=cy-(cy-ty)*ratio;
      scale=newScale;
      apply();
    }
    vp.addEventListener('wheel',function(e){
      e.preventDefault();
      var r=vp.getBoundingClientRect();
      zoomAt(e.deltaY<0?1.15:1/1.15, e.clientX-r.left, e.clientY-r.top);
    },{passive:false});
    var dragging=false, sx=0, sy=0, stx=0, sty=0;
    vp.addEventListener('mousedown',function(e){ dragging=true; sx=e.clientX; sy=e.clientY; stx=tx; sty=ty; vp.style.cursor='grabbing'; });
    window.addEventListener('mousemove',function(e){
      if(!dragging) return;
      tx=stx+(e.clientX-sx); ty=sty+(e.clientY-sy);
      apply();
    });
    window.addEventListener('mouseup',function(){ dragging=false; vp.style.cursor='grab'; });
    // touch: single-finger drag, two-finger pinch
    var lastDist=null, lastMid=null;
    vp.addEventListener('touchstart',function(e){
      if(e.touches.length===1){ dragging=true; sx=e.touches[0].clientX; sy=e.touches[0].clientY; stx=tx; sty=ty; }
      else if(e.touches.length===2){ dragging=false; lastDist=touchDist(e.touches); lastMid=touchMid(e.touches); }
    },{passive:true});
    vp.addEventListener('touchmove',function(e){
      if(e.touches.length===1&&dragging){
        tx=stx+(e.touches[0].clientX-sx); ty=sty+(e.touches[0].clientY-sy); apply();
      } else if(e.touches.length===2){
        var d=touchDist(e.touches), mid=touchMid(e.touches), r=vp.getBoundingClientRect();
        if(lastDist) zoomAt(d/lastDist, mid.x-r.left, mid.y-r.top);
        lastDist=d; lastMid=mid;
      }
    },{passive:true});
    vp.addEventListener('touchend',function(){ dragging=false; lastDist=null; });
    function touchDist(t){ var dx=t[0].clientX-t[1].clientX, dy=t[0].clientY-t[1].clientY; return Math.sqrt(dx*dx+dy*dy); }
    function touchMid(t){ return {x:(t[0].clientX+t[1].clientX)/2, y:(t[0].clientY+t[1].clientY)/2}; }

    document.getElementById('sxZoomIn').addEventListener('click',function(){ zoomAt(1.3, vp.clientWidth/2, vp.clientHeight/2); });
    document.getElementById('sxZoomOut').addEventListener('click',function(){ zoomAt(1/1.3, vp.clientWidth/2, vp.clientHeight/2); });
    document.getElementById('sxZoomReset').addEventListener('click',function(){ scale=1; tx=0; ty=0; apply(); });
  }
})();
</script>
<?php endif; ?>

<?php elseif ($tab === 'portfolio'): // portfolio tab ?>
<div class="panel">
  <?php if (empty($portfolio)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No holdings. Buy shares from the Market tab.</div>
  <?php else:
    $totalValue = 0; $totalCost = 0;
    foreach ($portfolio as $h) { $totalValue += (int)$h['price'] * (int)$h['shares']; $totalCost += (int)$h['avg_buy_price'] * (int)$h['shares']; }
    $totalPL = $totalValue - $totalCost;
    $plCol = $totalPL >= 0 ? '#3bcf63' : 'var(--neon2)';
  ?>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px;padding:12px;background:var(--panel2);border:1px solid var(--line);border-radius:7px;align-items:center">
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:var(--accent)" data-sxcnt="<?= $totalValue ?>"><?= number_format($totalValue) ?> credits</div><div style="font-size:10px;color:var(--muted)">Portfolio Value</div></div>
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:<?= $plCol ?>"><?= $totalPL>=0?'+':'' ?><?= number_format($totalPL) ?> credits</div><div style="font-size:10px;color:var(--muted)">Unrealized P/L</div></div>
    <div style="flex:1;min-width:180px">
      <div style="font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px">Allocation</div>
      <div class="sx-alloc">
        <?php foreach ($portfolio as $h):
          $hv = (int)$h['price'] * (int)$h['shares'];
          $w = $totalValue > 0 ? max(1, round($hv / $totalValue * 100, 1)) : 0;
        ?>
        <div style="width:<?= $w ?>%;background:<?= $catColors[$h['category']] ?? '#8fa3c8' ?>" title="<?= e($h['ticker']) ?> — <?= $w ?>% (<?= number_format($hv) ?> credits)"></div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
        <?php foreach ($portfolio as $h): ?>
        <span style="font-size:9px;color:<?= $catColors[$h['category']] ?? '#8fa3c8' ?>">&#9632; <?= e($h['ticker']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;gap:10px">
    <?php foreach ($portfolio as $h):
      $currentVal = (int)$h['price'] * (int)$h['shares'];
      $costBasis  = (int)$h['avg_buy_price'] * (int)$h['shares'];
      $pl = $currentVal - $costBasis;
      $plCol = $pl >= 0 ? '#3bcf63' : 'var(--neon2)';
      $catCol = $catColors[$h['category']] ?? '#8fa3c8';
      $hist = $priceHistory[$h['stock_id']] ?? [];
      $plPct = $costBasis > 0 ? round(($pl / $costBasis) * 100, 1) : 0;
    ?>
    <div class="sx-holding" style="background:var(--panel2);border:1px solid <?= $pl >= 0 ? 'rgba(59,207,99,.2)' : 'rgba(255,45,149,.2)' ?>;border-radius:8px;padding:14px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div style="flex:1;min-width:160px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:<?= $catCol ?>"><?= e($h['ticker']) ?></span>
            <span style="font-size:12px;color:var(--text)"><?= e($h['name']) ?></span>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px"><?= number_format($h['shares']) ?> shares &middot; avg buy <b style="color:#e8d44d"><?= number_format($h['avg_buy_price']) ?></b> credits &middot; now <b style="color:var(--text)"><?= number_format($h['price']) ?></b> credits</div>
          <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <div>
              <div style="font-size:14px;font-weight:700;color:var(--text)"><?= number_format($currentVal) ?> credits</div>
              <div style="font-size:11px;color:<?= $plCol ?>"><?= $pl>=0?'+':'' ?><?= number_format($pl) ?> credits &nbsp;<span style="opacity:.7">(<?= $pl>=0?'+':'' ?><?= $plPct ?>%)</span></div>
            </div>
            <form method="post" style="margin:0;display:flex;gap:4px;align-items:center;flex-wrap:wrap" data-sxfx="sell"
                  data-sx-ticker="<?= e($h['ticker']) ?>" data-sx-price="<?= (int)$h['price'] ?>">
              <input type="hidden" name="action" value="sell">
              <input type="hidden" name="stock_id" value="<?= (int)$h['stock_id'] ?>">
              <input type="number" name="qty" value="1" min="1" max="<?= (int)$h['shares'] ?>" class="sx-qty sell" data-price="<?= (int)$h['price'] ?>" style="width:52px;padding:3px 6px;font-size:11px">
              <button type="button" class="fill-max" style="font-size:9px;padding:3px 7px" onclick="var i=this.previousElementSibling;i.value=<?= (int)$h['shares'] ?>;i.dispatchEvent(new Event('input'))">All</button>
              <button type="submit" style="padding:4px 10px;font-size:11px;color:var(--neon2);border-color:rgba(255,45,149,.3);background:rgba(255,45,149,.08)">Sell</button>
              <span class="sx-cost" style="flex-basis:100%">= <?= number_format((int)$h['price'] - max(1, (int)ceil($h['price'] * 0.01))) ?> credits after fee</span>
            </form>
          </div>
        </div>
        <?php if (count($hist) >= 2):
          $hh = sx_downsample($hist, 60);
          $buyPx = (int)$h['avg_buy_price'];
          $allPts = array_merge($hh, [$buyPx]);
          $mn = min($allPts); $mx = max($allPts); $rng = max(1, $mx - $mn);
          $cw = 150; $ch = 52;
          $pts = ''; $n = count($hh);
          for ($xi = 0; $xi < $n; $xi++) {
            $x = (int)round($xi / max(1,$n-1) * ($cw-2)) + 1;
            $y = (int)round($ch - 2 - ($hh[$xi] - $mn) / $rng * ($ch-4));
            $pts .= ($xi===0?'M':'L')."{$x},{$y} ";
          }
          $buyY = (int)round($ch - 2 - ($buyPx - $mn) / $rng * ($ch-4));
          $trendCol = end($hh) >= $hh[0] ? '#3bcf63' : '#ff2d95';
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

<script>
(function(){
'use strict';

/* ── Big board header: composite chart + scrolling ticker tape ── */
var sx=document.getElementById('sx-canvas');
if(sx){
  var c=sx.getContext('2d');
  var SW=560, SH=130;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  sx.width=SW*dpr; sx.height=SH*dpr;
  c.scale(dpr,dpr);
  var COMP=<?= json_encode($composite) ?>;
  var TAPE=<?= json_encode($tickerData) ?>;
  var tapeX=0;
  // pre-measure tape segments
  var segs=[];
  (function(){
    c.font='700 10px monospace';
    TAPE.forEach(function(s){
      var txt=s.t+' '+s.p.toLocaleString('en-US');
      var chg=(s.pct>0?'▲':(s.pct<0?'▼':'•'))+Math.abs(s.pct)+'%';
      segs.push({txt:txt,chg:chg,col:s.pct>0?'#3bcf63':(s.pct<0?'#ff2d95':'#8fa3c8'),
                 w:c.measureText(txt).width+c.measureText(' '+chg).width+26});
    });
  })();
  var tapeW=segs.reduce(function(a,s){return a+s.w;},0)||1;

  function sxLoop(t){
    if(!document.body.contains(sx)) return;
    requestAnimationFrame(sxLoop);
    c.clearRect(0,0,SW,SH);
    var bg=c.createLinearGradient(0,0,0,SH);
    bg.addColorStop(0,'#0b0a12'); bg.addColorStop(1,'#100e18');
    c.fillStyle=bg; c.fillRect(0,0,SW,SH);
    // faint grid
    c.strokeStyle='rgba(232,212,77,.04)';
    for(var gx=0;gx<SW;gx+=40){ c.beginPath(); c.moveTo(gx,0); c.lineTo(gx,SH-18); c.stroke(); }
    for(var gy=18;gy<SH-18;gy+=22){ c.beginPath(); c.moveTo(0,gy); c.lineTo(SW,gy); c.stroke(); }

    // composite index area chart
    if(COMP.length>=2){
      var mn=Math.min.apply(null,COMP), mx=Math.max.apply(null,COMP);
      var rng=Math.max(.01,mx-mn);
      var x0=0, x1=SW, y0=34, y1=SH-26;
      var up=COMP[COMP.length-1]>=COMP[0];
      var col=up?'#3bcf63':'#ff2d95';
      c.beginPath();
      COMP.forEach(function(v,i){
        var x=x0+i/(COMP.length-1)*(x1-x0);
        var y=y1-(v-mn)/rng*(y1-y0);
        i?c.lineTo(x,y):c.moveTo(x,y);
      });
      c.strokeStyle=col; c.lineWidth=1.6; c.shadowColor=col; c.shadowBlur=6;
      c.stroke();
      c.shadowBlur=0;
      c.lineTo(x1,y1); c.lineTo(x0,y1); c.closePath();
      var fg=c.createLinearGradient(0,y0,0,y1);
      fg.addColorStop(0,up?'rgba(59,207,99,.16)':'rgba(255,45,149,.13)');
      fg.addColorStop(1,'rgba(0,0,0,0)');
      c.fillStyle=fg; c.fill();
      c.lineWidth=1;
      // live dot on the last point
      var lx=x1, ly=y1-(COMP[COMP.length-1]-mn)/rng*(y1-y0);
      var pulse=.5+.5*Math.sin(t/300);
      c.fillStyle=col; c.shadowColor=col; c.shadowBlur=8*pulse;
      c.beginPath(); c.arc(lx-3,ly,2.4+pulse,0,Math.PI*2); c.fill();
      c.shadowBlur=0;
    } else {
      c.fillStyle='rgba(255,255,255,.25)';
      c.font='10px monospace'; c.textAlign='center';
      c.fillText('Composite index builds as price history accumulates',SW/2,SH/2);
      c.textAlign='left';
    }

    // ticker tape
    c.fillStyle='#05050c'; c.fillRect(0,SH-18,SW,18);
    c.fillStyle='rgba(232,212,77,.14)'; c.fillRect(0,SH-18,SW,1);
    tapeX-=.7;
    if(tapeX<-tapeW) tapeX+=tapeW;
    var x=tapeX;
    c.textBaseline='middle'; c.font='700 10px monospace'; c.textAlign='left';
    for(var rep=0;rep<2;rep++){
      segs.forEach(function(s){
        if(x>-220&&x<SW+10){
          c.fillStyle='#cfd4dc';
          c.fillText(s.txt,x,SH-9);
          c.fillStyle=s.col;
          c.fillText(s.chg,x+c.measureText(s.txt).width+6,SH-9);
        }
        x+=s.w;
      });
    }
  }
  requestAnimationFrame(sxLoop);
}

/* ── live order previews ── */
document.querySelectorAll('.sx-qty').forEach(function(inp){
  inp.addEventListener('input',function(){
    var price=parseInt(inp.dataset.price,10)||0;
    var q=Math.max(0,parseInt(inp.value,10)||0);
    var gross=price*q;
    var fee=Math.max(1,Math.ceil(gross*0.01));
    var span=inp.closest('form').querySelector('.sx-cost');
    if(!span) return;
    if(inp.classList.contains('sell')) span.textContent='= '+Math.max(0,gross-fee).toLocaleString('en-US')+' credits after fee';
    else span.textContent='= '+(gross+fee).toLocaleString('en-US')+' credits w/fee';
  });
});
})();
</script>

<script>
/* Order-ticket FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._sxFxBound) return;
  window._sxFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#sxfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(5,5,10,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#sxfx.show{opacity:1}'
    +'.sxfx-ticket{position:relative;min-width:230px;background:#0b0b16;border:1px solid rgba(255,255,255,.16);'
    +'border-radius:8px;padding:14px 18px 16px;font-family:monospace;box-shadow:0 0 28px rgba(0,0,0,.6),0 0 16px var(--sx-col-a);'
    +'animation:sxfxIn .22s ease-out}'
    +'@keyframes sxfxIn{0%{transform:translateY(12px);opacity:0}100%{transform:none;opacity:1}}'
    +'.sxfx-head{display:flex;justify-content:space-between;font-size:10px;color:#5d6680;letter-spacing:.14em;'
    +'border-bottom:1px dashed rgba(255,255,255,.14);padding-bottom:6px;margin-bottom:8px}'
    +'.sxfx-side{font-weight:900;color:var(--sx-col)}'
    +'.sxfx-line{display:flex;justify-content:space-between;gap:20px;font-size:12px;padding:2px 0;color:#cfd4dc}'
    +'.sxfx-line span:first-child{color:#5d6680}'
    +'.sxfx-stamp{position:absolute;right:10px;top:8px;font-size:11px;font-weight:900;letter-spacing:.1em;'
    +'color:var(--sx-col);border:2px solid var(--sx-col);border-radius:4px;padding:2px 8px;transform:rotate(8deg) scale(2);'
    +'opacity:0;text-shadow:0 0 8px var(--sx-col);animation:sxfxStamp .28s .4s cubic-bezier(.2,1.7,.4,1) forwards}'
    +'@keyframes sxfxStamp{to{opacity:1;transform:rotate(8deg) scale(1)}}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('stockMuted')==='1';
  function tone(freq,dur,type,vol,slide){
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
  window.toggleSxSound=function(){
    muted=!muted; localStorage.setItem('stockMuted',muted?'1':'0');
    var b=document.getElementById('sx-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };
  (function(){ var b=document.getElementById('sx-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; })();

  function hexA(hex,a){
    if(hex.charAt(0)!=='#') return hex;
    var n=parseInt(hex.slice(1),16);
    return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';
  }

  function ticket(side,ticker,qty,price,col){
    var old=document.getElementById('sxfx'); if(old) old.remove();
    var gross=qty*price;
    var fee=Math.max(1,Math.ceil(gross*0.01));
    var total=side==='BUY'?gross+fee:gross-fee;
    var o=document.createElement('div'); o.id='sxfx'; o.style.pointerEvents='auto';
    o.style.setProperty('--sx-col',col);
    o.style.setProperty('--sx-col-a',hexA(col,.35));
    o.innerHTML='<div class="sxfx-ticket">'
      +'<div class="sxfx-head"><span>GRID EXCHANGE · ORDER</span><span class="sxfx-side">'+side+'</span></div>'
      +'<div class="sxfx-line"><span>TICKER</span><b>'+ticker+'</b></div>'
      +'<div class="sxfx-line"><span>QTY</span><b>×'+qty.toLocaleString('en-US')+'</b></div>'
      +'<div class="sxfx-line"><span>PRICE</span><b>'+price.toLocaleString('en-US')+' credits</b></div>'
      +'<div class="sxfx-line"><span>FEE 1%</span><b>'+fee.toLocaleString('en-US')+' credits</b></div>'
      +'<div class="sxfx-line" style="border-top:1px dashed rgba(255,255,255,.14);margin-top:4px;padding-top:5px"><span>'+(side==='BUY'?'TOTAL':'NET')+'</span><b style="color:'+col+'">'+total.toLocaleString('en-US')+' credits</b></div>'
      +'<div class="sxfx-stamp">FILLED</div>'
      +'<button type="button" class="sxfx-ok" style="width:100%;margin-top:12px;color:'+col+';border-color:'+col+'55;background:'+hexA(col,.1)+'">Okay</button>'
      +'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    tone(1200,.05,'square',.03); setTimeout(function(){tone(1500,.05,'square',.03);},90); // order ticks
    setTimeout(function(){ tone(95,.1,'square',.05); },420); // stamp
    setTimeout(function(){
      if(side==='BUY'){ tone(523,.09,'sine',.045); setTimeout(function(){tone(784,.13,'sine',.045);},80); }
      else { tone(659,.09,'sine',.045); setTimeout(function(){tone(440,.13,'sine',.045);},80); }
    },560);
    // Stays open until the user dismisses it — no auto-hide timer. Dismiss
    // via the Okay button or by clicking the dimmed backdrop.
    function dismiss(){ o.classList.remove('show'); setTimeout(function(){ o.remove(); },220); }
    o.querySelector('.sxfx-ok').addEventListener('click',dismiss);
    o.addEventListener('click',function(e){ if(e.target===o) dismiss(); });
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute) return;
    var kind=f.getAttribute('data-sxfx');
    if(!kind) return;
    var qty=Math.max(1,parseInt((f.querySelector('input[name=qty]')||{}).value,10)||1);
    var price=parseInt(f.getAttribute('data-sx-price'),10)||0;
    var ticker=f.getAttribute('data-sx-ticker')||'';
    if(kind==='buy') ticket('BUY',ticker,qty,price,'#3bcf63');
    else ticket('SELL',ticker,qty,price,'#ff2d95');
  },true);
})();
</script>

<script>
/* Live quote polling — reflects other players' trades and the periodic
   price-drift tick without the viewer reloading. Read-only snapshot from
   stockex_api.php; the price-drift engine itself only runs from a normal
   page load of this file, this just displays whatever it last computed. */
(function(){
  if(window._sxPollBound) return;
  window._sxPollBound=true;

  var timer=null;
  function poll(){
    // Self-terminates once these elements leave the DOM (navigated away via
    // the sitewide AJAX swap) — same pattern as this file's composite-chart
    // canvas loop, since nothing here ever calls clearInterval otherwise.
    if(!document.querySelector('[data-sx-id]')){ clearInterval(timer); return; }
    fetch('stockex_api.php',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.ok||!d.quotes) return;
        document.querySelectorAll('[data-sx-id]').forEach(function(el){
          var q=d.quotes[el.getAttribute('data-sx-id')];
          if(!q) return;
          var arrow=q.dir==='up'?'&#9650;':(q.dir==='down'?'&#9660;':'&bull;');
          var pct=Math.abs(q.diffPct).toFixed(1)+'%';
          if(el.classList.contains('sx-row')){
            var priceEl=el.querySelector('.sx-price-val');
            if(priceEl) priceEl.textContent=q.price.toLocaleString('en-US');
            var pillEl=el.querySelector('.sx-change-pill');
            if(pillEl){
              pillEl.classList.remove('up','down','flat');
              pillEl.classList.add(q.dir);
              pillEl.innerHTML=arrow+' '+pct;
            }
            var availEl=el.querySelector('.sx-avail');
            if(availEl){
              var soldOut=q.avail<=0;
              availEl.classList.toggle('sold-out',soldOut);
              availEl.innerHTML=soldOut?'Sold out':'<b>'+q.avail.toLocaleString('en-US')+'</b> available';
            }
            var qtyInp=el.querySelector('.sx-qty');
            if(qtyInp) qtyInp.max=q.avail;
          } else if(el.id==='sx-detail-root'){
            var pEl=el.querySelector('.sx-detail-price');
            if(pEl) pEl.textContent=q.price.toLocaleString('en-US');
            var pill=el.querySelector('.sx-detail-pill');
            if(pill){
              var col=q.dir==='up'?'#3bcf63':(q.dir==='down'?'var(--neon2)':'var(--muted)');
              var bg=q.dir==='up'?'rgba(59,207,99,.1)':(q.dir==='down'?'rgba(255,45,149,.08)':'transparent');
              var bd=q.dir==='up'?'rgba(59,207,99,.3)':(q.dir==='down'?'rgba(255,45,149,.3)':'var(--line)');
              pill.style.color=col; pill.style.background=bg; pill.style.borderColor=bd;
              var a=pill.querySelector('.sx-detail-arrow'); if(a) a.innerHTML=arrow;
              var p=pill.querySelector('.sx-detail-pct'); if(p) p.textContent=pct;
            }
            var av=el.querySelector('.sx-detail-avail');
            if(av){
              av.textContent=q.avail.toLocaleString('en-US');
              av.style.color=q.avail<=0?'var(--neon2)':((q.total>0&&(q.avail/q.total)<0.2)?'#e8a33d':'var(--text)');
            }
            document.querySelectorAll('.sx-qty:not(.sell)').forEach(function(i){ i.max=q.avail; });
          }
        });
      })
      .catch(function(){});
  }
  timer=setInterval(poll,8000);
})();
</script>
