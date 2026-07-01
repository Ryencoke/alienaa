<?php /* pages/admin.php — staff admin hub (sub-pages via ?sec=) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$role = $player['role'] ?? 'member';
$canMod   = in_array($role, ['chatmod','moderator','admin','manager'], true);
$canBoardMod = in_array($role, ['moderator','admin','manager'], true); // chatmod = chat only
$canAdmin = in_array($role, ['admin','manager'], true);
$isManager = ($role === 'manager'); // gates the highest-impact tools: impersonation, user creation,
                                     // broadcasts, announcements, and server maintenance.

if (!$canMod) { echo '<script>if(history.length>1){history.back();}else{window.location.href="index.php?p=home";}</script>'; return; }

$sec    = $_GET['sec'] ?? '';
$editId = (int)($_GET['u'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {

    if ($a === 'impersonate' && $isManager) {
      $tgt = trim($_POST['imp_handle'] ?? '');
      $iq  = $pdo->prepare('SELECT id, username, role FROM players WHERE username=?'); $iq->execute([$tgt]); $ti = $iq->fetch();
      if (!$ti) throw new RuntimeException('Ghost not found: ' . htmlspecialchars($tgt, ENT_QUOTES));
      if ((int)$ti['id'] === $pid) throw new RuntimeException("You're already you.");
      try { $pdo->prepare('INSERT INTO admin_log (admin_id, target_id, field, old_value, new_value) VALUES (?,?,?,?,?)')->execute([$pid, (int)$ti['id'], 'impersonate', '', $ti['username']]); } catch (Throwable $e) {}
      $_SESSION['real_pid'] = $pid;
      $_SESSION['pid'] = (int)$ti['id'];
      if (!headers_sent()) header('Location: index.php?p=home'); exit;

    } elseif ($a === 'role_preview' && $isManager) {
      $rv = $_POST['preview_role'] ?? '';
      $allowedRoles = ['','member','chatmod','moderator','admin','manager'];
      if (!in_array($rv, $allowedRoles, true)) throw new RuntimeException('Invalid role.');
      // Only let preview DOWNGRADE — never preview a role above your own real rank.
      $rank = ['member'=>0,'chatmod'=>1,'moderator'=>2,'admin'=>3,'manager'=>4];
      $realRole = ($_SESSION['real_pid'] ?? null) ? ($realPlayer['role'] ?? $role) : $role;
      if ($rv !== '' && ($rank[$rv] ?? 0) > ($rank[$realRole] ?? 0)) throw new RuntimeException('Cannot preview a role above your own.');
      if ($rv === '') { unset($_SESSION['role_override']); $msg = 'Role preview cleared.'; }
      else { $_SESSION['role_override'] = $rv; $msg = 'Viewing as: '.$rv; }

    } elseif ($a === 'jail_player' && $canAdmin) {
      $uid    = (int)($_POST['jail_uid'] ?? 0);
      $reason = trim($_POST['jail_reason'] ?? '');
      $days   = max(1, min(365, (int)($_POST['jail_days'] ?? 1)));
      if (!$reason) throw new RuntimeException('Reason required.');
      try { $pdo->exec("CREATE TABLE IF NOT EXISTS jail_records (id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, reason VARCHAR(500) NOT NULL, days INT NOT NULL DEFAULT 1, jailed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, release_at DATETIME NOT NULL, jailed_by INT NOT NULL, status ENUM('active','released') NOT NULL DEFAULT 'active', released_early_by INT NULL, released_at DATETIME NULL, INDEX idx_player (player_id)) ENGINE=InnoDB"); } catch (Throwable $e) {}
      $pdo->prepare("UPDATE jail_records SET status='released' WHERE player_id=? AND status='active'")->execute([$uid]);
      $pdo->prepare("INSERT INTO jail_records (player_id, reason, days, jailed_at, release_at, jailed_by) VALUES (?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL ? DAY),?)")->execute([$uid, $reason, $days, $days, $pid]);
      $editId = $uid; $msg = "Player jailed for {$days} day(s).";

    } elseif ($a === 'unjail_player' && $canAdmin) {
      $uid = (int)($_POST['unjail_uid'] ?? 0);
      $pdo->prepare("UPDATE jail_records SET status='released', released_early_by=?, released_at=NOW() WHERE player_id=? AND status='active'")->execute([$pid, $uid]);
      $editId = $uid; $msg = 'Player released from jail.';

    } elseif ($a === 'find' && $canAdmin) {
      $h = trim($_POST['handle'] ?? '');
      $r = $pdo->prepare('SELECT id FROM players WHERE username = ?'); $r->execute([$h]);
      $editId = (int)$r->fetchColumn();
      if (!$editId) $msg = 'No ghost with that handle.';
    }
    elseif ($a === 'save_player' && $canAdmin) {
      $uid = (int)($_POST['uid'] ?? 0);
      $old = $pdo->prepare('SELECT * FROM players WHERE id=?'); $old->execute([$uid]); $old = $old->fetch();
      if ($old) {
        $intf = ['level','creds_pocket','creds_bank','shards','integrity','integrity_max','xp','xp_next','signal','signal_max','cycles','cycles_max','loan'];
        $new = [];
        foreach ($intf as $f) $new[$f] = max(0, (int)($_POST[$f] ?? (int)($old[$f] ?? 0)));
        // Role changes are Manager+ only — a non-manager admin's submitted value is
        // ignored outright rather than trusted, even if the UI already hides the select.
        $nr = $isManager ? ($_POST['role'] ?? $old['role']) : $old['role'];
        if (!in_array($nr, ['member','chatmod','moderator','admin','manager'], true)) $nr = $old['role'];
        // Subscription/Accord are edited as "days from today" rather than a raw date.
        $subDays = max(0, (int)($_POST['sub_days'] ?? 0));
        $sub = $subDays > 0 ? date('Y-m-d', strtotime("+{$subDays} days")) : null;
        $accordDays = max(0, (int)($_POST['accord_days'] ?? 0));
        $merchantUntil = $accordDays > 0 ? date('Y-m-d', strtotime("+{$accordDays} days")) : null;
        $emailEdit    = trim($_POST['edit_email'] ?? '');
        if ($emailEdit !== '' && !filter_var($emailEdit, FILTER_VALIDATE_EMAIL)) $emailEdit = '';
        $usernameEdit = trim($_POST['edit_username'] ?? '');
        if ($usernameEdit !== '' && !preg_match('/^[A-Za-z0-9_]{3,32}$/', $usernameEdit)) $usernameEdit = '';
        $birthdayEdit = trim($_POST['edit_birthday'] ?? '');
        if ($birthdayEdit !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdayEdit)) $birthdayEdit = '';
        $bioEdit = mb_substr(trim($_POST['edit_bio'] ?? ''), 0, 200);

        $set = []; $params = [];
        foreach ($intf as $f) { $set[] = ($f === 'signal' ? '`signal`' : $f) . '=?'; $params[] = $new[$f]; }
        $set[] = 'role=?';      $params[] = $nr;
        $set[] = 'sub_until=?';      $params[] = $sub;
        $set[] = 'merchant_until=?'; $params[] = $merchantUntil;
        $set[] = 'bio=?';       $params[] = $bioEdit;
        try { $pdo->exec('ALTER TABLE players ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE players ADD COLUMN IF NOT EXISTS birthday DATE NULL'); } catch (Throwable $e) {}
        if ($emailEdit !== '')    { $set[] = 'email=?';    $params[] = $emailEdit; }
        if ($usernameEdit !== '') { $set[] = 'username=?'; $params[] = $usernameEdit; }
        if ($birthdayEdit !== '') { $set[] = 'birthday=?'; $params[] = $birthdayEdit; }
        $params[] = $uid;
        $pdo->prepare('UPDATE players SET ' . implode(',', $set) . ' WHERE id=?')->execute($params);

        // Update PvP stats
        $pvpFields = ['str_pts','spd_pts','end_pts','unspent'];
        try {
          $pvpNew = []; foreach ($pvpFields as $f) $pvpNew[$f] = max(0, (int)($_POST[$f] ?? 5));
          $pdo->prepare('INSERT INTO player_stats (pid, str_pts, spd_pts, end_pts, unspent) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE str_pts=VALUES(str_pts), spd_pts=VALUES(spd_pts), end_pts=VALUES(end_pts), unspent=VALUES(unspent)')->execute([$uid, $pvpNew['str_pts'], $pvpNew['spd_pts'], $pvpNew['end_pts'], $pvpNew['unspent']]);
        } catch (Throwable $e) {}

        $logp = $pdo->prepare('INSERT INTO admin_log (admin_id, target_id, field, old_value, new_value) VALUES (?,?,?,?,?)');
        $log = function ($f, $ov, $nv) use ($logp, $pid, $uid) {
          if ((string)$ov !== (string)$nv) { try { $logp->execute([$pid, $uid, $f, (string)$ov, (string)$nv]); } catch (Throwable $e) {} }
        };
        foreach ($intf as $f) $log($f, $old[$f] ?? '', $new[$f]);
        $log('role', $old['role'] ?? '', $nr);
        $log('sub_until', $old['sub_until'] ?? '', $sub);
        $log('merchant_until', $old['merchant_until'] ?? '', $merchantUntil);
        if ($usernameEdit) $log('username', $old['username'] ?? '', $usernameEdit);
        if ($birthdayEdit) $log('birthday', $old['birthday'] ?? '', $birthdayEdit);
        $log('bio', $old['bio'] ?? '', $bioEdit);

        $editId = $uid; $msg = 'Player updated.';
      } else { $msg = 'No such player.'; }
    }
    elseif ($a === 'save_player_items' && $canAdmin) {
      $uid = (int)($_POST['uid'] ?? 0);
      if (!$uid) throw new RuntimeException('No player selected.');
      $ids  = (array)($_POST['item_id']  ?? []);
      $qtys = (array)($_POST['item_qty'] ?? []);
      foreach ($ids as $i => $iid) {
        $iid = (int)$iid; $qty = max(0, (int)($qtys[$i] ?? 0));
        if ($iid <= 0) continue;
        if ($qty === 0) {
          $pdo->prepare('DELETE FROM player_items WHERE player_id=? AND item_id=?')->execute([$uid, $iid]);
        } else {
          $pdo->prepare('INSERT INTO player_items (player_id,item_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty)')->execute([$uid, $iid, $qty]);
        }
      }
      $editId = $uid; $msg = 'Inventory updated.';
    }
    elseif ($a === 'create_user' && $isManager) {
      $nu = trim($_POST['new_username'] ?? '');
      $ne = trim($_POST['new_email'] ?? '');
      $np = $_POST['new_pass'] ?? '';
      if (!$nu || !$ne || !$np) throw new RuntimeException('All fields required.');
      if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $nu)) throw new RuntimeException('Username: 3-24 chars, letters/numbers/underscore.');
      if (!filter_var($ne, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');
      if (strlen($np) < 6) throw new RuntimeException('Password min 6 chars.');
      $chk = $pdo->prepare('SELECT id FROM players WHERE username=? OR email=?'); $chk->execute([$nu,$ne]);
      if ($chk->fetch()) throw new RuntimeException('Username or email already taken.');
      $pdo->prepare('INSERT INTO players (username,email,pass_hash) VALUES (?,?,?)')->execute([$nu,$ne,password_hash($np,PASSWORD_DEFAULT)]);
      $editId = (int)$pdo->lastInsertId();
      $msg = "User #{$editId} ({$nu}) created.";
      // Redirect straight to edit page so the link works
      echo '<script>location.replace("index.php?p=admin&sec=editplayer&u='.$editId.'");</script>'; return;
    }
    elseif ($a === 'trigger_reset' && $isManager) {
      // Capture pre-reset counts for summary
      $rTotal   = (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
      $rHealth  = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE integrity < integrity_max')->fetchColumn();
      $rSignal  = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE signal < signal_max')->fetchColumn();
      $rDriveNs = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE (sub_until IS NULL OR sub_until < CURDATE()) AND cycles < 500")->fetchColumn();
      $rDriveSb = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE sub_until >= CURDATE() AND cycles < 1500")->fetchColumn();
      // Run daily reset for all players
      $pdo->exec("UPDATE players SET integrity = integrity_max, `signal` = signal_max,
        cycles = LEAST(CASE WHEN sub_until >= CURDATE() THEN 1500 ELSE 500 END, cycles + 250)");
      // Skillsoft decay (same as lazy per-player reset)
      try { $pdo->exec("UPDATE player_skills SET points = CASE
        WHEN points > 0 AND points < 500  THEN GREATEST(0, points - 1)
        WHEN points >= 500 AND points < 1000 THEN GREATEST(0, points - 2)
        ELSE points END"); } catch (Throwable $e) {}
      // Clear per-player daily_reset keys so lazy-eval doesn't skip them
      $pdo->exec("DELETE FROM settings WHERE k LIKE 'daily_reset:%'");
      $msg = "Daily reset triggered. {$rTotal} players affected: "
           . "{$rHealth} had Health restored, {$rSignal} had Signal restored, "
           . ($rDriveNs+$rDriveSb) . " received +250 Drive ({$rDriveSb} subscribers, {$rDriveNs} non-subscribers).";
    }
    elseif ($a === 'del_chat' && $canMod) {
      $pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]); $msg = 'Chat message deleted.';
    }
    elseif ($a === 'del_post' && $canBoardMod) {
      $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]); $msg = 'Post deleted.';
    }
    elseif ($a === 'del_topic' && $canBoardMod) {
      $tid = (int)($_POST['id'] ?? 0);
      $pdo->prepare('DELETE FROM posts WHERE topic_id=?')->execute([$tid]);
      $pdo->prepare('DELETE FROM topics WHERE id=?')->execute([$tid]); $msg = 'Topic deleted.';
    }
    elseif ($a === 'del_update' && $isManager) {
      $uidd = (int)($_POST['id'] ?? 0);
      $pdo->prepare('DELETE FROM updates WHERE id=?')->execute([$uidd]);
      try { $pdo->prepare('DELETE FROM update_votes WHERE update_id=?')->execute([$uidd]); } catch (Throwable $e) {}
      $msg = 'Update deleted.';
    }
    elseif ($a === 'edit_update' && $isManager) {
      $uidd = (int)($_POST['id'] ?? 0); $b = trim($_POST['body'] ?? '');
      if ($b !== '') { $pdo->prepare('UPDATE updates SET body=? WHERE id=?')->execute([$b, $uidd]); $msg = 'Update edited.'; }
    }
    elseif ($a === 'broadcast' && $isManager) {
      $bBody   = trim($_POST['broadcast_body'] ?? '');
      $bTarget = $_POST['broadcast_target'] ?? 'all';
      if ($bBody === '') throw new RuntimeException('Write a message first.');
      if (mb_strlen($bBody) > 500) $bBody = mb_substr($bBody, 0, 500);
      if (!in_array($bTarget, ['all','online','subs','staff'], true)) $bTarget = 'all';
      try { $pdo->exec('CREATE TABLE IF NOT EXISTS player_notifications (id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, type VARCHAR(40) NOT NULL DEFAULT "info", body TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_player_read (player_id, is_read)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch (Throwable $e) {}
      $wheres = [
        'all'    => '1=1',
        'online' => 'last_seen >= (NOW() - INTERVAL 15 MINUTE)',
        'subs'   => 'sub_until >= CURDATE()',
        'staff'  => "role IN ('chatmod','moderator','admin','manager')",
      ];
      $bc = $pdo->prepare("INSERT INTO player_notifications (player_id, type, body)
                           SELECT id, 'announce', ? FROM players WHERE {$wheres[$bTarget]}");
      $bc->execute(['[Staff] ' . htmlspecialchars($bBody, ENT_QUOTES)]);
      $sent = $bc->rowCount();
      try { $pdo->prepare('INSERT INTO admin_log (admin_id, target_id, field, old_value, new_value) VALUES (?,?,?,?,?)')
                ->execute([$pid, 0, 'broadcast:'.$bTarget, '', mb_substr($bBody, 0, 120)]); } catch (Throwable $e) {}
      $msg = "Broadcast sent to " . number_format($sent) . " player" . ($sent === 1 ? '' : 's') . " ({$bTarget}).";
    }
    elseif ($a === 'grant_player' && $canAdmin) {
      $gHandle = trim($_POST['grant_handle'] ?? '');
      $gField  = $_POST['grant_field'] ?? '';
      $gAmt    = (int)($_POST['grant_amount'] ?? 0);
      $gReason = trim($_POST['grant_reason'] ?? '');
      $gNotify = !empty($_POST['grant_notify']);
      $gFields = ['creds_pocket'=>'Pocket Credits','creds_bank'=>'Bank Credits','shards'=>'Shards','cycles'=>'Drive','integrity'=>'Health','xp'=>'XP'];
      if (!isset($gFields[$gField])) throw new RuntimeException('Pick a valid currency/stat.');
      if ($gAmt === 0) throw new RuntimeException('Amount cannot be zero (use a negative number to deduct).');
      if (abs($gAmt) > 100000000) throw new RuntimeException('Amount out of range.');
      if ($gReason === '') throw new RuntimeException('A reason is required — it goes in the audit log.');
      $gq = $pdo->prepare('SELECT id, username FROM players WHERE username=?'); $gq->execute([$gHandle]); $gTarget = $gq->fetch();
      if (!$gTarget) throw new RuntimeException('Ghost not found: ' . htmlspecialchars($gHandle, ENT_QUOTES));
      $gid = (int)$gTarget['id'];
      // Whitelisted column; floor at zero so deductions can't go negative
      $pdo->prepare("UPDATE players SET {$gField} = GREATEST(0, {$gField} + ?) WHERE id = ?")->execute([$gAmt, $gid]);
      try { $pdo->prepare('INSERT INTO admin_log (admin_id, target_id, field, old_value, new_value) VALUES (?,?,?,?,?)')
                ->execute([$pid, $gid, 'grant:'.$gField, ($gAmt > 0 ? '+' : '').$gAmt, mb_substr($gReason, 0, 150)]); } catch (Throwable $e) {}
      try { $pdo->prepare('INSERT INTO tx_log (from_id, to_id, kind, amount, note) VALUES (?,?,?,?,?)')
                ->execute([null, $gid, 'admin_grant', abs($gAmt), $gField.' '.($gAmt > 0 ? '+' : '').$gAmt.' — '.mb_substr($gReason, 0, 100)]); } catch (Throwable $e) {}
      if ($gNotify) {
        try { $pdo->prepare('INSERT INTO player_notifications (player_id, type, body) VALUES (?,?,?)')
                  ->execute([$gid, 'info', '[Staff] '.($gAmt > 0 ? 'Granted +' : 'Adjusted ').number_format($gAmt).' '.$gFields[$gField].'. Reason: '.htmlspecialchars($gReason, ENT_QUOTES)]); } catch (Throwable $e) {}
      }
      $msg = ($gAmt > 0 ? 'Granted +' : 'Deducted ') . number_format(abs($gAmt)) . ' ' . $gFields[$gField] . ' ' . ($gAmt > 0 ? 'to' : 'from') . ' ' . e($gTarget['username']) . '.';
    }

  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$flash = $msg ? '<div class="flash">'.e($msg).'</div>' : '';
$back  = '<p class="muted"><a href="index.php?p=admin">&laquo; Admin</a></p>';

/* ============================ EDIT PLAYERS ============================ */
if ($sec === 'editplayer' && $canAdmin) {
  $t = null;
  if ($editId) { $ep = $pdo->prepare('SELECT * FROM players WHERE id=?'); $ep->execute([$editId]); $t = $ep->fetch(); }
  // Days remaining until a date column (0 if blank/past) — used for Subscription/Accord.
  $adm_days_until = function ($dateStr) {
    if (empty($dateStr)) return 0;
    return max(0, (int)ceil((strtotime($dateStr) - strtotime(date('Y-m-d'))) / 86400));
  };
  ?>
  <style>
  .adm-ep-sec{background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:14px}
  .adm-ep-sec h4{margin:0 0 12px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--accent);display:flex;align-items:center;gap:6px}
  .adm-ep-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 12px}
  .adm-ep-grid .field{margin-bottom:0}
  </style>
  <div class="panel">
    <h2>Edit Players</h2>
    <?= $back ?><?= $flash ?>
    <form method="post" style="margin-bottom:14px">
      <input type="hidden" name="action" value="find">
      <label>Find by handle</label>
      <div style="position:relative;max-width:280px">
        <p style="display:flex;gap:6px;margin:0"><input type="text" name="handle" id="adminFind" autocomplete="off" maxlength="32" data-no-counter placeholder="Ghost's handle"><button type="submit">Find</button></p>
        <div id="adminAC" style="position:absolute;left:0;right:42px;top:34px;background:var(--panel);border:1px solid var(--accent);border-radius:4px;z-index:20;display:none;max-height:200px;overflow:auto"></div>
      </div>
    </form>
    <script>
    (function(){
      var inp=document.getElementById('adminFind'), box=document.getElementById('adminAC'); if(!inp) return;
      var t;
      inp.addEventListener('input',function(){
        clearTimeout(t); var q=inp.value.trim();
        if(q.length<1){ box.style.display='none'; return; }
        t=setTimeout(function(){
          fetch('admin_api.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(list){
            if(!list||!list.length){ box.style.display='none'; return; }
            box.innerHTML='';
            list.forEach(function(u){ var a=document.createElement('a'); a.href='index.php?p=admin&sec=editplayer&u='+u.id;
              a.textContent=u.username; a.style.display='block'; a.style.padding='5px 8px'; a.style.fontSize='12px'; box.appendChild(a); });
            box.style.display='block';
          }).catch(function(){});
        },150);
      });
      document.addEventListener('click',function(e){ if(box && !box.contains(e.target) && e.target!==inp) box.style.display='none'; });
    })();
    </script>
    <?php if ($t):
      // Load PvP stats
      $pvpS = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];
      try { $pq = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $pq->execute([(int)$t['id']]); $ps = $pq->fetch(); if ($ps) $pvpS = $ps; } catch (Throwable $e) {}
      // Load jail status
      $curJail = null;
      try { $jq = $pdo->prepare("SELECT j.*, s.username AS staff_name FROM jail_records j JOIN players s ON s.id=j.jailed_by WHERE j.player_id=? AND j.status='active' AND j.release_at>NOW() LIMIT 1"); $jq->execute([(int)$t['id']]); $curJail = $jq->fetch() ?: null; } catch (Throwable $e) {}
      // Load syndicate
      $syndName = null;
      try { $sq = $pdo->prepare('SELECT s.name FROM syndicate_members m JOIN syndicates s ON s.id=m.syndicate_id WHERE m.player_id=? LIMIT 1'); $sq->execute([(int)$t['id']]); $syndName = $sq->fetchColumn() ?: null; } catch (Throwable $e) {}
      $tCol = chat_color($t['role'] ?? 'member', '');
    ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="width:44px;height:44px;border-radius:9px;background:var(--panel);border:2px solid <?= e($tCol) ?>;display:flex;align-items:center;justify-content:center;font-family:'Orbitron',sans-serif;font-weight:900;font-size:18px;color:<?= e($tCol) ?>;flex:none"><?= e(mb_strtoupper(mb_substr($t['username'],0,1))) ?></div>
      <div style="flex:1;min-width:180px">
        <div style="font-size:17px;font-weight:700;color:<?= e($tCol) ?>"><?= e($t['username']) ?> <?= role_tag($t['role'] ?? '', 'font-size:11px;margin-left:4px') ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">ID <?= (int)$t['id'] ?> &middot; Level <?= (int)$t['level'] ?> &middot; Joined <?= e(date('M j, Y', strtotime($t['created_at'] ?? 'now'))) ?></div>
        <?php if ($syndName): ?><div style="font-size:11px;color:var(--accent);margin-top:3px">&#128101; <?= e($syndName) ?></div><?php endif; ?>
        <?php if ($curJail): ?><div style="font-size:11px;color:var(--neon2);margin-top:3px">&#128274; JAILED — <?= e($curJail['reason']) ?> &middot; <?= ceil((strtotime($curJail['release_at'])-time())/86400) ?>d left</div><?php endif; ?>
      </div>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="save_player">
      <input type="hidden" name="uid" value="<?= (int)$t['id'] ?>">

      <div class="adm-ep-sec">
        <h4>&#128176; Currency &amp; Progression</h4>
        <div class="adm-ep-grid">
          <?php foreach (['level'=>'Level','creds_pocket'=>'Credits (pocket)','creds_bank'=>'Credits (bank)','shards'=>'Shards','xp'=>'XP','xp_next'=>'XP next','loan'=>'Loan (owed)'] as $k=>$lbl): ?>
            <div class="field"><span><?= $lbl ?></span><input type="number" name="<?= $k ?>" value="<?= (int)($t[$k] ?? 0) ?>"></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="adm-ep-sec">
        <h4>&#10084; Vitals</h4>
        <div class="adm-ep-grid">
          <?php foreach (['integrity'=>'Health','integrity_max'=>'Health max','signal'=>'Signal','signal_max'=>'Signal max','cycles'=>'Drive','cycles_max'=>'Drive max'] as $k=>$lbl): ?>
            <div class="field"><span><?= $lbl ?></span><input type="number" name="<?= $k ?>" value="<?= (int)($t[$k] ?? 0) ?>"></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="adm-ep-sec">
        <h4>&#128100; Account</h4>
        <div class="adm-ep-grid">
          <div class="field"><span>Email</span><input type="email" name="edit_email" value="<?= e($t['email'] ?? '') ?>" placeholder="(optional)"></div>
          <div class="field"><span>Username</span><input type="text" name="edit_username" value="<?= e($t['username'] ?? '') ?>" maxlength="32" data-no-counter></div>
          <div class="field"><span>Birthday</span><input type="date" name="edit_birthday" value="<?= e($t['birthday'] ?? '') ?>"></div>
        </div>
        <div class="field" style="margin-top:10px;margin-bottom:0"><span>Bio</span><textarea name="edit_bio" maxlength="200" style="width:100%;min-height:50px"><?= e($t['bio'] ?? '') ?></textarea></div>
      </div>

      <div class="adm-ep-sec">
        <h4>&#128272; Access &amp; Status</h4>
        <div class="adm-ep-grid">
          <div class="field">
            <span>Role<?= $isManager ? '' : ' (Manager+ only)' ?></span>
            <?php if ($isManager): ?>
            <select name="role">
              <?php foreach (['member','chatmod','moderator','admin','manager'] as $rr): ?>
                <option value="<?= $rr ?>" <?= ($t['role'] ?? 'member') === $rr ? 'selected' : '' ?>><?= $rr ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" value="<?= e($t['role'] ?? 'member') ?>" disabled title="Only Managers can change a player's role">
            <?php endif; ?>
          </div>
          <div class="field"><span>Subscription days</span><input type="number" name="sub_days" min="0" value="<?= $adm_days_until($t['sub_until'] ?? null) ?>">
            <span class="muted" style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0">0 = not subscribed</span>
          </div>
          <div class="field"><span>Accord days</span><input type="number" name="accord_days" min="0" value="<?= $adm_days_until($t['merchant_until'] ?? null) ?>">
            <span class="muted" style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0">0 = not enlisted</span>
          </div>
        </div>
      </div>

      <div class="adm-ep-sec">
        <h4>&#9876; Combat Stats (PvP)</h4>
        <div class="adm-ep-grid">
          <div class="field"><span>Strength</span><input type="number" name="str_pts" min="1" value="<?= (int)$pvpS['str_pts'] ?>"></div>
          <div class="field"><span>Speed</span><input type="number" name="spd_pts" min="1" value="<?= (int)$pvpS['spd_pts'] ?>"></div>
          <div class="field"><span>Endurance</span><input type="number" name="end_pts" min="1" value="<?= (int)$pvpS['end_pts'] ?>"></div>
          <div class="field"><span>Unspent Points</span><input type="number" name="unspent" min="0" value="<?= (int)$pvpS['unspent'] ?>"></div>
        </div>
      </div>

      <button type="submit">Save Player</button>
    </form>

    <?php
    // Items editing section
    $allItems = [];
    try { $allItems = $pdo->query('SELECT id, name, category FROM items ORDER BY category, name')->fetchAll(); } catch (Throwable $e) {}
    $playerItemQtys = [];
    try { $piq = $pdo->prepare('SELECT item_id, qty FROM player_items WHERE player_id=?'); $piq->execute([(int)$t['id']]); foreach ($piq as $pi) $playerItemQtys[(int)$pi['item_id']] = (int)$pi['qty']; } catch (Throwable $e) {}
    if ($allItems):
      $grouped = [];
      foreach ($allItems as $it) $grouped[$it['category'] ?: 'Other'][] = $it;
    ?>
    <div class="adm-ep-sec" style="margin-top:14px">
      <h4>&#127918; Inventory Items</h4>
      <form method="post">
        <input type="hidden" name="action" value="save_player_items">
        <input type="hidden" name="uid" value="<?= (int)$t['id'] ?>">
        <?php foreach ($grouped as $cat => $items): ?>
        <div style="margin-bottom:14px">
          <div style="font-size:11px;color:var(--accent);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px"><?= e($cat) ?></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">
            <?php foreach ($items as $it): ?>
            <div style="display:flex;align-items:center;gap:6px">
              <input type="hidden" name="item_id[]" value="<?= (int)$it['id'] ?>">
              <input type="number" name="item_qty[]" value="<?= (int)($playerItemQtys[(int)$it['id']] ?? 0) ?>" min="0" style="width:60px;font-size:11px">
              <label style="font-size:11px;color:var(--muted)"><?= e($it['name']) ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <button type="submit" style="font-size:12px">Save Inventory</button>
      </form>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ RECORDS HALL ============================ */
