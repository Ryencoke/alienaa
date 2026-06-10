<?php
require 'config.php';
require 'lib.php';
csrf_guard();
$p   = $_GET['p']   ?? 'home';
$act = $_GET['act'] ?? '';
$pageTitles = [
  'home'=>'Hideout','stash'=>'Inventory','city'=>'The Sprawl','bazaar'=>'Bazaar',
  'boards'=>'Boards','messages'=>'Messages','friends'=>'Friends','account'=>'Account',
  'updates'=>'Updates','admin'=>'Admin Panel','profile'=>'Profile','chat'=>'Public Channel',
  'daemon'=>'Daemon Casino','cityhall'=>'City Hall','datacore'=>'Skillsoft Lab',
  'foundry'=>'Foundry','transit'=>'Transit Hub','sim'=>'Combat Simulator','ledger'=>'Bank',
  'registry'=>'ID Registry','exchange'=>'The Exchange','lounge'=>'The Lounge',
  'blacksmith'=>'Blacksmith','generalstore'=>'General Store','library'=>'Library',
  'awards'=>'Grid Rankings','welfare'=>'Subsistence Terminal','synth'=>'Synthesis Den',
  'auction'=>'Black Market','bonds'=>'Credit Bonds','stockex'=>'Stock Exchange',
  'guilds'=>'Syndicates','apartments'=>'Apartments','tickets'=>'Customer Service',
  'pvp'=>'Combat Arena','jail'=>'Confinement Grid','training'=>'Combat Training',
  'mining'=>'Ore Mining','weaponcraft'=>'Fabrication Lab',
];
$pageTitle = $pageTitles[$p] ?? ucfirst($p);
// Stop impersonation
if (isset($_GET['stop_impersonate'])) {
  if (!empty($_SESSION['real_pid'])) { $_SESSION['pid'] = (int)$_SESSION['real_pid']; unset($_SESSION['real_pid']); unset($_SESSION['role_override']); }
  if (!headers_sent()) header('Location: index.php?p=admin'); exit;
}
$player = current_player();
if ($player) db()->prepare('UPDATE players SET last_seen = NOW() WHERE id = ?')->execute([$player['id']]);
// Impersonation: check if a real admin is impersonating this account
$isImpersonating = !empty($_SESSION['real_pid']);
$realPlayer = null;
if ($isImpersonating) {
  try { $rq = db()->prepare('SELECT * FROM players WHERE id=?'); $rq->execute([$_SESSION['real_pid']]); $realPlayer = $rq->fetch() ?: null; } catch (Throwable $e) {}
  if (!$realPlayer || !in_array($realPlayer['role'] ?? '', ['admin','manager'], true)) {
    $_SESSION['pid'] = (int)$_SESSION['real_pid']; unset($_SESSION['real_pid']); unset($_SESSION['role_override']); $isImpersonating = false; $player = current_player();
  }
}
// Role override for admins viewing as another role
if ($isImpersonating && !empty($_SESSION['role_override']) && $player) {
  $player['role'] = $_SESSION['role_override'];
}
// Jail check
$jailRecord = null;
if ($player) {
  try {
    $jq = db()->prepare("SELECT * FROM jail_records WHERE player_id=? AND status='active' AND release_at > NOW() LIMIT 1");
    $jq->execute([$player['id']]); $jailRecord = $jq->fetch() ?: null;
  } catch (Throwable $e) {}
  // Auto-release expired sentences
  try { db()->prepare("UPDATE jail_records SET status='released' WHERE player_id=? AND release_at<=NOW() AND status='active'")->execute([$player['id']]); } catch (Throwable $e) {}
}
$isStaff = $player && in_array($player['role'] ?? 'member', ['chatmod','moderator','admin','manager'], true);
// Sidebar stat preferences
$sbBars = null;
if ($player) {
  try {
    $sbQ = db()->prepare('SELECT v FROM settings WHERE k=?');
    $sbQ->execute(['sidebar_bars:' . $player['id']]);
    $sbRaw = $sbQ->fetchColumn();
    if ($sbRaw !== false && $sbRaw !== '') $sbBars = array_filter(explode(',', $sbRaw));
  } catch (Throwable $e) {}
}
// Daily reset — lazy-eval on first page load of a new Mountain Time day
if ($player && !$isImpersonating) {
  try {
    $__mtDate = (new DateTime('now', new DateTimeZone('America/Denver')))->format('Y-m-d');
    $__drKey  = 'daily_reset:' . $player['id'];
    $__drQ    = db()->prepare('SELECT v FROM settings WHERE k=?'); $__drQ->execute([$__drKey]);
    if ($__drQ->fetchColumn() !== $__mtDate) {
      db()->prepare('UPDATE players SET integrity = integrity_max, cycles = cycles_max, signal = signal_max WHERE id = ?')->execute([$player['id']]);
      // Skillsoft decay: per-skill drain based on current point level
      db()->prepare('UPDATE player_skills SET points = CASE
        WHEN points > 0 AND points < 500  THEN GREATEST(0, points - 1)
        WHEN points >= 500 AND points < 1000 THEN GREATEST(0, points - 2)
        ELSE points END
        WHERE player_id = ?')->execute([$player['id']]);
      db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$__drKey, $__mtDate]);
      $player = current_player();
    }
  } catch (Throwable $e) {}
}
// Per-page staff note key: includes numeric 'id' param so profile/thread notes are isolated
$noteKey = 'staff_note:' . $p . (isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? ':' . (int)$_GET['id'] : '');
if ($isStaff && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__staffnote'])) {
  $sn = trim($_POST['staffnote'] ?? ''); if (mb_strlen($sn) > 2000) $sn = mb_substr($sn, 0, 2000);
  try { db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$noteKey, $sn]); } catch (Throwable $e) {}
}

