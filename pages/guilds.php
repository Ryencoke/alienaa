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

// ── Load membership ──
$mySyn = null; $myRank = '';
try {
  $q = $pdo->prepare('SELECT sm.syndicate_id,sm.rank,s.name,s.tag,s.level,s.xp,s.bank,s.description,s.leader_id,s.announcement,s.announcement_at FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?');
  $q->execute([$pid]); $mySyn = $q->fetch() ?: null;
  if ($mySyn) $myRank = $mySyn['rank'];
} catch (Throwable $e) {}

// ── Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'create') {
      if ($mySyn) throw new RuntimeException('You already belong to a Syndicate. Leave it first.');
      $name = trim($_POST['syn_name'] ?? '');
      $tag  = strtoupper(trim($_POST['syn_tag'] ?? ''));
      $desc = trim($_POST['syn_desc'] ?? '');
      if (!preg_match('/^[A-Za-z0-9 _\-]{3,60}$/', $name)) throw new RuntimeException('Name: 3–60 chars, letters/numbers/spaces only.');
      if (!preg_match('/^[A-Z0-9]{2,8}$/', $tag))           throw new RuntimeException('Tag: 2–8 uppercase letters/numbers only.');
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SYNDICATE_CREATE_COST, $pid, SYNDICATE_CREATE_COST]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough Shards. Costs ' . SYNDICATE_CREATE_COST . ' ◆.');
      $pdo->beginTransaction();
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
      $pdo->prepare('INSERT INTO syndicate_members (syndicate_id,player_id,rank) VALUES (?,?,?)')->execute([$mySyn['syndicate_id'],$app['player_id'],'member']);
      $pdo->prepare('UPDATE syndicates SET xp=xp+50 WHERE id=?')->execute([$mySyn['syndicate_id']]);
      $pdo->prepare("UPDATE syndicate_applications SET status='accepted' WHERE id=?")->execute([$appId]);
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
        if ($ln['player_gear_id']) {
          foreach (['weapon','armor'] as $sl2) { $gq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $gq->execute(["equipped_{$sl2}:{$pid}"]); if ((int)($gq->fetchColumn()?:0) === (int)$ln['player_gear_id']) $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["equipped_{$sl2}:{$pid}"]); }
          $pdo->prepare('DELETE FROM player_gear WHERE id=? AND player_id=?')->execute([$ln['player_gear_id'],$pid]);
        }
        $pdo->prepare('UPDATE syndicate_loans SET returned_at=NOW() WHERE id=?')->execute([$ln['id']]);
        $pdo->prepare('UPDATE syndicate_stockpile SET available=1 WHERE id=(SELECT stockpile_id FROM syndicate_loans WHERE id=?)')->execute([$ln['id']]);
      }
      $pdo->prepare('DELETE FROM syndicate_members WHERE player_id=?')->execute([$pid]);
      $leaveDetail = $player['username'].' left the syndicate' . ($leavemsg ? ': "'.$leavemsg.'"' : '');
      syn_log($pdo, $sid, $pid, $pid, 'left', $leaveDetail);
      $mySyn = null; $myRank = ''; $msg = 'You left the Syndicate.';

    } elseif ($act === 'donate') {
      if (!$mySyn) throw new RuntimeException('Not in a Syndicate.');
      $amt = (int)($_POST['amount'] ?? 0); if ($amt < 1) throw new RuntimeException('Enter an amount above zero.');
      $u = $pdo->prepare('UPDATE players SET creds_pocket=creds_pocket-? WHERE id=? AND creds_pocket>=?'); $u->execute([$amt,$pid,$amt]);
      if ($u->rowCount()!==1) throw new RuntimeException('Not enough credits in pocket.');
      $pdo->prepare('UPDATE syndicates SET bank=bank+?,xp=xp+? WHERE id=?')->execute([$amt,max(1,(int)($amt/100)),$mySyn['syndicate_id']]);
      syn_log($pdo,$mySyn['syndicate_id'],$pid,$pid,'donated',number_format($amt).' cr donated to bank');
      $player = current_player();
      $msg = 'Donated ' . number_format($amt) . ' credits.';
      $q->execute([$pid]); $mySyn = $q->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];

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
      // Cannot elevate to leader via this action
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
        if ($ln['player_gear_id']) $pdo->prepare('DELETE FROM player_gear WHERE id=? AND player_id=?')->execute([$ln['player_gear_id'],$target]);
        $pdo->prepare('UPDATE syndicate_loans SET returned_at=NOW() WHERE id=?')->execute([$ln['id']]);
        $pdo->prepare('UPDATE syndicate_stockpile SET available=1 WHERE id=(SELECT stockpile_id FROM syndicate_loans WHERE id=?)')->execute([$ln['id']]);
      }
      $pdo->prepare('DELETE FROM syndicate_members WHERE player_id=? AND syndicate_id=?')->execute([$target,$mySyn['syndicate_id']]);
      $tq = $pdo->prepare('SELECT username FROM players WHERE id=?'); $tq->execute([$target]); $tn = $tq->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$target,$pid,'kicked',$tn.' was kicked');
      $msg = 'Member kicked.';

    } elseif ($act === 'stockpile_add') {
      if (!$mySyn || !syn_can($myRank,'manage_stockpile')) throw new RuntimeException('No permission.');
      $iname = mb_substr(trim($_POST['item_name'] ?? ''),0,100); $itype = $_POST['gear_type'] ?? 'weapon';
      $atk = max(0,min(999,(int)($_POST['atk_bonus'] ?? 0))); $def = max(0,min(999,(int)($_POST['def_bonus'] ?? 0)));
      $notes = mb_substr(trim($_POST['notes'] ?? ''),0,200);
      if ($iname==='') throw new RuntimeException('Enter an item name.');
      if (!in_array($itype,['weapon','armor','item'],true)) $itype='weapon';
      $pdo->prepare('INSERT INTO syndicate_stockpile (syndicate_id,item_name,gear_type,atk_bonus,def_bonus,notes,added_by) VALUES (?,?,?,?,?,?,?)')->execute([$mySyn['syndicate_id'],$iname,$itype,$atk,$def,$notes,$pid]);
      syn_log($pdo,$mySyn['syndicate_id'],null,$pid,'stockpile_add','Added "'.$iname.'" to stockpile');
      $msg = '"' . $iname . '" added to stockpile.';

    } elseif ($act === 'stockpile_remove') {
      if (!$mySyn || !syn_can($myRank,'manage_stockpile')) throw new RuntimeException('No permission.');
      $iid = (int)($_POST['item_id'] ?? 0);
      $ck = $pdo->prepare('SELECT item_name,available FROM syndicate_stockpile WHERE id=? AND syndicate_id=?'); $ck->execute([$iid,$mySyn['syndicate_id']]); $si = $ck->fetch();
      if (!$si) throw new RuntimeException('Item not found.');
      if (!$si['available']) throw new RuntimeException('Item is currently loaned out. Return it first.');
      $pdo->prepare('DELETE FROM syndicate_stockpile WHERE id=? AND syndicate_id=?')->execute([$iid,$mySyn['syndicate_id']]);
      syn_log($pdo,$mySyn['syndicate_id'],null,$pid,'stockpile_remove','Removed "'.$si['item_name'].'" from stockpile');
      $msg = 'Item removed from stockpile.';

    } elseif ($act === 'loan_item') {
      if (!$mySyn || !syn_can($myRank,'loan_items')) throw new RuntimeException('No permission.');
      $iid = (int)($_POST['item_id'] ?? 0); $borrower = (int)($_POST['borrower_id'] ?? 0);
      // Verify item belongs to this syndicate and is available
      $sq = $pdo->prepare('SELECT * FROM syndicate_stockpile WHERE id=? AND syndicate_id=? AND available=1'); $sq->execute([$iid,$mySyn['syndicate_id']]); $si = $sq->fetch();
      if (!$si) throw new RuntimeException('Item not found or already loaned.');
      // Verify borrower is in syndicate
      $bq = $pdo->prepare('SELECT rank FROM syndicate_members WHERE player_id=? AND syndicate_id=?'); $bq->execute([$borrower,$mySyn['syndicate_id']]); if (!$bq->fetchColumn()) throw new RuntimeException('Not a syndicate member.');
      // Insert into borrower's player_gear
      $pdo->prepare("INSERT INTO player_gear (player_id,recipe_id,name,gear_type,atk_bonus,def_bonus,loan_id) VALUES (?,0,?,?,?,?,0)")->execute([$borrower,$si['item_name'],$si['gear_type'],$si['atk_bonus'],$si['def_bonus'],0]);
      $pgid = (int)$pdo->lastInsertId();
      // Create loan record
      $pdo->prepare('INSERT INTO syndicate_loans (syndicate_id,stockpile_id,player_id,player_gear_id,loaned_by) VALUES (?,?,?,?,?)')->execute([$mySyn['syndicate_id'],$iid,$borrower,$pgid,$pid]);
      $lid = (int)$pdo->lastInsertId();
      // Update player_gear loan_id to the loan id
      $pdo->prepare('UPDATE player_gear SET loan_id=? WHERE id=?')->execute([$lid,$pgid]);
      // Mark stockpile item as loaned out
      $pdo->prepare('UPDATE syndicate_stockpile SET available=0 WHERE id=?')->execute([$iid]);
      $bname = $pdo->prepare('SELECT username FROM players WHERE id=?'); $bname->execute([$borrower]); $bn = $bname->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$borrower,$pid,'loan_out','"'.$si['item_name'].'" loaned to '.$bn);
      $msg = '"' . $si['item_name'] . '" loaned to ' . $bn . '.';

    } elseif ($act === 'return_item') {
      if (!$mySyn) throw new RuntimeException('Not in a Syndicate.');
      $lid = (int)($_POST['loan_id'] ?? 0);
      $lq = $pdo->prepare('SELECT * FROM syndicate_loans WHERE id=? AND syndicate_id=? AND returned_at IS NULL'); $lq->execute([$lid,$mySyn['syndicate_id']]); $ln = $lq->fetch();
      if (!$ln) throw new RuntimeException('Loan not found.');
      // Only the borrower or armourers+ can return
      if ((int)$ln['player_id'] !== $pid && !syn_can($myRank,'loan_items')) throw new RuntimeException('No permission.');
      // Remove from borrower's player_gear
      foreach (['weapon','armor'] as $gsl) { $gq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $gq->execute(["equipped_{$gsl}:{$ln['player_id']}"]); if ((int)($gq->fetchColumn()?:0)===(int)$ln['player_gear_id']) $pdo->prepare('DELETE FROM settings WHERE k=?')->execute(["equipped_{$gsl}:{$ln['player_id']}"]); }
      $pdo->prepare('DELETE FROM player_gear WHERE id=? AND player_id=?')->execute([$ln['player_gear_id'],$ln['player_id']]);
      $pdo->prepare('UPDATE syndicate_loans SET returned_at=NOW() WHERE id=?')->execute([$lid]);
      $pdo->prepare('UPDATE syndicate_stockpile SET available=1 WHERE id=?')->execute([$ln['stockpile_id']]);
      $sq = $pdo->prepare('SELECT item_name FROM syndicate_stockpile WHERE id=?'); $sq->execute([$ln['stockpile_id']]); $sname = $sq->fetchColumn() ?: '?';
      syn_log($pdo,$mySyn['syndicate_id'],$ln['player_id'],$pid,'returned','"'.$sname.'" returned to stockpile');
      $msg = '"' . $sname . '" returned to stockpile.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$tab = $_GET['tab'] ?? '';
$boardTid = (int)($_GET['tid'] ?? 0); // board: topic detail view
$validTabs = $mySyn ? ['home','board','members','stockpile','log','search'] : ['search','create'];
if (!in_array($tab, $validTabs, true)) $tab = $mySyn ? 'home' : 'search';

// Pending applications count for badge
$pendingApps = 0;
if ($mySyn && syn_can($myRank, 'manage_members')) {
  try { $pa = $pdo->prepare("SELECT COUNT(*) FROM syndicate_applications WHERE syndicate_id=? AND status='pending'"); $pa->execute([$mySyn['syndicate_id']]); $pendingApps = (int)$pa->fetchColumn(); } catch (Throwable $e) {}
}
?>

<?php if ($msg): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--neon2),var(--accent),transparent)"></div>
  <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0 0 2px">&#9760; Syndicates</h2>
      <p class="muted" style="margin:0;font-size:12px">Factions of the Sprawl. Strength through collective.</p>
    </div>
    <?php if ($mySyn): ?>
    <div style="text-align:right">
      <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:var(--neon2)">[<?= e($mySyn['tag']) ?>] <?= e($mySyn['name']) ?></div>
      <div style="font-size:11px;color:<?= e($SYN_RANK_COLORS[$myRank]??'var(--muted)') ?>"><?= e($SYN_RANK_LABELS[$myRank] ?? ucfirst($myRank)) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap">
  <?php
  $membersLabel = '&#128101; Members' . ($pendingApps ? ' <span style="background:var(--neon2);color:#000;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:700">'.$pendingApps.'</span>' : '');
  $tabDefs = $mySyn
    ? ['home'=>'&#128202; Overview','board'=>'&#128203; Board','members'=>$membersLabel,'stockpile'=>'&#9874; Stockpile','log'=>'&#128196; Log','search'=>'&#128269; Browse']
    : ['search'=>'&#128269; Find Syndicate','create'=>'&#43; Create'];
  foreach ($tabDefs as $tk=>$tl):
    $show = isset($tabDefs[$tk]);
    if ($tk==='log' && !syn_can($myRank,'view_log') && !$isAdmin) continue;
  ?>
  <a href="index.php?p=guilds&tab=<?= $tk ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tk?'var(--neon2)':'var(--line)' ?>;background:<?= $tab===$tk?'rgba(255,45,149,.1)':'var(--panel2)' ?>;color:<?= $tab===$tk?'var(--neon2)':'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php // ══ OVERVIEW ══════════════════════════════════════
