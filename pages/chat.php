<?php /* pages/chat.php — Multi-Room Chat */
$pid = $_SESSION['pid'];
$pdo = db();

// Ensure room col exists
try { $pdo->exec("ALTER TABLE chat_messages ADD COLUMN room VARCHAR(40) NOT NULL DEFAULT 'public' AFTER player_id"); } catch (Throwable $e) {}

// Presence — who currently has a room open, not who has recently posted in it.
// chat_api.php's `active` poll (which only chat.php's own JS calls, never the
// sitewide quickchat sidebar preview) keeps this fresh every ~4s while viewing.
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS chat_presence (
    room VARCHAR(40) NOT NULL, player_id INT NOT NULL,
    last_ping DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room, player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

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
      // Same 1-message-per-second throttle as chat_api.php — this fallback used to skip it
      $tq = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE player_id=? AND created_at > (NOW() - INTERVAL 1 SECOND)');
      $tq->execute([$pid]);
      if ((int)$tq->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO chat_messages (player_id, room, body, created_at) VALUES (?,?,?,NOW())')
            ->execute([$pid, $room, $body]);
      }
    } catch (Throwable $e) {}
  }
}

// Get recent messages for this room
$rows = [];
try {
  $rq = $pdo->prepare('SELECT c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color FROM chat_messages c JOIN players p ON p.id = c.player_id WHERE c.room=? ORDER BY c.id DESC LIMIT 150');
  $rq->execute([$room]); $rows = array_reverse($rq->fetchAll());
} catch (Throwable $e) {}

// Record this page load as presence too, so the very first render (before the
// JS poll loop ticks) already reflects this player as active in the room.
try { $pdo->prepare('INSERT INTO chat_presence (room,player_id,last_ping) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE last_ping=NOW()')->execute([$room, $pid]); } catch (Throwable $e) {}

// Active chatters — who currently has this room open (last 20s of presence pings)
$activeChatters = [];
try {
  $aq = $pdo->prepare('SELECT p.id, p.username, p.role, p.chat_color FROM chat_presence cp JOIN players p ON p.id=cp.player_id WHERE cp.room=? AND cp.last_ping >= NOW() - INTERVAL 20 SECOND ORDER BY p.username');
  $aq->execute([$room]); $activeChatters = $aq->fetchAll();
} catch (Throwable $e) {}

$onlineCount = 0;
try { $onlineCount = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn(); } catch (Throwable $e) {}

$roomIcons = ['public'=>'&#128172;','trading'=>'&#128178;','help'=>'&#10067;'];
if ($synRoomKey) $roomIcons[$synRoomKey] = '&#9760;';
?>

