<?php /* pages/tickets.php — Customer Service Tickets */
$pid     = $_SESSION['pid'];
$pdo     = db();
$msg     = '';
$isStaff = in_array($player['role'] ?? 'member', ['admin','manager'], true);

// Auto-create tables
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL,
    subject VARCHAR(160) NOT NULL, body TEXT NOT NULL,
    status ENUM('open','pending','waiting','closed') NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    assigned_to INT NULL, screenshot VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_player (player_id), INDEX idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_replies (
    id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, author_id INT NOT NULL,
    body TEXT NOT NULL, is_staff TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try { $pdo->exec('ALTER TABLE tickets ADD COLUMN screenshot VARCHAR(200) NULL'); } catch (Throwable $e) {}
} catch (Throwable $e) {}

$STATUSES  = ['open'=>['Open','rgba(59,207,99,.12)','rgba(59,207,99,.4)','#3bcf63'],
              'pending'=>['Pending','rgba(232,163,61,.12)','rgba(232,163,61,.4)','#e8a33d'],
              'waiting'=>['Waiting','rgba(25,240,199,.1)','rgba(25,240,199,.3)','var(--accent)'],
              'closed'=>['Closed','rgba(100,100,120,.12)','rgba(100,100,120,.3)','var(--muted)']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'create') {
      $sub  = trim($_POST['subject'] ?? '');
      $body = trim($_POST['body'] ?? '');
      if ($sub === '' || mb_strlen($sub) > 160) throw new RuntimeException('Subject must be 1–160 characters.');
      if (mb_strlen($body) < 10)                throw new RuntimeException('Please describe your issue (10+ chars).');
      if (mb_strlen($body) > 4000)              throw new RuntimeException('Description too long.');
      // Handle optional screenshot upload
      $imgPath = null;
      if (!empty($_FILES['screenshot']['tmp_name'])) {
        $ext = strtolower(pathinfo((string)$_FILES['screenshot']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) throw new RuntimeException('Screenshot must be a JPG, PNG, GIF, or WebP image.');
        if ($_FILES['screenshot']['size'] > 3 * 1024 * 1024) throw new RuntimeException('Screenshot too large (max 3 MB).');
        $ftype = function_exists('mime_content_type') ? @mime_content_type($_FILES['screenshot']['tmp_name']) : false;
        if (!$ftype || !str_starts_with($ftype, 'image/')) throw new RuntimeException('Invalid or unreadable file type.');
        $dir = __DIR__ . '/../uploads/tickets/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = 'tk_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['screenshot']['tmp_name'], $dir . $fname)) throw new RuntimeException('Screenshot upload failed.');
        $imgPath = $fname;
      }
      $pdo->prepare('INSERT INTO tickets (player_id, subject, body, screenshot) VALUES (?,?,?,?)')->execute([$pid, $sub, $body, $imgPath]);
      // Notify staff: increment admin new-ticket counter atomically so concurrent
      // submissions each add 1 instead of read-then-write clobbering the count.
      try {
        $pdo->prepare("INSERT INTO settings (k,v) VALUES ('admin_new_tickets',1) ON DUPLICATE KEY UPDATE v=v+1")->execute();
      } catch (Throwable $e) {}
      $msg = 'Ticket submitted. We will respond shortly.';

    } elseif ($act === 'reply') {
      $tid  = (int)($_POST['ticket_id'] ?? 0);
      $body = trim($_POST['body'] ?? '');
      if (mb_strlen($body) < 2) throw new RuntimeException('Write a reply first.');
      // Verify access: staff or owner
      $q = $pdo->prepare('SELECT player_id, status FROM tickets WHERE id=?'); $q->execute([$tid]); $t = $q->fetch();
      if (!$t || ((int)$t['player_id'] !== $pid && !$isStaff)) throw new RuntimeException('Not authorized.');
      // Non-staff limited to 1 reply per ticket
      if (!$isStaff) {
        $rc = $pdo->prepare('SELECT COUNT(*) FROM ticket_replies WHERE ticket_id=? AND author_id=? AND is_staff=0');
        $rc->execute([$tid, $pid]);
        if ((int)$rc->fetchColumn() > 0) throw new RuntimeException('You\'ve already replied. Staff will respond soon — additional replies are not permitted.');
      }
      $pdo->prepare('INSERT INTO ticket_replies (ticket_id, author_id, body, is_staff) VALUES (?,?,?,?)')->execute([$tid, $pid, $body, $isStaff ? 1 : 0]);
      // A reply must not silently reopen a closed ticket — leave closed tickets
      // closed (a staffer reopens explicitly via setstatus); only non-closed
      // tickets flip to pending as before. Always bump updated_at.
      if ($t['status'] === 'closed') {
        $pdo->prepare('UPDATE tickets SET updated_at=NOW() WHERE id=?')->execute([$tid]);
      } else {
        $pdo->prepare("UPDATE tickets SET status='pending', updated_at=NOW() WHERE id=?")->execute([$tid]);
      }
      $msg = 'Reply sent.';

    } elseif ($act === 'setstatus' && $isStaff) {
      $tid    = (int)($_POST['ticket_id'] ?? 0);
      $status = $_POST['status'] ?? '';
      if (!array_key_exists($status, $STATUSES)) throw new RuntimeException('Invalid status.');
      $pdo->prepare('UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?')->execute([$status, $tid]);
      $msg = 'Status updated.';

    } elseif ($act === 'close_own') {
      $tid = (int)($_POST['ticket_id'] ?? 0);
      $q = $pdo->prepare('SELECT player_id FROM tickets WHERE id=?'); $q->execute([$tid]); $t = $q->fetch();
      if (!$t || (int)$t['player_id'] !== $pid) throw new RuntimeException('Not your ticket.');
      $pdo->prepare("UPDATE tickets SET status='closed', updated_at=NOW() WHERE id=?")->execute([$tid]);
      $msg = 'Ticket closed.';
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); $msgErr = true; }
}

