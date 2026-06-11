<?php /* pages/register.php */
$err=''; $ok='';
$pdo=db();
// One-time schema migration — silently ignored if column already exists
try { $pdo->exec("ALTER TABLE players ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER pass_hash"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE players ADD UNIQUE KEY uq_player_email (email)"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE players ADD COLUMN gender CHAR(1) NOT NULL DEFAULT '' AFTER email"); } catch(Throwable $e){}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim($_POST['username']??'');
  $em=strtolower(trim($_POST['email']??''));
  $pw=$_POST['password']??'';
  $gd=in_array($_POST['gender']??'',['M','F'],true)?$_POST['gender']:'';
  if (!preg_match('/^[A-Za-z0-9_]{3,32}$/',$u)) $err='Handle must be 3-32 characters: letters, numbers, or underscore only.';
  elseif (!filter_var($em,FILTER_VALIDATE_EMAIL)) $err='Enter a valid email address.';
  elseif (strlen($pw)<8) $err='Passkey must be at least 8 characters.';
  else {
    try {
      $pdo->prepare('INSERT INTO players (username,email,gender,pass_hash) VALUES (?,?,?,?)')
          ->execute([$u,$em,$gd,password_hash($pw,PASSWORD_DEFAULT)]);
      $newId = (int)$pdo->lastInsertId();
      $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
      try { $pdo->prepare('INSERT INTO ip_log (player_id,ip,user_agent,action) VALUES (?,?,?,?)')->execute([$newId,$ip,$ua,'register']); } catch(Throwable $e){}
      $ok='Ghost created. You can jack in now.';
    } catch (PDOException $ex) {
      if ($ex->getCode() === '23000') $err='That handle or email is already registered.';
      else $err='Registration failed. Please try again.';
    }
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
.auth-card{position:relative;overflow:hidden;background:rgba(18,18,31,.86);backdrop-filter:blur(8px);
  box-shadow:0 18px 50px rgba(0,0,0,.55),0 0 44px rgba(25,240,199,.05);
  animation:authIn .5s cubic-bezier(.2,.8,.3,1) both}
.auth-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--accent) 35%,var(--neon2) 65%,transparent)}
.auth-card h2{font-family:'Orbitron',sans-serif;letter-spacing:2px}
.auth-card .field input:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px rgba(25,240,199,.1)}
.fld-hint{font-size:10px;margin-top:4px;letter-spacing:.4px;min-height:13px;color:var(--muted);transition:color .15s}
.fld-hint.good{color:#3bcf63}.fld-hint.bad{color:var(--neon2)}
.pw-meter{display:flex;gap:5px;margin-top:7px}
.pw-meter i{flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,.08);transition:background .25s}
.gd-row{display:flex;gap:10px}
.gd-chip{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;cursor:pointer;font-size:13px;
  padding:9px 10px;border:1px solid var(--line);border-radius:6px;background:rgba(255,255,255,.02);
  text-transform:none;letter-spacing:0;transition:border-color .15s,background .15s,transform .1s}
