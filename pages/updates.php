<?php /* pages/updates.php — Game Updates (managers post, everyone votes) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$isManager = (($player['role'] ?? 'member') === 'manager');

const UPDATES_PER_PAGE = 25;
$pg = max(1, (int)($_GET['pg'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'post') {
      if (!$isManager) throw new RuntimeException('Only Managers can post updates.');
      $body   = trim($_POST['body'] ?? '');
      $credit = trim($_POST['credit'] ?? '');
      if ($body === '')               throw new RuntimeException('Write the update text.');
      if (mb_strlen($body) > 4000)    throw new RuntimeException('Update is too long.');
      if (mb_strlen($credit) > 64)    $credit = mb_substr($credit, 0, 64);
      $pdo->prepare('INSERT INTO updates (author_id, body, credit) VALUES (?,?,?)')
          ->execute([$pid, $body, $credit]);
      $msg = 'Update posted.';
      $pg = 1;
    }

    elseif ($action === 'vote') {
      $uid = (int)($_POST['update_id'] ?? 0);
      $dir = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;
      $chk = $pdo->prepare('SELECT 1 FROM updates WHERE id = ?'); $chk->execute([$uid]);
      if ($chk->fetchColumn()) {
        $cur = $pdo->prepare('SELECT value FROM update_votes WHERE update_id = ? AND player_id = ?');
        $cur->execute([$uid, $pid]);
        $curVal = (int)($cur->fetchColumn() ?: 0);
        $newVal = ($curVal === $dir) ? 0 : $dir;  // re-click same dir = cancel back to 0
        if ($newVal === 0) {
          $pdo->prepare('DELETE FROM update_votes WHERE update_id = ? AND player_id = ?')->execute([$uid, $pid]);
        } else {
          $pdo->prepare('INSERT INTO update_votes (update_id, player_id, value) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$uid, $pid, $newVal]);
        }
      }
    }

  } catch (Throwable $ex) {
    $msg = $ex->getMessage();
  }
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM updates')->fetchColumn();
$pages = max(1, (int)ceil($total / UPDATES_PER_PAGE));
$pg    = min($pg, $pages);
$off   = ($pg - 1) * UPDATES_PER_PAGE;

$stmt = $pdo->prepare('SELECT u.id, u.body, u.credit, u.created_at,
                       p.id AS posted_id, p.username AS posted_by, p.role AS posted_role, p.chat_color AS posted_color,
                       (SELECT COALESCE(SUM(value),0) FROM update_votes v WHERE v.update_id = u.id) AS score,
                       (SELECT COALESCE(value,0) FROM update_votes v WHERE v.update_id = u.id AND v.player_id = ?) AS my_vote
                     FROM updates u LEFT JOIN players p ON p.id = u.author_id
                     ORDER BY u.created_at DESC, u.id DESC
                     LIMIT ' . (int)UPDATES_PER_PAGE . ' OFFSET ' . (int)$off);
$stmt->execute([$pid]);
$rows = $stmt->fetchAll();

function uvote($uid, $dir, $glyph, $myVote) {
  $active = ($myVote == ($dir === 'up' ? 1 : -1)) ? ' vote-active' : '';
  return '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="vote">'
       . '<input type="hidden" name="update_id" value="' . (int)$uid . '">'
       . '<input type="hidden" name="dir" value="' . $dir . '"><button class="vote' . $active . '">' . $glyph . '</button></form>';
}
?>
<div class="panel">
  <h2>Game Updates</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">Patch notes from the people who keep the Sprawl running.</p>
  <?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>

  <?php if ($isManager): ?>
  <form method="post" style="border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:6px">
    <input type="hidden" name="action" value="post">
    <div class="field">
      <span>New update</span>
      <textarea name="body" maxlength="4000" style="min-height:80px"></textarea>
    </div>
    <div class="field" style="max-width:280px">
      <span>Thanks to <span class="muted" style="text-transform:none;letter-spacing:0">(optional handle)</span></span>
      <input type="text" name="credit" maxlength="64">
    </div>
    <button type="submit">Post Update</button>
  </form>
  <?php endif; ?>
</div>

<div class="panel" style="padding:0">
  <?php if ($rows): ?>
    <?php foreach ($rows as $r): ?>
    <div class="updrow">
      <div class="upddate">
        <?php $uts = strtotime($r['created_at']); ?>
        <div><?= date('M j, Y', $uts) ?></div>
        <div style="font-size:10px;font-weight:400;color:var(--muted);margin-top:2px"><?= date('g:i a', $uts) ?></div>
      </div>
      <div class="updbody">
        <?= bbcode($r['body']) ?>
        <?php $puColor = chat_color($r['posted_role'] ?? '', $r['posted_color'] ?? ''); ?>
        <div style="font-size:11px;color:var(--muted);margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
          <span>&#128100; Posted by
            <?php if ($r['posted_id']): ?>
              <a href="index.php?p=profile&id=<?= (int)$r['posted_id'] ?>" style="color:<?= e($puColor) ?>;font-weight:700;text-decoration:none"><?= e($r['posted_by'] ?? 'Staff') ?></a>
              <?= role_tag($r['posted_role'] ?? '', 'font-size:10px;margin-left:3px') ?>
            <?php else: ?>
              <b style="color:var(--accent)">Staff</b>
            <?php endif; ?>
          </span>
          <?php if (trim($r['credit']) !== ''): ?><span style="font-style:italic">&#128172; Thanks to <?= e($r['credit']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="updvote">
        <?= uvote($r['id'], 'up', '&#9650;', (int)$r['my_vote']) ?>
        <b><?= (int)$r['score'] ?></b>
        <?= uvote($r['id'], 'down', '&#9660;', (int)$r['my_vote']) ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted" style="padding:14px">No updates yet.</p>
  <?php endif; ?>
</div>

<?php if ($pages > 1):
  $base = 'index.php?p=updates';
  echo '<div class="pager">';
  if ($pg > 1) echo '<a href="'.$base.'&pg='.($pg-1).'">&lsaquo;</a>';
  $start = max(1,$pg-2); $end = min($pages,$pg+2);
  if ($start>1){echo '<a href="'.$base.'&pg=1">1</a>';if($start>2)echo '<span class="dots">&hellip;</span>';}
  for($i=$start;$i<=$end;$i++) echo $i===$pg?'<span class="cur">'.$i.'</span>':'<a href="'.$base.'&pg='.$i.'">'.$i.'</a>';
  if($end<$pages){if($end<$pages-1)echo '<span class="dots">&hellip;</span>';echo '<a href="'.$base.'&pg='.$pages.'">'.$pages.'</a>';}
  if ($pg < $pages) echo '<a href="'.$base.'&pg='.($pg+1).'">&rsaquo;</a>';
  echo '</div>';
endif; ?>
