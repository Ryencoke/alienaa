<?php /* pages/login.php */
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $em=strtolower(trim($_POST['email']??'')); $pw=$_POST['password']??'';
  $pdo=db();

  // Lockout: 5 failed attempts for the same identifier within 15 minutes blocks
  // further attempts (even a correct password) until the window expires. Stored
  // in the generic settings k/v store as "count|first_attempt_unix_ts" — no
  // schema migration needed, mirrors how cooldowns elsewhere in the app work.
  $lockKey = 'login_fail:' . $em;
  $lockMax = 5; $lockWindow = 900; // 15 minutes
  $failCount = 0; $firstAttempt = 0;
  $lockRaw = setting_get($pdo, $lockKey, '');
  if ($lockRaw !== '' && str_contains($lockRaw, '|')) {
    [$failCount, $firstAttempt] = array_map('intval', explode('|', $lockRaw, 2));
  }
  $lockedOut = $failCount >= $lockMax && (time() - $firstAttempt) < $lockWindow;

  if ($lockedOut) {
    $waitMin = max(1, (int)ceil(($lockWindow - (time() - $firstAttempt)) / 60));
    $err = "Too many failed attempts. Try again in {$waitMin} minute" . ($waitMin === 1 ? '' : 's') . '.';
  } else {
    if ($failCount >= $lockMax) { $failCount = 0; $firstAttempt = 0; } // window expired — reset
    // Try by email first; fall back to username so existing accounts still work
    $stmt=$pdo->prepare('SELECT * FROM players WHERE email=?');
    $stmt->execute([$em]); $row=$stmt->fetch();
    if (!$row) {
      $stmt=$pdo->prepare('SELECT * FROM players WHERE username=?');
      $stmt->execute([$em]); $row=$stmt->fetch();
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    if ($row && password_verify($pw,$row['pass_hash'])) {
      setting_set($pdo, $lockKey, ''); // clear lockout on success
      session_regenerate_id(true);
      $_SESSION['pid']=(int)$row['id']; // int, so strict ===-comparisons against (int) ids behave
      $_SESSION['last_activity']=time();
      unset($_SESSION['timed_out']);
      try { $pdo->prepare('INSERT INTO ip_log (player_id,ip,user_agent,action) VALUES (?,?,?,?)')->execute([$row['id'],$ip,$ua,'login']); } catch(Throwable $e){}
      header('Location: index.php?p=welcome'); exit;
    }
    $failCount++;
    if ($firstAttempt === 0) $firstAttempt = time();
    setting_set($pdo, $lockKey, $failCount . '|' . $firstAttempt);
    try { $pdo->prepare('INSERT INTO ip_log (player_id,ip,user_agent,action) VALUES (?,?,?,?)')->execute([$row['id']??null,$ip,$ua,'fail']); } catch(Throwable $e){}
    $err='Bad credentials. The Grid does not know you.';
  }
}
?>
<style>
.auth-bg{position:fixed;inset:0;z-index:0;pointer-events:none}
.auth-shell{position:relative;z-index:1}
.auth-back{position:fixed;top:14px;left:18px;z-index:2;font-size:12px;color:var(--muted);letter-spacing:.5px}
.auth-back:hover{color:var(--accent)}
.auth-brand{text-align:center;margin-bottom:20px}
.auth-brand .lg{font-family:'Orbitron',sans-serif;font-size:27px;font-weight:900;color:var(--accent);
  letter-spacing:7px;text-shadow:0 0 30px rgba(25,240,199,.45);animation:authFlk 5s linear infinite}
.auth-brand .tg{display:block;font-size:9px;color:var(--muted);letter-spacing:4px;text-transform:uppercase;margin-top:7px}
@keyframes authFlk{0%,92%,96%,100%{opacity:1}94%{opacity:.5}}
@keyframes authIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
@keyframes authShake{0%,100%{margin-left:0}20%,60%{margin-left:-8px}40%,80%{margin-left:8px}}
.auth-card{position:relative;overflow:hidden;background:rgba(18,18,31,.86);backdrop-filter:blur(8px);
  box-shadow:0 18px 50px rgba(0,0,0,.55),0 0 44px rgba(25,240,199,.05);
  animation:authIn .5s cubic-bezier(.2,.8,.3,1) both}
.auth-card.shake{animation:authIn .5s cubic-bezier(.2,.8,.3,1) both,authShake .38s .25s}
.auth-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--accent) 35%,var(--neon2) 65%,transparent)}
.auth-card h2{font-family:'Orbitron',sans-serif;letter-spacing:2px}
.auth-card .field input:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px rgba(25,240,199,.1)}
</style>

<a class="auth-back" href="index.php">&larr; Back to the Sprawl</a>
<canvas class="auth-bg" id="auth-bg"></canvas>

