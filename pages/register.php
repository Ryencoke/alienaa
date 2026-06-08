<?php /* pages/register.php */
$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim($_POST['username']??''); $pw=$_POST['password']??'';
  if (!preg_match('/^[A-Za-z0-9_]{3,32}$/',$u)) $err='Handle must be 3-32 letters/numbers.';
  elseif (strlen($pw)<8) $err='Passkey must be at least 8 characters.';
  else {
    try {
      db()->prepare('INSERT INTO players (username,pass_hash) VALUES (?,?)')
          ->execute([$u,password_hash($pw,PASSWORD_DEFAULT)]);
      $ok='Ghost created. You can jack in now.';
    } catch (PDOException $e) { $err='That handle is taken.'; }
  }
}
?>
<div class="panel">
  <h2>Register a Ghost</h2>
  <?php if($err):?><div class="flash"><?= e($err) ?></div><?php endif;?>
  <?php if($ok):?><div class="flash"><?= e($ok) ?></div>
    <p><a href="index.php?p=login">Jack in &raquo;</a></p>
  <?php else:?>
  <form method="post">
    <p><label>Handle</label><input type="text" name="username"></p>
    <p><label>Passkey (8+ chars)</label><input type="password" name="password"></p>
    <p><button type="submit">Create Ghost</button></p>
  </form>
  <?php endif;?>
</div>
