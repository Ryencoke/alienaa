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
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
  if ($row && password_verify($pw,$row['pass_hash'])) {
    session_regenerate_id(true);
    $_SESSION['pid']=$row['id'];
    $_SESSION['last_activity']=time();
    unset($_SESSION['timed_out']);
    try { $pdo->prepare('INSERT INTO ip_log (player_id,ip,user_agent,action) VALUES (?,?,?,?)')->execute([$row['id'],$ip,$ua,'login']); } catch(Throwable $e){}
    header('Location: index.php?p=home'); exit;
  }
  try { $pdo->prepare('INSERT INTO ip_log (player_id,ip,user_agent,action) VALUES (?,?,?,?)')->execute([$row['id']??null,$ip,$ua,'fail']); } catch(Throwable $e){}
  $err='Bad credentials. The Grid does not know you.';
}
?>
<div class="auth-shell">
  <div class="auth-card">
    <h2>Jack In</h2>
    <p class="auth-sub">Sprawl-9 &mdash; sign in to your ghost.</p>
    <?php if(!empty($_SESSION['timed_out'])): unset($_SESSION['timed_out']); ?>
    <div class="flash flash-err">Session expired due to inactivity. Please sign in again.</div>
    <?php elseif($err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endif;?>
    <form method="post">
      <div class="field">
        <span>Email or Handle</span>
        <input type="text" name="email" autocomplete="email" autofocus>
      </div>
      <div class="field">
        <span>Passkey</span>
        <div class="pass-wrap" style="max-width:none">
          <input type="password" name="password" autocomplete="current-password">
          <button type="button" class="pass-toggle" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈'">&#128065;</button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Jack In</button>
    </form>
    <p class="auth-foot">No handle yet? <a href="index.php?p=register">Register a ghost.</a></p>
  </div>
</div>
