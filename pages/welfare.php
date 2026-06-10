<?php /* pages/welfare.php — Subsistence Terminal: new ghost relief */
$pid   = $_SESSION['pid'];
$pdo   = db();
$msg   = '';
$today = date('Y-m-d');

define('WELFARE_AMOUNT',   500);
define('WELFARE_MAX_DAYS', 30);  // account must be under 30 days old

// Calculate account age in days
$created = !empty($player['created_at']) ? strtotime($player['created_at']) : time();
$daysOld = (int)floor((time() - $created) / 86400);
$eligible = $daysOld <= WELFARE_MAX_DAYS;

// Check if claimed today
$lastClaim = '';
try {
  $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $q->execute(["welfare_at:{$pid}"]);
  $lastClaim = $q->fetchColumn() ?: '';
} catch (Throwable $e) {}
$claimedToday = ($lastClaim === $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
  try {
    if (!$eligible) throw new RuntimeException('Your account is too old for the Subsistence Terminal.');
    if ($claimedToday) throw new RuntimeException('Already claimed today. Come back tomorrow.');
    $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([WELFARE_AMOUNT, $pid]);
    $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["welfare_at:{$pid}", $today]);
    $msg = 'success';
    $claimedToday = true;
    $player = current_player();
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#3bcf63,var(--accent),transparent)"></div>
  <div style="padding:16px 20px;text-align:center">
    <h2 style="margin:0 0 4px">&#128241; Subsistence Terminal</h2>
    <p class="muted" style="margin:0;font-size:13px">The Grid's support program for fresh arrivals. You have 30 days — use it.</p>
  </div>
</div>

<div class="panel">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px">
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:12px;text-align:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:<?= $eligible ? 'var(--accent)' : 'var(--muted)' ?>"><?= $daysOld ?> day<?= $daysOld !== 1 ? 's' : '' ?></div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Account Age</div>
    </div>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:12px;text-align:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:<?= $eligible ? '#3bcf63' : 'var(--neon2)' ?>"><?= $eligible ? WELFARE_MAX_DAYS - $daysOld . ' days left' : 'Expired' ?></div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Eligibility Window</div>
    </div>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:12px;text-align:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:var(--accent)"><?= number_format(WELFARE_AMOUNT) ?> cr</div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Daily Stipend</div>
    </div>
  </div>

  <?php if ($msg === 'success'): ?>
    <div style="background:rgba(59,207,99,.08);border:1px solid rgba(59,207,99,.3);border-radius:7px;padding:14px;text-align:center;margin-bottom:14px">
      <div style="font-size:24px;margin-bottom:6px">&#10003;</div>
      <div style="font-weight:bold;color:#3bcf63;font-size:14px"><?= number_format(WELFARE_AMOUNT) ?> creds deposited to your pocket.</div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">Come back tomorrow for your next allocation.</div>
    </div>
  <?php elseif ($msg): ?>
    <div style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3);border-radius:7px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:var(--neon2)"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if (!$eligible): ?>
    <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:8px;padding:20px;text-align:center">
      <div style="font-size:28px;margin-bottom:8px">&#128683;</div>
      <div style="font-weight:700;font-size:15px;color:var(--neon2);margin-bottom:6px">Not Eligible</div>
      <div style="font-size:13px;color:var(--muted)">Your account is <?= $daysOld ?> days old. The Subsistence Terminal only serves ghosts within their first <?= WELFARE_MAX_DAYS ?> days on the Grid. You're on your own now.</div>
    </div>
  <?php elseif ($claimedToday): ?>
    <div style="background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.15);border-radius:8px;padding:20px;text-align:center">
      <div style="font-size:28px;margin-bottom:8px">&#128274;</div>
      <div style="font-weight:700;font-size:15px;color:var(--accent);margin-bottom:6px">Already Claimed Today</div>
      <div style="font-size:13px;color:var(--muted)">Your daily allocation of <?= number_format(WELFARE_AMOUNT) ?> creds has been issued. Resets at midnight server time.</div>
    </div>
  <?php else: ?>
    <div style="text-align:center">
      <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
        New ghosts receive <b style="color:var(--accent)"><?= number_format(WELFARE_AMOUNT) ?> creds</b> daily for their first <?= WELFARE_MAX_DAYS ?> days in the Sprawl. Use it wisely — the Grid doesn't hand out freebies forever.
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="claim">
        <button type="submit" style="padding:12px 32px;font-size:14px;background:rgba(59,207,99,.12);border:1px solid rgba(59,207,99,.4);color:#3bcf63">
          &#128197; Claim Daily Stipend &mdash; <?= number_format(WELFARE_AMOUNT) ?> creds
        </button>
      </form>
    </div>
  <?php endif; ?>

  <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
    <div style="font-size:11px;color:var(--muted);font-style:italic;text-align:center">
      "The Subsistence Terminal is funded by the Grid Authority's Rehabilitation Program. Eligibility is non-negotiable and non-transferable."
    </div>
  </div>
</div>

<!-- Tips for new players -->
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;color:var(--accent)">&#128161; New Ghost Tips</h3>
  <div style="display:flex;flex-direction:column;gap:8px;font-size:12px;color:var(--muted)">
    <div>&#8594; Train your skills at the <a href="index.php?p=datacore&act=lab">Skillsoft Lab</a> to unlock better gear and locations.</div>
    <div>&#8594; Fight at the <a href="index.php?p=sim">Combat Sim</a> to earn XP, creds, and loot.</div>
    <div>&#8594; Deposit creds at the <a href="index.php?p=ledger&act=bank">Bank</a> — you earn 0.5% daily interest on your balance.</div>
    <div>&#8594; Grab the daily Shard vault at <a href="index.php?p=exchange">The Exchange</a> for premium currency.</div>
    <div>&#8594; Check <a href="index.php?p=city">The Sprawl</a> for all districts and services.</div>
  </div>
</div>
