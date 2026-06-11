<?php /* pages/cityhall.php — Admin Spire (City Hall) hub */
$pdo = db();
$sec = $_GET['sec'] ?? '';

/* ============================ PRESIDENTIAL OFFICES (staff roster) ============================ */
if ($sec === 'offices') {
  $staff = $pdo->query("SELECT id, username, role, chat_color FROM players
                        WHERE role <> 'member'
                        ORDER BY FIELD(role,'manager','admin','moderator','chatmod'), username")->fetchAll();
  $groups = ['manager'=>[], 'admin'=>[], 'moderator'=>[], 'chatmod'=>[]];
  foreach ($staff as $s) if (isset($groups[$s['role']])) $groups[$s['role']][] = $s;
  $blurbs = [
    'manager'   => ['Managers',  'The benevolent dictators. They run the Sprawl.'],
    'admin'     => ['Admins',    'Handle day-to-day player issues and account problems.'],
    'moderator' => ['Moderators','Keep the message boards civil and help new ghosts.'],
    'chatmod'   => ['Chat Mods', 'Watch the Public Channel for spam and abuse.'],
  ];
  ?>
  <div class="panel"><h2>Presidential Offices</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a> &middot; Each staff member has a specific role &mdash; contact the right one.</p>
  </div>
  <?php foreach ($blurbs as $rk => $b): ?>
  <div class="panel">
    <h3><?= e($b[0]) ?></h3>
    <p class="muted" style="margin-top:-4px"><?= e($b[1]) ?></p>
    <?php if ($groups[$rk]): foreach ($groups[$rk] as $s): $c = chat_color($s['role'], $s['chat_color']); ?>
      <div style="padding:2px 0"><a href="index.php?p=profile&id=<?= (int)$s['id'] ?>" style="color:<?= e($c) ?>;font-weight:bold"><?= e($s['username']) ?></a></div>
    <?php endforeach; else: ?>
      <p class="muted">None appointed yet.</p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php return;
}

/* ============================ RECORDS HALL (stats) ============================ */
if ($sec === 'records') {
  $total  = (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
  $online = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
  $week   = 0; $male = null; $female = null;
  try { $week = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}
  try {
    $male   = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE avatar = 1')->fetchColumn();
    $female = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE avatar = 2')->fetchColumn();
  } catch (Throwable $e) {}
  $subs = null;
  try { $subs = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE sub_until >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
  ?>
  <div class="panel"><h2>&#128202; Records Hall</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a> &middot; Live census of the Sprawl.</p>
    <?php
      $censusTiles = [
        ['Citizens',      number_format($total),  'var(--accent)', 'ghosts call the Sprawl home'],
        ['Online Now',    number_format($online), '#3bcf63',       'jacked in this moment'],
        ['New This Week', number_format($week),   '#e8a33d',       'first jacked in within 7 days'],
      ];
      if ($subs !== null)  $censusTiles[] = ['Subscribers', number_format($subs), '#e8d44d', 'active subscriptions'];
      if ($male !== null)  $censusTiles[] = ['Drifters / Netghosts', number_format($male) . ' / ' . number_format($female), '#a66de8', 'avatar census'];
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-top:6px">
      <?php foreach ($censusTiles as [$ctL, $ctV, $ctC, $ctS]): ?>
      <div style="position:relative;overflow:hidden;background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:13px 14px;text-align:center">
        <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $ctC ?>,transparent)"></div>
        <div style="font-family:'Orbitron',sans-serif;font-size:19px;font-weight:700;color:<?= $ctC ?>"><?= $ctV ?></div>
        <div style="font-size:11px;color:var(--text);font-weight:700;margin-top:3px"><?= $ctL ?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= $ctS ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php return;
}

/* ============================ CRYOGENIC STORAGE ============================ */
if ($sec === 'cryo') {
  $pid = $_SESSION['pid']; $today = date('Y-m-d'); $msg = '';
  $sub = is_subscribed($player);
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'freeze') {
    if (!$sub) { $msg = 'Cryo is for subscribers only.'; }
    else {
      $days = max(3, (int)($_POST['days'] ?? 3));
      $until = date('Y-m-d', strtotime("+{$days} days"));
      $pdo->prepare('UPDATE players SET cryo_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Frozen until {$until}."; $player = current_player();
    }
  }
  $frozen = !empty($player['cryo_until']) && $player['cryo_until'] >= $today;
  ?>
  <div class="panel"><h2>Cryogenic Storage</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a></p>
    <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
    <p>Freeze your ghost while you're away. Minimum 3 days. Once PvP and subscription-day loss are in, freezing protects you from both.</p>
    <?php if ($frozen): ?>
      <p>Status: <b>frozen until <?= e($player['cryo_until']) ?></b>.</p>
    <?php elseif ($sub): ?>
      <form method="post"><input type="hidden" name="action" value="freeze">
        <label>Days to freeze (min 3)</label>
        <p><input type="number" name="days" min="3" value="3" style="max-width:120px"></p>
        <p><button type="submit">Freeze</button></p>
      </form>
    <?php else: ?>
      <p class="flash">Subscribers only. Grab a subscription at <a href="index.php?p=exchange">The Exchange</a>.</p>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ GAME LAWS ============================ */
if ($sec === 'laws') {
  ?>
  <div class="panel"><h2>Game Laws</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a></p>
    <p>This page is the single source of truth for rules in Sprawl-9. If a rule isn't written here, it isn't a rule. Anything posted by players claiming otherwise is null and void.</p>
    <h3>Conduct</h3>
    <ul>
      <li>No harassment, hate speech, or threats toward other ghosts.</li>
      <li>No spamming the Public Channel, boards, or private messages.</li>
      <li>No impersonating staff or other players.</li>
      <li>One account per person unless staff says otherwise.</li>
    </ul>
    <h3>Fair Play</h3>
    <ul>
      <li>No bots, scripts, or automation against the game.</li>
      <li>No exploiting bugs &mdash; report them on the Bug Reports board instead.</li>
      <li>No real-money trading of in-game creds, items, or accounts.</li>
    </ul>
    <h3>Enforcement</h3>
    <p>Breaking these can mean a communication ban, stat reset, account freeze, or a permanent ban &mdash; at staff discretion, scaled to severity. A reasonable request from staff is an order; challenge it afterward via the boards, but follow it at the time.</p>
    <p class="muted" style="font-size:11px">Staff reserve the right to update these laws. Check back after major updates.</p>
  </div>
  <?php return;
}

/* ============================ GAME HELP ============================ */
if ($sec === 'help') {
  ?>
  <div class="panel"><h2>Game Help</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a> &middot; New to the Sprawl? Start here.</p>
    <h3>The Basics</h3>
    <p>You're a drifter who jacks in with nothing. Build <b>Creds</b>, raise your <b>Level</b> (via XP), and climb the rankings. Your left-side card shows your money and meters: <b>Health</b>, <b>XP</b>, <b>Signal</b>, and <b>Drive</b> (spent on skills).</p>
    <h3>The Core Loop</h3>
    <ul>
      <li><b>Datacore</b> (in The Sprawl) &mdash; spend Drive to learn skills that unlock deeper activities.</li>
      <li><b>Foundry Sector</b> &mdash; scavenge raw materials, then craft items (gated by skills).</li>
      <li><b>Transit Hub</b> &mdash; run cargo for creds and mine ore (gated by Drone Piloting).</li>
      <li><b>Bazaar</b> &mdash; sell what you craft/mine to other players, or buy gear.</li>
      <li><b>Bank</b> &mdash; stash creds, transfer to other players, or take a loan.</li>
    </ul>
    <h3>Action</h3>
    <ul>
      <li><b>Combat Sim</b> (The Firewall) &mdash; fight drones for creds, XP, and loot. Heal with Field Patch Kits crafted at the Foundry.</li>
      <li><b>The Lucky Daemon</b> (The Undervolt) &mdash; Dice, Neon Reels, and Blackjack. The house has an edge; bet what you can lose.</li>
    </ul>
    <h3>Social</h3>
    <ul>
      <li><b>Message Boards</b> &mdash; threaded discussion; vote on posts.</li>
      <li><b>Public Channel</b> &mdash; live chat. Use <code>[b]bold[/b]</code> / <code>[i]italics[/i]</code>; set your name color in Account.</li>
      <li><b>Messages</b> &mdash; private one-on-one conversations with other ghosts.</li>
      <li><b>Profiles</b> &mdash; click any player's name to see their stats and message them.</li>
    </ul>
  </div>
  <?php return;
}

/* ============================ HUB MENU ============================ */
// Mini census for the header strip
$chTotal = $chOnline = $chWeek = 0;
try {
  $chr = $pdo->query("SELECT COUNT(*) t,
    SUM(last_seen >= (NOW() - INTERVAL 5 MINUTE)) o,
    SUM(created_at >= (NOW() - INTERVAL 7 DAY)) w FROM players")->fetch();
  if ($chr) { $chTotal = (int)$chr['t']; $chOnline = (int)$chr['o']; $chWeek = (int)$chr['w']; }
} catch (Throwable $e) {}
?>
<style>
#ch-canvas{display:block;width:100%;height:118px;border-radius:9px 9px 0 0}
#ch-head h2{font-family:'Orbitron',sans-serif;letter-spacing:2px;text-shadow:0 0 14px rgba(25,240,199,.35)}
.ch-card{position:relative;overflow:hidden;text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:9px;transition:transform .12s,border-color .15s,box-shadow .15s;animation:chIn .3s ease-out backwards}
@keyframes chIn{0%{opacity:0;transform:translateY(8px)}100%{opacity:1;transform:none}}
.ch-card:hover{transform:translateY(-2px);border-color:var(--ch-col,var(--accent));box-shadow:0 4px 14px rgba(0,0,0,.3),0 0 12px var(--ch-glow,rgba(25,240,199,.1))}
.ch-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--ch-col,var(--accent)),transparent)}
.ch-card .ic{font-size:22px;transition:transform .15s,text-shadow .15s}
.ch-card:hover .ic{transform:scale(1.12);text-shadow:0 0 12px var(--ch-col,var(--accent))}
.ch-census{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);background:rgba(6,6,14,.78);border:1px solid var(--line);border-radius:14px;padding:4px 12px;backdrop-filter:blur(3px)}
.ch-census b{font-family:'Orbitron',sans-serif;color:var(--accent)}
</style>

