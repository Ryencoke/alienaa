<?php /* pages/landing.php — public splash / signup */
if (!function_exists('avatar_svg')) {
  function avatar_svg($color) {
    return '<svg viewBox="0 0 80 104" style="width:56px;height:74px" xmlns="http://www.w3.org/2000/svg">'
      . '<g fill="none" stroke="' . $color . '" stroke-width="3" stroke-linecap="round">'
      . '<circle cx="40" cy="20" r="13"/><line x1="40" y1="33" x2="40" y2="66"/>'
      . '<line x1="40" y1="44" x2="22" y2="58"/><line x1="40" y1="44" x2="58" y2="58"/>'
      . '<line x1="40" y1="66" x2="26" y2="96"/><line x1="40" y1="66" x2="54" y2="96"/>'
      . '</g></svg>';
  }
}

$err = ''; $openModal = false; $av = 1; $signupStep = 1; $pendingEmail = '';

// Auto-create pending_signups table
try {
  db()->exec("CREATE TABLE IF NOT EXISTS pending_signups (
    id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, username VARCHAR(32) NOT NULL,
    pass_hash VARCHAR(255) NOT NULL, avatar TINYINT NOT NULL DEFAULT 1, code CHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY k_email (email), UNIQUE KEY k_username (username)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // Add email col to players if missing
  try { db()->exec('ALTER TABLE players ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL'); } catch (Throwable $e) {}
} catch (Throwable $e) {}

$act = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'signup_init') {
  $openModal = true; $signupStep = 1;
  $email = strtolower(trim($_POST['email'] ?? ''));
  $u     = trim($_POST['username'] ?? '');
  $pw    = $_POST['password'] ?? '';
  $av    = ((int)($_POST['avatar'] ?? 1) === 2) ? 2 : 1;
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
  elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $u)) $err = 'Handle must be 3–32 letters, numbers, or underscore.';
  elseif (strlen($pw) < 8) $err = 'Passkey must be at least 8 characters.';
  else {
    // Check for existing email or username
    $ex = db()->prepare('SELECT id FROM players WHERE email=? OR username=?'); $ex->execute([$email, $u]);
    if ($ex->fetchColumn()) { $err = 'That email or handle is already registered.'; }
    else {
      $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      try {
        db()->prepare('INSERT INTO pending_signups (email, username, pass_hash, avatar, code, expires_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE username=VALUES(username), pass_hash=VALUES(pass_hash), avatar=VALUES(avatar), code=VALUES(code), expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE)')
          ->execute([$email, $u, password_hash($pw, PASSWORD_DEFAULT), $av, $code]);
        // Send verification email
        $subject = 'Sprawl-9 — Verify your Ghost';
        $body    = "Your verification code is: {$code}\n\nEnter this code on the signup page. It expires in 15 minutes.\n\n— Sprawl-9 Grid Authority";
        @mail($email, $subject, $body, "From: noreply@sprawl9.com\r\nContent-Type: text/plain\r\n");
        $signupStep  = 2;
        $pendingEmail = $email;
        $err = ''; // clear any error
      } catch (Throwable $e) { $err = 'Registration error. Try again.'; }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'signup_verify') {
  $openModal = true; $signupStep = 2;
  $email = strtolower(trim($_POST['email'] ?? ''));
  $code  = preg_replace('/\D/', '', $_POST['code'] ?? '');
  $pendingEmail = $email;
  $row = null;
  try {
    $q = db()->prepare('SELECT * FROM pending_signups WHERE email=? AND expires_at > NOW() LIMIT 1');
    $q->execute([$email]); $row = $q->fetch();
  } catch (Throwable $e) {}
  if (!$row)             { $err = 'Code expired or email not found. Start over.'; $signupStep = 1; }
  elseif ($row['code'] !== $code) { $err = 'Incorrect verification code.'; }
  else {
    try {
      db()->prepare('INSERT INTO players (username, pass_hash, avatar, email) VALUES (?,?,?,?)')->execute([$row['username'], $row['pass_hash'], (int)$row['avatar'], $email]);
      session_regenerate_id(true); // same fixation guard as the login path
      $_SESSION['pid'] = (int)db()->lastInsertId();
      try { db()->prepare('DELETE FROM pending_signups WHERE email=?')->execute([$email]); } catch (Throwable $e) {}
      if (!headers_sent()) header('Location: index.php?p=home');
      echo '<script>location.href="index.php?p=home";</script>'; exit;
    } catch (PDOException $e) { $err = 'That handle is already taken. Please start over.'; $signupStep = 1; }
  }
}

// Live stats
$totalPlayers = 0; $onlineNow = 0; $totalCombat = 0;
try {
  $totalPlayers = (int)db()->query("SELECT COUNT(*) FROM players")->fetchColumn();
  $onlineNow    = (int)db()->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
  $totalCombat  = (int)db()->query("SELECT COUNT(*) FROM combat_log")->fetchColumn();
} catch (Throwable $e) {}
?>
<style>
/* ── Landing-only styles ─────────────────────────────── */
.land-bg{
  min-height:100vh;background:var(--bg);
  background-image:
    radial-gradient(ellipse 80% 50% at 50% -10%, rgba(25,240,199,.07) 0%, transparent 70%),
    radial-gradient(ellipse 50% 40% at 80% 80%, rgba(255,45,149,.05) 0%, transparent 60%);
}
.land-nav{
  position:sticky;top:0;z-index:100;
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 24px;
  background:rgba(8,8,18,.85);backdrop-filter:blur(12px);
  border-bottom:1px solid var(--line);
}
.land-logo-txt{
  font-family:'Orbitron',sans-serif;font-size:22px;font-weight:900;
  color:var(--accent);letter-spacing:4px;
  text-shadow:0 0 24px rgba(25,240,199,.4);
}
.land-nav-login{display:flex;gap:8px;align-items:center}
.land-nav-login input{
  padding:7px 12px;background:rgba(255,255,255,.05);border:1px solid var(--line);
  border-radius:5px;color:var(--text);font-size:13px;width:130px;
}
.land-nav-login input:focus{border-color:var(--accent);outline:none}
.land-nav-login button{
  padding:7px 18px;background:transparent;border:1px solid var(--accent);
  color:var(--accent);border-radius:5px;font-size:13px;cursor:pointer;
  transition:background .15s,color .15s;
}
.land-nav-login button:hover{background:var(--accent);color:#000}

.land-hero{
  max-width:900px;margin:0 auto;padding:64px 24px 40px;text-align:center;
}
.land-eyebrow{
  display:inline-block;
  padding:4px 14px;border-radius:20px;
  background:rgba(25,240,199,.1);border:1px solid rgba(25,240,199,.25);
  color:var(--accent);font-size:11px;font-family:'Orbitron',sans-serif;
  letter-spacing:2px;text-transform:uppercase;margin-bottom:20px;
}
.land-title{
  font-family:'Orbitron',sans-serif;font-size:clamp(32px,6vw,64px);
  font-weight:900;line-height:1.05;color:var(--text);
  margin:0 0 20px;
  text-shadow:0 0 40px rgba(25,240,199,.15);
}
.land-title span{
  background:linear-gradient(90deg,var(--accent),var(--neon2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.land-sub{
  font-size:clamp(14px,2vw,18px);color:var(--muted);max-width:580px;
  margin:0 auto 32px;line-height:1.6;
}
.land-cta-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:48px}
.land-btn-primary{
  padding:13px 32px;background:var(--accent);color:#000;border:none;
  border-radius:6px;font-size:14px;font-weight:700;font-family:'Orbitron',sans-serif;
  cursor:pointer;letter-spacing:.5px;transition:all .15s;
  box-shadow:0 0 24px rgba(25,240,199,.3);
}
.land-btn-primary:hover{background:#14d4ae;box-shadow:0 0 32px rgba(25,240,199,.5);transform:translateY(-2px)}
.land-btn-ghost{
  padding:13px 28px;background:transparent;color:var(--accent);
  border:1px solid rgba(25,240,199,.35);border-radius:6px;
  font-size:14px;cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block;
}
.land-btn-ghost:hover{border-color:var(--accent);background:rgba(25,240,199,.06)}

.land-stats{
  display:flex;gap:0;justify-content:center;flex-wrap:wrap;
  border:1px solid var(--line);border-radius:10px;overflow:hidden;
  max-width:500px;margin:0 auto 60px;
  background:rgba(255,255,255,.02);
}
.land-stat{
  flex:1;min-width:120px;padding:16px 20px;text-align:center;
  border-right:1px solid var(--line);
}
.land-stat:last-child{border-right:none}
.land-stat-num{
  font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;
  color:var(--accent);display:block;margin-bottom:4px;
}
.land-stat-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px}

.land-features{
  max-width:900px;margin:0 auto;padding:0 24px 60px;
}
.land-feat-title{
  font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;
  color:var(--text);text-align:center;margin-bottom:32px;
}
.land-feat-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;
}
.land-feat-card{
  background:rgba(255,255,255,.02);border:1px solid var(--line);
  border-radius:10px;padding:20px;transition:border-color .2s,transform .2s;
}
.land-feat-card:hover{border-color:rgba(25,240,199,.3);transform:translateY(-3px)}
.land-feat-icon{font-size:28px;margin-bottom:10px}
.land-feat-name{font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:var(--accent);margin-bottom:6px}
.land-feat-desc{font-size:12px;color:var(--muted);line-height:1.5}

.land-ghost-row{
  max-width:900px;margin:0 auto;padding:0 24px 60px;
  display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;
  align-items:center;
}
.land-ghost-card{
  display:flex;flex-direction:column;align-items:center;padding:28px 20px;
  border:1px solid var(--line);border-radius:12px;cursor:pointer;
  background:rgba(255,255,255,.015);transition:all .2s;text-align:center;
  position:relative;overflow:hidden;
}
.land-ghost-card:hover{border-color:var(--accent);background:rgba(25,240,199,.04);transform:translateY(-4px)}
.land-ghost-card.alt:hover{border-color:var(--neon2);background:rgba(255,45,149,.04)}
.land-ghost-card .cap{font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:var(--text);margin:12px 0 4px;letter-spacing:1px}
.land-ghost-card .sub{font-size:12px;color:var(--muted)}
.land-ghost-badge{
  position:absolute;top:10px;right:10px;padding:2px 8px;border-radius:10px;
  font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;
}

.land-footer{
  text-align:center;padding:24px;border-top:1px solid var(--line);
  font-size:11px;color:var(--muted);
}

/* ── Hero backdrop + entrances ── */
.land-hero-wrap{position:relative;overflow:hidden}
#land-sky{position:absolute;inset:0;width:100%;height:100%;display:block}
.land-hero{position:relative;z-index:1}
@keyframes landUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
.land-eyebrow{animation:landUp .6s cubic-bezier(.2,.8,.3,1) both}
.land-title{animation:landUp .6s cubic-bezier(.2,.8,.3,1) .08s both}
.land-sub{animation:landUp .6s cubic-bezier(.2,.8,.3,1) .16s both}
.land-cta-row{animation:landUp .6s cubic-bezier(.2,.8,.3,1) .24s both}
.land-stats{animation:landUp .6s cubic-bezier(.2,.8,.3,1) .32s both;backdrop-filter:blur(3px)}
.land-stat{transition:background .15s}
.land-stat:hover{background:rgba(255,255,255,.03)}

/* live-online pill in nav */
.land-live{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--muted);
  letter-spacing:.6px;text-transform:uppercase}
.land-live .dot{width:7px;height:7px;border-radius:50%;background:#3bcf63;
  box-shadow:0 0 8px #3bcf63;animation:landDot 1.6s ease-in-out infinite}
@keyframes landDot{50%{opacity:.35}}
@media(max-width:620px){.land-live{display:none}}

/* scroll reveal */
.land-reveal{opacity:0;transform:translateY(14px);
  transition:opacity .55s ease,transform .55s cubic-bezier(.2,.8,.3,1),border-color .2s,background .2s,box-shadow .2s}
.land-reveal.vis{opacity:1;transform:none}

/* per-feature accents */
.land-feat-card{position:relative;overflow:hidden}
.land-feat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:var(--fc,var(--accent));opacity:0;transition:opacity .2s}
.land-feat-card:hover::before{opacity:.85}
.land-feat-card:hover{border-color:var(--fc,rgba(25,240,199,.3));box-shadow:0 6px 18px rgba(0,0,0,.3)}
.land-feat-name{color:var(--fc,var(--accent))}

/* ghost cards */
.land-ghost-card svg{transition:transform .25s cubic-bezier(.2,.8,.3,1)}
.land-ghost-card:hover svg{transform:translateY(-4px) scale(1.06)}

/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;
  align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-bg.show{display:flex}
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}}
.modal-bg.show .modal{animation:modalIn .22s cubic-bezier(.2,.9,.3,1.15)}
.modal{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:28px;width:100%;max-width:380px;position:relative;
  box-shadow:0 0 60px rgba(0,0,0,.6),0 0 0 1px rgba(25,240,199,.1)}