if ($tab === 'home' && $mySyn):

  // Load recent log
  $recentLog = [];
  try { $lq = $pdo->prepare('SELECT sl.*,p.username FROM syndicate_log sl LEFT JOIN players p ON p.id=sl.actor_id WHERE sl.syndicate_id=? ORDER BY sl.id DESC LIMIT 5'); $lq->execute([$mySyn['syndicate_id']]); $recentLog = $lq->fetchAll(); } catch (Throwable $e) {}
?>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
  <?php foreach ([['Level',(int)$mySyn['level'],'var(--accent)'],['XP',number_format((int)$mySyn['xp']),'#e8a33d'],['Bank',number_format((int)$mySyn['bank']).' cr','#3bcf63']] as [$sl,$sv,$sc]): ?>
  <div class="panel" style="text-align:center;margin-bottom:0">
    <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:<?= $sc ?>"><?= $sv ?></div>
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px"><?= $sl ?></div>
  </div>
  <?php endforeach; ?>
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

<!-- Finance actions -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;font-size:13px">&#128178; Donate to Bank</h3>
    <form method="post">
      <input type="hidden" name="action" value="donate">
      <div class="field">
        <span>Amount <span class="muted" style="font-size:11px">Pocket: <?= number_format((int)$player['creds_pocket']) ?> cr</span></span>
        <div class="num-wrap"><input type="number" name="amount" id="donAmt" min="1" max="<?= (int)$player['creds_pocket'] ?>" placeholder="0"><button type="button" class="fill-max" onclick="document.getElementById('donAmt').value=<?= (int)$player['creds_pocket'] ?>">Max</button></div>
      </div>
      <button type="submit" style="margin-top:8px;font-size:12px" <?= (int)$player['creds_pocket']<1?'disabled':'' ?>>Donate</button>
    </form>
  </div>
  <?php if (syn_can($myRank,'manage_bank')): ?>
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;font-size:13px">&#128178; Withdraw from Bank</h3>
    <form method="post">
      <input type="hidden" name="action" value="bank_withdraw">
      <div class="field">
        <span>Amount <span class="muted" style="font-size:11px">Bank: <?= number_format((int)$mySyn['bank']) ?> cr</span></span>
        <div class="num-wrap"><input type="number" name="amount" id="wdAmt" min="1" max="<?= (int)$mySyn['bank'] ?>" placeholder="0"><button type="button" class="fill-max" onclick="document.getElementById('wdAmt').value=<?= (int)$mySyn['bank'] ?>">Max</button></div>
      </div>
      <button type="submit" style="margin-top:8px;font-size:12px" <?= (int)$mySyn['bank']<1?'disabled':'' ?>>Withdraw</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- Recent log -->
