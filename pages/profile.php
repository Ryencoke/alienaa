<?php /* pages/profile.php — public player profile */
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$pq = $pdo->prepare('SELECT * FROM players WHERE id = ?');
$pq->execute([$id]);
$prof = $pq->fetch();

if (!$prof) {
  echo '<div class="panel"><h2>Profile</h2><p class="muted">No such ghost on the Grid.</p></div>';
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
  $rq = $pdo->prepare('SELECT outcome, COUNT(*) c FROM combat_log WHERE player_id = ? GROUP BY outcome');
  $rq->execute([$id]);
  foreach ($rq as $r) { if (isset($rec[$r['outcome']])) $rec[$r['outcome']] = (int)$r['c']; }
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

<div class="panel">
  <div class="prof-hero">
    <div class="prof-avatar"><?= mb_strtoupper(mb_substr($prof['username'],0,1)) ?></div>
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

<div class="panel">
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
<div class="panel">
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

<div class="panel">
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
<div class="panel">
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
<div class="panel">
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
    <a href="index.php?p=friends&unfriend=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:6px;background:rgba(255,45,149,.04)">&#10005; Remove Friend</a>
    <?php else: ?>
    <a href="index.php?p=friends&add=<?= $id ?>" style="padding:7px 16px;font-size:12px;text-decoration:none;border:1px solid var(--line);color:var(--muted);border-radius:6px;background:var(--panel2)">&#43; Add Friend</a>
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
