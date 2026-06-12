<?php /* pages/trade.php — Secure Trade Post */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

// ── Schema ──
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS trade_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_id INT NOT NULL, to_id INT NOT NULL,
    from_credits BIGINT NOT NULL DEFAULT 0, to_credits BIGINT NOT NULL DEFAULT 0,
    from_shards  BIGINT NOT NULL DEFAULT 0,  to_shards  BIGINT NOT NULL DEFAULT 0,
    note VARCHAR(200) NULL,
    status ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_to (to_id,status), INDEX idx_from (from_id,status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE trade_offers ADD COLUMN from_shards BIGINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE trade_offers ADD COLUMN to_shards  BIGINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS trade_offer_items (
    id INT AUTO_INCREMENT PRIMARY KEY, offer_id INT NOT NULL,
    direction ENUM('from','to') NOT NULL, item_id INT NOT NULL, qty INT NOT NULL DEFAULT 1,
    INDEX idx_offer (offer_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'propose') {
      $toHandle = trim($_POST['to_name'] ?? '');
      $toId = 0;
      if (ctype_digit($toHandle)) { $r=$pdo->prepare('SELECT id FROM players WHERE id=?'); $r->execute([(int)$toHandle]); $toId=(int)$r->fetchColumn(); }
      if (!$toId) { $r=$pdo->prepare('SELECT id FROM players WHERE username=?'); $r->execute([$toHandle]); $toId=(int)$r->fetchColumn(); }
      if (!$toId || $toId === $pid) throw new RuntimeException('Recipient not found or invalid.');

      $fcr = max(0,(int)($_POST['from_credits']??0));
      $fsh = max(0,(int)($_POST['from_shards']??0));
      $tcr = max(0,(int)($_POST['to_credits']??0));
      $tsh = max(0,(int)($_POST['to_shards']??0));
      $note = mb_substr(trim($_POST['note']??''),0,200);

      // Collect offered items
      $fromItems = []; // [item_id => qty]
      foreach ((array)($_POST['from_item_id'] ?? []) as $i => $iid) {
        $iid = (int)$iid; $iqty = max(1,(int)(($_POST['from_item_qty'][$i])??1));
        if ($iid > 0) $fromItems[$iid] = ($fromItems[$iid] ?? 0) + $iqty;
      }
      // Collect requested items
      $toItems = [];
      foreach ((array)($_POST['to_item_id'] ?? []) as $i => $iid) {
        $iid = (int)$iid; $iqty = max(1,(int)(($_POST['to_item_qty'][$i])??1));
        if ($iid > 0) $toItems[$iid] = ($toItems[$iid] ?? 0) + $iqty;
      }

      if ($fcr===0 && $fsh===0 && $tcr===0 && $tsh===0 && empty($fromItems) && empty($toItems))
        throw new RuntimeException('Offer must include something on at least one side.');

      // Validate and lock proposer's credits/shards
      $pdo->beginTransaction();
      if ($fcr > 0) {
        $u = $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?');
        $u->execute([$fcr, $pid, $fcr]);
        if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits in pocket.');
      }
      if ($fsh > 0) {
        $u = $pdo->prepare('UPDATE players SET shards=shards-? WHERE id=? AND shards>=?');
        $u->execute([$fsh, $pid, $fsh]);
        if ($u->rowCount() !== 1) throw new RuntimeException('Not enough shards.');
      }
      // Lock offered items
      foreach ($fromItems as $iid => $iqty) {
        $u = $pdo->prepare('UPDATE player_items SET qty=qty-? WHERE player_id=? AND item_id=? AND qty>=?');
        $u->execute([$iqty, $pid, $iid, $iqty]);
        if ($u->rowCount() !== 1) throw new RuntimeException('Not enough of item #' . $iid . ' in inventory.');
      }

      $pdo->prepare('INSERT INTO trade_offers (from_id,to_id,from_credits,to_credits,from_shards,to_shards,note) VALUES (?,?,?,?,?,?,?)')
          ->execute([$pid,$toId,$fcr,$tcr,$fsh,$tsh,$note]);
      $oid = (int)$pdo->lastInsertId();

      foreach ($fromItems as $iid => $iqty)
        $pdo->prepare('INSERT INTO trade_offer_items (offer_id,direction,item_id,qty) VALUES (?,?,?,?)')->execute([$oid,'from',$iid,$iqty]);
      foreach ($toItems as $iid => $iqty)
        $pdo->prepare('INSERT INTO trade_offer_items (offer_id,direction,item_id,qty) VALUES (?,?,?,?)')->execute([$oid,'to',$iid,$iqty]);

      $pdo->commit();
      $msg = 'Trade proposal sent. Items and currency are held in escrow.';
      $player = current_player();

    } elseif ($act === 'accept') {
      $oid = (int)($_POST['offer_id'] ?? 0);
      $pdo->beginTransaction();
      $oq = $pdo->prepare('SELECT * FROM trade_offers WHERE id=? AND to_id=? AND status="pending" FOR UPDATE');
      $oq->execute([$oid, $pid]); $offer = $oq->fetch();
      if (!$offer) throw new RuntimeException('Offer not found.');

      // Load items
      $itmq = $pdo->prepare('SELECT * FROM trade_offer_items WHERE offer_id=?');
      $itmq->execute([$oid]); $offerItems = $itmq->fetchAll();

      // Deduct acceptor's requested resources
      if ($offer['to_credits'] > 0) {
        $u=$pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?');
        $u->execute([$offer['to_credits'],$pid,$offer['to_credits']]);
        if ($u->rowCount()!==1) throw new RuntimeException('Not enough credits to accept.');
      }
      if ($offer['to_shards'] > 0) {
        $u=$pdo->prepare('UPDATE players SET shards=shards-? WHERE id=? AND shards>=?');
        $u->execute([$offer['to_shards'],$pid,$offer['to_shards']]);
        if ($u->rowCount()!==1) throw new RuntimeException('Not enough shards to accept.');
      }
      foreach ($offerItems as $oi) {
        if ($oi['direction'] === 'to') {
          $u=$pdo->prepare('UPDATE player_items SET qty=qty-? WHERE player_id=? AND item_id=? AND qty>=?');
          $u->execute([$oi['qty'],$pid,$oi['item_id'],$oi['qty']]);
          if ($u->rowCount()!==1) throw new RuntimeException('Not enough of a requested item in inventory.');
        }
      }

      $fid = (int)$offer['from_id'];
      // Give acceptor what was offered
      if ($offer['from_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['from_credits'],$pid]);
      if ($offer['from_shards']>0)  $pdo->prepare('UPDATE players SET shards=shards+? WHERE id=?')->execute([$offer['from_shards'],$pid]);
      foreach ($offerItems as $oi) {
        if ($oi['direction']==='from')
          $pdo->prepare('INSERT INTO player_items (player_id,item_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$pid,$oi['item_id'],$oi['qty']]);
      }
      // Give proposer what was requested
      if ($offer['to_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['to_credits'],$fid]);
      if ($offer['to_shards']>0)  $pdo->prepare('UPDATE players SET shards=shards+? WHERE id=?')->execute([$offer['to_shards'],$fid]);
      foreach ($offerItems as $oi) {
        if ($oi['direction']==='to')
          $pdo->prepare('INSERT INTO player_items (player_id,item_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$fid,$oi['item_id'],$oi['qty']]);
      }

      $pdo->prepare('UPDATE trade_offers SET status="accepted",resolved_at=NOW() WHERE id=?')->execute([$oid]);
      $pdo->commit();
      $msg = 'Trade accepted! All items and currency exchanged.';
      $player = current_player();

    } elseif ($act === 'reject') {
      $oid = (int)($_POST['offer_id'] ?? 0);
      $pdo->beginTransaction();
      $oq = $pdo->prepare('SELECT * FROM trade_offers WHERE id=? AND to_id=? AND status="pending" FOR UPDATE');
      $oq->execute([$oid, $pid]); $offer = $oq->fetch();
      if (!$offer) throw new RuntimeException('Offer not found.');
      $fid = (int)$offer['from_id'];
      if ($offer['from_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['from_credits'],$fid]);
      if ($offer['from_shards']>0)  $pdo->prepare('UPDATE players SET shards=shards+? WHERE id=?')->execute([$offer['from_shards'],$fid]);
      $itmq=$pdo->prepare('SELECT * FROM trade_offer_items WHERE offer_id=? AND direction="from"'); $itmq->execute([$oid]);
      foreach ($itmq as $oi) $pdo->prepare('INSERT INTO player_items (player_id,item_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$fid,$oi['item_id'],$oi['qty']]);
      $pdo->prepare('UPDATE trade_offers SET status="rejected",resolved_at=NOW() WHERE id=?')->execute([$oid]);
      $pdo->commit(); $msg = 'Trade rejected. Proposer refunded.';

    } elseif ($act === 'cancel') {
      $oid = (int)($_POST['offer_id'] ?? 0);
      $pdo->beginTransaction();
      $oq = $pdo->prepare('SELECT * FROM trade_offers WHERE id=? AND from_id=? AND status="pending" FOR UPDATE');
      $oq->execute([$oid, $pid]); $offer = $oq->fetch();
      if (!$offer) throw new RuntimeException('Offer not found.');
      if ($offer['from_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['from_credits'],$pid]);
      if ($offer['from_shards']>0)  $pdo->prepare('UPDATE players SET shards=shards+? WHERE id=?')->execute([$offer['from_shards'],$pid]);
      $itmq=$pdo->prepare('SELECT * FROM trade_offer_items WHERE offer_id=? AND direction="from"'); $itmq->execute([$oid]);
      foreach ($itmq as $oi) $pdo->prepare('INSERT INTO player_items (player_id,item_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$pid,$oi['item_id'],$oi['qty']]);
      $pdo->prepare('UPDATE trade_offers SET status="cancelled",resolved_at=NOW() WHERE id=?')->execute([$oid]);
      $pdo->commit(); $msg = 'Offer cancelled. Your items returned.';
      $player = current_player();

    } elseif ($act === 'transfer') {
      $toHandle = trim($_POST['to_name'] ?? '');
      $toId = 0;
      if (ctype_digit($toHandle)) { $r=$pdo->prepare('SELECT id FROM players WHERE id=?'); $r->execute([(int)$toHandle]); $toId=(int)$r->fetchColumn(); }
      if (!$toId) { $r=$pdo->prepare('SELECT id FROM players WHERE username=?'); $r->execute([$toHandle]); $toId=(int)$r->fetchColumn(); }
      if (!$toId || $toId === (int)$pid) throw new RuntimeException('Recipient not found or invalid.');
      $amt = max(1,(int)($_POST['amount']??0));
      $type = ($_POST['currency']??'credits') === 'shards' ? 'shards' : 'credits';
      $pdo->beginTransaction();
      if ($type === 'credits') {
        $u=$pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?');
        $u->execute([$amt,$pid,$amt]); if ($u->rowCount()!==1) throw new RuntimeException('Not enough credits.');
        $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$amt,$toId]);
      } else {
        $u=$pdo->prepare('UPDATE players SET shards=shards-? WHERE id=? AND shards>=?');
        $u->execute([$amt,$pid,$amt]); if ($u->rowCount()!==1) throw new RuntimeException('Not enough shards.');
        $pdo->prepare('UPDATE players SET shards=shards+? WHERE id=?')->execute([$amt,$toId]);
      }
      $pdo->commit();
      $tgt=$pdo->prepare('SELECT username FROM players WHERE id=?'); $tgt->execute([$toId]); $tname=$tgt->fetchColumn();
      $msg = 'Transferred ' . number_format($amt) . ' ' . $type . ' to ' . $tname . '.';
      $player = current_player();
    }
  } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); $msg = $ex->getMessage(); $msgErr = true; }
}

// Pre-select a trade partner from URL
$withId = (int)($_GET['with'] ?? 0);
$withName = '';
if ($withId) { try { $wr=$pdo->prepare('SELECT username FROM players WHERE id=?'); $wr->execute([$withId]); $withName=(string)$wr->fetchColumn(); } catch (Throwable $e) {} }

// Load pending trades
$incoming = [];
try { $q=$pdo->prepare('SELECT tr.*,p.username AS from_name FROM trade_offers tr JOIN players p ON p.id=tr.from_id WHERE tr.to_id=? AND tr.status="pending" ORDER BY tr.id DESC'); $q->execute([$pid]); $incoming=$q->fetchAll(); } catch (Throwable $e) {}
$outgoing = [];
try { $q=$pdo->prepare('SELECT tr.*,p.username AS to_name FROM trade_offers tr JOIN players p ON p.id=tr.to_id WHERE tr.from_id=? AND tr.status="pending" ORDER BY tr.id DESC'); $q->execute([$pid]); $outgoing=$q->fetchAll(); } catch (Throwable $e) {}

// Load all offer items for display
$allOfferItems = [];
$allOids = array_merge(array_column($incoming,'id'), array_column($outgoing,'id'));
if ($allOids) {
  try {
    $iq=$pdo->query('SELECT toi.*,i.name AS item_name FROM trade_offer_items toi JOIN items i ON i.id=toi.item_id WHERE toi.offer_id IN ('.implode(',',array_map('intval',$allOids)).')');
    foreach ($iq as $r) $allOfferItems[(int)$r['offer_id']][] = $r;
  } catch (Throwable $e) {}
}

// My inventory for offering
$myInv = [];
try { $q=$pdo->prepare('SELECT pi.item_id,pi.qty,i.name,i.category FROM player_items pi JOIN items i ON i.id=pi.item_id WHERE pi.player_id=? AND pi.qty>0 ORDER BY i.category,i.name'); $q->execute([$pid]); $myInv=$q->fetchAll(); } catch (Throwable $e) {}

// Friends for quick-select
$tradeFriends = [];
try { $q=$pdo->prepare('SELECT p.id,p.username,p.role,p.chat_color FROM friends f JOIN players p ON p.id=f.friend_id WHERE f.player_id=? ORDER BY p.username LIMIT 30'); $q->execute([$pid]); $tradeFriends=$q->fetchAll(); } catch (Throwable $e) {}

$tab = in_array($_GET['tab'] ?? '', ['pending','transfer','new']) ? $_GET['tab'] : ($withId ? 'new' : 'pending');
?>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8a33d,var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#128260; Secure Trade Post</h2>
    <p class="muted" style="margin:0;font-size:12px">Trade items, credits, and shards with other players. Offered items are held in escrow until the trade resolves.</p>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;flex-wrap:wrap">
  <?php foreach (['pending'=>'&#128260; Pending Trades ('.( count($incoming)+count($outgoing)).')','new'=>'&#43; New Trade','transfer'=>'&#128178; Direct Transfer'] as $tid=>$tl): ?>
  <a href="index.php?p=trade&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid?'#e8a33d':'var(--line)' ?>;background:<?= $tab===$tid?'rgba(232,163,61,.1)':'var(--panel2)' ?>;color:<?= $tab===$tid?'#e8a33d':'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php /* ── PENDING TRADES ── */ if ($tab === 'pending'): ?>

<?php if (empty($incoming) && empty($outgoing)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:24px">No pending trades. Start one from the New Trade tab or via a player's profile.</div>
<?php endif; ?>

<?php if (!empty($incoming)): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">Incoming Offers (<?= count($incoming) ?>)</h3>
  <?php foreach ($incoming as $offer): $items=$allOfferItems[(int)$offer['id']]??[]; ?>
  <div style="background:rgba(25,240,199,.04);border:1px solid rgba(25,240,199,.18);border-radius:7px;padding:12px;margin-bottom:10px">
    <div style="font-size:12px;margin-bottom:8px">
      <b style="color:var(--accent)"><?= e($offer['from_name']) ?></b> offers to you:
      <?php if ($offer['from_credits']>0): ?><span style="color:#3bcf63"> +<?= number_format($offer['from_credits']) ?> cr</span><?php endif; ?>
      <?php if ($offer['from_shards']>0): ?><span style="color:#e8d44d"> +<?= number_format($offer['from_shards']) ?> &#9670;</span><?php endif; ?>
      <?php foreach ($items as $oi): if ($oi['direction']==='from'): ?><span style="color:#3bcf63"> +<?= (int)$oi['qty'] ?>× <?= e($oi['item_name']) ?></span><?php endif; endforeach; ?>
      <?php $hasRequest = $offer['to_credits']>0 || $offer['to_shards']>0 || !empty(array_filter($items,fn($x)=>$x['direction']==='to')); ?>
      <?php if ($hasRequest): ?><br><span class="muted" style="font-size:11px">In exchange for:</span>
        <?php if ($offer['to_credits']>0): ?><span style="color:var(--neon2)"> <?= number_format($offer['to_credits']) ?> cr from you</span><?php endif; ?>
        <?php if ($offer['to_shards']>0): ?><span style="color:var(--neon2)"> <?= number_format($offer['to_shards']) ?> &#9670;</span><?php endif; ?>
        <?php foreach ($items as $oi): if ($oi['direction']==='to'): ?><span style="color:var(--neon2)"> <?= (int)$oi['qty'] ?>× <?= e($oi['item_name']) ?></span><?php endif; endforeach; ?>
      <?php endif; ?>
      <?php if ($offer['note']): ?><div style="color:var(--muted);font-size:11px;margin-top:3px;font-style:italic"><?= e($offer['note']) ?></div><?php endif; ?>
    </div>
    <div style="display:flex;gap:6px">
      <form method="post" style="margin:0"><input type="hidden" name="action" value="accept"><input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>"><button style="font-size:11px;padding:4px 14px;background:rgba(59,207,99,.1);border-color:rgba(59,207,99,.3);color:#3bcf63">Accept</button></form>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="reject"><input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>"><button style="font-size:11px;padding:4px 14px;background:rgba(226,59,59,.06);border-color:rgba(226,59,59,.25);color:#e23b3b" onclick="return confirm('Reject this trade? The proposer will be refunded.')">Reject</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($outgoing)): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">My Pending Offers (<?= count($outgoing) ?>)</h3>
  <?php foreach ($outgoing as $offer): $items=$allOfferItems[(int)$offer['id']]??[]; ?>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:12px;margin-bottom:8px;font-size:12px">
    <div style="margin-bottom:6px">
      <span class="muted">To:</span> <b><?= e($offer['to_name']) ?></b>
      <span style="color:var(--muted);font-size:10px;margin-left:6px"><?= e(date('M j g:ia',strtotime($offer['created_at']))) ?></span>
    </div>
    <div style="margin-bottom:6px">
      Offering: <?php if ($offer['from_credits']>0): ?><span style="color:#e8a33d"><?= number_format($offer['from_credits']) ?> cr</span> <?php endif; ?>
      <?php if ($offer['from_shards']>0): ?><span style="color:#e8d44d"><?= number_format($offer['from_shards']) ?> &#9670;</span> <?php endif; ?>
      <?php foreach ($items as $oi): if ($oi['direction']==='from'): ?><span style="color:#e8a33d"><?= (int)$oi['qty'] ?>× <?= e($oi['item_name']) ?></span> <?php endif; endforeach; ?>
      <?php if ($offer['to_credits']>0 || $offer['to_shards']>0 || !empty(array_filter($items,fn($x)=>$x['direction']==='to'))): ?>
      | Requesting: <?php if ($offer['to_credits']>0): ?><?= number_format($offer['to_credits']) ?> cr <?php endif; ?>
      <?php if ($offer['to_shards']>0): ?><?= number_format($offer['to_shards']) ?> &#9670; <?php endif; ?>
      <?php foreach ($items as $oi): if ($oi['direction']==='to'): ?><?= (int)$oi['qty'] ?>× <?= e($oi['item_name']) ?> <?php endif; endforeach; ?>
      <?php endif; ?>
    </div>
    <form method="post" style="margin:0" onsubmit="return confirm('Cancel this offer? Your items will be returned.')"><input type="hidden" name="action" value="cancel"><input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>"><button style="font-size:11px;padding:3px 12px;color:var(--neon2);border-color:rgba(255,45,149,.3);background:transparent">Cancel Offer</button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ── NEW TRADE ── */ elseif ($tab === 'new'): ?>
<div class="panel">
  <h3 style="margin-top:0">Propose a Trade</h3>
  <p class="muted" style="font-size:12px;margin-top:0">Items and currency you offer are locked in escrow until the trade is accepted, rejected, or cancelled.</p>

  <?php if (!empty($tradeFriends)): ?>
  <div style="margin-bottom:12px">
    <div style="font-size:11px;color:var(--muted);margin-bottom:5px">Quick-select a friend:</div>
    <div style="display:flex;flex-wrap:wrap;gap:5px">
      <?php foreach ($tradeFriends as $tf): $tfc = chat_color($tf['role'],$tf['chat_color']); ?>
      <button type="button" data-fill-trade="<?= e($tf['username']) ?>" style="font-size:11px;padding:3px 10px;color:<?= e($tfc) ?>;border-color:<?= e($tfc) ?>;background:rgba(0,0,0,.2)"><?= e($tf['username']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="propose">
    <div class="field" style="max-width:280px">
      <span>Trade with (handle or ID)</span>
      <div class="ac-wrap">
        <input type="text" name="to_name" id="tradeToName" value="<?= e($withName) ?>" autocomplete="off" data-no-counter>
        <div class="ac-list" id="tradeAcList" style="display:none"></div>
      </div>
      <div id="tradeConfirm" style="display:none;margin-top:6px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:5px;padding:7px 10px;font-size:12px"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
      <div>
        <div style="font-size:12px;font-weight:700;color:#3bcf63;margin-bottom:8px">&#8595; You Offer</div>
        <div class="field"><span>Credits (pocket: <?= number_format((int)$player['creds_pocket']) ?>)</span><input type="number" name="from_credits" value="0" min="0"></div>
        <div class="field"><span>Shards (&#9670; <?= number_format((int)$player['shards']) ?>)</span><input type="number" name="from_shards" value="0" min="0"></div>
        <?php if (!empty($myInv)): ?>
        <div style="font-size:11px;color:var(--muted);margin-bottom:5px">Items from inventory:</div>
        <div id="fromItems">
          <div class="from-item-row" style="display:flex;gap:6px;margin-bottom:5px">
            <select name="from_item_id[]" style="flex:1;font-size:11px">
              <option value="">-- None --</option>
              <?php foreach ($myInv as $it): ?><option value="<?= (int)$it['item_id'] ?>"><?= e($it['name']) ?> (×<?= (int)$it['qty'] ?>)</option><?php endforeach; ?>
            </select>
            <input type="number" name="from_item_qty[]" value="1" min="1" style="width:55px;font-size:11px">
          </div>
        </div>
        <button type="button" style="font-size:10px;padding:2px 8px;margin-bottom:8px" onclick="addItemRow('fromItems','from')">+ Add Item</button>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--neon2);margin-bottom:8px">&#8593; You Request</div>
        <div class="field"><span>Credits from them</span><input type="number" name="to_credits" value="0" min="0"></div>
        <div class="field"><span>Shards from them</span><input type="number" name="to_shards" value="0" min="0"></div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:5px">Items to request:</div>
        <div id="toItems">
          <div class="to-item-row" style="display:flex;gap:6px;margin-bottom:5px">
            <select name="to_item_id[]" style="flex:1;font-size:11px">
              <option value="">-- None --</option>
              <?php foreach ($myInv as $it): ?><option value="<?= (int)$it['item_id'] ?>"><?= e($it['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="number" name="to_item_qty[]" value="1" min="1" style="width:55px;font-size:11px">
          </div>
        </div>
        <button type="button" style="font-size:10px;padding:2px 8px;margin-bottom:8px" onclick="addItemRow('toItems','to')">+ Add Item</button>
      </div>
    </div>

    <div class="field" style="max-width:400px"><span>Note (optional)</span><input type="text" name="note" maxlength="200" placeholder="e.g. for the job last cycle..." data-no-counter></div>
    <button type="submit">&#128260; Send Trade Proposal</button>
  </form>
</div>

<?php /* ── DIRECT TRANSFER ── */ elseif ($tab === 'transfer'): ?>
<div class="panel">
  <h3 style="margin-top:0">Direct Transfer</h3>
  <p class="muted" style="font-size:12px;margin-top:0">Instant, no escrow. Credit or shard transfers only — use New Trade for items.</p>

  <?php if (!empty($tradeFriends)): ?>
  <div style="margin-bottom:12px">
    <div style="font-size:11px;color:var(--muted);margin-bottom:5px">Quick-select:</div>
    <div style="display:flex;flex-wrap:wrap;gap:5px">
      <?php foreach ($tradeFriends as $tf): $tfc = chat_color($tf['role'],$tf['chat_color']); ?>
      <button type="button" data-fill-xfer="<?= e($tf['username']) ?>" style="font-size:11px;padding:3px 10px;color:<?= e($tfc) ?>;border-color:<?= e($tfc) ?>;background:rgba(0,0,0,.2)"><?= e($tf['username']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <form method="post" style="max-width:360px">
    <input type="hidden" name="action" value="transfer">
    <div class="field"><span>Recipient (handle or ID)</span>
      <div class="ac-wrap"><input type="text" name="to_name" id="xferToName" value="<?= e($withName) ?>" autocomplete="off" data-no-counter>
      <div class="ac-list" id="xferAcList" style="display:none"></div></div>
      <div id="xferConfirm" style="display:none;margin-top:6px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:5px;padding:7px 10px;font-size:12px"></div>
    </div>
    <div class="field"><span>Amount</span><input type="number" name="amount" min="1" value="1"></div>
    <div class="field"><span>Currency</span>
      <label style="display:flex;align-items:center;gap:4px;cursor:pointer;margin-bottom:4px"><input type="radio" name="currency" value="credits" checked style="width:auto"> Credits (pocket: <?= number_format((int)$player['creds_pocket']) ?>)</label>
      <label style="display:flex;align-items:center;gap:4px;cursor:pointer"><input type="radio" name="currency" value="shards" style="width:auto"> <span style="color:#e8d44d">Shards (&#9670; <?= number_format((int)$player['shards']) ?>)</span></label>
    </div>
    <button type="submit" onclick="return confirm('Send this transfer? It cannot be reversed.')">&#128178; Send Transfer</button>
  </form>
</div>
<?php endif; ?>

<script>
(function(){
  var invOpts = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['item_id'],'name'=>$it['name'],'qty'=>(int)$it['qty']],$myInv)) ?>;
  window.addItemRow=function(containerId,dir){
    var c=document.getElementById(containerId); if(!c) return;
    var row=document.createElement('div'); row.style.cssText='display:flex;gap:6px;margin-bottom:5px';
    var sel=document.createElement('select'); sel.name=dir+'_item_id[]'; sel.style.cssText='flex:1;font-size:11px';
    var none=document.createElement('option'); none.value=''; none.textContent='-- None --'; sel.appendChild(none);
    invOpts.forEach(function(it){ var o=document.createElement('option'); o.value=it.id; o.textContent=it.name+(dir==='from'?' (×'+it.qty+')':''); sel.appendChild(o); });
    var qty=document.createElement('input'); qty.type='number'; qty.name=dir+'_item_qty[]'; qty.value='1'; qty.min='1'; qty.style.cssText='width:55px;font-size:11px';
    row.appendChild(sel); row.appendChild(qty); c.appendChild(row);
  };
  // Autocomplete helper
  function ac(inp,listEl,confirmEl){
    if(!inp||!listEl) return;
    var cur=-1,items=[];
    function show(names){ items=names; cur=-1; if(!names.length){listEl.style.display='none';return;} listEl.innerHTML=''; names.forEach(function(n,i){ var d=document.createElement('div'); d.className='ac-item'; d.textContent=n; d.addEventListener('mousedown',function(e){e.preventDefault();inp.value=n;listEl.style.display='none';lookupPlayer(n,confirmEl);}); listEl.appendChild(d); }); listEl.style.display='block'; }
    inp.addEventListener('input',function(){ var q=inp.value.trim(); if(confirmEl) confirmEl.style.display='none'; if(q.length<1){listEl.style.display='none';return;} fetch('players_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(show).catch(function(){}); });
    inp.addEventListener('blur',function(){ var q=inp.value.trim(); if(q.length>0 && confirmEl) lookupPlayer(q,confirmEl); });
    inp.addEventListener('keydown',function(e){ if(!items.length) return; var rows=listEl.querySelectorAll('.ac-item'); if(e.key==='ArrowDown'){e.preventDefault();cur=Math.min(cur+1,rows.length-1);rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);});}else if(e.key==='ArrowUp'){e.preventDefault();cur=Math.max(cur-1,-1);rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);});}else if(e.key==='Enter'&&cur>=0){e.preventDefault();inp.value=items[cur];listEl.style.display='none';lookupPlayer(items[cur],confirmEl);}else if(e.key==='Escape'){listEl.style.display='none';} });
    document.addEventListener('click',function(e){ if(!inp.contains(e.target)&&!listEl.contains(e.target)) listEl.style.display='none'; });
  }
  function lookupPlayer(val,confirmEl){
    if(!confirmEl||!val) return;
    fetch('players_search.php?lookup='+encodeURIComponent(val),{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d){confirmEl.innerHTML='<span style="color:var(--neon2)">&#9888; Player not found.</span>';confirmEl.style.display='block';return;}
        var roles={admin:'<span style="color:#ff4444;font-weight:700">[Admin]</span>',manager:'<span style="color:#ff8800;font-weight:700">[Mgr]</span>',moderator:'<span style="color:#4488ff;font-weight:700">[Mod]</span>'};
        var rb=roles[d.role]||'';
        confirmEl.innerHTML='&#10003; <b style="color:var(--accent)">'+d.username+'</b> '+rb+' &middot; ID #'+d.id+' &middot; Level '+d.level;
        confirmEl.style.display='block';
      }).catch(function(){confirmEl.style.display='none';});
  }
  ac(document.getElementById('tradeToName'),document.getElementById('tradeAcList'),document.getElementById('tradeConfirm'));
  ac(document.getElementById('xferToName'), document.getElementById('xferAcList'),document.getElementById('xferConfirm'));
  document.querySelectorAll('[data-fill-trade]').forEach(function(btn){
    btn.addEventListener('click',function(){ var inp=document.getElementById('tradeToName'); inp.value=btn.dataset.fillTrade; lookupPlayer(inp.value,document.getElementById('tradeConfirm')); });
  });
  document.querySelectorAll('[data-fill-xfer]').forEach(function(btn){
    btn.addEventListener('click',function(){ var inp=document.getElementById('xferToName'); inp.value=btn.dataset.fillXfer; lookupPlayer(inp.value,document.getElementById('xferConfirm')); });
  });
  // Pre-fill confirm if name already set
  (function(){
    var v=document.getElementById('tradeToName'); if(v&&v.value.trim()) lookupPlayer(v.value.trim(),document.getElementById('tradeConfirm'));
    var x=document.getElementById('xferToName'); if(x&&x.value.trim()) lookupPlayer(x.value.trim(),document.getElementById('xferConfirm'));
  })();
})();
</script>
