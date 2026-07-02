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
// DB-level backstop for "own at most one of each type" — the 'buy' handler
// already checks this before inserting, but without a real constraint two
// near-simultaneous buy requests could both pass that check and both insert.
// Silently no-ops (try/catch) if a host already has legacy duplicate rows.
try { $pdo->exec("ALTER TABLE player_apartments ADD UNIQUE KEY uq_player_type (player_id, apt_type_id)"); } catch (Throwable $e) {}
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS apartment_decor (
    id INT AUTO_INCREMENT PRIMARY KEY, apt_id INT NOT NULL, player_id INT NOT NULL,
    decor_key VARCHAR(40) NOT NULL, placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apt (apt_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
// Rental contracts — a rental is now a proposal both sides have to agree to,
// not an instant one-sided action. Renter accepts/declines from a Hideout
// notification; on accept the contract runs for a fixed number of days and
// the renter gets RENT_PERK_PCT of the unit's perk (not the full amount) —
// see perk_apply_partial() below. Kept separate from player_apartments'
// existing rented_to column (still used to mark the unit unavailable to
// list/sell/re-rent while a contract is pending or active) rather than
// folding contract state into that table.
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS apartment_rentals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    apt_id INT NOT NULL, owner_id INT NOT NULL, renter_id INT NOT NULL,
    days INT NOT NULL, rent_amount INT NOT NULL,
    status ENUM('pending','active','expired','declined','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL, expires_at DATETIME NULL,
    INDEX idx_apt (apt_id), INDEX idx_renter (renter_id, status), INDEX idx_owner (owner_id, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
const RENT_PERK_PCT = 0.5; // renters get half the owner's perk value while a rental is active

// Same idea as perk_apply() but for the reduced rental-perk, tracked under
// a separate settings namespace (apt_rent_perk_tid) so it can never collide
// with or overwrite a player's own owned-residence perk tracking — a player
// can have both an owned primary residence AND an active rental at once,
// each contributing independently.
function perk_apply_partial($pdo, $pid, $typeId, $APT_TYPES, $add = true) {
  if (!isset($APT_TYPES[$typeId])) return;
  $key = $APT_TYPES[$typeId]['perk_key'];
  $fullVal = (int)$APT_TYPES[$typeId]['perk_val'];
  $val = max(1, (int)round($fullVal * RENT_PERK_PCT));
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
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["apt_{$key}_rent:{$pid}", $val]);
      else
        $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["apt_{$key}_rent:{$pid}"]);
    }
    if ($add)
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["apt_rent_perk_tid:{$pid}", $typeId]);
    else
      $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["apt_rent_perk_tid:{$pid}"]);
  } catch (Throwable $e) {}
}


// Apartment type definitions. district: a coarse wealth tier for the
// neighborhood each region belongs to (poor/working/affluent/elite) — used
// to group the Buy tab and to tint the placeholder building art, so poorer
// districts visibly look rougher and richer ones look nicer, not just
// differently priced. max_units: how many of this type exist citywide —
// once every unit is owned, nobody can buy a new one (existing owners can
// still resell theirs on the player market).
// $DISTRICT_META/$APT_TYPES moved to lib.php's apartment_catalog() so
// library.php can list every district/type too (previously this whole
// catalog only existed inside apartments.php).
$aptCat = apartment_catalog();
$DISTRICT_META = $aptCat['districts'];
$APT_TYPES = $aptCat['types'];

$RARITY_COLORS = ['common'=>'var(--muted)','uncommon'=>'var(--accent)','rare'=>'var(--neon2)','legendary'=>'#e8d44d'];
$RARITY_FLOORS = ['common'=>4,'uncommon'=>5,'rare'=>7,'legendary'=>9];

// Lazy rental-contract expiry — same self-healing pattern as the daily
// reset: checked on any page load rather than needing a cron job. Any
// active contract whose time is up gets closed out and the renter's
// partial perk revoked. Needs $APT_TYPES, so this runs after it's defined.
try {
  $expiring = $pdo->query("SELECT * FROM apartment_rentals WHERE status='active' AND expires_at <= NOW()")->fetchAll();
  foreach ($expiring as $exp) {
    // Guard the status flip on status='active' + rowCount so two concurrent
    // page loads (anywhere) can't both close the same contract and revoke the
    // renter's partial perk twice (a permanent stat LOSS). Only the load that
    // actually flips 'active'->'expired' does the revoke.
    $flip = $pdo->prepare("UPDATE apartment_rentals SET status='expired' WHERE id=? AND status='active'");
    $flip->execute([$exp['id']]);
    if ($flip->rowCount() !== 1) continue;
    $pdo->prepare("UPDATE player_apartments SET rented_to=NULL, rent_amount=0 WHERE id=? AND rented_to=?")->execute([$exp['apt_id'], $exp['renter_id']]);
    $expApt = $pdo->prepare('SELECT apt_type_id FROM player_apartments WHERE id=?'); $expApt->execute([$exp['apt_id']]);
    $expTid = (int)$expApt->fetchColumn();
    if ($expTid) perk_apply_partial($pdo, (int)$exp['renter_id'], $expTid, $APT_TYPES, false);
    try { $pdo->prepare("INSERT INTO player_notifications (player_id, type, body) VALUES (?, 'guild_loan', ?)")
      ->execute([(int)$exp['renter_id'], 'Your rental contract has expired — its perks are no longer active.']); } catch (Throwable $e) {}
  }
} catch (Throwable $e) {}

