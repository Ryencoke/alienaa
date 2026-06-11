<?php /* pages/chat.php — Multi-Room Chat */
$pid = $_SESSION['pid'];
$pdo = db();

// Ensure room col exists
try { $pdo->exec("ALTER TABLE chat_messages ADD COLUMN room VARCHAR(40) NOT NULL DEFAULT 'public' AFTER player_id"); } catch (Throwable $e) {}

// Determine room
$roomRaw = $_GET['room'] ?? 'public';
$rooms = ['public'=>'Public Channel','trading'=>'Trading Chat','help'=>'Game Help'];
// Syndicate room
$mySynId = 0;
try {
  $sq = $pdo->prepare('SELECT syndicate_id FROM syndicate_members WHERE player_id=?');
  $sq->execute([$pid]); $mySynId = (int)($sq->fetchColumn() ?: 0);
} catch (Throwable $e) {}
$synRoomKey = $mySynId ? 'syn_'.$mySynId : null;
if ($synRoomKey) {
  $sname = '';
  try { $snq = $pdo->prepare('SELECT name FROM syndicates WHERE id=?'); $snq->execute([$mySynId]); $sname = (string)$snq->fetchColumn(); } catch (Throwable $e) {}
  $rooms[$synRoomKey] = $sname ? $sname.' Chat' : 'Syndicate Chat';
}
// Validate room
$room = array_key_exists($roomRaw, $rooms) ? $roomRaw : 'public';
$roomLabel = $rooms[$room];
if ($room === $synRoomKey && !$mySynId) $room = 'public';

// Handle POST (non-AJAX fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'say') {
  $body = trim($_POST['body'] ?? '');
  if ($body !== '') {
    try {
      if (mb_strlen($body) > 240) $body = mb_substr($body, 0, 240);
      $pdo->prepare('INSERT INTO chat_messages (player_id, room, body, created_at) VALUES (?,?,?,NOW())')
          ->execute([$pid, $room, $body]);
    } catch (Throwable $e) {}
  }
}

// Get recent messages for this room
$rows = [];
try {
  $rq = $pdo->prepare('SELECT c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color FROM chat_messages c JOIN players p ON p.id = c.player_id WHERE c.room=? ORDER BY c.id DESC LIMIT 150');
  $rq->execute([$room]); $rows = array_reverse($rq->fetchAll());
} catch (Throwable $e) {}

// Active chatters in this room (last 5 min)
$activeChatters = [];
try {
  $aq = $pdo->prepare('SELECT DISTINCT p.id, p.username, p.role, p.chat_color FROM chat_messages c JOIN players p ON p.id=c.player_id WHERE c.room=? AND c.created_at >= NOW() - INTERVAL 5 MINUTE ORDER BY p.username');
  $aq->execute([$room]); $activeChatters = $aq->fetchAll();
} catch (Throwable $e) {}

$onlineCount = 0;
try { $onlineCount = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn(); } catch (Throwable $e) {}

$roomIcons = ['public'=>'&#128172;','trading'=>'&#128178;','help'=>'&#10067;'];
if ($synRoomKey) $roomIcons[$synRoomKey] = '&#9760;';
?>

<div class="panel" style="margin-bottom:10px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0;font-size:16px"><?= $roomIcons[$room] ?? '&#128172;' ?> <?= e($roomLabel) ?></h2>
    </div>
    <div style="display:flex;gap:8px;align-items:center;font-size:12px;color:var(--muted)">
      <span style="width:8px;height:8px;border-radius:50%;background:var(--accent);display:inline-block;box-shadow:0 0 6px rgba(25,240,199,.7)"></span>Live
    </div>
  </div>
</div>

<!-- Room tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
  <?php foreach ($rooms as $rk => $rl): ?>
  <a href="index.php?p=chat&room=<?= e($rk) ?>"
     style="padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $room===$rk?'var(--accent)':'var(--line)' ?>;background:<?= $room===$rk?'rgba(25,240,199,.1)':'var(--panel2)' ?>;color:<?= $room===$rk?'var(--accent)':'var(--muted)' ?>">
    <?= $roomIcons[$rk] ?? '&#128172;' ?> <?= e($rl) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Main chat + active chatters layout -->