<div class="panel" id="ch-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="ch-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:12px;pointer-events:none">
      <h2 style="margin:0">&#127963; CITY HALL</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">The Grid Authority runs the Sprawl on paper. In practice, it runs on bribes and downtime.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
      <span class="ch-census">&#128101; <b><?= number_format($chTotal) ?></b> citizens</span>
      <span class="ch-census" style="border-color:rgba(59,207,99,.3)">&#128994; <b style="color:#3bcf63"><?= number_format($chOnline) ?></b> online</span>
      <span class="ch-census">&#10024; <b><?= number_format($chWeek) ?></b> new / 7d</span>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
  <?php
  $chCards = [
    ['index.php?p=cityhall&sec=offices', '&#128737;', 'Presidential Offices', 'Meet the staff — admins, mods, and who runs what.',       '#19f0c7'],
    ['index.php?p=cityhall&sec=records', '&#128202;', 'Records Hall',         'Live census of the Sprawl — population and activity stats.','#4d9be8'],
    ['index.php?p=cityhall&sec=cryo',    '&#10052;',  'Cryogenic Storage',    'Freeze your ghost while you\'re away. Subscribers only.',   '#a66de8'],
    ['index.php?p=updates',              '&#128221;', 'City Planning',        'Game updates, patch notes, and upcoming changes.',          '#e8a33d'],
    ['index.php?p=cityhall&sec=laws',    '&#9878;',   'Game Laws',            'The rules. If it\'s not written here, it\'s not a rule.',   '#ff2d95'],
    ['index.php?p=cityhall&sec=help',    '&#128218;', 'Game Help',            'New to the Sprawl? Start here for a full breakdown.',       '#3bcf63'],
  ];
  foreach ($chCards as $ci => [$chUrl, $chIc, $chTitle, $chDesc, $chCol]): ?>
  <a href="<?= $chUrl ?>" class="ch-card" style="--ch-col:<?= $chCol ?>;--ch-glow:<?= $chCol ?>22;animation-delay:<?= $ci * 50 ?>ms">
    <span class="ic"><?= $chIc ?></span>
    <div style="font-weight:700;font-size:13px;color:var(--text)"><?= $chTitle ?></div>
    <div style="font-size:12px;color:var(--muted)"><?= $chDesc ?></div>
  </a>
  <?php endforeach; ?>
