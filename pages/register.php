<?php /* pages/register.php */
$err=''; $ok='';
$pdo=db();
// One-time schema migration — silently ignored if column already exists
try { $pdo->exec("ALTER TABLE players ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER pass_hash"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE players ADD UNIQUE KEY uq_player_email (email)"); } catch(Throwable $e){}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim($_POST['username']??'');
  $em=strtolower(trim($_POST['email']??''));
  $pw=$_POST['password']??'';
  if (!preg_match('/^[A-Za-z0-9_]{3,32}$/',$u)) $err='Handle must be 3-32 characters: letters, numbers, or underscore only.';
  elseif (!filter_var($em,FILTER_VALIDATE_EMAIL)) $err='Enter a valid email address.';
  elseif (strlen($pw)<8) $err='Passkey must be at least 8 characters.';
  else {
    try {
      $pdo->prepare('INSERT INTO players (username,email,pass_hash) VALUES (?,?,?)')
          ->execute([$u,$em,password_hash($pw,PASSWORD_DEFAULT)]);
      $ok='Ghost created. You can jack in now.';
    } catch (PDOException $ex) {
      if ($ex->getCode() === '23000') $err='That handle or email is already registered.';
      else $err='Registration failed. Please try again.';
    }
  }
}
?>
<div class="auth-shell">
  <div class="auth-card">
    <h2>Create a Ghost</h2>
    <p class="auth-sub">Pick a handle and a passkey to enter the Sprawl.</p>
    <?php if($err):?><div class="flash flash-err"><?= e($err) ?></div><?php endif;?>
    <?php if($ok):?>
      <div class="flash flash-ok"><?= e($ok) ?></div>
      <p style="text-align:center;margin-top:12px"><a href="index.php?p=login" class="btn btn-primary">Jack In &rarr;</a></p>
    <?php else:?>
    <form method="post">
      <div class="field">
        <span>Handle <span class="muted" style="text-transform:none;letter-spacing:0">(public username)</span></span>
        <input type="text" name="username" maxlength="32" autocomplete="username" autofocus>
      </div>
      <div class="field">
        <span>Email <span class="muted" style="text-transform:none;letter-spacing:0">(used to log in)</span></span>
        <input type="email" name="email" autocomplete="email">
      </div>
      <div class="field">
        <span>Passkey <span class="muted" style="text-transform:none;letter-spacing:0">(8+ characters)</span></span>
        <input type="password" name="password" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Create Ghost</button>
    </form>
    <?php endif;?>
    <p class="auth-foot">Already have a ghost? <a href="index.php?p=login">Jack in.</a></p>
  </div>
</div>
