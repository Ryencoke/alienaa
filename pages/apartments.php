<?php /* pages/apartments.php — Apartment Complex & Housing Market */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Auto-create tables
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_apartments (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL,
    apt_type_id INT NOT NULL, region VARCHAR(40) NOT NULL,
    is_primary TINYINT NOT NULL DEFAULT 0,
    rented_to INT NULL, rent_amount INT NOT NULL DEFAULT 0,
    on_market TINYINT NOT NULL DEFAULT 0, market_price INT NOT NULL DEFAULT 0,
    market_currency ENUM('credits','shards') NOT NULL DEFAULT 'credits',
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player (player_id), INDEX idx_market (on_market)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE player_apartments ADD COLUMN market_currency ENUM('credits','shards') NOT NULL DEFAULT 'credits'"); } catch (Throwable $e) {}
// Credits actually paid for the unit (NULL = legacy row, treat as catalog price). Caps sell-back refunds.
try { $pdo->exec("ALTER TABLE player_apartments ADD COLUMN paid_price INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS apartment_decor (
    id INT AUTO_INCREMENT PRIMARY KEY, apt_id INT NOT NULL, player_id INT NOT NULL,
    decor_key VARCHAR(40) NOT NULL, placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apt (apt_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Apartment type definitions
$APT_TYPES = [
  1  => ['name'=>'Coffin Cube',      'region'=>'The Undervolt',   'price'=>500,   'rarity'=>'common',    'perks'=>'+5 max Drive',                'perk_key'=>'cycles_max','perk_val'=>5],
  2  => ['name'=>'Stack Unit',       'region'=>'The Stacks',      'price'=>1200,  'rarity'=>'common',    'perks'=>'+10 max Drive',               'perk_key'=>'cycles_max','perk_val'=>10],
  3  => ['name'=>'Grid Node Flat',   'region'=>'The Datacore',    'price'=>3000,  'rarity'=>'uncommon',  'perks'=>'+5% XP gain',                  'perk_key'=>'xp_bonus','perk_val'=>5],
  4  => ['name'=>'Neon Strip Loft',  'region'=>'Neon Strip',      'price'=>6000,  'rarity'=>'uncommon',  'perks'=>'+10 max Signal',               'perk_key'=>'signal_max','perk_val'=>10],
  5  => ['name'=>'Forge Quarter Den','region'=>'The Forge Quarter','price'=>9000, 'rarity'=>'rare',      'perks'=>'+10% Foundry yield',           'perk_key'=>'foundry_bonus','perk_val'=>10],
  6  => ['name'=>'Exchange Penthouse','region'=>'The Exchange Block','price'=>25000,'rarity'=>'rare',    'perks'=>'+0.25% extra bank interest',   'perk_key'=>'bank_bonus','perk_val'=>25],
  7  => ['name'=>'Spire Suite',      'region'=>'The Grid Authority','price'=>75000,'rarity'=>'legendary','perks'=>'+20 max Signal, +20 max Drive','perk_key'=>'dual_boost','perk_val'=>20],
];

$REGIONS = array_unique(array_column($APT_TYPES, 'region'));
$RARITY_COLORS = ['common'=>'var(--muted)','uncommon'=>'var(--accent)','rare'=>'var(--neon2)','legendary'=>'#e8d44d'];

$DECOR_ITEMS = [
  'neon_light'    => ['name'=>'Neon Wall Light',  'price'=>500,   'icon'=>'&#128268;'],
  'holo_portrait' => ['name'=>'Holo Portrait',    'price'=>1500,  'icon'=>'&#128444;'],
  'data_terminal' => ['name'=>'Data Terminal',    'price'=>2000,  'icon'=>'&#128187;'],
  'cyber_plant'   => ['name'=>'Cyber Plant',      'price'=>800,   'icon'=>'&#127807;'],
  'weapon_rack'   => ['name'=>'Weapon Rack',      'price'=>3000,  'icon'=>'&#9876;'],
  'security_cam'  => ['name'=>'Security Camera',  'price'=>1200,  'icon'=>'&#128249;'],
];

// Apply or remove the stat/setting bonus for an apartment type.
// Uses apt_perk_tid:{pid} in settings to track what's applied so reversals are safe.
function perk_apply($pdo, $pid, $typeId, $APT_TYPES, $add = true) {
  if (!isset($APT_TYPES[$typeId])) return;
  $key = $APT_TYPES[$typeId]['perk_key'];
  $val = (int)$APT_TYPES[$typeId]['perk_val'];
  $delta = $add ? $val : -$val;
  try {
    if ($key === 'cycles_max')
      $pdo->prepare('UPDATE players SET cycles_max = GREATEST(1, cycles_max + ?) WHERE id = ?')->execute([$delta, $pid]);
    elseif ($key === 'signal_max')
      $pdo->prepare('UPDATE players SET signal_max = GREATEST(1, signal_max + ?) WHERE id = ?')->execute([$delta, $pid]);
    elseif ($key === 'dual_boost')
      $pdo->prepare('UPDATE players SET cycles_max = GREATEST(1, cycles_max + ?), signal_max = GREATEST(1, signal_max + ?) WHERE id = ?')->execute([$delta, $delta, $pid]);
    elseif (in_array($key, ['xp_bonus','foundry_bonus','bank_bonus'], true)) {
      if ($add)
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["apt_{$key}:{$pid}", $val]);
      else
        $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["apt_{$key}:{$pid}"]);
    }
    if ($add)
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["apt_perk_tid:{$pid}", $typeId]);
    else
      $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["apt_perk_tid:{$pid}"]);
  } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'buy') {
      $typeId = (int)($_POST['type_id'] ?? 0);
      if (!isset($APT_TYPES[$typeId])) throw new RuntimeException('Invalid apartment type.');
      $apt  = $APT_TYPES[$typeId];
      $price = (int)$apt['price'];
      // Check how many the player already owns of this type
      $qc = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE player_id=? AND apt_type_id=?');
      $qc->execute([$pid, $typeId]); if ($qc->fetchColumn() >= 1) throw new RuntimeException('You already own one of this apartment type.');

      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$price, $pid, $price]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits in pocket.');

      // Check if they have a primary residence
      $qp = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE player_id=? AND is_primary=1'); $qp->execute([$pid]);
      $hasPrimary = (int)$qp->fetchColumn() > 0;
      $pdo->prepare('INSERT INTO player_apartments (player_id, apt_type_id, region, is_primary, paid_price) VALUES (?,?,?,?,?)')->execute([$pid, $typeId, $apt['region'], $hasPrimary ? 0 : 1, $price]);
      if (!$hasPrimary) perk_apply($pdo, $pid, $typeId, $APT_TYPES, true);
      $msg = 'Purchased ' . $apt['name'] . '!';
      if (!$hasPrimary) $msg .= ' Set as your primary residence — perks applied!';
      $player = current_player();

    } elseif ($act === 'setprimary') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $qa = $pdo->prepare('SELECT id FROM player_apartments WHERE id=? AND player_id=? AND rented_to IS NULL'); $qa->execute([$aptId, $pid]); if (!$qa->fetchColumn()) throw new RuntimeException('Not found or currently rented out.');
      // Remove old primary perk (only if previously tracked)
      $tkq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $tkq->execute(["apt_perk_tid:{$pid}"]);
      $oldTid = (int)$tkq->fetchColumn();
      if ($oldTid) perk_apply($pdo, $pid, $oldTid, $APT_TYPES, false);
      $pdo->prepare('UPDATE player_apartments SET is_primary=0 WHERE player_id=?')->execute([$pid]);
      $pdo->prepare('UPDATE player_apartments SET is_primary=1 WHERE id=? AND player_id=?')->execute([$aptId, $pid]);
      // Apply new primary's perk
      $ntq = $pdo->prepare('SELECT apt_type_id FROM player_apartments WHERE id=? AND player_id=?'); $ntq->execute([$aptId, $pid]);
      $newTid = (int)$ntq->fetchColumn();
      if ($newTid) perk_apply($pdo, $pid, $newTid, $APT_TYPES, true);
      $msg = 'Primary residence updated — perks applied!';

    } elseif ($act === 'rent_out') {
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $rentAmt  = max(1, (int)($_POST['rent_amount'] ?? 0));
      $toHandle = trim($_POST['renter'] ?? '');
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND is_primary=0 AND rented_to IS NULL'); $qa->execute([$aptId, $pid]); $apt2 = $qa->fetch();
      if (!$apt2) throw new RuntimeException('Cannot rent out — not found, is your primary, or already rented.');
      $qt = $pdo->prepare('SELECT id FROM players WHERE username=?'); $qt->execute([$toHandle]); $renterid = $qt->fetchColumn();
      if (!$renterid) throw new RuntimeException('Player "' . $toHandle . '" not found.');
      if ((int)$renterid === $pid) throw new RuntimeException("You can't rent to yourself.");
      $pdo->prepare('UPDATE player_apartments SET rented_to=?, rent_amount=? WHERE id=?')->execute([$renterid, $rentAmt, $aptId]);
      $msg = 'Rented to ' . $toHandle . ' for ' . number_format($rentAmt) . ' credits/day.';

    } elseif ($act === 'end_rent') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $pdo->prepare('UPDATE player_apartments SET rented_to=NULL, rent_amount=0 WHERE id=? AND player_id=?')->execute([$aptId, $pid]);
      $msg = 'Rental ended.';

    } elseif ($act === 'sellback') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND is_primary=0 AND on_market=0 AND rented_to IS NULL');
      $qa->execute([$aptId, $pid]); $aptRow = $qa->fetch();
      if (!$aptRow) throw new RuntimeException('Cannot sell back — not found, is your primary, or unavailable.');
      $atype = $APT_TYPES[$aptRow['apt_type_id']] ?? null;
      if (!$atype) throw new RuntimeException('Unknown apartment type.');
      // Refund 50% of what was actually paid (capped at catalog) so cheap player-market
      // purchases can't be flipped to the system for profit. Legacy rows = catalog price.
      $paid   = $aptRow['paid_price'] !== null ? (int)$aptRow['paid_price'] : (int)$atype['price'];
      $refund = (int)(min((int)$atype['price'], $paid) / 2);
      $pdo->beginTransaction();
      try { $pdo->prepare('DELETE FROM apartment_decor WHERE apt_id=?')->execute([$aptId]); } catch (Throwable $e) {}
      $pdo->prepare('DELETE FROM player_apartments WHERE id=? AND player_id=?')->execute([$aptId, $pid]);
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$refund, $pid]);
      $pdo->commit();
      $msg = 'Sold back ' . $atype['name'] . ' — refunded ' . number_format($refund) . ' credits (50% return).';
      $player = current_player();

    } elseif ($act === 'decor_buy') {
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $decorKey = $_POST['decor_key'] ?? '';
      if (!isset($DECOR_ITEMS[$decorKey])) throw new RuntimeException('Invalid decor item.');
      $qa = $pdo->prepare('SELECT id FROM player_apartments WHERE id=? AND player_id=?'); $qa->execute([$aptId, $pid]); if (!$qa->fetchColumn()) throw new RuntimeException('Apartment not found.');
      $decor = $DECOR_ITEMS[$decorKey];
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$decor['price'], $pid, $decor['price']]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits (costs ' . number_format($decor['price']) . ' cr).');
      $pdo->prepare('INSERT INTO apartment_decor (apt_id, player_id, decor_key) VALUES (?,?,?)')->execute([$aptId, $pid, $decorKey]);
      $msg = $decor['name'] . ' placed in your apartment!';
      $player = current_player();

    } elseif ($act === 'decor_remove') {
      $decorId = (int)($_POST['decor_id'] ?? 0);
      $pdo->prepare('DELETE FROM apartment_decor WHERE id=? AND player_id=?')->execute([$decorId, $pid]);
      $msg = 'Decoration removed.';

    } elseif ($act === 'list_market') {
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $mktPrice = max(1, (int)($_POST['market_price'] ?? 0));
      $currency = ($_POST['market_currency'] ?? 'credits') === 'shards' ? 'shards' : 'credits';
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND rented_to IS NULL AND on_market=0'); $qa->execute([$aptId, $pid]); $aptRow = $qa->fetch(); if (!$aptRow) throw new RuntimeException('Cannot list — not found or unavailable.');
      $ml = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE apt_type_id=? AND on_market=1 AND id != ?'); $ml->execute([$aptRow['apt_type_id'], $aptId]);
      if ((int)$ml->fetchColumn() >= 1) throw new RuntimeException('A listing for this apartment type is already on the market. Only one per type allowed.');
      $pdo->prepare('UPDATE player_apartments SET on_market=1, market_price=?, market_currency=? WHERE id=?')->execute([$mktPrice, $currency, $aptId]);
      $msg = 'Listed on the apartment market for ' . number_format($mktPrice) . ' ' . $currency . '.';

    } elseif ($act === 'market_buy') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $qa = $pdo->prepare('SELECT pa.*, p.username AS seller_name FROM player_apartments pa JOIN players p ON p.id=pa.player_id WHERE pa.id=? AND pa.on_market=1 AND pa.player_id != ?');
      $qa->execute([$aptId, $pid]); $listing = $qa->fetch();
      if (!$listing) throw new RuntimeException('Listing not found.');
      $price2 = (int)$listing['market_price'];
      $currency = $listing['market_currency'] ?? 'credits';
      $pdo->beginTransaction();
      // Claim the listing first (guarded on on_market + seller) so two buyers can't both pay
      $qp = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE player_id=? AND is_primary=1'); $qp->execute([$pid]); $hasPrimary = (int)$qp->fetchColumn() > 0;
      $paidCreds = $currency === 'shards' ? 0 : $price2; // shard buys have no credit refund value
      $claim = $pdo->prepare('UPDATE player_apartments SET player_id=?, on_market=0, market_price=0, market_currency=\'credits\', is_primary=?, rented_to=NULL, paid_price=? WHERE id=? AND on_market=1 AND player_id=?');
      $claim->execute([$pid, $hasPrimary?0:1, $paidCreds, $aptId, $listing['player_id']]);
      if ($claim->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Listing no longer available.'); }
      if ($currency === 'shards') {
        $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
        $u->execute([$price2, $pid, $price2]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough shards.'); }
        $pdo->prepare('UPDATE players SET shards = shards + ? WHERE id=?')->execute([$price2, $listing['player_id']]);
      } else {
        $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
        $u->execute([$price2, $pid, $price2]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits.'); }
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$price2, $listing['player_id']]);
      }
      $pdo->commit();
      if (!$hasPrimary) perk_apply($pdo, $pid, (int)$listing['apt_type_id'], $APT_TYPES, true);
      $msg = 'Purchased ' . $APT_TYPES[$listing['apt_type_id']]['name'] . ' from ' . $listing['seller_name'] . '!';
      $player = current_player();
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$tab = in_array($_GET['tab'] ?? '', ['mine','buy','market']) ? $_GET['tab'] : 'mine';
$myApts = [];
try { $q = $pdo->prepare('SELECT * FROM player_apartments WHERE player_id=? ORDER BY is_primary DESC, id ASC'); $q->execute([$pid]); $myApts = $q->fetchAll(); } catch (Throwable $e) {}
$marketListings = [];
try { $marketListings = $pdo->query("SELECT pa.*, p.username AS seller_name FROM player_apartments pa JOIN players p ON p.id=pa.player_id WHERE pa.on_market=1 AND pa.player_id != {$pid} ORDER BY pa.market_price ASC LIMIT 50")->fetchAll(); } catch (Throwable $e) {}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8a33d,var(--neon2),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#127968; Apartment Complex</h2>
    <p class="muted" style="margin:0;font-size:12px">Own your corner of the Sprawl. Primary residence grants stat perks; extras can be rented for passive income.</p>
  </div>
</div>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;flex-wrap:wrap">
  <?php foreach (['mine'=>'&#127968; My Properties ('.count($myApts).')','buy'=>'&#128722; Buy New','market'=>'&#127974; Market ('.count($marketListings).')'] as $tid=>$tl): ?>
  <a href="index.php?p=apartments&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? '#e8a33d' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(232,163,61,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? '#e8a33d' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<!-- ── MY PROPERTIES ── -->
<?php if ($tab === 'mine'): ?>
<?php if (empty($myApts)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:24px">You own no properties. Head to the Buy or Market tabs.</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:10px">
  <?php foreach ($myApts as $a):
    $atype = $APT_TYPES[$a['apt_type_id']] ?? null; if (!$atype) continue;
    $rc = $RARITY_COLORS[$atype['rarity']];
  ?>
  <div class="panel" style="border:1px solid <?= $a['is_primary'] ? 'rgba(232,163,61,.4)' : 'var(--line)' ?>;<?= $a['is_primary'] ? 'background:rgba(232,163,61,.03)' : '' ?>">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <?php if ($a['is_primary']): ?><div style="font-size:10px;color:#e8a33d;font-family:'Orbitron',sans-serif;margin-bottom:4px">PRIMARY RESIDENCE</div><?php endif; ?>
        <div style="font-weight:700;font-size:14px;color:<?= $rc ?>"><?= e($atype['name']) ?></div>
        <div style="font-size:12px;color:var(--muted);margin-top:2px"><?= e($atype['region']) ?></div>
        <div style="font-size:11px;margin-top:4px">
          <?php if ($a['is_primary']): ?>
            <span style="color:#3bcf63">&#10003; Perks active: <?= e($atype['perks']) ?></span>
          <?php else: ?>
            <span style="color:var(--muted)">&#9888; Secondary — no perks (not primary)</span>
          <?php endif; ?>
        </div>
        <?php if ($a['rented_to']): ?>
          <div style="font-size:11px;color:#3bcf63;margin-top:4px">Rented out &mdash; <?= number_format($a['rent_amount']) ?> cr/day</div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if (!$a['is_primary'] && !$a['rented_to']): ?>
        <form method="post" style="margin:0"><input type="hidden" name="action" value="setprimary"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px">Set Primary</button></form>
        <?php endif; ?>
        <?php if (!$a['is_primary'] && !$a['rented_to'] && !$a['on_market']): ?>
          <button style="font-size:11px;padding:5px 10px" onclick="this.closest('.panel').querySelector('.rent-form').style.display='block'">Rent Out</button>
          <button style="font-size:11px;padding:5px 10px" onclick="this.closest('.panel').querySelector('.sell-form').style.display='block'">List for Sale</button>
          <button style="font-size:11px;padding:5px 10px;color:var(--neon2);border-color:rgba(255,45,149,.3)" onclick="this.closest('.panel').querySelector('.sellback-form').style.display='block'">Sell Back</button>
        <?php endif; ?>
        <button style="font-size:11px;padding:5px 10px" onclick="this.closest('.panel').querySelector('.decor-form').style.display=this.closest('.panel').querySelector('.decor-form').style.display==='block'?'none':'block'">&#128268; Furnish</button>
        <?php if ($a['rented_to']): ?>
          <form method="post" style="margin:0"><input type="hidden" name="action" value="end_rent"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px;color:var(--neon2);border-color:rgba(255,45,149,.3)">End Rental</button></form>
        <?php endif; ?>
        <?php if ($a['on_market']): $aCur = $a['market_currency'] ?? 'credits'; ?>
          <span style="font-size:11px;color:#e8a33d;padding:5px 10px;border:1px solid rgba(232,163,61,.3);border-radius:5px">On Market — <?= number_format($a['market_price']) ?> <?= $aCur==='shards'?'&#9670; shards':'cr' ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="rent-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <form method="post"><input type="hidden" name="action" value="rent_out"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px">
          <div class="xfer-to-wrap" style="flex:1;min-width:120px">
            <input type="text" name="renter" class="rent-ac-inp" placeholder="Renter's handle" autocomplete="off" maxlength="32" style="width:100%">
            <div class="ac-list rent-ac-list" style="display:none"></div>
          </div>
          <input type="number" name="rent_amount" min="1" placeholder="Daily rent (cr)" style="width:130px">
          <button type="submit" style="font-size:12px">Confirm Rental</button>
        </div>
      </form>
    </div>
    <div class="sell-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <form method="post"><input type="hidden" name="action" value="list_market"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px">
          <input type="number" name="market_price" min="1" placeholder="Price" style="flex:1;min-width:100px">
          <label style="display:flex;align-items:center;gap:4px;cursor:pointer"><input type="radio" name="market_currency" value="credits" checked style="width:auto"> Credits</label>
          <label style="display:flex;align-items:center;gap:4px;cursor:pointer"><input type="radio" name="market_currency" value="shards" style="width:auto"> <span style="color:#e8d44d">Shards</span></label>
          <button type="submit" style="font-size:12px">List on Market</button>
        </div>
      </form>
    </div>
    <?php if (!$a['is_primary']): ?>
    <div class="sellback-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <p style="margin:0 0 8px;font-size:12px;color:var(--muted)">Sell back to system for <b style="color:var(--accent)"><?= number_format((int)($atype['price'] / 2)) ?> cr</b> (50% of original price). This cannot be undone.</p>
      <form method="post" onsubmit="return confirm('Sell this apartment back for <?= number_format((int)($atype['price'] / 2)) ?> credits? This is permanent.')">
        <input type="hidden" name="action" value="sellback"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <button type="submit" style="font-size:12px;color:var(--neon2);border-color:rgba(255,45,149,.3)">Confirm Sell Back</button>
      </form>
    </div>
    <?php endif; ?>
    <?php
      $aptDecor = [];
      try { $dq = $pdo->prepare('SELECT * FROM apartment_decor WHERE apt_id=? ORDER BY placed_at ASC'); $dq->execute([$a['id']]); $aptDecor = $dq->fetchAll(); } catch (Throwable $e) {}
    ?>
    <div class="decor-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <div style="font-size:12px;font-weight:700;margin-bottom:8px">&#128268; Furnishings</div>
      <?php if (!empty($aptDecor)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
        <?php foreach ($aptDecor as $d): $di = $DECOR_ITEMS[$d['decor_key']] ?? null; if (!$di) continue; ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:6px 10px;font-size:11px;display:flex;align-items:center;gap:6px">
          <span><?= $di['icon'] ?> <?= e($di['name']) ?></span>
          <form method="post" style="margin:0"><input type="hidden" name="action" value="decor_remove"><input type="hidden" name="decor_id" value="<?= (int)$d['id'] ?>"><button type="submit" style="font-size:10px;padding:2px 6px;color:var(--neon2);border-color:rgba(255,45,149,.3)">&times;</button></form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p style="font-size:12px;color:var(--muted);margin:0 0 8px">No furnishings yet.</p>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px">
        <?php foreach ($DECOR_ITEMS as $dk => $di):
          $alreadyPlaced = !empty(array_filter($aptDecor, fn($d) => $d['decor_key'] === $dk));
          $canAfford = (int)$player['creds_pocket'] >= $di['price'];
        ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px 10px">
          <div style="font-size:13px;margin-bottom:3px"><?= $di['icon'] ?> <?= e($di['name']) ?></div>
          <div style="font-size:11px;color:var(--accent);margin-bottom:6px"><?= number_format($di['price']) ?> cr</div>
          <?php if ($alreadyPlaced): ?>
          <button disabled style="font-size:10px;width:100%;opacity:.4">Placed</button>
          <?php else: ?>
          <form method="post" style="margin:0"><input type="hidden" name="action" value="decor_buy"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="decor_key" value="<?= e($dk) ?>"><button type="submit" style="font-size:10px;width:100%" <?= !$canAfford ? 'disabled style="opacity:.4"' : '' ?>>Place</button></form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── BUY NEW ── -->
<?php elseif ($tab === 'buy'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
  <?php foreach ($APT_TYPES as $tid => $atype):
    $rc = $RARITY_COLORS[$atype['rarity']];
    $owned = false; foreach ($myApts as $a) { if ($a['apt_type_id'] === $tid) { $owned = true; break; } }
  ?>
  <div style="background:var(--panel2);border:1px solid <?= $atype['rarity']==='legendary'?'rgba(232,212,77,.4)':($atype['rarity']==='rare'?'rgba(255,45,149,.25)':'var(--line)') ?>;border-radius:9px;padding:16px;position:relative">
    <?php if ($atype['rarity'] !== 'common'): ?><div style="position:absolute;top:8px;right:10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;color:<?= $rc ?>;text-transform:uppercase"><?= $atype['rarity'] ?></div><?php endif; ?>
    <div style="font-size:22px;margin-bottom:8px">&#127968;</div>
    <div style="font-weight:700;font-size:14px;color:<?= $rc ?>;margin-bottom:3px"><?= e($atype['name']) ?></div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:6px">&#128205; <?= e($atype['region']) ?></div>
    <div style="font-size:12px;color:#3bcf63;margin-bottom:10px">&#10003; <?= e($atype['perks']) ?></div>
    <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--accent);margin-bottom:10px"><?= number_format($atype['price']) ?> <span style="font-size:11px;font-weight:400;color:var(--muted)">cr</span></div>
    <?php if ($owned): ?>
      <button disabled style="width:100%;opacity:.4;font-size:12px">Already Owned</button>
    <?php else: ?>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="buy"><input type="hidden" name="type_id" value="<?= $tid ?>"><button type="submit" style="width:100%;font-size:12px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)" <?= (int)$player['creds_pocket'] < $atype['price'] ? 'disabled' : '' ?>>Purchase</button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── MARKET ── -->
<?php elseif ($tab === 'market'): ?>
<?php if (empty($marketListings)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:24px">No apartments listed on the market right now.</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:8px">
  <?php foreach ($marketListings as $l):
    $atype = $APT_TYPES[$l['apt_type_id']] ?? null; if (!$atype) continue;
    $rc = $RARITY_COLORS[$atype['rarity']];
  ?>
  <div class="panel" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <div style="font-weight:700;font-size:13px;color:<?= $rc ?>"><?= e($atype['name']) ?></div>
      <div style="font-size:11px;color:var(--muted)">&#128205; <?= e($atype['region']) ?> &middot; <?= e($atype['perks']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px">Seller: <?= e($l['seller_name']) ?></div>
    </div>
    <?php $lCur = $l['market_currency'] ?? 'credits'; $lAfford = $lCur === 'shards' ? (int)$player['shards'] >= $l['market_price'] : (int)$player['creds_pocket'] >= $l['market_price']; ?>
    <div style="display:flex;gap:10px;align-items:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:<?= $lCur==='shards'?'#e8d44d':'var(--accent)' ?>"><?= number_format($l['market_price']) ?> <?= $lCur==='shards'?'&#9670;':'cr' ?></div>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="market_buy"><input type="hidden" name="apt_id" value="<?= (int)$l['id'] ?>"><button type="submit" style="font-size:12px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)" <?= !$lAfford ? 'disabled' : '' ?>>Buy</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<script>
(function(){
  document.querySelectorAll('.rent-ac-inp').forEach(function(inp){
    var list=inp.closest('.xfer-to-wrap').querySelector('.rent-ac-list');
    if(!inp||!list) return;
    var cur=-1, items=[];
    function show(names){
      items=names; cur=-1;
      if(!names.length){ list.style.display='none'; return; }
      list.innerHTML=''; names.forEach(function(n,i){
        var d=document.createElement('div'); d.className='ac-item'; d.textContent=n;
        d.addEventListener('mousedown',function(e){ e.preventDefault(); inp.value=n; list.style.display='none'; });
        list.appendChild(d);
      }); list.style.display='block';
    }
    inp.addEventListener('input',function(){
      var q=inp.value.trim(); if(q.length<1){ list.style.display='none'; return; }
      fetch('players_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'})
        .then(function(r){return r.json();}).then(show).catch(function(){});
    });
    inp.addEventListener('keydown',function(e){
      if(!items.length) return;
      var rows=list.querySelectorAll('.ac-item');
      if(e.key==='ArrowDown'){ e.preventDefault(); cur=Math.min(cur+1,rows.length-1); rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);}); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); cur=Math.max(cur-1,-1); rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);}); }
      else if(e.key==='Enter'&&cur>=0){ e.preventDefault(); inp.value=items[cur]; list.style.display='none'; }
      else if(e.key==='Escape'){ list.style.display='none'; }
    });
    document.addEventListener('click',function(e){ if(!inp.contains(e.target)&&!list.contains(e.target)) list.style.display='none'; });
  });
})();
</script>