function bar($label, $val, $max, $key = '') {
  $pct = $max > 0 ? min(100, round($val / $max * 100)) : 0;
  $df  = $key ? ' data-fill="'.$key.'"' : '';
  $de  = $key ? ' data-em="'.$key.'"' : '';
  $db  = $key ? ' data-bar="'.$key.'"' : '';
  static $barColors = ['integrity'=>'#3bcf63','xp'=>'var(--accent)','signal'=>'var(--neon2)','cycles'=>'#e8a33d'];
  $bg = isset($barColors[$key]) ? 'background:'.$barColors[$key].';' : '';
  echo '<div class="meter"'.$db.'>'
     . '<div class="meter-head"><span>'.e($label).'</span>'
     . '<em'.$de.'>'.number_format($val).' / '.number_format($max).'</em></div>'
     . '<div class="track"><div class="fill"'.$df.' style="'.$bg.'width:'.$pct.'%"></div></div>'
     . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0a0a12">
<meta name="color-scheme" content="dark">
<meta name="description" content="Sprawl-9 — cyberpunk browser MMO">
<title>Sprawl-9 &mdash; <?= e($pageTitle) ?></title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__.'/style.css') ?: '1' ?>">
<?php if ($player) echo theme_css($player['theme'] ?? 'neon', $player['accent_color'] ?? ''); ?>
<?php if ($player):
  // Load per-player font preference
  $playerFont = 'default';
  try { $fq = db()->prepare('SELECT v FROM settings WHERE k=?'); $fq->execute(['font:'.$player['id']]); $fv = $fq->fetchColumn(); if ($fv !== false) $playerFont = $fv; } catch (Throwable $e) {}
  $fontImports = ['rajdhani'=>'Rajdhani:wght@400;600;700','share_tech_mono'=>'Share+Tech+Mono','inter'=>'Inter:wght@300;400;600;700','jura'=>'Jura:wght@400;600;700','ibm_plex_mono'=>'IBM+Plex+Mono:wght@300;400;600'];
  $fontStacks  = ['default'=>"'Exo 2',sans-serif",'rajdhani'=>"'Rajdhani',sans-serif",'share_tech_mono'=>"'Share Tech Mono',monospace",'inter'=>"'Inter',sans-serif",'jura'=>"'Jura',sans-serif",'ibm_plex_mono'=>"'IBM Plex Mono',monospace"];
  if ($playerFont !== 'default' && isset($fontImports[$playerFont])):
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family='.$fontImports[$playerFont].'&display=swap">';
  endif;
  if ($playerFont !== 'default' && isset($fontStacks[$playerFont])):
    echo '<style>body,p,li,td,input,textarea,select,button:not(.orb){font-family:'.$fontStacks[$playerFont].'!important}</style>';
  endif;
endif; ?>
</head><body>