$view = (int)($_GET['tid'] ?? 0);

if ($view) {
  $q = $pdo->prepare('SELECT t.*, p.username FROM tickets t JOIN players p ON p.id=t.player_id WHERE t.id=?');
  $q->execute([$view]); $ticket = $q->fetch();
  if (!$ticket || ((int)$ticket['player_id'] !== $pid && !$isStaff)) { $view = 0; $ticket = null; }
}
?>

<?php if ($msg): ?>
<div class="flash <?= ($msgErr ?? false) ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($view && isset($ticket)): // ── SINGLE TICKET VIEW ──
  $replies = [];
  try { $q = $pdo->prepare('SELECT r.*, p.username, p.role FROM ticket_replies r JOIN players p ON p.id=r.author_id WHERE r.ticket_id=? ORDER BY r.id ASC'); $q->execute([$view]); $replies = $q->fetchAll(); } catch (Throwable $e) {}
  $st = $STATUSES[$ticket['status']] ?? $STATUSES['open'];
?>
<div class="panel">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <div>
      <a href="index.php?p=tickets" style="font-size:12px;color:var(--muted)">&#8592; All Tickets</a>
      <h2 style="margin:6px 0 2px"><?= e($ticket['subject']) ?></h2>
      <div style="font-size:11px;color:var(--muted)">
        Ticket #<?= (int)$ticket['id'] ?> &middot; Opened by <?= e($ticket['username']) ?> &middot; <?= e(date('M j Y', strtotime($ticket['created_at']))) ?>
      </div>
    </div>
    <span style="background:<?= $st[1] ?>;border:1px solid <?= $st[2] ?>;color:<?= $st[3] ?>;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= $st[0] ?></span>
  </div>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:14px;margin-bottom:12px">
    <div style="font-size:13px;white-space:pre-wrap"><?= e($ticket['body']) ?></div>
    <?php if (!empty($ticket['screenshot'])): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
      <div style="font-size:11px;color:var(--muted);margin-bottom:6px">&#128247; Screenshot:</div>
      <a href="uploads/tickets/<?= e($ticket['screenshot']) ?>" target="_blank">
        <img src="uploads/tickets/<?= e($ticket['screenshot']) ?>" alt="screenshot" style="max-width:100%;max-height:300px;border-radius:5px;border:1px solid var(--line)">
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($replies as $r):
  $isStaffMsg = (int)$r['is_staff'];
  $rRole   = $r['role'] ?? 'member';
  $rColor  = chat_color($rRole, '');
  $rLabel  = role_label($rRole);
  $bg   = $isStaffMsg ? 'rgba(25,240,199,.04)' : 'var(--panel2)';
  $bord = $isStaffMsg ? 'rgba(25,240,199,.2)'  : 'var(--line)';
