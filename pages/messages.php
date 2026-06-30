<?php /* pages/messages.php — private messages */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$with = (int)($_GET['u'] ?? 0) ?: (int)($_GET['to'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  try {
    $body   = trim($_POST['body'] ?? '');
    $toId   = (int)($_POST['to_id'] ?? 0);
    $toName = trim($_POST['to_name'] ?? '');
    if ($body === '')            throw new RuntimeException('Write a message.');
    if (mb_strlen($body) > 4000) throw new RuntimeException('Message is too long.');
    if (!$toId && $toName !== '') {
      // Exact username match takes priority — usernames may legally be all-digits,
      // so checking that first avoids routing to an unrelated player whose numeric
      // ID happens to match a numeric handle.
      $r = $pdo->prepare('SELECT id FROM players WHERE username = ?'); $r->execute([$toName]);
      $toId = (int)$r->fetchColumn();
      if (!$toId && ctype_digit($toName)) {
        $r = $pdo->prepare('SELECT id FROM players WHERE id = ?'); $r->execute([(int)$toName]);
        $toId = (int)$r->fetchColumn();
      }
    }
    if (!$toId)              throw new RuntimeException('No such recipient.');
    if ($toId === (int)$pid) throw new RuntimeException("You can't message yourself.");
    $chk = $pdo->prepare('SELECT 1 FROM players WHERE id = ?'); $chk->execute([$toId]);
    if (!$chk->fetchColumn()) throw new RuntimeException('No such recipient.');
    $pdo->prepare('INSERT INTO messages (from_id, to_id, body) VALUES (?,?,?)')->execute([$pid, $toId, $body]);
    $with = $toId; $msg = 'Message sent.';
  } catch (Throwable $ex) { $msg = $ex->getMessage(); $msgErr = true; }
}

$flash = $msg ? '<div class="flash '.(!empty($msgErr) ? 'flash-err' : 'flash-ok').'">'.e($msg).'</div>' : '';
?>
<script>
/* Comms FX — bound once; transmit overlay on document.body survives AJAX swaps. */
(function(){
  if(window._pmFxBound) return;
  window._pmFxBound=true;
  var css=document.createElement('style');
  css.textContent=
    '#pmfx{position:fixed;left:50%;top:38%;transform:translate(-50%,-50%);z-index:10001;pointer-events:none;'
    +'font-size:30px;opacity:0;filter:drop-shadow(0 0 10px rgba(25,240,199,.6))}'
    +'@keyframes pmFly{0%{opacity:0;transform:translate(-50%,-50%) translateX(-40px) rotate(10deg) scale(.7)}'
    +'25%{opacity:1}100%{opacity:0;transform:translate(-50%,-50%) translateX(120px) translateY(-50px) rotate(-12deg) scale(1)}}';
  document.head.appendChild(css);
  var ac=null;
  function tone(freq,dur,vol,slide){
    try{
      ac=ac||new (window.AudioContext||window.webkitAudioContext)();
      var o=ac.createOscillator(),g=ac.createGain();
      o.type='sine'; o.frequency.value=freq;
      if(slide) o.frequency.exponentialRampToValueAtTime(slide,ac.currentTime+dur);
      g.gain.value=vol||.04;
      g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+dur);
      o.connect(g); g.connect(ac.destination);
      o.start(); o.stop(ac.currentTime+dur);
    }catch(e){}
  }
  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute||f.getAttribute('data-pmfx')!=='send') return;
    var ta=f.querySelector('textarea[name=body]');
    if(ta&&!ta.value.trim()) return; // empty messages get a server error, no whoosh
    var old=document.getElementById('pmfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='pmfx';
    o.textContent='\u{1F4E8}';
    o.style.animation='pmFly .8s ease-out forwards';
    document.body.appendChild(o);
    tone(500,.25,.04,1400);
    setTimeout(function(){ if(o.parentNode) o.remove(); },900);
  },true);
})();
</script>
<?php

