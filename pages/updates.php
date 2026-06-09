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
        $pdo->prepare('INSERT INTO update_votes (update_id, player_id, value) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$uid, $pid, $dir]);
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

$rows = $pdo->query('SELECT u.id, u.body, u.credit, u.created_at,
                       (SELECT COALESCE(SUM(value),0) FROM update_votes v WHERE v.update_id = u.id) AS score
                     FROM updates u ORDER BY u.created_at DESC, u.id DESC
                     LIMIT ' . (int)UPDATES_PER_PAGE . ' OFFSET ' . (int)$off)->fetchAll();

function uvote($uid, $dir, $glyph) {
  return '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="vote">'
       . '<input type="hidden" name="update_id" value="' . (int)$uid . '">'
       . '<input type="hidden" name="dir" value="' . $dir . '"><button class="vote">' . $glyph . '</button></form>';
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
      <div class="upddate"><?= e($r['created_at']) ?></div>
      <div class="updbody">
        <?= bbcode($r['body']) ?>
        <?php if (trim($r['credit']) !== ''): ?><div class="muted" style="font-style:italic;font-size:11px">Thanks to <?= e($r['credit']) ?></div><?php endif; ?>
      </div>
      <div class="updvote">
        <?= uvote($r['id'], 'up', '&#9650;') ?>
        <b><?= (int)$r['score'] ?></b>
        <?= uvote($r['id'], 'down', '&#9660;') ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted" style="padding:14px">No updates yet.</p>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<p class="muted" style="text-align:center">
  <?php if ($pg > 1): ?><a href="index.php?p=updates&pg=<?= $pg - 1 ?>">&laquo; Prev</a> &nbsp;<?php endif; ?>
  Page <?= $pg ?> of <?= $pages ?>
  <?php if ($pg < $pages): ?>&nbsp; <a href="index.php?p=updates&pg=<?= $pg + 1 ?>">Next &raquo;</a><?php endif; ?>
</p>
<?php endif; ?>