<?php if (!empty($recentLog)): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">&#128196; Recent Activity</h3>
  <?php foreach ($recentLog as $le): ?>
  <div style="font-size:12px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);color:var(--muted)">
    <span style="color:var(--accent)"><?= e($le['username'] ?? 'System') ?></span> — <?= e($le['detail'] ?? $le['action']) ?>
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
elseif ($tab === 'board' && $mySyn):

if ($boardTid) {
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
    $tc = chat_color($topicPost['role'],$topicPost['chat_color']);
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
      $rc = chat_color($rp['role'],$rp['chat_color']); ?>
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
    $tc = chat_color($t['role'], $t['chat_color']);
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


<?php // ══ MEMBERS ════════════════════════════════════════
elseif ($tab === 'members' && $mySyn):
  $members = [];
  try { $mq = $pdo->prepare('SELECT sm.player_id,sm.rank,sm.joined_at,p.username,p.level,p.role,p.chat_color FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY FIELD(sm.rank,"leader","coleader","treasurer","armourer","librarian","advisor","member"),p.username'); $mq->execute([$mySyn['syndicate_id']]); $members = $mq->fetchAll(); } catch (Throwable $e) {}
  $canManage = syn_can($myRank,'manage_members');
  // Pending applications
  $apps = [];
  if ($canManage) { try { $aq = $pdo->prepare("SELECT sa.*,p.username,p.level FROM syndicate_applications sa JOIN players p ON p.id=sa.player_id WHERE sa.syndicate_id=? AND sa.status='pending' ORDER BY sa.created_at ASC"); $aq->execute([$mySyn['syndicate_id']]); $apps = $aq->fetchAll(); } catch (Throwable $e) {} }
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)"><?= count($members) ?> member<?= count($members)!==1?'s':'' ?></div>
  <?php foreach ($members as $m):
    $mc = chat_color($m['role'],$m['chat_color']);
    $rc = $SYN_RANK_COLORS[$m['rank']] ?? 'var(--muted)';
    $rl = $SYN_RANK_LABELS[$m['rank']] ?? ucfirst($m['rank']);
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);flex-wrap:wrap">
    <div style="width:30px;height:30px;border-radius:7px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.12);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent);flex:none"><?= mb_strtoupper(mb_substr($m['username'],0,1)) ?></div>
    <div style="flex:1;min-width:100px">
      <a href="index.php?p=profile&id=<?= (int)$m['player_id'] ?>" style="font-weight:700;color:<?= e($mc) ?>"><?= e($m['username']) ?></a>
      <span style="font-size:10px;color:var(--muted)"> Lv<?= (int)$m['level'] ?></span>
    </div>
    <em style="font-size:11px;font-weight:700;color:<?= $rc ?>;font-style:italic"><?= e($rl) ?></em>
    <?php if ($canManage && (int)$m['player_id'] !== $pid && $m['rank'] !== 'leader'): ?>
    <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
      <input type="hidden" name="action" value="setrole">
      <input type="hidden" name="target_id" value="<?= (int)$m['player_id'] ?>">
      <select name="new_rank" style="font-size:11px;padding:2px 6px;background:var(--panel2);border:1px solid var(--line);color:var(--text);border-radius:4px">
        <?php foreach (array_diff($SYN_RANKS,['leader']) as $rk): ?><option value="<?= $rk ?>" <?= $m['rank']===$rk?'selected':'' ?>><?= $SYN_RANK_LABELS[$rk]??ucfirst($rk) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" style="font-size:10px;padding:3px 8px">Set</button>
    </form>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="kick">
      <input type="hidden" name="target_id" value="<?= (int)$m['player_id'] ?>">
      <button type="submit" style="font-size:10px;padding:3px 8px;background:rgba(226,59,59,.1);border-color:rgba(226,59,59,.3);color:#e23b3b" onclick="return confirm('Kick <?= e($m['username']) ?>?')">Kick</button>
    </form>
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


