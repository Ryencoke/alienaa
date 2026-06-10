<?php /* pages/guilds.php — Syndicates */
$pid  = $_SESSION['pid'];
$pdo  = db();
$msg  = '';
$role = $player['role'] ?? 'member';

// Auto-create tables
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicates (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL UNIQUE,
    tag VARCHAR(8) NOT NULL UNIQUE, description TEXT,
    leader_id INT NOT NULL, bank BIGINT NOT NULL DEFAULT 0,
    level INT NOT NULL DEFAULT 1, xp BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leader (leader_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_members (
    syndicate_id INT NOT NULL, player_id INT NOT NULL,
    rank ENUM('leader','officer','member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id), INDEX idx_syn (syndicate_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS syndicate_posts (
    id INT AUTO_INCREMENT PRIMARY KEY, syndicate_id INT NOT NULL,
    author_id INT NOT NULL, title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_syn (syndicate_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

define('SYNDICATE_CREATE_COST', 50); // shards

// Find current membership
$mySyn = null; $myRank = '';
try {
  $q = $pdo->prepare('SELECT sm.syndicate_id, sm.rank, s.name, s.tag, s.level, s.xp, s.bank, s.description, s.leader_id FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?');
  $q->execute([$pid]); $mySyn = $q->fetch() ?: null;
  if ($mySyn) $myRank = $mySyn['rank'];
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'create') {
      if ($mySyn) throw new RuntimeException('You already belong to a Syndicate. Leave it first.');
      $name = trim($_POST['syn_name'] ?? '');
      $tag  = strtoupper(trim($_POST['syn_tag'] ?? ''));
      $desc = trim($_POST['syn_desc'] ?? '');
      if (!preg_match('/^[A-Za-z0-9 _\-]{3,60}$/', $name)) throw new RuntimeException('Name: 3–60 chars, letters/numbers/spaces only.');
      if (!preg_match('/^[A-Z0-9]{2,8}$/', $tag))          throw new RuntimeException('Tag: 2–8 uppercase letters/numbers only.');
      // Deduct shards
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SYNDICATE_CREATE_COST, $pid, SYNDICATE_CREATE_COST]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough Shards. Costs ' . SYNDICATE_CREATE_COST . ' ◆.');
      $pdo->beginTransaction();
      try {
        $pdo->prepare('INSERT INTO syndicates (name, tag, description, leader_id) VALUES (?,?,?,?)')->execute([$name, $tag, $desc, $pid]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO syndicate_members (syndicate_id, player_id, rank) VALUES (?,?,?)')->execute([$sid, $pid, 'leader']);
        $pdo->commit();
        $msg = 'Syndicate "' . $name . '" [' . $tag . '] created.';
        $q->execute([$pid]); $mySyn = $q->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];
      } catch (Throwable $e) { $pdo->rollBack(); if ($pdo->inTransaction()) {} throw new RuntimeException('Name or Tag already taken.'); }

    } elseif ($act === 'join') {
      if ($mySyn) throw new RuntimeException('You already belong to a Syndicate.');
      $sid = (int)($_POST['syn_id'] ?? 0);
      $q   = $pdo->prepare('SELECT id, name FROM syndicates WHERE id=?'); $q->execute([$sid]); $syn = $q->fetch();
      if (!$syn) throw new RuntimeException('Syndicate not found.');
      $pdo->prepare('INSERT INTO syndicate_members (syndicate_id, player_id, rank) VALUES (?,?,?)')->execute([$sid, $pid, 'member']);
      // Give XP to syndicate for new recruit
      $pdo->prepare('UPDATE syndicates SET xp = xp + 50 WHERE id=?')->execute([$sid]);
      $msg = 'Joined ' . $syn['name'] . '.';
      $qm = $pdo->prepare('SELECT sm.syndicate_id, sm.rank, s.name, s.tag, s.level, s.xp, s.bank, s.description, s.leader_id FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?');
      $qm->execute([$pid]); $mySyn = $qm->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];

    } elseif ($act === 'leave') {
      if (!$mySyn) throw new RuntimeException('You are not in a Syndicate.');
      if ($myRank === 'leader') throw new RuntimeException('Transfer leadership before leaving.');
      $pdo->prepare('DELETE FROM syndicate_members WHERE player_id=?')->execute([$pid]);
      $mySyn = null; $myRank = ''; $msg = 'You left your Syndicate.';

    } elseif ($act === 'donate') {
      if (!$mySyn) throw new RuntimeException('You are not in a Syndicate.');
      $amt = (int)($_POST['amount'] ?? 0);
      if ($amt < 1) throw new RuntimeException('Enter an amount above zero.');
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$amt, $pid, $amt]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits in pocket.');
      $pdo->prepare('UPDATE syndicates SET bank = bank + ?, xp = xp + ? WHERE id=?')->execute([$amt, max(1,(int)($amt/100)), $mySyn['syndicate_id']]);
      $msg = 'Donated ' . number_format($amt) . ' credits to the Syndicate.';
      // Refresh
      $qm = $pdo->prepare('SELECT sm.syndicate_id, sm.rank, s.name, s.tag, s.level, s.xp, s.bank, s.description, s.leader_id FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?');
      $qm->execute([$pid]); $mySyn = $qm->fetch() ?: null; if ($mySyn) $myRank = $mySyn['rank'];
      $player = current_player();

    } elseif ($act === 'post') {
      if (!$mySyn) throw new RuntimeException('Members only.');
      $title = trim($_POST['post_title'] ?? '');
      $body  = trim($_POST['post_body'] ?? '');
      if ($title === '' || mb_strlen($title) > 120) throw new RuntimeException('Title: 1–120 chars.');
      if (mb_strlen($body) < 2) throw new RuntimeException('Write something in the body.');
      $pdo->prepare('INSERT INTO syndicate_posts (syndicate_id, author_id, title, body) VALUES (?,?,?,?)')->execute([$mySyn['syndicate_id'], $pid, $title, $body]);
      $msg = 'Post published to the Syndicate board.';

    } elseif ($act === 'setrole' && $myRank === 'leader') {
      $target = (int)($_POST['target_id'] ?? 0);
      $newRank = $_POST['new_rank'] ?? '';
      if (!in_array($newRank, ['officer','member'], true)) throw new RuntimeException('Invalid rank.');
      $pdo->prepare('UPDATE syndicate_members SET rank=? WHERE player_id=? AND syndicate_id=?')->execute([$newRank, $target, $mySyn['syndicate_id']]);
      $msg = 'Rank updated.';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$tab = in_array($_GET['tab'] ?? '', ['home','board','members','search','create']) ? $_GET['tab'] : ($mySyn ? 'home' : 'search');
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
      <div style="font-size:11px;color:var(--muted)">Level <?= (int)$mySyn['level'] ?> &middot; <?= ucfirst($myRank) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap">
  <?php
  $tabs = $mySyn
    ? ['home'=>'&#128202; Overview','board'=>'&#128203; Board','members'=>'&#128101; Members','search'=>'&#128269; All Syndicates']
    : ['search'=>'&#128269; Search &amp; Join','create'=>'&#43; Create'];
  foreach ($tabs as $tid=>$tl): ?>
  <a href="index.php?p=guilds&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? 'var(--neon2)' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(255,45,149,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? 'var(--neon2)' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<!-- ── MY SYNDICATE HOME ── -->
<?php if ($tab === 'home' && $mySyn): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
  <?php foreach ([['Level',$mySyn['level'],'var(--accent)'],['XP',$mySyn['xp'],'#e8a33d'],['Bank',number_format($mySyn['bank']).' cr','#3bcf63']] as [$lbl,$val,$c]): ?>
  <div class="panel" style="text-align:center;margin-bottom:0">
    <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:<?= $c ?>"><?= $val ?></div>
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php if (!empty($mySyn['description'])): ?>
<div class="panel"><p style="margin:0;font-size:13px;color:var(--muted)"><?= e($mySyn['description']) ?></p></div>
<?php endif; ?>
<div class="panel">
  <h3 style="margin-top:0">Donate Credits</h3>
  <form method="post">
    <input type="hidden" name="action" value="donate">
    <div class="field">
      <span>Amount <span class="muted" style="font-size:11px">Pocket: <?= number_format((int)$player['creds_pocket']) ?> cr</span></span>
      <div class="num-wrap">
        <input type="number" name="amount" id="donateAmt" min="1" max="<?= (int)$player['creds_pocket'] ?>" placeholder="0">
        <button type="button" class="fill-max" onclick="document.getElementById('donateAmt').value=<?= (int)$player['creds_pocket'] ?>">Max</button>
      </div>
    </div>
    <button type="submit" style="margin-top:8px;font-size:12px" <?= (int)$player['creds_pocket'] < 1 ? 'disabled' : '' ?>>&#128178; Donate</button>
  </form>
  <p class="muted" style="font-size:11px;margin:6px 0 0">Donations give XP to the Syndicate. The bank is controlled by leadership.</p>
</div>
<?php if ($myRank !== 'leader'): ?>
<div class="panel">
  <form method="post" style="margin:0">
    <input type="hidden" name="action" value="leave">
    <button type="submit" style="background:rgba(255,45,149,.08);border-color:rgba(255,45,149,.25);color:var(--neon2);font-size:12px" onclick="return confirm('Leave your Syndicate?')">Leave Syndicate</button>
  </form>
</div>
<?php endif; ?>

<!-- ── BOARD ── -->
<?php elseif ($tab === 'board' && $mySyn): ?>
<div class="panel">
  <h3 style="margin-top:0">Post to Board</h3>
  <form method="post">
    <input type="hidden" name="action" value="post">
    <div class="field"><span>Title</span><input type="text" name="post_title" maxlength="120"></div>
    <div class="field" style="margin-top:8px"><span>Body</span><textarea name="post_body" style="min-height:80px"></textarea></div>
    <button type="submit" style="margin-top:8px">Post</button>
  </form>
</div>
<?php
  $posts = [];
  try { $q = $pdo->prepare('SELECT sp.*, p.username, p.role, p.chat_color FROM syndicate_posts sp JOIN players p ON p.id=sp.author_id WHERE sp.syndicate_id=? ORDER BY sp.id DESC LIMIT 30'); $q->execute([$mySyn['syndicate_id']]); $posts = $q->fetchAll(); } catch (Throwable $e) {}
?>
<?php foreach ($posts as $post): $pcol = chat_color($post['role'], $post['chat_color']); ?>
<div class="panel" style="padding:14px">
  <div style="font-weight:700;font-size:14px;margin-bottom:6px"><?= e($post['title']) ?></div>
  <div style="font-size:12px;color:var(--muted);white-space:pre-wrap"><?= e($post['body']) ?></div>
  <div style="margin-top:8px;font-size:11px;color:var(--muted)">by <b style="color:<?= e($pcol) ?>"><?= e($post['username']) ?></b> &middot; <?= e(date('M j Y', strtotime($post['created_at']))) ?></div>
</div>
<?php endforeach; ?>
<?php if (empty($posts)) { echo '<div class="panel" style="color:var(--muted);text-align:center">No posts yet.</div>'; } ?>

<?php // ── MEMBERS ──
elseif ($tab === 'members' && $mySyn):
  $members = [];
  try { $q = $pdo->prepare('SELECT sm.player_id, sm.rank, sm.joined_at, p.username, p.level, p.role, p.chat_color FROM syndicate_members sm JOIN players p ON p.id=sm.player_id WHERE sm.syndicate_id=? ORDER BY FIELD(sm.rank,"leader","officer","member"), p.username'); $q->execute([$mySyn['syndicate_id']]); $members = $q->fetchAll(); } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted)"><?= count($members) ?> member<?= count($members)!==1?'s':'' ?></div>
  <?php foreach ($members as $m):
    $mc = chat_color($m['role'], $m['chat_color']);
    $rankColors = ['leader'=>'var(--neon2)','officer'=>'var(--accent)','member'=>'var(--muted)'];
    $rc = $rankColors[$m['rank']] ?? 'var(--muted)';
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04)">
    <div style="width:30px;height:30px;border-radius:7px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.12);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent);flex:none"><?= mb_strtoupper(mb_substr($m['username'],0,1)) ?></div>
    <div style="flex:1">
      <a href="index.php?p=profile&id=<?= (int)$m['player_id'] ?>" style="font-weight:700;color:<?= e($mc) ?>"><?= e($m['username']) ?></a>
      <span style="font-size:10px;color:var(--muted)"> Lv<?= (int)$m['level'] ?></span>
    </div>
    <span style="font-size:11px;font-weight:700;color:<?= $rc ?>"><?= ucfirst($m['rank']) ?></span>
    <?php if ($myRank === 'leader' && (int)$m['player_id'] !== $pid): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="setrole">
      <input type="hidden" name="target_id" value="<?= (int)$m['player_id'] ?>">
      <select name="new_rank" style="font-size:11px;padding:2px 4px">
        <option value="member" <?= $m['rank']==='member'?'selected':'' ?>>Member</option>
        <option value="officer" <?= $m['rank']==='officer'?'selected':'' ?>>Officer</option>
      </select>
      <button type="submit" style="font-size:10px;padding:3px 8px">Set</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php // ── SEARCH ──
elseif ($tab === 'search'):
  $allSyns = [];
  try {
    $allSyns = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM syndicate_members sm WHERE sm.syndicate_id=s.id) AS members, p.username AS leader_name FROM syndicates s JOIN players p ON p.id=s.leader_id ORDER BY s.level DESC, s.xp DESC LIMIT 50")->fetchAll();
  } catch (Throwable $e) {}