if ($sec === 'records' && $canAdmin) {
  $validFilters = [
    'total'     => ['All Players',       '1=1',                                              ''],
    'online'    => ['Online Now',        "last_seen >= (NOW() - INTERVAL 5 MINUTE)",         '#3bcf63'],
    'subs'      => ['Subscribers',       "sub_until >= CURDATE()",                           '#e8d44d'],
    'good'      => ['Good Alignment',    "mortality > 0",                                    '#e8d44d'],
    'evil'      => ['Evil Alignment',    "mortality < 0",                                    'var(--neon2)'],
    'neutral'   => ['Neutral Alignment', "mortality = 0",                                    'var(--muted)'],
    'new_today' => ['Joined Today',      "created_at >= (NOW() - INTERVAL 24 HOUR)",         'var(--accent)'],
    'new_week'  => ['Joined This Week',  "created_at >= (NOW() - INTERVAL 7 DAY)",           'var(--accent)'],
  ];
  $filter  = isset($validFilters[$_GET['filter'] ?? '']) ? $_GET['filter'] : 'total';
  $page    = max(1, (int)($_GET['page'] ?? 1));
  $perPage = 50;
  $offset  = ($page - 1) * $perPage;
  [$filterLabel, $filterWhere, $filterColor] = $validFilters[$filter];

  $total = 0; $rows = [];
  try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE {$filterWhere}")->fetchColumn();
    $rows  = $pdo->query("SELECT id, username, level, role, mortality, gender, last_seen, created_at, sub_until FROM players WHERE {$filterWhere} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}")->fetchAll();
  } catch (Throwable $e) {}

  $pages  = max(1, (int)ceil($total / $perPage));
  $qBase  = 'index.php?p=admin&sec=records&filter=' . $filter;
  ?>
  <div class="panel">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <div>
        <h2 style="margin:0">&#128196; Records Hall</h2>
        <p class="muted" style="margin:4px 0 0;font-size:12px">
          <?= $filterColor ? '<span style="color:'.$filterColor.'">' : '' ?><?= e($filterLabel) ?><?= $filterColor ? '</span>' : '' ?>
          &mdash; <?= number_format($total) ?> player<?= $total !== 1 ? 's' : '' ?>
        </p>
      </div>
      <?= $back ?>
    </div>
    <!-- Filter tabs -->
    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px">
      <?php foreach ($validFilters as $fk => [$fl,$fw,$fc]): ?>
      <a href="index.php?p=admin&sec=records&filter=<?= $fk ?>"
         style="padding:4px 10px;border-radius:5px;font-size:11px;text-decoration:none;border:1px solid <?= $filter===$fk ? ($fc ?: 'var(--accent)') : 'var(--line)' ?>;color:<?= $filter===$fk ? ($fc ?: 'var(--accent)') : 'var(--muted)' ?>;background:<?= $filter===$fk ? 'rgba(25,240,199,.07)' : 'var(--panel2)' ?>"><?= e($fl) ?></a>
      <?php endforeach; ?>
    </div>
    <!-- Player table -->
    <?php if (empty($rows)): ?>
      <p class="muted">No players match this filter.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <tr style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">
          <th>ID</th><th>Username</th><th>Level</th><th>Role</th><th>Alignment</th><th>Last Seen</th><th>Joined</th><th></th>
        </tr>
        <?php foreach ($rows as $r):
          $mort = (int)$r['mortality'];
          $mortStr = $mort > 0 ? '<span style="color:#e8d44d">&#9728; +' . $mort . '</span>' : ($mort < 0 ? '<span style="color:var(--neon2)">&#9760; ' . $mort . '</span>' : '<span style="color:var(--muted)">—</span>');
          $lsAgo = '';
          if ($r['last_seen']) { $diff = time() - strtotime($r['last_seen']); $lsAgo = $diff < 60 ? 'now' : ($diff < 3600 ? round($diff/60).'m' : ($diff < 86400 ? round($diff/3600).'h' : round($diff/86400).'d')); }
          $isOnline = $r['last_seen'] && (time() - strtotime($r['last_seen'])) < 300;
          $isSub = !empty($r['sub_until']) && $r['sub_until'] >= date('Y-m-d');
        ?>
        <tr style="font-size:12px">
          <td class="muted"><?= (int)$r['id'] ?></td>
          <td>
            <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$r['id'] ?>" style="font-weight:700"><?= e($r['username']) ?></a>
            <?php if ($isSub): ?><span style="color:#e8d44d;font-size:10px" title="Subscriber">&#9733;</span><?php endif; ?>
          </td>
          <td><?= (int)$r['level'] ?></td>
          <td><?= $r['role'] !== 'member' ? role_tag($r['role']) : '<span style="color:var(--muted);font-size:11px">Member</span>' ?></td>
          <td><?= $mortStr ?></td>
          <td style="color:<?= $isOnline ? '#3bcf63' : 'var(--muted)' ?>"><?= $lsAgo ?: '—' ?><?= $isOnline ? ' &#9679;' : '' ?></td>
          <td class="muted" style="font-size:11px"><?= $r['created_at'] ? e(date('M j, Y', strtotime($r['created_at']))) : '—' ?></td>
          <td><a href="index.php?p=profile&id=<?= (int)$r['id'] ?>" style="font-size:11px;color:var(--muted)">Profile</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div style="display:flex;align-items:center;gap:6px;margin-top:14px;flex-wrap:wrap">
      <?php if ($page > 1): ?><a href="<?= $qBase ?>&page=<?= $page-1 ?>" style="padding:4px 10px;border:1px solid var(--line);border-radius:5px;font-size:12px;color:var(--muted);text-decoration:none">&laquo; Prev</a><?php endif; ?>
      <?php for ($pg = max(1,$page-3); $pg <= min($pages,$page+3); $pg++): ?>
      <a href="<?= $qBase ?>&page=<?= $pg ?>" style="padding:4px 10px;border:1px solid <?= $pg===$page?'var(--accent)':'var(--line)' ?>;border-radius:5px;font-size:12px;color:<?= $pg===$page?'var(--accent)':'var(--muted)' ?>;background:<?= $pg===$page?'rgba(25,240,199,.08)':'var(--panel2)' ?>;text-decoration:none"><?= $pg ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= $qBase ?>&page=<?= $page+1 ?>" style="padding:4px 10px;border:1px solid var(--line);border-radius:5px;font-size:12px;color:var(--muted);text-decoration:none">Next &raquo;</a><?php endif; ?>
      <span style="font-size:11px;color:var(--muted);margin-left:4px">Page <?= $page ?> of <?= $pages ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ COMBAT LOG ============================ */