<?php // ══ STOCKPILE ══════════════════════════════════════
elseif ($tab === 'stockpile' && $mySyn):
  $stockpile = [];
  try { $sq = $pdo->prepare('SELECT sp.*,p.username AS added_by_name FROM syndicate_stockpile sp LEFT JOIN players p ON p.id=sp.added_by WHERE sp.syndicate_id=? ORDER BY sp.gear_type,sp.item_name'); $sq->execute([$mySyn['syndicate_id']]); $stockpile = $sq->fetchAll(); } catch (Throwable $e) {}
  $activeLoans = [];
  try { $lq = $pdo->prepare('SELECT sl.*,sp.item_name,pl.username AS borrower_name FROM syndicate_loans sl JOIN syndicate_stockpile sp ON sp.id=sl.stockpile_id JOIN players pl ON pl.id=sl.player_id WHERE sl.syndicate_id=? AND sl.returned_at IS NULL ORDER BY sl.loaned_at DESC'); $lq->execute([$mySyn['syndicate_id']]); $activeLoans = $lq->fetchAll(); } catch (Throwable $e) {}
  $canManageStock = syn_can($myRank,'manage_stockpile');
  $canLoan = syn_can($myRank,'loan_items');
  // Members for loan dropdown
  $synMembers = [];
  if ($canLoan) { try { $mq = $pdo->prepare('SELECT sm.player_id,p.username FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY p.username'); $mq->execute([$mySyn['syndicate_id']]); $synMembers = $mq->fetchAll(); } catch (Throwable $e) {} }