.modal h3{margin:0 0 4px;font-family:'Orbitron',sans-serif;font-size:16px;color:var(--accent)}
.modal .x{position:absolute;top:12px;right:14px;font-size:22px;cursor:pointer;color:var(--muted);line-height:1}
.modal .x:hover{color:var(--text)}
</style>

<div class="land-bg">
<!-- ── Nav ──────────────────────────────────────────── -->
<nav class="land-nav">
  <div style="display:flex;align-items:center;gap:16px">
    <div class="land-logo-txt">SPRAWL-9</div>
    <span class="land-live"><span class="dot"></span><span><?= number_format($onlineNow) ?> online</span></span>
  </div>
  <form class="land-nav-login" method="post" action="index.php?p=login">
    <input type="text" name="email" placeholder="email or handle" autocomplete="email">
    <input type="password" name="password" placeholder="passkey" autocomplete="current-password">
    <button type="submit">Login</button>
  </form>
</nav>

<!-- ── Hero ─────────────────────────────────────────── -->
<div class="land-hero-wrap">
<canvas id="land-sky"></canvas>
<div class="land-hero">
  <div class="land-eyebrow">&#9889; Free to Play &bull; Browser-Based &bull; No Download</div>
  <h1 class="land-title">Jack into the<br><span>Neon Underground</span></h1>
  <p class="land-sub">You're a ghost in a dying megacity. Scavenge, fight, trade, and claw your way up from nothing in <b>Sprawl-9</b> — a cyberpunk text MMO that runs in your browser.</p>
  <div class="land-cta-row">
    <button class="land-btn-primary" onclick="openSignup(1)">&#9889; Start Playing — It's Free</button>
    <a href="#features" class="land-btn-ghost">&#8594; See What's Inside</a>
  </div>

  <!-- Live Stats -->
  <div class="land-stats">
    <div class="land-stat">
      <span class="land-stat-num" id="ls-total" data-n="<?= (int)$totalPlayers ?>"><?= number_format($totalPlayers) ?></span>
      <div class="land-stat-lbl">Ghosts Registered</div>
    </div>
    <div class="land-stat">
      <span class="land-stat-num" style="color:var(--neon2)" id="ls-online" data-n="<?= (int)$onlineNow ?>"><?= number_format($onlineNow) ?></span>
      <div class="land-stat-lbl">Online Now</div>
    </div>
    <div class="land-stat">
      <span class="land-stat-num" style="color:#e8a33d" id="ls-combat" data-n="<?= (int)$totalCombat ?>"><?= number_format($totalCombat) ?></span>
      <div class="land-stat-lbl">Fights Logged</div>
    </div>
  </div>