<?php
$roomAccents = ['public'=>'#19f0c7','trading'=>'#e8d44d','help'=>'#4d9be8'];
if ($synRoomKey) $roomAccents[$synRoomKey] = '#ff2d95';
$roomAccent = $roomAccents[$room] ?? '#19f0c7';
?>
<style>
.cht-pill{padding:6px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s}
.cht-pill.on{border-color:var(--cht-col,var(--accent));background:var(--cht-bg,rgba(25,240,199,.1));color:var(--cht-col,var(--accent))}
.chatline-full{transition:background .12s;border-radius:4px}
.chatline-full:hover{background:rgba(255,255,255,.025)}
.chat-mention{background:rgba(232,212,77,.07);box-shadow:inset 2px 0 0 #e8d44d}
.chat-new{animation:chtIn .25s ease-out}
@keyframes chtIn{0%{opacity:0;transform:translateY(4px)}100%{opacity:1;transform:none}}
@keyframes chtDot{0%,100%{opacity:1}50%{opacity:.35}}
.cht-livedot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#3bcf63;box-shadow:0 0 8px #3bcf63;animation:chtDot 1.4s ease-in-out infinite;vertical-align:middle}
#cht-count{font-size:10px;color:var(--muted);min-width:46px;text-align:right;flex:none}
#cht-count.low{color:var(--neon2);font-weight:700}
</style>

<?= scene_header('cht-canvas', $roomIcons[$room] ?? '&#128172;', e($roomLabel),
      'Live channel — transmissions visible to everyone tuned in.', 'pulse', $roomAccent,
      '<span style="font-size:11px;color:var(--muted)"><span class="cht-livedot"></span> <b style="font-family:\'Orbitron\',sans-serif;color:#3bcf63;letter-spacing:.08em">LIVE</b></span>') ?>
<?= scene_header_js() ?>

<!-- Room tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
  <?php foreach ($rooms as $rk => $rl): $rAcc = $roomAccents[$rk] ?? '#19f0c7'; ?>
  <a href="index.php?p=chat&room=<?= e($rk) ?>" class="cht-pill <?= $room===$rk ? 'on' : '' ?>"
     style="--cht-col:<?= $rAcc ?>;--cht-bg:<?= $rAcc ?>1a">
    <?= $roomIcons[$rk] ?? '&#128172;' ?> <?= e($rl) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Chat feed -->
<div class="panel" style="padding:0;overflow:hidden">
  <div id="chatroom" class="chatroom-full">
    <?php if ($rows): foreach ($rows as $r):
      $col     = chat_color($r['role'], '');
      $textCol = chat_color($r['role'], $r['chat_color']);
    ?>
      <?php $isMention = $r['uid'] != $pid && stripos($r['body'], $player['username']) !== false; ?>
      <div class="chatline-full<?= $isMention ? ' chat-mention' : '' ?>">
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
      <span id="cht-count"></span>
      <button type="submit" style="flex:none;padding:8px 16px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)">Send</button>
    </form>
    <div style="font-size:11px;color:var(--muted);margin-top:6px">
      BBCode: <code>[b]bold[/b]</code> <code>[i]italics[/i]</code> &middot; Set text color in <a href="index.php?p=account&sec=chat">Account</a>.
    </div>
  </div>
</div>

<!-- Active chatters — horizontal strip below the feed instead of a squeezed side column -->
<div class="panel" style="padding:10px 14px">
  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px">&#128172; Active Now</div>
  <div id="active-chatters" style="display:flex;flex-wrap:wrap;gap:6px 16px">
    <?php if (empty($activeChatters)): ?>
    <div style="font-size:11px;color:var(--muted);font-style:italic">No one active yet</div>
    <?php else: foreach ($activeChatters as $acu):
      $acColor = chat_color($acu['role'], '');
      $acTitle = role_label($acu['role']);
    ?>
    <div style="font-size:12px;white-space:nowrap">
      <span style="display:inline-block;width:6px;height:6px;background:var(--accent);border-radius:50%;margin-right:4px;vertical-align:middle;box-shadow:0 0 4px rgba(25,240,199,.7)"></span>
      <a href="index.php?p=profile&id=<?= (int)$acu['id'] ?>" style="color:<?= e($acColor) ?><?= $acTitle ? ';font-weight:700' : '' ?>"><?= e($acu['username']) ?></a>
      <?php if ($acTitle): ?> <?= role_tag($acu['role'], 'font-size:9px') ?><?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
(function(){
  var room=document.getElementById('chatroom'),
      form=document.getElementById('chatform-full'),
      inp =document.getElementById('chatinput-full'),
      roomKey=document.getElementById('chat-room-key').value;
  if(!room||!form) return;

  var MYNAME=<?= json_encode($player['username']) ?>;
  var prevCount=-1;
  function render(msgs){
    if(!msgs||!msgs.length) return;
    var fresh=prevCount>=0?Math.max(0,msgs.length-prevCount):0;
    prevCount=msgs.length;
    room.innerHTML='';
    msgs.forEach(function(m,mi){
      var line=document.createElement('div'); line.className='chatline-full';
      if(m.username!==MYNAME&&(m.html||'').toLowerCase().indexOf(MYNAME.toLowerCase())!==-1) line.classList.add('chat-mention');
      if(fresh>0&&mi>=msgs.length-fresh) line.classList.add('chat-new');
      var t=document.createElement('span'); t.className='chattime-full'; t.textContent=m.time;
      var body=document.createElement('div'); body.className='chatline-body';
      var who=document.createElement('a'); who.href='index.php?p=profile&id='+m.id;
      who.style.color=m.name_color||'#c9d1e0'; who.style.fontWeight='700';
      who.textContent=m.username; // textContent, not innerHTML — don't trust the username shape
      var sep=document.createElement('span'); sep.style.color='var(--muted)'; sep.textContent=': ';
      var txt=document.createElement('span'); txt.style.color=m.color||'#c9d1e0'; txt.innerHTML=m.html; // server-sanitized bbcode
      body.appendChild(who); body.appendChild(sep); body.appendChild(txt);
      line.appendChild(t); line.appendChild(body);
      room.appendChild(line);
    });
    room.scrollTop=room.scrollHeight;
  }

  // remaining-characters hint (shows under 60 left)
  var cnt=document.getElementById('cht-count');
  if(cnt&&inp){
    inp.addEventListener('input',function(){
      var left=240-inp.value.length;
      cnt.textContent=left<=60?left+' left':'';
      cnt.classList.toggle('low',left<=20);
    });
  }

  function renderActive(active){
    var box=document.getElementById('active-chatters'); if(!box) return;
    if(!active||!active.length){ box.innerHTML='<div style="font-size:11px;color:var(--muted);font-style:italic">No one active yet</div>'; return; }
    box.innerHTML='';
    active.forEach(function(a){
      var d=document.createElement('div'); d.style.cssText='font-size:12px;white-space:nowrap';
      var dot=document.createElement('span');
      dot.style.cssText='display:inline-block;width:6px;height:6px;background:var(--accent);border-radius:50%;margin-right:4px;vertical-align:middle;box-shadow:0 0 4px rgba(25,240,199,.7)';
      var link=document.createElement('a');
      link.href='index.php?p=profile&id='+encodeURIComponent(a.id);
      link.style.color=a.color||'#c9d1e0';
      if(a.title) link.style.fontWeight='700';
      link.textContent=a.name||''; // textContent, not innerHTML — usernames are untrusted
      d.appendChild(dot); d.appendChild(link);
      if(a.title){
        var tag=document.createElement('em');
        tag.style.cssText='font-style:italic;color:'+(a.color||'#c9d1e0')+';font-size:9px;margin-left:4px';
        tag.textContent=a.title; // textContent — server value, but untrusted is cheap to keep safe
        d.appendChild(tag);
      }
      box.appendChild(d);
    });
  }

  function load(){
    // Stop polling once the chat page is swapped out by AJAX nav
    if(!document.body.contains(room)){ if(window.__chatInterval){clearInterval(window.__chatInterval);window.__chatInterval=null;} return; }
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