?>

<!-- Add to stockpile -->
<?php if ($canManageStock): ?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px">&#9874; Add Item to Stockpile</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px">
    <input type="hidden" name="action" value="stockpile_add">
    <div class="field"><span>Item Name</span><input type="text" name="item_name" maxlength="100" placeholder="e.g. Plasma Blade Mk2"></div>
    <div class="field"><span>Type</span><select name="gear_type"><option value="weapon">Weapon</option><option value="armor">Armor</option><option value="item">Item</option></select></div>
    <div class="field"><span>ATK Bonus</span><input type="number" name="atk_bonus" value="0" min="0" max="999"></div>
    <div class="field"><span>DEF Bonus</span><input type="number" name="def_bonus" value="0" min="0" max="999"></div>
    <div class="field" style="grid-column:1/-1"><span>Notes</span><input type="text" name="notes" maxlength="200" placeholder="Optional notes about this item"></div>
    <div><button type="submit" style="font-size:12px">Add to Stockpile</button></div>
  </form>
</div>
<?php endif; ?>

<!-- Stockpile list -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:13px;font-weight:700">Stockpile Items</div>
    <span class="muted" style="font-size:11px"><?= count($stockpile) ?> items</span>
  </div>
  <?php if (empty($stockpile)): ?>
  <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">No items in stockpile yet.</div>
  <?php else: foreach ($stockpile as $si):
    $typeIcon = $si['gear_type']==='weapon'?'&#9876;':($si['gear_type']==='armor'?'&#128737;':'&#9866;');
    $typeColor = $si['gear_type']==='weapon'?'var(--neon2)':($si['gear_type']==='armor'?'var(--accent)':'#e8a33d');
  ?>
  <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:18px;flex:none;color:<?= $typeColor ?>"><?= $typeIcon ?></span>
    <div style="flex:1;min-width:120px">
      <div style="font-weight:700;font-size:13px"><?= e($si['item_name']) ?></div>
      <div style="font-size:11px;color:var(--muted)">
        <?php if ($si['atk_bonus']): ?><span style="color:var(--neon2)">+<?= $si['atk_bonus'] ?> ATK</span><?php endif; ?>
        <?php if ($si['def_bonus']): ?><span style="color:var(--accent);margin-left:6px">+<?= $si['def_bonus'] ?> DEF</span><?php endif; ?>
        <?php if ($si['notes']): ?><span style="margin-left:6px"><?= e($si['notes']) ?></span><?php endif; ?>
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
    <?php if ($canManageStock && $si['available']): ?>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="stockpile_remove"><input type="hidden" name="item_id" value="<?= (int)$si['id'] ?>"><button type="submit" style="font-size:10px;padding:3px 8px;background:rgba(226,59,59,.1);border-color:rgba(226,59,59,.3);color:#e23b3b" onclick="return confirm('Remove this item?')">Remove</button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Active loans -->
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