</div>

<script>
(function(){
'use strict';
/* ── Civic spire header: stepped tower, beacon, holo banners, data motes ── */
var cc=document.getElementById('ch-canvas');
if(!cc) return;
var c=cc.getContext('2d');
var CW=560, CH=118;
var dpr=Math.min(2,window.devicePixelRatio||1);
cc.width=CW*dpr; cc.height=CH*dpr;
c.scale(dpr,dpr);
var motes=[];
for(var i=0;i<18;i++) motes.push({x:Math.random()*CW,y:Math.random()*CH,v:.1+Math.random()*.22,p:Math.random()*9});

function chLoop(t){
  if(!document.body.contains(cc)) return;
  requestAnimationFrame(chLoop);
  c.clearRect(0,0,CW,CH);
  var bg=c.createLinearGradient(0,0,0,CH);
  bg.addColorStop(0,'#090a12'); bg.addColorStop(1,'#0d0e1a');
  c.fillStyle=bg; c.fillRect(0,0,CW,CH);

  // rising data motes
  for(var mi=0;mi<motes.length;mi++){
    var M=motes[mi];
    M.y-=M.v;
    if(M.y<-4){ M.y=CH+4; M.x=Math.random()*CW; }
    c.fillStyle='rgba(25,240,199,'+(0.10+0.10*Math.sin(t/700+M.p))+')';
    c.fillRect(M.x,M.y,1.6,1.6);
  }

  // stepped civic spire (center-right)
  var sx=CW-130, base=CH-8;
  c.fillStyle='#141528'; c.strokeStyle='rgba(255,255,255,.1)';
  [[64,34],[48,30],[32,26],[18,20]].forEach(function(lvl,i2){
    var w2=lvl[0], h2=lvl[1];
    var y2=base; for(var k=0;k<=i2;k++) y2-= [[34],[30],[26],[20]][k][0];
    c.fillRect(sx-w2/2,y2,w2,[[34],[30],[26],[20]][i2][0]);
    c.strokeRect(sx-w2/2+.5,y2+.5,w2,[[34],[30],[26],[20]][i2][0]);
  });
  // lit windows on the spire
  for(var wy2=0;wy2<8;wy2++){
    if(((t/900+wy2*1.7)%5)<2.6){
      c.fillStyle='rgba(25,240,199,.4)';
      c.fillRect(sx-18+((wy2*13)%30),CH-22-wy2*11,3.4,3.4);
    }
  }
  // antenna + beacon
  c.strokeStyle='rgba(255,255,255,.3)';
  c.beginPath(); c.moveTo(sx,base-110); c.lineTo(sx,base-128); c.stroke();
  var bp=.5+.5*Math.sin(t/420);
  c.fillStyle='#ff2d95'; c.shadowColor='#ff2d95'; c.shadowBlur=9*bp;
  c.beginPath(); c.arc(sx,base-130,2.4+bp,0,Math.PI*2); c.fill();
  c.shadowBlur=0;

  // holo banners (left of spire) — waving ribbons
  [['#19f0c7',CW-230],['#e8a33d',CW-196]].forEach(function(bn,bi){
    c.strokeStyle='rgba(255,255,255,.2)';
    c.beginPath(); c.moveTo(bn[1],CH-8); c.lineTo(bn[1],34); c.stroke();
    c.fillStyle=bn[0]; c.globalAlpha=.22+.08*Math.sin(t/600+bi);
    c.beginPath();
    c.moveTo(bn[1],36);
    for(var yy=0;yy<=26;yy+=2){
      c.lineTo(bn[1]+18+Math.sin(t/300+yy/5+bi*2)*3, 36+yy);
    }
    c.lineTo(bn[1],62); c.closePath(); c.fill();
    c.globalAlpha=1;
  });

  // ground sheen + steps hint
  c.fillStyle='rgba(255,255,255,.04)'; c.fillRect(0,CH-8,CW,1.4);
  c.fillStyle='rgba(25,240,199,.05)'; c.fillRect(0,CH-5,CW,5);
}
requestAnimationFrame(chLoop);
})();
</script>
