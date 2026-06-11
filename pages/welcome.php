<?php /* pages/welcome.php — Welcome: first-login + news + birthdays */
$pid = $_SESSION['pid'];
$pdo = db();

// Latest staff post
$staffPost = null;
try {
  $q = $pdo->query("SELECT t.id, t.title, p.username, p.role, t.created_at,
    (SELECT body FROM posts WHERE topic_id=t.id ORDER BY id ASC LIMIT 1) AS body
    FROM topics t JOIN players p ON p.id=t.author_id
    WHERE p.role IN ('manager','admin') ORDER BY t.created_at DESC LIMIT 1");
  $staffPost = $q->fetch() ?: null;
} catch (Throwable $e) {}

// New players (last 7 days)
$newPlayers = [];
try {
  $newPlayers = $pdo->query("SELECT id, username, created_at FROM players WHERE created_at >= (NOW() - INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

// Birthdays (players whose created_at month/day matches today)
$birthdays = [];
try {
  $birthdays = $pdo->query("SELECT id, username, created_at, YEAR(NOW()) - YEAR(created_at) AS years
    FROM players WHERE DATE_FORMAT(created_at,'%m-%d') = DATE_FORMAT(NOW(),'%m-%d')
    AND YEAR(created_at) < YEAR(NOW()) ORDER BY years DESC LIMIT 20")->fetchAll();
} catch (Throwable $e) {}

// Total stats
$totalPlayers = 0; $onlineNow = 0;
try {
  $totalPlayers = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
  $onlineNow    = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
} catch (Throwable $e) {}
?>

<!-- Header -->
<?= scene_header('wel-canvas', '&#9889;', 'Welcome back, ' . e($player['username']),
      "You're one of " . number_format($totalPlayers) . ' ghosts on the Grid.',
      'pulse', '#19f0c7',
      '<span style="font-size:11px;color:var(--muted)"><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#3bcf63;box-shadow:0 0 8px #3bcf63;vertical-align:middle"></span> <b style="font-family:\'Orbitron\',sans-serif;color:#3bcf63">' . (int)$onlineNow . '</b> ONLINE NOW</span>') ?>
<?= scene_header_js() ?>

<!-- Staff Post -->
<?php if ($staffPost): ?>
<div class="panel" style="border:1px solid rgba(232,212,77,.25);background:rgba(232,212,77,.03)">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <span style="font-size:20px">&#128203;</span>
    <div>
      <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#e8d44d;font-family:'Orbitron',sans-serif">Latest from Staff</div>
      <h3 style="margin:2px 0 0;font-size:15px"><a href="index.php?p=boards&t=<?= (int)$staffPost['id'] ?>" style="color:var(--text)"><?= e($staffPost['title']) ?></a></h3>
    </div>
    <span style="margin-left:auto;font-size:11px;color:var(--muted)"><?= e(date('M j, Y', strtotime($staffPost['created_at']))) ?></span>
  </div>
  <?php if (!empty($staffPost['body'])): ?>
  <div style="font-size:13px;color:var(--muted);line-height:1.6;border-top:1px solid rgba(232,212,77,.15);padding-top:10px">
    <?= e(mb_substr(strip_tags($staffPost['body']), 0, 400)) ?><?= mb_strlen($staffPost['body']) > 400 ? '...' : '' ?>
    <a href="index.php?p=boards&t=<?= (int)$staffPost['id'] ?>" style="color:#e8d44d;margin-left:4px">Read more &rarr;</a>
  </div>
  <?php endif; ?>
  <div style="margin-top:8px;font-size:11px;color:var(--muted)">Posted by <b style="color:#e8d44d"><?= e($staffPost['username']) ?></b></div>
</div>
<?php endif; ?>

<!-- New Players + Birthdays side by side -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">

  <!-- New Players -->
  <div class="panel">
    <h3 style="margin-top:0;margin-bottom:12px">&#127381; New Arrivals <span class="muted" style="font-size:12px;font-weight:400">(last 7 days)</span></h3>
    <?php if (empty($newPlayers)): ?>
      <p class="muted" style="font-size:12px">No new ghosts this week.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($newPlayers as $np):
          $daysAgo = max(0, (int)floor((time() - strtotime($np['created_at'])) / 86400));
        ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)">
          <div style="width:28px;height:28px;border-radius:6px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.15);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--accent);flex:none">
            <?= mb_strtoupper(mb_substr($np['username'],0,1)) ?>
          </div>
          <div style="flex:1;min-width:0">
            <a href="index.php?p=profile&id=<?= (int)$np['id'] ?>" style="font-weight:700;font-size:13px;color:var(--text)"><?= e($np['username']) ?></a>
          </div>
          <span style="font-size:10px;color:var(--muted)"><?= $daysAgo === 0 ? 'Today' : $daysAgo.'d ago' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Birthdays -->
  <div class="panel">
    <h3 style="margin-top:0;margin-bottom:12px">&#127881; Ghost Anniversaries <span class="muted" style="font-size:12px;font-weight:400">(Today)</span></h3>
    <?php if (empty($birthdays)): ?>
      <p class="muted" style="font-size:12px">No anniversaries today.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($birthdays as $b): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)">
          <span style="font-size:18px">&#127881;</span>
          <div style="flex:1">
            <a href="index.php?p=profile&id=<?= (int)$b['id'] ?>" style="font-weight:700;font-size:13px;color:var(--text)"><?= e($b['username']) ?></a>
          </div>
          <span style="font-size:12px;color:#e8d44d;font-weight:700"><?= (int)$b['years'] ?> year<?= $b['years']!==1?'s':'' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Quick links for new players -->
<?php if ($player['level'] <= 3): ?>
<div class="panel" style="border:1px solid rgba(25,240,199,.2);background:rgba(25,240,199,.03)">
  <h3 style="margin-top:0;color:var(--accent)">&#128161; Getting Started</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;font-size:12px">
    <a href="index.php?p=sim" style="display:block;padding:8px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:6px;color:var(--text);text-decoration:none">&#128737; Fight in the Combat Sim — earn XP and loot</a>
    <a href="index.php?p=datacore&act=lab" style="display:block;padding:8px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:6px;color:var(--text);text-decoration:none">&#127891; Train skills at the Skillsoft Lab</a>
    <a href="index.php?p=welfare" style="display:block;padding:8px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:6px;color:var(--text);text-decoration:none">&#128241; Claim your 500 daily credits from the Subsistence Terminal</a>
    <a href="index.php?p=ledger&act=bank" style="display:block;padding:8px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:6px;color:var(--text);text-decoration:none">&#127974; Deposit to the Bank for daily interest</a>
  </div>
</div>
<?php endif; ?>
