<?php
require 'config.php';
require 'lib.php';
$p   = $_GET['p']   ?? 'home';
$act = $_GET['act'] ?? '';
$player = current_player();
if ($player) db()->prepare('UPDATE players SET last_seen = NOW() WHERE id = ?')->execute([$player['id']]);
$isStaff = $player && in_array($player['role'] ?? 'member', ['chatmod','moderator','admin','manager'], true);

function bar($label, $val, $max, $key = '') {
  $pct = $max > 0 ? min(100, round($val / $max * 100)) : 0;
  $df  = $key ? ' data-fill="'.$key.'"' : '';
  $de  = $key ? ' data-em="'.$key.'"' : '';
  echo '<div class="meter"><span>'.e($label).'</span>'
     . '<div class="track"><div class="fill"'.$df.' style="width:'.$pct.'%"></div></div>'
     . '<em'.$de.'>'.number_format($val).' / '.number_format($max).'</em></div>';
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sprawl-9</title>
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__.'/style.css') ?: '1' ?>">
<?php if ($player) echo theme_css($player['theme'] ?? 'neon', $player['accent_color'] ?? ''); ?>
</head><body>

<?php if ($player): ?>
<nav class="topbar">
  <a href="index.php?p=home">Hideout</a>
  <a href="index.php?p=stash">Stash</a>
  <a href="index.php?p=city">The Sprawl</a>
  <a href="index.php?p=bazaar">Bazaar</a>
  <a href="index.php?p=boards">Boards</a>
  <a href="index.php?p=messages">Messages</a>
  <a href="index.php?p=account">Account</a>
  <a href="index.php?p=updates">Updates</a>
  <?php if ($isStaff): ?><a href="index.php?p=admin">Admin</a><?php endif; ?>
  <a href="index.php?p=logout">Logout</a>
</nav>

<div class="shell">
  <aside class="left">
    <div class="card">
      <div class="avatar"></div>
      <div class="name"><a href="index.php?p=profile&id=<?= (int)$player['id'] ?>" style="color:inherit"><?= e($player['username']) ?></a></div>
      <div class="stat"><span>Level</span><b id="st-level"><?= (int)$player['level'] ?></b></div>
      <div class="stat"><span>Creds</span><b id="st-pocket"><?= number_format($player['creds_pocket']) ?></b></div>
      <div class="stat"><span>Bank</span><b id="st-bank"><?= number_format($player['creds_bank']) ?></b></div>
      <div class="stat"><span>Shards</span><b id="st-shards"><?= number_format($player['shards']) ?></b></div>
    </div>
    <div class="meters">
      <?php
        bar('Integrity', $player['integrity'], $player['integrity_max'], 'integrity');
        bar('XP', $player['xp'], $player['xp_next'], 'xp');
        bar('Signal', $player['signal'], $player['signal_max'], 'signal');
        bar('Cycles', $player['cycles'], $player['cycles_max'], 'cycles');
      ?>
    </div>
    <ul class="menu">
      <li><a href="index.php?p=home">Hideout</a></li>
      <li><a href="index.php?p=stash">Stash</a></li>
      <li><a href="index.php?p=ledger&act=bank">Bank</a></li>
      <li><a href="index.php?p=city">The Sprawl</a></li>
      <li><a href="index.php?p=bazaar">Bazaar</a></li>
      <li><a href="index.php?p=boards">Message Boards</a></li>
      <li><a href="index.php?p=messages">Messages</a></li>
      <li><a href="index.php?p=account">Account</a></li>
      <li><a href="index.php?p=updates">Updates</a></li>
      <?php if ($isStaff): ?><li><a href="index.php?p=admin">Admin</a></li><?php endif; ?>
    </ul>
  </aside>

  <main class="center">
<?php
  $file = __DIR__ . "/pages/{$p}.php";
  if (preg_match('/^[a-z]+$/', $p) && file_exists($file)) {
    try { require $file; }
    catch (Throwable $ex) {
      echo '<div class="panel"><h2>Glitch in the Grid</h2><p class="muted">'.e($ex->getMessage()).'</p></div>';
    }
  } else {
    echo '<div class="panel"><h2>Signal Lost</h2><p>That node doesn\'t exist on the Sprawl.</p></div>';
  }
?>
  </main>

  <aside class="right">
    <div class="panel"><h3>City Time</h3><p><?= date('m/d/Y h:i a') ?></p></div>
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
        msgs.slice(-5).forEach(function(m){
          var line=document.createElement('div'); line.style.marginBottom='2px';
          var t=document.createElement('span');
          t.textContent=m.time+' '; t.style.color='#5d6680'; t.style.fontSize='10px';
          var who=document.createElement('a'); who.href='index.php?p=profile&id='+m.id;
          who.textContent=m.username+': '; who.style.color=m.color; who.style.fontWeight='bold';
          var body=document.createElement('span');
          body.style.color=m.color; body.innerHTML=m.html;   // server-sanitized (escaped + whitelisted BBCode)
          line.appendChild(t); line.appendChild(who); line.appendChild(body);
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
      <h3>Online</h3>
      <?php
        $online = db()->query("SELECT id, username, role, chat_color FROM players
                               WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)
                               ORDER BY username LIMIT 50")->fetchAll();
      ?>
      <div id="jackedin">
        <?php foreach ($online as $o): $oc = chat_color($o['role'], $o['chat_color']); ?>
          <div style="font-size:12px;padding:1px 0"><a href="index.php?p=profile&id=<?= (int)$o['id'] ?>" style="color:<?= e($oc) ?>;font-weight:bold"><?= e($o['username']) ?></a></div>
        <?php endforeach; ?>
      </div>
      <p class="muted" style="font-size:10px;margin-top:6px"><span id="jackedin-count"><?= count($online) ?></span> online</p>
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
        var d=document.createElement('div'); d.style.fontSize='12px'; d.style.padding='1px 0';
        var a=document.createElement('a'); a.href='index.php?p=profile&id='+o.id;
        a.textContent=o.name; a.style.color=o.color; a.style.fontWeight='bold';
        d.appendChild(a); box.appendChild(d);
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
  /* AJAX: submit center-column forms in place instead of a full page reload. */
  (function(){
    function runScripts(c){ c.querySelectorAll('script').forEach(function(o){
      var s=document.createElement('script'); if(o.src)s.src=o.src; else s.textContent=o.textContent;
      o.parentNode.replaceChild(s,o); }); }
    document.addEventListener('submit', function(ev){
      var form=ev.target;
      if(!form || (form.getAttribute('method')||'get').toLowerCase()!=='post') return;
      if(form.getAttribute('action')) return;                 // only forms that post to the current page
      var main=document.querySelector('main.center');
      if(!main || !main.contains(form)) return;
      ev.preventDefault();
      var fd=new FormData(form);
      if(ev.submitter && ev.submitter.name) fd.append(ev.submitter.name, ev.submitter.value||'');
      main.style.opacity='0.45';
      fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.text();})
        .then(function(html){
          var nm=new DOMParser().parseFromString(html,'text/html').querySelector('main.center');
          if(!nm){ window.location.reload(); return; }
          main.innerHTML=nm.innerHTML; runScripts(main); main.style.opacity='';
          if(window.refreshState) window.refreshState();
        })
        .catch(function(){ main.style.opacity=''; form.submit(); });
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
