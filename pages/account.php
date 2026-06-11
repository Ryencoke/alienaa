<?php /* pages/account.php — settings hub (tabbed sections) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
// Email column migration
try { $pdo->exec("ALTER TABLE players ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER pass_hash"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE players ADD UNIQUE KEY uq_player_email (email)"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE players ADD COLUMN gender CHAR(1) NOT NULL DEFAULT '' AFTER email"); } catch(Throwable $e){}
$all_themes    = themes();
$all_countries = countries();

$secs = ['profile'=>'Profile','sidebar'=>'Sidebar','schemes'=>'Appearance','chat'=>'Chat','boards'=>'Boards','account'=>'Credentials','goals'=>'Goals','journal'=>'Journal','premium'=>'Subscribe','shards'=>'Shards'];
$sec = $_GET['sec'] ?? 'profile';
if (!isset($secs[$sec])) $sec = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'profile') {
      $bio = trim($_POST['bio'] ?? ''); if (mb_strlen($bio) > 200) $bio = mb_substr($bio, 0, 200);
      $country = strtoupper(trim($_POST['country'] ?? '')); if (!isset($all_countries[$country])) $country = '';
      $gender = ($_POST['gender'] ?? '') === 'F' ? 'F' : 'M';
      $pdo->prepare('UPDATE players SET bio = ?, country = ?, gender = ? WHERE id = ?')->execute([$bio, $country, $gender, $pid]);
      $msg = 'Profile saved.'; $player = current_player();
    }
    elseif ($action === 'journal') {
      $jtext = trim($_POST['journal_text'] ?? '');
      if (mb_strlen($jtext) > 1000) $jtext = mb_substr($jtext, 0, 1000);
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(['journal:'.$pid, $jtext]);
      $msg = 'Journal saved.';
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
    elseif ($action === 'sidebar_topbar') {
      $hide = ($_POST['hide_topbar'] ?? '0') === '1' ? '1' : '0';
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(['hide_topbar:'.$pid, $hide]);
      $msg = $hide ? 'Top bar hidden.' : 'Top bar visible.';
    }
    elseif ($action === 'font') {
      $validFonts = ['default','rajdhani','share_tech_mono','inter','jura','ibm_plex_mono'];
      $font = $_POST['font_choice'] ?? 'default';
      if (!in_array($font, $validFonts, true)) $font = 'default';
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(['font:'.$pid, $font]);
      $msg = 'Font preference saved.';
    }
    elseif ($action === 'handle') {
      $newu = trim($_POST['new_username'] ?? ''); $cur = $_POST['current_password'] ?? '';
      if (!password_verify($cur, $player['pass_hash'])) $msg = 'Current passkey is wrong.';
      elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $newu)) $msg = 'Handle must be 3–32 characters (letters, numbers, underscore).';
      else {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM players WHERE LOWER(username)=LOWER(?) AND id!=?');
        $dup->execute([$newu, $pid]);
        if ((int)$dup->fetchColumn() > 0) {
          $msg = 'That handle is already taken.';
        } else {
          // Block handles too similar to staff names (same letters after stripping digits/symbols)
          $staffQ = $pdo->prepare("SELECT username FROM players WHERE role IN ('admin','manager','moderator','chatmod')");
          $staffQ->execute(); $staffNames = $staffQ->fetchAll(PDO::FETCH_COLUMN);
          $newLetters = preg_replace('/[^a-z]/','',strtolower($newu));
          $staffBlocked = false;
          foreach ($staffNames as $sn) {
            if (strtolower($newu) === strtolower($sn)) { $msg = 'That handle matches a staff member\'s name.'; $staffBlocked = true; break; }
            $snLetters = preg_replace('/[^a-z]/','',strtolower($sn));
            if ($newLetters !== '' && $snLetters !== '' && $newLetters === $snLetters) { $msg = 'That handle is too similar to a staff member\'s name.'; $staffBlocked = true; break; }
          }
          if (!$staffBlocked) {
            try { $pdo->prepare('UPDATE players SET username=? WHERE id=?')->execute([$newu,$pid]); $msg='Handle changed to '.$newu.'.'; $player=current_player(); }
            catch (PDOException $e) { $msg='That handle is taken.'; }
          }
        }
      }
    }
    elseif ($action === 'add_goal') {
      $gtype   = $_POST['goal_type']   ?? 'custom';
      $gtarget = max(0,(int)($_POST['goal_target'] ?? 0));
      $glabel  = trim(mb_substr($_POST['goal_label'] ?? '',0,100));
      $validGTypes = ['level','credits_pocket','credits_bank','combat_wins','custom'];
      if (!in_array($gtype,$validGTypes,true)) $gtype='custom';
      if ($gtype !== 'custom' && $gtarget < 1) throw new RuntimeException('Enter a target value greater than 0.');
      if ($gtype === 'custom' && $glabel === '') throw new RuntimeException('Enter a description for your custom goal.');
      if ($glabel === '') {
        $autoLabels = ['level'=>'Reach level '.$gtarget,'credits_pocket'=>'Save '.number_format($gtarget).' credits (pocket)','credits_bank'=>'Store '.number_format($gtarget).' in bank','combat_wins'=>'Win '.$gtarget.' fights'];
        $glabel = $autoLabels[$gtype] ?? 'Goal';
      }
      $goalsRaw = '';
      try { $gq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $gq->execute(['goals:'.$pid]); $goalsRaw=(string)$gq->fetchColumn(); } catch(Throwable $e){}
      $goals = ($goalsRaw && $goalsRaw !== '0') ? (json_decode($goalsRaw,true) ?: []) : [];
      $goals[] = ['id'=>time().'_'.mt_rand(1000,9999),'type'=>$gtype,'label'=>$glabel,'target'=>$gtype==='custom'?null:$gtarget,'completed'=>false];
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(['goals:'.$pid,json_encode($goals)]);
      $msg = 'Goal added.';
    }
    elseif ($action === 'del_goal') {
      $gid = $_POST['goal_id'] ?? '';
      $goalsRaw = '';
      try { $gq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $gq->execute(['goals:'.$pid]); $goalsRaw=(string)$gq->fetchColumn(); } catch(Throwable $e){}
      $goals = ($goalsRaw && $goalsRaw !== '0') ? (json_decode($goalsRaw,true) ?: []) : [];
      $goals = array_values(array_filter($goals,fn($g)=>($g['id']??'')!==$gid));
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(['goals:'.$pid,json_encode($goals)]);
      $msg = 'Goal removed.';
    }
    elseif ($action === 'complete_goal') {
      $gid = $_POST['goal_id'] ?? '';
      $goalsRaw = '';
      try { $gq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $gq->execute(['goals:'.$pid]); $goalsRaw=(string)$gq->fetchColumn(); } catch(Throwable $e){}
      $goals = ($goalsRaw && $goalsRaw !== '0') ? (json_decode($goalsRaw,true) ?: []) : [];
      foreach ($goals as &$g) { if(($g['id']??'')===$gid){$g['completed']=true;break;} } unset($g);
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(['goals:'.$pid,json_encode($goals)]);
      $msg = 'Goal marked complete!';
    }
    elseif ($action === 'password') {
      $cur = $_POST['current_password'] ?? ''; $n1 = $_POST['new_password'] ?? ''; $n2 = $_POST['new_password2'] ?? '';
      if (!password_verify($cur, $player['pass_hash'])) $msg = 'Current passkey is wrong.';
      elseif (strlen($n1) < 8)                          $msg = 'New passkey must be at least 8 characters.';
      elseif ($n1 !== $n2)                              $msg = 'New passkeys do not match.';
      else { $pdo->prepare('UPDATE players SET pass_hash = ? WHERE id = ?')->execute([password_hash($n1, PASSWORD_DEFAULT), $pid]); $msg = 'Passkey changed.'; $player = current_player(); }
    }
    elseif ($action === 'email') {
      $newem = strtolower(trim($_POST['new_email'] ?? '')); $cur = $_POST['current_password'] ?? '';
      if (!password_verify($cur, $player['pass_hash']))   $msg = 'Current passkey is wrong.';
      elseif (!filter_var($newem, FILTER_VALIDATE_EMAIL)) $msg = 'Enter a valid email address.';
      else {
        try { $pdo->prepare('UPDATE players SET email = ? WHERE id = ?')->execute([$newem, $pid]); $msg = 'Email updated.'; $player = current_player(); }
        catch (PDOException $e) { $msg = 'That email is already in use.'; }
      }
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
  <div id="profile-msg" style="display:none;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.3);border-radius:6px;padding:8px 14px;font-size:13px;color:var(--accent);margin-bottom:10px"></div>
  <form method="post" id="profile-form">
    <input type="hidden" name="action" value="profile">
    <div class="field">
      <span>Country</span>
      <select name="country" id="profile-country" style="max-width:280px">
        <option value="">&mdash; none &mdash;</option>
        <?php foreach ($all_countries as $code => $cn): ?>
          <option value="<?= $code ?>" <?= ($player['country'] ?? '') === $code ? 'selected' : '' ?>><?= e($cn) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="muted" style="font-size:11px;text-transform:none;letter-spacing:0">Your flag shows on your Hideout and profile.</span>
    </div>
    <div class="field">
      <span>Gender</span>
      <div style="display:flex;gap:12px">
        <?php $curGender = ($player['gender'] ?? '') === 'F' ? 'F' : 'M'; ?>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0">
          <input type="radio" name="gender" value="M" <?= $curGender==='M'?'checked':'' ?> style="width:auto;accent-color:#5fa8e8"> <span style="color:#5fa8e8">&#9794; Male</span>
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0">
          <input type="radio" name="gender" value="F" <?= $curGender==='F'?'checked':'' ?> style="width:auto;accent-color:#ff75b5"> <span style="color:#ff75b5">&#9792; Female</span>
        </label>
      </div>
    </div>
    <div class="field">
      <span>Bio / Tagline <span class="muted" style="text-transform:none;letter-spacing:0">(200 max)</span></span>
      <textarea name="bio" maxlength="200" style="min-height:60px"><?= e($player['bio'] ?? '') ?></textarea>
    </div>
    <p style="margin-bottom:12px"><a href="index.php?p=profile&id=<?= (int)$player['id'] ?>">View my profile &rarr;</a></p>
    <button type="submit">Save Profile</button>
  </form>
  <script>
  (function(){
    var form=document.getElementById('profile-form'), msgDiv=document.getElementById('profile-msg');
    if(!form) return;
    form.addEventListener('submit',function(e){
      e.preventDefault();
      var fd=new FormData(form), country=(fd.get('country')||'').toLowerCase();
      fetch('index.php?p=account&sec=profile',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){ return r.ok; })
        .then(function(ok){
          msgDiv.textContent=ok?'Profile saved.':'Save failed — try again.';
          msgDiv.style.display='';
          clearTimeout(msgDiv._t); msgDiv._t=setTimeout(function(){ msgDiv.style.display='none'; },3000);
          // Update flag in sidebar name div
          var nameDiv=document.querySelector('.name');
          if(nameDiv){
            var old=nameDiv.querySelector('img[src*="flagcdn"]'); if(old) old.remove();
            if(country&&/^[a-z]{2}$/.test(country)){
              var img=document.createElement('img');
              img.src='https://flagcdn.com/20x15/'+country+'.png';
              img.width=20; img.height=15; img.alt=country.toUpperCase();
              img.style.cssText='border-radius:2px;vertical-align:middle;box-shadow:0 1px 3px rgba(0,0,0,.3);margin-right:4px';
              var link=nameDiv.querySelector('a'); if(link) link.after(img); else nameDiv.prepend(img);
            }
          }
        }).catch(function(){
          msgDiv.textContent='Save failed — try again.'; msgDiv.style.display='';
        });
    });
  })();
  </script>

<?php elseif ($sec === 'sidebar'):
  $current = player_sidebar($player);
  $nl = nav_links();
  $inSyndicate = false;
  try { $sq = $pdo->prepare('SELECT COUNT(*) FROM syndicate_members WHERE player_id=?'); $sq->execute([$pid]); $inSyndicate = (int)$sq->fetchColumn() > 0; } catch (Throwable $e) {}
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
          <?php if ($k === 'account'): ?>
            <span style="font-size:10px;color:var(--muted);padding:0 5px" title="Account cannot be removed">&#128274;</span>
          <?php else: ?>
            <span class="sbdel">&times;</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="field" style="max-width:280px">
      <span>Add link</span>
      <select id="sbadd">
        <option value="">+ Add link&hellip;</option>
        <?php foreach ($nl as $k => $v): if (!in_array($k, $current, true)): ?>
          <?php if ($k === 'guilds' && !$inSyndicate) continue; ?>
          <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
        <?php endif; endforeach; ?>
      </select>
    </div>
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
    $hideTopbar = false;
    try { $htq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $htq->execute(['hide_topbar:'.$pid]); $hideTopbar = $htq->fetchColumn() === '1'; } catch (Throwable $e) {}
    $barLabels = ['creds'=>'Credits','bank'=>'Bank','shards'=>'Shards','integrity'=>'Health','xp'=>'XP','signal'=>'Signal','cycles'=>'Drive'];
  ?>
  <h3 style="margin-top:24px">Visible Stats</h3>
  <p class="muted">Choose which stats appear in your character card. Level is always shown.</p>
  <div class="stat-toggle-grid" id="stat-bars-grid">
    <?php foreach ($barLabels as $key => $lbl): $chk = in_array($key, $activeBars, true); ?>
    <label class="stat-toggle">
      <input type="checkbox" class="bars-chk" value="<?= e($key) ?>" <?= $chk ? 'checked' : '' ?>>
      <span class="st-label"><?= e($lbl) ?></span>
    </label>
    <?php endforeach; ?>
  </div>

  <h3 style="margin-top:24px">Navigation Display</h3>
  <label class="stat-toggle">
    <input type="checkbox" id="hide-topbar-chk" <?= $hideTopbar ? 'checked' : '' ?>>
    <span class="st-label">Hide top navigation bar</span>
  </label>

  <script>
  (function(){
    // ── Stat bar toggles (realtime, no button) ──────────────────────────────
    var cbx=document.querySelectorAll('#stat-bars-grid .bars-chk');
    function applyLive(key,show){
      var stat=document.querySelector('[data-stat="'+key+'"]'); if(stat) stat.style.display=show?'':'none';
      var bar=document.querySelector('[data-bar="'+key+'"]');  if(bar)  bar.style.display=show?'':'none';
    }
    function saveAndApply(){
      var chosen=[];
      cbx.forEach(function(c){ if(c.checked) chosen.push(c.value); applyLive(c.value,c.checked); });
      var fd=new FormData(); fd.append('action','sidebar_bars');
      chosen.forEach(function(v){ fd.append('bars[]',v); });
      fetch('index.php?p=account&sec=sidebar',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
    }
    cbx.forEach(function(c){ c.addEventListener('change',saveAndApply); });

    // ── Topbar toggle (realtime, no button) ─────────────────────────────────
    var htChk=document.getElementById('hide-topbar-chk');
    if(htChk){
      htChk.addEventListener('change',function(){
        var hide=htChk.checked;
        var tn=document.getElementById('topnav'); if(tn) tn.style.display=hide?'none':'';
        var fd=new FormData(); fd.append('action','sidebar_topbar'); fd.append('hide_topbar',hide?'1':'0');
        fetch('index.php?p=account&sec=sidebar',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
      });
    }

    // ── Quick-links drag/reorder (realtime, no Save button) ─────────────────
    var list=document.getElementById('sblist'), form=document.getElementById('sbform'),
        order=document.getElementById('sborder'), add=document.getElementById('sbadd');
    if(!list) return;
    var labels=<?= json_encode(array_map(function($v){ return $v[0]; }, $nl)) ?>;
    var navData=<?= json_encode(array_map(function($v){ return ['text'=>$v[0],'href'=>$v[1]]; }, $nl)) ?>;
    list.addEventListener('click',function(e){
      var row=e.target.closest('.sbrow'); if(!row) return;
      if(e.target.classList.contains('sbdel')){
        var k=row.getAttribute('data-key');
        if(k==='account') return; // account link is permanent
        row.remove();
        var o=document.createElement('option'); o.value=k; o.textContent=labels[k]||k; add.appendChild(o);
        return;
      }
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
    function getKeys(){ var k=[]; list.querySelectorAll('.sbrow').forEach(function(r){ k.push(r.getAttribute('data-key')); }); return k; }
    function rebuildMenu(keys){
      var menu=document.getElementById('sidemenu'); if(!menu) return;
      var adminLi=menu.querySelector('[data-navkey="admin"]');
      menu.querySelectorAll('[data-navkey]').forEach(function(li){ if(li.getAttribute('data-navkey')!=='admin') li.remove(); });
      var curP=((window.location.href||'').match(/[?&]p=([a-z]+)/)||[])[1]||'home';
      keys.forEach(function(k){
        if(!navData[k]) return;
        var li=document.createElement('li'); li.setAttribute('data-navkey',k);
        var active=navData[k].href.indexOf('p='+curP)!==-1; if(active) li.className='active';
        var a=document.createElement('a'); a.href=navData[k].href; a.textContent=navData[k].text;
        li.appendChild(a); menu.insertBefore(li,adminLi||null);
      });
      var topnav=document.getElementById('topnav'); if(!topnav) return;
      topnav.querySelectorAll('a[data-navkey]').forEach(function(a){ a.remove(); });
      var logoutA=topnav.querySelector('a[href*="p=logout"]');
      keys.forEach(function(k){
        if(!navData[k]) return;
        var a=document.createElement('a'); a.href=navData[k].href; a.setAttribute('data-navkey',k);
        a.textContent=navData[k].text;
        if(navData[k].href.indexOf('p='+curP)!==-1) a.className='active';
        topnav.insertBefore(a,logoutA||null);
      });
    }
    var saveTimer;
    function autoSave(){
      var keys=getKeys(); order.value=keys.join(','); rebuildMenu(keys);
      clearTimeout(saveTimer);
      saveTimer=setTimeout(function(){
        var fd=new FormData(); fd.append('action','sidebar'); fd.append('order',keys.join(','));
        fetch('index.php?p=account&sec=sidebar',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
      },400);
    }
    list.addEventListener('click',function(e){
      if(e.target.classList.contains('sbdel')||e.target.classList.contains('sbmove')) setTimeout(autoSave,0);
    });
    add.addEventListener('change',function(){ setTimeout(autoSave,0); });
    form.addEventListener('submit',function(){ order.value=getKeys().join(','); });
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

  <!-- Font Preference -->
  <?php
  $curFont = 'default';
  try { $fq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $fq->execute(['font:'.$pid]); $fv = $fq->fetchColumn(); if ($fv !== false) $curFont = $fv; } catch (Throwable $e) {}
  $fontOptions = [
    'default'        => ['label'=>'Exo 2 (Default)',        'stack'=>"'Exo 2', sans-serif",             'import'=>'Exo+2:wght@300;400;600;700;900'],
    'rajdhani'       => ['label'=>'Rajdhani (Compact)',      'stack'=>"'Rajdhani', sans-serif",           'import'=>'Rajdhani:wght@400;600;700'],
    'share_tech_mono'=> ['label'=>'Share Tech Mono (Hacker)','stack'=>"'Share Tech Mono', monospace",     'import'=>'Share+Tech+Mono'],
    'inter'          => ['label'=>'Inter (Clean Modern)',    'stack'=>"'Inter', sans-serif",              'import'=>'Inter:wght@300;400;600;700'],
    'jura'           => ['label'=>'Jura (Sci-Fi)',           'stack'=>"'Jura', sans-serif",               'import'=>'Jura:wght@400;600;700'],
    'ibm_plex_mono'  => ['label'=>'IBM Plex Mono (Terminal)','stack'=>"'IBM Plex Mono', monospace",      'import'=>'IBM+Plex+Mono:wght@300;400;600'],
  ];
  ?>
  <hr style="border:none;border-top:1px solid var(--line);margin:20px 0">
  <h3 style="margin-bottom:12px">Font Style</h3>
  <p class="muted" style="font-size:12px;margin-bottom:14px">Changes the body font across the whole site. Selects save instantly. Headings and stats always use Orbitron.</p>
  <div id="font-msg" style="display:none;font-size:12px;color:var(--accent);margin-bottom:8px">&#10003; Font saved.</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-bottom:4px">
    <?php foreach ($fontOptions as $fk => $fo): ?>
    <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid <?= $curFont===$fk ? 'var(--accent)' : 'var(--line)' ?>;border-radius:7px;cursor:pointer;background:<?= $curFont===$fk ? 'rgba(25,240,199,.05)' : 'var(--panel2)' ?>" id="font-label-<?= $fk ?>">
      <input type="radio" name="font_choice" value="<?= $fk ?>" <?= $curFont===$fk ? 'checked' : '' ?> style="width:auto;accent-color:var(--accent)" onchange="previewFont('<?= $fk ?>')">
      <div>
        <div style="font-family:<?= $fo['stack'] ?>;font-weight:600;font-size:13px"><?= $fo['label'] ?></div>
        <div style="font-family:<?= $fo['stack'] ?>;font-size:11px;color:var(--muted)">The quick brown fox 0123</div>
      </div>
    </label>
    <?php endforeach; ?>
  </div>
  <script>
  var fontImports = <?= json_encode(array_combine(array_keys($fontOptions), array_column($fontOptions, 'import'))) ?>;
  var fontStacks  = <?= json_encode(array_combine(array_keys($fontOptions), array_column($fontOptions, 'stack'))) ?>;
  var fontMsg = document.getElementById('font-msg');
  function previewFont(fk) {
    var imp = fontImports[fk], stack = fontStacks[fk];
    if (!imp) return;
    if (fk !== 'default') {
      var lk = document.getElementById('live-font-link');
      if (!lk) { lk = document.createElement('link'); lk.id='live-font-link'; lk.rel='stylesheet'; document.head.appendChild(lk); }
      lk.href = 'https://fonts.googleapis.com/css2?family='+imp+'&display=swap';
    }
    document.body.style.fontFamily = stack;
    document.querySelectorAll('[id^="font-label-"]').forEach(function(el){
      var sel = el.querySelector('input[type=radio]').value === fk;
      el.style.borderColor = sel ? 'var(--accent)' : 'var(--line)';
      el.style.background  = sel ? 'rgba(25,240,199,.05)' : 'var(--panel2)';
    });
    // AJAX save
    var fd=new FormData(); fd.append('action','font'); fd.append('font_choice',fk);
    fetch('index.php?p=account&sec=schemes',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){
        if(fontMsg){ fontMsg.style.display=''; clearTimeout(fontMsg._t); fontMsg._t=setTimeout(function(){ fontMsg.style.display='none'; },2000); }
      }).catch(function(){});
  }
  </script>

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
      <input type="text" name="new_username" id="handle-input" value="<?= e($player['username']) ?>" data-no-counter style="max-width:280px" autocomplete="off">
      <span id="handle-warn" style="display:none;font-size:11px;color:var(--neon2);margin-top:3px">Handle must be 3–32 characters.</span>
    </div>
    <div class="field">
      <span>Current passkey (to confirm)</span>
      <div class="pass-wrap"><input type="password" name="current_password" autocomplete="current-password"><button type="button" class="pass-toggle" onclick="pwToggle(this)" title="Show/hide">&#128065;</button></div>
    </div>
    <button type="submit">Change Handle</button>
  </form>

  <h3>Change Passkey</h3>
  <form method="post" style="margin-bottom:24px">
    <input type="hidden" name="action" value="password">
    <div class="field">
      <span>Current passkey</span>
      <div class="pass-wrap"><input type="password" name="current_password" autocomplete="current-password"><button type="button" class="pass-toggle" onclick="pwToggle(this)" title="Show/hide">&#128065;</button></div>
    </div>
    <div class="field">
      <span>New passkey <span class="muted" style="text-transform:none;letter-spacing:0">(8+ chars)</span></span>
      <div class="pass-wrap"><input type="password" name="new_password" autocomplete="new-password"><button type="button" class="pass-toggle" onclick="pwToggle(this)" title="Show/hide">&#128065;</button></div>
    </div>
    <div class="field">
      <span>New passkey again</span>
      <div class="pass-wrap"><input type="password" name="new_password2" autocomplete="new-password"><button type="button" class="pass-toggle" onclick="pwToggle(this)" title="Show/hide">&#128065;</button></div>
    </div>
    <button type="submit">Change Passkey</button>
  </form>

  <h3>Change Email</h3>
  <p class="muted" style="font-size:12px">Current: <b><?= e($player['email'] ?? '') ?: '<span class="muted">not set</span>' ?></b></p>
  <form method="post">
    <input type="hidden" name="action" value="email">
    <div class="field">
      <span>New email</span>
      <input type="email" name="new_email" autocomplete="email" style="max-width:280px">
    </div>
    <div class="field">
      <span>Current passkey (to confirm)</span>
      <div class="pass-wrap"><input type="password" name="current_password" autocomplete="current-password"><button type="button" class="pass-toggle" onclick="pwToggle(this)" title="Show/hide">&#128065;</button></div>
    </div>
    <button type="submit">Change Email</button>
  </form>
  <script>
  function pwToggle(btn){
    var inp=btn.previousElementSibling;
    inp.type=inp.type==='password'?'text':'password';
    btn.textContent=inp.type==='password'?'👁':'🙈';
  }
  (function(){
    var hi=document.getElementById('handle-input'), hw=document.getElementById('handle-warn');
    if(!hi||!hw) return;
    hi.addEventListener('input',function(){
      var v=hi.value, ok=/^[A-Za-z0-9_]{3,32}$/.test(v);
      hw.textContent = v.length > 32 ? 'Too long — max 32 characters.' : (v.length < 3 && v.length > 0 ? 'Too short — min 3 characters.' : '');
      hw.style.display = !ok && v.length > 0 ? '' : 'none';
    });
  })();
  </script>

<?php elseif ($sec === 'goals'):
  // Load goals
  $goalsRaw = '';
  try { $gq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $gq->execute(['goals:'.$pid]); $goalsRaw=(string)$gq->fetchColumn(); } catch(Throwable $e){}
  $goals = ($goalsRaw && $goalsRaw !== '0') ? (json_decode($goalsRaw,true) ?: []) : [];
  // Load progress values
  $currentLevel   = (int)$player['level'];
  $currentPocket  = (int)$player['creds_pocket'];
  $currentBank    = (int)$player['creds_bank'];
  $currentWins    = 0;
  try { $wq=$pdo->prepare('SELECT COUNT(*) FROM pvp_log WHERE winner_id=?'); $wq->execute([$pid]); $currentWins=(int)$wq->fetchColumn(); } catch(Throwable $e){}
  $progressMap    = ['level'=>$currentLevel,'credits_pocket'=>$currentPocket,'credits_bank'=>$currentBank,'combat_wins'=>$currentWins];
  $typeLabels     = ['level'=>'Level','credits_pocket'=>'Credits (pocket)','credits_bank'=>'Credits (bank)','combat_wins'=>'Combat wins','custom'=>'Custom'];
?>
  <h3 style="margin-bottom:4px">&#127919; Goals</h3>
  <p class="muted" style="font-size:12px;margin-bottom:16px">Set personal goals and track your progress. Goals are private to you.</p>

  <?php if (!empty($goals)):
    $active    = array_filter($goals, fn($g)=>!($g['completed']??false));
    $completed = array_filter($goals, fn($g)=> ($g['completed']??false));
  ?>
  <?php foreach ($active as $g):
    $type     = $g['type'] ?? 'custom';
    $target   = (int)($g['target'] ?? 0);
    $current  = $progressMap[$type] ?? null;
    $pct      = ($type !== 'custom' && $target > 0 && $current !== null) ? min(100, round($current / $target * 100)) : null;
    $done     = $pct !== null && $pct >= 100;
  ?>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:8px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:<?= $pct !== null ? '8' : '0' ?>px">
      <div>
        <div style="font-size:13px;font-weight:600;color:<?= $done ? 'var(--accent)' : 'var(--text)' ?>"><?= e($g['label']) ?></div>
        <?php if ($type !== 'custom' && $target > 0): ?>
        <div style="font-size:11px;color:var(--muted)">
          <?= $typeLabels[$type] ?? $type ?> &middot;
          <?= number_format($current ?? 0) ?> / <?= number_format($target) ?>
          <?php if ($done): ?><span style="color:var(--accent)"> &#10003; Complete!</span><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <?php if (!$done): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="complete_goal">
          <input type="hidden" name="goal_id" value="<?= e($g['id']) ?>">
          <button type="submit" style="font-size:11px;padding:3px 10px;background:rgba(25,240,199,.07);border-color:rgba(25,240,199,.25);color:var(--accent)">&#10003; Done</button>
        </form>
        <?php endif; ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="del_goal">
          <input type="hidden" name="goal_id" value="<?= e($g['id']) ?>">
          <button type="submit" style="font-size:11px;padding:3px 10px;background:rgba(255,45,149,.05);border-color:rgba(255,45,149,.2);color:var(--neon2)">&times;</button>
        </form>
      </div>
    </div>
    <?php if ($pct !== null): ?>
    <div style="height:6px;background:rgba(0,0,0,.3);border-radius:3px;overflow:hidden">
      <div style="height:100%;width:<?= $pct ?>%;background:<?= $done ? 'var(--accent)' : 'var(--neon2)' ?>;border-radius:3px;transition:width .3s"></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if (!empty($completed)): ?>
  <div style="margin-top:12px;margin-bottom:4px;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Completed</div>
  <?php foreach ($completed as $g): ?>
  <div style="background:rgba(25,240,199,.03);border:1px solid rgba(25,240,199,.15);border-radius:7px;padding:9px 12px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:12px;color:var(--muted);text-decoration:line-through"><?= e($g['label']) ?></div>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="del_goal">
      <input type="hidden" name="goal_id" value="<?= e($g['id']) ?>">
      <button type="submit" style="font-size:11px;padding:2px 8px;background:transparent;border-color:rgba(255,45,149,.15);color:rgba(255,45,149,.5)">&times;</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Add new goal -->
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:14px;margin-top:<?= empty($goals) ? '0' : '14' ?>px">
    <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">+ New Goal</div>
    <form method="post" id="goal-form">
      <input type="hidden" name="action" value="add_goal">
      <div class="field" style="max-width:280px;margin-bottom:8px">
        <span>Type</span>
        <select name="goal_type" id="goal-type-sel">
          <option value="level">Reach a level</option>
          <option value="credits_pocket">Credits in pocket</option>
          <option value="credits_bank">Credits in bank</option>
          <option value="combat_wins">Combat wins</option>
          <option value="custom">Custom (text only)</option>
        </select>
      </div>
      <div id="goal-target-wrap" class="field" style="max-width:200px;margin-bottom:8px">
        <span>Target value</span>
        <input type="number" name="goal_target" id="goal-target" min="1" placeholder="e.g. 25">
      </div>
      <div class="field" style="max-width:400px;margin-bottom:10px">
        <span id="goal-label-lbl">Custom label <span class="muted" style="text-transform:none;letter-spacing:0">(optional — auto-generated if blank)</span></span>
        <input type="text" name="goal_label" id="goal-label" maxlength="100" data-no-counter placeholder="e.g. Hit the big leagues">
      </div>
      <button type="submit" style="padding:8px 20px">Add Goal</button>
    </form>
  </div>
  <script>
  (function(){
    var sel=document.getElementById('goal-type-sel');
    var tw=document.getElementById('goal-target-wrap');
    var ll=document.getElementById('goal-label-lbl');
    if(!sel) return;
    sel.addEventListener('change',function(){
      var isCustom=sel.value==='custom';
      if(tw) tw.style.display=isCustom?'none':'';
      if(ll) ll.childNodes[0].nodeValue=isCustom?'Goal description':'Custom label ';
    });
  })();
  </script>

<?php elseif ($sec === 'journal'):
  $journalText = '';
  try { $jq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $jq->execute(['journal:'.$pid]); $journalText=(string)($jq->fetchColumn()?: ''); } catch(Throwable $e){}
  $journalLen = mb_strlen($journalText);
?>
  <h3 style="margin-bottom:4px">&#128214; My Journal</h3>
  <p class="muted" style="font-size:12px;margin-bottom:14px">Your personal journal is visible to other players on your profile. BBCode is supported: <code>[b]bold[/b]</code>, <code>[i]italic[/i]</code>, <code>[u]underline[/u]</code>, <code>[s]strikethrough[/s]</code>. 1,000 character limit.</p>
  <form method="post">
    <input type="hidden" name="action" value="journal">
    <div class="field">
      <span>Journal Entry <span class="muted" id="journal-counter" style="text-transform:none;letter-spacing:0;font-weight:400">(<?= $journalLen ?> / 1000)</span></span>
      <textarea name="journal_text" id="journal-textarea" maxlength="1000" style="min-height:180px"><?= e($journalText) ?></textarea>
    </div>
    <button type="submit">Save Journal</button>
    <?php if ($journalText !== ''): ?>
    <a href="index.php?p=journal&id=<?= $pid ?>" style="margin-left:12px;font-size:12px;color:var(--muted)">Preview &rarr;</a>
    <?php endif; ?>
  </form>
  <script>
  (function(){
    var ta=document.getElementById('journal-textarea'), ctr=document.getElementById('journal-counter');
    if(!ta||!ctr) return;
    ta.addEventListener('input',function(){ ctr.textContent='('+ta.value.length+' / 1000)'; });
  })();
  </script>

<?php elseif ($sec === 'premium'):
  $isSub = is_subscribed($player);
  $subUntil = $player['sub_until'] ?? '';
?>
  <h3>&#9733; Subscriber Status</h3>
  <?php if ($isSub): ?>
  <div style="background:rgba(232,212,77,.06);border:1px solid rgba(232,212,77,.3);border-radius:8px;padding:16px;margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:28px">&#9733;</span>
      <div>
        <div style="font-family:'Orbitron',sans-serif;font-weight:700;color:#e8d44d;font-size:14px">Active Subscriber</div>
        <div style="font-size:12px;color:var(--muted);margin-top:2px">Expires: <?= e(date('M j, Y', strtotime($subUntil))) ?></div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div style="background:rgba(255,45,149,.05);border:1px solid rgba(255,45,149,.2);border-radius:8px;padding:16px;margin-bottom:16px">
    <div style="font-size:13px;color:var(--muted)">You are not currently subscribed.</div>
  </div>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:16px">
    <?php $perks = [['&#9733; Subscriber Star','Gold star next to your name in chat and profiles'],['&#128176; 2&times; Shards','Double Shard drops from the daily vault'],['&#9889; XP Bonus','+10% experience from all sources'],['&#127885; Lounge Access','Exclusive entry to The Static Lounge']]; foreach ($perks as [$icon,$desc]): ?>
    <div style="background:var(--panel2);border:1px solid rgba(232,212,77,.2);border-radius:7px;padding:12px">
      <div style="font-weight:700;font-size:12px;color:#e8d44d;margin-bottom:4px"><?= $icon ?></div>
      <div style="font-size:11px;color:var(--muted)"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:14px;text-align:center">
    <div style="font-size:13px;color:var(--muted);margin-bottom:8px">Subscriptions are managed by Grid Administration.</div>
    <a href="index.php?p=tickets" class="btn btn-ghost">&#127979; Contact Support</a>
  </div>

<?php elseif ($sec === 'shards'):
  $shards = (int)($player['shards'] ?? 0);
?>
  <h3>&#9670; Shards</h3>
  <div style="background:rgba(255,45,149,.05);border:1px solid rgba(255,45,149,.2);border-radius:8px;padding:16px;margin-bottom:16px;display:flex;align-items:center;gap:16px">
    <span style="font-size:36px;color:var(--neon2)">&#9670;</span>
    <div>
      <div style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:24px;color:var(--neon2)"><?= number_format($shards) ?></div>
      <div style="font-size:12px;color:var(--muted);margin-top:2px">Your Shard balance</div>
    </div>
  </div>
  <p class="muted" style="font-size:13px">Shards are the premium currency of the Sprawl. Earn them for free from the daily vault, or as a <a href="index.php?p=account&sec=premium">Subscriber</a> bonus.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:14px">
    <?php $uses = [['&#128176; Exchange','Trade Shards for Creds at the Exchange'],['&#9889; Boost','Purchase temporary stat boosts'],['&#9734; Cosmetics','Unlock exclusive chat colors and titles'],['&#127979; Support','Contact Staff for Shard purchases']]; foreach ($uses as [$icon,$desc]): ?>
    <div style="background:var(--panel2);border:1px solid rgba(255,45,149,.15);border-radius:7px;padding:12px">
      <div style="font-weight:700;font-size:12px;color:var(--neon2);margin-bottom:4px"><?= $icon ?></div>
      <div style="font-size:11px;color:var(--muted)"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