<div style="display:grid;grid-template-columns:1fr 160px;gap:10px;align-items:start">

  <!-- Chat feed -->
  <div class="panel" style="padding:0;overflow:hidden">
    <div id="chatroom" class="chatroom-full">
      <?php if ($rows): foreach ($rows as $r):
        $col     = chat_color($r['role'], '');
        $textCol = chat_color($r['role'], $r['chat_color']);
      ?>
        <div class="chatline-full">
          <span class="chattime-full"><?= e(date('H:i', strtotime($r['created_at']))) ?></span>
          <div class="chatline-body">
            <a href="index.php?p=profile&id=<?= (int)$r['uid'] ?>" style="color:<?= e($col) ?>;font-weight:700"><?= e($r['username']) ?></a><span style="color:var(--muted)">:</span>
            <span style="color:<?= e($textCol) ?>"><?= bbcode($r['body']) ?></span>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
          <div style="font-size:32px;margin-bottom:8px">&#128172;</div>
          <div>Dead air. Say something.</div>
        </div>
      <?php endif; ?>
    </div>
    <div style="border-top:1px solid var(--line);padding:10px 14px;background:var(--panel2)">
      <form id="chatform-full" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="action" value="say">
        <input type="hidden" id="chat-room-key" value="<?= e($room) ?>">
        <input type="text" id="chatinput-full" name="body" maxlength="240" autocomplete="off" placeholder="Transmit..." style="flex:1">
        <button type="submit" style="flex:none;padding:8px 16px">Send</button>
      </form>
      <div style="font-size:11px;color:var(--muted);margin-top:6px">
        BBCode: <code>[b]bold[/b]</code> <code>[i]italics[/i]</code> &middot; Set text color in <a href="index.php?p=account&sec=chat">Account</a>.
      </div>
    </div>
  </div>

  <!-- Active chatters -->
  <div>
    <div class="panel" style="padding:10px 12px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px">&#128172; Active Now</div>
      <div id="active-chatters">
        <?php if (empty($activeChatters)): ?>
        <div style="font-size:11px;color:var(--muted);font-style:italic">No one active yet</div>
        <?php else: foreach ($activeChatters as $acu):
          $acColor = chat_color($acu['role'], '');
        ?>
        <div style="font-size:12px;margin-bottom:4px">
          <span style="display:inline-block;width:6px;height:6px;background:var(--accent);border-radius:50%;margin-right:4px;vertical-align:middle;box-shadow:0 0 4px rgba(25,240,199,.7)"></span>
          <a href="index.php?p=profile&id=<?= (int)$acu['id'] ?>" style="color:<?= e($acColor) ?>"><?= e($acu['username']) ?></a>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
(function(){
  var room=document.getElementById('chatroom'),
      form=document.getElementById('chatform-full'),
      inp =document.getElementById('chatinput-full'),
      roomKey=document.getElementById('chat-room-key').value;
  if(!room||!form) return;

  // Replace quickchat sidebar with Active Now list while in chat
  var qcp=document.getElementById('quickchat-panel');
  var qcpOrig=qcp?qcp.innerHTML:null;
  if(qcp){
    qcp.innerHTML='<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px">&#128172; Active Now</div><div id="sidebar-active-now"><div style="font-size:11px;color:var(--muted);font-style:italic">Loading...</div></div>';
  }
  document.addEventListener('sprawl:swapped', function restore(){
    if(qcp&&qcpOrig) qcp.innerHTML=qcpOrig;
    document.removeEventListener('sprawl:swapped', restore);
  });

  function render(msgs){
    if(!msgs||!msgs.length) return;
    room.innerHTML='';
    msgs.forEach(function(m){
      var line=document.createElement('div'); line.className='chatline-full';
      var t=document.createElement('span'); t.className='chattime-full'; t.textContent=m.time;
      var body=document.createElement('div'); body.className='chatline-body';
      body.innerHTML='<a href="index.php?p=profile&id='+m.id+'" style="color:'+(m.name_color||'#c9d1e0')+';font-weight:700">'+m.username+'</a><span style="color:var(--muted)">:</span> <span style="color:'+(m.color||'#c9d1e0')+'">'+m.html+'</span>';
      line.appendChild(t); line.appendChild(body);
      room.appendChild(line);
    });
    room.scrollTop=room.scrollHeight;
  }

  function renderActive(active){
    var ids=['active-chatters','sidebar-active-now'];
    ids.forEach(function(id){
      var box=document.getElementById(id); if(!box) return;
      if(!active||!active.length){ box.innerHTML='<div style="font-size:11px;color:var(--muted);font-style:italic">No one active yet</div>'; return; }
      box.innerHTML='';
      active.forEach(function(a){
        var d=document.createElement('div'); d.style.cssText='font-size:12px;margin-bottom:4px';
        d.innerHTML='<span style="display:inline-block;width:6px;height:6px;background:var(--accent);border-radius:50%;margin-right:4px;vertical-align:middle;box-shadow:0 0 4px rgba(25,240,199,.7)"></span><a href="index.php?p=profile&id='+a.id+'" style="color:'+(a.color||'#c9d1e0')+'">'+(a.name||'')+'</a>';
        box.appendChild(d);
      });
    });
  }

  function load(){
    fetch('chat_api.php?action=list&n=150&room='+encodeURIComponent(roomKey),{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){ if(d&&d.messages) render(d.messages); })
      .catch(function(){});
    fetch('chat_api.php?action=active&room='+encodeURIComponent(roomKey),{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){ if(d&&d.active) renderActive(d.active); })
      .catch(function(){});
  }

  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    var t=inp.value.trim(); if(!t) return;
    var fd=new FormData(); fd.append('action','say'); fd.append('body',t); fd.append('room',roomKey);
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
