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
<div class="auth-shell">
  <div class="auth-card">
    <h2>Jack In</h2>
    <p class="auth-sub">Sprawl-9 &mdash; sign in to your ghost.</p>
    <?php if($err):?><div class="flash flash-err"><?= e($err) ?></div><?php endif;?>
    <form method="post">
      <div class="field">
        <span>Handle</span>
        <input type="text" name="username" autocomplete="username" autofocus>
      </div>
      <div class="field">
        <span>Passkey</span>
        <input type="password" name="password" autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Jack In</button>
    </form>
    <p class="auth-foot">No handle yet? <a href="index.php?p=register">Register a ghost.</a></p>
  </div>
</div>
