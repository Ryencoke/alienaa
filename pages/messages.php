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
  <div class="panel" style="padding:0;overflow:hidden">
    <div style="padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px">
      <a href="index.php?p=messages" style="color:var(--muted);font-size:18px;line-height:1;flex:none">&larr;</a>
      <div style="display:flex;align-items:center;gap:10px;flex:1">
        <div style="width:36px;height:36px;border-radius:50%;background:var(--panel2);border:2px solid <?= e($ocol) ?>;display:flex;align-items:center;justify-content:center;font-weight:700;color:<?= e($ocol) ?>;font-size:14px;flex:none"><?= strtoupper(mb_substr(e($other['username']),0,1)) ?></div>
        <a href="index.php?p=profile&id=<?= (int)$with ?>" style="color:<?= e($ocol) ?>;font-weight:700;font-size:14px"><?= e($other['username']) ?></a>
      </div>
    </div>
    <?= $flash ?>
    <div id="thread-scroll" style="max-height:480px;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:8px;background:#05050d">
      <?php if (!$thread): ?>
        <div style="text-align:center;padding:30px;color:var(--muted)">No messages yet. Say something.</div>
      <?php endif; ?>
      <?php foreach ($thread as $m):
        $c = chat_color($m['role'], $m['chat_color']);
        $isMine = ($m['from_id'] == $pid);
        $ts = strtotime($m['created_at']);
        $timeStr = (time() - $ts < 86400) ? date('g:i a', $ts) : date('M j, g:i a', $ts);
      ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMine?'flex-end':'flex-start' ?>;gap:2px">
          <div style="max-width:72%;background:<?= $isMine?'rgba(25,240,199,.1)':'var(--panel2)' ?>;border:1px solid <?= $isMine?'rgba(25,240,199,.25)':'var(--line)' ?>;border-radius:<?= $isMine?'12px 12px 3px 12px':'12px 12px 12px 3px' ?>;padding:9px 13px;font-size:13px;line-height:1.55;word-break:break-word">
            <?php if (!$isMine): ?>
              <div style="font-size:10px;font-weight:700;color:<?= e($c) ?>;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px"><?= e($m['from_name']) ?></div>
            <?php endif; ?>
            <?= bbcode($m['body']) ?>
          </div>
          <div style="font-size:10px;color:var(--muted)"><?= e($timeStr) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="border-top:1px solid var(--line);padding:10px 14px;background:var(--panel2)">
      <form method="post" style="margin:0;display:flex;gap:8px;align-items:flex-end">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="to_id" value="<?= (int)$with ?>">
        <textarea name="body" maxlength="4000" placeholder="Write a message..." style="flex:1;min-height:42px;max-height:160px;resize:vertical"></textarea>
        <button type="submit" style="flex:none;align-self:flex-end;padding:10px 16px">Send</button>
      </form>
      <div style="font-size:10px;color:var(--muted);margin-top:4px">Ctrl+Enter to send</div>
    </div>
  </div>
  <script>
  (function(){
    var el=document.getElementById('thread-scroll'); if(el) el.scrollTop=el.scrollHeight;
    var ta=document.querySelector('textarea[name=body]');
    if(ta) ta.addEventListener('keydown',function(e){
      if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){ e.preventDefault(); ta.closest('form').requestSubmit(); }
    });
  })();
  </script>
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
      <div class="ac-wrap" style="max-width:280px">
        <input type="text" name="to_name" id="msgToName" maxlength="32" autocomplete="off" data-no-counter>
        <div class="ac-list" id="msgAcList" style="display:none"></div>
      </div>
    </div>
    <div class="field">
      <span>Message</span>
      <textarea name="body" maxlength="4000" style="min-height:80px"></textarea>
    </div>
    <button type="submit">Send</button>
  </form>
</div>

<script>
(function(){
  var inp=document.getElementById('msgToName'), list=document.getElementById('msgAcList');
  if(!inp||!list) return;
  var cur=-1, items=[];
  function show(names){
    items=names; cur=-1;
    if(!names.length){ list.style.display='none'; return; }
    list.innerHTML=''; names.forEach(function(n,i){
      var d=document.createElement('div'); d.className='ac-item'; d.textContent=n;
      d.addEventListener('mousedown',function(e){ e.preventDefault(); inp.value=n; list.style.display='none'; });
      list.appendChild(d);
    }); list.style.display='block';
  }
  inp.addEventListener('input',function(){
    var q=inp.value.trim(); if(q.length<1){ list.style.display='none'; return; }
    fetch('players_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'})
      .then(function(r){return r.json();}).then(show).catch(function(){});
  });
  inp.addEventListener('keydown',function(e){
    if(!items.length) return;
    var rows=list.querySelectorAll('.ac-item');
    if(e.key==='ArrowDown'){ e.preventDefault(); cur=Math.min(cur+1,rows.length-1); rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);}); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); cur=Math.max(cur-1,-1); rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);}); }
    else if(e.key==='Enter'&&cur>=0){ e.preventDefault(); inp.value=items[cur]; list.style.display='none'; }
    else if(e.key==='Escape'){ list.style.display='none'; }
  });
  document.addEventListener('click',function(e){ if(!inp.contains(e.target)&&!list.contains(e.target)) list.style.display='none'; });
})();
</script>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 16px;border-bottom:1px solid var(--line)">
    <h3 style="margin:0;font-size:13px">Conversations</h3>
  </div>
  <?php if ($convos): ?>
  <div class="convo-list">
    <?php foreach ($convos as $oid => $c):
      $nm = $names[$oid] ?? null; if (!$nm) continue;
      $ncol = chat_color($nm['role'], $nm['chat_color']);
      $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', $c['last']['body'])), 0, 55);
      $ts = strtotime($c['last']['created_at']);
      $timeStr = (time()-$ts < 86400) ? date('g:i a',$ts) : date('M j',$ts);
      $isFromMe = ($c['last']['from_id'] == $pid);
    ?>
    <a href="index.php?p=messages&u=<?= (int)$oid ?>" class="convo-row">
      <div class="convo-av" style="border-color:<?= e($ncol) ?>;color:<?= e($ncol) ?>"><?= strtoupper(mb_substr($nm['username'],0,1)) ?></div>
      <div class="convo-info">
        <div class="convo-name" style="color:<?= e($ncol) ?>"><?= e($nm['username']) ?></div>
        <div class="convo-preview"><?= $isFromMe ? 'You: ' : '' ?><?= e($snippet) ?>...</div>
      </div>
      <div class="convo-right">
        <div class="convo-time"><?= $timeStr ?></div>
        <?php if ($c['unread']): ?>
          <div class="convo-badge"><?= (int)$c['unread'] ?></div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div style="text-align:center;padding:30px;color:var(--muted)">No messages yet. Send a message to a player from their profile.</div>
  <?php endif; ?>
</div>
