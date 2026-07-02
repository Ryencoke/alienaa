<?php /* pages/contracts.php — The Contract Board: daily cross-activity objectives.
   The board offers CONTRACT_DAILY_COUNT objectives per Mountain-Time day (the
   same set for everyone, rotating daily). Progress ticks automatically as you
   play the activities they watch (contract_record() is called from sim/pvp/
   mining/transit/thenet/weaponcraft/synth); finished contracts are claimed
   here for credits + XP. Pure rules live in contracts_engine.php. */
require_once __DIR__ . '/../contracts_engine.php';

$pid = (int)$player['id'];
$pdo = db();
ensure_player_contracts_table($pdo);

$day     = contracts_mt_date();
$today   = daily_contracts($day);          // [cid => def] for today
$msg = ''; $msgErr = false;

// Make sure today's contracts have a row so they render at 0 even before any
// matching activity (and so the claim gate has a row to flip).
try {
  $seed = $pdo->prepare('INSERT IGNORE INTO player_contracts (player_id, day, contract_id, progress) VALUES (?,?,?,0)');
  foreach (array_keys($today) as $cid) $seed->execute([$pid, $day, $cid]);
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
  try {
    $cid = $_POST['contract_id'] ?? '';
    if (!isset($today[$cid])) throw new RuntimeException('That contract is not on the board today.');
    $def = $today[$cid];
    // Atomic claim: flip claimed 0->1 only if complete and unclaimed. rowCount
    // gate means a double-submit / two tabs can't pay the reward twice.
    $claim = $pdo->prepare('UPDATE player_contracts SET claimed=1
                            WHERE player_id=? AND day=? AND contract_id=? AND claimed=0 AND progress>=?');
    $claim->execute([$pid, $day, $cid, (int)$def['goal']]);
    if ($claim->rowCount() !== 1) throw new RuntimeException('That contract is not ready to claim.');
    $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([(int)$def['cr'], $pid]);
    if ((int)$def['xp'] > 0) { try { grant_xp($pid, (int)$def['xp']); } catch (Throwable $e) {} }
    $player = current_player();
    $msg = 'Contract complete: ' . e($def['label']) . ' &mdash; +' . number_format((int)$def['cr']) . ' credits, +' . (int)$def['xp'] . ' XP.';
  } catch (Throwable $ex) {
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

// Load progress/claimed for today.
$state = [];
try {
  $q = $pdo->prepare('SELECT contract_id, progress, claimed FROM player_contracts WHERE player_id=? AND day=?');
  $q->execute([$pid, $day]);
  foreach ($q as $r) $state[$r['contract_id']] = ['progress'=>(int)$r['progress'], 'claimed'=>(int)$r['claimed']];
} catch (Throwable $e) {}

$doneCount = 0; $claimable = 0;
foreach ($today as $cid => $def) {
  $prog = $state[$cid]['progress'] ?? 0; $cl = $state[$cid]['claimed'] ?? 0;
  if ($prog >= $def['goal']) { $doneCount++; if (!$cl) $claimable++; }
}
// Seconds until the next Mountain-Time midnight (board rotation).
$mtNow  = new DateTime('now', new DateTimeZone('America/Denver'));
$mtNext = (clone $mtNow)->modify('tomorrow midnight');
$secsLeft = max(0, $mtNext->getTimestamp() - $mtNow->getTimestamp());
$hrsLeft = intdiv($secsLeft, 3600); $minLeft = intdiv($secsLeft % 3600, 60);
?>
<style>
#ct-head h2{text-shadow:0 0 14px rgba(232,212,77,.4)}
.ct-card{position:relative;overflow:hidden;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px;transition:border-color .15s,transform .12s}
.ct-card.done{border-color:rgba(59,207,99,.4)}
.ct-card.claimed{opacity:.62}
.ct-bar{height:10px;border-radius:5px;background:#0a0812;overflow:hidden;margin:8px 0 4px}
.ct-bar>div{height:100%;border-radius:5px;transition:width .4s ease}
</style>

<div class="panel" id="ct-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0">&#128220; Contract Board</h2>
    <p class="muted" style="margin:4px 0 0;font-size:12px">Daily jobs from across the Sprawl. Progress ticks as you play &mdash; fight, mine, run cargo, dive the Net, forge, brew. Finish them here for credits and XP.</p>
  </div>
  <div style="text-align:right;font-size:12px;color:var(--muted)">
    <div><b style="color:var(--accent)"><?= $doneCount ?></b> / <?= count($today) ?> complete</div>
    <div style="margin-top:3px">Rotates in <b style="color:var(--text)"><?= $hrsLeft ?>h <?= $minLeft ?>m</b></div>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= $msg ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
  <?php foreach ($today as $cid => $def):
    $prog = $state[$cid]['progress'] ?? 0;
    $claimed = ($state[$cid]['claimed'] ?? 0) === 1;
    $goal = (int)$def['goal'];
    $done = $prog >= $goal;
    $pct = $goal > 0 ? min(100, round($prog / $goal * 100)) : 0;
    $barCol = $done ? '#3bcf63' : 'var(--accent)';
  ?>
  <div class="ct-card <?= $done ? 'done' : '' ?> <?= $claimed ? 'claimed' : '' ?>">
    <div style="display:flex;align-items:center;gap:9px;margin-bottom:4px">
      <span style="font-size:22px"><?= $def['icon'] ?></span>
      <div style="flex:1">
        <div style="font-weight:700;font-size:14px"><?= e($def['label']) ?></div>
        <div style="font-size:11px;color:var(--muted)"><?= e($def['blurb']) ?></div>
      </div>
    </div>
    <div class="ct-bar"><div style="width:<?= $pct ?>%;background:<?= $barCol ?>"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:11px">
      <span style="color:var(--muted)"><b style="color:<?= $done ? '#3bcf63' : 'var(--text)' ?>"><?= min($prog, $goal) ?></b> / <?= $goal ?></span>
      <span style="color:#e8d44d">+<?= number_format((int)$def['cr']) ?> cr &middot; +<?= (int)$def['xp'] ?> XP</span>
    </div>
    <div style="margin-top:10px">
      <?php if ($claimed): ?>
        <div style="text-align:center;font-size:12px;color:#3bcf63;font-weight:700">&#10003; Claimed</div>
      <?php elseif ($done): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="claim">
          <input type="hidden" name="contract_id" value="<?= e($cid) ?>">
          <button type="submit" class="btn" style="width:100%;background:rgba(59,207,99,.12);border-color:rgba(59,207,99,.45);color:#3bcf63;font-weight:700">Claim Reward</button>
        </form>
      <?php else: ?>
        <div style="text-align:center;font-size:11px;color:var(--muted);font-style:italic">
          <?php
            $where = ['pve_win'=>'Combat Sim','pvp_win'=>'the Arena','ore_mined'=>'The Sump','cargo_delivered'=>'the Switchyard','net_dive'=>'The Net','net_core'=>'The Net','gear_forged'=>'the Fab Lab','stim_brewed'=>'the Synth Den'][$def['type']] ?? 'the Sprawl';
          ?>
          <?= $goal - min($prog, $goal) ?> to go &mdash; work it at <?= e($where) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="panel" style="margin-top:12px;font-size:11px;color:var(--muted)">
  Contracts refresh every day at midnight Mountain Time. Unclaimed rewards on expired contracts are forfeit &mdash; claim them before the board rotates.
</div>
