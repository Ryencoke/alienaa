<?php
require 'config.php';
require 'lib.php';

// ── Site password gate ──────────────────────────────────────────────────────
$SITE_PASSWORD = 'durpin';
if (!empty($_POST['__site_pw'])) {
  if (hash_equals($SITE_PASSWORD, (string)$_POST['__site_pw'])) {
    $_SESSION['site_pw_ok'] = true;
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'index.php')); exit;
  }
  $__site_pw_err = true;
}
if (empty($_SESSION['site_pw_ok'])) {
  ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Sprawl-9</title>
<style>
  html,body{height:100%;margin:0}
  body{background:#0a0a12;color:#c9d1e0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center}
  .gate{background:#12121e;border:1px solid #1e2236;border-radius:12px;padding:32px 28px;width:280px;text-align:center;box-shadow:0 0 40px rgba(25,240,199,.08)}
  .gate h1{font-size:18px;margin:0 0 4px;color:#19f0c7;letter-spacing:1px}
  .gate p{font-size:12px;color:#5d6680;margin:0 0 20px}
  .gate input{width:100%;box-sizing:border-box;background:#0a0a12;border:1px solid #1e2236;color:#c9d1e0;border-radius:6px;padding:10px 12px;font-size:14px;margin-bottom:12px;outline:none}
  .gate input:focus{border-color:#19f0c7}
  .gate button{width:100%;background:#19f0c7;color:#0a0a12;border:0;border-radius:6px;padding:10px;font-size:14px;font-weight:700;cursor:pointer}
  .gate .err{color:#ff2d95;font-size:12px;margin-bottom:12px}
</style></head><body>
  <form class="gate" method="post" autocomplete="off">
    <?php if (!empty($__site_pw_err)): ?><div class="err">Incorrect password.</div><?php endif; ?>
    <input type="password" name="__site_pw" placeholder="Enter password" autofocus>
    <button type="submit">Enter</button>
  </form>
</body></html><?php
  exit;
}

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
// Role override for admins viewing as another role. Clamp to the REAL admin's
// rank so "view as" can only ever DOWNGRADE capability — otherwise an admin
// could impersonate a member, preview as 'manager', and inherit manager powers.
if ($isImpersonating && !empty($_SESSION['role_override']) && $player && $realPlayer) {
  $rank = ['member'=>0,'chatmod'=>1,'moderator'=>2,'admin'=>3,'manager'=>4];
  $realRank = $rank[$realPlayer['role'] ?? 'member'] ?? 0;
  $ovRank   = $rank[$_SESSION['role_override']] ?? 0;
  $player['role'] = ($ovRank <= $realRank) ? $_SESSION['role_override'] : ($realPlayer['role'] ?? 'member');
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

// Game AJAX gateway — pages with in-page JSON endpoints (mining, vats, exchange)
// must run BEFORE any HTML is emitted, or their JSON arrives prefixed with the
// page layout and the client sees "Network error".
if ($player && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (!empty($_POST['mine_ajax']) || !empty($_POST['vat_ajax']) || !empty($_POST['exch_ajax'])
        || ($p === 'friends' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])))) {
  $__ajaxStaff = in_array($player['role'] ?? 'member', ['chatmod','moderator','admin','manager'], true);
  if ($jailRecord && !$__ajaxStaff) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'err' => 'Account suspended.']);
    exit;
  }
  $__ajaxFile = __DIR__ . "/pages/{$p}.php";
  if (preg_match('/^[a-z]+$/', $p) && file_exists($__ajaxFile)) {
    try { require $__ajaxFile; } catch (Throwable $e) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'err' => 'Server error.']);
    }
  }
  // The page's AJAX branch exits on its own; reaching here means it didn't match.
  if (!headers_sent()) header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'err' => 'Bad request.']);
  exit;
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
      $__driveCap = is_subscribed($player) ? 1500 : 500;
      db()->prepare('UPDATE players SET integrity = integrity_max, `signal` = signal_max,
        cycles = LEAST(?, cycles + 250) WHERE id = ?')->execute([$__driveCap, $player['id']]);
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
  $snVal = json_encode(['note'=>$sn, 'by'=>$player['username'], 'at'=>date('M j Y, g:ia')]);
  try { db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$noteKey, $snVal]); } catch (Throwable $e) {}
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
<?php
  $_hideTopbar = false;
  try { $htq = db()->prepare('SELECT v FROM settings WHERE k=?'); $htq->execute(['hide_topbar:'.$player['id']]); $_hideTopbar = $htq->fetchColumn() === '1'; } catch (Throwable $e) {}
  $__unreadMsgs = 0;
  try { $__uq=db()->prepare('SELECT COUNT(*) FROM messages WHERE to_id=? AND is_read=0'); $__uq->execute([$player['id']]); $__unreadMsgs=(int)$__uq->fetchColumn(); } catch(Throwable $e){}
  $__unreadNotifs = 0;
  try { $__uq=db()->prepare('SELECT COUNT(*) FROM player_notifications WHERE player_id=? AND is_read=0'); $__uq->execute([$player['id']]); $__unreadNotifs=(int)$__uq->fetchColumn(); } catch(Throwable $e){}
  $__unreadCount = $__unreadMsgs + $__unreadNotifs;