.gd-chip:hover{transform:translateY(-1px)}
.gd-chip:has(input:checked){background:rgba(255,255,255,.05)}
.gd-chip.m:has(input:checked){border-color:#5fa8e8;box-shadow:0 0 10px rgba(95,168,232,.15)}
.gd-chip.f:has(input:checked){border-color:#ff75b5;box-shadow:0 0 10px rgba(255,117,181,.15)}
@keyframes regOk{0%{transform:scale(.9);opacity:0}60%{transform:scale(1.04)}100%{transform:scale(1);opacity:1}}
.reg-ok{animation:regOk .45s cubic-bezier(.2,.9,.3,1.2) both}
</style>

<a class="auth-back" href="index.php">&larr; Back to the Sprawl</a>
<canvas class="auth-bg" id="auth-bg"></canvas>

<div class="auth-shell">
  <div>
    <div class="auth-brand">
      <span class="lg">SPRAWL-9</span>
      <span class="tg">New Ghost Registration</span>
    </div>
    <div class="auth-card">
      <h2>Create a Ghost</h2>
      <p class="auth-sub">Pick a handle and a passkey to enter the Sprawl.</p>
      <?php if($err):?><div class="flash flash-err"><?= e($err) ?></div><?php endif;?>
      <?php if($ok):?>
        <div class="reg-ok">
          <div class="flash flash-ok"><?= e($ok) ?></div>
          <p style="text-align:center;margin-top:14px"><a href="index.php?p=login" class="btn btn-primary">&#9889; Jack In &rarr;</a></p>
        </div>
      <?php else:?>
      <form method="post">
        <div class="field">
          <span>Handle <span class="muted" style="text-transform:none;letter-spacing:0">(public username)</span></span>
          <input type="text" name="username" id="regHandle" maxlength="32" autocomplete="username" autofocus>
          <div class="fld-hint" id="hndHint">&nbsp;</div>
        </div>
        <div class="field">
          <span>Email <span class="muted" style="text-transform:none;letter-spacing:0">(used to log in)</span></span>
          <input type="email" name="email" autocomplete="email">
        </div>
        <div class="field">
          <span>Passkey <span class="muted" style="text-transform:none;letter-spacing:0">(8+ characters)</span></span>
          <div class="pass-wrap" style="max-width:none">
            <input type="password" name="password" id="regPass" autocomplete="new-password">
            <button type="button" class="pass-toggle" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈'">&#128065;</button>
          </div>
          <div class="pw-meter" id="pwMeter"><i></i><i></i><i></i><i></i></div>
          <div class="fld-hint" id="pwLbl">&nbsp;</div>
        </div>
        <div class="field">
          <span>Gender</span>
          <div class="gd-row">
            <label class="gd-chip m">
              <input type="radio" name="gender" value="M" style="width:auto;accent-color:#5fa8e8"> <span style="color:#5fa8e8">&#9794; Male</span>
            </label>
            <label class="gd-chip f">
              <input type="radio" name="gender" value="F" style="width:auto;accent-color:#ff75b5"> <span style="color:#ff75b5">&#9792; Female</span>
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">&#9889; Create Ghost</button>
      </form>
      <?php endif;?>
      <p class="auth-foot">Already have a ghost? <a href="index.php?p=login">Jack in.</a></p>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
/* Live handle + passkey feedback */
var hnd=document.getElementById('regHandle'), hHint=document.getElementById('hndHint');
if(hnd&&hHint){
  hnd.addEventListener('input',function(){
    var v=hnd.value;
    if(!v){ hHint.textContent=' '; hHint.className='fld-hint'; return; }
    if(/^[A-Za-z0-9_]{3,32}$/.test(v)){ hHint.textContent='✓ Handle looks good'; hHint.className='fld-hint good'; }
    else if(v.length<3){ hHint.textContent='Too short — 3 characters minimum'; hHint.className='fld-hint bad'; }
    else { hHint.textContent='✗ Letters, numbers, and underscore only'; hHint.className='fld-hint bad'; }
  });
}
var pw=document.getElementById('regPass'), meter=document.getElementById('pwMeter'), pwLbl=document.getElementById('pwLbl');
if(pw&&meter&&pwLbl){
  var COLS=['#ff5c5c','#e8a33d','#e8d44d','#3bcf63'];
  var LBLS=['Weak — 8+ characters needed','Fair','Good','Strong'];
  pw.addEventListener('input',function(){
    var v=pw.value, score=0;
    if(v.length>=8) score++;
    if(v.length>=12) score++;
    if(/[a-z]/.test(v)&&/[A-Z]/.test(v)) score++;
    if(/\d/.test(v)&&/[^A-Za-z0-9]/.test(v)) score++;
    score=Math.min(4,score);
    var segs=meter.querySelectorAll('i');
    for(var i=0;i<4;i++) segs[i].style.background = (v&&i<score) ? COLS[score-1] : 'rgba(255,255,255,.08)';
    if(!v){ pwLbl.textContent=' '; pwLbl.className='fld-hint'; }
    else if(v.length<8){ pwLbl.textContent=LBLS[0]; pwLbl.className='fld-hint bad'; }
    else { pwLbl.textContent=LBLS[score-1]||LBLS[1]; pwLbl.className='fld-hint'+(score>=3?' good':''); }
  });
}

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

  for(var s=0;s<70;s++){
    var tw=.32+.3*Math.sin(t/900+s*1.7);
    if(tw<=0) continue;
    c.fillStyle='rgba(220,240,255,'+tw.toFixed(2)+')';
    c.fillRect(rnd(s+11)*W, rnd(s+77)*hz*0.88, 1.4, 1.4);
  }

  var br=1+.08*Math.sin(t/2400);
  var hg=c.createRadialGradient(W/2,hz,0,W/2,hz,W*0.62*br);
  hg.addColorStop(0,'rgba(25,240,199,.11)');
  hg.addColorStop(.45,'rgba(255,45,149,.045)');
  hg.addColorStop(1,'rgba(0,0,0,0)');
  c.fillStyle=hg; c.fillRect(0,0,W,H);

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

  var v=c.createRadialGradient(W/2,H*0.45,60,W/2,H*0.45,Math.max(W,H)*0.55);
  v.addColorStop(0,'rgba(5,5,10,.40)'); v.addColorStop(1,'rgba(5,5,10,0)');
  c.fillStyle=v; c.fillRect(0,0,W,H);
}
requestAnimationFrame(loop);
})();
</script>
