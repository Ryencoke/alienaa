<?php /* pages/profile.php — public player profile */
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$pq = $pdo->prepare('SELECT * FROM players WHERE id = ?');
$pq->execute([$id]);
$prof = $pq->fetch();

if (!$prof) {
  echo '<div class="panel pf-panel"><h2>Profile</h2><p class="muted">No such ghost on the Grid.</p></div>';
  return;
}

$role  = $prof['role'] ?? 'member';
$ccol  = $prof['chat_color'] ?? '#c9d1e0';
$bio   = trim($prof['bio'] ?? '');
$col   = chat_color($role, '');
$rlbl  = role_label($role);
$isMe  = ((int)$prof['id'] === (int)($_SESSION['pid'] ?? 0));
$country = strtolower(trim($prof['country'] ?? ''));

$rec = ['win' => 0, 'loss' => 0];
try {
  $wq = $pdo->prepare('SELECT COUNT(*) FROM pvp_log WHERE winner_id = ?');
  $wq->execute([$id]); $rec['win'] = (int)$wq->fetchColumn();
  $lq = $pdo->prepare('SELECT COUNT(*) FROM pvp_log WHERE (attacker_id = ? OR defender_id = ?) AND winner_id != ?');
  $lq->execute([$id, $id, $id]); $rec['loss'] = (int)$lq->fetchColumn();
} catch (Throwable $e) {}

