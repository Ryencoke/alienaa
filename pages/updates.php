<?php /* pages/updates.php — Game Updates (managers post, everyone votes) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;
$isManager = (($player['role'] ?? 'member') === 'manager');

try { $pdo->exec("ALTER TABLE updates ADD COLUMN credit_player_id INT NULL"); } catch (Throwable $e) {}

const UPDATES_PER_PAGE = 25;
$pg = max(1, (int)($_GET['pg'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'post') {
      if (!$isManager) throw new RuntimeException('Only Managers can post updates.');
      $body   = trim($_POST['body'] ?? '');
      $credit = trim($_POST['credit'] ?? '');
      if ($body === '')               throw new RuntimeException('Write the update text.');
      if (mb_strlen($body) > 4000)    throw new RuntimeException('Update is too long.');
      if (mb_strlen($credit) > 64)    $credit = mb_substr($credit, 0, 64);
      $creditPlayerId = null;
      if ($credit !== '') {
        $cpq = $pdo->prepare('SELECT id, username FROM players WHERE username=?'); $cpq->execute([$credit]);
        $cp = $cpq->fetch();
        if (!$cp) throw new RuntimeException('"' . htmlspecialchars($credit, ENT_QUOTES) . '" is not a ghost in the Sprawl — pick a handle from the search.');
        $creditPlayerId = (int)$cp['id']; $credit = $cp['username']; // canonical case
      }
      $pdo->prepare('INSERT INTO updates (author_id, body, credit, credit_player_id) VALUES (?,?,?,?)')
          ->execute([$pid, $body, $credit, $creditPlayerId]);
      $msg = 'Update posted.';
      $pg = 1;
    }

    elseif ($action === 'vote') {
      $uid = (int)($_POST['update_id'] ?? 0);
      $dir = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;
      $chk = $pdo->prepare('SELECT 1 FROM updates WHERE id = ?'); $chk->execute([$uid]);
      if ($chk->fetchColumn()) {
        $cur = $pdo->prepare('SELECT value FROM update_votes WHERE update_id = ? AND player_id = ?');
        $cur->execute([$uid, $pid]);
        $curVal = (int)($cur->fetchColumn() ?: 0);
        $newVal = ($curVal === $dir) ? 0 : $dir;  // re-click same dir = cancel back to 0
        if ($newVal === 0) {
          $pdo->prepare('DELETE FROM update_votes WHERE update_id = ? AND player_id = ?')->execute([$uid, $pid]);
        } else {
          $pdo->prepare('INSERT INTO update_votes (update_id, player_id, value) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$uid, $pid, $newVal]);
        }
      }
    }

  } catch (Throwable $ex) {
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM updates')->fetchColumn();
$pages = max(1, (int)ceil($total / UPDATES_PER_PAGE));
$pg    = min($pg, $pages);
$off   = ($pg - 1) * UPDATES_PER_PAGE;

$stmt = $pdo->prepare('SELECT u.id, u.body, u.credit, u.credit_player_id, u.created_at,
                       p.id AS posted_id, p.username AS posted_by, p.role AS posted_role, p.chat_color AS posted_color,
                       cp.id AS credit_pid, cp.username AS credit_username,
                       (SELECT COALESCE(SUM(value),0) FROM update_votes v WHERE v.update_id = u.id) AS score,
                       (SELECT COALESCE(value,0) FROM update_votes v WHERE v.update_id = u.id AND v.player_id = ?) AS my_vote
                     FROM updates u LEFT JOIN players p ON p.id = u.author_id
                                     LEFT JOIN players cp ON cp.id = u.credit_player_id
                     ORDER BY u.created_at DESC, u.id DESC
                     LIMIT ' . (int)UPDATES_PER_PAGE . ' OFFSET ' . (int)$off);
$stmt->execute([$pid]);
$rows = $stmt->fetchAll();

$latestTs = $rows ? strtotime($rows[0]['created_at']) : null;

// Short relative time ("2h ago", "3d ago")
function upd_rel(int $ts): string {
  $d = time() - $ts;
  if ($d < 60)        return 'just now';
  if ($d < 3600)      return floor($d / 60) . 'm ago';
  if ($d < 86400)     return floor($d / 3600) . 'h ago';
  if ($d < 86400 * 30) return floor($d / 86400) . 'd ago';
  return date('M Y', $ts);
}

function uvote($uid, $dir, $glyph, $myVote) {
  $active = ($myVote == ($dir === 'up' ? 1 : -1)) ? ' on' : '';
  $cls    = $dir === 'up' ? 'up' : 'down';
  return '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="vote">'
       . '<input type="hidden" name="update_id" value="' . (int)$uid . '">'
       . '<input type="hidden" name="dir" value="' . $dir . '"><button class="upd-vbtn ' . $cls . $active . '" title="' . ($dir === 'up' ? 'Helpful' : 'Not helpful') . '">' . $glyph . '</button></form>';
}
?>
<style>
#upd-canvas{display:block;width:100%;height:96px;border-radius:9px 9px 0 0}
#upd-head h2{text-shadow:0 0 14px rgba(25,240,199,.35)}
@keyframes updLive{0%,100%{opacity:1}50%{opacity:.25}}
.upd-livedot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#3bcf63;box-shadow:0 0 8px #3bcf63;animation:updLive 1.4s ease-in-out infinite;vertical-align:middle}
#upd-filter{background:var(--panel2);border:1px solid var(--line);border-radius:16px;color:var(--text);padding:6px 13px;font-size:12px;width:200px;transition:border-color .15s}
#upd-filter:focus{border-color:var(--accent);outline:none;box-shadow:0 0 8px rgba(25,240,199,.15)}
/* timeline */
.upd-tl{position:relative;padding:4px 0 4px 18px}
.upd-tl::before{content:'';position:absolute;left:23px;top:10px;bottom:10px;width:2px;background:linear-gradient(180deg,var(--accent),var(--line) 30%,var(--line))}
.upd-item{position:relative;display:flex;gap:14px;margin-bottom:14px;animation:updIn .3s ease-out backwards}
@keyframes updIn{0%{opacity:0;transform:translateY(8px)}100%{opacity:1;transform:none}}
.upd-node{flex:none;width:12px;display:flex;justify-content:center;padding-top:18px}
.upd-dot{width:11px;height:11px;border-radius:50%;background:var(--panel2);border:2px solid var(--muted);z-index:1}
.upd-item.latest .upd-dot{background:var(--accent);border-color:var(--accent);box-shadow:0 0 10px rgba(25,240,199,.6)}
.upd-item.fresh .upd-dot{border-color:var(--accent)}
.upd-card{flex:1;min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:13px 15px;transition:border-color .15s,transform .12s,box-shadow .15s}
.upd-item:hover .upd-card{transform:translateY(-1px);border-color:rgba(25,240,199,.25)}
.upd-item.latest .upd-card{border-color:rgba(25,240,199,.4);box-shadow:0 0 16px rgba(25,240,199,.07)}
.upd-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.05)}
.upd-when{font-family:'Orbitron',sans-serif;font-size:11px;font-weight:700;color:var(--text)}
.upd-rel{font-size:10px;color:var(--muted)}
.upd-tag{font-size:8px;font-weight:900;letter-spacing:.1em;padding:1px 7px;border-radius:8px}
.upd-tag.latest{color:#0a0a12;background:var(--accent);box-shadow:0 0 8px rgba(25,240,199,.4)}
.upd-tag.new{color:var(--accent);border:1px solid rgba(25,240,199,.4);background:rgba(25,240,199,.07)}
.upd-body{font-size:13px;line-height:1.6;color:var(--text);word-wrap:break-word}
.upd-foot{font-size:11px;color:var(--muted);margin-top:10px;display:flex;gap:14px;flex-wrap:wrap;align-items:center}
/* votes */
.upd-votes{margin-left:auto;display:flex;align-items:center;gap:5px;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:16px;padding:2px 6px}
.upd-vbtn{background:none;border:none;cursor:pointer;font-size:11px;padding:3px 6px;color:var(--muted);border-radius:10px;transition:color .12s,background .12s,transform .08s;line-height:1}
.upd-vbtn:hover{color:var(--text);background:rgba(255,255,255,.06)}
.upd-vbtn:active{transform:scale(1.25)}
.upd-vbtn.up.on{color:#3bcf63;background:rgba(59,207,99,.12)}
.upd-vbtn.down.on{color:var(--neon2);background:rgba(255,45,149,.12)}
.upd-score{font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;min-width:20px;text-align:center}
/* composer */
.upd-composer{background:rgba(25,240,199,.03);border:1px solid rgba(25,240,199,.18);border-radius:9px;padding:14px}
.upd-composer textarea{min-height:88px}
</style>

<div class="panel" id="upd-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="upd-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#128240; Game Updates</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Patch notes from the people who keep the Sprawl running.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:10px;text-align:right;font-size:10px;color:var(--muted)">
      <div><span class="upd-livedot"></span> <span style="letter-spacing:.1em;font-weight:700;color:#3bcf63">LIVE FEED</span></div>
      <div style="margin-top:3px"><b style="font-family:'Orbitron',sans-serif;color:var(--text)"><?= number_format($total) ?></b> bulletins<?= $latestTs ? ' · latest ' . upd_rel($latestTs) : '' ?></div>
    </div>
  </div>
  <div style="padding:10px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?php if ($msg): ?><div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>" style="margin:0;flex:1;min-width:200px"><?= e($msg) ?></div><?php endif; ?>
    <input type="text" id="upd-filter" placeholder="&#128269; filter this page..." autocomplete="off" data-no-counter style="margin-left:auto">
  </div>
</div>

<?php if ($isManager): ?>
<div class="panel" style="padding:14px">
  <div class="upd-composer">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="font-size:15px">&#128226;</span>
      <b style="font-size:13px;color:var(--accent)">Broadcast an Update</b>
      <span class="muted" style="font-size:10px;margin-left:auto">[b] [i] [u] [s] supported · Manager only</span>
    </div>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="post">
      <div class="field">
        <textarea name="body" maxlength="4000" placeholder="What changed in the Sprawl today?"></textarea>
      </div>
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">
        <div class="field" style="max-width:260px;flex:1;margin:0">
          <span>Thanks to <span class="muted" style="text-transform:none;letter-spacing:0">(optional handle)</span></span>
          <div class="ac-wrap" style="position:relative">
            <input type="text" id="updCredit" name="credit" placeholder="Ghost's handle" autocomplete="off" maxlength="32" data-no-counter style="width:100%">
            <div class="ac-list" id="updCreditAcList" style="display:none"></div>
          </div>
        </div>
        <button type="submit" style="background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent);flex:none">&#128240; Publish</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var inp=document.getElementById('updCredit'), list=document.getElementById('updCreditAcList');
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
<?php endif; ?>

<div class="panel" style="padding:10px 14px 6px">
  <?php if ($rows): ?>
  <div class="upd-tl" id="upd-list">
    <?php foreach ($rows as $i => $r):
      $uts     = strtotime($r['created_at']);
      $isLatest = ($pg === 1 && $i === 0);
      $isFresh  = (time() - $uts) < 172800; // < 48h
      $score    = (int)$r['score'];
      $scoreCol = $score > 0 ? '#3bcf63' : ($score < 0 ? 'var(--neon2)' : 'var(--muted)');
      $puColor  = chat_color($r['posted_role'] ?? '', '');
    ?>
    <div class="upd-item<?= $isLatest ? ' latest' : '' ?><?= $isFresh ? ' fresh' : '' ?>" style="animation-delay:<?= min(10, $i) * 45 ?>ms" data-search="<?= e(mb_strtolower($r['body'] . ' ' . ($r['posted_by'] ?? '') . ' ' . $r['credit'])) ?>">
      <div class="upd-node"><span class="upd-dot"></span></div>
      <div class="upd-card">
        <div class="upd-meta">
          <span class="upd-when"><?= date('M j, Y', $uts) ?></span>
          <span class="upd-rel"><?= date('g:i a', $uts) ?> · <?= upd_rel($uts) ?></span>
          <?php if ($isLatest): ?><span class="upd-tag latest">LATEST PATCH</span>
          <?php elseif ($isFresh): ?><span class="upd-tag new">NEW</span><?php endif; ?>
          <div class="upd-votes">
            <?= uvote($r['id'], 'up', '&#9650;', (int)$r['my_vote']) ?>
            <span class="upd-score" style="color:<?= $scoreCol ?>"><?= $score > 0 ? '+' : '' ?><?= $score ?></span>
            <?= uvote($r['id'], 'down', '&#9660;', (int)$r['my_vote']) ?>
          </div>
        </div>
        <div class="upd-body"><?= nl2br(bbcode($r['body'])) ?></div>
        <div class="upd-foot">
          <span>&#128100; Posted by
            <?php if ($r['posted_id']): ?>
              <a href="index.php?p=profile&id=<?= (int)$r['posted_id'] ?>" style="color:<?= e($puColor) ?>;font-weight:700;text-decoration:none"><?= e($r['posted_by'] ?? 'Staff') ?></a>
              <?= role_tag($r['posted_role'] ?? '', 'font-size:10px;margin-left:3px') ?>
            <?php else: ?>
              <b style="color:var(--accent)">Staff</b>
            <?php endif; ?>
          </span>
          <?php if (trim($r['credit']) !== ''): ?><span style="font-style:italic">&#128172; Thanks to
            <?php if ($r['credit_pid']): ?><a href="index.php?p=profile&id=<?= (int)$r['credit_pid'] ?>" style="color:var(--text);font-weight:700;text-decoration:none"><?= e($r['credit_username']) ?></a>
            <?php else: ?><b style="color:var(--text)"><?= e($r['credit']) ?></b><?php endif; ?>
          </span><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p id="upd-nores" class="muted" style="display:none;text-align:center;padding:20px 0">Nothing on this page matches that filter.</p>
  <?php else: ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">
      <div style="font-size:28px;margin-bottom:8px">&#128240;</div>
      No updates yet. The Sprawl is suspiciously quiet.
    </div>
  <?php endif; ?>
</div>

<?= pager('index.php?p=updates', $pg, $pages) ?>

<script>
(function(){
'use strict';

/* ── Broadcast tower header ── */
var uc=document.getElementById('upd-canvas');
if(uc){
  var c=uc.getContext('2d');
  var UW=560, UH=96;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  uc.width=UW*dpr; uc.height=UH*dpr;
  c.scale(dpr,dpr);
  function cssVar(n,fb){
    try{ var v=getComputedStyle(document.documentElement).getPropertyValue(n).trim(); return v||fb; }catch(e){ return fb; }
  }
  var ACCENT=cssVar('--accent','#19f0c7');
  // terminal feed lines (decorative)
  var FEED=['> patch deployed to sector 7','> hotfix: vault drift corrected','> economy rebalance live','> new gear catalog synced','> daemon odds recalibrated','> grid stability nominal'];
  var lines=[];
  for(var i=0;i<5;i++) lines.push({y:14+i*16,x:UW-200,txt:FEED[Math.floor(Math.random()*FEED.length)],a:.05+Math.random()*.12,v:.12+Math.random()*.18});
  var rings=[];

  function uLoop(t){
    if(!document.body.contains(uc)) return;
    requestAnimationFrame(uLoop);
    c.clearRect(0,0,UW,UH);
    var bg=c.createLinearGradient(0,0,0,UH);
    bg.addColorStop(0,'#090911'); bg.addColorStop(1,'#0d0d18');
    c.fillStyle=bg; c.fillRect(0,0,UW,UH);

    // broadcast mast (left-center)
    var mx=240, my=UH-12;
    c.strokeStyle='rgba(255,255,255,.25)'; c.lineWidth=1.4;
    c.beginPath();
    c.moveTo(mx-14,my); c.lineTo(mx,my-58); c.lineTo(mx+14,my);
    c.moveTo(mx-9,my-19); c.lineTo(mx+9,my-19);
    c.moveTo(mx-5,my-38); c.lineTo(mx+5,my-38);
    c.stroke();
    // beacon
    var bp=.5+.5*Math.sin(t/360);
    c.fillStyle=ACCENT; c.shadowColor=ACCENT; c.shadowBlur=10*bp;
    c.beginPath(); c.arc(mx,my-60,2.6+bp,0,Math.PI*2); c.fill();
    c.shadowBlur=0;
    // expanding signal rings
    if(Math.random()<.018) rings.push({r:4,a:.5});
    for(var ri=rings.length-1;ri>=0;ri--){
      var R=rings[ri];
      R.r+=.5; R.a-=.004;
      if(R.a<=0){ rings.splice(ri,1); continue; }
      c.strokeStyle='rgba(25,240,199,'+R.a+')';
      c.beginPath(); c.arc(mx,my-60,R.r,0,Math.PI*2); c.stroke();
    }

    // drifting terminal feed (right)
    c.font='9px monospace'; c.textAlign='left';
    for(var li=0;li<lines.length;li++){
      var L=lines[li];
      L.x-=L.v;
      if(L.x<UW-330){ L.x=UW-150+Math.random()*60; L.txt=FEED[Math.floor(Math.random()*FEED.length)]; }
      c.fillStyle='rgba(25,240,199,'+L.a+')';
      c.fillText(L.txt,L.x,L.y);
    }

    // scanline
    var sy=(t/45)%UH;
    c.fillStyle='rgba(25,240,199,.045)'; c.fillRect(0,sy,UW,1.4);
  }
  requestAnimationFrame(uLoop);
}

/* ── page filter ── */
var filt=document.getElementById('upd-filter'), list=document.getElementById('upd-list');
if(filt&&list){
  filt.addEventListener('input',function(){
    var q=filt.value.trim().toLowerCase();
    var shown=0;
    list.querySelectorAll('.upd-item').forEach(function(it){
      var hit=!q||(it.dataset.search||'').indexOf(q)!==-1;
      it.style.display=hit?'':'none';
      if(hit) shown++;
    });
    var nr=document.getElementById('upd-nores');
    if(nr) nr.style.display=shown?'none':'';
  });
}
})();
</script>
