<?php /* pages/account.php — settings hub (tabbed sections) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$all_themes    = themes();
$all_countries = countries();

$secs = ['profile'=>'Profile','sidebar'=>'Sidebar','schemes'=>'Appearance','chat'=>'Chat','boards'=>'Boards','account'=>'Credentials'];
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
      if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
        $pdo->prepare('UPDATE players SET chat_color = ? WHERE id = ?')->execute([$c, $pid]);
        $msg = 'Chat color updated.'; $player = current_player();
      } else $msg = 'Pick a valid color.';
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
    elseif ($action === 'sidebar_bars') {
      $allowed = ['creds','bank','shards','integrity','xp','signal','cycles'];
      $chosen  = array_values(array_intersect($allowed, (array)($_POST['bars'] ?? [])));
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
          ->execute(['sidebar_bars:' . $pid, implode(',', $chosen)]);
      $msg = 'Stat display updated.';
    }
    elseif ($action === 'handle') {
      $newu = trim($_POST['new_username'] ?? ''); $cur = $_POST['current_password'] ?? '';
      if (!password_verify($cur, $player['pass_hash']))      $msg = 'Current passkey is wrong.';
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
  <?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>
  <div class="tabs">
    <?php foreach ($secs as $k => $lbl): ?>
      <a class="tab <?= $k === $sec ? 'is-active' : '' ?>" href="index.php?p=account&sec=<?= $k ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>

<?php if ($sec === 'profile'): ?>
  <h3>Member Profile</h3>
  <form method="post">
    <input type="hidden" name="action" value="profile">
    <div class="field">
      <span>Country</span>
      <select name="country" style="max-width:280px">
        <option value="">&mdash; none &mdash;</option>
        <?php foreach ($all_countries as $code => $cn): ?>
          <option value="<?= $code ?>" <?= ($player['country'] ?? '') === $code ? 'selected' : '' ?>><?= e($cn) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="muted" style="font-size:11px;text-transform:none;letter-spacing:0">Your flag shows on your Hideout and profile (not in chat).</span>
    </div>
    <div class="field">
      <span>Bio / Tagline <span class="muted" style="text-transform:none;letter-spacing:0">(200 max)</span></span>
      <textarea name="bio" maxlength="200" style="min-height:60px"><?= e($player['bio'] ?? '') ?></textarea>
    </div>
    <p style="margin-bottom:12px"><a href="index.php?p=profile&id=<?= (int)$player['id'] ?>">View my profile &rarr;</a></p>
    <button type="submit">Save Profile</button>
  </form>

<?php elseif ($sec === 'sidebar'):
  $current = player_sidebar($player);
  $nl = nav_links();
?>
  <h3>Sidebar Quick Links</h3>
  <p class="muted">Reorder, add, or remove links from your sidebar navigation.</p>
  <form method="post" action="index.php?p=account&sec=sidebar" id="sbform">
    <input type="hidden" name="action" value="sidebar">
    <input type="hidden" name="order" id="sborder">
    <div id="sblist" style="margin-bottom:12px">
      <?php foreach ($current as $k): ?>
        <div class="sbrow" data-key="<?= e($k) ?>">
          <span class="sbmove" data-dir="up">&#9650;</span>
          <span class="sbmove" data-dir="down">&#9660;</span>
          <span class="sblabel"><?= e($nl[$k][0]) ?></span>
          <span class="sbdel">&times;</span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="field" style="max-width:280px">
      <span>Add link</span>
      <select id="sbadd">
        <option value="">+ Add link&hellip;</option>
        <?php foreach ($nl as $k => $v): if (!in_array($k, $current, true)): ?>
          <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
        <?php endif; endforeach; ?>
      </select>
    </div>
    <button type="submit">Save Sidebar</button>
  </form>
  <form method="post" action="index.php?p=account&sec=sidebar" style="margin-top:8px">
    <input type="hidden" name="action" value="sidebar_reset">
    <button type="submit" class="btn btn-ghost btn-sm">Reset to defaults</button>
  </form>

  <?php
    $sbBarsKey = 'sidebar_bars:' . $pid;
    $sbBarsRaw = '';
    try { $r = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $r->execute([$sbBarsKey]); $sbBarsRaw = (string)$r->fetchColumn(); } catch (Throwable $e) {}
    $activeBars = $sbBarsRaw !== '' ? array_filter(explode(',', $sbBarsRaw)) : ['creds','bank','shards','integrity','xp','signal','cycles'];
    $barLabels = ['creds'=>'Creds','bank'=>'Bank','shards'=>'Shards','integrity'=>'Integrity','xp'=>'XP','signal'=>'Signal','cycles'=>'Cycles'];
  ?>
  <h3 style="margin-top:24px">Visible Stats</h3>
  <p class="muted">Choose which stats appear in your character card. Level is always shown.</p>
  <form method="post" action="index.php?p=account&sec=sidebar">
    <input type="hidden" name="action" value="sidebar_bars">
    <div class="stat-toggle-grid">
      <?php foreach ($barLabels as $key => $lbl): $chk = in_array($key, $activeBars, true); ?>
      <label class="stat-toggle">
        <input type="checkbox" name="bars[]" value="<?= e($key) ?>" <?= $chk ? 'checked' : '' ?>>
        <span class="st-label"><?= e($lbl) ?></span>
      </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" style="margin-top:12px">Save Stat Display</button>
  </form>
  <script>
  (function(){
    var cbx=document.querySelectorAll('input[name="bars[]"]');
    if(!cbx.length) return;
    function applyLive(key,show){
      // Text stats
      var stat=document.querySelector('[data-stat="'+key+'"]');
      if(stat) stat.style.display=show?'':'none';
      // Meter bars
      var bar=document.querySelector('[data-bar="'+key+'"]');
      if(bar) bar.style.display=show?'':'none';
    }
    function saveAndApply(){
      var chosen=[];
      cbx.forEach(function(c){ if(c.checked) chosen.push(c.value); applyLive(c.value,c.checked); });
      var fd=new FormData();
      fd.append('action','sidebar_bars');
      chosen.forEach(function(v){ fd.append('bars[]',v); });
      fetch('index.php?p=account&sec=sidebar',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
    }
    cbx.forEach(function(c){ c.addEventListener('change',saveAndApply); });
  })();
  </script>
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

<?php elseif ($sec === 'schemes'): ?>
  <h3>Appearance &amp; Themes</h3>
  <form method="post">
    <input type="hidden" name="action" value="theme">
    <div class="field" style="max-width:280px">
      <span>Theme</span>
      <select name="theme">
        <?php foreach ($all_themes as $key => $t): ?>
          <option value="<?= e($key) ?>" <?= $key === $curTheme ? 'selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label style="display:inline-flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:13px;cursor:pointer">
        <input type="checkbox" name="use_accent" value="1" <?= $curAccent ? 'checked' : '' ?> style="width:auto;accent-color:var(--accent)">
        Custom accent color (overrides the theme)
      </label>
      <input type="color" name="accent_color" value="<?= e($curAccent ?: '#19f0c7') ?>" style="width:64px;height:36px;padding:2px;margin-top:4px">
    </div>
    <button type="submit">Save Appearance</button>
  </form>

<?php elseif ($sec === 'chat'):
  $cur = $player['chat_color'] ?? '#c9d1e0';
  if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cur)) $cur = '#c9d1e0';
  $nameColor = chat_color($role, '');   // role-based only — same as what chat displays
  $presets = ['#b8a472','#ffffff','#e23b3b','#3bcf63','#4d6be8','#5fe0e0','#ffe14d','#e8a33d','#ff2d95','#9bff3d'];
?>
  <h3>Chat Color</h3>
  <?php if ($role !== 'member'): ?>
    <p class="muted">Your username always appears in your role color (<b style="color:<?= e($nameColor) ?>"><?= e($player['username']) ?></b>). The color you set here applies to your message text only.</p>
  <?php else: ?>
    <p class="muted">Your username color is determined by your role. This color applies to your message text only.</p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="chatcolor">
    <div class="field">
      <span>Pick a preset</span>
      <div id="swatches" style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($presets as $pc): ?>
          <span class="swatch" data-color="<?= $pc ?>" style="background:<?= $pc ?>"></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field" style="flex-direction:row;align-items:center;gap:10px">
      <div>
        <span>Custom color</span>
        <input type="color" id="ccPick" name="chat_color" value="<?= e($cur) ?>" style="width:52px;height:36px;padding:2px">
      </div>
      <div>
        <span>Hex value</span>
        <input type="text" id="ccHex" value="<?= e($cur) ?>" maxlength="7" style="width:100px">
      </div>
    </div>
    <div class="panel" style="background:#080812;margin-bottom:14px">
      <div class="muted" style="font-size:11px;margin-bottom:6px">Preview</div>
      <div style="font-size:13px">
        <span class="muted">00:00:00</span>
        <b id="ccName" style="color:<?= e($nameColor) ?>"><?= e($player['username']) ?>:</b>
        <span id="ccMsg" style="color:<?= e($cur) ?>">This is what your chat messages will look like!</span>
      </div>
    </div>
    <button type="submit">Save Chat Color</button>
  </form>
  <script>
  (function(){
    var pick=document.getElementById('ccPick'), hex=document.getElementById('ccHex'),
        msg=document.getElementById('ccMsg');
    if(!pick) return;
    function set(c){ if(!/^#[0-9a-fA-F]{6}$/.test(c)) return; pick.value=c; hex.value=c; msg.style.color=c; }
    document.querySelectorAll('#swatches .swatch').forEach(function(s){ s.addEventListener('click',function(){ set(s.getAttribute('data-color')); }); });
    pick.addEventListener('input',function(){ set(pick.value); });
    hex.addEventListener('input',function(){ set(hex.value); });
  })();
  </script>

<?php elseif ($sec === 'boards'): ?>
  <h3>Board Settings</h3>
  <form method="post">
    <input type="hidden" name="action" value="boards">
    <div class="field">
      <span>Signature <span class="muted" style="text-transform:none;letter-spacing:0">(255 max, BBCode ok)</span></span>
      <textarea name="signature" maxlength="255" style="min-height:60px"><?= e($player['signature'] ?? '') ?></textarea>
    </div>
    <button type="submit">Save Board Settings</button>
  </form>

<?php elseif ($sec === 'account'): ?>
  <h3>Change Handle</h3>
  <form method="post" style="margin-bottom:24px">
    <input type="hidden" name="action" value="handle">
    <div class="field">
      <span>New handle</span>
      <input type="text" name="new_username" value="<?= e($player['username']) ?>" maxlength="32" style="max-width:280px">
    </div>
    <div class="field">
      <span>Current passkey (to confirm)</span>
      <input type="password" name="current_password" autocomplete="current-password" style="max-width:280px">
    </div>
    <button type="submit">Change Handle</button>
  </form>

  <h3>Change Passkey</h3>
  <form method="post">
    <input type="hidden" name="action" value="password">
    <div class="field">
      <span>Current passkey</span>
      <input type="password" name="current_password" autocomplete="current-password" style="max-width:280px">
    </div>
    <div class="field">
      <span>New passkey <span class="muted" style="text-transform:none;letter-spacing:0">(8+ chars)</span></span>
      <input type="password" name="new_password" autocomplete="new-password" style="max-width:280px">
    </div>
    <div class="field">
      <span>New passkey again</span>
      <input type="password" name="new_password2" autocomplete="new-password" style="max-width:280px">
    </div>
    <button type="submit">Change Passkey</button>
  </form>
<?php endif; ?>
</div>