<?php if ($player): ?>
<?php if ($isImpersonating): ?>
<div style="position:sticky;top:0;z-index:9999;background:var(--neon2);color:#000;text-align:center;padding:7px 16px;font-size:12px;font-weight:700;letter-spacing:.5px;display:flex;align-items:center;justify-content:center;gap:16px">
  <span>&#128737; IMPERSONATING: <?= e($player['username']) ?><?= !empty($_SESSION['role_override']) ? ' &middot; Role: '.e($_SESSION['role_override']) : '' ?></span>
  <a href="index.php?stop_impersonate=1" style="color:#000;text-decoration:underline;font-weight:900">&#8617; STOP</a>
</div>
<?php endif; ?>
<nav class="topbar">
  <button id="nav-toggle" class="nav-toggle" aria-label="Open menu" title="Menu">&#9776;</button>
  <a href="index.php?p=home" class="<?= $p==='home'?'active':'' ?>">Hideout</a>
  <a href="index.php?p=stash" class="<?= $p==='stash'?'active':'' ?>">Inventory</a>
  <a href="index.php?p=city" class="<?= $p==='city'?'active':'' ?>">The Sprawl</a>
  <a href="index.php?p=bazaar" class="<?= $p==='bazaar'?'active':'' ?>">Bazaar</a>
  <a href="index.php?p=boards" class="<?= $p==='boards'?'active':'' ?>">Boards</a>
  <a href="index.php?p=messages" class="<?= $p==='messages'?'active':'' ?>">Messages</a>
  <a href="index.php?p=friends" class="<?= $p==='friends'?'active':'' ?>">Friends</a>
  <a href="index.php?p=account" class="<?= $p==='account'?'active':'' ?>">Account</a>
  <a href="index.php?p=updates" class="<?= $p==='updates'?'active':'' ?>">Updates</a>
  <?php if ($isStaff): ?><a href="index.php?p=admin" class="<?= $p==='admin'?'active':'' ?>">Admin</a><?php endif; ?>
  <a href="index.php?p=logout">Logout</a>
</nav>

<?php
$__playerSynId = 0;
try {
  $__sq = db()->prepare('SELECT syndicate_id FROM syndicate_members WHERE player_id=?');
  $__sq->execute([$player['id']]);
  $__playerSynId = (int)($__sq->fetchColumn() ?: 0);
} catch (Throwable $__e) {}
?>
<div class="shell">
  <aside class="left">
    <div class="card">
      <div class="pcard-row">
        <div class="avatar"><?= strtoupper(mb_substr($player['username'], 0, 1)) ?></div>
        <div class="pcard-info">
          <div class="name">
            <a href="index.php?p=profile&id=<?= (int)$player['id'] ?>" style="color:inherit"><?= e($player['username']) ?></a>
            <?php echo flag_img($player['country'] ?? ''); ?>
            <?php if (is_subscribed($player)): ?><span title="Subscriber" style="color:#e8d44d;font-size:13px;vertical-align:middle">&#9733;</span><?php endif; ?>
          </div>
          <div class="pcard-stat"><span>Level</span><b id="st-level"><?= (int)$player['level'] ?></b></div>
          <?php if ($sbBars === null || in_array('creds', $sbBars, true)): ?>
          <div class="pcard-stat" data-stat="creds"><span>Credits</span><b id="st-pocket"><?= number_format($player['creds_pocket']) ?></b></div>
          <?php endif; ?>
          <?php if ($sbBars === null || in_array('bank', $sbBars, true)): ?>
          <div class="pcard-stat" data-stat="bank"><span>Bank</span><b id="st-bank"><?= number_format($player['creds_bank']) ?></b></div>
          <?php endif; ?>
          <?php if ($sbBars === null || in_array('shards', $sbBars, true)): ?>
          <div class="pcard-stat" data-stat="shards"><span>Shards</span><b id="st-shards"><?= number_format($player['shards']) ?></b></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="meters">
      <?php
        if ($sbBars === null || in_array('integrity', $sbBars, true)) bar('Health', $player['integrity'], $player['integrity_max'], 'integrity');
        if ($sbBars === null || in_array('xp', $sbBars, true))        bar('XP', $player['xp'], $player['xp_next'], 'xp');
        if ($sbBars === null || in_array('signal', $sbBars, true))    bar('Signal', $player['signal'], $player['signal_max'], 'signal');
        if ($sbBars === null || in_array('cycles', $sbBars, true))    bar('Drive', $player['cycles'], $player['cycles_max'], 'cycles');
      ?>
    </div>
    <ul class="menu" id="sidemenu">
      <?php $nl = nav_links(); foreach (player_sidebar($player) as $k):
        $isActive = (strpos($nl[$k][1], 'p='.$p) !== false); ?>
        <li data-navkey="<?= e($k) ?>"<?= $isActive ? ' class="active"' : '' ?>><a href="<?= $nl[$k][1] ?>"><?= e($nl[$k][0]) ?></a></li>
      <?php endforeach; ?>
      <?php if ($__playerSynId): ?><li data-navkey="guilds"<?= $p==='guilds'?' class="active"':'' ?>><a href="index.php?p=guilds">My Syndicate</a></li><?php endif; ?>
      <?php if ($isStaff): ?><li data-navkey="admin"<?= $p==='admin'?' class="active"':'' ?>><a href="index.php?p=admin">Admin</a></li><?php endif; ?>
    </ul>
    <div class="sidebar-cta">
      <a href="index.php?p=account&sec=premium" class="cta-btn cta-sub">&#9733; Subscribe</a>
      <a href="index.php?p=account&sec=shards" class="cta-btn cta-shards">&#9670; Buy Shards</a>
    </div>
  </aside>

  <main class="center">
