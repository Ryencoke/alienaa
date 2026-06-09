<?php /* pages/messages.php — private messages */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$with = (int)($_GET['u'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  try {
    $body   = trim($_POST['body'] ?? '');
    $toId   = (int)($_POST['to_id'] ?? 0);
    $toName = trim($_POST['to_name'] ?? '');
    if ($body === '')            throw new RuntimeException('Write a message.');
    if (mb_strlen($body) > 4000) throw new RuntimeException('Message is too long.');
    if (!$toId && $toName !== '') {
      $r = $pdo->prepare('SELECT id FROM players WHERE username = ?'); $r->execute([$toName]);
      $toId = (int)$r->fetchColumn();
    }
    if (!$toId)              throw new RuntimeException('No such recipient.');
    if ($toId === (int)$pid) throw new RuntimeException("You can't message yourself.");
    $chk = $pdo->prepare('SELECT 1 FROM players WHERE id = ?'); $chk->execute([$toId]);
    if (!$chk->fetchColumn()) throw new RuntimeException('No such recipient.');
    $pdo->prepare('INSERT INTO messages (from_id, to_id, body) VALUES (?,?,?)')->execute([$pid, $toId, $body]);
    $with = $toId; $msg = 'Message sent.';
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$flash = $msg ? '<div class="flash flash-ok">'.e($msg).'</div>' : '';

/* ---------- conversation thread ---------- */
if ($with) {
  $oq = $pdo->prepare('SELECT id, username, role, chat_color FROM players WHERE id = ?');
  $oq->execute([$with]); $other = $oq->fetch();
  if (!$other) {
    echo '<div class="panel"><h2>Messages</h2>'.$flash.'<p class="muted">No such ghost.</p></div>';
    return;
  }

  $pdo->prepare('UPDATE messages SET is_read = 1 WHERE to_id = ? AND from_id = ? AND is_read = 0')->execute([$pid, $with]);

  $tq = $pdo->prepare('SELECT m.id, m.from_id, m.body, m.created_at, p.username AS from_name, p.role, p.chat_color
                       FROM messages m JOIN players p ON p.id = m.from_id
                       WHERE (m.from_id = ? AND m.to_id = ?) OR (m.from_id = ? AND m.to_id = ?)
                       ORDER BY m.id ASC');
  $tq->execute([$pid, $with, $with, $pid]);
  $thread = $tq->fetchAll();
  $ocol = chat_color($other['role'], $other['chat_color']);
  ?>
  <div class="panel">
    <h2>Messages</h2>
    <p class="muted" style="margin-bottom:12px">
      <a href="index.php?p=messages">&larr; Inbox</a>
      &nbsp;&middot;&nbsp;
      Conversation with <a href="index.php?p=profile&id=<?= (int)$with ?>" style="color:<?= e($ocol) ?>;font-weight:bold"><?= e($other['username']) ?></a>
    </p>
    <?= $flash ?>
    <?php foreach ($thread as $m):
      $c = chat_color($m['role'], $m['chat_color']);
      $isMine = ($m['from_id'] == $pid);
    ?>
      <div class="msg-bubble<?= $isMine ? ' mine' : '' ?>">
        <div class="msg-meta">
          <b style="color:<?= e($c) ?>"><?= e($m['from_name']) ?></b>
          <span style="margin-left:8px"><?= e($m['created_at']) ?></span>
        </div>
        <div class="msg-body"><?= bbcode($m['body']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$thread): ?><p class="muted">No messages yet. Say something.</p><?php endif; ?>
    <form method="post" style="margin-top:14px">
      <input type="hidden" name="action" value="send">
      <input type="hidden" name="to_id" value="<?= (int)$with ?>">
      <div class="field">
        <span>Reply</span>
        <textarea name="body" maxlength="4000" style="min-height:80px"></textarea>
      </div>
      <button type="submit">Send</button>
    </form>
  </div>
  <?php
  return;
}

/* ---------- inbox ---------- */
$rs = $pdo->prepare('SELECT * FROM messages WHERE from_id = ? OR to_id = ? ORDER BY id DESC LIMIT 200');
$rs->execute([$pid, $pid]);
$convos = [];
foreach ($rs as $m) {
  $o = ($m['from_id'] == $pid) ? (int)$m['to_id'] : (int)$m['from_id'];
  if (!isset($convos[$o])) $convos[$o] = ['last' => $m, 'unread' => 0];
  if ($m['to_id'] == $pid && !$m['is_read']) $convos[$o]['unread']++;
}
$names = [];
if ($convos) {
  $ids = implode(',', array_map('intval', array_keys($convos)));
  foreach ($pdo->query("SELECT id, username, role, chat_color FROM players WHERE id IN ($ids)") as $r) $names[(int)$r['id']] = $r;
}
?>
<div class="panel">
  <h2>Messages</h2>
  <?= $flash ?>
  <h3>New Message</h3>
  <form method="post">
    <input type="hidden" name="action" value="send">
    <div class="field">
      <span>To (handle)</span>
      <input type="text" name="to_name" maxlength="32" style="max-width:280px">
    </div>
    <div class="field">
      <span>Message</span>
      <textarea name="body" maxlength="4000" style="min-height:80px"></textarea>
    </div>
    <button type="submit">Send</button>
  </form>
</div>

<div class="panel">
  <h3>Inbox</h3>
  <?php if ($convos): ?>
  <table>
    <tr><th>With</th><th>Last message</th><th>When</th></tr>
    <?php foreach ($convos as $oid => $c):
      $nm = $names[$oid] ?? null; if (!$nm) continue;
      $ncol = chat_color($nm['role'], $nm['chat_color']);
      $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', $c['last']['body'])), 0, 60); ?>
    <tr>
      <td>
        <a href="index.php?p=messages&u=<?= (int)$oid ?>" style="color:<?= e($ncol) ?>;font-weight:bold"><?= e($nm['username']) ?></a>
        <?php if ($c['unread']): ?>
          <span style="color:var(--neon2);font-size:12px;margin-left:4px">(<?= (int)$c['unread'] ?> new)</span>
        <?php endif; ?>
      </td>
      <td class="muted"><?= e($snippet) ?></td>
      <td class="muted"><?= e($c['last']['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><p class="muted">No messages yet.</p><?php endif; ?>
</div>
