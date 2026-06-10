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
    assigned_to INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_player (player_id), INDEX idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_replies (
    id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, author_id INT NOT NULL,
    body TEXT NOT NULL, is_staff TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
      $pdo->prepare('INSERT INTO tickets (player_id, subject, body) VALUES (?,?,?)')->execute([$pid, $sub, $body]);
      $msg = 'Ticket submitted. We will respond shortly.';

    } elseif ($act === 'reply') {
      $tid  = (int)($_POST['ticket_id'] ?? 0);
      $body = trim($_POST['body'] ?? '');
      if (mb_strlen($body) < 2) throw new RuntimeException('Write a reply first.');
      // Verify access: staff or owner
      $q = $pdo->prepare('SELECT player_id, status FROM tickets WHERE id=?'); $q->execute([$tid]); $t = $q->fetch();
      if (!$t || ((int)$t['player_id'] !== $pid && !$isStaff)) throw new RuntimeException('Not authorized.');
      $pdo->prepare('INSERT INTO ticket_replies (ticket_id, author_id, body, is_staff) VALUES (?,?,?,?)')->execute([$tid, $pid, $body, $isStaff ? 1 : 0]);
      $pdo->prepare("UPDATE tickets SET status='pending', updated_at=NOW() WHERE id=?")->execute([$tid]);
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
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$view = (int)($_GET['tid'] ?? 0);

if ($view) {
  $q = $pdo->prepare('SELECT t.*, p.username FROM tickets t JOIN players p ON p.id=t.player_id WHERE t.id=?');
  $q->execute([$view]); $ticket = $q->fetch();
  if (!$ticket || ((int)$ticket['player_id'] !== $pid && !$isStaff)) { $view = 0; $ticket = null; }
}
?>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:8px"><?= e($msg) ?></div>
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
  </div>
</div>

<?php foreach ($replies as $r):
  $isStaffMsg = (int)$r['is_staff'];
  $bg = $isStaffMsg ? 'rgba(25,240,199,.04)' : 'var(--panel2)';
  $bord = $isStaffMsg ? 'rgba(25,240,199,.2)' : 'var(--line)';
?>
<div style="background:<?= $bg ?>;border:1px solid <?= $bord ?>;border-radius:7px;padding:12px;margin-bottom:8px">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
    <b style="font-size:13px;color:<?= $isStaffMsg ? 'var(--accent)' : 'var(--text)' ?>"><?= e($r['username']) ?></b>
    <?php if ($isStaffMsg): ?><span style="background:rgba(25,240,199,.12);border:1px solid rgba(25,240,199,.25);color:var(--accent);padding:1px 8px;border-radius:10px;font-size:10px;font-family:'Orbitron',sans-serif">STAFF</span><?php endif; ?>
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
    <div style="display:flex;gap:8px;align-items:center">
      <button type="submit">Send Reply</button>
      <?php if ((int)$ticket['player_id'] === $pid && $ticket['status'] !== 'closed'): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="close_own">
        <input type="hidden" name="ticket_id" value="<?= (int)$view ?>">
        <button type="submit" style="background:rgba(100,100,120,.12);border-color:rgba(100,100,120,.3);color:var(--muted);font-size:12px">Close Ticket</button>
      </form>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php else: // ── TICKET LIST ──
  if ($isStaff) {
    $tickets = $pdo->query("SELECT t.*, p.username FROM tickets t JOIN players p ON p.id=t.player_id ORDER BY FIELD(t.status,'open','pending','waiting','closed'), t.updated_at DESC LIMIT 100")->fetchAll();
  } else {
    $q = $pdo->prepare("SELECT * FROM tickets WHERE player_id=? ORDER BY updated_at DESC"); $q->execute([$pid]); $tickets = $q->fetchAll();
  }
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),var(--neon2),transparent)"></div>
  <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0 0 2px">&#127381; Customer Service</h2>
      <p class="muted" style="margin:0;font-size:12px">Submit issues, report problems, or request help from the team.</p>
    </div>
    <button onclick="document.getElementById('new-ticket-form').style.display = document.getElementById('new-ticket-form').style.display==='none'?'block':'none'" style="font-size:12px">&#43; New Ticket</button>
  </div>
</div>

<div id="new-ticket-form" style="display:none">
  <div class="panel">
    <h3 style="margin-top:0">Submit a Ticket</h3>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <span>Subject</span>
        <input type="text" name="subject" maxlength="160" placeholder="Brief description of your issue">
      </div>
      <div class="field" style="margin-top:10px">
        <span>Details</span>
        <textarea name="body" style="min-height:100px" placeholder="Describe your issue in detail..."></textarea>
      </div>
      <button type="submit" style="margin-top:10px">Submit Ticket</button>
    </form>
  </div>
</div>

<?php if (empty($tickets)): ?>
<div class="panel" style="text-align:center;color:var(--muted);padding:32px">No tickets yet. Submit one above if you need help.</div>
<?php else: ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="display:grid;grid-template-columns:60px 1fr 100px 100px 110px;padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line);font-weight:700">
    <span>#</span><span>Subject</span><?php if ($isStaff): ?><span>Player</span><?php else: ?><span></span><?php endif; ?><span>Status</span><span>Updated</span>
  </div>
  <?php foreach ($tickets as $t):
    $st = $STATUSES[$t['status']] ?? $STATUSES['open'];
  ?>
  <div style="display:grid;grid-template-columns:60px 1fr 100px 100px 110px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);align-items:center;font-size:12px">
    <span style="color:var(--muted)">#<?= (int)$t['id'] ?></span>
    <span><a href="index.php?p=tickets&tid=<?= (int)$t['id'] ?>" style="color:var(--text);font-weight:700"><?= e($t['subject']) ?></a></span>
    <?php if ($isStaff): ?>
      <span style="color:var(--muted)"><?= e($t['username'] ?? '') ?></span>
    <?php else: ?>
      <span></span>
    <?php endif; ?>
    <span style="background:<?= $st[1] ?>;border:1px solid <?= $st[2] ?>;color:<?= $st[3] ?>;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-align:center"><?= $st[0] ?></span>
    <span style="color:var(--muted)"><?= e(date('M j', strtotime($t['updated_at']))) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