<?php // ══ LOG ════════════════════════════════════════════
elseif ($tab === 'log' && $mySyn && (syn_can($myRank,'view_log') || $isAdmin)):
  $logs = [];
  try { $lq = $pdo->prepare('SELECT sl.*,a.username AS actor_name,pp.username AS subject_name FROM syndicate_log sl LEFT JOIN players a ON a.id=sl.actor_id LEFT JOIN players pp ON pp.id=sl.player_id WHERE sl.syndicate_id=? ORDER BY sl.id DESC LIMIT 100'); $lq->execute([$mySyn['syndicate_id']]); $logs = $lq->fetchAll(); } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)">Last <?= count($logs) ?> events</div>
  <?php if (empty($logs)): ?>
  <div style="padding:20px;text-align:center;color:var(--muted)">No activity logged yet.</div>
  <?php else: foreach ($logs as $le):
    $logIcons = ['joined'=>'&#128101;','left'=>'&#128463;','kicked'=>'&#128245;','role_change'=>'&#9733;','donated'=>'&#128178;','withdrew'=>'&#128198;','founded'=>'&#9760;','loan_out'=>'&#9874;','returned'=>'&#9100;','announcement'=>'&#128204;','stockpile_add'=>'&#43;','stockpile_remove'=>'&#8722;'];
    $licon = $logIcons[$le['action']] ?? '&#8226;';
  ?>
  <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
    <span style="font-size:14px;flex:none;color:var(--accent)"><?= $licon ?></span>
    <div style="flex:1;min-width:0;font-size:12px">
      <?php if ($le['actor_name']): ?><b style="color:var(--accent)"><?= e($le['actor_name']) ?></b> <?php endif; ?>
      <?= e($le['detail'] ?: $le['action']) ?>
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
      <?php elseif ($isMem): ?><span style="font-size:11px;color:var(--accent)">&#10003; Your Syndicate</span><?php endif; ?>
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
<?php endif; ?>
