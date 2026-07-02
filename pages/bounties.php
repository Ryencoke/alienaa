<?php /* pages/bounties.php — The Bounty Board: player-funded PvP contracts.
   Post credits on a rival's head (escrowed from your pocket immediately);
   whoever BEATS that ghost in the Arena collects every standing bounty on them
   (see lib.php bounty_settle_on_beat, called from pvp.php). Escrow-only, so
   credits only ever move — never mint. Rules in bounty_engine.php. */
require_once __DIR__ . '/../bounty_engine.php';

$pid = (int)$player['id'];
$pdo = db();
ensure_bounties_table($pdo);
$msg = ''; $msgErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'post') {
      $handle = trim($_POST['target'] ?? '');
      $amount = (int)($_POST['amount'] ?? 0);
      if ($handle === '') throw new RuntimeException("Enter the mark's handle.");
      $tq = $pdo->prepare('SELECT id, username, role FROM players WHERE username=?');
      $tq->execute([$handle]); $target = $tq->fetch() ?: null;
      // Validate against the live pocket balance.
      $err = bounty_validate($target, $pid, $amount, (int)$player['creds_pocket']);
      if ($err !== '') throw new RuntimeException($err);
      $pdo->beginTransaction();
      // Escrow atomically — deduct only if the pocket still covers it.
      $d = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id=? AND creds_pocket >= ?');
      $d->execute([$amount, $pid, $amount]);
      if ($d->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough credits in your pocket.'); }
      $pdo->prepare('INSERT INTO bounties (target_id, poster_id, amount) VALUES (?,?,?)')
          ->execute([(int)$target['id'], $pid, $amount]);
      $pdo->commit();
      $player = current_player();
      $msg = 'Bounty posted: ' . number_format($amount) . ' credits on ' . e($target['username']) . '. It pays out to whoever beats them in the Arena.';
    } elseif ($act === 'cancel') {
      $bid = (int)($_POST['bounty_id'] ?? 0);
      $pdo->beginTransaction();
      // Claim the cancel atomically: only an active bounty you posted, and only
      // once (rowCount gate) — so a double-submit can't double-refund.
      $bq = $pdo->prepare("SELECT amount FROM bounties WHERE id=? AND poster_id=? AND status='active' FOR UPDATE");
      $bq->execute([$bid, $pid]);
      $amt = $bq->fetchColumn();
      if ($amt === false) { $pdo->rollBack(); throw new RuntimeException('That bounty is not yours or already resolved.'); }
      $c = $pdo->prepare("UPDATE bounties SET status='cancelled', resolved_at=NOW() WHERE id=? AND status='active'");
      $c->execute([$bid]);
      if ($c->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('That bounty was just resolved.'); }
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([(int)$amt, $pid]);
      $pdo->commit();
      $player = current_player();
      $msg = 'Bounty cancelled — ' . number_format((int)$amt) . ' credits refunded to your pocket.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

// Marketplace: open bounties aggregated per target (the hunter's shortlist).
$open = [];
try {
  $oq = $pdo->query("SELECT b.target_id, p.username AS target_name, p.role AS target_role, p.integrity, p.merchant_until,
                            SUM(b.amount) AS pool, COUNT(*) AS n
                     FROM bounties b JOIN players p ON p.id=b.target_id
                     WHERE b.status='active' GROUP BY b.target_id, p.username, p.role, p.integrity, p.merchant_until
                     ORDER BY pool DESC LIMIT 40");
  $open = $oq->fetchAll();
} catch (Throwable $e) {}
// Bounties standing on ME.
$onMe = [];
try {
  $mq = $pdo->prepare("SELECT b.amount, pp.username AS poster_name FROM bounties b JOIN players pp ON pp.id=b.poster_id
                       WHERE b.target_id=? AND b.status='active' ORDER BY b.amount DESC");
  $mq->execute([$pid]); $onMe = $mq->fetchAll();
} catch (Throwable $e) {}
$onMePool = 0; foreach ($onMe as $r) $onMePool += (int)$r['amount'];
// MY active postings (cancelable).
$mine = [];
try {
  $pq = $pdo->prepare("SELECT b.id, b.amount, t.username AS target_name FROM bounties b JOIN players t ON t.id=b.target_id
                       WHERE b.poster_id=? AND b.status='active' ORDER BY b.created_at DESC");
  $pq->execute([$pid]); $mine = $pq->fetchAll();
} catch (Throwable $e) {}
$_now = date('Y-m-d');
?>
<style>
#bt-head h2{text-shadow:0 0 14px rgba(255,45,149,.4)}
.bt-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);margin-bottom:7px;flex-wrap:wrap}
</style>

<div class="panel" id="bt-head">
  <h2 style="margin:0">&#127919; Bounty Board</h2>
  <p class="muted" style="margin:4px 0 0;font-size:12px">Put credits on a ghost's head. The escrow is held from your pocket now; whoever beats that ghost in the <a href="index.php?p=pvp" style="color:var(--neon2)">Arena</a> collects every standing bounty on them. Cancel anytime for a full refund.</p>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= $msg ?></div>
<?php endif; ?>

<?php if ($onMe): ?>
<div class="panel" style="border:1px solid rgba(255,45,149,.35);background:rgba(255,45,149,.04)">
  <h3 style="margin-top:0;font-size:13px">&#9888; Bounties on YOU &mdash; <span style="color:var(--neon2)"><?= number_format($onMePool) ?> credits</span> across <?= count($onMe) ?></h3>
  <p class="muted" style="font-size:11px;margin:0">Ghosts are being paid to hunt you. Stay sharp, keep your Health up, and think twice before flatlining in the open.</p>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start" id="bt-grid">
  <!-- Post a bounty -->
  <div class="panel" style="margin:0">
    <h3 style="margin-top:0;font-size:14px">&#128176; Post a Bounty</h3>
    <p class="muted" style="font-size:11px;margin-bottom:10px">Escrowed from your pocket (<b style="color:var(--accent)"><?= number_format((int)$player['creds_pocket']) ?></b> cr). Min <?= number_format(BOUNTY_MIN) ?>, max <?= number_format(BOUNTY_MAX) ?>.</p>
    <form method="post" style="display:flex;flex-direction:column;gap:8px">
      <input type="hidden" name="action" value="post">
      <div class="ac-wrap" style="position:relative">
        <input type="text" id="btTarget" name="target" placeholder="Mark's handle" autocomplete="off" maxlength="32" data-no-counter style="width:100%">
        <div class="ac-list" id="btAcList" style="display:none"></div>
      </div>
      <input type="number" name="amount" min="<?= BOUNTY_MIN ?>" max="<?= BOUNTY_MAX ?>" placeholder="Amount (credits)" data-no-counter style="width:100%">
      <button type="submit" class="btn" style="background:rgba(255,45,149,.1);border-color:rgba(255,45,149,.4);color:var(--neon2)">Post Bounty</button>
    </form>
    <div id="btConfirm" style="display:none;margin-top:8px;font-size:12px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:5px;padding:7px 10px"></div>

    <?php if ($mine): ?>
    <h4 style="margin:16px 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Your Active Bounties</h4>
    <?php foreach ($mine as $b): ?>
    <div class="bt-row" style="margin-bottom:6px;padding:7px 10px">
      <span style="font-size:12px"><b style="color:var(--text)"><?= e($b['target_name']) ?></b> &middot; <b style="color:#e8d44d"><?= number_format((int)$b['amount']) ?> cr</b></span>
      <form method="post" style="margin:0" onsubmit="return confirm('Cancel this bounty and refund the escrow?')">
        <input type="hidden" name="action" value="cancel"><input type="hidden" name="bounty_id" value="<?= (int)$b['id'] ?>">
        <button type="submit" class="btn btn-sm btn-ghost" style="font-size:11px">Cancel</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Open marketplace -->
  <div class="panel" style="margin:0">
    <h3 style="margin-top:0;font-size:14px">&#127919; Open Contracts</h3>
    <p class="muted" style="font-size:11px;margin-bottom:10px">Marks with a price on their head. Beat one in the Arena to collect the pool.</p>
    <?php if (empty($open)): ?>
      <p class="muted" style="text-align:center;padding:14px;font-size:12px">No open bounties. Post the first one.</p>
    <?php else: foreach ($open as $o):
      $tid = (int)$o['target_id'];
      $isMe = $tid === $pid;
      $isStaff = in_array($o['target_role'], ['chatmod','moderator','admin','manager'], true);
      $isMerchant = !empty($o['merchant_until']) && $o['merchant_until'] >= $_now;
      $attackable = !$isMe && !$isStaff && !$isMerchant && (int)$o['integrity'] >= 10;
    ?>
    <div class="bt-row">
      <div style="min-width:0">
        <a href="index.php?p=profile&id=<?= $tid ?>" style="font-weight:700;font-size:13px;color:var(--text);text-decoration:none"><?= e($o['target_name']) ?></a>
        <span style="font-size:10px;color:var(--muted)"><?= (int)$o['n'] ?> bount<?= (int)$o['n']===1?'y':'ies' ?><?= $isMe ? ' &middot; that\'s you' : '' ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <b style="color:#e8d44d;font-family:'Orbitron',sans-serif"><?= number_format((int)$o['pool']) ?></b>
        <?php if ($isMe): ?>
          <span style="font-size:10px;color:var(--neon2)">on you</span>
        <?php elseif ($attackable): ?>
          <a href="index.php?p=pvp&tab=arena&target=<?= urlencode($o['target_name']) ?>" class="btn btn-sm" style="background:rgba(255,45,149,.1);border-color:rgba(255,45,149,.35);color:var(--neon2);font-size:11px">Hunt</a>
        <?php else: ?>
          <span style="font-size:10px;color:var(--muted)"><?= $isStaff ? 'protected' : ($isMerchant ? 'merchant' : 'flatlined') ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
(function(){
  if (window.PlayerAC && document.getElementById('btTarget')) {
    PlayerAC.attach(document.getElementById('btTarget'), document.getElementById('btAcList'), {confirm: document.getElementById('btConfirm')});
  }
})();
</script>
