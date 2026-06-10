<?php /* pages/jail.php — City Lockup: Confinement Grid */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$isStaff = in_array($player['role'] ?? 'member', ['admin','manager'], true);

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS jail_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    days INT NOT NULL DEFAULT 1,
    jailed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    release_at DATETIME NOT NULL,
    jailed_by INT NOT NULL,
    status ENUM('active','released') NOT NULL DEFAULT 'active',
    released_early_by INT NULL,
    released_at DATETIME NULL,
    INDEX idx_player (player_id), INDEX idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaff) {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'jail') {
      $handle = trim($_POST['handle'] ?? '');
      $reason = trim($_POST['reason'] ?? '');
      $days   = max(1, min(365, (int)($_POST['days'] ?? 1)));
      if (!$handle) throw new RuntimeException('Enter a ghost handle.');
      if (mb_strlen($reason) < 4) throw new RuntimeException('Provide a reason (4+ chars).');
      $q = $pdo->prepare('SELECT id, username FROM players WHERE username=?');
      $q->execute([$handle]); $target = $q->fetch();
      if (!$target) throw new RuntimeException('"' . e($handle) . '" is not a ghost in the Sprawl.');
      if ((int)$target['id'] === $pid) throw new RuntimeException("You can't jail yourself.");
      $pdo->prepare("UPDATE jail_records SET status='released', released_early_by=?, released_at=NOW() WHERE player_id=? AND status='active'")->execute([$pid, (int)$target['id']]);
      $pdo->prepare("INSERT INTO jail_records (player_id, reason, days, jailed_at, release_at, jailed_by) VALUES (?,?,?,NOW(), DATE_ADD(NOW(), INTERVAL ? DAY),?)")->execute([(int)$target['id'], $reason, $days, $days, $pid]);
      $msg = e($target['username']) . " jailed for {$days} day(s).";

    } elseif ($act === 'release') {
      $jid = (int)($_POST['jid'] ?? 0);
      $pdo->prepare("UPDATE jail_records SET status='released', released_early_by=?, released_at=NOW() WHERE id=? AND status='active'")->execute([$pid, $jid]);
      $msg = 'Player released.';
    }
  } catch (RuntimeException $ex) { $msg = $ex->getMessage(); }
}

// Load active jail records
$activeJailed = [];
try {
  $q = $pdo->query("SELECT j.*, p.username AS target_name, s.username AS staff_name
    FROM jail_records j
    JOIN players p ON p.id=j.player_id
    JOIN players s ON s.id=j.jailed_by
    WHERE j.status='active' AND j.release_at > NOW()
    ORDER BY j.jailed_at DESC");
  $activeJailed = $q->fetchAll();
} catch (Throwable $e) {}

// Check if current player is jailed (for personal view)
$myJail = null;
foreach ($activeJailed as $jr) { if ((int)$jr['player_id'] === $pid) { $myJail = $jr; break; } }

// Recent release history (last 10)
$history = [];
try {
  $q = $pdo->query("SELECT j.*, p.username AS target_name, s.username AS staff_name
    FROM jail_records j JOIN players p ON p.id=j.player_id JOIN players s ON s.id=j.jailed_by
    WHERE j.status='released' ORDER BY j.jailed_at DESC LIMIT 10");
  $history = $q->fetchAll();
} catch (Throwable $e) {}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--neon2),rgba(255,45,149,.3),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#128274; Confinement Grid</h2>
    <p class="muted" style="margin:0;font-size:12px">Ghosts in lock-down. Violations of Grid Authority directives result in forced account suspension.</p>
  </div>
</div>

<?php if ($msg): ?>
<div style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3);border-radius:6px;padding:10px 14px;font-size:13px;color:var(--neon2)"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($myJail): ?>
<!-- Player's own jail notice (if they somehow access this page) -->
<div class="panel" style="border:2px solid rgba(255,45,149,.5);background:rgba(255,45,149,.05);text-align:center">
  <div style="font-size:36px;margin-bottom:10px">&#128274;</div>
  <h2 style="color:var(--neon2);font-family:'Orbitron',sans-serif;margin-bottom:8px">YOU ARE JAILED</h2>
  <p style="color:var(--muted);margin-bottom:8px"><?= e($myJail['reason']) ?></p>
  <?php $secsLeft = strtotime($myJail['release_at']) - time(); $daysLeft = ceil($secsLeft / 86400); ?>
  <div style="font-family:'Orbitron',sans-serif;font-size:24px;font-weight:700;color:var(--neon2)"><?= max(0,$daysLeft) ?> day<?= $daysLeft!==1?'s':'' ?> remaining</div>
  <p class="muted" style="font-size:11px;margin-top:8px">Releases: <?= e(date('M j, Y g:ia', strtotime($myJail['release_at']))) ?></p>