/* ---------- conversation thread ---------- */
if ($with) {
  $oq = $pdo->prepare('SELECT id, username, role, chat_color FROM players WHERE id = ?');
  $oq->execute([$with]); $other = $oq->fetch();
  if (!$other) {
    echo '<div class="panel"><h2>Messages</h2>'.$flash.'<p class="muted">No such ghost.</p></div>';
    return;
  }

  $pdo->prepare('UPDATE messages SET is_read = 1 WHERE to_id = ? AND from_id = ? AND is_read = 0')->execute([$pid, $with]);
  // Also clear the seeded "New message" notifications for this thread, or the
  // sidebar badge keeps double-counting PMs you've already read.
  try {
    $pdo->prepare("UPDATE player_notifications SET is_read = 1
                   WHERE player_id = ? AND ref_type = 'message'
                     AND ref_id IN (SELECT id FROM messages WHERE to_id = ? AND from_id = ?)")
        ->execute([$pid, $pid, $with]);
  } catch (Throwable $e) {}

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
      <form method="post" style="margin:0" data-pmfx="send">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="to_id" value="<?= (int)$with ?>">
        <div style="display:flex;gap:8px;align-items:flex-end">
          <textarea name="body" maxlength="4000" placeholder="Write a message..." data-no-counter style="flex:1;min-height:60px;max-height:200px;resize:vertical;width:0;min-width:0"></textarea>
          <button type="submit" style="flex:none;align-self:flex-end;padding:10px 16px">Send</button>
        </div>
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
$msgFriends = [];
try { $fq = $pdo->prepare('SELECT p.id,p.username,p.role,p.chat_color FROM friends f JOIN players p ON p.id=f.friend_id WHERE f.player_id=? ORDER BY p.username LIMIT 30'); $fq->execute([$pid]); $msgFriends = $fq->fetchAll(); } catch (Throwable $e) {}
$msgRows = [];
try {
  $rs = $pdo->prepare('SELECT * FROM messages WHERE from_id = ? OR to_id = ? ORDER BY id DESC LIMIT 200');
  $rs->execute([$pid, $pid]);
  $msgRows = $rs->fetchAll();
} catch (Throwable $e) {}
$convos = [];
foreach ($msgRows as $m) {
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
<style>
#pm-canvas{display:block;width:100%;height:96px;border-radius:9px 9px 0 0}
#pm-head h2{text-shadow:0 0 14px rgba(25,240,199,.35)}
@keyframes pmBadge{0%,100%{transform:scale(1)}50%{transform:scale(1.18)}}
.convo-badge{animation:pmBadge 1.6s ease-in-out infinite}
#pm-filter{background:var(--panel2);border:1px solid var(--line);border-radius:16px;color:var(--text);padding:5px 12px;font-size:12px;width:170px;transition:border-color .15s}
#pm-filter:focus{border-color:var(--accent);outline:none;box-shadow:0 0 8px rgba(25,240,199,.15)}
</style>

<div class="panel" id="pm-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="pm-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#128225; Comms</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Private transmissions, ghost to ghost. Encrypted. Mostly.</p>
    </div>
  </div>
  <div style="padding:0 14px"><?= $flash ?></div>
</div>

<div class="panel">
  <h3 style="margin-top:0">New Message</h3>
  <?php if (!empty($msgFriends)): ?>
  <div style="margin-bottom:10px">
    <div style="font-size:11px;color:var(--muted);margin-bottom:5px">Friends</div>
    <div style="display:flex;flex-wrap:wrap;gap:5px">
      <?php foreach ($msgFriends as $mf): $mfc = chat_color($mf['role'], $mf['chat_color']); ?>
      <button type="button" onclick="document.getElementById('msgToName').value='<?= e(addslashes($mf['username'])) ?>'" style="font-size:11px;padding:3px 10px;color:<?= e($mfc) ?>;border-color:<?= e($mfc) ?>;background:rgba(0,0,0,.2)"><?= e($mf['username']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <form method="post" data-pmfx="send">
    <input type="hidden" name="action" value="send">
    <div class="field">
      <span>To (handle or player ID)</span>
      <div class="ac-wrap" style="max-width:280px">
        <input type="text" name="to_name" id="msgToName" autocomplete="off" data-no-counter>
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
  <div style="padding:12px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <h3 style="margin:0;font-size:13px">Conversations</h3>
    <input type="text" id="pm-filter" placeholder="&#128269; filter..." autocomplete="off" data-no-counter>
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

<script>
(function(){
'use strict';
/* ── Comms header: waveform, dish, packet stream ── */
var pc=document.getElementById('pm-canvas');
if(pc){
  var c=pc.getContext('2d');
  var PW=560, PH=96;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  pc.width=PW*dpr; pc.height=PH*dpr;
  c.scale(dpr,dpr);
  var packets=[];
  var rings=[];

  function pLoop(t){
    if(!document.body.contains(pc)) return;
    requestAnimationFrame(pLoop);
    c.clearRect(0,0,PW,PH);
    var bg=c.createLinearGradient(0,0,0,PH);
    bg.addColorStop(0,'#090a11'); bg.addColorStop(1,'#0d0e17');
    c.fillStyle=bg; c.fillRect(0,0,PW,PH);

    // live waveform across the middle
    c.strokeStyle='rgba(25,240,199,.4)'; c.lineWidth=1.4;
    c.beginPath();
    for(var x=0;x<=PW;x+=4){
      var y=PH*0.55
        + Math.sin(x/26+t/300)*6
        + Math.sin(x/9+t/160)*2.5
        + Math.sin(x/61+t/700)*4;
      x?c.lineTo(x,y):c.moveTo(x,y);
    }
    c.stroke();
    c.lineWidth=1;

    // satellite dish (right) with signal rings
    var dx2=PW-66, dy2=30;
    c.strokeStyle='rgba(255,255,255,.3)';
    c.beginPath(); c.arc(dx2,dy2,13,Math.PI*0.25,Math.PI*1.25); c.stroke();
    c.beginPath(); c.moveTo(dx2,dy2); c.lineTo(dx2-9,dy2+9); c.stroke();
    c.beginPath(); c.moveTo(dx2-4,dy2+14); c.lineTo(dx2-14,dy2+14); c.stroke();
    if(Math.random()<.02) rings.push({r:5,a:.5});
    for(var ri=rings.length-1;ri>=0;ri--){
      var R=rings[ri];
      R.r+=.45; R.a-=.005;
      if(R.a<=0){ rings.splice(ri,1); continue; }
      c.strokeStyle='rgba(25,240,199,'+R.a+')';
      c.beginPath(); c.arc(dx2+6,dy2-6,R.r,Math.PI*1.4,Math.PI*1.95); c.stroke();
    }

    // packet stream dots riding the waveform
    if(Math.random()<.06) packets.push({x:-6});
    for(var pi=packets.length-1;pi>=0;pi--){
      var P=packets[pi];
      P.x+=2.4;
      if(P.x>PW+6){ packets.splice(pi,1); continue; }
      var py=PH*0.55+Math.sin(P.x/26+t/300)*6+Math.sin(P.x/9+t/160)*2.5+Math.sin(P.x/61+t/700)*4;
      c.fillStyle='rgba(232,212,77,.8)';
      c.shadowColor='#e8d44d'; c.shadowBlur=6;
      c.fillRect(P.x-1.5,py-1.5,3,3);
      c.shadowBlur=0;
    }
  }
  requestAnimationFrame(pLoop);
}

/* ── conversation filter ── */
var filt=document.getElementById('pm-filter');
if(filt){
  filt.addEventListener('input',function(){
    var q=filt.value.trim().toLowerCase();
    document.querySelectorAll('.convo-row').forEach(function(row){
      row.style.display=!q||row.textContent.toLowerCase().indexOf(q)!==-1?'':'none';
    });
  });
}
})();
</script>