</div>
</div>

<!-- ── Features ─────────────────────────────────────── -->
<div class="land-features" id="features">
  <h2 class="land-feat-title">What's in the Sprawl?</h2>
  <div class="land-feat-grid">
    <?php $feats = [
      ['&#128737;','Combat Simulator','Test your ghost against a tier-based enemy roster. Win XP, loot, and street cred. Gear up and push into higher tiers.'],
      ['&#9881;','Foundry &amp; Synthesis','Scavenge crates in the Fabrication Bay, craft from raw components, and brew combat stims in the Synthesis Den.'],
      ['&#127974;','Deep Economy','A full economy: pocket creds, bank with daily interest, bonds, stock exchange, Black Market auctions, and a player bazaar.'],
      ['&#127942;','Grid Rankings','Compete globally across XP, Level, Wealth, Combat Wins, and Bank balance leaderboards.'],
      ['&#9670;','Shards &amp; Subscriptions','Earn free Shards from the daily vault. Subscribe for XP bonuses, double Shards, and the exclusive Lounge.'],
      ['&#128313;','Syndicates &amp; Guilds','Join or create a Syndicate. Climb ranks, donate to the collective, run private boards, and level up your faction.'],
      ['&#127748;','Black Market','Post anything for auction. Live bidding, outbid refunds, and a ruthless auctioneer fee. Every sale is final.'],
      ['&#128241;','Subsistence Terminal','New to the Grid? Claim 500 free credits every day for your first 30 days. The Sprawl gives new ghosts a fighting chance.'],
    ];
    $featCols = ['#19f0c7','#ff2d95','#e8d44d','#4d9be8','#3bcf63','#b48cff','#e8a33d','#ff5c5c'];
    foreach ($feats as $fi => [$icon,$name,$desc]): ?>
    <div class="land-feat-card land-reveal" style="--fc:<?= $featCols[$fi % count($featCols)] ?>" data-rd="<?= ($fi % 3) * 70 ?>">
      <div class="land-feat-icon"><?= $icon ?></div>
      <div class="land-feat-name"><?= $name ?></div>
      <div class="land-feat-desc"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Choose your ghost ─────────────────────────────── -->