?>
<nav class="topbar<?= $_hideTopbar ? ' topbar-hidden' : '' ?>" id="topnav">
  <button id="nav-toggle" class="nav-toggle" aria-label="Open menu" title="Menu">&#9776;</button>
  <?php $__nl = nav_links(); foreach (player_sidebar($player) as $__k): if (!isset($__nl[$__k])) continue;
    $__active = (strpos($__nl[$__k][1], 'p='.$p) !== false);
    $__isMsgLink = ($__k === 'messages' && $__unreadMsgs > 0); ?>
  <a href="<?= $__nl[$__k][1] ?>" class="<?= $__active?'active':'' ?>" data-navkey="<?= e($__k) ?>"<?= $__isMsgLink ? ' style="font-weight:700"' : '' ?>><?= e($__nl[$__k][0]) ?><?php if ($__isMsgLink): ?> <span style="background:var(--neon2);color:#0a0a12;border-radius:10px;font-size:9px;padding:1px 5px;font-weight:700;vertical-align:middle;margin-left:2px"><?= $__unreadMsgs ?></span><?php endif; ?></a>
  <?php endforeach; ?>
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
        $isActive = (strpos($nl[$k][1], 'p='.$p) !== false);
        $hasNotif   = ($k === 'home'     && $__unreadCount > 0);
        $hasMsgNotif = ($k === 'messages' && $__unreadMsgs > 0);
        $linkBold = $hasNotif || $hasMsgNotif;
      ?>
        <li data-navkey="<?= e($k) ?>"<?= $isActive ? ' class="active"' : '' ?>>
          <a href="<?= $nl[$k][1] ?>"<?= $linkBold ? ' style="font-weight:700"' : '' ?>>
            <?php if ($hasMsgNotif): ?>
              <?= e($nl[$k][0]) ?> <span style="background:var(--neon2);color:#0a0a12;border-radius:10px;font-size:10px;padding:1px 6px;font-weight:700;vertical-align:middle;margin-left:3px"><?= $__unreadMsgs ?></span>
            <?php elseif ($hasNotif): ?>
              <?= e($nl[$k][0]) ?> <span style="background:var(--neon2);color:#0a0a12;border-radius:10px;font-size:10px;padding:1px 6px;font-weight:700;vertical-align:middle;margin-left:3px"><?= $__unreadCount ?></span>
            <?php else: ?>
              <?= e($nl[$k][0]) ?>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
      <?php if ($isStaff): ?><li data-navkey="admin"<?= $p==='admin'?' class="active"':'' ?>><a href="index.php?p=admin">Admin</a></li><?php endif; ?>
    </ul>
    <div class="sidebar-cta">
      <a href="index.php?p=account&sec=premium" class="cta-btn cta-sub">&#9733; Subscribe</a>
      <a href="index.php?p=account&sec=shards" class="cta-btn cta-shards">&#9670; Buy Shards</a>
      <a href="index.php?p=logout" class="cta-btn" style="margin-top:4px">&#10006; Logout</a>
    </div>
  </aside>

  <main class="center">
<?php
  if ($isStaff) {
    $snote = ''; $snoteAuthor = ''; $snoteEditText = '';
    try {
      $s = db()->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$noteKey]); $sv = $s->fetchColumn();
      if ($sv !== false && $sv !== '') {
        $sj = json_decode($sv, true);
        if (is_array($sj)) { $snote = $sj['note'] ?? ''; $snoteAuthor = ($sj['by'] ?? '') . (!empty($sj['at']) ? ' · ' . $sj['at'] : ''); $snoteEditText = $snote; }
        else { $snote = $sv; $snoteEditText = $sv; } // legacy plain text
      }
    } catch (Throwable $e) {}
    $snoteView = $snote !== '' ? nl2br(e($snote)) . ($snoteAuthor ? '<div style="margin-top:5px;font-size:10px;color:var(--muted);border-top:1px solid rgba(255,255,255,.06);padding-top:4px">&#9999; ' . e($snoteAuthor) . '</div>' : '') : '<span class="muted">+ Add note for this page</span>';
    echo '<div class="staffnote"><div class="staffnote-head">&#128204; <b>Staff Note</b>'
       . '<a href="#" onclick="var n=this.closest(\'.staffnote\');n.querySelector(\'.staffnote-edit\').style.display=\'block\';n.querySelector(\'.staffnote-view\').style.display=\'none\';return false;" style="float:right;font-size:11px">[Edit]</a></div>'
       . '<div class="staffnote-view">' . $snoteView . '</div>'
       . '<form class="staffnote-edit" method="post" style="display:none;margin-top:6px"><input type="hidden" name="__staffnote" value="1">'
       . '<textarea name="staffnote" style="width:100%;min-height:50px" placeholder="Leave empty to clear...">' . e($snoteEditText) . '</textarea>'
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
  // Already authenticated: the auth pages don't belong inside the game shell
  // (a POST there would re-run login/session_regenerate_id mid-layout).
  if (in_array($p, ['login','register','landing'], true)) {
    echo '<script>location.replace("index.php?p=home");</script>';
    echo '<div class="panel"><p class="muted">Already jacked in. <a href="index.php?p=home">Continue &rarr;</a></p></div>';
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
  } // end auth-page redirect else
  } // end jail else block