$postCount = 0;
try { $pc = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?'); $pc->execute([$id]); $postCount = (int)$pc->fetchColumn(); } catch (Throwable $e) {}

$msgCount = 0;
try { $mc = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE from_id = ?'); $mc->execute([$id]); $msgCount = (int)$mc->fetchColumn(); } catch (Throwable $e) {}

$casinoStats = ['games' => 0, 'net' => 0];
try {
  $cq = $pdo->prepare('SELECT COUNT(*) games, COALESCE(SUM(net),0) net FROM casino_log WHERE player_id = ?');
  $cq->execute([$id]); $csRow = $cq->fetch();
  if ($csRow) $casinoStats = ['games'=>(int)$csRow['games'],'net'=>(int)$csRow['net']];
} catch (Throwable $e) {}

$isFriend = false;
try {
  $fq = $pdo->prepare('SELECT 1 FROM friends WHERE player_id = ? AND friend_id = ?');
  $fq->execute([(int)($_SESSION['pid']??0), $id]); $isFriend = (bool)$fq->fetchColumn();
} catch (Throwable $e) {}

$profGuild = null;
try {
  $pgq = $pdo->prepare("SELECT s.name, s.tag, sm.rank, sm.joined_at FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?");
  $pgq->execute([$id]); $profGuild = $pgq->fetch();
} catch (Throwable $e) {}

$isOnline  = !empty($prof['last_seen']) && strtotime($prof['last_seen']) >= time() - 300;
$isBanned  = $role === 'banned';
$isSub     = is_subscribed($prof);
$totalWins = $rec['win'];
$totalLoss = $rec['loss'];
$winRate   = ($totalWins + $totalLoss) > 0 ? round($totalWins / ($totalWins + $totalLoss) * 100) : 0;
?>

<style>
#pf-canvas{position:absolute;inset:0;width:100%;height:100%;border-radius:9px}
.pf-hero-wrap{position:relative;overflow:hidden;border-radius:9px}
.pf-hero-wrap .prof-hero{position:relative;z-index:1}
.prof-username{text-shadow:0 1px 6px rgba(0,0,0,.8)}
@keyframes pfRing{0%{box-shadow:0 0 0 0 rgba(59,207,99,.5)}100%{box-shadow:0 0 0 9px rgba(59,207,99,0)}}
.pf-online-ring{animation:pfRing 1.8s ease-out infinite;border-radius:50%}
.pf-panel{transition:transform .12s,border-color .15s,box-shadow .15s}
.pf-panel:hover{transform:translateY(-2px);border-color:rgba(25,240,199,.25);box-shadow:0 4px 12px rgba(0,0,0,.3)}
</style>

<div class="panel pf-hero-wrap">
  <canvas id="pf-canvas"></canvas>
  <div class="prof-hero">
    <div class="prof-avatar <?= ($isOnline && !$isBanned) ? 'pf-online-ring' : '' ?>"><?= mb_strtoupper(mb_substr($prof['username'],0,1)) ?></div>
    <div class="prof-main">
      <?php $profMortality = (int)($prof['mortality'] ?? 0); ?>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="prof-username" style="color:<?= e($col) ?>"><?= e($prof['username']) ?></div>
        <?php echo flag_img($country); ?>
        <?= gender_icon($prof['gender'] ?? '') ?>
        <?php if ($isOnline && !$isBanned): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#3bcf63;box-shadow:0 0 6px #3bcf63" title="Online"></span><?php endif; ?>
        <?= mortality_icon($profMortality) ?>
        <?php if ($profMortality !== 0): ?>
        <span style="font-size:11px;color:<?= $profMortality > 0 ? '#e8d44d' : '#ff2d95' ?>;font-weight:700"><?= ($profMortality > 0 ? '+' : '') . $profMortality ?></span>
        <?php endif; ?>
      </div>

      <div class="prof-meta">
        <?php if ($role !== 'member'): ?>
          <span class="prof-meta-item" style="color:<?= e($col) ?>">&#128737; <em style="font-style:italic"><?= e($rlbl) ?></em></span>
        <?php endif; ?>
        <?php if ($isSub): ?>
          <span class="prof-meta-item" style="color:#e8d44d">&#9733; Subscriber</span>
        <?php endif; ?>
        <span class="prof-meta-item">&#127381; Level <?= (int)$prof['level'] ?></span>
        <?php if (!empty($prof['created_at'])): ?>
          <span class="prof-meta-item">&#128197; Joined <?= e(date('M Y', strtotime($prof['created_at']))) ?></span>
        <?php endif; ?>
        <?php
          $lastSeenTs = strtotime($prof['last_seen'] ?? '');
          $lsStr = $isOnline ? '<span style="color:#3bcf63">Online now</span>' : ($lastSeenTs ? e(date('M j, g:ia', $lastSeenTs)) : '');
        ?>
      </div>

      <?php if ($bio !== ''): ?>
        <div class="prof-bio"><?= e($bio) ?></div>
      <?php endif; ?>

      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($isMe): ?>
          <a href="index.php?p=account" class="btn btn-ghost btn-sm">&#9998; Edit Profile</a>
        <?php else: ?>
          <a href="index.php?p=messages&u=<?= (int)$prof['id'] ?>" class="btn btn-ghost btn-sm">&#9993; Message</a>
          <?php if (!$isFriend): ?>
            <form method="post" action="index.php?p=friends" style="display:inline;margin:0">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="friend_id" value="<?= (int)$prof['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm">&#43; Add Friend</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel pf-panel">
  <h3 style="margin-bottom:12px">&#128202; Stats</h3>
  <div class="prof-grid">
    <div class="prof-stat">
      <div class="val" style="color:var(--accent)"><?= (int)$prof['level'] ?></div>
      <div class="lbl">Level</div>
    </div>
    <div class="prof-stat">
      <div class="val"><?= number_format($postCount) ?></div>
      <div class="lbl">Board Posts</div>
    </div>
    <div class="prof-stat">
      <div class="val" style="color:var(--muted);font-size:16px">#<?= (int)$prof['id'] ?></div>
      <div class="lbl">Player ID</div>
    </div>
    <?php if ($profMortality !== 0): ?>
    <div class="prof-stat">
      <div class="val" style="color:<?= $profMortality > 0 ? '#e8d44d' : '#ff2d95' ?>"><?= mortality_icon($profMortality) ?><?= ($profMortality > 0 ? '+' : '') . $profMortality ?></div>
      <div class="lbl"><?= $profMortality > 0 ? 'Good' : 'Evil' ?> Alignment</div>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($lsStr !== '' || !empty($prof['birthday'])): ?>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:12px;color:var(--muted)">
    <?php if ($lsStr !== ''): ?><span>&#128337; <?= $lsStr ?></span><?php endif; ?>
    <?php if (!empty($prof['birthday'])): ?><span>&#127874; <?= e(date('M j', strtotime($prof['birthday']))) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($profGuild): ?>
<?php
  $SYN_RANK_COLORS_PROF = ['leader'=>'#e8d44d','coleader'=>'var(--neon2)','treasurer'=>'#3bcf63','armourer'=>'var(--accent)','librarian'=>'#9b8cff','advisor'=>'#e8a33d','member'=>'var(--muted)'];
  $SYN_RANK_LABELS_PROF = ['leader'=>'Leader','coleader'=>'Co-Leader','treasurer'=>'Treasurer','armourer'=>'Armourer','librarian'=>'Librarian','advisor'=>'Advisor','member'=>'Member'];
  $pGRank = $profGuild['rank'] ?? 'member';
?>
<div class="panel pf-panel">
  <h3 style="margin-bottom:10px">&#128101; Syndicate</h3>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:var(--accent)">[<?= e($profGuild['tag']) ?>] <?= e($profGuild['name']) ?></div>
      <div style="font-size:12px;color:<?= $SYN_RANK_COLORS_PROF[$pGRank] ?? 'var(--muted)' ?>;margin-top:2px;font-weight:700"><?= $SYN_RANK_LABELS_PROF[$pGRank] ?? ucfirst($pGRank) ?></div>
    </div>
  </div>
  <?php if ($profGuild['joined_at']): ?>
  <div style="font-size:11px;color:var(--muted)">Member since <?= e(date('M j, Y', strtotime($profGuild['joined_at']))) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel pf-panel">
  <h3 style="margin-bottom:10px">&#9876; Combat Record</h3>
  <?php if (($totalWins + $totalLoss) > 0): ?>
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
    <div style="text-align:center;min-width:48px">
      <div style="font-size:22px;font-weight:700;color:var(--accent)"><?= $totalWins ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Wins</div>
    </div>
    <div style="flex:1;height:8px;background:var(--panel2);border-radius:4px;overflow:hidden;border:1px solid var(--line)">
      <div style="height:100%;width:<?= $winRate ?>%;background:<?= $winRate >= 50 ? 'var(--accent)' : 'var(--neon2)' ?>;border-radius:4px"></div>
    </div>
    <div style="text-align:center;min-width:48px">
      <div style="font-size:22px;font-weight:700;color:var(--neon2)"><?= $totalLoss ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Losses</div>
    </div>
  </div>
  <div style="text-align:center;font-size:12px;color:var(--muted)"><?= $winRate ?>% win rate &middot; <?= $totalWins + $totalLoss ?> fights total</div>
  <?php else: ?>
  <p class="muted" style="text-align:center;margin:0">No combat history yet.</p>
  <?php endif; ?>
</div>

<?php
$badges = [];
if ($isSub) $badges[] = ['&#9733; Subscriber', 'gold'];
if ($role !== 'member') $badges[] = [e($rlbl), 'pink'];
if ($totalWins >= 100) $badges[] = ['&#9876; Centurion', 'teal'];
if ($totalWins >= 50)  $badges[] = ['&#9876; Fighter', 'teal'];
if ($postCount >= 100) $badges[] = ['&#128172; Forum Regular', 'teal'];
if ((int)$prof['level'] >= 10) $badges[] = ['&#127381; Veteran', 'teal'];
if ($casinoStats['net'] >= 10000) $badges[] = ['&#127920; High Roller', 'gold'];
if ($badges):
?>
<div class="panel pf-panel">
  <h3 style="margin-bottom:8px">&#127942; Badges</h3>
  <div class="prof-badges">
    <?php foreach ($badges as [$label, $type]): ?>
      <span class="prof-badge <?= $type ?>"><?= $label ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
$isStaffViewer = in_array($player['role'] ?? '', ['admin','manager','moderator'], true);
?>
<?php if (!$isMe): ?>
<div class="panel pf-panel">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#9889; Quick Actions</h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <a href="index.php?p=messages&u=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--accent);color:var(--accent);border-radius:6px;background:rgba(25,240,199,.05)">&#9993; Send Message</a>
    <a href="index.php?p=trade&with=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid #e8a33d;color:#e8a33d;border-radius:6px;background:rgba(232,163,61,.05)">&#128260; Secure Trade &amp; Transfer</a>
    <?php if (!$isBanned): ?>
    <a href="index.php?p=pvp&target=<?= urlencode($prof['username']) ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--neon2);color:var(--neon2);border-radius:6px;background:rgba(255,45,149,.04)">&#9876; Attack</a>
    <?php endif; ?>
    <?php
      $hasJournal = false;
      try { $hjq = $pdo->prepare('SELECT 1 FROM settings WHERE k=? AND v!=?'); $hjq->execute(['journal:'.$prof['id'],  '']); $hasJournal = (bool)$hjq->fetchColumn(); } catch(Throwable $e){}
    ?>
    <?php if ($hasJournal): ?>
    <a href="index.php?p=journal&id=<?= (int)$prof['id'] ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--line);color:var(--muted);border-radius:6px;background:var(--panel2)">&#128214; Journal</a>
    <?php endif; ?>
    <?php if ($isFriend): ?>
    <form method="post" action="index.php?p=friends" style="display:inline;margin:0">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="friend_id" value="<?= (int)$id ?>">
      <button type="submit" style="padding:7px 16px;font-size:12px;border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:6px;background:rgba(255,45,149,.04);cursor:pointer">&#10005; Remove Friend</button>
    </form>
    <?php else: ?>
    <form method="post" action="index.php?p=friends" style="display:inline;margin:0">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="friend_id" value="<?= (int)$id ?>">
      <button type="submit" style="padding:7px 16px;font-size:12px;border:1px solid var(--line);color:var(--muted);border-radius:6px;background:var(--panel2);cursor:pointer">&#43; Add Friend</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($isStaffViewer): ?>
<div class="panel" style="border:1px solid rgba(226,59,59,.25);background:rgba(226,59,59,.03)">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#e23b3b">&#128736; Admin Tools &mdash; <?= e($prof['username']) ?></h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <a href="index.php?p=admin&sec=players&find=<?= urlencode($prof['username']) ?>" style="padding:6px 14px;font-size:11px;border:1px solid rgba(226,59,59,.3);border-radius:5px;color:#e23b3b;text-decoration:none;background:rgba(226,59,59,.06)">&#128737; Edit Player</a>
    <a href="index.php?p=admin&sec=txlog&pid=<?= $id ?>" style="padding:6px 14px;font-size:11px;border:1px solid var(--line);border-radius:5px;color:var(--muted);text-decoration:none;background:var(--panel2)">&#128178; TX Log</a>
    <a href="index.php?p=admin&sec=combat&pid=<?= $id ?>" style="padding:6px 14px;font-size:11px;border:1px solid var(--line);border-radius:5px;color:var(--muted);text-decoration:none;background:var(--panel2)">&#9876; Combat Log</a>
    <a href="index.php?p=jail&target=<?= $id ?>" style="padding:6px 14px;font-size:11px;border:1px solid rgba(255,45,149,.3);border-radius:5px;color:var(--neon2);text-decoration:none;background:rgba(255,45,149,.04)">&#128274; Jail</a>
  </div>
  <div style="font-size:11px;color:var(--muted)">
    Role: <em style="font-style:italic;color:<?= e(chat_color($role,'')) ?>"><?= e(role_label($role)?:'Member') ?></em> &middot;
    ID: <?= $id ?> &middot;
    Registered: <?= e(date('M j Y',strtotime($prof['created_at']??'now'))) ?> &middot;
    Last seen: <?= $isOnline ? '<span style="color:#3bcf63">Online now</span>' : e(date('M j g:ia',strtotime($prof['last_seen']??'now'))) ?>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
'use strict';
/* Holo ID-card backdrop behind the profile hero */
var pf=document.getElementById('pf-canvas');
if(!pf) return;
var c=pf.getContext('2d');
var wrap=pf.parentNode;
var W=720, H=Math.max(120,wrap.offsetHeight||150);
var dpr=Math.min(2,window.devicePixelRatio||1);
pf.width=W*dpr; pf.height=H*dpr;
c.scale(dpr,dpr);
var HEXC='0123456789ABCDEF';
var ticks=[];
for(var i=0;i<5;i++) ticks.push({y:16+i*((H-30)/5),txt:'',next:0});

function loop(t){
  if(!document.body.contains(pf)) return;
  requestAnimationFrame(loop);
  c.clearRect(0,0,W,H);
  var bg=c.createLinearGradient(0,0,W,H);
  bg.addColorStop(0,'#0b0c14'); bg.addColorStop(1,'#0e0f1a');
  c.fillStyle=bg; c.fillRect(0,0,W,H);

  // micro grid
  c.strokeStyle='rgba(25,240,199,.04)';
  for(var gx=0;gx<W;gx+=22){ c.beginPath(); c.moveTo(gx,0); c.lineTo(gx,H); c.stroke(); }
  for(var gy=0;gy<H;gy+=22){ c.beginPath(); c.moveTo(0,gy); c.lineTo(W,gy); c.stroke(); }

  // big ghost watermark (right)
  c.save();
  c.translate(W-86,H/2);
  c.globalAlpha=.06+.02*Math.sin(t/1200);
  c.font='900 92px monospace'; c.textAlign='center'; c.textBaseline='middle';
  c.fillStyle='#19f0c7';
  c.fillText('☠',0,4);
  c.restore();

  // registry microtext + scrolling hex ticks (right column)
  c.font='700 8px monospace'; c.textAlign='right';
  c.fillStyle='rgba(255,255,255,.18)';
  c.fillText('GRID ID REGISTRY // SECTOR 9',W-14,12);
  c.font='9px monospace';
  ticks.forEach(function(k){
    if(t>k.next){
      k.next=t+400+Math.random()*900;
      k.txt='';
      for(var j=0;j<10;j++) k.txt+=HEXC[Math.floor(Math.random()*16)];
    }
    c.fillStyle='rgba(25,240,199,.13)';
    c.fillText(k.txt,W-14,28+k.y);
  });

  // holo sheen sweep
  var sx=((t/30)%(W+260))-130;
  var sh=c.createLinearGradient(sx-70,0,sx+70,0);
  sh.addColorStop(0,'rgba(255,255,255,0)'); sh.addColorStop(.5,'rgba(255,255,255,.035)'); sh.addColorStop(1,'rgba(255,255,255,0)');
  c.fillStyle=sh; c.fillRect(0,0,W,H);

  // scanline
  var sy=(t/50)%H;
  c.fillStyle='rgba(25,240,199,.05)'; c.fillRect(0,sy,W,1.4);

  // left fade so the avatar/name stay readable
  var lv=c.createLinearGradient(0,0,W*.6,0);
  lv.addColorStop(0,'rgba(8,8,14,.75)'); lv.addColorStop(1,'rgba(8,8,14,0)');
  c.fillStyle=lv; c.fillRect(0,0,W*.6,H);
}
requestAnimationFrame(loop);
})();
</script>