?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($allSyns)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No Syndicates yet. Be the first to form one.</div>
  <?php else: ?>
  <div style="padding:8px 14px;border-bottom:1px solid var(--line);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px"><?= count($allSyns) ?> Syndicates</div>
  <?php foreach ($allSyns as $s):
    $isMember = $mySyn && (int)$mySyn['syndicate_id'] === (int)$s['id'];
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
      <?php if (!$mySyn && !$isMember): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="join">
        <input type="hidden" name="syn_id" value="<?= (int)$s['id'] ?>">
        <button type="submit" style="font-size:12px;padding:6px 16px">Join</button>
      </form>
      <?php elseif ($isMember): ?>
        <span style="font-size:11px;color:var(--accent)">&#10003; Member</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php // ── CREATE ──
elseif ($tab === 'create'):
?>
<div class="panel">
  <?php if ($mySyn): ?>
    <p style="color:var(--neon2)">You already belong to a Syndicate. Leave it first.</p>
  <?php else: ?>
  <h3 style="margin-top:0">Create a Syndicate</h3>
  <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:6px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:14px">
    &#9670; Costs <b style="color:var(--neon2)"><?= SYNDICATE_CREATE_COST ?> Shards</b> to create. You have <?= number_format($player['shards'] ?? 0) ?> Shards.
  </div>
  <form method="post">
    <input type="hidden" name="action" value="create">
    <div class="field"><span>Syndicate Name <span class="muted">(3–60 chars)</span></span><input type="text" name="syn_name" maxlength="60" placeholder="e.g. Iron Veil"></div>
    <div class="field" style="margin-top:8px"><span>Tag <span class="muted">(2–8 uppercase)</span></span><input type="text" name="syn_tag" maxlength="8" placeholder="e.g. IRON" style="text-transform:uppercase"></div>
    <div class="field" style="margin-top:8px"><span>Description <span class="muted">(optional)</span></span><textarea name="syn_desc" style="min-height:70px" maxlength="500"></textarea></div>
    <button type="submit" style="margin-top:10px" <?= ($player['shards'] ?? 0) < SYNDICATE_CREATE_COST ? 'disabled' : '' ?>>&#9760; Form Syndicate — <?= SYNDICATE_CREATE_COST ?> &#9670;</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
