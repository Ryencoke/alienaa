<?php /* pages/account.php — settings hub (tabbed sections) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$all_themes    = themes();
$all_countries = countries();

$secs = ['profile'=>'Member Profile','sidebar'=>'Sidebar','schemes'=>'Schemes & Styles','chat'=>'Chat Settings','boards'=>'Board Settings','account'=>'Account'];
$sec = $_GET['sec'] ?? 'profile';
if (!isset($secs[$sec])) $sec = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'profile') {
      $bio = trim($_POST['bio'] ?? ''); if (mb_strlen($bio) > 200) $bio = mb_substr($bio, 0, 200);
      $country = strtoupper(trim($_POST['country'] ?? '')); if (!isset($all_countries[$country])) $country = '';
      $pdo->prepare('UPDATE players SET bio = ?, country = ? WHERE id = ?')->execute([$bio, $country, $pid]);
      $msg = 'Profile saved.'; $player = current_player();
    }
    elseif ($action === 'theme') {
      $theme = $_POST['theme'] ?? 'neon'; if (!isset($all_themes[$theme])) $theme = 'neon';
      $accent = '';
      if (isset($_POST['use_accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color'] ?? '')) $accent = $_POST['accent_color'];
      $pdo->prepare('UPDATE players SET theme = ?, accent_color = ? WHERE id = ?')->execute([$theme, $accent, $pid]);
      echo '<script>location.replace("index.php?p=account&sec=schemes&saved=1");</script>'; return;
    }
    elseif ($action === 'chatcolor') {
      $c = $_POST['chat_color'] ?? '';
      if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) { $pdo->prepare('UPDATE players SET chat_color = ? WHERE id = ?')->execute([$c, $pid]); $msg = 'Chat color updated.'; $player = current_player(); }
      else $msg = 'Pick a valid color.';
    }
    elseif ($action === 'boards') {
      $sig = trim($_POST['signature'] ?? ''); if (mb_strlen($sig) > 255) $sig = mb_substr($sig, 0, 255);
      $pdo->prepare('UPDATE players SET signature = ? WHERE id = ?')->execute([$sig, $pid]);
      $msg = 'Board settings saved.'; $player = current_player();
    }
    elseif ($action === 'sidebar') {
      $valid = nav_links(); $out = [];
      foreach (explode(',', $_POST['order'] ?? '') as $k) { $k = trim($k); if (isset($valid[$k]) && !in_array($k, $out, true)) $out[] = $k; }
      $pdo->prepare('UPDATE players SET sidebar = ? WHERE id = ?')->execute([implode(',', $out), $pid]);
      $msg = 'Sidebar saved.'; $player = current_player();
    }
    elseif ($action === 'sidebar_reset') {
      $pdo->prepare('UPDATE players SET sidebar = ? WHERE id = ?')->execute(['', $pid]);
      $msg = 'Sidebar reset to defaults.'; $player = current_player();
    }
    elseif ($action === 'handle') {
      $newu = trim($_POST['new_username'] ?? ''); $cur = $_POST['current_password'] ?? '';
      if (!password_verify($cur, $player['pass_hash']))     $msg = 'Current passkey is wrong.';
      elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $newu)) $msg = 'Handle must be 3-32 letters, numbers, or underscore.';
      else {
        try { $pdo->prepare('UPDATE players SET username = ? WHERE id = ?')->execute([$newu, $pid]); $msg = 'Handle changed to ' . $newu . '.'; $player = current_player(); }
        catch (PDOException $e) { $msg = 'That handle is taken.'; }
      }
    }
    elseif ($action === 'password') {
      $cur = $_POST['current_password'] ?? ''; $n1 = $_POST['new_password'] ?? ''; $n2 = $_POST['new_password2'] ?? '';
      if (!password_verify($cur, $player['pass_hash'])) $msg = 'Current passkey is wrong.';
      elseif (strlen($n1) < 8)                          $msg = 'New passkey must be at least 8 characters.';
      elseif ($n1 !== $n2)                              $msg = 'New passkeys do not match.';
      else { $pdo->prepare('UPDATE players SET pass_hash = ? WHERE id = ?')->execute([password_hash($n1, PASSWORD_DEFAULT), $pid]); $msg = 'Passkey changed.'; $player = current_player(); }
    }

  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}
if (($_GET['saved'] ?? '') === '1' && $msg === '') $msg = 'Appearance saved.';

$role      = $player['role'] ?? 'member';
$curTheme  = $player['theme'] ?? 'neon';
$curAccent = $player['accent_color'] ?? '';
?>
<div class="panel">
  <h2>Account Settings</h2>
  <p class="muted" style="font-size:12px">
    <?php $i = 0; foreach ($secs as $k => $lbl): if ($i++) echo ' &middot; ';
      if ($k === $sec) echo '<b style="color:var(--accent)">' . e($lbl) . '</b>';
      else echo '<a href="index.php?p=account&sec=' . $k . '">' . e($lbl) . '</a>';
    endforeach; ?>
  </p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
</div>

<?php if ($sec === 'profile'): ?>
<div class="panel">
  <h3>Member Profile</h3>
  <form method="post">
    <input type="hidden" name="action" value="profile">
    <label>Country</label>
    <p><select name="country" style="max-width:240px">
      <option value="">&mdash; none &mdash;</option>
      <?php foreach ($all_countries as $code => $cn): ?>
        <option value="<?= $code ?>" <?= ($player['country'] ?? '') === $code ? 'selected' : '' ?>><?= e($cn) ?></option>
      <?php endforeach; ?>
    </select></p>
    <p class="muted" style="font-size:11px">Your flag shows next to your name on your Hideout and profile (not in chat).</p>
    <label>Tagline / bio (200 max)</label>
    <p><textarea name="bio" maxlength="200" style="min-height:60px"><?= e($player['bio'] ?? '') ?></textarea></p>
    <p><a href="index.php?p=profile&id=<?= (int)$player['id'] ?>">View my profile &raquo;</a></p>
    <p><button type="submit">Save Profile</button></p>
  </form>
</div>

<?php elseif ($sec === 'sidebar'):
  $current = player_sidebar($player);
  $nl = nav_links();
?>
<div class="panel">
  <h3>Sidebar Quick Links</h3>
  <p class="muted">Reorder, add, or remove links from your sidebar navigation.</p>
  <form method="post" action="index.php?p=account&sec=sidebar" id="sbform">
    <input type="hidden" name="action" value="sidebar">
    <input type="hidden" name="order" id="sborder">
    <div id="sblist">
      <?php foreach ($current as $k): ?>
        <div class="sbrow" data-key="<?= e($k) ?>">
          <span class="sbmove" data-dir="up">&#9650;</span>
          <span class="sbmove" data-dir="down">&#9660;</span>
          <span class="sblabel"><?= e($nl[$k][0]) ?></span>
          <span class="sbdel">&times;</span>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="margin-top:8px"><select id="sbadd" style="max-width:240px">
      <option value="">+ Add link&hellip;</option>
      <?php foreach ($nl as $k => $v): if (!in_array($k, $current, true)): ?><option value="<?= e($k) ?>"><?= e($v[0]) ?></option><?php endif; endforeach; ?>
    </select></p>
    <p><button type="submit">Save Sidebar</button></p>
  </form>
  <form method="post" action="index.php?p=account&sec=sidebar">
    <input type="hidden" name="action" value="sidebar_reset">
    <button type="submit" style="background:none;color:var(--muted);box-shadow:none;padding:0;font-size:12px">Reset to defaults</button>
  </form>
  <script>
  (function(){
    var list=document.getElementById('sblist'), form=document.getElementById('sbform'),
        order=document.getElementById('sborder'), add=document.getElementById('sbadd');
    if(!list) return;
    var labels=<?= json_encode(array_map(function($v){ return $v[0]; }, $nl)) ?>;
    list.addEventListener('click',function(e){
      var row=e.target.closest('.sbrow'); if(!row) return;
      if(e.target.classList.contains('sbdel')){ var k=row.getAttribute('data-key'); row.remove();
        var o=document.createElement('option'); o.value=k; o.textContent=labels[k]||k; add.appendChild(o); return; }
      if(e.target.classList.contains('sbmove')){
        var dir=e.target.getAttribute('data-dir');
        if(dir==='up'&&row.previousElementSibling) list.insertBefore(row,row.previousElementSibling);
        if(dir==='down'&&row.nextElementSibling) list.insertBefore(row.nextElementSibling,row);
      }
    });
    add.addEventListener('change',function(){
      var k=add.value; if(!k) return;
      var row=document.createElement('div'); row.className='sbrow'; row.setAttribute('data-key',k);
      row.innerHTML='<span class="sbmove" data-dir="up">&#9650;</span><span class="sbmove" data-dir="down">&#9660;</span><span class="sblabel"></span><span class="sbdel">&times;</span>';
      row.querySelector('.sblabel').textContent=labels[k]||k;
      list.appendChild(row);
      var opt=add.querySelector('option[value="'+k+'"]'); if(opt) opt.remove(); add.value='';
    });
    form.addEventListener('submit',function(){
      var keys=[]; list.querySelectorAll('.sbrow').forEach(function(r){ keys.push(r.getAttribute('data-key')); });
      order.value=keys.join(',');
    });
  })();
  </script>
</div>

<?php elseif ($sec === 'schemes'): ?>
<div class="panel">
  <h3>Schemes &amp; Styles</h3>
  <form method="post">
    <input type="hidden" name="action" value="theme">
    <label>Theme</label>
    <p><select name="theme">
      <?php foreach ($all_themes as $key => $t): ?>
        <option value="<?= e($key) ?>" <?= $key === $curTheme ? 'selected' : '' ?>><?= e($t['name']) ?></option>
      <?php endforeach; ?>
    </select></p>
    <label style="display:inline-block"><input type="checkbox" name="use_accent" value="1" <?= $curAccent ? 'checked' : '' ?> style="width:auto"> Custom accent color (overrides the theme's)</label>
    <p><input type="color" name="accent_color" value="<?= e($curAccent ?: '#19f0c7') ?>" style="width:64px;height:34px;padding:2px"></p>
    <p><button type="submit">Save Appearance</button></p>
  </form>
</div>

<?php elseif ($sec === 'chat'):
  $cur = $player['chat_color'] ?? '#c9d1e0';
  if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cur)) $cur = '#c9d1e0';
  $presets = ['#b8a472','#ffffff','#e23b3b','#3bcf63','#4d6be8','#5fe0e0','#ffe14d','#e8a33d','#ff2d95','#9bff3d'];
?>
<div class="panel">
  <h3>Chat Options</h3>
  <?php if ($role !== 'member'): ?>
    <p class="muted">Note: in chat your name shows in <b style="color:<?= e(chat_color($role, '')) ?>"><?= e(role_label($role)) ?></b> staff color, which overrides your personal pick. You can still set one for when you're off staff.</p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="chatcolor">
    <label>Chat text color</label>
    <div id="swatches" style="display:flex;gap:6px;flex-wrap:wrap;margin:6px 0">
      <?php foreach ($presets as $pc): ?><span class="swatch" data-color="<?= $pc ?>" style="background:<?= $pc ?>"></span><?php endforeach; ?>
    </div>
    <p style="display:flex;gap:8px;align-items:center">
      <span class="muted">Custom</span>
      <input type="color" id="ccPick" name="chat_color" value="<?= e($cur) ?>" style="width:48px;height:34px;padding:2px">
      <input type="text" id="ccHex" value="<?= e($cur) ?>" maxlength="7" style="width:96px">
    </p>
    <div class="panel" style="background:#080812;margin-bottom:10px">
      <div class="muted" style="font-size:11px;margin-bottom:4px">Preview:</div>
      <div style="font-size:13px"><span class="muted">00:00:00</span>
        <b id="ccName" style="color:<?= e($cur) ?>"><?= e($player['username']) ?>:</b>
        <span id="ccMsg" style="color:<?= e($cur) ?>">This is what your chat messages will look like!</span></div>
    </div>
    <p><button type="submit">Save Chat Settings</button></p>
  </form>
  <script>
  (function(){
    var pick=document.getElementById('ccPick'), hex=document.getElementById('ccHex'),
        nm=document.getElementById('ccName'), msg=document.getElementById('ccMsg');
    if(!pick) return;
    function set(c){ if(!/^#[0-9a-fA-F]{6}$/.test(c)) return; pick.value=c; hex.value=c; nm.style.color=c; msg.style.color=c; }
    document.querySelectorAll('#swatches .swatch').forEach(function(s){ s.addEventListener('click',function(){ set(s.getAttribute('data-color')); }); });
    pick.addEventListener('input',function(){ set(pick.value); });
    hex.addEventListener('input',function(){ set(hex.value); });
  })();
  </script>
</div>

<?php elseif ($sec === 'boards'): ?>
<div class="panel">
  <h3>Message Board Settings</h3>
  <form method="post">
    <input type="hidden" name="action" value="boards">
    <label>Signature &mdash; shown under your board posts (255 max, BBCode ok)</label>
    <p><textarea name="signature" maxlength="255" style="min-height:60px"><?= e($player['signature'] ?? '') ?></textarea></p>
    <p><button type="submit">Save Board Settings</button></p>
  </form>
</div>

<?php elseif ($sec === 'account'): ?>
<div class="panel">
  <h3>Account Credentials</h3>
  <form method="post" style="margin-bottom:16px">
    <input type="hidden" name="action" value="handle">
    <label>Change handle</label>
    <p><input type="text" name="new_username" value="<?= e($player['username']) ?>" maxlength="32"></p>
    <label>Current passkey (to confirm)</label>
    <p><input type="password" name="current_password" autocomplete="current-password"></p>
    <p><button type="submit">Change Handle</button></p>
  </form>
  <form method="post">
    <input type="hidden" name="action" value="password">
    <label>Current passkey</label>
    <p><input type="password" name="current_password" autocomplete="current-password"></p>
    <label>New passkey (8+ chars)</label>
    <p><input type="password" name="new_password" autocomplete="new-password"></p>
    <label>New passkey again</label>
    <p><input type="password" name="new_password2" autocomplete="new-password"></p>
    <p><button type="submit">Change Passkey</button></p>
  </form>
</div>
<?php endif; ?>