</div>
<?php endif; ?>

<!-- Active Jail List -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
    <h3 style="margin:0">Currently Jailed <span style="background:rgba(255,45,149,.12);border:1px solid rgba(255,45,149,.3);color:var(--neon2);padding:2px 10px;border-radius:20px;font-size:12px;margin-left:8px"><?= count($activeJailed) ?></span></h3>
  </div>
  <?php if (empty($activeJailed)): ?>
    <div style="padding:28px;text-align:center;color:var(--muted)">No ghosts currently in lock-down.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:140px 1fr 90px 120px <?= $isStaff ? '80px' : '' ?>;padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line)">
      <span>Ghost</span><span>Reason</span><span>Remaining</span><span>Jailed By</span><?= $isStaff ? '<span>Release</span>' : '' ?>
    </div>
    <?php foreach ($activeJailed as $jr):
      $secsLeft = strtotime($jr['release_at']) - time();
      $daysLeft = ceil($secsLeft / 86400);
    ?>
    <div style="display:grid;grid-template-columns:140px 1fr 90px 120px <?= $isStaff ? '80px' : '' ?>;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center">
      <span style="font-weight:700;color:var(--neon2)"><?= e($jr['target_name']) ?></span>
      <span style="color:var(--muted)"><?= e(mb_substr($jr['reason'], 0, 80)) ?><?= mb_strlen($jr['reason']) > 80 ? '...' : '' ?></span>
      <span style="font-family:'Orbitron',sans-serif;font-size:11px;font-weight:700;color:#e8a33d"><?= max(0,$daysLeft) ?>d</span>
      <span class="muted"><?= e($jr['staff_name']) ?></span>
      <?php if ($isStaff): ?>
      <span>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="release">
          <input type="hidden" name="jid" value="<?= (int)$jr['id'] ?>">
          <button type="submit" style="font-size:11px;padding:3px 10px;background:rgba(59,207,99,.08);border-color:rgba(59,207,99,.3);color:#3bcf63">Release</button>
        </form>
      </span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Staff Jail Tool -->
<?php if ($isStaff): ?>
<div class="panel" style="border:1px solid rgba(255,45,149,.2);background:rgba(255,45,149,.03)">
  <h3 style="margin-top:0;color:var(--neon2)">&#128274; Detain a Ghost</h3>
  <form method="post" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="action" value="jail">
    <div>
      <label style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Ghost Handle</label>
      <input type="text" name="handle" placeholder="username" style="width:100%;margin-top:4px">
    </div>
    <div>
      <label style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Reason</label>
      <input type="text" name="reason" maxlength="500" placeholder="Reason for detention" style="width:100%;margin-top:4px">
    </div>
    <div>
      <label style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Days</label>
      <input type="number" name="days" min="1" max="365" value="1" style="width:70px;margin-top:4px">
    </div>
    <div>
      <button type="submit" style="background:rgba(255,45,149,.12);border-color:rgba(255,45,149,.4);color:var(--neon2);padding:9px 18px;margin-top:4px">Detain</button>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- History -->
<?php if ($isStaff && !empty($history)): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:10px 14px;border-bottom:1px solid var(--line)">
    <h3 style="margin:0">Recent Release History</h3>
  </div>
  <?php foreach ($history as $jr): ?>
  <div style="display:flex;gap:12px;padding:8px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center;flex-wrap:wrap">
    <span style="color:var(--muted);width:140px"><b style="color:var(--text)"><?= e($jr['target_name']) ?></b></span>
    <span style="flex:1;color:var(--muted)"><?= e(mb_substr($jr['reason'], 0, 60)) ?></span>
    <span style="color:var(--muted);font-size:11px"><?= (int)$jr['days'] ?>d &middot; by <?= e($jr['staff_name']) ?> &middot; <?= e(date('M j', strtotime($jr['jailed_at']))) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