// apt_building_art() moved to lib.php so library.php can render the same
// placeholder "photo" for each apartment type.

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

      $pdo->beginTransaction();
      // Re-check ownership inside the transaction — the uq_player_type
      // unique key (see schema block above) is the real backstop against two
      // simultaneous buys both passing this check, but re-checking here
      // avoids relying solely on catching a constraint-violation exception.
      $qc = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE player_id=? AND apt_type_id=?');
      $qc->execute([$pid, $typeId]);
      if ($qc->fetchColumn() >= 1) { $pdo->rollBack(); throw new RuntimeException('You already own one of this apartment type.'); }

      // Citywide supply cap — once every unit of this type is owned, no new
      // ones can be bought (existing owners can still resell theirs on the
      // player market, which is how a sold-out type becomes available again).
      $maxUnits = (int)($apt['max_units'] ?? 0);
      if ($maxUnits > 0) {
        $qu = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE apt_type_id=?');
        $qu->execute([$typeId]);
        if ((int)$qu->fetchColumn() >= $maxUnits) { $pdo->rollBack(); throw new RuntimeException('Every ' . $apt['name'] . ' in the city is currently owned — check the Market tab for a resale.'); }
      }

      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$price, $pid, $price]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits in pocket.'); }

      // Check if they have a primary residence. Lock this player's apartment
      // rows FOR UPDATE first so two concurrent first-buys can't both see no
      // primary, both write is_primary=1, and both fire perk_apply() (a doubled
      // permanent perk). Serialized here, the second buy sees the first's row.
      $qp = $pdo->prepare('SELECT COUNT(*) FROM player_apartments WHERE player_id=? AND is_primary=1 FOR UPDATE'); $qp->execute([$pid]);
      $hasPrimary = (int)$qp->fetchColumn() > 0;
      try {
        $pdo->prepare('INSERT INTO player_apartments (player_id, apt_type_id, region, is_primary, paid_price) VALUES (?,?,?,?,?)')->execute([$pid, $typeId, $apt['region'], $hasPrimary ? 0 : 1, $price]);
      } catch (Throwable $dupEx) {
        $pdo->rollBack();
        throw new RuntimeException('You already own one of this apartment type.');
      }
      $pdo->commit();
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

    } elseif ($act === 'propose_rental') {
      // A rental offer, not an instant rental — nothing happens to the
      // apartment or either player's credits until the renter accepts from
      // their Hideout notification. See accept_rental/decline_rental below.
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $days     = max(1, min(90, (int)($_POST['days'] ?? 0)));
      $rentAmt  = max(1, (int)($_POST['rent_amount'] ?? 0));
      $toHandle = trim($_POST['renter'] ?? '');
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND is_primary=0 AND rented_to IS NULL AND on_market=0'); $qa->execute([$aptId, $pid]); $apt2 = $qa->fetch();
      if (!$apt2) throw new RuntimeException('Cannot propose a rental — not found, is your primary residence, already rented, or listed for sale.');
      $peq = $pdo->prepare("SELECT id FROM apartment_rentals WHERE apt_id=? AND status='pending'"); $peq->execute([$aptId]);
      if ($peq->fetchColumn()) throw new RuntimeException('A rental offer is already pending on this apartment.');
      // Renter can be given by ID or username, matching players_search.php's lookup convention.
      if (ctype_digit($toHandle)) { $qt = $pdo->prepare('SELECT id FROM players WHERE id=?'); $qt->execute([(int)$toHandle]); }
      else { $qt = $pdo->prepare('SELECT id FROM players WHERE username=?'); $qt->execute([$toHandle]); }
      $renterid = $qt->fetchColumn();
      if (!$renterid) throw new RuntimeException('Player "' . $toHandle . '" not found.');
      if ((int)$renterid === $pid) throw new RuntimeException("You can't rent to yourself.");
      $pdo->prepare("INSERT INTO apartment_rentals (apt_id, owner_id, renter_id, days, rent_amount, status) VALUES (?,?,?,?,?,'pending')")
          ->execute([$aptId, $pid, $renterid, $days, $rentAmt]);
      $atypeName = $APT_TYPES[$apt2['apt_type_id']]['name'] ?? 'an apartment';
      try { $pdo->prepare("INSERT INTO player_notifications (player_id, type, body) VALUES (?, 'guild_loan', ?)")
        ->execute([(int)$renterid, e($player['username']) . ' wants to rent you &ldquo;' . e($atypeName) . '&rdquo; for ' . $days . ' day' . ($days!==1?'s':'') . ' at ' . number_format($rentAmt) . ' credits total. <a href="index.php?p=apartments&tab=mine">Review the offer &rarr;</a>']); } catch (Throwable $e) {}
      $msg = 'Rental offer sent to ' . $toHandle . ' — waiting on their response.';

    } elseif ($act === 'cancel_rental_offer') {
      $contractId = (int)($_POST['contract_id'] ?? 0);
      $up = $pdo->prepare("UPDATE apartment_rentals SET status='cancelled', responded_at=NOW() WHERE id=? AND owner_id=? AND status='pending'");
      $up->execute([$contractId, $pid]);
      if ($up->rowCount() !== 1) throw new RuntimeException('Offer not found or already resolved.');
      $msg = 'Rental offer withdrawn.';

    } elseif ($act === 'accept_rental') {
      $contractId = (int)($_POST['contract_id'] ?? 0);
      $pdo->beginTransaction();
      // Claim the pending offer inside the txn with a status guard + rowCount:
      // two parallel accepts (same player, two sessions) would otherwise both
      // read status='pending', both charge rent, and both apply the partial
      // perk — the perk gets revoked only once at expiry, leaving a permanent
      // stat gain. Only the accept that actually flips 'pending'->'active' here
      // proceeds; the loser rolls back with an error.
      $cq = $pdo->prepare("SELECT * FROM apartment_rentals WHERE id=? AND renter_id=? AND status='pending' FOR UPDATE");
      $cq->execute([$contractId, $pid]); $contract = $cq->fetch();
      if (!$contract) { $pdo->rollBack(); throw new RuntimeException('Offer not found or already resolved.'); }
      $aq = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? FOR UPDATE'); $aq->execute([$contract['apt_id']]); $aptRow2 = $aq->fetch();
      if (!$aptRow2 || $aptRow2['rented_to'] !== null) { $pdo->rollBack(); throw new RuntimeException('That apartment is no longer available.'); }
      // One-active-rental check, now INSIDE the txn (was a SELECT outside it) so
      // two different pending offers can't both be accepted into stacked perks.
      $existingRentalQ = $pdo->prepare("SELECT id FROM apartment_rentals WHERE renter_id=? AND status='active'"); $existingRentalQ->execute([$pid]);
      if ($existingRentalQ->fetchColumn()) { $pdo->rollBack(); throw new RuntimeException('You already have an active rental — end it before accepting another.'); }
      // Claim: flip pending->active first, guarded, so a racing accept fails here.
      $claim = $pdo->prepare("UPDATE apartment_rentals SET status='active', responded_at=NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=? AND status='pending'");
      $claim->execute([(int)$contract['days'], $contractId]);
      if ($claim->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('This rental offer is no longer available.'); }
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([(int)$contract['rent_amount'], $pid, (int)$contract['rent_amount']]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits to accept — need ' . number_format($contract['rent_amount']) . '.'); }
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([(int)$contract['rent_amount'], (int)$contract['owner_id']]);
      $pdo->prepare('UPDATE player_apartments SET rented_to=?, rent_amount=? WHERE id=?')->execute([$pid, (int)$contract['rent_amount'], $contract['apt_id']]);
      $pdo->commit();
      perk_apply_partial($pdo, $pid, (int)$aptRow2['apt_type_id'], $APT_TYPES, true);
      try { $pdo->prepare("INSERT INTO player_notifications (player_id, type, body) VALUES (?, 'guild_loan', ?)")
        ->execute([(int)$contract['owner_id'], e($player['username']) . ' accepted your rental offer for ' . (int)$contract['days'] . ' days.']); } catch (Throwable $e) {}
      $msg = 'Rental accepted! ' . (int)$contract['days'] . '-day contract is now active — you\'re getting a share of its perks.';
      $player = current_player();

    } elseif ($act === 'decline_rental') {
      $contractId = (int)($_POST['contract_id'] ?? 0);
      $cq = $pdo->prepare("SELECT * FROM apartment_rentals WHERE id=? AND renter_id=? AND status='pending'");
      $cq->execute([$contractId, $pid]); $contract = $cq->fetch();
      if (!$contract) throw new RuntimeException('Offer not found or already resolved.');
      $pdo->prepare("UPDATE apartment_rentals SET status='declined', responded_at=NOW() WHERE id=?")->execute([$contractId]);
      try { $pdo->prepare("INSERT INTO player_notifications (player_id, type, body) VALUES (?, 'guild_loan', ?)")
        ->execute([(int)$contract['owner_id'], e($player['username']) . ' declined your rental offer.']); } catch (Throwable $e) {}
      $msg = 'Offer declined.';

    } elseif ($act === 'end_rent') {
      // Owner-initiated early termination — no refund of the (already paid,
      // lump-sum) rent, same tradeoff as apartment sellback's irreversible
      // "you're choosing this" framing. Revokes the renter's partial perk.
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $aq = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=?'); $aq->execute([$aptId, $pid]); $endApt = $aq->fetch();
      if (!$endApt || !$endApt['rented_to']) throw new RuntimeException('This apartment is not currently rented.');
      $renterIdEnd = (int)$endApt['rented_to'];
      $pdo->prepare('UPDATE player_apartments SET rented_to=NULL, rent_amount=0 WHERE id=? AND player_id=?')->execute([$aptId, $pid]);
      // Guard the contract close on status='active' + rowCount so this can't
      // race the lazy expiry (above) into revoking the renter's perk twice.
      // Only the caller that actually flips the active contract does the revoke.
      $cxl = $pdo->prepare("UPDATE apartment_rentals SET status='cancelled', responded_at=NOW() WHERE apt_id=? AND status='active'");
      $cxl->execute([$aptId]);
      if ($cxl->rowCount() === 1) {
        perk_apply_partial($pdo, $renterIdEnd, (int)$endApt['apt_type_id'], $APT_TYPES, false);
        try { $pdo->prepare("INSERT INTO player_notifications (player_id, type, body) VALUES (?, 'guild_loan', ?)")
          ->execute([$renterIdEnd, 'Your rental contract was ended early by the owner — its perks are no longer active.']); } catch (Throwable $e) {}
      }
      $msg = 'Rental ended.';

    } elseif ($act === 'sellback') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      // No longer requires is_primary=0 — an only/primary residence can be
      // sold back too (you're choosing to give up its perks along with it).
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND on_market=0 AND rented_to IS NULL');
      $qa->execute([$aptId, $pid]); $aptRow = $qa->fetch();
      if (!$aptRow) throw new RuntimeException('Cannot sell back — not found or unavailable.');
      $peq2 = $pdo->prepare("SELECT id FROM apartment_rentals WHERE apt_id=? AND status='pending'"); $peq2->execute([$aptId]);
      if ($peq2->fetchColumn()) throw new RuntimeException('Cannot sell back — a rental offer is pending on this apartment. Withdraw it first.');
      $atype = $APT_TYPES[$aptRow['apt_type_id']] ?? null;
      if (!$atype) throw new RuntimeException('Unknown apartment type.');
      // Refund 50% of what was actually paid (capped at catalog) so cheap player-market
      // purchases can't be flipped to the system for profit. Legacy rows = catalog price.
      $paid   = $aptRow['paid_price'] !== null ? (int)$aptRow['paid_price'] : (int)$atype['price'];
      $refund = (int)(min((int)$atype['price'], $paid) / 2);
      $wasPrimary = (int)$aptRow['is_primary'] === 1;
      $pdo->beginTransaction();
      try { $pdo->prepare('DELETE FROM apartment_decor WHERE apt_id=?')->execute([$aptId]); } catch (Throwable $e) {}
      $pdo->prepare('DELETE FROM player_apartments WHERE id=? AND player_id=?')->execute([$aptId, $pid]);
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$refund, $pid]);
      $pdo->commit();
      // Revoke the perk if this was the residence granting it — otherwise
      // selling your primary would let you keep its bonuses forever.
      if ($wasPrimary) perk_apply($pdo, $pid, (int)$aptRow['apt_type_id'], $APT_TYPES, false);
      $msg = 'Sold back ' . $atype['name'] . ' — refunded ' . number_format($refund) . ' credits (50% return).';
      if ($wasPrimary) $msg .= ' This was your primary residence, so its perks are gone too.';
      $player = current_player();

    } elseif ($act === 'decor_buy') {
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $decorKey = $_POST['decor_key'] ?? '';
      if (!isset($DECOR_ITEMS[$decorKey])) throw new RuntimeException('Invalid decor item.');
      $qa = $pdo->prepare('SELECT id FROM player_apartments WHERE id=? AND player_id=?'); $qa->execute([$aptId, $pid]); if (!$qa->fetchColumn()) throw new RuntimeException('Apartment not found.');
      // Server-side duplicate guard — the client only disables the "Placed"
      // button, so a forged/replayed POST could place (and pay for) the same
      // decor twice. Reject if this apartment already has this item.
      $dupq = $pdo->prepare('SELECT id FROM apartment_decor WHERE apt_id=? AND decor_key=?'); $dupq->execute([$aptId, $decorKey]);
      if ($dupq->fetchColumn()) throw new RuntimeException('That furnishing is already placed in this apartment.');
      $decor = $DECOR_ITEMS[$decorKey];
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$decor['price'], $pid, $decor['price']]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits (costs ' . number_format($decor['price']) . ' credits).');
      $pdo->prepare('INSERT INTO apartment_decor (apt_id, player_id, decor_key) VALUES (?,?,?)')->execute([$aptId, $pid, $decorKey]);
      $msg = $decor['name'] . ' placed in your apartment!';
      $player = current_player();

    } elseif ($act === 'decor_remove') {
      // Sell-back, not a free delete — matches the apartment sellback
      // convention (50% of what it cost, no arbitrage from buy-then-remove).
      $decorId = (int)($_POST['decor_id'] ?? 0);
      $dq = $pdo->prepare('SELECT decor_key FROM apartment_decor WHERE id=? AND player_id=?');
      $dq->execute([$decorId, $pid]); $dKey = $dq->fetchColumn();
      if (!$dKey) throw new RuntimeException('Furnishing not found.');
      $dItem = $DECOR_ITEMS[$dKey] ?? null;
      $refund = $dItem ? (int)floor($dItem['price'] / 2) : 0;
      $pdo->beginTransaction();
      $del = $pdo->prepare('DELETE FROM apartment_decor WHERE id=? AND player_id=?');
      $del->execute([$decorId, $pid]);
      if ($del->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Furnishing not found.'); }
      if ($refund > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$refund, $pid]);
      $pdo->commit();
      $msg = $refund > 0 ? ('Sold back ' . $dItem['name'] . ' for ' . number_format($refund) . ' credits (50% return).') : 'Furnishing removed.';
      $player = current_player();

    } elseif ($act === 'list_market') {
      $aptId    = (int)($_POST['apt_id'] ?? 0);
      $mktPrice = max(1, (int)($_POST['market_price'] ?? 0));
      $currency = ($_POST['market_currency'] ?? 'credits') === 'shards' ? 'shards' : 'credits';
      // This used to also require no OTHER player anywhere already had a
      // listing for the same apartment type — a global one-per-type cap
      // across the whole market, not per-player. With only 7 types total,
      // that meant the market filled up almost immediately and nobody else
      // could ever list theirs. Removed; a real market allows multiple
      // independent sellers listing the same type.
      $qa = $pdo->prepare('SELECT * FROM player_apartments WHERE id=? AND player_id=? AND rented_to IS NULL AND on_market=0'); $qa->execute([$aptId, $pid]); $aptRow = $qa->fetch(); if (!$aptRow) throw new RuntimeException('Cannot list — not found or unavailable.');
      $peq3 = $pdo->prepare("SELECT id FROM apartment_rentals WHERE apt_id=? AND status='pending'"); $peq3->execute([$aptId]);
      if ($peq3->fetchColumn()) throw new RuntimeException('Cannot list — a rental offer is pending on this apartment. Withdraw it first.');
      $pdo->prepare('UPDATE player_apartments SET on_market=1, market_price=?, market_currency=? WHERE id=?')->execute([$mktPrice, $currency, $aptId]);
      $msg = 'Listed on the apartment market for ' . number_format($mktPrice) . ' ' . $currency . '. Manage or cancel it any time from My Properties.';

    } elseif ($act === 'cancel_listing') {
      $aptId = (int)($_POST['apt_id'] ?? 0);
      $up = $pdo->prepare('UPDATE player_apartments SET on_market=0, market_price=0, market_currency=\'credits\' WHERE id=? AND player_id=? AND on_market=1');
      $up->execute([$aptId, $pid]);
      if ($up->rowCount() !== 1) throw new RuntimeException('Listing not found.');
      $msg = 'Listing cancelled — no longer on the market.';

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
      // The seller could list their primary residence — if they did, revoke
      // their perk now that it no longer belongs to them, or they'd keep
      // its bonuses forever with nothing granting them.
      if ((int)$listing['is_primary'] === 1) perk_apply($pdo, (int)$listing['player_id'], (int)$listing['apt_type_id'], $APT_TYPES, false);
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
try { $mlq = $pdo->prepare("SELECT pa.*, p.username AS seller_name FROM player_apartments pa JOIN players p ON p.id=pa.player_id WHERE pa.on_market=1 AND pa.player_id != ? ORDER BY pa.market_price ASC LIMIT 50"); $mlq->execute([$pid]); $marketListings = $mlq->fetchAll(); } catch (Throwable $e) {}
$unitsOwned = [];
try { foreach ($pdo->query('SELECT apt_type_id, COUNT(*) AS cnt FROM player_apartments GROUP BY apt_type_id') as $r) $unitsOwned[(int)$r['apt_type_id']] = (int)$r['cnt']; } catch (Throwable $e) {}
$activeRentalsByApt = [];
try {
  $arq = $pdo->prepare("SELECT ar.apt_id, ar.expires_at, ar.days, p.username AS renter_name FROM apartment_rentals ar
    JOIN player_apartments pa ON pa.id = ar.apt_id JOIN players p ON p.id = ar.renter_id
    WHERE pa.player_id=? AND ar.status='active'");
  $arq->execute([$pid]);
  foreach ($arq as $r) $activeRentalsByApt[(int)$r['apt_id']] = $r;
} catch (Throwable $e) {}
$incomingOffers = []; $outgoingOffers = [];
try {
  $ioq = $pdo->prepare("SELECT ar.*, pa.apt_type_id, p.username AS owner_name FROM apartment_rentals ar
    JOIN player_apartments pa ON pa.id = ar.apt_id JOIN players p ON p.id = ar.owner_id
    WHERE ar.renter_id=? AND ar.status='pending' ORDER BY ar.created_at DESC");
  $ioq->execute([$pid]); $incomingOffers = $ioq->fetchAll();
  $ooq = $pdo->prepare("SELECT ar.*, pa.apt_type_id, p.username AS renter_name FROM apartment_rentals ar
    JOIN player_apartments pa ON pa.id = ar.apt_id JOIN players p ON p.id = ar.renter_id
    WHERE ar.owner_id=? AND ar.status='pending' ORDER BY ar.created_at DESC");
  $ooq->execute([$pid]); $outgoingOffers = $ooq->fetchAll();
} catch (Throwable $e) {}
// Up to 6 sample neighbors per type, for the detail popup's "who else lives
// here" list — one query grouped by type rather than 40 separate ones.
$neighborsByType = [];
try {
  $nb = $pdo->query('SELECT pa.apt_type_id, p.id, p.username, p.role FROM player_apartments pa JOIN players p ON p.id = pa.player_id ORDER BY pa.apt_type_id, pa.purchased_at DESC');
  foreach ($nb as $r) {
    $tid2 = (int)$r['apt_type_id'];
    if (!isset($neighborsByType[$tid2])) $neighborsByType[$tid2] = [];
    if (count($neighborsByType[$tid2]) < 6) $neighborsByType[$tid2][] = ['id' => (int)$r['id'], 'username' => $r['username'], 'color' => chat_color($r['role'] ?? 'member', '')];
  }
} catch (Throwable $e) {}
?>
<style>
.apt-art{width:100%;height:130px;border-radius:9px 9px 0 0;overflow:hidden;position:relative;border-bottom:1px solid var(--line)}
.apt-art .apt-badge{position:absolute;top:10px;right:10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:3px 9px;border-radius:20px;backdrop-filter:blur(2px)}
.apt-art .apt-region{position:absolute;bottom:8px;left:12px;font-size:10px;color:#cfd4dc;text-shadow:0 1px 4px #000}
.apt-card{background:var(--panel2);border:1px solid var(--line);border-radius:9px;overflow:hidden;transition:transform .12s,box-shadow .15s}
.apt-card:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.35)}
.apt-card.primary{border-color:rgba(232,163,61,.45);box-shadow:0 0 16px rgba(232,163,61,.1)}
.apt-body{padding:14px 16px}
#aptDetailModal{align-items:center}
</style>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8a33d,var(--neon2),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#127968; Apartment Complex</h2>
    <p class="muted" style="margin:0;font-size:12px">Own your corner of the Sprawl. Primary residence grants stat perks; extras can be rented for passive income or resold on the player market.</p>
  </div>
</div>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach (['mine'=>'&#127968; My Properties ('.count($myApts).')','buy'=>'&#128722; Buy New','market'=>'&#127974; Market ('.count($marketListings).')'] as $tid=>$tl): ?>
  <a href="index.php?p=apartments&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? '#e8a33d' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(232,163,61,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? '#e8a33d' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<!-- ── MY PROPERTIES ── -->
<?php if ($tab === 'mine'): ?>
<?php if ($incomingOffers || $outgoingOffers): ?>
<div class="panel" style="border:1px solid rgba(232,163,61,.3);background:rgba(232,163,61,.04);margin-bottom:14px">
  <h3 style="margin-top:0;font-size:13px">&#128203; Rental Offers</h3>
  <?php foreach ($incomingOffers as $off):
    $offType = $APT_TYPES[$off['apt_type_id']] ?? null; if (!$offType) continue;
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">
    <div style="font-size:12px">
      <b style="color:var(--accent)"><?= e($off['owner_name']) ?></b> wants to rent you <b><?= e($offType['name']) ?></b>
      for <b><?= (int)$off['days'] ?></b> day<?= (int)$off['days']!==1?'s':'' ?> at <b style="color:#e8a33d"><?= number_format($off['rent_amount']) ?> credits</b> total
      <span class="muted">(you'll get <?= round(RENT_PERK_PCT*100) ?>% of: <?= e($offType['perks']) ?>)</span>
    </div>
    <div style="display:flex;gap:6px">
      <form method="post" style="margin:0"><input type="hidden" name="action" value="accept_rental"><input type="hidden" name="contract_id" value="<?= (int)$off['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px;background:rgba(59,207,99,.1);border-color:rgba(59,207,99,.35);color:#3bcf63">Accept</button></form>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="decline_rental"><input type="hidden" name="contract_id" value="<?= (int)$off['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px">Decline</button></form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php foreach ($outgoingOffers as $off):
    $offType = $APT_TYPES[$off['apt_type_id']] ?? null; if (!$offType) continue;
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">
    <div style="font-size:12px">Offered <b><?= e($offType['name']) ?></b> to <b><?= e($off['renter_name']) ?></b> &mdash; <?= (int)$off['days'] ?> days, <?= number_format($off['rent_amount']) ?> credits <span class="muted">(awaiting response)</span></div>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="cancel_rental_offer"><input type="hidden" name="contract_id" value="<?= (int)$off['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px">Withdraw</button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (empty($myApts)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:24px">You own no properties. Head to the Buy or Market tabs.</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px">
  <?php foreach ($myApts as $a):
    $atype = $APT_TYPES[$a['apt_type_id']] ?? null; if (!$atype) continue;
    $rc = $RARITY_COLORS[$atype['rarity']];
    $floors = $RARITY_FLOORS[$atype['rarity']] ?? 4;
  ?>
  <div class="apt-card<?= $a['is_primary'] ? ' primary' : '' ?>">
    <div style="display:flex;flex-wrap:wrap">
      <div class="apt-art" style="width:150px;flex:none;height:auto;border-radius:9px 0 0 9px;border-right:1px solid var(--line);border-bottom:none">
        <?= apt_building_art($rc, (int)$a['apt_type_id'], $floors, $atype['district'] ?? 'working') ?>
        <?php if ($a['is_primary']): ?><span class="apt-badge" style="color:#e8a33d;background:rgba(0,0,0,.5);border:1px solid #e8a33d">Primary</span><?php endif; ?>
      </div>
      <div class="apt-body" style="flex:1;min-width:220px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <div>
            <div style="font-weight:700;font-size:14px;color:<?= $rc ?>"><?= e($atype['name']) ?></div>
            <div style="font-size:12px;color:var(--muted);margin-top:2px">&#128205; <?= e($atype['region']) ?></div>
            <div style="font-size:11px;margin-top:4px">
              <?php if ($a['is_primary']): ?>
                <span style="color:#3bcf63">&#10003; Perks active: <?= e($atype['perks']) ?></span>
              <?php else: ?>
                <span style="color:var(--muted)">&#9888; Secondary — no perks (not primary)</span>
              <?php endif; ?>
            </div>
            <?php if ($a['rented_to']): $arInfo = $activeRentalsByApt[(int)$a['id']] ?? null; ?>
              <div style="font-size:11px;color:#3bcf63;margin-top:4px">
                Rented out for <?= number_format($a['rent_amount']) ?> credits
                <?php if ($arInfo): ?>
                  to <b><?= e($arInfo['renter_name']) ?></b> &mdash; ends <?= e(date('M j', strtotime($arInfo['expires_at']))) ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if (!$a['is_primary'] && !$a['rented_to']): ?>
            <form method="post" style="margin:0"><input type="hidden" name="action" value="setprimary"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px">Set Primary</button></form>
            <?php endif; ?>
            <?php if (!$a['is_primary'] && !$a['rented_to'] && !$a['on_market']): ?>
              <button style="font-size:11px;padding:5px 10px" onclick="this.closest('.apt-card').querySelector('.rent-form').style.display='block'">Rent Out</button>
            <?php endif; ?>
            <?php if (!$a['rented_to'] && !$a['on_market']): ?>
              <button style="font-size:11px;padding:5px 10px" onclick="this.closest('.apt-card').querySelector('.sell-form').style.display='block'">List for Sale</button>
              <button style="font-size:11px;padding:5px 10px" onclick="this.closest('.apt-card').querySelector('.sellback-form').style.display='block'">Sell Back</button>
            <?php endif; ?>
            <button style="font-size:11px;padding:5px 10px" onclick="var d=this.closest('.apt-card').querySelector('.decor-form');d.style.display=d.style.display==='block'?'none':'block'">Furnish</button>
            <?php if ($a['rented_to']): ?>
              <form method="post" style="margin:0"><input type="hidden" name="action" value="end_rent"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px;color:var(--neon2);border-color:rgba(255,45,149,.3)">End Rental</button></form>
            <?php endif; ?>
            <?php if ($a['on_market']): $aCur = $a['market_currency'] ?? 'credits'; ?>
              <span style="font-size:11px;color:#e8a33d;padding:5px 10px;border:1px solid rgba(232,163,61,.3);border-radius:5px">On Market — <?= number_format($a['market_price']) ?> <?= $aCur==='shards'?'&#9670; shards':'credits' ?></span>
              <form method="post" style="margin:0"><input type="hidden" name="action" value="cancel_listing"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>"><button type="submit" style="font-size:11px;padding:5px 10px;color:var(--neon2);border-color:rgba(255,45,149,.3)">Cancel Listing</button></form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div style="padding:0 16px 16px">
    <div class="rent-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <p class="muted" style="font-size:11px;margin:0 0 8px">This sends a rental offer — nothing happens until they accept it from their Hideout notifications. They'll get <?= round(RENT_PERK_PCT * 100) ?>% of this apartment's perk for the contract's duration.</p>
      <form method="post"><input type="hidden" name="action" value="propose_rental"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px">
          <div class="xfer-to-wrap" style="flex:1;min-width:120px">
            <input type="text" name="renter" class="rent-ac-inp" placeholder="Renter's ID or handle" autocomplete="off" maxlength="32" style="width:100%">
            <div class="ac-list rent-ac-list" style="display:none"></div>
          </div>
          <input type="number" name="days" min="1" max="90" placeholder="Days" style="width:80px">
          <input type="number" name="rent_amount" min="1" placeholder="Total rent (credits)" style="width:140px">
          <button type="submit" style="font-size:12px">Send Offer</button>
        </div>
        <div class="rent-ac-confirm" style="display:none;margin-top:6px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:5px;padding:7px 10px;font-size:12px"></div>
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
    <?php
      // Mirror the server's actual sellback payout (min(catalog, paid_price) / 2) so this
      // estimate never overstates what apartments.php's 'sellback' handler will pay out.
      $sbPaid   = $a['paid_price'] !== null ? (int)$a['paid_price'] : (int)$atype['price'];
      $sbRefund = (int)(min((int)$atype['price'], $sbPaid) / 2);
    ?>
    <div class="sellback-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <p style="margin:0 0 8px;font-size:12px;color:var(--muted)">Sell back to system for <b style="color:var(--accent)"><?= number_format($sbRefund) ?> credits</b> (50% of what you paid, capped at catalog price).<?= $a['is_primary'] ? ' This is your primary residence — selling it back also removes its perks.' : '' ?> This cannot be undone.</p>
      <form method="post" onsubmit="return confirm('Sell this apartment back for <?= number_format($sbRefund) ?> credits? This is permanent.')">
        <input type="hidden" name="action" value="sellback"><input type="hidden" name="apt_id" value="<?= (int)$a['id'] ?>">
        <button type="submit" style="font-size:12px">Confirm Sell Back</button>
      </form>
    </div>
    <?php
      $aptDecor = [];
      try { $dq = $pdo->prepare('SELECT * FROM apartment_decor WHERE apt_id=? ORDER BY placed_at ASC'); $dq->execute([$a['id']]); $aptDecor = $dq->fetchAll(); } catch (Throwable $e) {}
    ?>
    <div class="decor-form" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
      <div style="font-size:12px;font-weight:700;margin-bottom:10px">&#128268; Furnishings</div>
      <?php if (!empty($aptDecor)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
        <?php foreach ($aptDecor as $d): $di = $DECOR_ITEMS[$d['decor_key']] ?? null; if (!$di) continue;
          $dRefund = (int)floor($di['price'] / 2);
        ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:7px 10px;font-size:11px;display:flex;align-items:center;gap:10px;line-height:1">
          <span style="display:inline-flex;align-items:center;gap:4px"><?= $di['icon'] ?> <?= e($di['name']) ?></span>
          <form method="post" style="margin:0;line-height:1" onsubmit="return confirm('Sell back <?= e($di['name']) ?> for <?= number_format($dRefund) ?> credits?')"><input type="hidden" name="action" value="decor_remove"><input type="hidden" name="decor_id" value="<?= (int)$d['id'] ?>"><button type="submit" style="font-size:10px;padding:3px 7px;line-height:1;vertical-align:middle" title="Sell back for <?= number_format($dRefund) ?> credits">Sell (<?= number_format($dRefund) ?>)</button></form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p style="font-size:12px;color:var(--muted);margin:0 0 12px">No furnishings yet.</p>
      <?php endif; ?>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">Available to Place</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px">
        <?php foreach ($DECOR_ITEMS as $dk => $di):
          $alreadyPlaced = !empty(array_filter($aptDecor, fn($d) => $d['decor_key'] === $dk));
          $canAfford = (int)$player['creds_pocket'] >= $di['price'];
        ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px 10px">
          <div style="font-size:13px;margin-bottom:3px"><?= $di['icon'] ?> <?= e($di['name']) ?></div>
          <div style="font-size:11px;color:var(--accent);margin-bottom:6px"><?= number_format($di['price']) ?> credits</div>
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
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── BUY NEW ── -->
<?php elseif ($tab === 'buy'):
  $typesByDistrict = [];
  foreach ($APT_TYPES as $tid => $atype) $typesByDistrict[$atype['district'] ?? 'working'][$tid] = $atype;
  $aptDetailData = [];
?>
<!-- Quick-jump nav — 40 types across 4 districts is a lot to scroll through,
     so each section is collapsible (open by default only for the first) and
     this strip jumps straight to one. -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;align-items:center;justify-content:space-between">
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach (['poor','working','affluent','elite'] as $dk):
      if (empty($typesByDistrict[$dk])) continue;
      $dm = $DISTRICT_META[$dk];
    ?>
    <a href="#district-<?= $dk ?>" class="apt-district-jump" data-district="<?= $dk ?>" style="font-size:11px;padding:5px 12px;border-radius:20px;text-decoration:none;border:1px solid <?= $dm['col'] ?>55;color:<?= $dm['col'] ?>;background:<?= $dm['col'] ?>11"><?= e($dm['label']) ?> (<?= count($typesByDistrict[$dk]) ?>)</a>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
    <input type="text" id="aptSearch" placeholder="&#128269; Search by name..." style="font-size:11px;padding:5px 10px;width:150px">
    <select id="aptSort" style="font-size:11px;padding:5px 8px;width:auto">
      <option value="default">Sort: Default</option>
      <option value="price-asc">Price: Low to High</option>
      <option value="price-desc">Price: High to Low</option>
      <option value="rarity-asc">Rarity: Common first</option>
      <option value="rarity-desc">Rarity: Legendary first</option>
    </select>
  </div>
</div>
<script>
(function(){
  // Native #anchor jump-to-<details> doesn't reliably auto-expand a closed
  // <details> across browsers — clicking the button would just scroll to the
  // collapsed summary bar with nothing visible, looking like it did nothing.
  // Force it open first, then scroll.
  document.querySelectorAll('.apt-district-jump').forEach(function(a){
    a.addEventListener('click', function(ev){
      var el = document.getElementById('district-' + a.dataset.district);
      if (!el) return;
      ev.preventDefault();
      el.open = true;
      el.scrollIntoView({behavior:'smooth', block:'start'});
    });
  });

  var rarityOrder = {common:0, uncommon:1, rare:2, elite:3, legendary:4};
  var sorters = {
    'price-asc':   function(a,b){ return parseInt(a.dataset.price,10)-parseInt(b.dataset.price,10); },
    'price-desc':  function(a,b){ return parseInt(b.dataset.price,10)-parseInt(a.dataset.price,10); },
    'rarity-asc':  function(a,b){ return (rarityOrder[a.dataset.rarity]||0)-(rarityOrder[b.dataset.rarity]||0); },
    'rarity-desc': function(a,b){ return (rarityOrder[b.dataset.rarity]||0)-(rarityOrder[a.dataset.rarity]||0); }
  };
  var grids = document.querySelectorAll('details[id^="district-"] > div');
  var origOrders = new Map();
  grids.forEach(function(g){ origOrders.set(g, Array.prototype.slice.call(g.children)); });

  var sortSel = document.getElementById('aptSort');
  if (sortSel) sortSel.addEventListener('change', function(){
    var v = sortSel.value;
    grids.forEach(function(g){
      var cards = v==='default' ? origOrders.get(g).slice() : Array.prototype.slice.call(g.children).sort(sorters[v]);
      cards.forEach(function(c){ g.appendChild(c); });
    });
  });

  var searchEl = document.getElementById('aptSearch');
  if (searchEl) searchEl.addEventListener('input', function(){
    var q = searchEl.value.toLowerCase().trim();
    document.querySelectorAll('.apt-card').forEach(function(c){
      var match = !q || (c.dataset.name||'').indexOf(q) !== -1;
      c.style.display = match ? '' : 'none';
      if (match && q) { var det = c.closest('details'); if (det) det.open = true; }
    });
  });
})();
</script>
<?php foreach (['poor','working','affluent','elite'] as $di => $dk):
  if (empty($typesByDistrict[$dk])) continue;
  $dm = $DISTRICT_META[$dk];
?>
<details id="district-<?= $dk ?>" style="margin-bottom:10px" <?= $di === 0 ? 'open' : '' ?>>
  <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;padding:8px 0">
    <span style="font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;color:<?= $dm['col'] ?>;text-transform:uppercase;letter-spacing:.08em"><?= e($dm['label']) ?></span>
    <span style="font-size:10px;color:var(--muted)">(<?= count($typesByDistrict[$dk]) ?> types)</span>
    <span style="flex:1;height:1px;background:linear-gradient(90deg,<?= $dm['col'] ?>33,transparent)"></span>
  </summary>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:4px">
    <?php foreach ($typesByDistrict[$dk] as $tid => $atype):
      $rc = $RARITY_COLORS[$atype['rarity']];
      $owned = false; foreach ($myApts as $a) { if ($a['apt_type_id'] === $tid) { $owned = true; break; } }
      $floors = $RARITY_FLOORS[$atype['rarity']] ?? 4;
      $maxUnits = (int)($atype['max_units'] ?? 0);
      $ownedCnt = $unitsOwned[$tid] ?? 0;
      $soldOutCity = $maxUnits > 0 && $ownedCnt >= $maxUnits;
      $aptDetailData[$tid] = [
        'name' => $atype['name'], 'region' => $atype['region'], 'district' => $dm['label'], 'dcol' => $dm['col'],
        'rarity' => $atype['rarity'], 'rc' => $rc, 'price' => (int)$atype['price'], 'perks' => $atype['perks'],
        'desc' => $atype['name'] . ' sits in ' . $atype['region'] . ', part of the Sprawl\'s ' . $dm['label'] . '. ' .
                  ($atype['rarity'] === 'legendary' ? 'One of the most sought-after addresses in the city.' :
                   ($atype['rarity'] === 'rare' ? 'A step above the surrounding blocks — comfortable, and it shows.' :
                    ($atype['rarity'] === 'uncommon' ? 'Solid, mid-tier living — not flashy, but reliable.' :
                     'Cheap, cramped, and exactly what it costs.'))),
        'maxUnits' => $maxUnits, 'owned' => $ownedCnt, 'floors' => $floors,
        'neighbors' => $neighborsByType[$tid] ?? [],
      ];
    ?>
    <div class="apt-card" style="border-color:<?= $atype['rarity']==='legendary'?'rgba(232,212,77,.4)':($atype['rarity']==='rare'?'rgba(255,45,149,.25)':'var(--line)') ?>;cursor:pointer" data-apt-detail="<?= $tid ?>" data-price="<?= (int)$atype['price'] ?>" data-rarity="<?= e($atype['rarity']) ?>" data-name="<?= strtolower(e($atype['name'])) ?>">
      <div class="apt-art" style="height:80px"><?= apt_building_art($rc, $tid, $floors, $dk) ?>
        <?php if ($atype['rarity'] !== 'common'): ?><span class="apt-badge" style="color:<?= $rc ?>;background:rgba(0,0,0,.5);border:1px solid <?= $rc ?>;font-size:9px;padding:2px 7px"><?= $atype['rarity'] ?></span><?php endif; ?>
      </div>
      <div class="apt-body" style="padding:10px 12px">
        <div style="font-weight:700;font-size:13px;color:<?= $rc ?>;margin-bottom:4px"><?= e($atype['name']) ?></div>
        <div style="font-size:11px;color:#3bcf63;margin-bottom:6px"><?= e($atype['perks']) ?></div>
        <?php if ($maxUnits > 0): ?>
        <div style="font-size:9px;color:<?= $soldOutCity ? 'var(--neon2)' : 'var(--muted)' ?>;margin-bottom:8px"><?= $soldOutCity ? 'Sold out citywide' : number_format($maxUnits - $ownedCnt) . '/' . number_format($maxUnits) . ' left' ?></div>
        <?php endif; ?>
        <div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:var(--accent);margin-bottom:10px"><?= number_format($atype['price']) ?> <span style="font-size:10px;font-weight:400;color:var(--muted)">credits</span></div>
        <?php if ($owned): ?>
          <button disabled style="width:100%;opacity:.4;font-size:11px">Already Owned</button>
        <?php elseif ($soldOutCity): ?>
          <button disabled style="width:100%;opacity:.4;font-size:11px">Sold Out</button>
        <?php else: ?>
          <form method="post" class="apt-buy-form" style="margin:0" onclick="event.stopPropagation()" data-apt-name="<?= e($atype['name']) ?>" data-apt-price="<?= (int)$atype['price'] ?>">
            <input type="hidden" name="action" value="buy"><input type="hidden" name="type_id" value="<?= $tid ?>">
            <button type="submit" style="width:100%;font-size:11px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)" <?= (int)$player['creds_pocket'] < $atype['price'] ? 'disabled' : '' ?>>Purchase</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</details>
<?php endforeach; ?>

<!-- Shared detail popup — one modal, populated from the clicked card's data
     instead of rendering 40 separate modals up front. -->
<div class="modal-bg" id="aptDetailModal">
  <div class="modal" style="max-width:460px">
    <span class="x" onclick="document.getElementById('aptDetailModal').classList.remove('show')">&times;</span>
    <div id="aptDetailArt" style="width:100%;height:140px;border-radius:8px;overflow:hidden;margin-bottom:12px"></div>
    <h3 id="aptDetailName" style="margin:0 0 2px"></h3>
    <div id="aptDetailRegion" class="muted" style="font-size:12px;margin-bottom:10px"></div>
    <p id="aptDetailDesc" style="font-size:13px;line-height:1.6;margin:0 0 12px"></p>
    <div style="background:rgba(59,207,99,.06);border:1px solid rgba(59,207,99,.2);border-radius:6px;padding:10px 12px;margin-bottom:12px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px">Incentive</div>
      <div id="aptDetailPerks" style="font-size:13px;color:#3bcf63;font-weight:700"></div>
    </div>
    <div id="aptDetailSupply" style="font-size:12px;color:var(--muted);margin-bottom:10px"></div>
    <div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Neighbors in this area</div>
      <div id="aptDetailNeighbors" style="font-size:12px"></div>
    </div>
  </div>
</div>
<script>
(function(){
  var APT_DATA = <?= json_encode($aptDetailData) ?>;
  var modal = document.getElementById('aptDetailModal');
  document.querySelectorAll('[data-apt-detail]').forEach(function(card){
    card.addEventListener('click', function(){
      var d = APT_DATA[card.dataset.aptDetail]; if (!d) return;
      document.getElementById('aptDetailArt').innerHTML = card.querySelector('.apt-art').innerHTML;
      document.getElementById('aptDetailName').textContent = d.name;
      document.getElementById('aptDetailName').style.color = d.rc;
      document.getElementById('aptDetailRegion').innerHTML = '&#128205; ' + d.region + ' &middot; <span style="color:' + d.dcol + '">' + d.district + '</span>';
      document.getElementById('aptDetailDesc').textContent = d.desc;
      document.getElementById('aptDetailPerks').textContent = d.perks;
      document.getElementById('aptDetailSupply').textContent = d.maxUnits > 0
        ? (d.owned + ' of ' + d.maxUnits + ' units owned citywide')
        : 'Unlimited supply';
      // Usernames are player-chosen data, not trusted markup — build these
      // nodes via textContent rather than interpolating into innerHTML.
      var nEl = document.getElementById('aptDetailNeighbors');
      nEl.innerHTML = '';
      if (d.neighbors && d.neighbors.length) {
        d.neighbors.forEach(function(n){
          var a = document.createElement('a');
          a.href = 'index.php?p=profile&id=' + n.id;
          a.style.cssText = 'display:inline-block;background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:3px 10px;margin:0 4px 4px 0;text-decoration:none;color:' + n.color + ';font-weight:600';
          a.textContent = n.username;
          nEl.appendChild(a);
        });
      } else {
        var muted = document.createElement('span'); muted.className = 'muted'; muted.textContent = 'Nobody lives here yet.';
        nEl.appendChild(muted);
      }
      modal.classList.add('show');
    });
  });
  if (modal) modal.addEventListener('click', function(e){ if (e.target === this) modal.classList.remove('show'); });
})();
</script>

<!-- Purchase confirmation popup — this is a real-estate purchase, worth a moment's pause -->
<div class="modal-bg" id="aptBuyModal">
  <div class="modal" style="max-width:380px">
    <span class="x" onclick="document.getElementById('aptBuyModal').classList.remove('show')">&times;</span>
    <h3>Confirm Purchase</h3>
    <p style="font-size:13px;margin:0 0 4px">You're about to buy:</p>
    <p id="aptBuyName" style="font-size:16px;font-weight:700;color:var(--accent);margin:0 0 10px"></p>
    <p style="font-size:13px;color:var(--muted);margin:0 0 16px">for <b id="aptBuyPrice" style="color:var(--text)"></b> credits. This deed is yours the moment the transfer clears.</p>
    <div style="display:flex;gap:8px">
      <button type="button" onclick="document.getElementById('aptBuyModal').classList.remove('show')" style="flex:1">Cancel</button>
      <button type="button" id="aptBuyConfirmBtn" style="flex:1;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)">Confirm</button>
    </div>
  </div>
</div>
<script>
(function(){
  var modal=document.getElementById('aptBuyModal'), pendingForm=null;
  document.querySelectorAll('.apt-buy-form').forEach(function(f){
    // stopPropagation is required here, not just preventDefault — index.php's
    // sitewide submit handler is bound on document and doesn't check
    // defaultPrevented, so without this it would still AJAX-submit the form
    // immediately, before this confirmation modal ever gets a chance to show.
    f.addEventListener('submit', function(ev){
      if (f.dataset.confirmed==='1') return;
      ev.preventDefault();
      ev.stopPropagation();
      pendingForm=f;
      document.getElementById('aptBuyName').textContent=f.dataset.aptName;
      document.getElementById('aptBuyPrice').textContent=parseInt(f.dataset.aptPrice,10).toLocaleString('en-US');
      modal.classList.add('show');
    });
  });
  document.getElementById('aptBuyConfirmBtn').addEventListener('click', function(){
    if (!pendingForm) return;
    pendingForm.dataset.confirmed='1';
    modal.classList.remove('show');
    pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit();
  });
  modal.addEventListener('click', function(e){ if (e.target===this) modal.classList.remove('show'); });
})();
</script>

<!-- ── MARKET ── -->
<?php elseif ($tab === 'market'): ?>
<?php if (empty($marketListings)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:24px">No apartments listed on the market right now.</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:10px">
  <?php foreach ($marketListings as $l):
    $atype = $APT_TYPES[$l['apt_type_id']] ?? null; if (!$atype) continue;
    $rc = $RARITY_COLORS[$atype['rarity']];
    $floors = $RARITY_FLOORS[$atype['rarity']] ?? 4;
  ?>
  <div class="apt-card" style="display:flex;flex-wrap:wrap">
    <div class="apt-art" style="width:120px;flex:none;height:auto;border-radius:9px 0 0 9px;border-right:1px solid var(--line);border-bottom:none">
      <?= apt_building_art($rc, (int)$l['apt_type_id'], $floors, $atype['district'] ?? 'working') ?>
    </div>
    <div class="apt-body" style="flex:1;min-width:220px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-weight:700;font-size:13px;color:<?= $rc ?>"><?= e($atype['name']) ?></div>
        <div style="font-size:11px;color:var(--muted)">&#128205; <?= e($atype['region']) ?> &middot; <?= e($atype['perks']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">Seller: <?= e($l['seller_name']) ?></div>
      </div>
      <?php $lCur = $l['market_currency'] ?? 'credits'; $lAfford = $lCur === 'shards' ? (int)$player['shards'] >= $l['market_price'] : (int)$player['creds_pocket'] >= $l['market_price']; ?>
      <div style="display:flex;gap:10px;align-items:center">
        <div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:<?= $lCur==='shards'?'#e8d44d':'var(--accent)' ?>"><?= number_format($l['market_price']) ?> <?= $lCur==='shards'?'&#9670; shards':'credits' ?></div>
        <form method="post" style="margin:0"><input type="hidden" name="action" value="market_buy"><input type="hidden" name="apt_id" value="<?= (int)$l['id'] ?>"><button type="submit" style="font-size:12px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)" <?= !$lAfford ? 'disabled' : '' ?>>Buy</button></form>
      </div>
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
    var confirmEl=inp.closest('.rent-form').querySelector('.rent-ac-confirm');
    PlayerAC.attach(inp, list, {confirm: confirmEl});
  });
})();
</script>