<?php
  if ($isStaff) {
    $snote = '';
    try { $s = db()->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$noteKey]); $snote = (string)$s->fetchColumn(); } catch (Throwable $e) {}
    echo '<div class="staffnote"><div class="staffnote-head">&#128204; <b>Staff Note</b>'
       . '<a href="#" onclick="var n=this.closest(\'.staffnote\');n.querySelector(\'.staffnote-edit\').style.display=\'block\';n.querySelector(\'.staffnote-view\').style.display=\'none\';return false;" style="float:right;font-size:11px">[Edit]</a></div>'
       . '<div class="staffnote-view">' . ($snote !== '' ? nl2br(e($snote)) : '<span class="muted">+ Add note for this page</span>') . '</div>'
       . '<form class="staffnote-edit" method="post" style="display:none;margin-top:6px"><input type="hidden" name="__staffnote" value="1">'
       . '<textarea name="staffnote" style="width:100%;min-height:50px" placeholder="Leave empty to clear...">' . e($snote) . '</textarea>'
       . '<p style="margin:6px 0 0"><button type="submit">Save Note</button></p></form></div>';
  }
  // Jail block — jailed players see only the notice (admins/managers bypass)
  if ($jailRecord && !$isStaff) {
    $secsLeft  = strtotime($jailRecord['release_at']) - time();
    $daysLeft  = max(0, ceil($secsLeft / 86400));
    echo '<div class="panel" style="border:2px solid rgba(255,45,149,.5);background:rgba(255,45,149,.04);text-align:center;padding:40px 20px">'
       . '<div style="font-size:48px;margin-bottom:14px">&#128274;</div>'
       . '<h2 style="font-family:\'Orbitron\',sans-serif;color:var(--neon2);margin-bottom:10px">ACCOUNT SUSPENDED</h2>'
       . '<p style="color:var(--muted);max-width:400px;margin:0 auto 16px;line-height:1.6">'.e($jailRecord['reason']).'</p>'
       . '<div style="font-family:\'Orbitron\',sans-serif;font-size:28px;font-weight:700;color:var(--neon2)">'.number_format($daysLeft).' day'.($daysLeft!==1?'s':'').' remaining</div>'
       . '<p class="muted" style="font-size:11px;margin-top:10px">Sentence ends: '.e(date('M j, Y g:ia', strtotime($jailRecord['release_at']))).'</p>'
       . '</div>';
  } else {
  $file = __DIR__ . "/pages/{$p}.php";
  if (preg_match('/^[a-z]+$/', $p) && file_exists($file)) {
    try { require $file; }
    catch (Throwable $ex) {
      $seeDetail = $player && in_array($player['role'] ?? 'member', ['admin','manager'], true);
      echo '<div class="panel"><h2>Glitch in the Grid</h2><p class="muted">'
         . e($seeDetail ? $ex->getMessage() : 'Something glitched. Try again, or flag it to staff.') . '</p></div>';
    }
  } else {
    echo '<div class="panel"><h2>Signal Lost</h2><p>That node doesn\'t exist on the Sprawl.</p></div>';
  }
  } // end jail else block