?>
<div style="background:<?= $bg ?>;border:1px solid <?= $bord ?>;border-radius:7px;padding:12px;margin-bottom:8px">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
    <b style="font-size:13px;color:<?= $isStaffMsg ? $rColor : 'var(--text)' ?>"><?= e($r['username']) ?></b>
    <?php if ($isStaffMsg && $rLabel): ?>
      <em style="background:<?= e($rColor) ?>22;border:1px solid <?= e($rColor) ?>55;color:<?= e($rColor) ?>;padding:1px 8px;border-radius:10px;font-size:10px;font-family:'Orbitron',sans-serif;letter-spacing:.5px;font-style:italic"><?= e($rLabel) ?></em>
    <?php elseif ($isStaffMsg): ?>
      <span style="background:rgba(25,240,199,.12);border:1px solid rgba(25,240,199,.25);color:var(--accent);padding:1px 8px;border-radius:10px;font-size:10px;font-family:'Orbitron',sans-serif">STAFF</span>
    <?php endif; ?>
    <span style="font-size:11px;color:var(--muted);margin-left:auto"><?= e(date('M j Y g:ia', strtotime($r['created_at']))) ?></span>
  </div>
  <div style="font-size:13px;white-space:pre-wrap"><?= e($r['body']) ?></div>
</div>
<?php endforeach; ?>

<div class="panel">
  <?php if ($isStaff): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <?php foreach ($STATUSES as $sk=>$sv): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="setstatus">
      <input type="hidden" name="ticket_id" value="<?= (int)$view ?>">
      <input type="hidden" name="status" value="<?= $sk ?>">
      <button type="submit" style="font-size:11px;padding:4px 12px;background:<?= $sv[1] ?>;border-color:<?= $sv[2] ?>;color:<?= $sv[3] ?>;<?= $ticket['status']===$sk?'font-weight:700':'' ?>"><?= $sv[0] ?></button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <h3 style="margin-top:0">Reply</h3>
  <form method="post">
    <input type="hidden" name="action" value="reply">
    <input type="hidden" name="ticket_id" value="<?= (int)$view ?>">
    <textarea name="body" style="width:100%;min-height:90px;margin-bottom:10px" placeholder="Write your reply..."></textarea>
    <button type="submit">Send Reply</button>
  </form>
  <?php if ((int)$ticket['player_id'] === $pid && $ticket['status'] !== 'closed'): ?>
  <form method="post" style="margin-top:8px">
    <input type="hidden" name="action" value="close_own">
    <input type="hidden" name="ticket_id" value="<?= (int)$view ?>">
    <button type="submit" style="background:rgba(100,100,120,.12);border-color:rgba(100,100,120,.3);color:var(--muted);font-size:12px">Close Ticket</button>
  </form>
  <?php endif; ?>
</div>

<?php else: // ── TICKET LIST ──
  $newTicketCount = 0;
  if ($isStaff) {
    $tickets = $pdo->query("SELECT t.*, p.username FROM tickets t JOIN players p ON p.id=t.player_id ORDER BY FIELD(t.status,'open','pending','waiting','closed'), t.updated_at DESC LIMIT 100")->fetchAll();
    try {
      $nq = $pdo->query("SELECT COALESCE(v,0) FROM settings WHERE k='admin_new_tickets'");
      $newTicketCount = (int)($nq->fetchColumn() ?: 0);
      if ($newTicketCount > 0) {
        $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(['admin_new_tickets']);
      }
    } catch (Throwable $e) {}
  } else {
    $q = $pdo->prepare("SELECT * FROM tickets WHERE player_id=? ORDER BY updated_at DESC"); $q->execute([$pid]); $tickets = $q->fetchAll();
  }
?>
<?php if ($isStaff && $newTicketCount > 0): ?>
<div style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3);border-radius:7px;padding:12px 16px;font-size:13px;color:var(--neon2);margin-bottom:8px">
  &#128276; <b><?= (int)$newTicketCount ?> new ticket<?= $newTicketCount !== 1 ? 's' : '' ?></b> submitted since your last visit.
</div>
<?php endif; ?>
<?php
$tkOpen = 0;
foreach ($tickets as $tCount) { if ($tCount['status'] !== 'closed') $tkOpen++; }
?>
<style>
.tk-row{transition:background .12s}
.tk-row:hover{background:rgba(25,240,199,.03)}
.tk-chip{padding:5px 13px;border-radius:16px;font-size:11px;cursor:pointer;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s;user-select:none}
.tk-chip.on{border-color:var(--accent);background:rgba(25,240,199,.1);color:var(--accent)}
</style>
<?= scene_header('tk-canvas', '&#127915;', 'Customer Service',
      'Submit issues, report problems, or request help from the team.', 'desk', '#19f0c7',
      '<span style="font-size:11px;color:var(--muted)"><b style="font-family:\'Orbitron\',sans-serif;color:' . ($tkOpen > 0 ? 'var(--accent)' : 'var(--muted)') . '">' . $tkOpen . '</b> OPEN TICKET' . ($tkOpen !== 1 ? 'S' : '') . '</span>') ?>