<div class="auth-shell">
  <div>
    <div class="auth-brand">
      <span class="lg">SPRAWL-9</span>
      <span class="tg">Grid Access Terminal</span>
    </div>
    <div class="auth-card<?= ($err || !empty($_SESSION['timed_out'])) ? ' shake' : '' ?>">
      <h2>Jack In</h2>
      <p class="auth-sub">Sign in to your ghost.</p>
      <?php if(!empty($_SESSION['timed_out'])): unset($_SESSION['timed_out']); ?>
      <div class="flash flash-err">Session expired due to inactivity. Please sign in again.</div>
      <?php elseif($err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif;?>
      <form method="post">
        <div class="field">
          <span>Email or Handle</span>
          <input type="text" name="email" autocomplete="email" autofocus>
        </div>
        <div class="field">
          <span>Passkey</span>
          <div class="pass-wrap" style="max-width:none">
            <input type="password" name="password" autocomplete="current-password">
            <button type="button" class="pass-toggle" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈'">&#128065;</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">&#9889; Jack In</button>
      </form>
      <p class="auth-foot">No handle yet? <a href="index.php?p=landing">Register a ghost.</a></p>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
/* Synthwave grid horizon behind the auth card */
var cv=document.getElementById('auth-bg');
if(!cv) return;
var c=cv.getContext('2d'), W, H, dpr;
function rnd(i){ var x=Math.sin(i*127.17)*43758.5453; return x-Math.floor(x); }
function size(){
  dpr=Math.min(2,window.devicePixelRatio||1);
  W=window.innerWidth; H=window.innerHeight;
  cv.width=W*dpr; cv.height=H*dpr;
  c.setTransform(dpr,0,0,dpr,0,0);
}
size();
var rsT; window.addEventListener('resize',function(){ clearTimeout(rsT); rsT=setTimeout(size,150); });

function loop(t){
  if(!document.body.contains(cv)) return;
  requestAnimationFrame(loop);
  c.clearRect(0,0,W,H);
  var hz=H*0.68;
  var g=c.createLinearGradient(0,0,0,H);
  g.addColorStop(0,'#07070f'); g.addColorStop(1,'#0b0a16');
  c.fillStyle=g; c.fillRect(0,0,W,H);

  // stars
  for(var s=0;s<70;s++){
    var tw=.32+.3*Math.sin(t/900+s*1.7);
    if(tw<=0) continue;
    c.fillStyle='rgba(220,240,255,'+tw.toFixed(2)+')';
    c.fillRect(rnd(s+11)*W, rnd(s+77)*hz*0.88, 1.4, 1.4);
  }

  // horizon glow (teal core, pink halo, slow breathing)
  var br=1+.08*Math.sin(t/2400);
  var hg=c.createRadialGradient(W/2,hz,0,W/2,hz,W*0.62*br);
  hg.addColorStop(0,'rgba(25,240,199,.11)');
  hg.addColorStop(.45,'rgba(255,45,149,.045)');
  hg.addColorStop(1,'rgba(0,0,0,0)');
  c.fillStyle=hg; c.fillRect(0,0,W,H);

  // skyline silhouette sitting on the horizon
  for(var i=0,x=-12;x<W+30;i++){
    var bw=18+rnd(i+3)*42, bh=8+rnd(i+9)*54;
    c.fillStyle='#0c0c18';
    c.fillRect(x,hz-bh,bw,bh);
    if(rnd(i+5)>.45){
      var lit=.10+.09*Math.sin(t/800+i*2.3);
      c.fillStyle=(rnd(i+21)>.5)?'rgba(25,240,199,'+lit.toFixed(2)+')':'rgba(255,45,149,'+lit.toFixed(2)+')';
      c.fillRect(x+bw*0.3, hz-bh+4, 2, 2);
    }
    x+=bw+3+rnd(i+13)*10;
  }
  c.strokeStyle='rgba(25,240,199,.28)'; c.lineWidth=1;
  c.beginPath(); c.moveTo(0,hz+.5); c.lineTo(W,hz+.5); c.stroke();

  // perspective grid floor
  var vp=W/2;
  c.strokeStyle='rgba(25,240,199,.14)';
  for(var k=-14;k<=14;k++){
    c.beginPath(); c.moveTo(vp+k*24,hz); c.lineTo(vp+k*W*0.15,H+60); c.stroke();
  }
  var rows=10;
  for(var r=0;r<rows;r++){
    var p=((r/rows)+(t/7000))%1;
    var y=hz+Math.pow(p,2.2)*(H-hz);
    c.globalAlpha=.10+p*.26;
    c.beginPath(); c.moveTo(0,y); c.lineTo(W,y); c.stroke();
  }
  c.globalAlpha=1;

  // soft veil behind the card so it pops
  var v=c.createRadialGradient(W/2,H*0.45,60,W/2,H*0.45,Math.max(W,H)*0.55);
  v.addColorStop(0,'rgba(5,5,10,.40)'); v.addColorStop(1,'rgba(5,5,10,0)');
  c.fillStyle=v; c.fillRect(0,0,W,H);
}
requestAnimationFrame(loop);
})();
</script>