?>
  </main>

  <aside class="right">
    <?php
      $__mt  = new DateTime('now', new DateTimeZone('America/Denver'));
      $__mtn = new DateTime('tomorrow midnight', new DateTimeZone('America/Denver'));
      $__resetSecs = max(0, $__mtn->getTimestamp() - time());
    ?>
    <div class="panel"><h3>&#128337; City Time</h3>
      <p style="font-family:'Orbitron',sans-serif;font-size:15px;color:var(--accent);margin:2px 0"><?= $__mt->format('g:i a') ?></p>
      <p style="font-size:11px;color:var(--muted);margin:2px 0"><?= $__mt->format('l, M j Y') ?> &middot; MT</p>
      <div style="margin-top:8px;border-top:1px solid var(--line);padding-top:8px">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Next Reset In</div>
        <div id="reset-countdown" style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--neon2);letter-spacing:.05em">--:--:--</div>
      </div>
    </div>
    <script>
    (function(){
      var s=<?= (int)$__resetSecs ?>;
      var el=document.getElementById('reset-countdown');
      if(!el)return;
      function tick(){
        if(s<=0){el.textContent='Resetting...';return;}
        var h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sc=s%60;
        el.textContent=(h<10?'0':'')+h+':'+(m<10?'0':'')+m+':'+(sc<10?'0':'')+sc;
        s--;
        setTimeout(tick,1000);
      }
      tick();
    })();
    </script>
    <div class="panel">
      <h3>Public Channel <a href="index.php?p=chat" style="font-size:11px;float:right;font-weight:normal">[full chat]</a></h3>
      <div id="chatfeed" style="max-height:220px;overflow-y:auto;font-size:12px"></div>
      <form id="chatform" style="margin-top:8px;display:flex;gap:4px">
        <input type="text" id="chatinput" maxlength="240" autocomplete="off" placeholder="say something...">
        <button type="submit">Say</button>
      </form>
    </div>
    <script>
    (function(){
      var feed=document.getElementById('chatfeed'),
          form=document.getElementById('chatform'),
          input=document.getElementById('chatinput');
      if(!feed||!form) return;
      function render(msgs){
        feed.innerHTML='';
        msgs.slice(-6).forEach(function(m){
          var line=document.createElement('div'); line.style.marginBottom='2px';
          var t=document.createElement('span');
          t.textContent=m.time+' '; t.style.color='#5d6680'; t.style.fontSize='10px';
          if(m.sub){ var star=document.createElement('span'); star.textContent='★'; star.style.cssText='color:#e8d44d;font-size:10px;margin-right:2px'; line.appendChild(t); line.appendChild(star); } else { line.appendChild(t); }
          var who=document.createElement('a'); who.href='index.php?p=profile&id='+m.id;
          who.textContent=m.username+': '; who.style.color=m.name_color||'#c9d1e0'; who.style.fontWeight='bold';
          var body=document.createElement('span');
          body.style.color=m.color; body.innerHTML=m.html;   // server-sanitized (escaped + whitelisted BBCode)
          line.appendChild(who); line.appendChild(body);
          feed.appendChild(line);
        });
        feed.scrollTop=feed.scrollHeight;
      }
      function load(){
        fetch('chat_api.php?action=list',{credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(d){ if(d&&d.messages) render(d.messages); })
          .catch(function(){});
      }
      form.addEventListener('submit',function(ev){
        ev.preventDefault();
        var t=input.value.trim(); if(!t) return;
        var fd=new FormData(); fd.append('action','say'); fd.append('body',t);
        fetch('chat_api.php',{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(){ input.value=''; load(); })
          .catch(function(){});
      });
      load(); setInterval(load,4000);
    })();
    </script>
    <div class="panel">
      <h3>Online <a href="index.php?p=friends" style="font-size:11px;float:right;font-weight:normal">[friends]</a></h3>
      <?php
        // Show online friends first; fall back to all online if no friends table yet
        try {
          $onlineQ = db()->prepare("SELECT p.id, p.username, p.role, p.chat_color
                                    FROM friends f JOIN players p ON p.id = f.friend_id
                                    WHERE f.player_id = ? AND p.last_seen >= (NOW() - INTERVAL 5 MINUTE)
                                    ORDER BY p.username LIMIT 50");
          $onlineQ->execute([$player['id']]);
          $online = $onlineQ->fetchAll();
          if (empty($online)) {
            // No online friends — show a soft hint rather than all players
            $online = [];
          }
        } catch (Throwable $e) {
          $online = db()->query("SELECT id, username, role, chat_color FROM players
                                 WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)
                                 ORDER BY username LIMIT 50")->fetchAll();
        }
      ?>
      <div id="jackedin">
        <?php if (empty($online)): ?>
          <p class="muted" style="font-size:11px">No friends online. <a href="index.php?p=friends">Add friends</a> to see them here.</p>
        <?php else: foreach ($online as $o): $oc = chat_color($o['role'], ''); ?>
          <div class="online-player"><span class="online-dot"></span><a href="index.php?p=profile&id=<?= (int)$o['id'] ?>" style="color:<?= e($oc) ?>;font-weight:bold"><?= e($o['username']) ?></a></div>
        <?php endforeach; endif; ?>
      </div>
      <p class="muted" style="font-size:10px;margin-top:6px"><span id="jackedin-count"><?= count($online) ?></span> friends online</p>
    </div>
  </aside>

  <script>
  (function(){
    function fmt(n){ return Number(n).toLocaleString('en-US'); }
    function setText(id,v){ var el=document.getElementById(id); if(el) el.textContent=v; }
    function setMeter(key,val,max){
      var f=document.querySelector('[data-fill="'+key+'"]'), em=document.querySelector('[data-em="'+key+'"]');
      var pct = max>0 ? Math.min(100, Math.round(val/max*100)) : 0;
      if(f) f.style.width=pct+'%';
      if(em) em.textContent=fmt(val)+' / '+fmt(max);
    }
    function renderOnline(list){
      var box=document.getElementById('jackedin'); if(!box) return;
      box.innerHTML='';
      list.forEach(function(o){
        var d=document.createElement('div'); d.className='online-player';
        var dot=document.createElement('span'); dot.className='online-dot';
        var a=document.createElement('a'); a.href='index.php?p=profile&id='+o.id;
        a.textContent=o.name; a.style.color=o.color; a.style.fontWeight='bold';
        d.appendChild(dot); d.appendChild(a); box.appendChild(d);
      });
      var c=document.getElementById('jackedin-count'); if(c) c.textContent=list.length;
    }
    function refresh(){
      fetch('state_api.php',{credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(d){
          if(!d||!d.ok) return; var s=d.s;
          setText('st-level', s.level); setText('st-pocket', fmt(s.pocket));
          setText('st-bank', fmt(s.bank)); setText('st-shards', fmt(s.shards));
          setMeter('integrity', s.integrity, s.integrity_max);
          setMeter('xp', s.xp, s.xp_next);
          setMeter('signal', s.signal, s.signal_max);
          setMeter('cycles', s.cycles, s.cycles_max);
          if(d.online) renderOnline(d.online);
        }).catch(function(){});
    }
    window.refreshState = refresh; refresh(); setInterval(refresh, 3000);
  })();
  </script>

  <script>
  /* ── Sprawl-9 · AJAX Navigation + UX System ── */
  (function(){

    /* ── Progress bar ── */
    var pb=(function(){
      var el=null,t1,t2;
      function mk(){ if(el) return; el=document.createElement('div'); el.id='pb';
        el.style.cssText='position:fixed;top:0;left:0;height:3px;width:0;z-index:10000;background:linear-gradient(90deg,var(--accent),var(--neon2));pointer-events:none;will-change:width,opacity';
        document.body.appendChild(el); }
      return {
        start:function(){ mk(); clearTimeout(t1); clearTimeout(t2);
          el.style.transition='none'; el.style.opacity='1'; el.style.width='0';
          requestAnimationFrame(function(){ el.style.transition='width .55s cubic-bezier(.05,.5,.3,1)'; el.style.width='78%'; }); },
        done:function(){ if(!el) return; clearTimeout(t1); clearTimeout(t2);
          el.style.transition='width .12s ease'; el.style.width='100%';
          t1=setTimeout(function(){ el.style.transition='opacity .22s ease'; el.style.opacity='0';
            t2=setTimeout(function(){ el.style.width='0'; el.style.opacity='1'; },240); },130); }
      };
    })();

    /* ── Toast ── */
    window.showToast=function(text,type){
      if(!text) return;
      var c=document.getElementById('toasts');
      if(!c){ c=document.createElement('div'); c.id='toasts'; document.body.appendChild(c); }
      var t=document.createElement('div'); t.className='toast'+(type?' toast-'+type:''); t.textContent=text; c.appendChild(t);
      requestAnimationFrame(function(){ t.classList.add('show'); });
      setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ if(t.parentNode) t.remove(); },320); },3600);
    };

    /* ── Run scripts injected via innerHTML ── */
    function runScripts(c){ c.querySelectorAll('script').forEach(function(o){
      var s=document.createElement('script'); if(o.src)s.src=o.src; else s.textContent=o.textContent;
      o.parentNode.replaceChild(s,o); }); }

    /* ── Restore submit buttons ── */
    function restoreBtns(form){
      (form||document).querySelectorAll('[type=submit][data-orig]').forEach(function(b){
        b.innerHTML=b.dataset.orig; b.disabled=false; delete b.dataset.orig; });
    }

    /* ── Swap main content ── */
    function swapCenter(html, href){
      var doc=new DOMParser().parseFromString(html,'text/html');
      var nm=doc.querySelector('main.center');
      var main=document.querySelector('main.center');
      if(!nm||!main){ window.location.href=href; return; }

      main.innerHTML=nm.innerHTML;
      main.classList.remove('page-loading');
      runScripts(main);
      if(window.refreshState) window.refreshState();

      /* Animate content in */
      main.style.cssText='opacity:0;transform:translateY(5px)';
      requestAnimationFrame(function(){
        main.style.cssText='opacity:1;transform:none;transition:opacity .18s ease,transform .18s ease';
        setTimeout(function(){ main.style.cssText=''; },200);
      });

      /* Flash messages → toasts (after AJAX swap) */
      main.querySelectorAll('.flash-ok').forEach(function(f){ window.showToast(f.textContent.trim(),'ok'); f.style.display='none'; });
      main.querySelectorAll('.flash-err').forEach(function(f){ window.showToast(f.textContent.trim(),'err'); });

      /* Sync nav active state */
      var m=(href||'').match(/[?&]p=([a-z]+)/); var curP=m?m[1]:'home';
      document.querySelectorAll('.topbar a').forEach(function(a){
        var am=(a.getAttribute('href')||'').match(/[?&]p=([a-z]+)/);
        a.classList.toggle('active',!!(am&&am[1]===curP)); });
      document.querySelectorAll('.menu li').forEach(function(li){
        var a=li.querySelector('a'); if(!a) return;
        var am=(a.getAttribute('href')||'').match(/[?&]p=([a-z]+)/);
        li.classList.toggle('active',!!(am&&am[1]===curP)); });

      /* Tab title */
      var nt=doc.querySelector('title'); if(nt) document.title=nt.textContent;

      /* Close mobile sidebar */
      var aside=document.querySelector('aside.left');
      if(aside) aside.classList.remove('open');
      document.body.classList.remove('sidebar-open');

      /* Scroll to top */
      window.scrollTo({top:0,behavior:'instant'});

      /* Event for extensions */
      document.dispatchEvent(new CustomEvent('sprawl:swapped',{detail:{page:curP}}));

      /* Restore any stuck buttons */
      restoreBtns();
    }

    /* ── POST forms ── */
    document.addEventListener('submit',function(ev){
      var form=ev.target;
      if(!form||(form.getAttribute('method')||'get').toLowerCase()!=='post') return;
      if(form.getAttribute('action')) return;
      var main=document.querySelector('main.center');
      if(!main||!main.contains(form)) return;
      ev.preventDefault();
      var fd=new FormData(form);
      if(ev.submitter&&ev.submitter.name) fd.append(ev.submitter.name,ev.submitter.value||'');

      /* Button loading state */
      var btns=form.querySelectorAll('[type=submit]:not([disabled])');
      btns.forEach(function(b){ b.dataset.orig=b.innerHTML; b.disabled=true;
        b.innerHTML='<span class="spin">&#10227;</span>'; });

      pb.start();
      main.classList.add('page-loading');

      fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.text();})
        .then(function(html){ pb.done(); swapCenter(html,window.location.href); })
        .catch(function(){ pb.done(); main.classList.remove('page-loading'); restoreBtns(form); form.submit(); });
    });

    /* ── GET link navigation ── */
    document.addEventListener('click',function(ev){
      var a=ev.target.closest('a[href]'); if(!a) return;
      var href=a.getAttribute('href');
      if(!href||href.charAt(0)==='#'||a.target) return;
      if(href.indexOf('://')!==-1) return;
      if(href.indexOf('p=logout')!==-1||href.indexOf('stop_impersonate')!==-1) return;
      var main=document.querySelector('main.center'); if(!main) return;
      ev.preventDefault();
      history.pushState(null,'',href);
      pb.start(); main.classList.add('page-loading');
      fetch(href,{credentials:'same-origin'})
        .then(function(r){return r.text();})
        .then(function(html){ pb.done(); swapCenter(html,href); })
        .catch(function(){ pb.done(); main.classList.remove('page-loading'); window.location.href=href; });
    });

    /* ── Back / forward ── */
    window.addEventListener('popstate',function(){
      var href=window.location.href;
      var main=document.querySelector('main.center'); if(!main) return;
      pb.start(); main.classList.add('page-loading');
      fetch(href,{credentials:'same-origin'})
        .then(function(r){return r.text();})
        .then(function(html){ pb.done(); swapCenter(html,href); })
        .catch(function(){ pb.done(); window.location.reload(); });
    });

    /* ── Character counters ── */
    function attachCounters(root){
      (root||document).querySelectorAll('textarea[maxlength],input[maxlength]:not([type=hidden]):not(#chatinput):not(#chatinput-full)').forEach(function(el){
        if(el._cc) return; el._cc=true;
        var max=parseInt(el.getAttribute('maxlength'),10); if(!max||max>10000) return;
        var c=document.createElement('span'); c.className='char-count';
        c.textContent=max+' left';
        el.parentNode.insertBefore(c,el.nextSibling);
        el.addEventListener('input',function(){
          var left=max-el.value.length;
          c.textContent=left+' left';
          c.className='char-count'+(left<30?' cc-danger':left<max*.15?' cc-warn':'');
        });
      });
    }
    attachCounters();
    document.addEventListener('sprawl:swapped',function(){ attachCounters(document.querySelector('main.center')); });

    /* ── Mobile sidebar toggle ── */
    var toggle=document.getElementById('nav-toggle');
    var sidebar=document.querySelector('aside.left');
    if(toggle&&sidebar){
      toggle.addEventListener('click',function(ev){
        ev.stopPropagation();
        sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open');
        toggle.setAttribute('aria-label', sidebar.classList.contains('open')?'Close menu':'Open menu');
      });
      document.addEventListener('click',function(ev){
        if(sidebar.classList.contains('open')&&!ev.target.closest('aside.left')&&!ev.target.closest('#nav-toggle')){
          sidebar.classList.remove('open'); document.body.classList.remove('sidebar-open');
        }
      });
    }

    /* ── Auto-dismiss static flash messages ── */
    function autoDismissFlashes(root){
      (root||document).querySelectorAll('.flash-ok').forEach(function(f){
        setTimeout(function(){
          f.style.transition='opacity .4s ease,max-height .4s ease,margin .4s ease,padding .4s ease';
          f.style.opacity='0'; f.style.maxHeight='0'; f.style.marginBottom='0'; f.style.paddingTop='0'; f.style.paddingBottom='0';
          setTimeout(function(){ if(f.parentNode) f.remove(); },420);
        },4500);
      });
    }
    autoDismissFlashes();
    document.addEventListener('sprawl:swapped',function(){ autoDismissFlashes(document.querySelector('main.center')); });

    /* ── Keyboard shortcuts ── */
    document.addEventListener('keydown',function(ev){
      var tag=document.activeElement?document.activeElement.tagName:'';
      var inField=(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT');
      /* / → focus sidebar chat */
      if(ev.key==='/'&&!inField&&!ev.ctrlKey&&!ev.metaKey){
        var ci=document.getElementById('chatinput');
        if(ci){ ev.preventDefault(); ci.focus(); ci.scrollIntoView({behavior:'smooth',block:'nearest'}); }
      }
      /* Escape → close mobile menu / blur */
      if(ev.key==='Escape'){
        if(sidebar) sidebar.classList.remove('open');
        document.body.classList.remove('sidebar-open');
        if(inField) document.activeElement.blur();
      }
      /* Ctrl/Cmd+Enter → submit textarea form */
      if((ev.ctrlKey||ev.metaKey)&&ev.key==='Enter'&&tag==='TEXTAREA'){
        var f=document.activeElement.closest('form');
        if(f){ var s=f.querySelector('[type=submit]:not([disabled])'); if(s){ ev.preventDefault(); s.click(); } }
      }
    });

  })();
  </script>
</div>

<?php else: ?>
<?php
  if ($p === 'login' || $p === 'register') {
    echo '<div class="shell solo"><main class="center">';
    require __DIR__ . "/pages/{$p}.php";
    echo '</main></div>';
  } else {
    require __DIR__ . '/pages/landing.php';   // full-width splash
  }
?>
<?php endif; ?>

</body></html>
