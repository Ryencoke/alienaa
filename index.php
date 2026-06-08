<?php
require 'config.php';
require 'lib.php';
$p   = $_GET['p']   ?? 'home';
$act = $_GET['act'] ?? '';
$player = current_player();

// Pages that don't require login
$public = ['login','register'];
if (!in_array($p, $public) && !$player) { header('Location: index.php?p=login'); exit; }

function bar($label, $val, $max) {
  $pct = $max > 0 ? min(100, round($val / $max * 100)) : 0;
  echo '<div class="meter"><span>'.e($label).'</span>'
     . '<div class="track"><div class="fill" style="width:'.$pct.'%"></div></div>'
     . '<em>'.number_format($val).' / '.number_format($max).'</em></div>';
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sprawl-9</title>
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__.'/style.css') ?: '1' ?>">
</head><body>

<?php if ($player): ?>
<nav class="topbar">
  <a href="index.php?p=home">Hideout</a>
  <a href="index.php?p=stash">Stash</a>
  <a href="index.php?p=city">The Sprawl</a>
  <a href="index.php?p=bazaar">Bazaar</a>
  <a href="index.php?p=boards">Boards</a>
  <a href="index.php?p=datacore&act=lab">Datacore</a>
  <a href="index.php?p=account">Account</a>
  <a href="index.php?p=logout">Jack Out</a>
</nav>

<div class="shell">
  <aside class="left">
    <div class="card">
      <div class="avatar"></div>
      <div class="name"><?= e($player['username']) ?></div>
      <div class="stat"><span>Level</span><b><?= (int)$player['level'] ?></b></div>
      <div class="stat"><span>Creds</span><b><?= number_format($player['creds_pocket']) ?></b></div>
      <div class="stat"><span>Bank</span><b><?= number_format($player['creds_bank']) ?></b></div>
      <div class="stat"><span>Shards</span><b><?= number_format($player['shards']) ?></b></div>
    </div>
    <div class="meters">
      <?php
        bar('Integrity', $player['integrity'], $player['integrity_max']);
        bar('XP', $player['xp'], $player['xp_next']);
        bar('Signal', $player['signal'], $player['signal_max']);
        bar('Cycles', $player['cycles'], $player['cycles_max']);
      ?>
    </div>
    <ul class="menu">
      <li><a href="index.php?p=home">Hideout</a></li>
      <li><a href="index.php?p=stash">Stash</a></li>
      <li><a href="index.php?p=ledger&act=bank">Iron Ledger</a></li>
      <li><a href="index.php?p=city">The Sprawl</a></li>
      <li><a href="index.php?p=bazaar">Bazaar</a></li>
      <li><a href="index.php?p=boards">Message Boards</a></li>
      <li><a href="index.php?p=datacore&act=lab">Datacore</a></li>
      <li><a href="index.php?p=account">Account</a></li>
    </ul>
  </aside>

  <main class="center">
<?php
  $file = __DIR__ . "/pages/{$p}.php";
  if (preg_match('/^[a-z]+$/', $p) && file_exists($file)) {
    require $file;
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
        msgs.forEach(function(m){
          var line=document.createElement('div'); line.style.marginBottom='2px';
          var t=document.createElement('span');
          t.textContent=m.time+' '; t.style.color='#5d6680'; t.style.fontSize='10px';
          var who=document.createElement('b');
          who.textContent=m.username+': '; who.style.color=m.color;
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
    <div class="panel"><h3>Jacked In</h3><p class="muted">Online players list.</p></div>
  </aside>
</div>

<?php else: ?>
<div class="shell solo">
  <main class="center">
<?php
  $file = __DIR__ . "/pages/{$p}.php";
  if (in_array($p,$public) && file_exists($file)) require $file;
  else { header('Location: index.php?p=login'); exit; }
?>
  </main>
</div>
<?php endif; ?>

</body></html>
