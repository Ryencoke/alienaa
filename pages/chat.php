<?php /* pages/chat.php — Public Channel: full chat view */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'say') {
  $body = trim($_POST['body'] ?? '');
  if ($body !== '') {
    if (mb_strlen($body) > 240) $body = mb_substr($body, 0, 240);
    $pdo->prepare('INSERT INTO chat_messages (player_id, body, created_at) VALUES (?,?,NOW())')
        ->execute([$pid, $body]);
  }
}

// Get recent messages
$rows = array_reverse($pdo->query(
  'SELECT c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color, p.sub_until
   FROM chat_messages c JOIN players p ON p.id = c.player_id
   ORDER BY c.id DESC LIMIT 150')->fetchAll());

// Online count
$onlineCount = 0;
try {
  $onlineCount = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
} catch (Throwable $e) {}
?>

<div class="panel" style="margin-bottom:10px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0;font-size:16px">&#128172; Public Channel</h2>
      <p class="muted" style="margin:2px 0 0;font-size:12px">Open frequency — <?= $onlineCount ?> runner<?= $onlineCount !== 1 ? 's' : '' ?> online</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;font-size:12px;color:var(--muted)">
      <span style="width:8px;height:8px;border-radius:50%;background:var(--accent);display:inline-block;box-shadow:0 0 6px rgba(25,240,199,.7)"></span>
      Live
    </div>
  </div>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <!-- Chat feed -->
  <div id="chatroom" class="chatroom-full">
    <?php if ($rows): foreach ($rows as $r):
      $col = chat_color($r['role'], $r['chat_color']);
      $isSub = !empty($r['sub_until']) && $r['sub_until'] >= date('Y-m-d');
      $isStaffR = in_array($r['role'] ?? 'member', ['chatmod','moderator','admin','manager'], true);
    ?>
      <div class="chatline-full">
        <span class="chattime-full"><?= e(date('H:i', strtotime($r['created_at']))) ?></span>
        <div class="chatline-body">
          <?php if ($isSub): ?><span style="color:#e8d44d;font-size:10px;vertical-align:middle">&#9733;</span><?php endif; ?>
          <?php if ($isStaffR): ?><span style="font-size:10px;color:var(--neon2);vertical-align:middle;margin-right:2px">[<?= e(role_label($r['role'])) ?>]</span><?php endif; ?>
          <a href="index.php?p=profile&id=<?= (int)$r['uid'] ?>" style="color:<?= e($col) ?>;font-weight:700"><?= e($r['username']) ?></a><span style="color:var(--muted)">:</span>
          <span style="color:var(--text)"><?= bbcode($r['body']) ?></span>
        </div>
      </div>
    <?php endforeach; else: ?>
      <div style="text-align:center;padding:40px;color:var(--muted)">
        <div style="font-size:32px;margin-bottom:8px">&#128172;</div>
        <div>Dead air. Say something.</div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Send bar -->
  <div style="border-top:1px solid var(--line);padding:10px 14px;background:var(--panel2)">
    <form id="chatform-full" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="action" value="say">
      <input type="text" id="chatinput-full" name="body" maxlength="240" autocomplete="off"
             placeholder="Transmit to the channel..." style="flex:1">
      <button type="submit" style="flex:none;padding:8px 20px">Send</button>
    </form>
    <div style="font-size:11px;color:var(--muted);margin-top:6px">
      BBCode: <code>[b]bold[/b]</code> <code>[i]italics[/i]</code> <code>[u]under[/u]</code> <code>[s]strike[/s]</code>
      &middot; Set name color in <a href="index.php?p=account">Account</a>.
    </div>
  </div>
</div>

<script>
(function(){
  var room=document.getElementById('chatroom'),
      form=document.getElementById('chatform-full'),
      inp =document.getElementById('chatinput-full');
  if(!room||!form) return;

  function render(msgs){
    if(!msgs||!msgs.length) return;
    room.innerHTML='';
    msgs.forEach(function(m){
      var line=document.createElement('div'); line.className='chatline-full';
      var t=document.createElement('span'); t.className='chattime-full'; t.textContent=m.time;
      var body=document.createElement('div'); body.className='chatline-body';
      var stars='';
      if(m.sub) stars+='<span style="color:#e8d44d;font-size:10px;vertical-align:middle">&#9733;</span>';
      body.innerHTML=stars+'<a href="index.php?p=profile&id='+m.id+'" style="color:'+m.color+';font-weight:700">'+m.username+'</a><span style="color:var(--muted)">:</span> <span style="color:var(--text)">'+m.html+'</span>';
      line.appendChild(t); line.appendChild(body);
      room.appendChild(line);
    });
    room.scrollTop=room.scrollHeight;
  }

  function load(){
    fetch('chat_api.php?action=list&n=150',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){ if(d&&d.messages) render(d.messages); })
      .catch(function(){});
  }

  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    var t=inp.value.trim(); if(!t) return;
    var fd=new FormData(); fd.append('action','say'); fd.append('body',t);
    fetch('chat_api.php',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(){ inp.value=''; load(); })
      .catch(function(){});
  });

  room.scrollTop=room.scrollHeight;
  load();
  if(window.__chatInterval) clearInterval(window.__chatInterval);
  window.__chatInterval=setInterval(load,4000);
})();
</script>