<?= scene_header_js() ?>

<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
  <span class="tk-chip on" data-tk="all">All</span>
  <span class="tk-chip" data-tk="open">Open</span>
  <span class="tk-chip" data-tk="pending">Pending</span>
  <span class="tk-chip" data-tk="waiting">Waiting</span>
  <span class="tk-chip" data-tk="closed">Closed</span>
  <?php if (!$isStaff): ?>
  <button onclick="document.getElementById('new-ticket-form').style.display = document.getElementById('new-ticket-form').style.display==='none'?'block':'none'" style="font-size:12px;margin-left:auto;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)">&#43; New Ticket</button>
  <?php endif; ?>
</div>

<div id="new-ticket-form" style="display:none">
  <div class="panel">
    <h3 style="margin-top:0">Submit a Ticket</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <span>Subject</span>
        <input type="text" name="subject" maxlength="160" placeholder="Brief description of your issue">
      </div>
      <div class="field" style="margin-top:10px">
        <span>Details</span>
        <textarea name="body" style="min-height:100px" placeholder="Describe your issue in detail..."></textarea>
      </div>
      <div class="field" style="margin-top:10px">
        <span>Screenshot <span class="muted" style="font-weight:400;text-transform:none">(optional, max 3 MB)</span></span>
        <input type="file" name="screenshot" accept="image/jpeg,image/png,image/gif,image/webp" style="padding:4px 0">
      </div>
      <button type="submit" style="margin-top:10px">Submit Ticket</button>
    </form>
  </div>
</div>

<?php if (empty($tickets)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:32px">No tickets yet. Submit one above if you need help.</div>
<?php else: ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="display:grid;grid-template-columns:50px 1fr 100px 100px 90px;padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line);font-weight:700">
    <span>#</span><span>Subject</span><?php if ($isStaff): ?><span>Player</span><?php else: ?><span></span><?php endif; ?><span>Status</span><span>Updated</span>
  </div>
  <?php foreach ($tickets as $t):
    $st = $STATUSES[$t['status']] ?? $STATUSES['open'];
  ?>
  <div class="tk-row" data-status="<?= e($t['status']) ?>" style="display:grid;grid-template-columns:50px 1fr 100px 100px 90px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);align-items:center;font-size:12px">
    <span style="color:var(--muted)">#<?= (int)$t['id'] ?></span>
    <span><a href="index.php?p=tickets&tid=<?= (int)$t['id'] ?>" style="color:var(--text);font-weight:700"><?= e($t['subject']) ?></a></span>
    <?php if ($isStaff): ?>
      <span style="color:var(--muted)"><?= e($t['username'] ?? '') ?></span>
    <?php else: ?>
      <span></span>
    <?php endif; ?>
    <span><span style="background:<?= $st[1] ?>;border:1px solid <?= $st[2] ?>;color:<?= $st[3] ?>;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;white-space:nowrap;display:inline-block"><?= $st[0] ?></span></span>
    <span style="color:var(--muted);padding-left:8px"><?= e(date('M j', strtotime($t['updated_at']))) ?></span>
  </div>
  <?php endforeach; ?>
  <p id="tk-nores" class="muted" style="display:none;text-align:center;padding:20px 0">No tickets with that status.</p>
</div>
<?php endif; ?>

<script>
(function(){
  document.querySelectorAll('.tk-chip').forEach(function(chip){
    chip.addEventListener('click',function(){
      document.querySelectorAll('.tk-chip').forEach(function(ch){ch.classList.remove('on');});
      chip.classList.add('on');
      var f=chip.dataset.tk, shown=0;
      document.querySelectorAll('.tk-row').forEach(function(row){
        var hit=f==='all'||row.dataset.status===f;
        row.style.display=hit?'':'none';
        if(hit) shown++;
      });
      var nr=document.getElementById('tk-nores');
      if(nr) nr.style.display=shown?'none':'';
    });
  });
})();
</script>
<?php endif; ?>