if ($sec === 'combat' && $canAdmin) {
  $filterPid = (int)($_GET['pid'] ?? 0);
  $page    = max(1, (int)($_GET['page'] ?? 1));
  $perPage = 50;
  $offset  = ($page - 1) * $perPage;
  $filterName = '';
  if ($filterPid) {
    try { $fn = $pdo->prepare('SELECT username FROM players WHERE id=?'); $fn->execute([$filterPid]); $filterName = $fn->fetchColumn() ?: ''; } catch (Throwable $e) {}
  }
  $whereClause = $filterPid ? 'WHERE l.attacker_id=:pid OR l.defender_id=:pid' : '';
  $total = 0; $fights = [];
  try {
    $cntQ = $pdo->prepare("SELECT COUNT(*) FROM pvp_log l $whereClause");
    if ($filterPid) $cntQ->bindValue(':pid', $filterPid, PDO::PARAM_INT);
    $cntQ->execute(); $total = (int)$cntQ->fetchColumn();
    $fq = $pdo->prepare("SELECT l.id, l.attacker_id, l.defender_id, l.winner_id, l.rounds, l.atk_xp, l.def_xp, l.credits_looted, l.fought_at,
              a.username AS atk_name, d.username AS def_name, w.username AS winner_name
            FROM pvp_log l JOIN players a ON a.id=l.attacker_id JOIN players d ON d.id=l.defender_id JOIN players w ON w.id=l.winner_id
            $whereClause ORDER BY l.fought_at DESC LIMIT $perPage OFFSET $offset");
    if ($filterPid) $fq->bindValue(':pid', $filterPid, PDO::PARAM_INT);
    $fq->execute(); $fights = $fq->fetchAll();
  } catch (Throwable $e) {}
  $pages = max(1, (int)ceil($total / $perPage));
  $qBase = 'index.php?p=admin&sec=combat' . ($filterPid ? '&pid='.$filterPid : '');
  ?>
  <div class="panel">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <div>
        <h2 style="margin:0">&#9876; Combat Log</h2>
        <?php if ($filterName): ?><p class="muted" style="margin:4px 0 0;font-size:12px">Filtered: <b><?= e($filterName) ?></b> &mdash; <?= number_format($total) ?> fight<?= $total !== 1 ? 's' : '' ?></p>
        <?php else: ?><p class="muted" style="margin:4px 0 0;font-size:12px"><?= number_format($total) ?> total fights</p><?php endif; ?>
      </div>
      <?= $back ?>
    </div>
    <?php if (empty($fights)): ?>
      <p class="muted">No combat records found.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <tr style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">
          <th>#</th><th>Attacker</th><th>Defender</th><th>Winner</th><th>Rounds</th><th>XP (A/D)</th><th>Looted</th><th>When</th>
        </tr>
        <?php foreach ($fights as $f):
          $ago = time() - strtotime($f['fought_at']);
          $agoStr = $ago < 60 ? 'just now' : ($ago < 3600 ? round($ago/60).'m ago' : ($ago < 86400 ? round($ago/3600).'h ago' : round($ago/86400).'d ago'));
        ?>
        <tr style="font-size:12px">
          <td class="muted"><?= (int)$f['id'] ?></td>
          <td><a href="index.php?p=admin&sec=editplayer&u=<?= (int)$f['attacker_id'] ?>"><?= e($f['atk_name']) ?></a></td>
          <td><a href="index.php?p=admin&sec=editplayer&u=<?= (int)$f['defender_id'] ?>"><?= e($f['def_name']) ?></a></td>
          <td style="color:var(--accent);font-weight:700"><?= e($f['winner_name']) ?></td>
          <td class="muted"><?= (int)$f['rounds'] ?></td>
          <td class="muted" style="font-size:11px"><?= (int)$f['atk_xp'] ?> / <?= (int)$f['def_xp'] ?></td>
          <td style="color:#e8a33d"><?= $f['credits_looted'] > 0 ? number_format($f['credits_looted']).' cr' : '—' ?></td>
          <td class="muted" style="font-size:11px"><?= $agoStr ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php if ($pages > 1): ?>
    <div style="display:flex;align-items:center;gap:6px;margin-top:14px;flex-wrap:wrap">
      <?php if ($page > 1): ?><a href="<?= $qBase ?>&page=<?= $page-1 ?>" style="padding:4px 10px;border:1px solid var(--line);border-radius:5px;font-size:12px;color:var(--muted);text-decoration:none">&laquo; Prev</a><?php endif; ?>
      <?php for ($pg = max(1,$page-3); $pg <= min($pages,$page+3); $pg++): ?>
      <a href="<?= $qBase ?>&page=<?= $pg ?>" style="padding:4px 10px;border:1px solid <?= $pg===$page?'var(--accent)':'var(--line)' ?>;border-radius:5px;font-size:12px;color:<?= $pg===$page?'var(--accent)':'var(--muted)' ?>;background:<?= $pg===$page?'rgba(25,240,199,.08)':'var(--panel2)' ?>;text-decoration:none"><?= $pg ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= $qBase ?>&page=<?= $page+1 ?>" style="padding:4px 10px;border:1px solid var(--line);border-radius:5px;font-size:12px;color:var(--muted);text-decoration:none">Next &raquo;</a><?php endif; ?>
      <span style="font-size:11px;color:var(--muted);margin-left:4px">Page <?= $page ?> of <?= $pages ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ TRANSACTION LOG ============================ */
if ($sec === 'txlog' && $canAdmin) {
  ?>
  <div class="panel">
    <h2>Transaction Log</h2>
    <?= $back ?><?= $flash ?>
    <?php
      $tx = [];
      try {
        $tx = $pdo->query("SELECT t.*, f.username AS fn, r.username AS rn
                           FROM tx_log t LEFT JOIN players f ON f.id=t.from_id LEFT JOIN players r ON r.id=t.to_id
                           ORDER BY t.id DESC LIMIT 100")->fetchAll();
      } catch (Throwable $e) { echo '<p class="muted">Run schema_txlog.sql to enable the transaction log.</p>'; }
    ?>
    <?php if ($tx): ?>
    <table>
      <tr><th>When</th><th>Kind</th><th>From</th><th>To</th><th>Amount</th><th>Note</th></tr>
      <?php foreach ($tx as $r): ?>
      <tr><td class="muted"><?= e($r['created_at']) ?></td><td><?= e($r['kind']) ?></td>
          <td><?= e($r['fn'] ?? '—') ?></td><td><?= e($r['rn'] ?? '—') ?></td>
          <td><?= number_format($r['amount']) ?></td><td class="muted"><?= e($r['note']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php elseif (empty($tx)): ?><p class="muted">No transactions logged yet.</p><?php endif; ?>
  </div>
  <?php return;
}

/* ============================ PLAYER EDIT LOG ============================ */
if ($sec === 'editlog' && $canAdmin) {
  ?>
  <div class="panel">
    <h2>Player Edit Log</h2>
    <?= $back ?><?= $flash ?>
    <?php
      $alog = [];
      try {
        $alog = $pdo->query("SELECT l.*, a.username AS an, a.role AS admin_role, t.username AS tn
                             FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id LEFT JOIN players t ON t.id=l.target_id
                             ORDER BY l.id DESC LIMIT 100")->fetchAll();
      } catch (Throwable $e) { echo '<p class="muted">Run schema_admin_profile.sql to enable the edit log.</p>'; }
    ?>
    <?php if ($alog): ?>
    <table>
      <tr><th>When</th><th>Admin</th><th>Player</th><th>Field</th><th>Change</th></tr>
      <?php foreach ($alog as $l): ?>
      <tr>
        <td class="muted" style="font-size:11px"><?= e($l['created_at']) ?></td>
        <td><span style="color:<?= chat_color($l['admin_role'] ?? '', '') ?>"><?= e($l['an'] ?? '—') ?></span></td>
        <td><?= e($l['tn'] ?? '—') ?></td>
        <td><?= e($l['field']) ?></td>
        <td><span class="muted"><?= $l['old_value'] === '' ? '&empty;' : e($l['old_value']) ?></span>
            &rarr; <b style="color:var(--accent)"><?= $l['new_value'] === '' ? '&empty;' : e($l['new_value']) ?></b></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php elseif (empty($alog)): ?><p class="muted">No edits logged yet.</p><?php endif; ?>
  </div>
  <?php return;
}

/* ============================ LOG ENTRY DETAIL ============================ */
if ($sec === 'logentry' && $canAdmin) {
  $lid2 = (int)($_GET['id'] ?? 0);
  $entry = null;
  try {
    $leq = $pdo->prepare("SELECT l.*, a.username AS admin_name, a.role AS admin_role, t.username AS target_name, t.role AS target_role
                          FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id LEFT JOIN players t ON t.id=l.target_id
                          WHERE l.id=?");
    $leq->execute([$lid2]); $entry = $leq->fetch();
  } catch (Throwable $e) {}
  ?>
  <div class="panel">
    <h2>&#128220; Staff Action Detail</h2>
    <?= $back ?>
    <?php if (!$entry): ?>
    <p class="muted">Log entry not found.</p>
    <?php else: ?>
    <table>
      <tr><th style="width:140px">When</th><td><?= e($entry['created_at']) ?></td></tr>
      <tr><th>Staff member</th><td>
        <?php if ($entry['admin_name']): ?>
          <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$entry['admin_id'] ?>" style="color:<?= e(chat_color($entry['admin_role'] ?? '', '')) ?>;font-weight:700"><?= e($entry['admin_name']) ?></a>
          <?= role_tag($entry['admin_role'] ?? '', 'font-size:10px;margin-left:4px') ?>
        <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
      </td></tr>
      <tr><th>Target player</th><td>
        <?php if ($entry['target_name']): ?>
          <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$entry['target_id'] ?>" style="color:<?= e(chat_color($entry['target_role'] ?? '', '')) ?>;font-weight:700"><?= e($entry['target_name']) ?></a>
        <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
      </td></tr>
      <tr><th>Field</th><td><?= e($entry['field']) ?></td></tr>
      <tr><th>Old value</th><td class="muted"><?= $entry['old_value'] === '' ? '&empty; (blank)' : e($entry['old_value']) ?></td></tr>
      <tr><th>New value</th><td><b style="color:var(--accent)"><?= $entry['new_value'] === '' ? '&empty; (blank)' : e($entry['new_value']) ?></b></td></tr>
      <tr><th>Log ID</th><td class="muted">#<?= (int)$entry['id'] ?></td></tr>
    </table>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ MODERATION ============================ */
if ($sec === 'moderation') {
  ?>
  <div class="panel">
    <h2>Moderation Tools</h2>
    <?= $back ?><?= $flash ?>
    <p class="muted">Recent chat</p>
    <table>
      <tr><th>When</th><th>Who</th><th>Message</th><th></th></tr>
      <?php foreach ($pdo->query("SELECT c.id,c.body,c.created_at,p.username FROM chat_messages c JOIN players p ON p.id=c.player_id ORDER BY c.id DESC LIMIT 20") as $c): ?>
      <tr><td class="muted"><?= e($c['created_at']) ?></td><td><?= e($c['username']) ?></td><td><?= e(mb_substr($c['body'],0,60)) ?></td>
          <td><form method="post" style="margin:0"><input type="hidden" name="action" value="del_chat"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button>Delete</button></form></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($canBoardMod): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
      <form method="post"><input type="hidden" name="action" value="del_post"><label>Delete board post by ID</label>
        <p style="display:flex;gap:6px"><input type="number" name="id"><button>Delete</button></p></form>
      <form method="post"><input type="hidden" name="action" value="del_topic"><label>Delete board topic by ID</label>
        <p style="display:flex;gap:6px"><input type="number" name="id"><button>Delete</button></p></form>
    </div>
    <?php endif; ?>
    <p class="muted" style="font-size:11px;margin-top:8px">Tip: you can also delete a post directly from a thread on the <a href="index.php?p=boards">Message Boards</a>.</p>
  </div>
  <?php return;
}

/* ============================ IP LOG ============================ */
if ($sec === 'iplog') {
  // IP data is moderator+ (chatmods pass the page-level $canMod gate but must not see IPs)
  if (!in_array($role, ['moderator','admin','manager'], true)) {
    echo '<div class="panel"><h2>&#127758; IP &amp; Access Log</h2><p class="muted">Moderator access required.</p></div>';
    return;
  }
  $filterIp     = trim($_GET['ip'] ?? '');
  $filterPlayer = trim($_GET['player'] ?? '');
  $filterAction = $_GET['act'] ?? '';
  $page = max(1, (int)($_GET['pg'] ?? 1));
  $perPage = 50;
  $offset = ($page - 1) * $perPage;

  $where = []; $params = [];
  if ($filterIp !== '')     { $where[] = 'l.ip LIKE ?';              $params[] = '%'.$filterIp.'%'; }
  if ($filterPlayer !== '') { $where[] = 'p.username LIKE ?';        $params[] = '%'.$filterPlayer.'%'; }
  if ($filterAction !== '') { $where[] = 'l.action = ?';             $params[] = $filterAction; }
  $wq = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $rows = []; $total = 0;
  try {
    $cq = $pdo->prepare("SELECT COUNT(*) FROM ip_log l LEFT JOIN players p ON p.id=l.player_id $wq");
    $cq->execute($params); $total = (int)$cq->fetchColumn();
    $lq = $pdo->prepare("SELECT l.*, p.username FROM ip_log l LEFT JOIN players p ON p.id=l.player_id $wq ORDER BY l.id DESC LIMIT $perPage OFFSET $offset");
    $lq->execute($params); $rows = $lq->fetchAll();
  } catch (Throwable $e) {
    echo '<div class="panel"><h2>&#127758; IP &amp; Access Log</h2>'.$back.'<p class="muted">Run <code>schema_iplog.sql</code> to enable this feature.</p></div>';
    return;
  }

  $pages = max(1, (int)ceil($total / $perPage));
  $qBase = 'index.php?p=admin&sec=iplog'.($filterIp?'&ip='.urlencode($filterIp):'').($filterPlayer?'&player='.urlencode($filterPlayer):'').($filterAction?'&act='.urlencode($filterAction):'');

  $actionColors = ['login'=>'#3bcf63','fail'=>'#ff2d95','register'=>'#19f0c7','logout'=>'#8a8fa8'];
  $ipView = $_GET['view'] ?? 'log';
  ?>
  <div class="panel">
    <h2>&#127758; IP &amp; Access Log</h2>
    <?= $back ?><?= $flash ?>

    <!-- View tabs -->
    <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
      <?php foreach (['log'=>'&#128196; Log','shared'=>'&#128101; Shared IPs','suspicious'=>'&#9888; Suspicious Activity'] as $vk=>$vl): ?>
      <a href="index.php?p=admin&sec=iplog&view=<?= $vk ?>" style="padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $ipView===$vk?'var(--accent)':'var(--line)' ?>;color:<?= $ipView===$vk?'var(--accent)':'var(--muted)' ?>;background:<?= $ipView===$vk?'rgba(25,240,199,.08)':'var(--panel2)' ?>"><?= $vl ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($ipView === 'shared'): ?>
    <!-- Shared IPs: multiple accounts on same IP -->
    <?php
      $sharedRows = [];
      try {
        $sq = $pdo->query("SELECT l.ip, GROUP_CONCAT(DISTINCT p.username ORDER BY p.username SEPARATOR ', ') AS accounts, COUNT(DISTINCT l.player_id) AS account_count, MAX(l.created_at) AS last_seen FROM ip_log l JOIN players p ON p.id=l.player_id WHERE l.action='login' GROUP BY l.ip HAVING account_count > 1 ORDER BY account_count DESC, last_seen DESC LIMIT 100");
        $sharedRows = $sq->fetchAll();
      } catch (Throwable $e) {}
    ?>
    <p style="font-size:12px;color:var(--muted);margin-bottom:10px">IPs where more than one account has logged in — potential multi-accounting.</p>
    <?php if (empty($sharedRows)): ?>
    <p class="muted" style="text-align:center;padding:20px 0">No shared IP logins found.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <tr><th>IP Address</th><th>Accounts</th><th># Accts</th><th>Last Login</th></tr>
      <?php foreach ($sharedRows as $sr): ?>
      <tr>
        <td><a href="index.php?p=admin&sec=iplog&ip=<?= urlencode($sr['ip']) ?>" style="font-family:monospace;font-size:12px;color:var(--neon2)"><?= e($sr['ip']) ?></a></td>
        <td style="font-size:12px;max-width:320px"><?= e($sr['accounts']) ?></td>
        <td style="font-weight:700;color:<?= (int)$sr['account_count']>=3?'#e23b3b':'#e8a33d' ?>"><?= (int)$sr['account_count'] ?></td>
        <td class="muted" style="font-size:11px;white-space:nowrap"><?= e($sr['last_seen']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>

    <?php elseif ($ipView === 'suspicious'): ?>
    <!-- Suspicious Activity: brute force + high login rate -->
    <?php
      $bruteRows = [];
      try {
        $bq = $pdo->query("SELECT ip, COUNT(*) AS fail_count, MIN(created_at) AS first_attempt, MAX(created_at) AS last_attempt FROM ip_log WHERE action='fail' AND created_at >= NOW() - INTERVAL 24 HOUR GROUP BY ip HAVING fail_count >= 3 ORDER BY fail_count DESC LIMIT 50");
        $bruteRows = $bq->fetchAll();
      } catch (Throwable $e) {}
      $highVolRows = [];
      try {
        $hvq = $pdo->query("SELECT ip, COUNT(*) AS login_count, COUNT(DISTINCT player_id) AS unique_players, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen FROM ip_log WHERE action='login' AND created_at >= NOW() - INTERVAL 1 HOUR GROUP BY ip HAVING login_count >= 5 ORDER BY login_count DESC LIMIT 30");
        $highVolRows = $hvq->fetchAll();
      } catch (Throwable $e) {}
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
      <div>
        <h3 style="font-size:13px;color:var(--neon2)">&#128165; Failed Login Bursts (last 24h, ≥3 fails)</h3>
        <?php if (empty($bruteRows)): ?><p class="muted" style="font-size:12px">No bursts detected.</p>
        <?php else: ?>
        <table style="font-size:12px;width:100%">
          <tr><th>IP</th><th>Fails</th><th>First</th><th>Last</th></tr>
          <?php foreach ($bruteRows as $br): ?>
          <tr>
            <td><a href="index.php?p=admin&sec=iplog&ip=<?= urlencode($br['ip']) ?>" style="font-family:monospace;color:var(--neon2)"><?= e($br['ip']) ?></a></td>
            <td style="font-weight:700;color:#e23b3b"><?= (int)$br['fail_count'] ?></td>
            <td class="muted" style="font-size:10px"><?= e(date('M j g:ia',strtotime($br['first_attempt']))) ?></td>
            <td class="muted" style="font-size:10px"><?= e(date('M j g:ia',strtotime($br['last_attempt']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
      <div>
        <h3 style="font-size:13px;color:#e8a33d">&#9889; High Login Volume (last 1h, ≥5 logins)</h3>
        <?php if (empty($highVolRows)): ?><p class="muted" style="font-size:12px">No high-volume IPs right now.</p>
        <?php else: ?>
        <table style="font-size:12px;width:100%">
          <tr><th>IP</th><th>Logins</th><th>Players</th><th>Last</th></tr>
          <?php foreach ($highVolRows as $hv): ?>
          <tr>
            <td><a href="index.php?p=admin&sec=iplog&ip=<?= urlencode($hv['ip']) ?>" style="font-family:monospace;color:#e8a33d"><?= e($hv['ip']) ?></a></td>
            <td style="font-weight:700"><?= (int)$hv['login_count'] ?></td>
            <td><?= (int)$hv['unique_players'] ?></td>
            <td class="muted" style="font-size:10px"><?= e(date('g:ia',strtotime($hv['last_seen']))) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <?php else: /* 'log' view */ ?>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end">
      <input type="hidden" name="p" value="admin">
      <input type="hidden" name="sec" value="iplog">
      <div><label style="font-size:11px">Filter IP</label><br><input type="text" name="ip" value="<?= e($filterIp) ?>" placeholder="e.g. 192.168." style="width:140px"></div>
      <div><label style="font-size:11px">Filter Player</label><br><input type="text" name="player" value="<?= e($filterPlayer) ?>" placeholder="username..." style="width:140px"></div>
      <div><label style="font-size:11px">Action</label><br>
        <select name="act">
          <option value="">All</option>
          <?php foreach (['login','fail','register','logout'] as $a): ?>
            <option value="<?= $a ?>" <?= $filterAction===$a?'selected':'' ?>><?= ucfirst($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button type="submit">Filter</button> <?php if ($filterIp||$filterPlayer||$filterAction): ?><a href="index.php?p=admin&sec=iplog" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?></div>
    </form>

    <div class="muted" style="font-size:11px;margin-bottom:8px"><?= number_format($total) ?> entr<?= $total===1?'y':'ies' ?> &middot; page <?= $page ?> of <?= $pages ?></div>

    <?php if ($rows): ?>
    <div style="overflow-x:auto">
    <table>
      <tr><th>When</th><th>Action</th><th>Player</th><th>IP</th><th>User Agent</th></tr>
      <?php foreach ($rows as $r):
        $acol = $actionColors[$r['action']] ?? '#8a8fa8';
      ?>
      <tr>
        <td class="muted" style="font-size:11px;white-space:nowrap"><?= e($r['created_at']) ?></td>
        <td><span style="color:<?= $acol ?>;font-weight:600;font-size:12px"><?= e(strtoupper($r['action'])) ?></span></td>
        <td><?php if ($r['player_id']): ?>
          <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$r['player_id'] ?>" style="font-size:12px"><?= e($r['username'] ?? '#'.(int)$r['player_id']) ?></a>
        <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td style="font-family:monospace;font-size:12px">
          <a href="index.php?p=admin&sec=iplog&ip=<?= urlencode($r['ip']) ?>" style="color:var(--text)"><?= e($r['ip']) ?></a>
        </td>
        <td class="muted" style="font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['user_agent']) ?>"><?= e(mb_substr($r['user_agent'],0,80)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap">
      <?php for ($i = max(1,$page-3); $i <= min($pages,$page+3); $i++): ?>
        <a href="<?= $qBase ?>&pg=<?= $i ?>" class="btn btn-ghost btn-sm" <?= $i===$page?'style="color:var(--accent);font-weight:700"':'' ?>><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= $qBase ?>&pg=<?= $pages ?>" class="btn btn-ghost btn-sm">Last &raquo;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <p class="muted" style="text-align:center;padding:24px 0">No log entries yet. Entries appear after players log in.</p>
    <?php endif; ?>
    <?php endif; /* end log view */ ?>
  </div>
  <?php return;
}

/* ============================ CREATE USER ============================ */
if ($sec === 'createuser' && $isManager) { ?>
  <div class="panel">
    <h2>&#128100; Create New User</h2>
    <?= $back ?><?= $flash ?>
    <form method="post" style="max-width:400px">
      <input type="hidden" name="action" value="create_user">
      <div style="display:flex;flex-direction:column;gap:10px">
        <div><label>Username</label><input type="text" name="new_username" maxlength="24" placeholder="3-24 chars, letters/numbers/_" style="width:100%"></div>
        <div><label>Email</label><input type="email" name="new_email" style="width:100%"></div>
        <div><label>Password</label><input type="password" name="new_pass" minlength="6" style="width:100%"></div>
        <button type="submit">Create Account</button>
      </div>
    </form>
  </div>
  <?php return;
}

/* ============================ MAINTENANCE ============================ */
if ($sec === 'maintenance' && $isManager) { ?>
  <div class="panel">
    <h2>&#9881; Maintenance</h2>
    <?= $back ?><?= $flash ?>
    <div style="background:rgba(232,163,61,.05);border:1px solid rgba(232,163,61,.25);border-radius:8px;padding:14px;margin-bottom:16px">
      <h4 style="margin:0 0 8px;color:#e8a33d">&#8635; Daily Reset</h4>
      <p class="muted" style="font-size:12px;margin:0 0 10px">Runs the daily reset immediately for all players: restores Health &amp; Signal to max, adds +250 Drive (capped). Clears the per-player reset flag so normal daily reset still fires at midnight.</p>
      <form method="post" onsubmit="return confirm('Trigger daily reset for ALL players now?')">
        <input type="hidden" name="action" value="trigger_reset">
        <button type="submit" style="background:rgba(232,163,61,.1);border-color:rgba(232,163,61,.4);color:#e8a33d">Trigger Daily Reset</button>
      </form>
    </div>

    <h4 style="margin:18px 0 8px">&#128340; Server Health</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:12px">
      <?php
      $healthTables = ['players','chat_messages','pvp_log','market_listings','market_sales','auction_listings','casino_log','tx_log','settings','player_notifications','messages','posts'];
      foreach ($healthTables as $ht):
        $cnt = null;
        try { $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$ht}`")->fetchColumn(); } catch (Throwable $e) {}
      ?>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px 10px">
        <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:<?= $cnt === null ? 'var(--neon2)' : 'var(--accent)' ?>"><?= $cnt === null ? 'missing' : number_format($cnt) ?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= e($ht) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php
    $dbVer = '?';
    try { $dbVer = (string)$pdo->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable $e) {}
    ?>
    <p class="muted" style="font-size:11px">PHP <?= e(PHP_VERSION) ?> &middot; MySQL <?= e($dbVer) ?> &middot; Session save path writable: <?= is_writable(session_save_path() ?: sys_get_temp_dir()) ? '<b style="color:#3bcf63">yes</b>' : '<b style="color:var(--neon2)">no</b>' ?></p>
    <p class="muted" style="font-size:12px">Manage the database directly via your hosting control panel for other maintenance tasks.</p>
  </div>
  <?php return;
}

/* ============================ ECONOMY DASHBOARD ============================ */
if ($sec === 'economy' && $canAdmin) {
  $ecoKinds = []; $ecoCasino = null; $ecoMarket = null; $ecoRich = []; $ecoLiab = ['loans'=>0,'bonds'=>0];
  try { $ecoKinds = $pdo->query("SELECT kind, COUNT(*) n, COALESCE(SUM(amount),0) vol FROM tx_log WHERE created_at >= (NOW() - INTERVAL 7 DAY) GROUP BY kind ORDER BY vol DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) {}
  try { $ecoCasino = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(bet),0) wagered, COALESCE(SUM(net),0) playernet FROM casino_log WHERE played_at >= (NOW() - INTERVAL 7 DAY)")->fetch(); } catch (Throwable $e) {}
  try { $ecoMarket = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(qty*unit_price),0) vol FROM market_sales WHERE sold_at >= (NOW() - INTERVAL 7 DAY)")->fetch(); } catch (Throwable $e) {}
  try { $ecoRich = $pdo->query("SELECT id, username, creds_pocket, creds_bank, (creds_pocket+creds_bank) total FROM players ORDER BY total DESC LIMIT 10")->fetchAll(); } catch (Throwable $e) {}
  try { $ecoLiab['loans'] = (int)$pdo->query("SELECT COALESCE(SUM(loan),0) FROM players")->fetchColumn(); } catch (Throwable $e) {}
  try { $ecoLiab['bonds'] = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM bonds WHERE status='active'")->fetchColumn(); } catch (Throwable $e) {}
  $houseNet = $ecoCasino ? -(int)$ecoCasino['playernet'] : 0;
  ?>
  <div class="panel">
    <h2>&#128202; Economy Dashboard <span class="muted" style="font-size:12px;font-weight:400">— last 7 days</span></h2>
    <?= $back ?><?= $flash ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;margin-bottom:16px">
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:11px 12px">
        <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:<?= $houseNet >= 0 ? '#3bcf63' : 'var(--neon2)' ?>"><?= ($houseNet >= 0 ? '+' : '') . number_format($houseNet) ?> cr</div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px">Casino house P/L &middot; <?= number_format((int)($ecoCasino['n'] ?? 0)) ?> plays &middot; <?= number_format((int)($ecoCasino['wagered'] ?? 0)) ?> wagered</div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:11px 12px">
        <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--accent)"><?= number_format((int)($ecoMarket['vol'] ?? 0)) ?> cr</div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px">Bazaar volume &middot; <?= number_format((int)($ecoMarket['n'] ?? 0)) ?> sales</div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:11px 12px">
        <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:#e8a33d"><?= number_format($ecoLiab['loans']) ?> cr</div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px">Outstanding bank loans</div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:11px 12px">
        <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:#a66de8"><?= number_format($ecoLiab['bonds']) ?> cr</div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px">Locked in active bonds</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
      <div>
        <h4 style="margin:0 0 8px">Transfer flows by kind (tx_log)</h4>
        <?php if (empty($ecoKinds)): ?><p class="muted" style="font-size:12px">No transactions logged in the last 7 days.</p>
        <?php else: $ecoMax = max(1, max(array_column($ecoKinds, 'vol'))); ?>
        <?php foreach ($ecoKinds as $ek): ?>
        <div style="margin-bottom:7px">
          <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px">
            <span><?= e($ek['kind']) ?> <span class="muted">×<?= number_format($ek['n']) ?></span></span>
            <b style="color:var(--accent)"><?= number_format($ek['vol']) ?> cr</b>
          </div>
          <div style="height:5px;border-radius:3px;background:rgba(255,255,255,.06);overflow:hidden">
            <div style="height:100%;width:<?= round($ek['vol'] / $ecoMax * 100) ?>%;background:linear-gradient(90deg,var(--accent),#3bcf63);border-radius:3px"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div>
        <h4 style="margin:0 0 8px">Richest players</h4>
        <?php foreach ($ecoRich as $i => $rp): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px">
          <span style="width:18px;color:var(--muted);font-family:'Orbitron',sans-serif;font-size:10px"><?= $i+1 ?></span>
          <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$rp['id'] ?>" style="flex:1"><?= e($rp['username']) ?></a>
          <span class="muted" style="font-size:10px">pocket <?= number_format($rp['creds_pocket']) ?></span>
          <b style="color:var(--accent)"><?= number_format($rp['total']) ?> cr</b>
        </div>
        <?php endforeach; ?>
        <p style="margin:10px 0 0"><a href="index.php?p=admin&sec=txlog" style="font-size:11px;color:var(--muted)">Full transaction log &rarr;</a></p>
      </div>
    </div>
  </div>
  <?php return;
}

/* ============================ BROADCAST ============================ */
if ($sec === 'broadcast' && $isManager) {
  $bcCounts = ['all'=>0,'online'=>0,'subs'=>0,'staff'=>0];
  try {
    $bcr = $pdo->query("SELECT COUNT(*) a,
      SUM(last_seen >= (NOW() - INTERVAL 15 MINUTE)) o,
      SUM(sub_until >= CURDATE()) s,
      SUM(role IN ('chatmod','moderator','admin','manager')) st FROM players")->fetch();
    if ($bcr) $bcCounts = ['all'=>(int)$bcr['a'],'online'=>(int)$bcr['o'],'subs'=>(int)$bcr['s'],'staff'=>(int)$bcr['st']];
  } catch (Throwable $e) {}
  $recentBc = [];
  try { $rbq = $pdo->query("SELECT l.*, a.username admin_name FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id WHERE l.field LIKE 'broadcast:%' ORDER BY l.id DESC LIMIT 8"); $recentBc = $rbq->fetchAll(); } catch (Throwable $e) {}
  ?>
  <div class="panel">
    <h2>&#128226; Broadcast</h2>
    <?= $back ?><?= $flash ?>
    <p class="muted" style="font-size:12px;margin-bottom:14px">Push a notification to players' Hideout feeds. It arrives prefixed with <b>[Staff]</b> and stays until dismissed.</p>
    <form method="post" style="max-width:520px">
      <input type="hidden" name="action" value="broadcast">
      <div class="field"><span>Message (max 500 chars)</span>
        <textarea name="broadcast_body" maxlength="500" style="width:100%;min-height:80px" placeholder="Server maintenance tonight at 02:00 MT — expect 10 minutes of downtime."></textarea>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap">
        <select name="broadcast_target" style="flex:1;min-width:200px">
          <option value="all">All players (<?= number_format($bcCounts['all']) ?>)</option>
          <option value="online">Online last 15 min (<?= number_format($bcCounts['online']) ?>)</option>
          <option value="subs">Subscribers (<?= number_format($bcCounts['subs']) ?>)</option>
          <option value="staff">Staff only (<?= number_format($bcCounts['staff']) ?>)</option>
        </select>
        <button type="submit" onclick="return confirm('Send this broadcast?')" style="background:rgba(25,240,199,.1);border-color:rgba(25,240,199,.35);color:var(--accent)">Send Broadcast</button>
      </div>
    </form>
    <?php if ($recentBc): ?>
    <h4 style="margin:18px 0 8px">Recent broadcasts</h4>
    <?php foreach ($recentBc as $rb): ?>
    <div style="padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px">
      <span class="muted" style="font-size:10px"><?= e($rb['created_at'] ?? '') ?> &middot; <?= e($rb['admin_name'] ?? '?') ?> &middot; <?= e(str_replace('broadcast:','to ',$rb['field'])) ?></span>
      <div><?= e($rb['new_value']) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <?php return;
}

/* ============================ QUICK GRANT ============================ */
if ($sec === 'grant' && $canAdmin) {
  $recentGrants = [];
  try { $rgq = $pdo->query("SELECT l.*, a.username admin_name, t.username target_name FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id LEFT JOIN players t ON t.id=l.target_id WHERE l.field LIKE 'grant:%' ORDER BY l.id DESC LIMIT 12"); $recentGrants = $rgq->fetchAll(); } catch (Throwable $e) {}
  ?>
  <div class="panel">
    <h2>&#127873; Quick Grant</h2>
    <?= $back ?><?= $flash ?>
    <p class="muted" style="font-size:12px;margin-bottom:14px">Grant (or deduct, with a negative amount) credits, shards, or stats. Every grant requires a reason and is written to the edit log and transaction log.</p>
    <form method="post" style="max-width:520px">
      <input type="hidden" name="action" value="grant_player">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="field"><span>Player handle</span>
          <div class="ac-wrap" style="position:relative">
            <input type="text" name="grant_handle" id="grantHandle" autocomplete="off" data-no-counter style="width:100%" placeholder="Ghost's handle">
            <div class="ac-list" id="grantAcList" style="display:none"></div>
          </div>
          <div id="grantConfirm" style="display:none;margin-top:6px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:5px;padding:7px 10px;font-size:12px"></div>
        </div>
        <div class="field"><span>Currency / stat</span>
          <select name="grant_field" style="width:100%">
            <option value="creds_pocket">Pocket Credits</option>
            <option value="creds_bank">Bank Credits</option>
            <option value="shards">Shards</option>
            <option value="cycles">Drive</option>
            <option value="integrity">Health</option>
            <option value="xp">XP</option>
          </select>
        </div>
        <div class="field"><span>Amount (negative = deduct)</span>
          <input type="number" name="grant_amount" style="width:100%" placeholder="e.g. 5000 or -5000">
        </div>
        <div class="field"><span>Reason (required, audited)</span>
          <input type="text" name="grant_reason" maxlength="150" style="width:100%" placeholder="e.g. event prize / bug compensation">
        </div>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;margin:10px 0;color:var(--muted)">
        <input type="checkbox" name="grant_notify" value="1" checked> Notify the player (with the reason)
      </label>
      <button type="submit" style="background:rgba(59,207,99,.1);border-color:rgba(59,207,99,.35);color:#3bcf63">Apply Grant</button>
    </form>
    <script>
    (function(){
      PlayerAC.attach(document.getElementById('grantHandle'), document.getElementById('grantAcList'), {confirm: document.getElementById('grantConfirm')});
    })();
    </script>
    <?php if ($recentGrants): ?>
    <h4 style="margin:18px 0 8px">Recent grants</h4>
    <?php foreach ($recentGrants as $rg): ?>
    <div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;flex-wrap:wrap">
      <span class="muted" style="font-size:10px;flex:none"><?= e($rg['created_at'] ?? '') ?></span>
      <b style="color:var(--accent)"><?= e($rg['admin_name'] ?? '?') ?></b>
      <span>&rarr; <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$rg['target_id'] ?>"><?= e($rg['target_name'] ?? '#'.$rg['target_id']) ?></a></span>
      <b style="color:<?= ($rg['old_value'][0] ?? '+') === '-' ? 'var(--neon2)' : '#3bcf63' ?>"><?= e($rg['old_value']) ?></b>
      <span class="muted"><?= e(str_replace('grant:','',$rg['field'])) ?></span>
      <span class="muted" style="flex-basis:100%;font-size:11px;padding-left:60px">&ldquo;<?= e($rg['new_value']) ?>&rdquo;</span>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <?php return;
}

/* ============================ MANAGE UPDATES ============================ */
if ($sec === 'updates' && $isManager) {
  ?>
  <div class="panel">
    <h2>&#128240; Manage Announcements</h2>
    <?= $back ?><?= $flash ?>
    <?php foreach ($pdo->query("SELECT id,body,created_at FROM updates ORDER BY id DESC LIMIT 25") as $u): ?>
    <div style="border-bottom:1px solid var(--line);padding:8px 0">
      <div class="muted" style="font-size:11px"><?= e($u['created_at']) ?> &middot; #<?= (int)$u['id'] ?></div>
      <form method="post" style="display:flex;gap:6px;align-items:flex-start">
        <input type="hidden" name="action" value="edit_update">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <textarea name="body" style="min-height:40px"><?= e($u['body']) ?></textarea>
        <button type="submit">Save</button>
      </form>
      <form method="post" style="margin-top:4px"><input type="hidden" name="action" value="del_update"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button>Delete</button></form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php return;
}

/* ============================ IMPERSONATION & VIEW TOOLS ============================ */
if ($sec === 'impersonate' && $isManager) {
  $recentImp = [];
  try { $riq = $pdo->query("SELECT l.*, a.username admin_name, t.username target_name FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id LEFT JOIN players t ON t.id=l.target_id WHERE l.field='impersonate' ORDER BY l.id DESC LIMIT 8"); $recentImp = $riq->fetchAll(); } catch (Throwable $e) {}
  ?>
  <div class="panel" style="border:1px solid rgba(255,45,149,.2);background:rgba(255,45,149,.03)">
    <h2 style="color:var(--neon2)">&#128737; Impersonation &amp; View Tools</h2>
    <?= $back ?><?= $flash ?>
    <p class="muted" style="font-size:12px;margin-bottom:14px">Impersonate a player to see the game exactly as they do. Your session is flagged — a stop banner is always shown. You can also preview how the UI looks for a specific staff role.</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;flex-wrap:wrap">
      <div>
        <h4 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">Impersonate Player</h4>
        <form method="post" style="display:flex;gap:8px" onsubmit="return confirm('Impersonate this player? You will act as them until you stop.')">
          <input type="hidden" name="action" value="impersonate">
          <input type="text" name="imp_handle" placeholder="Ghost's handle" style="flex:1">
          <button type="submit" style="background:rgba(255,45,149,.1);border-color:rgba(255,45,149,.4);color:var(--neon2)">Jack In</button>
        </form>
      </div>
      <div>
        <h4 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px">View as Role <?= !empty($_SESSION['role_override']) ? '(<b style="color:var(--accent)">'.e($_SESSION['role_override']).'</b>)' : '' ?></h4>
        <form method="post" style="display:flex;gap:8px">
          <input type="hidden" name="action" value="role_preview">
          <select name="preview_role" style="flex:1">
            <option value="">— clear override —</option>
            <?php foreach (['member','chatmod','moderator','admin','manager'] as $rr): ?>
            <option value="<?= $rr ?>" <?= ($_SESSION['role_override'] ?? '') === $rr ? 'selected' : '' ?>><?= ucfirst($rr) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Apply</button>
        </form>
      </div>
    </div>
    <?php if ($recentImp): ?>
    <h4 style="margin:20px 0 8px">Recent impersonations</h4>
    <?php foreach ($recentImp as $ri): ?>
    <div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px">
      <b style="color:var(--neon2)"><?= e($ri['admin_name'] ?? '?') ?></b> jacked in as
      <a href="index.php?p=admin&sec=editplayer&u=<?= (int)$ri['target_id'] ?>"><?= e($ri['target_name'] ?? '?') ?></a>
      <span class="muted" style="font-size:10px"> &middot; <?= e(date('M j g:ia', strtotime($ri['created_at']))) ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ HUB ============================ */
// Live stats for the hub
$hubStats = [];
try {
  $hs = db()->query("SELECT
    COUNT(*) AS total,
    SUM(last_seen >= (NOW() - INTERVAL 5 MINUTE)) AS online_now,
    SUM(sub_until >= CURDATE()) AS subs,
    SUM(creds_pocket) AS total_pocket,
    SUM(creds_bank) AS total_bank,
    SUM(loan) AS total_loans,
    AVG(level) AS avg_level,
    MAX(level) AS max_level,
    SUM(created_at >= (NOW() - INTERVAL 24 HOUR)) AS new_today,
    SUM(created_at >= (NOW() - INTERVAL 7 DAY)) AS new_week,
    SUM(mortality > 0) AS good_players,
    SUM(mortality < 0) AS evil_players
  FROM players")->fetch();
  $hubStats = $hs;
} catch (Throwable $e) {}

$openReports = 0;
try { $openReports = (int)db()->query("SELECT COUNT(*) FROM reports WHERE status='open'")->fetchColumn(); } catch (Throwable $e) {}

// Signups per day, last 14 days (sparkline)
$signupSeries = array_fill(0, 14, 0);
try {
  $ssq = db()->query("SELECT DATE(created_at) d, COUNT(*) n FROM players WHERE created_at >= (NOW() - INTERVAL 14 DAY) GROUP BY DATE(created_at)");
  $byDay = [];
  foreach ($ssq as $sr) $byDay[$sr['d']] = (int)$sr['n'];
  for ($i = 13; $i >= 0; $i--) $signupSeries[13 - $i] = $byDay[date('Y-m-d', strtotime("-{$i} days"))] ?? 0;
} catch (Throwable $e) {}

// Activity feed
$feedAdmin = [];
try { $feedAdmin = db()->query("SELECT l.*, a.username admin_name, a.role admin_role, t.username target_name FROM admin_log l LEFT JOIN players a ON a.id=l.admin_id LEFT JOIN players t ON t.id=l.target_id ORDER BY l.id DESC LIMIT 20")->fetchAll(); } catch (Throwable $e) {}
?>
<style>
#adm-canvas{display:block;width:100%;height:96px;border-radius:9px 9px 0 0}
#adm-head h2{text-shadow:0 0 14px rgba(25,240,199,.35)}
.staffcard{transition:transform .12s,border-color .15s,box-shadow .15s}
.staffcard:hover{transform:translateY(-2px);border-color:var(--sg-col,var(--accent));box-shadow:0 4px 14px rgba(0,0,0,.35),0 0 12px var(--sg-glow,rgba(25,240,199,.12))}
.adm-cat{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);font-weight:700;margin:16px 0 8px;display:flex;align-items:center;gap:8px}
.adm-cat::after{content:'';flex:1;height:1px;background:var(--line)}
#adm-search{background:var(--panel2);border:1px solid var(--line);border-radius:18px;color:var(--text);padding:7px 14px;font-size:12px;width:100%;transition:border-color .15s}
#adm-search:focus{border-color:var(--accent);outline:none;box-shadow:0 0 10px rgba(25,240,199,.15)}
.adm-feed-row{display:flex;gap:8px;align-items:baseline;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px}
</style>

<div class="panel" id="adm-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="adm-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:10px;pointer-events:none">
      <h2 style="margin:0">&#128737; Staff Command</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Role: <b style="color:var(--accent)"><?= e(role_label($role) ?: 'Member') ?></b> &middot; <?= date('M j, Y g:i a') ?></p>
    </div>
    <div style="position:absolute;right:14px;bottom:10px;text-align:right;font-size:10px;color:var(--muted)">
      <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:7px;height:7px;border-radius:50%;background:#3bcf63;box-shadow:0 0 8px #3bcf63"></span> <?= number_format((int)($hubStats['online_now'] ?? 0)) ?> ONLINE</span>
      <?php if ($openReports): ?><br><span style="color:var(--neon2);font-weight:700"><?= $openReports ?> OPEN REPORT<?= $openReports!==1?'S':'' ?></span><?php endif; ?>
    </div>
  </div>
  <div style="padding:10px 14px">
    <?= $flash ?>
    <?php if ($canAdmin): ?>
    <form method="post" action="index.php?p=admin&sec=editplayer" style="margin:0;position:relative" class="ac-wrap">
      <input type="hidden" name="action" value="find">
      <input type="text" name="handle" id="adm-search" placeholder="&#128269; Jump to player — type a handle and press Enter..." autocomplete="off" data-no-counter>
      <div class="ac-list" id="admAcList" style="display:none"></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canAdmin): ?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <h3 style="margin:0">&#128220; Latest Staff Actions</h3>
    <a href="index.php?p=admin&sec=editlog" style="font-size:11px;color:var(--muted)">View full log &rarr;</a>
  </div>
  <?php if (empty($feedAdmin)): ?>
  <p class="muted" style="font-size:12px">No admin actions logged yet.</p>
  <?php else: foreach ($feedAdmin as $fa): ?>
  <a href="index.php?p=admin&sec=logentry&id=<?= (int)$fa['id'] ?>" class="adm-feed-row" style="text-decoration:none;color:inherit">
    <span class="muted" style="font-size:10px;flex:none;width:82px"><?= e(date('M j g:ia', strtotime($fa['created_at'] ?? 'now'))) ?></span>
    <b style="color:<?= e(chat_color($fa['admin_role'] ?? '', '')) ?>;flex:none"><?= e($fa['admin_name'] ?? '?') ?></b>
    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($fa['field']) ?><?= $fa['target_name'] ? ' &rarr; '.e($fa['target_name']) : '' ?></span>
  </a>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php if ($canAdmin): ?>
<div class="adm-cat">&#9881; System</div>
<div class="staffgrid">
  <?php if ($isManager): ?>
  <a class="staffcard" href="index.php?p=admin&sec=broadcast" style="--sg-col:#e8a33d;--sg-glow:rgba(232,163,61,.12)">
    <span class="ic">&#128226;</span><h4>Broadcast</h4>
    <p>Push a notification to all players, online players, subs, or staff.</p>
    <span class="req">Manager+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=updates" style="--sg-col:#e8a33d;--sg-glow:rgba(232,163,61,.12)">
    <span class="ic">&#128240;</span><h4>Announcements</h4>
    <p>Post, edit, and delete game update announcements.</p>
    <span class="req">Manager+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=maintenance" style="--sg-col:#e8a33d;--sg-glow:rgba(232,163,61,.12)">
    <span class="ic">&#9881;</span><h4>Maintenance</h4>
    <p>Daily reset trigger, table row counts, and server health readout.</p>
    <span class="req">Manager+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=impersonate" style="--sg-col:#e8a33d;--sg-glow:rgba(232,163,61,.12)">
    <span class="ic">&#128737;</span><h4>Impersonation</h4>
    <p>Jack into a player's account, or preview the UI as a specific staff role.</p>
    <span class="req">Manager+</span>
  </a>
  <?php endif; ?>
  <a class="staffcard" href="index.php?p=admin&sec=editlog" style="--sg-col:#e8a33d;--sg-glow:rgba(232,163,61,.12)">
    <span class="ic">&#128220;</span><h4>Edit Log</h4>
    <p>Audit trail of all admin account changes &mdash; who changed what.</p>
    <span class="req">Admin+</span>
  </a>
</div>

<div class="adm-cat">&#128101; Players</div>
<div class="staffgrid">
  <?php if ($isManager): ?>
  <a class="staffcard" href="index.php?p=admin&sec=createuser" style="--sg-col:#19f0c7;--sg-glow:rgba(25,240,199,.12)">
    <span class="ic">&#128100;</span><h4>Create User</h4>
    <p>Manually create a new player account with username, email, and password.</p>
    <span class="req">Manager+</span>
  </a>
  <?php endif; ?>
  <a class="staffcard" href="index.php?p=admin&sec=editplayer" style="--sg-col:#19f0c7;--sg-glow:rgba(25,240,199,.12)">
    <span class="ic">&#128101;</span><h4>Players</h4>
    <p>Search and edit player accounts &mdash; stats, role &amp; subscription.</p>
    <span class="req">Admin+</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=combat" style="--sg-col:#19f0c7;--sg-glow:rgba(25,240,199,.12)">
    <span class="ic">&#9876;</span><h4>Combat Log</h4>
    <p>Site-wide PvP history, filterable by player. Spot harassment patterns.</p>
    <span class="req">Admin+</span>
  </a>
</div>

<div class="adm-cat">&#128176; Economy</div>
<div class="staffgrid">
  <a class="staffcard" href="index.php?p=admin&sec=economy" style="--sg-col:#3bcf63;--sg-glow:rgba(59,207,99,.12)">
    <span class="ic">&#128202;</span><h4>Economy Dashboard</h4>
    <p>7-day money flows, casino house P/L, market volume, richest players.</p>
    <span class="req">Admin+ &middot; NEW</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=grant" style="--sg-col:#3bcf63;--sg-glow:rgba(59,207,99,.12)">
    <span class="ic">&#127873;</span><h4>Quick Grant</h4>
    <p>Grant or deduct credits, shards, and stats — reason required, fully audited.</p>
    <span class="req">Admin+ &middot; NEW</span>
  </a>
  <a class="staffcard" href="index.php?p=admin&sec=txlog" style="--sg-col:#3bcf63;--sg-glow:rgba(59,207,99,.12)">
    <span class="ic">&#129534;</span><h4>Transaction Log</h4>
    <p>Raw cred transfer log — every transfer, grant, and fee.</p>
    <span class="req">Admin+</span>
  </a>
</div>
<?php endif; ?>

<div class="adm-cat">&#128737; Moderation</div>
<div class="staffgrid">
  <?php if ($canAdmin): ?>
  <a class="staffcard" href="index.php?p=jail" style="--sg-col:#ff2d95;--sg-glow:rgba(255,45,149,.12)">
    <span class="ic">&#128274;</span><h4>Confinement Grid</h4>
    <p>Jail players for violations. Set reason and duration. Release early.</p>
    <span class="req">Admin+</span>
  </a>
  <?php endif; ?>
  <?php if (in_array($role, ['moderator','admin','manager'], true)): ?>
  <a class="staffcard" href="index.php?p=admin&sec=iplog" style="--sg-col:#ff2d95;--sg-glow:rgba(255,45,149,.12)">
    <span class="ic">&#127758;</span><h4>IP &amp; Access Log</h4>
    <p>Track logins, IP addresses, and flag suspicious session activity.</p>
    <span class="req">Mod+</span>
  </a>
  <?php endif; ?>
  <a class="staffcard" href="index.php?p=admin&sec=moderation" style="--sg-col:#ff2d95;--sg-glow:rgba(255,45,149,.12)">
    <span class="ic">&#128737;</span><h4>Moderation</h4>
    <p>Delete chat messages, board posts and topics.</p>
    <?php if ($openReports): ?><p style="color:var(--neon2);font-weight:bold">&#9888; <?= $openReports ?> open report(s)</p><?php endif; ?>
    <span class="req">Chat+</span>
  </a>
</div>

<script>
(function(){
'use strict';

/* ── Command-center header: scan grid + radar sweep + status blips ── */
var hc=document.getElementById('adm-canvas');
if(hc){
  var c=hc.getContext('2d');
  var HW=560, HH=96;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  hc.width=HW*dpr; hc.height=HH*dpr;
  c.scale(dpr,dpr);
  var blips=[];
  for(var i=0;i<8;i++) blips.push({x:HW-90+(Math.random()-.5)*70,y:48+(Math.random()-.5)*54,p:Math.random()*9});
  function hLoop(t){
    if(!document.body.contains(hc)) return;
    requestAnimationFrame(hLoop);
    c.clearRect(0,0,HW,HH);
    var bg=c.createLinearGradient(0,0,0,HH);
    bg.addColorStop(0,'#080a12'); bg.addColorStop(1,'#0c0e1a');
    c.fillStyle=bg; c.fillRect(0,0,HW,HH);
    // grid
    c.strokeStyle='rgba(25,240,199,.05)';
    for(var gx=0;gx<HW;gx+=28){ c.beginPath(); c.moveTo(gx,0); c.lineTo(gx,HH); c.stroke(); }
    for(var gy=0;gy<HH;gy+=28){ c.beginPath(); c.moveTo(0,gy); c.lineTo(HW,gy); c.stroke(); }
    // radar (right side)
    var rx=HW-90, ry=48, rr=40;
    c.strokeStyle='rgba(25,240,199,.18)';
    [rr,rr*.66,rr*.33].forEach(function(r2){ c.beginPath(); c.arc(rx,ry,r2,0,Math.PI*2); c.stroke(); });
    c.beginPath(); c.moveTo(rx-rr,ry); c.lineTo(rx+rr,ry); c.moveTo(rx,ry-rr); c.lineTo(rx,ry+rr); c.stroke();
    var ang=t/1300;
    var sweep=c.createConicGradient?(function(){var g2=c.createConicGradient(ang,rx,ry);g2.addColorStop(0,'rgba(25,240,199,.30)');g2.addColorStop(.12,'rgba(25,240,199,0)');g2.addColorStop(1,'rgba(25,240,199,0)');return g2;})():null;
    if(sweep){ c.fillStyle=sweep; c.beginPath(); c.moveTo(rx,ry); c.arc(rx,ry,rr,0,Math.PI*2); c.fill(); }
    c.strokeStyle='rgba(25,240,199,.6)';
    c.beginPath(); c.moveTo(rx,ry); c.lineTo(rx+Math.cos(ang)*rr,ry+Math.sin(ang)*rr); c.stroke();
    // blips light up as the sweep passes
    for(var bi=0;bi<blips.length;bi++){
      var B=blips[bi];
      var ba=Math.atan2(B.y-ry,B.x-rx);
      var diff=((ang-ba)%(Math.PI*2)+Math.PI*2)%(Math.PI*2);
      var lit=Math.max(0,1-diff/1.1);
      c.fillStyle='rgba(59,207,99,'+(.15+.85*lit)+')';
      c.beginPath(); c.arc(B.x,B.y,1.8+lit,0,Math.PI*2); c.fill();
    }
    // scanline
    var sy=(t/40)%HH;
    c.fillStyle='rgba(25,240,199,.05)'; c.fillRect(0,sy,HW,1.5);
  }
  requestAnimationFrame(hLoop);
}

/* ── Signups sparkline ── */
var sp=document.getElementById('adm-spark');
if(sp){
  var sc=sp.getContext('2d');
  var data=<?= json_encode(array_values($signupSeries)) ?>;
  var W2=sp.width, H2=sp.height;
  var mx=Math.max(1,Math.max.apply(null,data));
  sc.strokeStyle='#19f0c7'; sc.lineWidth=1.6; sc.lineJoin='round';
  sc.beginPath();
  data.forEach(function(v,i){
    var x=4+i*(W2-8)/(data.length-1), y=H2-4-(v/mx)*(H2-10);
    i?sc.lineTo(x,y):sc.moveTo(x,y);
  });
  sc.stroke();
  var lg=sc.createLinearGradient(0,0,0,H2);
  lg.addColorStop(0,'rgba(25,240,199,.25)'); lg.addColorStop(1,'rgba(25,240,199,0)');
  sc.lineTo(W2-4,H2-2); sc.lineTo(4,H2-2); sc.closePath();
  sc.fillStyle=lg; sc.fill();
}

/* ── Quick player search autocomplete ── */
var qs=document.getElementById('adm-search'), ql=document.getElementById('admAcList');
if(qs&&ql){
  PlayerAC.attach(qs, ql, {onSelect: function(it){ qs.value=it.username; qs.closest('form').submit(); }});
}
})();
</script>

