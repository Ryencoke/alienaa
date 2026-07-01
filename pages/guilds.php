<?php /* pages/guilds.php — Syndicates */
$pid  = $_SESSION['pid'];
$pdo  = db();
$msg  = '';
$role = $player['role'] ?? 'member';
$isAdmin = in_array($role, ['admin','manager'], true);

// ── Schema ──
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicates (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL UNIQUE,
    tag VARCHAR(8) NOT NULL UNIQUE, description TEXT,
    announcement TEXT NULL, announcement_at DATETIME NULL,
    leader_id INT NOT NULL, bank BIGINT NOT NULL DEFAULT 0,
    level INT NOT NULL DEFAULT 1, xp BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leader (leader_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_members (
    syndicate_id INT NOT NULL, player_id INT NOT NULL,
    rank ENUM('leader','coleader','treasurer','armourer','librarian','advisor','member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id), INDEX idx_syn (syndicate_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Expand ENUM if table already exists with old rank values
  try { $pdo->exec("ALTER TABLE syndicate_members MODIFY COLUMN rank ENUM('leader','coleader','treasurer','armourer','librarian','advisor','member') NOT NULL DEFAULT 'member'"); } catch (Throwable $e) {}

  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_posts (
    id INT AUTO_INCREMENT PRIMARY KEY, syndicate_id INT NOT NULL,
    author_id INT NOT NULL, title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_syn (syndicate_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_stockpile (
    id INT AUTO_INCREMENT PRIMARY KEY, syndicate_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL, gear_type ENUM('weapon','armor','item') NOT NULL DEFAULT 'weapon',
    atk_bonus INT NOT NULL DEFAULT 0, def_bonus INT NOT NULL DEFAULT 0,
    notes VARCHAR(200) NULL, available TINYINT NOT NULL DEFAULT 1,
    added_by INT NOT NULL, added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_syn (syndicate_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_loans (
    id INT AUTO_INCREMENT PRIMARY KEY, syndicate_id INT NOT NULL,
    stockpile_id INT NOT NULL, player_id INT NOT NULL,
    player_gear_id INT NULL, loaned_by INT NOT NULL,
    loaned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_at DATETIME NULL,
    INDEX idx_active (syndicate_id, returned_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_log (
    id INT AUTO_INCREMENT PRIMARY KEY, syndicate_id INT NOT NULL,
    player_id INT NULL, actor_id INT NULL,
    action VARCHAR(60) NOT NULL, detail VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_syn (syndicate_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Add loan_id to player_gear if missing
  try { $pdo->exec("ALTER TABLE player_gear ADD COLUMN loan_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
  // Add announcement columns if missing
  try { $pdo->exec("ALTER TABLE syndicates ADD COLUMN announcement TEXT NULL, ADD COLUMN announcement_at DATETIME NULL"); } catch (Throwable $e) {}
  // Add threaded replies support
  try { $pdo->exec("ALTER TABLE syndicate_posts ADD COLUMN parent_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
  // Add last_post_at for board topic sorting
  try { $pdo->exec("ALTER TABLE syndicate_posts ADD COLUMN last_post_at DATETIME NULL"); } catch (Throwable $e) {}

  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_applications (
    id INT AUTO_INCREMENT PRIMARY KEY, syndicate_id INT NOT NULL,
    player_id INT NOT NULL, message VARCHAR(400) NULL,
    status ENUM('pending','accepted','denied') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app (syndicate_id, player_id, status),
    INDEX idx_syn (syndicate_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

define('SYNDICATE_CREATE_COST', 50);

// ── Role helpers ──
$SYN_RANKS = ['leader','coleader','treasurer','armourer','librarian','advisor','member'];
$SYN_RANK_LABELS = ['leader'=>'Overseer','coleader'=>'Underlord','treasurer'=>'Vault Keeper','armourer'=>'Armory Chief','librarian'=>'Lore Keeper','advisor'=>'Cipher','member'=>'Operative'];
$SYN_RANK_COLORS = ['leader'=>'var(--neon2)','coleader'=>'#e23b3b','treasurer'=>'#3bcf63','armourer'=>'var(--accent)','librarian'=>'#9d6bff','advisor'=>'#e8a33d','member'=>'var(--muted)'];

function syn_can($rank, $perm) {
  $perms = [
    'manage_members'  => ['leader','coleader'],
    'manage_bank'     => ['leader','coleader','treasurer'],
    'manage_stockpile'=> ['leader','coleader','armourer'],
    'give_stockpile'  => ['leader','coleader','treasurer'],
    'loan_items'      => ['leader','coleader','armourer'],
    'post_board'      => ['leader','coleader','treasurer','armourer','librarian','advisor','member'],
    'post_announce'   => ['leader','coleader'],
    'view_log'        => ['leader','coleader'],
  ];
  return in_array($rank, $perms[$perm] ?? [], true);
}

function syn_log($pdo, $sid, $pid, $actorId, $action, $detail = '') {
  try { $pdo->prepare('INSERT INTO syndicate_log (syndicate_id,player_id,actor_id,action,detail) VALUES (?,?,?,?,?)')->execute([$sid,$pid,$actorId,$action,$detail]); } catch (Throwable $e) {}
}
// Group identical stockpile/armoury rows (same item + status) for display so donated
// duplicates show once as "Name (×N)" instead of N separate rows. Each group keeps one
// representative row (for its id/notes/etc — actions still act on that single row; the
// count just reflects how many identical copies exist).
function syn_group_stock(array $rows, array $extraKeys = []): array {
  $groups = [];
  foreach ($rows as $r) {
    $key = $r['item_name'] . '|' . $r['available'];
    foreach ($extraKeys as $ek) $key .= '|' . ($r[$ek] ?? '');
    if (!isset($groups[$key])) { $groups[$key] = $r; $groups[$key]['_count'] = 0; }
    $groups[$key]['_count']++;
  }
  return array_values($groups);
}
// Hideout notification (same table/shape pvp.php uses for combat notifications).
function syn_notify($pdo, $playerId, $type, $body) {
  try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS player_notifications (id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, type VARCHAR(40) NOT NULL DEFAULT "info", body TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_player_read (player_id, is_read)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->prepare('INSERT INTO player_notifications (player_id,type,body) VALUES (?,?,?)')->execute([$playerId,$type,$body]);
  } catch (Throwable $e) {}
}
function syn_add_sidebar($pdo, $playerId, $key) {
  try {
    $sq = $pdo->prepare('SELECT sidebar FROM players WHERE id=?'); $sq->execute([$playerId]);
    $current = trim((string)$sq->fetchColumn());
    $keys = $current !== '' ? explode(',', $current) : default_sidebar();
    if (!in_array($key, $keys, true)) {
      $keys[] = $key;
      $pdo->prepare('UPDATE players SET sidebar=? WHERE id=?')->execute([implode(',', $keys), $playerId]);
    }
  } catch (Throwable $e) {}
}
function syn_remove_sidebar($pdo, $playerId, $key) {
  try {
    $sq = $pdo->prepare('SELECT sidebar FROM players WHERE id=?'); $sq->execute([$playerId]);
    $current = trim((string)$sq->fetchColumn());
    $keys = $current !== '' ? explode(',', $current) : default_sidebar();
    $keys = array_values(array_filter($keys, fn($k) => $k !== $key));
    $pdo->prepare('UPDATE players SET sidebar=? WHERE id=?')->execute([implode(',', $keys), $playerId]);
  } catch (Throwable $e) {}
}

// Un-equip (if currently worn) and delete a player's loaned player_gear row.
// Shared by leave/kick/return_item so a returned/reclaimed loan can't stay
// silently equipped via a stale equipped_weapon:/equipped_armor: settings key.
function syn_unequip_and_delete_gear(PDO $pdo, int $pid, int $gearId): void {
  if ($gearId <= 0) return;
  foreach (['weapon','armor'] as $slot) {
    $gq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $gq->execute(["equipped_{$slot}:{$pid}"]);
    if ((int)($gq->fetchColumn() ?: 0) === $gearId) {
      $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["equipped_{$slot}:{$pid}"]);
    }
  }
  $pdo->prepare('DELETE FROM player_gear WHERE id=? AND player_id=?')->execute([$gearId, $pid]);
}

// ── Load membership ──
$mySyn = null; $myRank = '';
try {
  $q = $pdo->prepare('SELECT sm.syndicate_id,sm.rank,s.name,s.tag,s.level,s.xp,s.bank,s.description,s.leader_id,s.announcement,s.announcement_at FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?');
  $q->execute([$pid]); $mySyn = $q->fetch() ?: null;
  if ($mySyn) $myRank = $mySyn['rank'];
} catch (Throwable $e) {}

$leaderIsSubbed = false;
if ($mySyn) {
  try {
    $lsq = $pdo->prepare('SELECT sub_until FROM players WHERE id=?');
    $lsq->execute([$mySyn['leader_id']]);
    $lr = $lsq->fetch();
    $leaderIsSubbed = !empty($lr['sub_until']) && $lr['sub_until'] >= date('Y-m-d');
  } catch (Throwable $e) {}
}
$playerIsSubbed = is_subscribed($player);
// A syndicate is locked once the leader's subscription lapses, unless the
// acting member has their own individual subscription. Locking gates the
// whole syndicate area (every "my syndicate" tab + every write action) instead
// of the old per-tab warnings that still let everything function underneath.
$MY_SYN_TABS = ['home','board','chat','members','staff','stockpile','armoury','treasury','log'];
$synLocked = $mySyn && !$leaderIsSubbed && !$playerIsSubbed;

// ── Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    // Locked syndicate: block every action except leaving, regardless of what
    // the (hidden) UI would otherwise offer — a locked tab has no forms to
    // submit, but this stops a direct POST from bypassing that.
    if ($synLocked && $act !== 'leave') throw new RuntimeException('Syndicate locked — the leader\'s subscription has expired.');

    if ($act === 'create') {
      if ($mySyn) throw new RuntimeException('You already belong to a Syndicate. Leave it first.');
      $name = trim($_POST['syn_name'] ?? '');
      $tag  = strtoupper(trim($_POST['syn_tag'] ?? ''));
      $desc = trim($_POST['syn_desc'] ?? '');
      if (!preg_match('/^[A-Za-z0-9 _\-]{3,60}$/', $name)) throw new RuntimeException('Name: 3–60 chars, letters/numbers/spaces only.');
      if (!preg_match('/^[A-Z0-9]{2,8}$/', $tag))           throw new RuntimeException('Tag: 2–8 uppercase letters/numbers only.');
      // Friendly pre-check so a dup name/tag doesn't surface as a raw SQL error
      $dup = $pdo->prepare('SELECT 1 FROM syndicates WHERE name=? OR tag=? LIMIT 1');
      $dup->execute([$name, $tag]);
      if ($dup->fetchColumn()) throw new RuntimeException('That name or tag is already taken.');
      // Deduct INSIDE the transaction — a dup that slips past the pre-check (race)
      // now rolls back the shard charge instead of pocketing it.
      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SYNDICATE_CREATE_COST, $pid, SYNDICATE_CREATE_COST]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough Shards. Costs ' . SYNDICATE_CREATE_COST . ' ◆.');
      $pdo->prepare('INSERT INTO syndicates (name,tag,description,leader_id) VALUES (?,?,?,?)')->execute([$name,$tag,$desc,$pid]);
      $sid = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO syndicate_members (syndicate_id,player_id,rank) VALUES (?,?,?)')->execute([$sid,$pid,'leader']);
      $pdo->commit();
      syn_log($pdo, $sid, $pid, $pid, 'founded', "Founded \"{$name}\"");
      syn_add_sidebar($pdo, $pid, 'guilds');
      $msg = 'Syndicate "' . $name . '" [' . $tag . '] created.';
      $q->execute([$pid]); $mySyn = $q->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];

    } elseif ($act === 'apply') {
      if ($mySyn) throw new RuntimeException('You already belong to a Syndicate.');
      $sid = (int)($_POST['syn_id'] ?? 0);
      $appmsg = mb_substr(trim($_POST['app_message'] ?? ''), 0, 400);
      $sq = $pdo->prepare('SELECT id,name FROM syndicates WHERE id=?'); $sq->execute([$sid]); $syn = $sq->fetch();
      if (!$syn) throw new RuntimeException('Syndicate not found.');
      // Check no pending application already
      $chk = $pdo->prepare("SELECT id FROM syndicate_applications WHERE syndicate_id=? AND player_id=? AND status='pending'");
      $chk->execute([$sid,$pid]);
      if ($chk->fetch()) throw new RuntimeException('You already have a pending application.');
      $pdo->prepare('INSERT INTO syndicate_applications (syndicate_id,player_id,message) VALUES (?,?,?)')->execute([$sid,$pid,$appmsg ?: null]);
      $msg = 'Application sent to ' . $syn['name'] . '. Await leadership approval.';

    } elseif ($act === 'approve_app') {
      if (!$mySyn || !syn_can($myRank,'manage_members')) throw new RuntimeException('No permission.');
      $appId = (int)($_POST['app_id'] ?? 0);
      $aq = $pdo->prepare("SELECT * FROM syndicate_applications WHERE id=? AND syndicate_id=? AND status='pending'");
      $aq->execute([$appId, $mySyn['syndicate_id']]); $app = $aq->fetch();
      if (!$app) throw new RuntimeException('Application not found.');
      // Check applicant not already in a syndicate
      $mc = $pdo->prepare('SELECT syndicate_id FROM syndicate_members WHERE player_id=?'); $mc->execute([$app['player_id']]); if ($mc->fetchColumn()) throw new RuntimeException('Player already joined a Syndicate.');
      // All three writes commit together — otherwise a mid-sequence failure left
      // the player a member with the application stuck "pending".
      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO syndicate_members (syndicate_id,player_id,rank) VALUES (?,?,?)')->execute([$mySyn['syndicate_id'],$app['player_id'],'member']);
      syn_grant_xp($pdo, (int)$mySyn['syndicate_id'], 50);
      $pdo->prepare("UPDATE syndicate_applications SET status='accepted' WHERE id=?")->execute([$appId]);
      $pdo->commit();
      $uname = $pdo->prepare('SELECT username FROM players WHERE id=?'); $uname->execute([$app['player_id']]); $un = $uname->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$app['player_id'],$pid,'joined',$un.' accepted and joined the syndicate');
      syn_add_sidebar($pdo, $app['player_id'], 'guilds');
      $msg = $un . ' has been accepted.';

    } elseif ($act === 'deny_app') {
      if (!$mySyn || !syn_can($myRank,'manage_members')) throw new RuntimeException('No permission.');
      $appId = (int)($_POST['app_id'] ?? 0);
      $pdo->prepare("UPDATE syndicate_applications SET status='denied' WHERE id=? AND syndicate_id=?")->execute([$appId, $mySyn['syndicate_id']]);
      $msg = 'Application denied.';

    } elseif ($act === 'leave') {
      if (!$mySyn) throw new RuntimeException('You are not in a Syndicate.');
      if (in_array($myRank, ['leader','coleader'], true)) throw new RuntimeException('Leaders and co-leaders must transfer or be demoted before leaving.');
      $leavemsg = mb_substr(trim($_POST['leave_message'] ?? ''), 0, 200);
      $sid = (int)$mySyn['syndicate_id'];
      // Auto-return all loaned items
      $loans = $pdo->prepare('SELECT sl.id,sl.player_gear_id FROM syndicate_loans sl WHERE sl.syndicate_id=? AND sl.player_id=? AND sl.returned_at IS NULL');
      $loans->execute([$sid,$pid]); $loanRows = $loans->fetchAll();
      foreach ($loanRows as $ln) {
        syn_unequip_and_delete_gear($pdo, $pid, (int)$ln['player_gear_id']);
        $pdo->prepare('UPDATE syndicate_loans SET returned_at=NOW() WHERE id=?')->execute([$ln['id']]);
        $pdo->prepare('UPDATE syndicate_stockpile SET available=1 WHERE id=(SELECT stockpile_id FROM syndicate_loans WHERE id=?)')->execute([$ln['id']]);
      }
      $pdo->prepare('DELETE FROM syndicate_members WHERE player_id=?')->execute([$pid]);
      $leaveDetail = $player['username'].' left the syndicate' . ($leavemsg ? ': "'.$leavemsg.'"' : '');
      syn_log($pdo, $sid, $pid, $pid, 'left', $leaveDetail);
      syn_remove_sidebar($pdo, $pid, 'guilds');
      $mySyn = null; $myRank = ''; $msg = 'You left the Syndicate.';

    } elseif ($act === 'donate') {
      if (!$mySyn) throw new RuntimeException('Not in a Syndicate.');
      $amt = (int)($_POST['amount'] ?? 0); if ($amt < 1) throw new RuntimeException('Enter an amount above zero.');
      $u = $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?'); $u->execute([$amt,$pid,$amt]);
      if ($u->rowCount()!==1) throw new RuntimeException('Not enough credits in pocket.');
      $pdo->prepare('UPDATE syndicates SET bank=bank+? WHERE id=?')->execute([$amt,$mySyn['syndicate_id']]);
      syn_grant_xp($pdo, (int)$mySyn['syndicate_id'], max(1,(int)($amt/100)));
      syn_log($pdo,$mySyn['syndicate_id'],$pid,$pid,'donated',number_format($amt).' cr donated to bank');
      $player = current_player();
      $msg = 'Donated ' . number_format($amt) . ' credits.';
      $q->execute([$pid]); $mySyn = $q->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];

    } elseif ($act === 'xp_donate') {
      if (!$mySyn) throw new RuntimeException('Not in a Syndicate.');
      $rate = max(0, min(100, (int)($_POST['rate'] ?? 0)));
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["guild_xp_donate:{$pid}", (string)$rate]);
      $msg = $rate > 0
        ? "Battle-XP donation set to {$rate}% — that share of XP you earn in combat now feeds the Syndicate."
        : 'Battle-XP donation turned off. You keep all combat XP.';

    } elseif ($act === 'bank_withdraw') {
      if (!$mySyn || !syn_can($myRank,'manage_bank')) throw new RuntimeException('No permission.');
      $amt = (int)($_POST['amount'] ?? 0); if ($amt < 1) throw new RuntimeException('Enter an amount.');
      $u = $pdo->prepare('UPDATE syndicates SET bank=bank-? WHERE id=? AND bank>=?'); $u->execute([$amt,$mySyn['syndicate_id'],$amt]);
      if ($u->rowCount()!==1) throw new RuntimeException('Syndicate bank is too low.');
      $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket+? WHERE id=?')->execute([$amt,$pid]);
      syn_log($pdo,$mySyn['syndicate_id'],$pid,$pid,'withdrew',number_format($amt).' cr withdrawn from bank');
      $player = current_player();
      $msg = 'Withdrew ' . number_format($amt) . ' credits.';
      $q->execute([$pid]); $mySyn = $q->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];

    } elseif ($act === 'post') {
      if (!$mySyn || !syn_can($myRank,'post_board')) throw new RuntimeException('Members only.');
      $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
      $body = trim($_POST['post_body'] ?? '');
      if (mb_strlen($body) < 2) throw new RuntimeException('Write something.');
      if ($parentId) {
        // reply: verify parent is top-level in this syndicate
        $pp = $pdo->prepare('SELECT id FROM syndicate_posts WHERE id=? AND syndicate_id=? AND (parent_id IS NULL OR parent_id=0)');
        $pp->execute([$parentId, $mySyn['syndicate_id']]);
        if (!$pp->fetch()) throw new RuntimeException('Thread not found.');
        $pdo->prepare('INSERT INTO syndicate_posts (syndicate_id,author_id,title,body,parent_id) VALUES (?,?,?,?,?)')->execute([$mySyn['syndicate_id'],$pid,'',$body,$parentId]);
        $msg = 'Reply posted.';
      } else {
        $title = trim($_POST['post_title'] ?? '');
        if ($title===''||mb_strlen($title)>120) throw new RuntimeException('Title: 1–120 chars.');
        $pdo->prepare('INSERT INTO syndicate_posts (syndicate_id,author_id,title,body,parent_id) VALUES (?,?,?,?,NULL)')->execute([$mySyn['syndicate_id'],$pid,$title,$body]);
        $msg = 'Discussion started.';
      }

    } elseif ($act === 'set_announce') {
      if (!$mySyn || !syn_can($myRank,'post_announce')) throw new RuntimeException('No permission.');
      $txt = mb_substr(trim($_POST['announcement'] ?? ''), 0, 500);
      $pdo->prepare('UPDATE syndicates SET announcement=?,announcement_at=NOW() WHERE id=?')->execute([$txt ?: null,$mySyn['syndicate_id']]);
      syn_log($pdo,$mySyn['syndicate_id'],$pid,$pid,'announcement','Announcement updated');
      $msg = 'Announcement updated.';
      $q->execute([$pid]); $mySyn = $q->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];

    } elseif ($act === 'setrole') {
      if (!$mySyn || !syn_can($myRank,'manage_members')) throw new RuntimeException('No permission.');
      $target = (int)($_POST['target_id'] ?? 0); $newRank = $_POST['new_rank'] ?? '';
      if (!in_array($newRank, array_diff($SYN_RANKS, ['leader']), true)) throw new RuntimeException('Invalid rank.');
      // The leader can't be demoted via this action — otherwise a co-leader could
      // forge target_id=<leader> to demote then kick the Overseer.
      $trq = $pdo->prepare('SELECT rank FROM syndicate_members WHERE player_id=? AND syndicate_id=?');
      $trq->execute([$target, $mySyn['syndicate_id']]);
      $curRank = $trq->fetchColumn();
      if (!$curRank) throw new RuntimeException('Not a member.');
      if ($curRank === 'leader') throw new RuntimeException('Cannot change the leader\'s rank.');
      $pdo->prepare('UPDATE syndicate_members SET rank=? WHERE player_id=? AND syndicate_id=?')->execute([$newRank,$target,$mySyn['syndicate_id']]);
      $tq = $pdo->prepare('SELECT username FROM players WHERE id=?'); $tq->execute([$target]); $tn = $tq->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$target,$pid,'role_change',$tn.' set to '.($SYN_RANK_LABELS[$newRank]??$newRank));
      $msg = 'Rank updated.';

    } elseif ($act === 'kick') {
      if (!$mySyn || !syn_can($myRank,'manage_members')) throw new RuntimeException('No permission.');
      $target = (int)($_POST['target_id'] ?? 0);
      // Verify target is in same syndicate and not the leader
      $ck = $pdo->prepare('SELECT rank FROM syndicate_members WHERE player_id=? AND syndicate_id=?'); $ck->execute([$target,$mySyn['syndicate_id']]); $tr = $ck->fetchColumn();
      if (!$tr) throw new RuntimeException('Not a member.');
      if ($tr === 'leader') throw new RuntimeException('Cannot kick the leader.');
      // Auto-return loans
      $loans = $pdo->prepare('SELECT id,player_gear_id FROM syndicate_loans WHERE syndicate_id=? AND player_id=? AND returned_at IS NULL'); $loans->execute([$mySyn['syndicate_id'],$target]); $lrows = $loans->fetchAll();
      foreach ($lrows as $ln) {
        syn_unequip_and_delete_gear($pdo, $target, (int)$ln['player_gear_id']);
        $pdo->prepare('UPDATE syndicate_loans SET returned_at=NOW() WHERE id=?')->execute([$ln['id']]);
        $pdo->prepare('UPDATE syndicate_stockpile SET available=1 WHERE id=(SELECT stockpile_id FROM syndicate_loans WHERE id=?)')->execute([$ln['id']]);
      }
      $pdo->prepare('DELETE FROM syndicate_members WHERE player_id=? AND syndicate_id=?')->execute([$target,$mySyn['syndicate_id']]);
      $tq = $pdo->prepare('SELECT username FROM players WHERE id=?'); $tq->execute([$target]); $tn = $tq->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$target,$pid,'kicked',$tn.' was kicked');
      $msg = 'Member kicked.';

    } elseif ($act === 'stockpile_add') {
      if (!$mySyn || !syn_can($myRank,'manage_stockpile')) throw new RuntimeException('No permission.');
      $donateItemId = (int)($_POST['donate_item_id'] ?? 0);
      $donateQty    = max(1, min(99, (int)($_POST['donate_qty'] ?? 1)));
      $notes = mb_substr(trim($_POST['notes'] ?? ''),0,200);
      if (!$donateItemId) throw new RuntimeException('Select an item to donate.');
      // Verify player has enough in inventory; pull slot and stats
      $iq = $pdo->prepare('SELECT i.name, i.slot, i.atk, i.def, pi.qty FROM player_items pi JOIN items i ON i.id=pi.item_id WHERE pi.player_id=? AND pi.item_id=? AND pi.qty>0');
      $iq->execute([$pid, $donateItemId]); $irow = $iq->fetch();
      if (!$irow) throw new RuntimeException('Item not found in inventory.');
      if ($irow['qty'] < $donateQty) throw new RuntimeException('Not enough in inventory (you have '.$irow['qty'].').');
      $iname = $irow['name'];
      // Auto-route weapons/armor to Armoury; everything else to Stockpile
      $gearType = in_array($irow['slot'] ?? '', ['weapon','armor'], true) ? $irow['slot'] : 'item';
      $atkBonus = $gearType === 'weapon' ? (int)($irow['atk'] ?? 0) : 0;
      $defBonus = $gearType === 'armor'  ? (int)($irow['def'] ?? 0) : 0;
      // Deduct from inventory
      if ($irow['qty'] - $donateQty <= 0) $pdo->prepare('DELETE FROM player_items WHERE player_id=? AND item_id=?')->execute([$pid, $donateItemId]);
      else $pdo->prepare('UPDATE player_items SET qty=qty-? WHERE player_id=? AND item_id=?')->execute([$donateQty, $pid, $donateItemId]);
      // Create one stockpile entry per item donated
      $dest = $gearType === 'item' ? 'stockpile' : 'armoury';
      for ($di=0; $di<$donateQty; $di++) {
        $pdo->prepare('INSERT INTO syndicate_stockpile (syndicate_id,item_name,gear_type,atk_bonus,def_bonus,notes,added_by) VALUES (?,?,?,?,?,?,?)')->execute([$mySyn['syndicate_id'],$iname,$gearType,$atkBonus,$defBonus,$notes,$pid]);
      }
      syn_log($pdo,$mySyn['syndicate_id'],null,$pid,'stockpile_add','Donated '.$donateQty.'× "'.$iname.'" to '.$dest);
      $msg = 'Donated ' . $donateQty . '× "' . $iname . '" to the ' . $dest . '.';

    } elseif ($act === 'stockpile_give') {
      // Giving items outright (as opposed to donating/loaning) is a Vault Keeper+ power —
      // narrower than manage_stockpile, which also covers Armory Chiefs.
      if (!$mySyn || !syn_can($myRank,'give_stockpile')) throw new RuntimeException('No permission.');
      $iid = (int)($_POST['item_id'] ?? 0);
      $recipient = (int)($_POST['recipient_id'] ?? 0);
      $ck = $pdo->prepare('SELECT item_name,available,gear_type FROM syndicate_stockpile WHERE id=? AND syndicate_id=?'); $ck->execute([$iid,$mySyn['syndicate_id']]); $si = $ck->fetch();
      if (!$si) throw new RuntimeException('Item not found.');
      // Weapons/armor are Armoury assets — they can only be loaned out and returned,
      // never handed out permanently, so a loan can't be undercut by giving away the source.
      if (in_array($si['gear_type'] ?? '', ['weapon','armor'], true)) throw new RuntimeException('Weapons and armor can only be loaned, not given from the Armoury.');
      if (!$si['available']) throw new RuntimeException('Item is currently loaned out.');
      $rq = $pdo->prepare('SELECT rank FROM syndicate_members WHERE player_id=? AND syndicate_id=?'); $rq->execute([$recipient,$mySyn['syndicate_id']]);
      if (!$rq->fetchColumn()) throw new RuntimeException('Recipient is not a member of this Syndicate.');
      $catQ = $pdo->prepare('SELECT id FROM items WHERE name=? LIMIT 1'); $catQ->execute([$si['item_name']]);
      $catalogId = (int)$catQ->fetchColumn();
      if (!$catalogId) throw new RuntimeException('This item can no longer be matched to the item catalog — flag it to staff.');
      $rn = $pdo->prepare('SELECT username FROM players WHERE id=?'); $rn->execute([$recipient]); $rname = $rn->fetchColumn() ?: '?';
      $pdo->prepare('INSERT INTO player_items (player_id, item_id, qty) VALUES (?,?,1) ON DUPLICATE KEY UPDATE qty=qty+1')->execute([$recipient, $catalogId]);
      $pdo->prepare('DELETE FROM syndicate_stockpile WHERE id=? AND syndicate_id=?')->execute([$iid,$mySyn['syndicate_id']]);
      syn_log($pdo,$mySyn['syndicate_id'],$recipient,$pid,'stockpile_give','"'.$si['item_name'].'" given'); // recipient rendered as a profile link (see tab=log)
      syn_notify($pdo, $recipient, 'guild_loan', '"'.$si['item_name'].'" was given to you from the Stockpile by '.$player['username'].'.');
      $msg = 'Gave "' . $si['item_name'] . '" to ' . $rname . '.';

    } elseif ($act === 'loan_item') {
      if (!$mySyn || !syn_can($myRank,'loan_items')) throw new RuntimeException('No permission.');
      $iid = (int)($_POST['item_id'] ?? 0); $borrower = (int)($_POST['borrower_id'] ?? 0);
      // Verify item belongs to this syndicate and is available
      $sq = $pdo->prepare('SELECT * FROM syndicate_stockpile WHERE id=? AND syndicate_id=? AND available=1'); $sq->execute([$iid,$mySyn['syndicate_id']]); $si = $sq->fetch();
      if (!$si) throw new RuntimeException('Item not found or already loaned.');
      // Only weapons/armor are loanable — general stockpile items stay donated outright.
      if (!in_array($si['gear_type'] ?? '', ['weapon','armor'], true)) throw new RuntimeException('Only weapons and armor can be loaned.');
      // Verify borrower is in syndicate
      $bq = $pdo->prepare('SELECT rank FROM syndicate_members WHERE player_id=? AND syndicate_id=?'); $bq->execute([$borrower,$mySyn['syndicate_id']]); if (!$bq->fetchColumn()) throw new RuntimeException('Not a syndicate member.');
      // Insert into borrower's player_gear
      $pdo->prepare("INSERT INTO player_gear (player_id,recipe_id,name,gear_type,atk_bonus,def_bonus,loan_id) VALUES (?,0,?,?,?,?,0)")->execute([$borrower,$si['item_name'],$si['gear_type'],$si['atk_bonus'],$si['def_bonus']]);
      $pgid = (int)$pdo->lastInsertId();
      // Create loan record
      $pdo->prepare('INSERT INTO syndicate_loans (syndicate_id,stockpile_id,player_id,player_gear_id,loaned_by) VALUES (?,?,?,?,?)')->execute([$mySyn['syndicate_id'],$iid,$borrower,$pgid,$pid]);
      $lid = (int)$pdo->lastInsertId();
      // Update player_gear loan_id to the loan id
      $pdo->prepare('UPDATE player_gear SET loan_id=? WHERE id=?')->execute([$lid,$pgid]);
      // Mark stockpile item as loaned out
      $pdo->prepare('UPDATE syndicate_stockpile SET available=0 WHERE id=?')->execute([$iid]);
      $bname = $pdo->prepare('SELECT username FROM players WHERE id=?'); $bname->execute([$borrower]); $bn = $bname->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$borrower,$pid,'loan_out','"'.$si['item_name'].'" loaned'); // borrower rendered as a profile link (see tab=log)
      syn_notify($pdo, $borrower, 'guild_loan', '"'.$si['item_name'].'" was loaned to you from the Armoury by '.$player['username'].'.');
      $msg = '"' . $si['item_name'] . '" loaned to ' . $bn . '.';

    } elseif ($act === 'return_item') {
      if (!$mySyn) throw new RuntimeException('Not in a Syndicate.');
      $lid = (int)($_POST['loan_id'] ?? 0);
      $lq = $pdo->prepare('SELECT * FROM syndicate_loans WHERE id=? AND syndicate_id=? AND returned_at IS NULL'); $lq->execute([$lid,$mySyn['syndicate_id']]); $ln = $lq->fetch();
      if (!$ln) throw new RuntimeException('Loan not found.');
      // Only the borrower or armourers+ can return
      $isSelfReturn = (int)$ln['player_id'] === $pid;
      if (!$isSelfReturn && !syn_can($myRank,'loan_items')) throw new RuntimeException('No permission.');
      // Remove from borrower's player_gear
      syn_unequip_and_delete_gear($pdo, (int)$ln['player_id'], (int)$ln['player_gear_id']);
      $pdo->prepare('UPDATE syndicate_loans SET returned_at=NOW() WHERE id=?')->execute([$lid]);
      $pdo->prepare('UPDATE syndicate_stockpile SET available=1 WHERE id=?')->execute([$ln['stockpile_id']]);
      $sq = $pdo->prepare('SELECT item_name FROM syndicate_stockpile WHERE id=?'); $sq->execute([$ln['stockpile_id']]); $sname = $sq->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$ln['player_id'],$pid,'returned','"'.$sname.'" returned to stockpile');
      // Only notify on an officer-initiated recall — a player returning their own
      // loan already knows, so a notification would just be noise.
      if (!$isSelfReturn) syn_notify($pdo, (int)$ln['player_id'], 'guild_loan', '"'.$sname.'" was recalled to the Armoury by '.$player['username'].'.');
      $msg = '"' . $sname . '" returned to stockpile.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

$tab = $_GET['tab'] ?? '';
$boardTid = (int)($_GET['tid'] ?? 0); // board: topic detail view
// 'search' (directory) and 'view' (read-only profile of any syndicate) are reachable
// regardless of membership — everyone should be able to browse other Syndicates.
$validTabs = ($mySyn ? $MY_SYN_TABS : ['create']);
$validTabs[] = 'search';
$validTabs[] = 'view';
if (!in_array($tab, $validTabs, true)) $tab = $mySyn ? 'home' : 'search';

// Pending applications count for badge
$pendingApps = 0;
if ($mySyn && syn_can($myRank, 'manage_members')) {
  try { $pa = $pdo->prepare("SELECT COUNT(*) FROM syndicate_applications WHERE syndicate_id=? AND status='pending'"); $pa->execute([$mySyn['syndicate_id']]); $pendingApps = (int)$pa->fetchColumn(); } catch (Throwable $e) {}
}
?>

<?php
// Member count for the HQ header
$synMemberCount = 0;
if ($mySyn) {
  try { $mcq = $pdo->prepare('SELECT COUNT(*) FROM syndicate_members WHERE syndicate_id=?'); $mcq->execute([$mySyn['syndicate_id']]); $synMemberCount = (int)$mcq->fetchColumn(); } catch (Throwable $e) {}
}
?>
<style>
#syn-canvas{display:block;width:100%;height:108px;border-radius:9px 9px 0 0}
#syn-head h2{text-shadow:0 0 14px rgba(255,45,149,.4)}
.syn-pill{padding:7px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s}
.syn-pill.on{border-color:var(--neon2);background:rgba(255,45,149,.1);color:var(--neon2);box-shadow:0 0 10px rgba(255,45,149,.14)}
.syn-stat{position:relative;overflow:hidden;text-align:center;margin-bottom:0;transition:transform .12s,border-color .15s,box-shadow .15s}
.syn-stat:hover{transform:translateY(-2px);border-color:var(--ss-col,var(--accent));box-shadow:0 4px 12px rgba(0,0,0,.3)}
.syn-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--ss-col,var(--accent)),transparent)}
.syn-feedrow{transition:background .12s}
.syn-feedrow:hover{background:rgba(255,255,255,.025)}
.syn-rankchip{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.06em;padding:2px 10px;border-radius:10px;border:1px solid currentColor;background:rgba(0,0,0,.35)}
</style>

<?php if ($msg): ?>
<div class="flash <?= ($msgErr ?? false) ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Header -->
<div class="panel" id="syn-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="syn-canvas"
      data-tag="<?= $mySyn ? e($mySyn['tag']) : '' ?>"
      data-name="<?= $mySyn ? e($mySyn['name']) : '' ?>"></canvas>
    <div style="position:absolute;left:16px;bottom:12px;pointer-events:none">
      <h2 style="margin:0">&#9760; Syndicates</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Factions of the Sprawl. Strength through collective.</p>
    </div>
    <?php if ($mySyn): ?>
    <div style="position:absolute;right:14px;bottom:12px;text-align:right">
      <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:var(--neon2);text-shadow:0 0 10px rgba(255,45,149,.5)">[<?= e($mySyn['tag']) ?>] <?= e($mySyn['name']) ?></div>
      <div style="margin-top:4px">
        <span class="syn-rankchip" style="color:<?= e($SYN_RANK_COLORS[$myRank]??'var(--muted)') ?>"><?= e($SYN_RANK_LABELS[$myRank] ?? ucfirst($myRank)) ?></span>
        <span style="font-size:10px;color:var(--muted);margin-left:8px"><?= $synMemberCount ?> member<?= $synMemberCount!==1?'s':'' ?></span>
      </div>
    </div>
    <?php else: ?>
    <div style="position:absolute;right:14px;bottom:12px;text-align:right;font-size:11px;color:var(--muted)">
      Unaffiliated &mdash; <span style="color:var(--neon2)">find your faction</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <?php
  $membersLabel = '&#128101; Members' . ($pendingApps ? ' <span style="background:var(--neon2);color:#000;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:700">'.$pendingApps.'</span>' : '');
  $tabDefs = $mySyn
    ? ['home'=>'&#128202; Overview','board'=>'&#128203; Board','chat'=>'&#128172; Chat','members'=>$membersLabel,'staff'=>'&#128737; Staff','stockpile'=>'&#9874; Stockpile','armoury'=>'&#9876; Armoury','treasury'=>'&#128178; Treasury','log'=>'&#128196; Log']
    : ['create'=>'&#43; Create'];
  // Directory is shown to everyone — members browse other Syndicates here too,
  // they just won't see an Apply button on their own (or any, while affiliated).
  $tabDefs = ['search'=>($mySyn ? '&#128270; Directory' : '&#128269; Find Syndicate')] + $tabDefs;
  foreach ($tabDefs as $tk=>$tl):
    $show = isset($tabDefs[$tk]);
    if ($tk==='log' && !syn_can($myRank,'view_log') && !$isAdmin) continue;
  ?>
  <a href="index.php?p=guilds&tab=<?= $tk ?>" class="syn-pill <?= ($tab===$tk || ($tab==='view' && $tk==='search')) ? 'on' : '' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php // ══ LOCKED ═════════════════════════════════════════
// The leader's subscription has lapsed and this member has no individual
// subscription of their own — every "my syndicate" tab shows this instead of
// its normal content, rather than the old per-tab warnings that still left
// everything usable underneath.
if ($synLocked && in_array($tab, $MY_SYN_TABS, true)): ?>
<div class="panel" style="border:1px solid rgba(226,59,59,.3);background:rgba(226,59,59,.05);text-align:center;padding:36px 20px">
  <div style="font-size:34px;margin-bottom:10px">&#128274;</div>
  <h3 style="margin:0 0 8px;color:#e23b3b">Syndicate Locked</h3>
  <p class="muted" style="font-size:13px;max-width:440px;margin:0 auto;line-height:1.6">
    <b style="color:var(--text)"><?= e($mySyn['name']) ?></b>'s Overseer subscription has expired.
    Syndicate features are locked for members without their own individual subscription.
  </p>
  <?php if (!in_array($myRank, ['leader','coleader'], true)): ?>
  <form method="post" style="margin-top:18px" onsubmit="return confirm('Leave your Syndicate? Any loaned items will be returned.')">
    <input type="hidden" name="action" value="leave">
    <button type="submit" style="background:rgba(255,45,149,.08);border-color:rgba(255,45,149,.25);color:var(--neon2);font-size:12px">Leave Syndicate</button>
  </form>
  <?php else: ?>
  <p style="font-size:11px;color:#e8a33d;margin-top:16px">As leader/co-leader, subscribe to restore full access for your members.</p>
  <?php endif; ?>
</div>

<?php // ══ OVERVIEW ══════════════════════════════════════
elseif ($tab === 'home' && $mySyn):

  // Load recent log
  $recentLog = [];
  try { $lq = $pdo->prepare('SELECT sl.*,p.username FROM syndicate_log sl LEFT JOIN players p ON p.id=sl.actor_id WHERE sl.syndicate_id=? ORDER BY sl.id DESC LIMIT 5'); $lq->execute([$mySyn['syndicate_id']]); $recentLog = $lq->fetchAll(); } catch (Throwable $e) {}
?>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
  <?php foreach ([['Level',(int)$mySyn['level'],'var(--accent)'],['XP',number_format((int)$mySyn['xp']),'#e8a33d'],['Bank',number_format((int)$mySyn['bank']).' cr','#3bcf63'],['Members',$synMemberCount,'var(--neon2)']] as [$sl,$sv,$sc]): ?>
  <div class="panel syn-stat" style="--ss-col:<?= $sc ?>">
    <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:<?= $sc ?>"><?= $sv ?></div>
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px"><?= $sl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php
  // Level progress bar — XP earned within the current level toward the next
  $synLvl   = (int)$mySyn['level'];
  $synXp    = (int)$mySyn['xp'];
  $lvlFloor = syn_xp_for_level($synLvl);
  $lvlNext  = syn_xp_for_level($synLvl + 1);
  $span     = max(1, $lvlNext - $lvlFloor);
  $into     = max(0, min($span, $synXp - $lvlFloor));
  $pct      = (int)round($into / $span * 100);
?>
<div class="panel" style="margin-top:0">
  <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:11px;color:var(--muted);margin-bottom:6px">
    <span>Progress to Level <?= $synLvl + 1 ?></span>
    <span><?= number_format($into) ?> / <?= number_format($span) ?> XP</span>
  </div>
  <div style="height:9px;border-radius:5px;background:rgba(255,255,255,.06);overflow:hidden">
    <div style="height:100%;width:<?= $pct ?>%;border-radius:5px;background:linear-gradient(90deg,#e8a33d,var(--accent));transition:width .4s"></div>
  </div>
</div>

<!-- Announcement -->
<?php if (!empty($mySyn['announcement'])): ?>
<div class="panel" style="border:1px solid rgba(255,45,149,.25);background:rgba(255,45,149,.04)">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
    <span style="font-size:16px">&#128204;</span>
    <div style="font-family:'Orbitron',sans-serif;font-size:11px;color:var(--neon2);letter-spacing:.5px">OVERSEER ANNOUNCEMENT</div>
    <?php if (!empty($mySyn['announcement_at'])): ?><span style="font-size:10px;color:var(--muted);margin-left:auto"><?= e(date('M j, g:ia',strtotime($mySyn['announcement_at']))) ?></span><?php endif; ?>
  </div>
  <p style="margin:0;font-size:13px;line-height:1.6"><?= nl2br(e($mySyn['announcement'])) ?></p>
</div>
<?php endif; ?>

<!-- Set announcement (leader/coleader) -->
<?php if (syn_can($myRank,'post_announce')): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">&#128204; Update Announcement</h3>
  <form method="post">
    <input type="hidden" name="action" value="set_announce">
    <textarea name="announcement" style="width:100%;min-height:70px;margin-bottom:8px" maxlength="500" placeholder="Post a message to your syndicate members..."><?= e($mySyn['announcement'] ?? '') ?></textarea>
    <button type="submit" style="font-size:12px">Update</button>
    <span class="muted" style="font-size:11px;margin-left:8px">Max 500 chars. Leave empty to clear.</span>
  </form>
</div>
<?php endif; ?>

<!-- Description -->
<?php if (!empty($mySyn['description'])): ?>
<div class="panel"><p style="margin:0;font-size:13px;color:var(--muted)"><?= e($mySyn['description']) ?></p></div>
<?php endif; ?>

<!-- Recent log -->
<?php if (!empty($recentLog)): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">&#128196; Recent Activity</h3>
  <?php foreach ($recentLog as $le): ?>
  <div class="syn-feedrow" style="font-size:12px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);color:var(--muted)">
    <?php if (!empty($le['actor_id'])): ?><a href="index.php?p=profile&id=<?= (int)$le['actor_id'] ?>" style="color:var(--accent);font-weight:700"><?= e($le['username'] ?? '?') ?></a><?php else: ?><span style="color:var(--accent)">System</span><?php endif; ?> — <?= e($le['detail'] ?? $le['action']) ?>
    <span style="float:right;font-size:10px"><?= e(date('M j g:ia',strtotime($le['created_at']))) ?></span>
  </div>
  <?php endforeach; ?>
  <?php if (syn_can($myRank,'view_log')): ?><a href="index.php?p=guilds&tab=log" style="display:block;font-size:11px;color:var(--accent);margin-top:8px">View full log &rarr;</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Leave -->
<?php if (!in_array($myRank, ['leader','coleader'], true)): ?>
<div class="panel" style="border:1px solid rgba(255,45,149,.15)">
  <h4 style="margin:0 0 10px;font-size:12px;color:var(--neon2)">Leave Syndicate</h4>
  <form method="post" onsubmit="return confirm('Leave your Syndicate? Any loaned items will be returned.')">
    <input type="hidden" name="action" value="leave">
    <textarea name="leave_message" maxlength="200" placeholder="Optional: reason for leaving..." style="width:100%;min-height:50px;font-size:12px;margin-bottom:8px"></textarea>
    <button type="submit" style="background:rgba(255,45,149,.08);border-color:rgba(255,45,149,.25);color:var(--neon2);font-size:12px">Leave Syndicate</button>
  </form>
</div>
<?php elseif (in_array($myRank, ['leader','coleader'], true)): ?>
<div class="panel" style="background:rgba(232,163,61,.04);border:1px solid rgba(232,163,61,.2)">
  <p style="font-size:12px;color:#e8a33d;margin:0">&#9888; Leaders and co-leaders must transfer or be demoted before leaving the Syndicate.</p>
</div>
<?php endif; ?>


<?php // ══ BOARD ══════════════════════════════════════════
elseif ($tab === 'board' && $mySyn): ?>

<?php if ($boardTid) {
  // ── Topic detail view ──────────────────────────────
  $topicPost = null;
  try {
    $tpq = $pdo->prepare('SELECT sp.*,p.username,p.role,p.chat_color FROM syndicate_posts sp JOIN players p ON p.id=sp.author_id WHERE sp.id=? AND sp.syndicate_id=? AND (sp.parent_id IS NULL OR sp.parent_id=0)');
    $tpq->execute([$boardTid, $mySyn['syndicate_id']]); $topicPost = $tpq->fetch() ?: null;
  } catch (Throwable $e) {}
  if (!$topicPost): ?>
  <div class="panel"><p class="muted">Topic not found. <a href="index.php?p=guilds&tab=board">Back to board</a></p></div>
  <?php else:
    $reps = [];
    try { $rq = $pdo->prepare('SELECT sp.*,p.username,p.role,p.chat_color FROM syndicate_posts sp JOIN players p ON p.id=sp.author_id WHERE sp.syndicate_id=? AND sp.parent_id=? ORDER BY sp.created_at ASC'); $rq->execute([$mySyn['syndicate_id'], $boardTid]); $reps = $rq->fetchAll(); } catch (Throwable $e) {}
    $tc = chat_color($topicPost['role'],'');
  ?>
  <div class="panel">
    <p class="muted" style="margin-top:0"><a href="index.php?p=guilds&tab=board">&laquo; Board</a></p>
    <h3 style="margin-top:0"><?= e($topicPost['title']) ?></h3>
    <div style="font-size:13px;line-height:1.7;white-space:pre-wrap;margin-bottom:10px"><?= bbcode($topicPost['body']) ?></div>
    <div style="font-size:11px;color:var(--muted)">
      <a href="index.php?p=profile&id=<?= (int)$topicPost['author_id'] ?>" style="color:<?= e($tc) ?>;font-weight:700"><?= e($topicPost['username']) ?></a>
      <?php if (role_label($topicPost['role']??'')): ?><em style="font-size:10px;color:<?= e(chat_color($topicPost['role'],'')) ?>"><?= e(role_label($topicPost['role'])) ?></em><?php endif; ?>
      &middot; <?= e(date('M j Y, g:ia', strtotime($topicPost['created_at']))) ?>
    </div>
  </div>

  <?php if (!empty($reps)): ?>
  <div class="panel" style="padding:0;overflow:hidden">
    <div style="padding:10px 14px;border-bottom:1px solid var(--line);font-size:11px;color:var(--muted)"><?= count($reps) ?> repl<?= count($reps)===1?'y':'ies' ?></div>
    <?php foreach ($reps as $rp):
      $rc = chat_color($rp['role'],''); ?>
    <div style="padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
      <div style="font-size:13px;line-height:1.6;white-space:pre-wrap;margin-bottom:6px"><?= bbcode($rp['body']) ?></div>
      <div style="font-size:11px;color:var(--muted)">
        <a href="index.php?p=profile&id=<?= (int)$rp['author_id'] ?>" style="color:<?= e($rc) ?>;font-weight:700"><?= e($rp['username']) ?></a>
        <?php if (role_label($rp['role']??'')): ?><em style="font-size:10px;color:<?= e(chat_color($rp['role'],'')) ?>"><?= e(role_label($rp['role'])) ?></em><?php endif; ?>
        &middot; <?= e(date('M j Y, g:ia', strtotime($rp['created_at']))) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="panel" id="replyform">
    <h4 style="margin-top:0">Post a Reply</h4>
    <form method="post">
      <input type="hidden" name="action" value="post">
      <input type="hidden" name="parent_id" value="<?= $boardTid ?>">
      <textarea name="post_body" style="width:100%;min-height:80px" placeholder="Write your reply..."></textarea>
      <button type="submit" style="margin-top:8px">Post Reply</button>
    </form>
  </div>
  <?php endif;

} else {
  // ── Topics list ────────────────────────────────────
  $topics = [];
  try {
    $tq = $pdo->prepare('SELECT sp.id, sp.title, sp.created_at, sp.author_id, p.username, p.role, p.chat_color,
      (SELECT COUNT(*) FROM syndicate_posts r WHERE r.parent_id=sp.id) AS replies,
      (SELECT MAX(r2.created_at) FROM syndicate_posts r2 WHERE r2.parent_id=sp.id) AS last_reply_at
      FROM syndicate_posts sp JOIN players p ON p.id=sp.author_id
      WHERE sp.syndicate_id=? AND (sp.parent_id IS NULL OR sp.parent_id=0)
      ORDER BY COALESCE((SELECT MAX(r3.created_at) FROM syndicate_posts r3 WHERE r3.parent_id=sp.id), sp.created_at) DESC
      LIMIT 50');
    $tq->execute([$mySyn['syndicate_id']]); $topics = $tq->fetchAll();
  } catch (Throwable $e) {}
?>

<?php if (empty($topics)): ?>
<div class="panel" style="color:var(--muted);text-align:center;padding:24px">No posts yet. Start a discussion below.</div>
<?php else: ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px"><?= count($topics) ?> discussion<?= count($topics)!==1?'s':'' ?></div>
  <?php foreach ($topics as $t):
    $tc = chat_color($t['role'], '');
    $lastAt = $t['last_reply_at'] ?: $t['created_at'];
  ?>
  <a href="index.php?p=guilds&tab=board&tid=<?= (int)$t['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.04);text-decoration:none;color:var(--text)">
    <div style="width:8px;height:8px;border-radius:50%;background:var(--accent);flex:none;margin-top:2px"></div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($t['title']) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px">by <span style="color:<?= e($tc) ?>"><?= e($t['username']) ?></span></div>
    </div>
    <div style="text-align:right;flex:none">
      <div style="font-size:13px;font-weight:700"><?= (int)$t['replies'] ?> <span style="font-size:10px;color:var(--muted);font-weight:400">repl<?= $t['replies']==1?'y':'ies' ?></span></div>
      <div style="font-size:10px;color:var(--muted)"><?= e(date('M j, Y', strtotime($lastAt))) ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel" style="border:1px solid rgba(25,240,199,.15)">
  <h3 style="margin-top:0;font-size:13px;color:var(--accent)">&#43; New Discussion</h3>
  <form method="post">
    <input type="hidden" name="action" value="post">
    <div class="field"><span>Title</span><input type="text" name="post_title" maxlength="120" placeholder="Discussion title..."></div>
    <div class="field" style="margin-top:8px"><span>Body</span><textarea name="post_body" style="min-height:80px" placeholder="What's on your mind?"></textarea></div>
    <button type="submit" style="margin-top:8px">Post</button>
  </form>
</div>
<?php } // end topics list ?>


<?php // ══ CHAT ═════════════════════════════════════════
elseif ($tab === 'chat' && $mySyn):
  $synChatRoom = 'syn_' . (int)$mySyn['syndicate_id'];
  $synChatRows = [];
  try {
    $scq = $pdo->prepare('SELECT c.body, c.created_at, p.id AS uid, p.username, p.role, p.chat_color FROM chat_messages c JOIN players p ON p.id=c.player_id WHERE c.room=? ORDER BY c.id DESC LIMIT 100');
    $scq->execute([$synChatRoom]); $synChatRows = array_reverse($scq->fetchAll());
  } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div id="syn-chatroom" class="chatroom-full">
    <?php if ($synChatRows): foreach ($synChatRows as $r):
      $col     = chat_color($r['role'], '');
      $textCol = chat_color($r['role'], $r['chat_color']);
    ?>
      <div class="chatline-full">
        <span class="chattime-full"><?= e(date('H:i', strtotime($r['created_at']))) ?></span>
        <div class="chatline-body">
          <a href="index.php?p=profile&id=<?= (int)$r['uid'] ?>" style="color:<?= e($col) ?>;font-weight:700"><?= e($r['username']) ?></a><span style="color:var(--muted)">:</span>
          <span style="color:<?= e($textCol) ?>"><?= bbcode($r['body']) ?></span>
        </div>
      </div>
    <?php endforeach; else: ?>
      <div style="text-align:center;padding:32px;color:var(--muted)">
        <div style="font-size:28px;margin-bottom:8px">&#128172;</div>
        <div>No messages yet. Start the conversation.</div>
      </div>
    <?php endif; ?>
  </div>
  <div style="border-top:1px solid var(--line);padding:10px 14px;background:var(--panel2)">
    <form id="syn-chatform" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" id="syn-chat-room" value="<?= e($synChatRoom) ?>">
      <input type="text" id="syn-chatinput" maxlength="240" autocomplete="off" placeholder="Message syndicate..." style="flex:1">
      <button type="submit" style="flex:none;padding:8px 16px">Send</button>
    </form>
  </div>
</div>
<script>
(function(){
  var room=document.getElementById('syn-chatroom'),
      form=document.getElementById('syn-chatform'),
      inp=document.getElementById('syn-chatinput'),
      rkEl=document.getElementById('syn-chat-room');
  if(!room||!form||!rkEl) return;
  var rk=rkEl.value;
  function render(msgs){
    if(!msgs||!msgs.length) return;
    room.innerHTML='';
    msgs.forEach(function(m){
      var line=document.createElement('div'); line.className='chatline-full';
      var t=document.createElement('span'); t.className='chattime-full'; t.textContent=m.time;
      var body=document.createElement('div'); body.className='chatline-body';
      var who=document.createElement('a'); who.href='index.php?p=profile&id='+m.id;
      who.style.color=m.name_color||'#c9d1e0'; who.style.fontWeight='700';
      who.textContent=m.username; // textContent, not innerHTML — don't trust the username shape
      var sep=document.createElement('span'); sep.style.color='var(--muted)'; sep.textContent=': ';
      var txt=document.createElement('span'); txt.style.color=m.color||'#c9d1e0'; txt.innerHTML=m.html; // server-sanitized bbcode
      body.appendChild(who); body.appendChild(sep); body.appendChild(txt);
      line.appendChild(t); line.appendChild(body); room.appendChild(line);
    });
    room.scrollTop=room.scrollHeight;
  }
  function load(){
    if(!document.body.contains(room)){ if(window.__synChatInterval){clearInterval(window.__synChatInterval);window.__synChatInterval=null;} return; }
    fetch('chat_api.php?action=list&n=100&room='+encodeURIComponent(rk),{credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(d){ if(d&&d.messages) render(d.messages); }).catch(function(){});
  }
  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    var t=inp.value.trim(); if(!t) return;
    var fd=new FormData(); fd.append('action','say'); fd.append('body',t); fd.append('room',rk);
    fetch('chat_api.php',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(){ inp.value=''; load(); }).catch(function(){});
  });
  room.scrollTop=room.scrollHeight; load();
  if(window.__synChatInterval) clearInterval(window.__synChatInterval);
  window.__synChatInterval=setInterval(load,4000);
})();
</script>


<?php // ══ MEMBERS ════════════════════════════════════════
elseif ($tab === 'members' && $mySyn):
  $memSort = in_array($_GET['msort'] ?? '', ['level','joined','name']) ? $_GET['msort'] : 'rank';
  $memOrder = match($memSort) {
    'level'  => 'p.level DESC, p.username ASC',
    'joined' => 'sm.joined_at ASC, p.username ASC',
    'name'   => 'p.username ASC',
    default  => 'FIELD(sm.rank,"leader","coleader","treasurer","armourer","librarian","advisor","member"), p.username ASC',
  };
  $members = [];
  try { $mq = $pdo->prepare("SELECT sm.player_id,sm.rank,sm.joined_at,p.username,p.level,p.role,p.chat_color FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY {$memOrder}"); $mq->execute([$mySyn['syndicate_id']]); $members = $mq->fetchAll(); } catch (Throwable $e) {}
  $canManage = syn_can($myRank,'manage_members');
  // Pending applications
  $apps = [];
  if ($canManage) { try { $aq = $pdo->prepare("SELECT sa.*,p.username,p.level FROM syndicate_applications sa JOIN players p ON p.id=sa.player_id WHERE sa.syndicate_id=? AND sa.status='pending' ORDER BY sa.created_at ASC"); $aq->execute([$mySyn['syndicate_id']]); $apps = $aq->fetchAll(); } catch (Throwable $e) {} }
?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;align-items:center">
  <span style="font-size:11px;color:var(--muted)">Sort:</span>
  <?php foreach (['rank'=>'Rank','level'=>'Level','joined'=>'Oldest','name'=>'Name'] as $sk=>$sl): ?>
  <a href="index.php?p=guilds&tab=members&msort=<?= $sk ?>" style="padding:4px 10px;border-radius:5px;font-size:11px;text-decoration:none;border:1px solid <?= $memSort===$sk?'var(--accent)':'var(--line)' ?>;color:<?= $memSort===$sk?'var(--accent)':'var(--muted)' ?>;background:<?= $memSort===$sk?'rgba(25,240,199,.08)':'var(--panel2)' ?>"><?= $sl ?></a>
  <?php endforeach; ?>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)"><?= count($members) ?> member<?= count($members)!==1?'s':'' ?></div>
  <?php foreach ($members as $m):
    $mc = chat_color($m['role'],'');
    $rc = $SYN_RANK_COLORS[$m['rank']] ?? 'var(--muted)';
    $rl = $SYN_RANK_LABELS[$m['rank']] ?? ucfirst($m['rank']);
    $joinStr = !empty($m['joined_at']) ? date('M j Y', strtotime($m['joined_at'])) : '';
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);flex-wrap:wrap">
    <div style="width:30px;height:30px;border-radius:7px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.12);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent);flex:none"><?= mb_strtoupper(mb_substr($m['username'],0,1)) ?></div>
    <div style="flex:1;min-width:100px">
      <a href="index.php?p=profile&id=<?= (int)$m['player_id'] ?>" style="font-weight:700;color:<?= e($mc) ?>"><?= e($m['username']) ?></a>
      <span style="font-size:10px;color:var(--muted)"> Lv<?= (int)$m['level'] ?></span>
      <?php if ($joinStr): ?><span style="font-size:10px;color:var(--muted);margin-left:6px">Joined <?= $joinStr ?></span><?php endif; ?>
    </div>
    <em style="font-size:11px;font-weight:700;color:<?= $rc ?>;font-style:italic"><?= e($rl) ?></em>
    <?php if ($canManage && (int)$m['player_id'] !== $pid && $m['rank'] !== 'leader'): ?>
    <div style="display:flex;gap:4px;align-items:center;flex:none;flex-shrink:0">
      <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
        <input type="hidden" name="action" value="setrole">
        <input type="hidden" name="target_id" value="<?= (int)$m['player_id'] ?>">
        <select name="new_rank" style="font-size:11px;padding:3px 6px;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:4px;height:28px">
          <?php foreach (array_diff($SYN_RANKS,['leader']) as $rk): ?><option value="<?= $rk ?>" <?= $m['rank']===$rk?'selected':'' ?>><?= $SYN_RANK_LABELS[$rk]??ucfirst($rk) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" style="font-size:11px;padding:4px 10px;height:28px;line-height:1">Set</button>
      </form>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="kick">
        <input type="hidden" name="target_id" value="<?= (int)$m['player_id'] ?>">
        <button type="submit" style="font-size:11px;padding:4px 10px;height:28px;line-height:1;background:rgba(226,59,59,.1);border-color:rgba(226,59,59,.3);color:#e23b3b" onclick="return confirm('Kick <?= e($m['username']) ?>?')">Kick</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pending Applications -->
<?php if ($canManage && !empty($apps)): ?>
<div class="panel" style="border:1px solid rgba(25,240,199,.2);padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700;color:var(--accent)">&#128235; Pending Applications (<?= count($apps) ?>)</div>
  <?php foreach ($apps as $app): ?>
  <div style="padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:160px">
      <div><a href="index.php?p=profile&id=<?= (int)$app['player_id'] ?>" style="font-weight:700;color:var(--accent)"><?= e($app['username']) ?></a> <span class="muted" style="font-size:11px">Lv<?= (int)$app['level'] ?></span></div>
      <?php if ($app['message']): ?><div style="font-size:12px;color:var(--muted);margin-top:3px;font-style:italic">"<?= e($app['message']) ?>"</div><?php endif; ?>
      <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= e(date('M j, Y g:ia', strtotime($app['created_at']))) ?></div>
    </div>
    <div style="display:flex;gap:6px">
      <form method="post" style="margin:0"><input type="hidden" name="action" value="approve_app"><input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>"><button type="submit" style="font-size:11px;padding:4px 12px;background:rgba(59,207,99,.08);border-color:rgba(59,207,99,.3);color:#3bcf63">Accept</button></form>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="deny_app"><input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>"><button type="submit" style="font-size:11px;padding:4px 12px;background:rgba(255,45,149,.08);border-color:rgba(255,45,149,.25);color:var(--neon2)">Deny</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<?php // ══ STAFF PAGE ═════════════════════════════════════
elseif ($tab === 'staff' && $mySyn):
  $staffByRank = [];
  try {
    $sfq = $pdo->prepare("SELECT sm.rank,sm.player_id,p.username,p.level FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? AND sm.rank != 'member' ORDER BY FIELD(sm.rank,'leader','coleader','treasurer','armourer','librarian','advisor')");
    $sfq->execute([$mySyn['syndicate_id']]);
    foreach ($sfq->fetchAll() as $sfm) $staffByRank[$sfm['rank']][] = $sfm;
  } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700;color:var(--neon2)">&#128737; Syndicate Officers</div>
  <?php foreach (['leader','coleader','treasurer','armourer','librarian','advisor'] as $ork):
    $holders = $staffByRank[$ork] ?? [];
    $rc = $SYN_RANK_COLORS[$ork] ?? 'var(--muted)';
    $rl = $SYN_RANK_LABELS[$ork] ?? ucfirst($ork);
  ?>
  <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="width:100px;flex:none;font-size:11px;font-weight:700;font-style:italic;color:<?= $rc ?>"><?= e($rl) ?></div>
    <?php if (empty($holders)): ?>
      <span style="font-size:12px;color:var(--muted);font-style:italic">Vacant</span>
    <?php else: foreach ($holders as $ho): ?>
      <a href="index.php?p=profile&id=<?= (int)$ho['player_id'] ?>" style="font-weight:700;font-size:13px;color:<?= e($rc) ?>"><?= e($ho['username']) ?></a>
      <span style="font-size:11px;color:var(--muted)">Lv<?= (int)$ho['level'] ?></span>
    <?php endforeach; endif; ?>
  </div>
  <?php endforeach; ?>
</div>


<?php // ══ STOCKPILE ══════════════════════════════════════
elseif ($tab === 'stockpile' && $mySyn):
  // General items only (gear_type='item')
  $stockpile = [];
  try { $sq = $pdo->prepare("SELECT sp.*,p.username AS added_by_name FROM syndicate_stockpile sp LEFT JOIN players p ON p.id=sp.added_by WHERE sp.syndicate_id=? AND sp.gear_type='item' ORDER BY sp.item_name"); $sq->execute([$mySyn['syndicate_id']]); $stockpile = $sq->fetchAll(); } catch (Throwable $e) {}
  $stockpileGrouped = syn_group_stock($stockpile);
  $activeLoans = [];
  try { $lq = $pdo->prepare("SELECT sl.*,sp.item_name,pl.username AS borrower_name FROM syndicate_loans sl JOIN syndicate_stockpile sp ON sp.id=sl.stockpile_id JOIN players pl ON pl.id=sl.player_id WHERE sl.syndicate_id=? AND sl.returned_at IS NULL AND sp.gear_type='item' ORDER BY sl.loaned_at DESC"); $lq->execute([$mySyn['syndicate_id']]); $activeLoans = $lq->fetchAll(); } catch (Throwable $e) {}
  $canManageStock = syn_can($myRank,'manage_stockpile');
  $canGiveStock = syn_can($myRank,'give_stockpile');
  $canLoan = syn_can($myRank,'loan_items'); // still needed below for the legacy Return button
  // Player inventory — exclude weapons/armor (those go to Armoury)
  $playerInvStock = [];
  try { $piq = $pdo->prepare("SELECT pi.item_id,pi.qty,i.name,i.category FROM player_items pi JOIN items i ON i.id=pi.item_id WHERE pi.player_id=? AND pi.qty>0 AND (i.slot IS NULL OR i.slot NOT IN ('weapon','armor')) ORDER BY i.category,i.name"); $piq->execute([$pid]); $playerInvStock = $piq->fetchAll(); } catch (Throwable $e) {}
  $synMembers = [];
  if ($canGiveStock) { try { $mq = $pdo->prepare('SELECT sm.player_id,p.username FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY p.username'); $mq->execute([$mySyn['syndicate_id']]); $synMembers = $mq->fetchAll(); } catch (Throwable $e) {} }
?>

<!-- Add to stockpile -->
<?php if ($canManageStock): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">&#9874; Donate to Stockpile</h3>
  <p class="muted" style="font-size:12px;margin-top:-4px">General items only. Weapons &amp; armor go to the <a href="index.php?p=guilds&tab=armoury" style="color:var(--accent)">Armoury &rarr;</a></p>
  <?php if (empty($playerInvStock)): ?>
  <p class="muted" style="font-size:12px">No donatable items in inventory.</p>
  <?php else: ?>
  <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
    <input type="hidden" name="action" value="stockpile_add">
    <div class="field" style="flex:1;min-width:180px"><span>Item</span>
      <select name="donate_item_id">
        <option value="">-- Select from inventory --</option>
        <?php foreach ($playerInvStock as $pi): ?><option value="<?= (int)$pi['item_id'] ?>"><?= e($pi['name']) ?> &times;<?= (int)$pi['qty'] ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="width:80px"><span>Qty</span><input type="number" name="donate_qty" value="1" min="1" max="99"></div>
    <div class="field" style="flex:1;min-width:160px"><span>Notes</span><input type="text" name="notes" maxlength="200" placeholder="Optional notes" data-no-counter></div>
    <div><button type="submit" style="font-size:12px">Donate</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stockpile list -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:13px;font-weight:700">&#9874; General Stockpile</div>
    <span class="muted" style="font-size:11px"><?= count($stockpile) ?> items</span>
  </div>
  <?php if (empty($stockpile)): ?>
  <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">No items in stockpile yet.</div>
  <?php else: foreach ($stockpileGrouped as $si): ?>
  <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:120px">
      <div style="font-weight:700;font-size:13px"><?= e($si['item_name']) ?><?php if ($si['_count'] > 1): ?> <span class="muted" style="font-weight:400">&times;<?= (int)$si['_count'] ?></span><?php endif; ?></div>
      <?php if ($si['notes']): ?><div style="font-size:11px;color:var(--muted)"><?= e($si['notes']) ?></div><?php endif; ?>
    </div>
    <span style="font-size:11px;font-weight:700;color:<?= $si['available']?'#3bcf63':'#e8a33d' ?>"><?= $si['available']?'Available':'On Loan' ?></span>
    <?php if ($canGiveStock && $si['available'] && !empty($synMembers)): ?>
    <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
      <input type="hidden" name="action" value="stockpile_give">
      <input type="hidden" name="item_id" value="<?= (int)$si['id'] ?>">
      <select name="recipient_id" style="font-size:11px;padding:2px 6px;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:4px">
        <?php foreach ($synMembers as $sm): ?><option value="<?= (int)$sm['player_id'] ?>"><?= e($sm['username']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" style="font-size:10px;padding:3px 10px;background:rgba(59,207,99,.1);border:1px solid rgba(59,207,99,.3);color:#3bcf63;border-radius:4px;cursor:pointer" onclick="return confirm('Give one &quot;<?= e($si['item_name']) ?>&quot; to the selected member?')">&#127873; Give</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Active loans (legacy only — general items can no longer be loaned, only returned) -->
<?php if (!empty($activeLoans)): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700">Active Loans</div>
  <?php foreach ($activeLoans as $ln): ?>
  <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:120px">
      <div style="font-weight:700;font-size:13px"><?= e($ln['item_name']) ?></div>
      <div style="font-size:11px;color:var(--muted)">Loaned to <b style="color:var(--accent)"><?= e($ln['borrower_name']) ?></b> &middot; <?= e(date('M j',strtotime($ln['loaned_at']))) ?></div>
    </div>
    <?php if ($canLoan || (int)$ln['player_id']===$pid): ?>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="return_item"><input type="hidden" name="loan_id" value="<?= (int)$ln['id'] ?>"><button type="submit" style="font-size:11px;padding:4px 12px" onclick="return confirm('Return this item to stockpile?')">&#9100; Return</button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<?php // ══ ARMOURY ════════════════════════════════════════
elseif ($tab === 'armoury' && $mySyn):
  $armoury = [];
  try { $sq = $pdo->prepare("SELECT sp.*,p.username AS added_by_name FROM syndicate_stockpile sp LEFT JOIN players p ON p.id=sp.added_by WHERE sp.syndicate_id=? AND sp.gear_type IN ('weapon','armor') ORDER BY sp.gear_type,sp.item_name"); $sq->execute([$mySyn['syndicate_id']]); $armoury = $sq->fetchAll(); } catch (Throwable $e) {}
  $armouryGrouped = syn_group_stock($armoury, ['gear_type','atk_bonus','def_bonus']);
  $armouryLoans = [];
  try { $lq = $pdo->prepare("SELECT sl.*,sp.item_name,sp.gear_type,sp.atk_bonus,sp.def_bonus,pl.username AS borrower_name FROM syndicate_loans sl JOIN syndicate_stockpile sp ON sp.id=sl.stockpile_id JOIN players pl ON pl.id=sl.player_id WHERE sl.syndicate_id=? AND sl.returned_at IS NULL AND sp.gear_type IN ('weapon','armor') ORDER BY sl.loaned_at DESC"); $lq->execute([$mySyn['syndicate_id']]); $armouryLoans = $lq->fetchAll(); } catch (Throwable $e) {}
  $canManageStock = syn_can($myRank,'manage_stockpile');
  $canLoan = syn_can($myRank,'loan_items');
  $synMembers = [];
  if ($canLoan) { try { $mq = $pdo->prepare('SELECT sm.player_id,p.username FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY p.username'); $mq->execute([$mySyn['syndicate_id']]); $synMembers = $mq->fetchAll(); } catch (Throwable $e) {} }
  // Player inventory — weapons and armor only
  $playerArmouryInv = [];
  try { $piq = $pdo->prepare("SELECT pi.item_id,pi.qty,i.name,i.slot,i.atk,i.def FROM player_items pi JOIN items i ON i.id=pi.item_id WHERE pi.player_id=? AND pi.qty>0 AND i.slot IN ('weapon','armor') ORDER BY i.slot,i.name"); $piq->execute([$pid]); $playerArmouryInv = $piq->fetchAll(); } catch (Throwable $e) {}
?>


<!-- Donate weapon/armor -->
<?php if ($canManageStock): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">&#9876; Donate to Armoury</h3>
  <p class="muted" style="font-size:12px;margin-top:-4px">Weapons and armor from your inventory. Stats are stored automatically.</p>
  <?php if (empty($playerArmouryInv)): ?>
  <p class="muted" style="font-size:12px">No weapons or armor in your inventory.</p>
  <?php else: ?>
  <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
    <input type="hidden" name="action" value="stockpile_add">
    <div class="field" style="flex:1;min-width:180px"><span>Item</span>
      <select name="donate_item_id">
        <option value="">-- Select from inventory --</option>
        <?php foreach ($playerArmouryInv as $pi):
          $bonus = $pi['slot']==='weapon' ? '+'.((int)$pi['atk']).' ATK' : '+'.((int)$pi['def']).' DEF';
        ?><option value="<?= (int)$pi['item_id'] ?>"><?= e($pi['name']) ?> (<?= $bonus ?>) &times;<?= (int)$pi['qty'] ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="width:80px"><span>Qty</span><input type="number" name="donate_qty" value="1" min="1" max="99"></div>
    <div class="field" style="flex:1;min-width:160px"><span>Notes</span><input type="text" name="notes" maxlength="200" placeholder="Optional notes" data-no-counter></div>
    <div><button type="submit" style="font-size:12px">Donate</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Armoury list -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:13px;font-weight:700">&#9876; Armoury Inventory</div>
    <span class="muted" style="font-size:11px"><?= count($armoury) ?> items</span>
  </div>
  <?php if (empty($armoury)): ?>
  <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">No weapons or armor stored yet.</div>
  <?php else:
    $lastType = null;
    foreach ($armouryGrouped as $si):
      if ($si['gear_type'] !== $lastType):
        $lastType = $si['gear_type'];
        $typeLabel = $lastType === 'weapon' ? '&#9876; Weapons' : '&#128737; Armor';
  ?>
  <div style="padding:6px 14px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);background:rgba(0,0,0,.2)"><?= $typeLabel ?></div>
  <?php endif; ?>
  <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:120px">
      <div style="font-weight:700;font-size:13px"><?= e($si['item_name']) ?><?php if ($si['_count'] > 1): ?> <span class="muted" style="font-weight:400">&times;<?= (int)$si['_count'] ?></span><?php endif; ?></div>
      <div style="font-size:11px;color:var(--muted)">
        <?php if ($si['gear_type']==='weapon' && $si['atk_bonus']>0): ?><span style="color:var(--neon2)">+<?= (int)$si['atk_bonus'] ?> ATK</span><?php endif; ?>
        <?php if ($si['gear_type']==='armor'  && $si['def_bonus']>0): ?><span style="color:var(--accent)">+<?= (int)$si['def_bonus'] ?> DEF</span><?php endif; ?>
        <?php if ($si['notes']): ?> &middot; <?= e($si['notes']) ?><?php endif; ?>
      </div>
    </div>
    <span style="font-size:11px;font-weight:700;color:<?= $si['available']?'#3bcf63':'#e8a33d' ?>"><?= $si['available']?'Available':'On Loan' ?></span>
    <?php if ($canLoan && $si['available'] && !empty($synMembers)): ?>
    <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
      <input type="hidden" name="action" value="loan_item">
      <input type="hidden" name="item_id" value="<?= (int)$si['id'] ?>">
      <select name="borrower_id" style="font-size:11px;padding:2px 6px;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:4px">
        <?php foreach ($synMembers as $sm): ?><option value="<?= (int)$sm['player_id'] ?>"><?= e($sm['username']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" style="font-size:10px;padding:3px 8px">Loan</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Active armoury loans -->
<?php if (!empty($armouryLoans)): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700">Active Loans</div>
  <?php foreach ($armouryLoans as $ln): ?>
  <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:120px">
      <div style="font-weight:700;font-size:13px"><?= e($ln['item_name']) ?>
        <?php if ($ln['atk_bonus']>0): ?><span style="font-size:11px;color:var(--neon2);font-weight:400"> +<?= (int)$ln['atk_bonus'] ?> ATK</span><?php endif; ?>
        <?php if ($ln['def_bonus']>0): ?><span style="font-size:11px;color:var(--accent);font-weight:400"> +<?= (int)$ln['def_bonus'] ?> DEF</span><?php endif; ?>
      </div>
      <div style="font-size:11px;color:var(--muted)">Loaned to <b style="color:var(--accent)"><?= e($ln['borrower_name']) ?></b> &middot; <?= e(date('M j',strtotime($ln['loaned_at']))) ?></div>
    </div>
    <?php if ($canLoan || (int)$ln['player_id']===$pid):
      $isSelfReturn = (int)$ln['player_id'] === $pid;
    ?>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="return_item"><input type="hidden" name="loan_id" value="<?= (int)$ln['id'] ?>"><button type="submit" style="font-size:11px;padding:4px 12px" onclick="return confirm('<?= $isSelfReturn ? 'Return this item to the Armoury?' : 'Recall this item from '.e($ln['borrower_name']).'?' ?>')"><?= $isSelfReturn ? '&#9100; Return' : '&#8634; Recall' ?></button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<?php // ══ TREASURY ═══════════════════════════════════════
elseif ($tab === 'treasury' && $mySyn): ?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <h3 style="margin:0;font-size:13px">&#128178; Syndicate Treasury</h3>
    <span style="font-size:15px;color:#3bcf63;font-weight:700;font-family:'Orbitron',sans-serif"><?= number_format((int)$mySyn['bank']) ?> <span style="font-size:11px;font-family:inherit;color:var(--muted)">cr</span></span>
  </div>
  <p style="font-size:12px;color:var(--muted);margin:0 0 14px">Donate pocket credits to the guild fund. Donations earn XP for the Syndicate.</p>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="action" value="donate">
    <div class="field" style="flex:1;min-width:160px;margin:0">
      <span>Amount <span class="muted" style="font-size:11px">Pocket: <?= number_format((int)$player['creds_pocket']) ?> cr</span></span>
      <div class="num-wrap"><input type="number" name="amount" id="donAmt" min="1" max="<?= (int)$player['creds_pocket'] ?>" placeholder="0"><button type="button" class="fill-max" onclick="document.getElementById('donAmt').value=<?= (int)$player['creds_pocket'] ?>">Max</button></div>
    </div>
    <button type="submit" style="font-size:12px" <?= (int)$player['creds_pocket']<1?'disabled':'' ?>>Donate</button>
  </form>
</div>

<?php $xpRate = guild_xp_donate_rate($pdo, $pid); ?>
<div class="panel" style="border:1px solid rgba(232,163,61,.18)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:6px">
    <h4 style="margin:0;font-size:12px;color:#e8a33d">&#9876; Battle-XP Tithe</h4>
    <span style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:#e8a33d"><span id="xpRateOut"><?= $xpRate ?></span>%</span>
  </div>
  <p style="font-size:12px;color:var(--muted);margin:0 0 12px">Pledge a share of the XP you earn from combat (PvP &amp; the Sim) to the Syndicate. The pledged XP feeds guild levels; you keep the rest. Set it to 0% to keep all your XP.</p>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="action" value="xp_donate">
    <input type="range" name="rate" id="xpRate" min="0" max="100" step="5" value="<?= $xpRate ?>"
           oninput="document.getElementById('xpRateOut').textContent=this.value;document.getElementById('xpRateNum').value=this.value"
           style="flex:1;min-width:160px;accent-color:#e8a33d">
    <input type="number" id="xpRateNum" min="0" max="100" value="<?= $xpRate ?>"
           oninput="var v=Math.max(0,Math.min(100,this.value|0));document.getElementById('xpRate').value=v;document.getElementById('xpRateOut').textContent=v;this.form.rate.value=v"
           style="width:64px;text-align:center">
    <button type="submit" style="font-size:12px">Save</button>
  </form>
</div>

<?php if (syn_can($myRank,'manage_bank') && (int)$mySyn['bank'] > 0): ?>
<div class="panel" style="border:1px solid rgba(59,207,99,.15)">
  <h4 style="margin:0 0 10px;font-size:12px;color:#3bcf63">&#128274; Vault Keeper — Withdraw</h4>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="action" value="bank_withdraw">
    <div class="field" style="flex:1;min-width:160px;margin:0">
      <span>Withdraw <span class="muted" style="font-size:11px">Balance: <?= number_format((int)$mySyn['bank']) ?> cr</span></span>
      <div class="num-wrap"><input type="number" name="amount" id="wdAmt" min="1" max="<?= (int)$mySyn['bank'] ?>" placeholder="0"><button type="button" class="fill-max" onclick="document.getElementById('wdAmt').value=<?= (int)$mySyn['bank'] ?>">Max</button></div>
    </div>
    <button type="submit" style="font-size:12px;background:rgba(255,45,149,.06);border-color:rgba(255,45,149,.25);color:var(--neon2)">Withdraw</button>
  </form>
</div>
<?php endif; ?>


<?php // ══ LOG ════════════════════════════════════════════
elseif ($tab === 'log' && $mySyn && (syn_can($myRank,'view_log') || $isAdmin)):
  $logs = [];
  try { $lq = $pdo->prepare('SELECT sl.*,a.username AS actor_name,a.role AS actor_role,pp.username AS subject_name FROM syndicate_log sl LEFT JOIN players a ON a.id=sl.actor_id LEFT JOIN players pp ON pp.id=sl.player_id WHERE sl.syndicate_id=? ORDER BY sl.id DESC LIMIT 100'); $lq->execute([$mySyn['syndicate_id']]); $logs = $lq->fetchAll(); } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)">Last <?= count($logs) ?> events</div>
  <?php if (empty($logs)): ?>
  <div style="padding:20px;text-align:center;color:var(--muted)">No activity logged yet.</div>
  <?php else: foreach ($logs as $le):
    $logIcons = ['joined'=>'&#128101;','left'=>'&#128463;','kicked'=>'&#128245;','role_change'=>'&#9733;','donated'=>'&#128178;','withdrew'=>'&#128198;','founded'=>'&#9760;','loan_out'=>'&#9874;','returned'=>'&#9100;','announcement'=>'&#128204;','stockpile_add'=>'&#43;','stockpile_remove'=>'&#8722;','stockpile_give'=>'&#127873;'];
    $licon = $logIcons[$le['action']] ?? '&#8226;';
  ?>
  <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
    <span style="font-size:14px;flex:none;color:var(--accent)"><?= $licon ?></span>
    <div style="flex:1;min-width:0;font-size:12px">
      <?php if ($le['actor_name']): $actorCol = chat_color($le['actor_role'] ?? '', ''); ?><a href="index.php?p=profile&id=<?= (int)$le['actor_id'] ?>" style="color:<?= e($actorCol) ?>;font-weight:700"><?= e($le['actor_name']) ?></a> <?php endif; ?>
      <?= e($le['detail'] ?: $le['action']) ?>
      <?php if (in_array($le['action'], ['loan_out','stockpile_give'], true) && $le['subject_name']): ?>
        to <a href="index.php?p=profile&id=<?= (int)$le['player_id'] ?>" style="font-weight:700"><?= e($le['subject_name']) ?></a>
      <?php elseif ($le['action'] === 'returned' && $le['subject_name'] && (int)$le['player_id'] !== (int)$le['actor_id']): ?>
        &mdash; recalled from <a href="index.php?p=profile&id=<?= (int)$le['player_id'] ?>" style="font-weight:700"><?= e($le['subject_name']) ?></a>
      <?php endif; ?>
    </div>
    <span style="font-size:10px;color:var(--muted);flex:none;white-space:nowrap"><?= e(date('M j g:ia',strtotime($le['created_at']))) ?></span>
  </div>
  <?php endforeach; endif; ?>
</div>


<?php // ══ SEARCH ═════════════════════════════════════════
elseif ($tab === 'search'):
  $allSyns = [];
  try { $allSyns = $pdo->query("SELECT s.*,(SELECT COUNT(*) FROM syndicate_members sm WHERE sm.syndicate_id=s.id) AS members,p.username AS leader_name FROM syndicates s JOIN players p ON p.id=s.leader_id ORDER BY s.level DESC,s.xp DESC LIMIT 50")->fetchAll(); } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($allSyns)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No Syndicates yet. Be the first to form one.</div>
  <?php else: ?>
  <div style="padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px"><?= count($allSyns) ?> Syndicates</div>
  <?php foreach ($allSyns as $s):
    $isMem = $mySyn && (int)$mySyn['syndicate_id']===(int)$s['id'];
  ?>
  <div style="padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <span style="font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:var(--neon2)">[<?= e($s['tag']) ?>]</span>
        <span style="font-size:14px;font-weight:700;color:var(--text);margin-left:6px"><?= e($s['name']) ?></span>
        <span style="font-size:11px;color:var(--muted);margin-left:8px">Lv <?= (int)$s['level'] ?> &middot; <?= (int)$s['members'] ?> members &middot; Led by <?= e($s['leader_name']) ?></span>
        <?php if (!empty($s['description'])): ?>
        <div style="font-size:12px;color:var(--muted);margin-top:4px"><?= e(mb_substr($s['description'],0,120)) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
        <div style="display:flex;gap:8px;align-items:center">
          <a href="index.php?p=guilds&tab=view&sid=<?= (int)$s['id'] ?>" class="syn-pill" style="padding:5px 12px;font-size:11px">&#128065; View</a>
          <?php if ($isMem): ?><span style="font-size:11px;color:var(--accent)">&#10003; Your Syndicate</span><?php endif; ?>
        </div>
      <?php if (!$mySyn && !$isMem):
        // Check for existing pending application
        $hasApp = false;
        try { $ap = $pdo->prepare("SELECT id FROM syndicate_applications WHERE syndicate_id=? AND player_id=? AND status='pending'"); $ap->execute([$s['id'], $pid]); $hasApp = (bool)$ap->fetchColumn(); } catch (Throwable $e) {}
      ?>
      <?php if ($hasApp): ?>
        <span style="font-size:11px;color:#e8a33d">&#9679; Application pending</span>
      <?php else: ?>
        <button type="button" onclick="this.nextElementSibling.style.display='block';this.style.display='none'" style="font-size:12px;padding:6px 16px">Apply</button>
        <div style="display:none;margin-top:8px">
          <form method="post" style="display:flex;flex-direction:column;gap:6px">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="syn_id" value="<?= (int)$s['id'] ?>">
            <textarea name="app_message" maxlength="400" placeholder="Optional message to the leaders..." style="font-size:12px;min-height:50px"></textarea>
            <button type="submit" style="font-size:12px;padding:5px 14px">Send Application</button>
          </form>
        </div>
      <?php endif; ?>
      <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php if (!$mySyn): ?><div style="margin-top:8px;text-align:center"><a href="index.php?p=guilds&tab=create" style="font-size:12px;color:var(--accent)">&#43; Create your own Syndicate</a></div><?php endif; ?>


<?php // ══ CREATE ═════════════════════════════════════════
elseif ($tab === 'create'): ?>
<div class="panel">
  <?php if ($mySyn): ?>
    <p style="color:var(--neon2)">You already belong to a Syndicate. Leave it first.</p>
  <?php else: ?>
  <h3 style="margin-top:0">Form a Syndicate</h3>
  <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:6px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:14px">
    &#9670; Costs <b style="color:var(--neon2)"><?= SYNDICATE_CREATE_COST ?> Shards</b> to create. You have <?= number_format($player['shards'] ?? 0) ?> Shards.
  </div>
  <form method="post">
    <input type="hidden" name="action" value="create">
    <div class="field"><span>Syndicate Name <span class="muted">(3–60 chars)</span></span><input type="text" name="syn_name" maxlength="60" placeholder="e.g. Iron Veil"></div>
    <div class="field" style="margin-top:8px"><span>Tag <span class="muted">(2–8 uppercase)</span></span><input type="text" name="syn_tag" maxlength="8" placeholder="e.g. IRON" style="text-transform:uppercase"></div>
    <div class="field" style="margin-top:8px"><span>Description <span class="muted">(optional)</span></span><textarea name="syn_desc" style="min-height:70px" maxlength="500"></textarea></div>
    <button type="submit" style="margin-top:10px" <?= ($player['shards']??0)<SYNDICATE_CREATE_COST?'disabled':'' ?>>&#9760; Form Syndicate &mdash; <?= SYNDICATE_CREATE_COST ?> &#9670;</button>
  </form>
  <?php endif; ?>
</div>
<?php // ══ VIEW (read-only public profile of any Syndicate) ═══
elseif ($tab === 'view'):
  $viewSid = (int)($_GET['sid'] ?? 0);
  $viewSyn = null;
  if ($viewSid > 0) {
    try {
      $vq = $pdo->prepare("SELECT s.*,(SELECT COUNT(*) FROM syndicate_members sm WHERE sm.syndicate_id=s.id) AS member_count,p.username AS leader_name,p.id AS leader_id FROM syndicates s JOIN players p ON p.id=s.leader_id WHERE s.id=?");
      $vq->execute([$viewSid]);
      $viewSyn = $vq->fetch();
    } catch (Throwable $e) {}
  }
?>
<div style="margin-bottom:10px"><a href="index.php?p=guilds&tab=search" style="font-size:11px;color:var(--muted);text-decoration:none">&larr; Back to Directory</a></div>
<?php if (!$viewSyn): ?>
<div class="panel" style="text-align:center;padding:24px;color:var(--muted)">That Syndicate doesn't exist (or was disbanded).</div>
<?php else:
  $viewIsMine = $mySyn && (int)$mySyn['syndicate_id'] === $viewSid;
  $vNextLevelXp = syn_xp_for_level((int)$viewSyn['level'] + 1);
  $vThisLevelXp = syn_xp_for_level((int)$viewSyn['level']);
  $vProgress = $vNextLevelXp > $vThisLevelXp ? max(0, min(100, round((((int)$viewSyn['xp'] - $vThisLevelXp) / ($vNextLevelXp - $vThisLevelXp)) * 100))) : 100;
  $viewOfficers = [];
  try {
    $vfq = $pdo->prepare("SELECT sm.rank,sm.player_id,p.username,p.level,p.role,p.chat_color FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? AND sm.rank != 'member' ORDER BY FIELD(sm.rank,'leader','coleader','treasurer','armourer','librarian','advisor')");
    $vfq->execute([$viewSid]);
    foreach ($vfq->fetchAll() as $vfm) $viewOfficers[$vfm['rank']][] = $vfm;
  } catch (Throwable $e) {}
  $viewMembers = [];
  try {
    $vmq = $pdo->prepare("SELECT sm.player_id,sm.rank,sm.joined_at,p.username,p.level,p.role,p.chat_color FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY FIELD(sm.rank,'leader','coleader','treasurer','armourer','librarian','advisor','member'), p.username ASC");
    $vmq->execute([$viewSid]);
    $viewMembers = $vmq->fetchAll();
  } catch (Throwable $e) {}
?>
<div class="panel">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <span style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:var(--neon2)">[<?= e($viewSyn['tag']) ?>]</span>
      <span style="font-size:18px;font-weight:700;color:var(--text);margin-left:8px"><?= e($viewSyn['name']) ?></span>
      <?php if ($viewIsMine): ?><span style="font-size:11px;color:var(--accent);margin-left:8px">&#10003; Your Syndicate</span><?php endif; ?>
      <div style="font-size:11px;color:var(--muted);margin-top:4px">Led by <a href="index.php?p=profile&id=<?= (int)$viewSyn['leader_id'] ?>" style="color:var(--neon2);font-weight:700"><?= e($viewSyn['leader_name']) ?></a> &middot; Founded <?= e(date('M j, Y', strtotime($viewSyn['created_at']))) ?></div>
    </div>
    <div style="text-align:right">
      <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:var(--accent)">Lv <?= (int)$viewSyn['level'] ?></div>
      <div style="font-size:10px;color:var(--muted)"><?= (int)$viewSyn['member_count'] ?> member<?= (int)$viewSyn['member_count']!==1?'s':'' ?></div>
    </div>
  </div>
  <?php if ($vNextLevelXp > $vThisLevelXp): ?>
  <div style="margin-top:10px;height:6px;border-radius:4px;background:var(--panel2);overflow:hidden"><div style="height:100%;width:<?= $vProgress ?>%;background:var(--accent)"></div></div>
  <div style="font-size:10px;color:var(--muted);margin-top:3px">Progress to Level <?= (int)$viewSyn['level']+1 ?></div>
  <?php endif; ?>
  <?php if (!empty($viewSyn['description'])): ?>
  <div style="font-size:12px;color:var(--muted);margin-top:12px;white-space:pre-wrap"><?= e($viewSyn['description']) ?></div>
  <?php endif; ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700;color:var(--neon2)">&#128737; Officers</div>
  <?php foreach (['leader','coleader','treasurer','armourer','librarian','advisor'] as $ork):
    $holders = $viewOfficers[$ork] ?? [];
    $rc = $SYN_RANK_COLORS[$ork] ?? 'var(--muted)';
    $rl = $SYN_RANK_LABELS[$ork] ?? ucfirst($ork);
  ?>
  <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="width:100px;flex:none;font-size:11px;font-weight:700;font-style:italic;color:<?= $rc ?>"><?= e($rl) ?></div>
    <?php if (empty($holders)): ?>
      <span style="font-size:12px;color:var(--muted);font-style:italic">Vacant</span>
    <?php else: foreach ($holders as $ho): ?>
      <a href="index.php?p=profile&id=<?= (int)$ho['player_id'] ?>" style="font-weight:700;font-size:13px;color:<?= e($rc) ?>"><?= e($ho['username']) ?></a>
      <span style="font-size:11px;color:var(--muted)">Lv<?= (int)$ho['level'] ?></span>
    <?php endforeach; endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)"><?= count($viewMembers) ?> member<?= count($viewMembers)!==1?'s':'' ?></div>
  <?php foreach ($viewMembers as $m):
    $mc = chat_color($m['role'],'');
    $rc = $SYN_RANK_COLORS[$m['rank']] ?? 'var(--muted)';
    $rl = $SYN_RANK_LABELS[$m['rank']] ?? ucfirst($m['rank']);
    $joinStr = !empty($m['joined_at']) ? date('M j Y', strtotime($m['joined_at'])) : '';
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);flex-wrap:wrap">
    <div style="width:30px;height:30px;border-radius:7px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.12);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent);flex:none"><?= mb_strtoupper(mb_substr($m['username'],0,1)) ?></div>
    <div style="flex:1;min-width:100px">
      <a href="index.php?p=profile&id=<?= (int)$m['player_id'] ?>" style="font-weight:700;color:<?= e($mc) ?>"><?= e($m['username']) ?></a>
      <span style="font-size:10px;color:var(--muted)"> Lv<?= (int)$m['level'] ?></span>
      <?php if ($joinStr): ?><span style="font-size:10px;color:var(--muted);margin-left:6px">Joined <?= $joinStr ?></span><?php endif; ?>
    </div>
    <em style="font-size:11px;font-weight:700;color:<?= $rc ?>;font-style:italic"><?= e($rl) ?></em>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
(function(){
'use strict';
/* ── Faction HQ header: wall, stencil tag, smoke, hazard stripe ── */
var gc=document.getElementById('syn-canvas');
if(!gc) return;
var c=gc.getContext('2d');
var GW=560, GH=108;
var dpr=Math.min(2,window.devicePixelRatio||1);
gc.width=GW*dpr; gc.height=GH*dpr;
c.scale(dpr,dpr);
var TAG=gc.dataset.tag||'';
var NAME=gc.dataset.name||'';
var member=TAG!=='';
// rivet positions (deterministic)
var rivets=[];
(function(){ var s=5; function r(){ s=(s*16807)%2147483647; return s/2147483647; }
  for(var i=0;i<3;i++) for(var j=0;j<13;j++) rivets.push({x:22+j*42+r()*4,y:16+i*32+r()*4});
})();
var smoke=[];
var buzzUntil=0;

function gLoop(t){
  if(!document.body.contains(gc)) return;
  requestAnimationFrame(gLoop);
  c.clearRect(0,0,GW,GH);
  // metal wall
  var bg=c.createLinearGradient(0,0,0,GH);
  bg.addColorStop(0,'#0c0a10'); bg.addColorStop(1,'#110d16');
  c.fillStyle=bg; c.fillRect(0,0,GW,GH);
  // wall panel seams + rivets
  c.strokeStyle='rgba(255,255,255,.045)';
  for(var sx=140;sx<GW;sx+=140){ c.beginPath(); c.moveTo(sx,0); c.lineTo(sx,GH-14); c.stroke(); }
  c.fillStyle='rgba(255,255,255,.07)';
  for(var ri=0;ri<rivets.length;ri++){ c.beginPath(); c.arc(rivets[ri].x,rivets[ri].y,1.2,0,Math.PI*2); c.fill(); }

  // stencil tag emblem (center-right) with neon flicker
  var on=t>buzzUntil;
  if(on&&Math.random()<.009) buzzUntil=t+90+Math.random()*320;
  var fl=on?(.8+.2*Math.sin(t/400)):.22;
  c.save();
  c.translate(GW-150,46); c.rotate(-.06);
  c.shadowColor='#ff2d95'; c.shadowBlur=16*fl;
  c.fillStyle='rgba(255,45,149,'+fl+')';
  c.font='900 34px Orbitron, monospace'; c.textAlign='center'; c.textBaseline='middle';
  c.fillText(member?('['+TAG+']'):'☠',0,0);
  if(member){
    c.shadowBlur=8*fl;
    c.font='700 9px monospace';
    c.fillStyle='rgba(255,45,149,'+(fl*.8)+')';
    c.fillText(NAME.toUpperCase().slice(0,28),0,26);
  } else {
    c.shadowBlur=8*fl;
    c.font='700 10px monospace';
    c.fillText('FIND YOUR FACTION',0,28);
  }
  c.restore();
  c.shadowBlur=0;

  // drifting smoke
  if(Math.random()<.05) smoke.push({x:30+Math.random()*220,y:GH-16,r:6+Math.random()*10,a:.10,vx:(Math.random()-.5)*.12});
  for(var si=smoke.length-1;si>=0;si--){
    var S=smoke[si];
    S.y-=.18; S.x+=S.vx; S.r+=.05; S.a-=.0008;
    if(S.a<=0){ smoke.splice(si,1); continue; }
    var sg=c.createRadialGradient(S.x,S.y,1,S.x,S.y,S.r);
    sg.addColorStop(0,'rgba(170,160,190,'+S.a+')'); sg.addColorStop(1,'rgba(170,160,190,0)');
    c.fillStyle=sg;
    c.beginPath(); c.arc(S.x,S.y,S.r,0,Math.PI*2); c.fill();
  }

  // sweeping search light
  var lx=((t/30)%(GW+260))-130;
  var lg=c.createLinearGradient(lx-70,0,lx+70,0);
  lg.addColorStop(0,'rgba(25,240,199,0)'); lg.addColorStop(.5,'rgba(25,240,199,.035)'); lg.addColorStop(1,'rgba(25,240,199,0)');
  c.fillStyle=lg; c.fillRect(0,0,GW,GH-14);

  // hazard stripe footer
  c.save();
  c.beginPath(); c.rect(0,GH-14,GW,14); c.clip();
  c.fillStyle='#0a090d'; c.fillRect(0,GH-14,GW,14);
  for(var hx=-20;hx<GW+20;hx+=24){
    c.fillStyle='rgba(232,163,61,.22)';
    c.beginPath();
    c.moveTo(hx,GH); c.lineTo(hx+12,GH-14); c.lineTo(hx+22,GH-14); c.lineTo(hx+10,GH);
    c.closePath(); c.fill();
  }
  c.restore();
}
requestAnimationFrame(gLoop);
})();
</script>
