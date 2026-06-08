<?php /* pages/chat.php — full Public Channel (Town Hall) */
$pid = $_SESSION['pid'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'say') {
  $body = trim($_POST['body'] ?? '');
  if ($body !== '') {
    if (mb_strlen($body) > 240) $body = mb_substr($body, 0, 240);
    $pdo->prepare('INSERT INTO chat_messages (player_id, body, created_at) VALUES (?,?,NOW())')
        ->execute([$pid, $body]);
  }
}

$rows = array_reverse($pdo->query(
  'SELECT c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color
   FROM chat_messages c JOIN players p ON p.id = c.player_id
   ORDER BY c.id DESC LIMIT 100')->fetchAll());
?>
<div class="panel">
  <h2>Town Hall</h2>
  <div id="chatroom" class="chatroom">
    <?php if ($rows): foreach ($rows as $r): $col = chat_color($r['role'], $r['chat_color']); ?>
      <div class="chatline">
        <span class="chattime"><?= e(date('H:i:s', strtotime($r['created_at']))) ?></span>
        <a style="color:<?= e($col) ?>;font-weight:bold" href="index.php?p=profile&id=<?= (int)$r['uid'] ?>"><?= e($r['username']) ?></a>:
        <span style="color:<?= e($col) ?>"><?= bbcode($r['body']) ?></span>
      </div>
    <?php endforeach; else: ?>
      <p class="muted">Dead air. Say something.</p>
    <?php endif; ?>
  </div>

  <form method="post" class="chatsay">
    <input type="hidden" name="action" value="say">
    <input type="text" name="body" maxlength="240" autocomplete="off" placeholder="say something...">
    <button type="submit">SAY</button>
  </form>
  <p class="muted" style="font-size:11px;margin-bottom:0">
    Formatting: <code>[b]bold[/b]</code> <code>[i]italics[/i]</code> <code>[u]underline[/u]</code> <code>[s]strike[/s]</code>
    &middot; set your name color in <a href="index.php?p=account">Account</a>.
  </p>
</div>
<script>
(function(){
  var room=document.getElementById('chatroom');
  if(!room) return;
  function render(msgs){
    room.innerHTML='';
    msgs.forEach(function(m){
      var line=document.createElement('div'); line.className='chatline';
      var t=document.createElement('span'); t.className='chattime'; t.textContent=m.time;
      var who=document.createElement('a'); who.href='index.php?p=profile&id='+m.id; who.textContent=' '+m.username+': '; who.style.color=m.color; who.style.fontWeight='bold';
      var body=document.createElement('span'); body.style.color=m.color; body.innerHTML=m.html; // server-sanitized
      line.appendChild(t); line.appendChild(who); line.appendChild(body);
      room.appendChild(line);
    });
    room.scrollTop=room.scrollHeight;
  }
  function load(){
    fetch('chat_api.php?action=list&n=100',{credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(d){ if(d&&d.messages) render(d.messages); })
      .catch(function(){});
  }
  room.scrollTop=room.scrollHeight;
  load(); setInterval(load,4000);
})();
</script>