<div style="max-width:900px;margin:0 auto;padding:0 24px 20px;text-align:center">
  <h2 style="font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;color:var(--text);margin-bottom:8px">Choose Your Ghost</h2>
  <p class="muted" style="font-size:13px;margin-bottom:28px">Both start the same. The choice is aesthetic — pick the one that feels like you.</p>
</div>
<div class="land-ghost-row">
  <div class="land-ghost-card land-reveal" onclick="openSignup(1)">
    <div class="land-ghost-badge" style="background:rgba(25,240,199,.12);border:1px solid rgba(25,240,199,.3);color:var(--accent)">DRIFTER</div>
    <?= avatar_svg('var(--accent)') ?>
    <div class="cap">THE DRIFTER</div>
    <div class="sub">A vagrant operative, no allegiances, no limits.</div>
    <div style="margin-top:16px;padding:8px 20px;border:1px solid rgba(25,240,199,.3);border-radius:5px;font-size:12px;color:var(--accent)">Jack In &rarr;</div>
  </div>
  <div class="land-ghost-card alt land-reveal" onclick="openSignup(2)" data-rd="90">
    <div class="land-ghost-badge" style="background:rgba(255,45,149,.12);border:1px solid rgba(255,45,149,.3);color:var(--neon2)">NETGHOST</div>
    <?= avatar_svg('var(--neon2)') ?>
    <div class="cap">THE NETGHOST</div>
    <div class="sub">A shadow on the Grid — invisible until they strike.</div>
    <div style="margin-top:16px;padding:8px 20px;border:1px solid rgba(255,45,149,.3);border-radius:5px;font-size:12px;color:var(--neon2)">Jack In &rarr;</div>
  </div>
