<?php /* pages/landing.php — logged-out splash + signup */

if (!function_exists('avatar_svg')) {
  function avatar_svg($color) {
    return '<svg viewBox="0 0 80 104" style="width:64px;height:84px" xmlns="http://www.w3.org/2000/svg">'
      . '<g fill="none" stroke="' . $color . '" stroke-width="3" stroke-linecap="round">'
      . '<circle cx="40" cy="20" r="13"/>'
      . '<line x1="40" y1="33" x2="40" y2="66"/>'
      . '<line x1="40" y1="44" x2="22" y2="58"/>'
      . '<line x1="40" y1="44" x2="58" y2="58"/>'
      . '<line x1="40" y1="66" x2="26" y2="96"/>'
      . '<line x1="40" y1="66" x2="54" y2="96"/>'
      . '</g></svg>';
  }
}

$err = ''; $openModal = false; $av = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'signup') {
  $u  = trim($_POST['username'] ?? '');
  $pw = $_POST['password'] ?? '';
  $av = ((int)($_POST['avatar'] ?? 1) === 2) ? 2 : 1;
  $openModal = true;
  if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $u)) $err = 'Handle must be 3-32 letters, numbers, or underscore.';
  elseif (strlen($pw) < 8)                       $err = 'Passkey must be at least 8 characters.';
  else {
    try {
      db()->prepare('INSERT INTO players (username, pass_hash, avatar) VALUES (?,?,?)')
          ->execute([$u, password_hash($pw, PASSWORD_DEFAULT), $av]);
      $_SESSION['pid'] = (int)db()->lastInsertId();
      if (!headers_sent()) header('Location: index.php?p=home');
      echo '<script>location.href="index.php?p=home";</script>'; exit;
    } catch (PDOException $e) { $err = 'That handle is taken.'; }
  }
}
?>
<div class="land">
  <div class="land-top">
    <div class="land-logo">SPRAWL-9</div>
    <form class="land-login" method="post" action="index.php?p=login">
      <input type="text" name="username" placeholder="handle" style="width:120px">
      <input type="password" name="password" placeholder="passkey" style="width:120px">
      <button type="submit">Login</button>
    </form>
  </div>

  <div class="land-hero">
    <div class="panel">
      <h2>A ghost in the neon.</h2>
      <p>Jack into <b>Sprawl-9</b> &mdash; a decaying megacity arcology where you start as an
         unregistered drifter with nothing but a handle and a grudge.</p>
      <p class="muted">Scavenge the service tunnels, craft contraband in the Foundry, run the Bazaar,
         fight in the Combat Sim, gamble at the Lucky Daemon, and claw your way up the rankings.
         No download &mdash; runs in any browser. 100% free.</p>
      <p>Pick your ghost and jack in &raquo;</p>
    </div>

    <div>
      <div class="land-cards">
        <div class="land-card" onclick="openSignup(1)">
          <?= avatar_svg('var(--accent)') ?>
          <div class="cap">THE DRIFTER</div>
          <div class="sub">jack in &raquo;</div>
        </div>
        <div class="land-card" onclick="openSignup(2)">
          <?= avatar_svg('var(--neon2)') ?>
          <div class="cap">THE NETGHOST</div>
          <div class="sub">jack in &raquo;</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-bg<?= $openModal ? ' show' : '' ?>" id="signupModal">
  <div class="modal">
    <a class="x" onclick="closeSignup()">&times;</a>
    <h3>Jack in for free</h3>
    <p class="muted" style="margin-top:-4px">All you need is a handle and a passkey.</p>
    <?php if ($err): ?><div class="flash"><?= e($err) ?></div><?php endif; ?>
    <form method="post" action="index.php?p=landing">
      <input type="hidden" name="action" value="signup">
      <input type="hidden" name="avatar" id="signupAvatar" value="<?= (int)$av ?>">
      <label>Handle</label>
      <p><input type="text" name="username" maxlength="32"></p>
      <label>Passkey (8+ chars)</label>
      <p><input type="password" name="password"></p>
      <p><button type="submit">Start Playing</button></p>
      <p class="muted" style="font-size:11px">Already have a ghost? <a href="index.php?p=login">Log in</a>.</p>
    </form>
  </div>
</div>

<script>
  function openSignup(av){ document.getElementById('signupAvatar').value = av; document.getElementById('signupModal').classList.add('show'); }
  function closeSignup(){ document.getElementById('signupModal').classList.remove('show'); }
</script>
