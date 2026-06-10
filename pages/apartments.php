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
    purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_player (player_id), INDEX idx_market (on_market)
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
      $pdo->prepare('INSERT INTO player_apartments (player_id, apt_type_id, region, is_primary) VALUES (?,?,?,?)')->execute([$pid, $typeId, $apt['region'], $hasPrimary ? 0 : 1]);
      $msg = 'Purchased ' . $apt['name'] . '!';
      if (!$hasPrimary) $msg .= ' Set as your primary residence (+perks active).';
      $player = current_player();

    } elseif ($act === 'setprimary') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $qa = $pdo->prepare('SELECT id FROM player_apartments WHERE id=? AND player_id=? AND rented_to IS NULL'); $qa->execute([$aptId, $pid]); if (!$qa->fetchColumn()) throw new RuntimeException('Not found or currently rented out.');
      $pdo->prepare('UPDATE player_apartments SET is_primary=0 WHERE player_id=?')->execute([$pid]);
      $pdo->prepare('UPDATE player_apartments SET is_primary=1 WHERE id=? AND player_id=?')->execute([$aptId, $pid]);
      $msg = 'Primary residence updated.';

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

    } elseif ($act === 'list_market') {
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $mktPrice = max(1, (int)($_POST['market_price'] ?? 0));
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND rented_to IS NULL AND on_market=0'); $qa->execute([$aptId, $pid]); if (!$qa->fetch()) throw new RuntimeException('Cannot list — not found or unavailable.');
      $pdo->prepare('UPDATE player_apartments SET on_market=1, market_price=? WHERE id=?')->execute([$mktPrice, $aptId]);
      $msg = 'Listed on the apartment market for ' . number_format($mktPrice) . ' credits.';

    } elseif ($act === 'market_buy') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $qa = $pdo->prepare('SELECT pa.*, p.username AS seller_name FROM player_apartments pa JOIN players p ON p.id=pa.player_id WHERE pa.id=? AND pa.on_market=1 AND pa.player_id != ?');
      $qa->execute([$aptId, $pid]); $listing = $qa->fetch();
      if (!$listing) throw new RuntimeException('Listing not found.');
      $price2 = (int)$listing['market_price'];
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$price2, $pid, $price2]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits.'); }
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$price2, $listing['player_id']]);
      // Check if buyer has a primary
      $qp = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE player_id=? AND is_primary=1'); $qp->execute([$pid]); $hasPrimary = (int)$qp->fetchColumn() > 0;
      $pdo->prepare('UPDATE player_apartments SET player_id=?, on_market=0, market_price=0, is_primary=?, rented_to=NULL WHERE id=?')->execute([$pid, $hasPrimary?0:1, $aptId]);
      $pdo->commit();
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
        <?php endif; ?>
        <?php if ($a['rented_to']): ?>
          <form method="post" style="margin:0"><input type="hidden" name="action" value="end_rent"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px;color:var(--neon2);border-color:rgba(255,45,149,.3)">End Rental</button></form>
        <?php endif; ?>
        <?php if ($a['on_market']): ?>
          <span style="font-size:11px;color:#e8a33d;padding:5px 10px;border:1px solid rgba(232,163,61,.3);border-radius:5px">On Market — <?= number_format($a['market_price']) ?> cr</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="rent-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <form method="post"><input type="hidden" name="action" value="rent_out"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px">
          <input type="text" name="renter" placeholder="Renter's handle" style="flex:1;min-width:120px">
          <input type="number" name="rent_amount" min="1" placeholder="Daily rent (cr)" style="width:130px">
          <button type="submit" style="font-size:12px">Confirm Rental</button>
        </div>
      </form>
    </div>
    <div class="sell-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <form method="post"><input type="hidden" name="action" value="list_market"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <div style="display:flex;gap:8px;align-items:center;font-size:12px">
          <input type="number" name="market_price" min="1" placeholder="Listing price (cr)" style="flex:1">
          <button type="submit" style="font-size:12px">List on Market</button>
        </div>
      </form>
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
      <form method="post" style="margin:0"><input type="hidden" name="action" value="buy"><input type="hidden" name="type_id" value="<?= $tid ?>"><button type="submit" style="width:100%;font-size:12px" <?= (int)$player['creds_pocket'] < $atype['price'] ? 'disabled style="opacity:.4"' : '' ?>>Purchase</button></form>
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
    <div style="display:flex;gap:10px;align-items:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:var(--accent)"><?= number_format($l['market_price']) ?> cr</div>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="market_buy"><input type="hidden" name="apt_id" value="<?= (int)$l['id'] ?>"><button type="submit" style="font-size:12px" <?= (int)$player['creds_pocket'] < $l['market_price'] ? 'disabled style="opacity:.4"' : '' ?>>Buy</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