</div>

<div class="land-footer">
  Sprawl-9 &copy; <?= date('Y') ?> &mdash; A cyberpunk text MMO &bull; Free to play &bull; <a href="index.php?p=login">Already have a ghost? Log in</a>
</div>
</div>

<!-- ── Signup Modal ──────────────────────────────────── -->
<div class="modal-bg<?= $openModal ? ' show' : '' ?>" id="signupModal">
  <div class="modal">
    <span class="x" onclick="closeSignup()">&times;</span>

    <!-- Step 1: registration form -->
    <div id="signup-step1" style="<?= $signupStep === 2 ? 'display:none' : '' ?>">
      <h3>Jack in for free</h3>
      <p class="muted" style="margin-top:-4px;margin-bottom:18px;font-size:13px">Create your ghost in seconds. A verification code will be sent to your email.</p>
      <?php if ($err && $signupStep === 1): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif; ?>
      <form method="post" action="index.php?p=landing">
        <input type="hidden" name="action" value="signup_init">
        <input type="hidden" name="avatar" id="signupAvatar" value="<?= (int)$av ?>">
        <div class="field">
          <span>Email <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(used for verification)</span></span>
          <input type="email" name="email" autocomplete="email" placeholder="your@email.com">
        </div>
        <div class="field" style="margin-top:10px">
          <span>Handle <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(your in-game name)</span></span>
          <input type="text" name="username" maxlength="32" autocomplete="username" placeholder="3–32 chars, letters/numbers/underscore">
        </div>
        <div class="field" style="margin-top:10px">
          <span>Passkey <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(8+ characters)</span></span>
          <div class="pass-wrap" style="max-width:none">
            <input type="password" name="password" autocomplete="new-password">
            <button type="button" class="pass-toggle" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈'">&#128065;</button>
          </div>
        </div>
        <button type="submit" style="margin-top:16px;width:100%;padding:11px;font-size:14px">&#9889; Send Verification Code</button>
        <p class="muted" style="text-align:center;margin-top:12px;font-size:12px">Already have a ghost? <a href="index.php?p=login">Log in here</a>.</p>
      </form>
    </div>

    <!-- Step 2: verify email code -->
    <div id="signup-step2" style="<?= $signupStep !== 2 ? 'display:none' : '' ?>">
      <h3>Verify your Email</h3>
      <p class="muted" style="margin-top:-4px;margin-bottom:18px;font-size:13px">A 6-digit code was sent to <b><?= e($pendingEmail) ?></b>. Expires in 15 minutes.</p>
      <?php if ($err && $signupStep === 2): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif; ?>
      <form method="post" action="index.php?p=landing">
        <input type="hidden" name="action" value="signup_verify">
        <input type="hidden" name="email" value="<?= e($pendingEmail) ?>">
        <div style="margin-bottom:16px">
          <input type="text" id="otpInput" name="code" maxlength="6" pattern="\d{6}" placeholder="000000"
            inputmode="numeric" autocomplete="one-time-code"
            style="font-size:28px;text-align:center;letter-spacing:12px;font-family:'Orbitron',sans-serif;
                   width:100%;padding:14px 12px;background:var(--panel2);border:1px solid var(--accent);
                   border-radius:8px;color:var(--accent);outline:none;
                   box-shadow:0 0 16px rgba(25,240,199,.15),inset 0 0 12px rgba(25,240,199,.04)"
            autofocus oninput="this.value=this.value.replace(/\D/g,'').slice(0,6)">
          <div style="font-size:10px;color:var(--muted);text-align:center;margin-top:6px;text-transform:uppercase;letter-spacing:.8px">6-digit code from your email</div>
        </div>
        <button type="submit" style="margin-top:4px;width:100%;padding:11px;font-size:14px">&#9889; Activate Ghost</button>
      </form>
      <p class="muted" style="text-align:center;margin-top:12px;font-size:12px"><a href="#" onclick="document.getElementById('signup-step1').style.display='';document.getElementById('signup-step2').style.display='none';return false">&larr; Use a different email</a></p>
    </div>
  </div>
