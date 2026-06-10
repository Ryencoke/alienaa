<?php /* pages/profile.php — public player profile */
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$pq = $pdo->prepare('SELECT * FROM players WHERE id = ?');
$pq->execute([$id]);
$prof = $pq->fetch();

if (!$prof) {
  echo '<div class="panel"><h2>Profile</h2><p class="muted">No such ghost on the Grid.</p></div>';
  return;
}

$role  = $prof['role'] ?? 'member';
$ccol  = $prof['chat_color'] ?? '#c9d1e0';
$bio   = trim($prof['bio'] ?? '');
$col   = chat_color($role, $ccol);
$rlbl  = role_label($role);
$isMe  = ((int)$prof['id'] === (int)($_SESSION['pid'] ?? 0));
$country = strtolower(trim($prof['country'] ?? ''));

$rec = ['win' => 0, 'loss' => 0];
try {
  $rq = $pdo->prepare('SELECT outcome, COUNT(*) c FROM combat_log WHERE player_id = ? GROUP BY outcome');
  $rq->execute([$id]);
  foreach ($rq as $r) { if (isset($rec[$r['outcome']])) $rec[$r['outcome']] = (int)$r['c']; }
} catch (Throwable $e) {}

$postCount = 0;
try { $pc = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?'); $pc->execute([$id]); $postCount = (int)$pc->fetchColumn(); } catch (Throwable $e) {}

$msgCount = 0;
try { $mc = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE from_id = ?'); $mc->execute([$id]); $msgCount = (int)$mc->fetchColumn(); } catch (Throwable $e) {}

$casinoStats = ['games' => 0, 'net' => 0];
try {
  $cq = $pdo->prepare('SELECT COUNT(*) games, COALESCE(SUM(net),0) net FROM casino_log WHERE player_id = ?');
  $cq->execute([$id]); $csRow = $cq->fetch();
  if ($csRow) $casinoStats = ['games'=>(int)$csRow['games'],'net'=>(int)$csRow['net']];
} catch (Throwable $e) {}

$isFriend = false;
try {
  $fq = $pdo->prepare('SELECT 1 FROM friends WHERE player_id = ? AND friend_id = ?');
  $fq->execute([(int)($_SESSION['pid']??0), $id]); $isFriend = (bool)$fq->fetchColumn();
} catch (Throwable $e) {}

$isOnline  = !empty($prof['last_seen']) && strtotime($prof['last_seen']) >= time() - 300;
$isBanned  = $role === 'banned';
$isSub     = is_subscribed($prof);
$totalWins = $rec['win'];
$totalLoss = $rec['loss'];
$winRate   = ($totalWins + $totalLoss) > 0 ? round($totalWins / ($totalWins + $totalLoss) * 100) : 0;
?>

<div class="panel">
  <div class="prof-hero">
    <div class="prof-avatar"><?= mb_strtoupper(mb_substr($prof['username'],0,1)) ?></div>
    <div class="prof-main">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="prof-username" style="color:<?= e($col) ?>"><?= e($prof['username']) ?></div>
        <?php echo flag_img($country); ?>
        <?php if ($isOnline && !$isBanned): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#3bcf63;box-shadow:0 0 6px #3bcf63" title="Online"></span><?php endif; ?>
      </div>

      <div class="prof-meta">
        <?php if ($role !== 'member'): ?>
          <span class="prof-meta-item" style="color:<?= e($col) ?>">&#128737; <em style="font-style:italic"><?= e($rlbl) ?></em></span>
        <?php endif; ?>
        <?php if ($isSub): ?>
          <span class="prof-meta-item" style="color:#e8d44d">&#9733; Subscriber</span>
        <?php endif; ?>
        <span class="prof-meta-item">&#127381; Level <?= (int)$prof['level'] ?></span>
        <?php if (!empty($prof['created_at'])): ?>
          <span class="prof-meta-item">&#128197; Joined <?= e(date('M Y', strtotime($prof['created_at']))) ?></span>
        <?php endif; ?>
        <?php
          $lastSeenTs = strtotime($prof['last_seen'] ?? '');
          $lsStr = $isOnline ? '<span style="color:#3bcf63">Online now</span>' : ($lastSeenTs ? e(date('M j, g:ia', $lastSeenTs)) : '');
        ?>
      </div>

      <?php if ($bio !== ''): ?>
        <div class="prof-bio"><?= e($bio) ?></div>
      <?php endif; ?>

      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($isMe): ?>
          <a href="index.php?p=account" class="btn btn-ghost btn-sm">&#9998; Edit Profile</a>
        <?php else: ?>
          <a href="index.php?p=messages&u=<?= (int)$prof['id'] ?>" class="btn btn-ghost btn-sm">&#9993; Message</a>
          <?php if (!$isFriend): ?>
            <form method="post" action="index.php?p=friends" style="display:inline;margin:0">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="friend_id" value="<?= (int)$prof['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm">&#43; Add Friend</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <h3 style="margin-bottom:12px">&#128202; Stats</h3>
  <div class="prof-grid">
    <div class="prof-stat">
      <div class="val" style="color:var(--accent)"><?= (int)$prof['level'] ?></div>
      <div class="lbl">Level</div>
    </div>
    <div class="prof-stat">
      <div class="val"><?= number_format($postCount) ?></div>
      <div class="lbl">Board Posts</div>
    </div>
    <div class="prof-stat">
      <div class="val" style="color:var(--muted);font-size:16px">#<?= (int)$prof['id'] ?></div>
      <div class="lbl">Player ID</div>
    </div>
  </div>
  <?php if ($lsStr !== '' || !empty($prof['birthday'])): ?>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:12px;color:var(--muted)">
    <?php if ($lsStr !== ''): ?><span>&#128337; <?= $lsStr ?></span><?php endif; ?>
    <?php if (!empty($prof['birthday'])): ?><span>&#127874; <?= e(date('M j', strtotime($prof['birthday']))) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h3 style="margin-bottom:10px">&#9876; Combat Record</h3>
  <?php if (($totalWins + $totalLoss) > 0): ?>
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
    <div style="text-align:center;min-width:48px">
      <div style="font-size:22px;font-weight:700;color:var(--accent)"><?= $totalWins ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Wins</div>
    </div>
    <div style="flex:1;height:8px;background:var(--panel2);border-radius:4px;overflow:hidden;border:1px solid var(--line)">
      <div style="height:100%;width:<?= $winRate ?>%;background:<?= $winRate >= 50 ? 'var(--accent)' : 'var(--neon2)' ?>;border-radius:4px"></div>
    </div>
    <div style="text-align:center;min-width:48px">
      <div style="font-size:22px;font-weight:700;color:var(--neon2)"><?= $totalLoss ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Losses</div>
    </div>
  </div>
  <div style="text-align:center;font-size:12px;color:var(--muted)"><?= $winRate ?>% win rate &middot; <?= $totalWins + $totalLoss ?> fights total</div>
  <?php else: ?>
  <p class="muted" style="text-align:center;margin:0">No combat history yet.</p>
  <?php endif; ?>
</div>

<?php
$badges = [];
if ($isSub) $badges[] = ['&#9733; Subscriber', 'gold'];
if ($role !== 'member') $badges[] = [e($rlbl), 'pink'];
if ($totalWins >= 100) $badges[] = ['&#9876; Centurion', 'teal'];
if ($totalWins >= 50)  $badges[] = ['&#9876; Fighter', 'teal'];
if ($postCount >= 100) $badges[] = ['&#128172; Forum Regular', 'teal'];
if ((int)$prof['level'] >= 10) $badges[] = ['&#127381; Veteran', 'teal'];
if ($casinoStats['net'] >= 10000) $badges[] = ['&#127920; High Roller', 'gold'];
if ($badges):
?>
<div class="panel">
  <h3 style="margin-bottom:8px">&#127942; Badges</h3>
  <div class="prof-badges">
    <?php foreach ($badges as [$label, $type]): ?>
      <span class="prof-badge <?= $type ?>"><?= $label ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// ── Trade offer schema ──
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS trade_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_id INT NOT NULL, to_id INT NOT NULL,
    from_credits BIGINT NOT NULL DEFAULT 0,
    to_credits BIGINT NOT NULL DEFAULT 0,
    note VARCHAR(200) NULL,
    status ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_to (to_id, status), INDEX idx_from (from_id, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$myPid = (int)($_SESSION['pid'] ?? 0);
$isStaffViewer = in_array($player['role'] ?? '', ['admin','manager','moderator'], true);

// ── Handle trade actions ──
$profMsg = '';
if (!$isMe && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $pa = $_POST['prof_action'] ?? '';
  try {
    if ($pa === 'propose_trade') {
      $fcr = max(0,(int)($_POST['from_credits']??0));
      $tcr = max(0,(int)($_POST['to_credits']??0));
      $note = mb_substr(trim($_POST['trade_note']??''),0,200);
      if ($fcr<1&&$tcr<1) throw new RuntimeException('Offer at least 1 credit on either side.');
      if ($fcr>(int)$player['creds_pocket']) throw new RuntimeException('Not enough credits in pocket.');
      $ec = $pdo->prepare('SELECT COUNT(*) FROM trade_offers WHERE from_id=? AND to_id=? AND status="pending"');
      $ec->execute([$myPid,$id]);
      if ((int)$ec->fetchColumn()>0) throw new RuntimeException('You already have a pending offer with this player.');
      if ($fcr>0) { $u=$pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?'); $u->execute([$fcr,$myPid,$fcr]); if ($u->rowCount()!==1) throw new RuntimeException('Not enough credits.'); }
      $pdo->prepare('INSERT INTO trade_offers (from_id,to_id,from_credits,to_credits,note) VALUES (?,?,?,?,?)')->execute([$myPid,$id,$fcr,$tcr,$note]);
      $profMsg = 'Trade proposal sent.';

    } elseif ($pa === 'accept_trade') {
      $oid = (int)($_POST['offer_id']??0);
      $pdo->beginTransaction();
      $oq = $pdo->prepare('SELECT * FROM trade_offers WHERE id=? AND to_id=? AND status="pending" FOR UPDATE');
      $oq->execute([$oid,$myPid]); $offer = $oq->fetch();
      if (!$offer) throw new RuntimeException('Offer not found.');
      if ($offer['to_credits']>0) { $u=$pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?'); $u->execute([$offer['to_credits'],$myPid,$offer['to_credits']]); if ($u->rowCount()!==1) throw new RuntimeException('Not enough credits to accept.'); }
      if ($offer['from_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['from_credits'],$myPid]);
      if ($offer['to_credits']>0)   $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['to_credits'],(int)$offer['from_id']]);
      $pdo->prepare('UPDATE trade_offers SET status="accepted",resolved_at=NOW() WHERE id=?')->execute([$oid]);
      $pdo->commit(); $profMsg = 'Trade accepted! Credits exchanged.';

    } elseif ($pa === 'reject_trade') {
      $oid = (int)($_POST['offer_id']??0);
      $pdo->beginTransaction();
      $oq = $pdo->prepare('SELECT * FROM trade_offers WHERE id=? AND to_id=? AND status="pending" FOR UPDATE');
      $oq->execute([$oid,$myPid]); $offer = $oq->fetch();
      if (!$offer) throw new RuntimeException('Offer not found.');
      if ($offer['from_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['from_credits'],(int)$offer['from_id']]);
      $pdo->prepare('UPDATE trade_offers SET status="rejected",resolved_at=NOW() WHERE id=?')->execute([$oid]);
      $pdo->commit(); $profMsg = 'Trade rejected. Credits refunded.';

    } elseif ($pa === 'cancel_trade') {
      $oid = (int)($_POST['offer_id']??0);
      $pdo->beginTransaction();
      $oq = $pdo->prepare('SELECT * FROM trade_offers WHERE id=? AND from_id=? AND status="pending" FOR UPDATE');
      $oq->execute([$oid,$myPid]); $offer = $oq->fetch();
      if (!$offer) throw new RuntimeException('Offer not found.');
      if ($offer['from_credits']>0) $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$offer['from_credits'],$myPid]);
      $pdo->prepare('UPDATE trade_offers SET status="cancelled",resolved_at=NOW() WHERE id=?')->execute([$oid]);
      $pdo->commit(); $profMsg = 'Offer cancelled. Credits refunded.';
    }
  } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); $profMsg = $ex->getMessage(); }
}
?>

<?php if ($profMsg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($profMsg) ?></div>
<?php endif; ?>

<?php if (!$isMe): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#9889; Quick Actions</h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <a href="index.php?p=messages&to=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--accent);color:var(--accent);border-radius:6px;background:rgba(25,240,199,.05)">&#9993; Send Message</a>
    <a href="index.php?p=ledger&act=transfer&to=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--line);color:var(--text);border-radius:6px;background:var(--panel2)">&#128178; Transfer Credits</a>
    <?php if ($isFriend): ?>
    <a href="index.php?p=friends&unfriend=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:6px;background:rgba(255,45,149,.04)">&#10005; Remove Friend</a>
    <?php else: ?>
    <a href="index.php?p=friends&add=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--line);color:var(--muted);border-radius:6px;background:var(--panel2)">&#43; Add Friend</a>
    <?php endif; ?>
  </div>
</div>

<!-- Secure Trade -->
<?php
  $incomingTrades = [];
  try { $tq = $pdo->prepare('SELECT tr.*,p.username AS from_name FROM trade_offers tr JOIN players p ON p.id=tr.from_id WHERE tr.to_id=? AND tr.from_id=? AND tr.status="pending" ORDER BY tr.id DESC'); $tq->execute([$myPid,$id]); $incomingTrades = $tq->fetchAll(); } catch (Throwable $e) {}
  $outgoingTrades = [];
  try { $tq2 = $pdo->prepare('SELECT * FROM trade_offers WHERE from_id=? AND to_id=? AND status="pending" ORDER BY id DESC'); $tq2->execute([$myPid,$id]); $outgoingTrades = $tq2->fetchAll(); } catch (Throwable $e) {}
?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128260; Secure Trade</h3>
  <p class="muted" style="font-size:12px;margin-top:0">Credits held in escrow until accepted or rejected.</p>

  <?php foreach ($incomingTrades as $offer): ?>
  <div style="background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:7px;padding:10px 12px;margin-bottom:10px">
    <div style="font-size:12px;margin-bottom:6px">
      <b style="color:var(--accent)"><?= e($offer['from_name']??'?') ?></b> offers:
      <?php if ($offer['from_credits']>0): ?><span style="color:#3bcf63"> +<?= number_format($offer['from_credits']) ?> cr to you</span><?php endif; ?>
      <?php if ($offer['to_credits']>0): ?><span style="color:var(--neon2)"> | requests <?= number_format($offer['to_credits']) ?> cr from you</span><?php endif; ?>
      <?php if ($offer['note']): ?><div style="color:var(--muted);margin-top:3px;font-style:italic"><?= e($offer['note']) ?></div><?php endif; ?>
    </div>
    <div style="display:flex;gap:6px">
      <form method="post" style="margin:0"><input type="hidden" name="prof_action" value="accept_trade"><input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>"><button type="submit" style="font-size:11px;padding:4px 12px;background:rgba(59,207,99,.1);border-color:rgba(59,207,99,.3);color:#3bcf63">Accept</button></form>
      <form method="post" style="margin:0"><input type="hidden" name="prof_action" value="reject_trade"><input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>"><button type="submit" style="font-size:11px;padding:4px 12px;background:rgba(226,59,59,.08);border-color:rgba(226,59,59,.25);color:#e23b3b">Reject</button></form>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($outgoingTrades as $offer): ?>
  <div style="background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:7px;padding:10px 12px;margin-bottom:10px;font-size:12px">
    <span class="muted">Pending offer: </span>
    <?php if ($offer['from_credits']>0): ?><span style="color:#e8a33d"><?= number_format($offer['from_credits']) ?> cr offered</span><?php endif; ?>
    <?php if ($offer['to_credits']>0): ?><span style="color:var(--muted)"> | requesting <?= number_format($offer['to_credits']) ?> cr</span><?php endif; ?>
    <form method="post" style="display:inline;margin:0 0 0 10px"><input type="hidden" name="prof_action" value="cancel_trade"><input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>"><button type="submit" style="font-size:10px;padding:2px 8px;background:transparent;border:1px solid var(--line);color:var(--muted)">Cancel</button></form>
  </div>
  <?php endforeach; ?>

  <?php if (empty($outgoingTrades)): ?>
  <form method="post">
    <input type="hidden" name="prof_action" value="propose_trade">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
      <div class="field"><span>You offer (cr) <span class="muted" style="font-size:10px">pocket: <?= number_format((int)$player['creds_pocket']) ?></span></span><input type="number" name="from_credits" value="0" min="0"></div>
      <div class="field"><span>You request (cr)</span><input type="number" name="to_credits" value="0" min="0"></div>
    </div>
    <div class="field" style="margin-bottom:8px"><span>Note <span class="muted">(optional)</span></span><input type="text" name="trade_note" maxlength="200" placeholder="e.g. for syndicate services..."></div>
    <button type="submit" style="font-size:12px">&#128260; Send Trade Proposal</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isStaffViewer): ?>
<div class="panel" style="border:1px solid rgba(226,59,59,.25);background:rgba(226,59,59,.03)">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#e23b3b">&#128736; Admin Tools &mdash; <?= e($prof['username']) ?></h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <a href="index.php?p=admin&sec=players&find=<?= urlencode($prof['username']) ?>" style="padding:6px 14px;font-size:11px;border:1px solid rgba(226,59,59,.3);border-radius:5px;color:#e23b3b;text-decoration:none;background:rgba(226,59,59,.06)">&#128737; Edit Player</a>
    <a href="index.php?p=admin&sec=txlog&pid=<?= $id ?>" style="padding:6px 14px;font-size:11px;border:1px solid var(--line);border-radius:5px;color:var(--muted);text-decoration:none;background:var(--panel2)">&#128178; TX Log</a>
    <a href="index.php?p=admin&sec=combat&pid=<?= $id ?>" style="padding:6px 14px;font-size:11px;border:1px solid var(--line);border-radius:5px;color:var(--muted);text-decoration:none;background:var(--panel2)">&#9876; Combat Log</a>
    <a href="index.php?p=jail&target=<?= $id ?>" style="padding:6px 14px;font-size:11px;border:1px solid rgba(255,45,149,.3);border-radius:5px;color:var(--neon2);text-decoration:none;background:rgba(255,45,149,.04)">&#128274; Jail</a>
  </div>
  <div style="font-size:11px;color:var(--muted)">
    Role: <em style="font-style:italic;color:<?= e(chat_color($role,'')) ?>"><?= e(role_label($role)?:'Member') ?></em> &middot;
    ID: <?= $id ?> &middot;
    Registered: <?= e(date('M j Y',strtotime($prof['created_at']??'now'))) ?> &middot;
    Last seen: <?= $isOnline ? '<span style="color:#3bcf63">Online now</span>' : e(date('M j g:ia',strtotime($prof['last_seen']??'now'))) ?>
  </div>
</div>
<?php endif; ?>