?>
  <div style="padding:4px 0 10px;text-align:left">
    <button onclick="history.back()" style="font-size:11px;padding:5px 14px;background:var(--panel2);border:1px solid var(--line);color:var(--muted);border-radius:5px;cursor:pointer">&#8592; Back</button>
  </div>
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
    <div class="panel" id="quickchat-panel">
      <h3>Public Channel <a href="index.php?p=chat" style="font-size:11px;float:right;font-weight:normal">[full chat]</a></h3>
      <div id="chatfeed" style="max-height:200px;overflow-y:auto;font-size:12px"></div>
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
          line.appendChild(t);
          var who=document.createElement('a'); who.href='index.php?p=profile&id='+m.id;
          who.textContent=m.username+': '; who.style.color=m.name_color||'#c9d1e0'; who.style.fontWeight='bold';
          var body=document.createElement('span');
          body.style.color=m.color||'#c9d1e0'; body.innerHTML=m.html;   // server-sanitized
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
    <div class="panel" style="padding-bottom:0">
      <h3 style="margin:0 0 8px">Online</h3>
      <div style="height:120px;overflow-y:auto;overflow-x:hidden">

      <?php
        $onlineFriends = $onlineSyndicate = $onlineStaff = [];
        try {
          $oq1 = db()->prepare("SELECT p.id, p.username, p.role, p.chat_color FROM friends f JOIN players p ON p.id=f.friend_id WHERE f.player_id=? AND p.last_seen>=(NOW()-INTERVAL 5 MINUTE) ORDER BY p.username LIMIT 30");
          $oq1->execute([$player['id']]); $onlineFriends = $oq1->fetchAll();
        } catch (Throwable $e) {}
        try {
          $oq2 = db()->prepare("SELECT p.id, p.username, p.role, p.chat_color FROM syndicate_members sm1 JOIN syndicate_members sm2 ON sm2.syndicate_id=sm1.syndicate_id AND sm2.player_id!=? JOIN players p ON p.id=sm2.player_id WHERE sm1.player_id=? AND p.last_seen>=(NOW()-INTERVAL 5 MINUTE) ORDER BY p.username LIMIT 30");
          $oq2->execute([$player['id'], $player['id']]); $onlineSyndicate = $oq2->fetchAll();
        } catch (Throwable $e) {}
        try {
          $oq3 = db()->query("SELECT id, username, role, chat_color FROM players WHERE role IN ('manager','admin','moderator','chatmod') AND last_seen>=(NOW()-INTERVAL 5 MINUTE) ORDER BY username LIMIT 20");
          $onlineStaff = $oq3->fetchAll();
        } catch (Throwable $e) {}

        foreach (['friends'=>$onlineFriends,'syndicate'=>$onlineSyndicate,'staff'=>$onlineStaff] as $tid=>$olist):
      ?>
      <div id="jackedin-<?= $tid ?>" style="display:none">
        <?php if (empty($olist)): ?>
          <p class="muted" style="font-size:11px;margin:3px 0">None online.</p>
        <?php else: foreach ($olist as $o): $oc = chat_color($o['role'], $o['chat_color'] ?? ''); ?>
          <div class="online-player"><span class="online-dot"></span><a href="index.php?p=profile&id=<?= (int)$o['id'] ?>" style="color:<?= e($oc) ?>"><?= e($o['username']) ?></a></div>
        <?php endforeach; endif; ?>
        <?php if ($tid === 'friends'): ?><p style="font-size:10px;margin:6px 0 0"><a href="index.php?p=friends" style="color:var(--muted)">View all friends &rarr;</a></p><?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div><!-- end fixed-height tab content -->

      <div id="online-tabs" style="display:flex;margin:10px -14px 0;border-top:1px solid var(--line)">
        <?php $__otabs = ['friends'=>'Friends','syndicate'=>'Syndicate','staff'=>'Staff']; $__otlast = array_key_last($__otabs);
        foreach ($__otabs as $tid=>$tl): ?>
        <button onclick="switchOnlineTab('<?= $tid ?>')" data-tab="<?= $tid ?>"
          style="flex:1;font-size:10px;padding:7px 4px;cursor:pointer;border:none;<?= $tid !== $__otlast ? 'border-right:1px solid var(--line);' : '' ?>background:var(--panel2);color:var(--muted);letter-spacing:.03em"><?= $tl ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function(){
      var _activeTab = localStorage.getItem('onlineTab') || 'friends';
      window.switchOnlineTab = function(tab){
        _activeTab = tab; localStorage.setItem('onlineTab', tab);
        ['friends','syndicate','staff'].forEach(function(t){
          var el=document.getElementById('jackedin-'+t); if(el) el.style.display=(t===tab?'block':'none');
          var btn=document.querySelector('#online-tabs [data-tab="'+t+'"]');
          if(btn){ btn.style.background=t===tab?'rgba(25,240,199,.08)':'var(--panel2)'; btn.style.color=t===tab?'var(--accent)':'var(--muted)'; btn.style.fontWeight=t===tab?'700':'400'; }
        });
      };
      switchOnlineTab(_activeTab);
    })();
    </script>
  </aside>

  <script>
  var _myPid = <?= (int)($player['id'] ?? 0) ?>;
  </script>
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
    function renderOnline(data){
      var sections=[
        ['jackedin-friends',  data.friends   || [], true],
        ['jackedin-syndicate',data.syndicate || [], false],
        ['jackedin-staff',    data.staff     || [], false]
      ];
      sections.forEach(function(sec){
        var box=document.getElementById(sec[0]); if(!box) return;
        var list=sec[1], isFriends=sec[2];
        var wasVisible = box.style.display !== 'none';
        box.innerHTML='';
        if(!list.length){
          var p=document.createElement('p');
          p.style.cssText='font-size:11px;color:var(--muted);margin:3px 0';
          p.textContent='None online.'; box.appendChild(p);
        } else {
          list.forEach(function(o){
            var d=document.createElement('div'); d.className='online-player';
            var dot=document.createElement('span'); dot.className='online-dot';
            var a=document.createElement('a'); a.href='index.php?p=profile&id='+o.id;
            a.textContent=o.name; a.style.color=o.color;
            d.appendChild(dot); d.appendChild(a);
            box.appendChild(d);
          });
        }
        if(isFriends){
          var fl=document.createElement('p'); fl.style.cssText='font-size:10px;margin:6px 0 0';
          var fa=document.createElement('a'); fa.href='index.php?p=friends'; fa.style.color='var(--muted)'; fa.textContent='View all friends →';
          fl.appendChild(fa); box.appendChild(fl);
        }
      });
    }
    function refresh(){
      fetch('state_api.php',{credentials:'same-origin'})
        .then(function(r){
          if(r.status===401){ window.location.replace('index.php?p=login'); return null; }
          return r.json();
        })
        .then(function(d){
          if(!d||!d.ok) return;
          // Cross-tab account switch detection: different pid means another account logged in
          if(d.pid && _myPid && d.pid !== _myPid){ window.location.reload(); return; }
          var s=d.s;
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
  /* ── Client-side idle auto-logout ── */
  (function(){
    var WARN_MS  = <?= (SESSION_TIMEOUT - 300) * 1000 ?>; // warn 5 min before timeout
    var LIMIT_MS = <?= SESSION_TIMEOUT * 1000 ?>;
    var lastAct  = Date.now();
    var warned   = false;
    var warnTimer, logoutTimer;
    function resetTimers(){
      lastAct = Date.now(); warned = false;
      clearTimeout(warnTimer); clearTimeout(logoutTimer);
      warnTimer   = setTimeout(warnUser,   WARN_MS);
      logoutTimer = setTimeout(forceLogout, LIMIT_MS);
    }
    function warnUser(){
      warned = true;
      if(window.showToast) showToast('You will be logged out in 5 minutes due to inactivity.','warn');
    }
    function forceLogout(){
      window.location.replace('index.php?p=logout');
    }
    ['mousemove','keydown','touchstart','click','scroll'].forEach(function(ev){
      document.addEventListener(ev, function(){ if(warned || (Date.now()-lastAct > 60000)) resetTimers(); }, {passive:true});
    });
    resetTimers();
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
      (root||document).querySelectorAll('textarea[maxlength],input[maxlength]:not([type=hidden]):not(#chatinput):not(#chatinput-full):not([data-no-counter])').forEach(function(el){
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
