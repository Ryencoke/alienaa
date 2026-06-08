<?php /* pages/login.php */
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u=trim($_POST['username']??''); $pw=$_POST['password']??'';
  $stmt=db()->prepare('SELECT * FROM players WHERE username=?');
  $stmt->execute([$u]); $row=$stmt->fetch();
  if ($row && password_verify($pw,$row['pass_hash'])) {
    $_SESSION['pid']=$row['id']; header('Location: index.php?p=home'); exit;
  }
  $err='Bad credentials. The Grid does not know you.';
}
?>
<div class="panel">
  <h2>Jack In — Sprawl-9</h2>
  <?php if($err):?><div class="flash"><?= e($err) ?></div><?php endif;?>
  <form method="post">
    <p><label>Handle</label><input type="text" name="username"></p>
    <p><label>Passkey</label><input type="password" name="password"></p>
    <p><button type="submit">Jack In</button></p>
  </form>
  <p class="muted">No handle yet? <a href="index.php?p=register">Register a ghost.</a></p>
</div>
