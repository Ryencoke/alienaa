<?php /* pages/achievements.php — permanent lifetime milestones across every
   activity. Tiered badges (Bronze/Silver/Gold) unlock as lifetime counters
   cross thresholds; each is a one-time credit/shard reward. Complements the
   daily Contract Board (short-term) and the Bounty Board (player-driven).
   Rules in achievements_engine.php; counters in player_lifetime (fed by
   lib.php contract_record()/bounty settle). */
require_once __DIR__ . '/../achievements_engine.php';

$pid = (int)$player['id'];
$pdo = db();
ensure_player_lifetime_table($pdo);
ensure_player_achievements_table($pdo);
$msg = ''; $msgErr = false;

// Build the player's full stat map: lifetime counters + derived live values.
function ach_stats(PDO $pdo, array $player): array {
  $pid = (int)$player['id'];
  $s = lifetime_stats($pdo, $pid);
  $s['level']      = (int)($player['level'] ?? 0);
  $s['net_worth']  = (int)($player['creds_pocket'] ?? 0) + (int)($player['creds_bank'] ?? 0);
  $s['territory_held'] = syn_territory_held($pdo, $pid);
  return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
  try {
    $aid = $_POST['ach_id'] ?? '';
    $def = achievement_def($aid);
    if (!$def) throw new RuntimeException('No such achievement.');
    // Verify completion server-side (never trust the client), then claim once.
    $stats = ach_stats($pdo, $player);
    $val = (int)($stats[$def['metric']] ?? 0);
    if ($val < (int)$def['threshold']) throw new RuntimeException('You haven\'t earned that one yet.');
    // Atomic one-time claim: the PK makes the INSERT the gate.
    $ins = $pdo->prepare('INSERT IGNORE INTO player_achievements (player_id, ach_id) VALUES (?,?)');
    $ins->execute([$pid, $aid]);
    if ($ins->rowCount() !== 1) throw new RuntimeException('Already claimed.');
    if ((int)$def['cr'] > 0)     $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([(int)$def['cr'], $pid]);
    if ((int)$def['shards'] > 0) $pdo->prepare('UPDATE players SET shards = shards + ? WHERE id=?')->execute([(int)$def['shards'], $pid]);
    $player = current_player();
    $reward = [];
    if ((int)$def['cr'] > 0) $reward[] = '+' . number_format((int)$def['cr']) . ' credits';
    if ((int)$def['shards'] > 0) $reward[] = '+' . (int)$def['shards'] . ' &#9670;';
    $msg = 'Achievement claimed: ' . e($def['label']) . ' &mdash; ' . implode(', ', $reward) . '.';
  } catch (Throwable $ex) {
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

$stats  = ach_stats($pdo, $player);
$status = achievements_status($stats);
$flat   = achievements_flat();
// Which have been claimed?
$claimed = [];
try {
  $cq = $pdo->prepare('SELECT ach_id FROM player_achievements WHERE player_id=?');
  $cq->execute([$pid]);
  foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $a) $claimed[$a] = true;
} catch (Throwable $e) {}

// Group by category for display, and tally.
$byCat = []; $doneCount = 0; $claimable = 0;
foreach ($flat as $id => $a) {
  $byCat[$a['category']][] = $id;
  if ($status[$id]['complete']) { $doneCount++; if (empty($claimed[$id])) $claimable++; }
}
$total = count($flat);
$catOrder = ['Combat','Industry','Netrunning','Reputation','Progression','Syndicate'];
uksort($byCat, fn($a,$b) => array_search($a,$catOrder) <=> array_search($b,$catOrder));

function ach_fmt(int $v, string $unit): string {
  if ($unit === 'cr') return number_format($v);
  return number_format($v);
}
?>
<style>
#ach-head h2{text-shadow:0 0 14px rgba(232,212,77,.4)}
.ach-card{position:relative;background:var(--panel2);border:1px solid var(--line);border-radius:9px;padding:11px 13px;transition:border-color .15s}
.ach-card.done{border-color:rgba(232,212,77,.4)}
.ach-card.claimed{opacity:.6}
.ach-bar{height:7px;border-radius:4px;background:#0a0812;overflow:hidden;margin:7px 0 3px}
.ach-bar>div{height:100%;border-radius:4px;transition:width .4s ease}
.ach-tier{font-size:9px;font-family:'Orbitron',sans-serif;letter-spacing:.05em;padding:1px 6px;border-radius:4px}
</style>

<div class="panel" id="ach-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0">&#127942; Achievements</h2>
    <p class="muted" style="margin:4px 0 0;font-size:12px">Permanent milestones across everything you do in the Sprawl. Each badge is a one-time reward &mdash; claim them here. See where you rank at the <a href="index.php?p=awards" style="color:#e8d44d">Grid Rankings</a>.</p>
  </div>
  <div style="text-align:right;font-size:12px;color:var(--muted)">
    <div><b style="color:#e8d44d"><?= $doneCount ?></b> / <?= $total ?> earned</div>
    <?php if ($claimable): ?><div style="margin-top:3px;color:#3bcf63"><b><?= $claimable ?></b> ready to claim</div><?php endif; ?>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= $msg ?></div>
<?php endif; ?>

<?php
$tierCol = [1=>'#c87941', 2=>'#c0c8d8', 3=>'#e8d44d']; // bronze/silver/gold
foreach ($byCat as $cat => $ids): ?>
<div class="panel" style="padding:14px">
  <h3 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#9670; <?= e($cat) ?></h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px">
    <?php foreach ($ids as $id):
      $a = $flat[$id]; $s = $status[$id];
      $done = $s['complete']; $cl = !empty($claimed[$id]);
      $val = $s['value']; $goal = $s['threshold'];
      $pct = $goal > 0 ? min(100, floor($val / $goal * 100)) : 0;
      $tc = $tierCol[$a['tier']] ?? 'var(--muted)';
    ?>
    <div class="ach-card <?= $done ? 'done' : '' ?> <?= $cl ? 'claimed' : '' ?>">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
        <span style="font-size:20px;filter:<?= $done ? 'none' : 'grayscale(1) opacity(.5)' ?>"><?= $a['icon'] ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:12px"><?= e($a['label']) ?></div>
          <span class="ach-tier" style="background:<?= $tc ?>22;color:<?= $tc ?>;border:1px solid <?= $tc ?>55"><?= e($a['tier_name']) ?></span>
        </div>
      </div>
      <div class="ach-bar"><div style="width:<?= $pct ?>%;background:<?= $done ? '#e8d44d' : 'var(--accent)' ?>"></div></div>
      <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--muted)">
        <span><?= ach_fmt(min($val,$goal), $a['unit']) ?> / <?= ach_fmt($goal, $a['unit']) ?> <?= e($a['unit']) ?></span>
        <span style="color:#e8d44d"><?php if ((int)$a['cr']>0): ?>+<?= number_format((int)$a['cr']) ?>cr<?php endif; ?><?php if ((int)$a['shards']>0): ?> +<?= (int)$a['shards'] ?>&#9670;<?php endif; ?></span>
      </div>
      <div style="margin-top:8px">
        <?php if ($cl): ?>
          <div style="text-align:center;font-size:11px;color:#e8d44d;font-weight:700">&#10003; Claimed</div>
        <?php elseif ($done): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="claim"><input type="hidden" name="ach_id" value="<?= e($id) ?>">
            <button type="submit" class="btn btn-sm" style="width:100%;background:rgba(232,212,77,.12);border-color:rgba(232,212,77,.45);color:#e8d44d;font-weight:700">Claim</button>
          </form>
        <?php else: ?>
          <div style="text-align:center;font-size:10px;color:var(--muted)"><?= number_format(max(0,$goal-$val)) ?> to go</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