</div>

<!-- Update nav login to say email -->
<script>
function openSignup(av){ document.getElementById('signupAvatar').value=av; document.getElementById('signupModal').classList.add('show'); }
function closeSignup(){ document.getElementById('signupModal').classList.remove('show'); }
document.getElementById('signupModal').addEventListener('click', function(e){ if(e.target===this) closeSignup(); });
document.querySelectorAll('a[href="#features"]').forEach(function(a){
  a.addEventListener('click',function(e){ e.preventDefault(); document.getElementById('features').scrollIntoView({behavior:'smooth'}); });
});

/* ── Live stat count-up ── */
document.querySelectorAll('.land-stat-num[data-n]').forEach(function(el){
  var target=parseInt(el.getAttribute('data-n'),10)||0, t0=null;
  if(target<=0) return;
  function step(t){
    if(!t0) t0=t;
    var p=Math.min(1,(t-t0)/1300); p=1-Math.pow(1-p,3);
    el.textContent=Math.round(target*p).toLocaleString();
    if(p<1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
});

/* ── Scroll reveal ── */
(function(){
  var rv=document.querySelectorAll('.land-reveal');
  if(!('IntersectionObserver' in window)){ rv.forEach(function(el){ el.classList.add('vis'); }); return; }
  var io=new IntersectionObserver(function(entries){
    entries.forEach(function(en){
      if(!en.isIntersecting) return;
      var el=en.target;
      el.style.transitionDelay=(el.getAttribute('data-rd')||0)+'ms';
      el.classList.add('vis');
      setTimeout(function(){ el.style.transitionDelay=''; },800);
      io.unobserve(el);
    });
  },{threshold:.12});
  rv.forEach(function(el){ io.observe(el); });
})();

/* ── Hero skyline backdrop ── */
(function(){
'use strict';
var cv=document.getElementById('land-sky');
if(!cv) return;
var c=cv.getContext('2d'), wrap=cv.parentNode;
var W,H,dpr,towers=[],backTowers=[],stars=[];
function rnd(i){ var x=Math.sin(i*127.17)*43758.5453; return x-Math.floor(x); }
function build(){
  dpr=Math.min(2,window.devicePixelRatio||1);
  W=wrap.clientWidth; H=wrap.clientHeight;
  cv.width=W*dpr; cv.height=H*dpr;
  c.setTransform(dpr,0,0,dpr,0,0);
  backTowers=[]; towers=[];
  var x=-30,i=0;
  while(x<W+60){ var bw=34+rnd(i+200)*70, bh=H*0.12+rnd(i+260)*H*0.22;
    backTowers.push({x:x,w:bw,h:bh}); x+=bw+6+rnd(i+220)*20; i++; }
  x=-20; i=0;
  while(x<W+40){ var tw=26+rnd(i)*56, th=H*0.10+rnd(i+99)*H*0.30;
    towers.push({x:x,w:tw,h:th,seed:i}); x+=tw+5+rnd(i+7)*18; i++; }
  stars=[];
  for(var s=0;s<110;s++) stars.push({x:rnd(s+500)*W, y:rnd(s+800)*H*0.5, r:.5+rnd(s+300)*1.1, p:rnd(s)*6.28});
}
build();
var rsT; window.addEventListener('resize',function(){ clearTimeout(rsT); rsT=setTimeout(build,150); });

function loop(t){
  if(!document.body.contains(cv)) return;
  requestAnimationFrame(loop);
  c.clearRect(0,0,W,H);
  var g=c.createLinearGradient(0,0,0,H);
  g.addColorStop(0,'#07070f'); g.addColorStop(.7,'#0b0a18'); g.addColorStop(1,'#0a0a12');
  c.fillStyle=g; c.fillRect(0,0,W,H);

  // stars
  stars.forEach(function(s){
    var a=.25+.3*Math.sin(t/900+s.p);
    if(a<=0) return;
    c.fillStyle='rgba(220,240,255,'+a.toFixed(2)+')';
    c.fillRect(s.x,s.y,s.r,s.r);
  });

  // neon haze pulses (off-screen signs)
  var h1=c.createRadialGradient(W*0.18,H*0.92,0,W*0.18,H*0.92,H*0.7);
  h1.addColorStop(0,'rgba(25,240,199,'+(0.05+0.02*Math.sin(t/1700)).toFixed(3)+')'); h1.addColorStop(1,'rgba(0,0,0,0)');
  c.fillStyle=h1; c.fillRect(0,0,W,H);
  var h2=c.createRadialGradient(W*0.84,H*0.95,0,W*0.84,H*0.95,H*0.65);
  h2.addColorStop(0,'rgba(255,45,149,'+(0.045+0.02*Math.sin(t/2100+2)).toFixed(3)+')'); h2.addColorStop(1,'rgba(0,0,0,0)');
  c.fillStyle=h2; c.fillRect(0,0,W,H);

  // aircar streak gliding across, repeats every ~9s
  var carP=(t%9000)/9000;
  if(carP<.45){
    var cx2=-60+(W+120)*(carP/.45), cy2=H*0.30+Math.sin(carP*9)*8;
    var cg=c.createLinearGradient(cx2-46,cy2,cx2,cy2);
    cg.addColorStop(0,'rgba(255,90,90,0)'); cg.addColorStop(1,'rgba(255,90,90,.5)');
    c.strokeStyle=cg; c.lineWidth=1.6;
    c.beginPath(); c.moveTo(cx2-46,cy2+1); c.lineTo(cx2,cy2); c.stroke();
    c.fillStyle='rgba(255,160,160,.9)'; c.fillRect(cx2-1,cy2-1,2.6,2.6);
  }

  // back skyline (hazier, taller)
  c.fillStyle='#0a0a15';
  backTowers.forEach(function(b){ c.fillRect(b.x,H-b.h,b.w,b.h); });

  // front skyline with flickering windows
  towers.forEach(function(tw){
    c.fillStyle='#0d0d19';
    c.fillRect(tw.x,H-tw.h,tw.w,tw.h);
    var cols=Math.max(1,Math.floor(tw.w/11)), rows=Math.max(2,Math.floor(tw.h/15));
    for(var r2=0;r2<rows;r2++){
      for(var cl=0;cl<cols;cl++){
        var k=tw.seed*31+r2*7+cl*13;
        if(rnd(k)<.62) continue;
        var fl=rnd(k+50)>.85 ? (0.5+0.5*Math.sin(t/600+k)) : 1;
        var a=(0.16+rnd(k+9)*0.2)*fl;
        if(a<=0.02) continue;
        c.fillStyle=(rnd(k+77)>.82)?'rgba(255,45,149,'+a.toFixed(2)+')':'rgba(25,240,199,'+a.toFixed(2)+')';
        c.fillRect(tw.x+4+cl*11, H-tw.h+6+r2*15, 4, 6);
      }
    }
  });

  // soft veil behind the headline so text stays crisp
  var v=c.createRadialGradient(W/2,H*0.36,40,W/2,H*0.36,Math.max(W,H)*0.55);
  v.addColorStop(0,'rgba(7,7,15,.38)'); v.addColorStop(1,'rgba(7,7,15,0)');
  c.fillStyle=v; c.fillRect(0,0,W,H);

  // fade into the page background at the bottom edge
  var f=c.createLinearGradient(0,H-80,0,H);
  f.addColorStop(0,'rgba(10,10,18,0)'); f.addColorStop(1,'#0a0a12');
  c.fillStyle=f; c.fillRect(0,H-80,W,80);
}
requestAnimationFrame(loop);
})();
</script>
