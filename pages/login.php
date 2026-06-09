<?php /* pages/login.php */
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $em=strtolower(trim($_POST['email']??'')); $pw=$_POST['password']??'';
  $pdo=db();
  // Try by email first; fall back to username so existing accounts still work
  $stmt=$pdo->prepare('SELECT * FROM players WHERE email=?');
  $stmt->execute([$em]); $row=$stmt->fetch();
  if (!$row) {
    $stmt=$pdo->prepare('SELECT * FROM players WHERE username=?');
    $stmt->execute([$em]); $row=$stmt->fetch();
  }
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
        <span>Email</span>
        <input type="email" name="email" autocomplete="email" autofocus>
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
