<?php /* pages/boards.php — Message Boards: categories -> boards -> topics -> posts */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

const TOPICS_PER_PAGE = 20;
const POSTS_PER_PAGE  = 15;
const BODY_MAX        = 8000;

$bid = (int)($_GET['b'] ?? 0);   // viewing a board
$tid = (int)($_GET['t'] ?? 0);   // viewing a topic
$pg  = max(1, (int)($_GET['pg'] ?? 1));
$jumpLast = false;

function pager($base, $pg, $pages) {
  if ($pages <= 1) return '';
  $o = '<p class="muted" style="text-align:center">';
  if ($pg > 1)      $o .= '<a href="' . $base . '&pg=' . ($pg - 1) . '">&laquo; Prev</a> &nbsp; ';
  $o .= "Page {$pg} of {$pages}";
  if ($pg < $pages) $o .= ' &nbsp; <a href="' . $base . '&pg=' . ($pg + 1) . '">Next &raquo;</a>';
  return $o . '</p>';
}

/* ---------- POST: new topic / reply (inline, no redirect) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'new_topic') {
      $board = (int)($_POST['board_id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $body  = trim($_POST['body'] ?? '');
      $chk = $pdo->prepare('SELECT 1 FROM boards WHERE id = ?'); $chk->execute([$board]);
      if (!$chk->fetchColumn())             throw new RuntimeException('That board does not exist.');
      if ($title === '' || mb_strlen($title) > 160) throw new RuntimeException('Title must be 1-160 characters.');
      if ($body === '')                     throw new RuntimeException('Write something in the body.');
      if (mb_strlen($body) > BODY_MAX)      throw new RuntimeException('Body is too long (' . BODY_MAX . ' max).');

      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO topics (board_id, author_id, title, created_at, last_post_at)
                     VALUES (?,?,?,NOW(),NOW())')->execute([$board, $pid, $title]);
      $tid = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO posts (topic_id, author_id, body, created_at)
                     VALUES (?,?,?,NOW())')->execute([$tid, $pid, $body]);
      $pdo->commit();
      $msg = 'Topic posted.';
      $pg = 1;
    }

    elseif ($action === 'reply') {
      $topic = (int)($_POST['topic_id'] ?? 0);
      $body  = trim($_POST['body'] ?? '');
      $chk = $pdo->prepare('SELECT 1 FROM topics WHERE id = ?'); $chk->execute([$topic]);
      if (!$chk->fetchColumn())            throw new RuntimeException('That topic is gone.');
      if ($body === '')                    throw new RuntimeException('Write something first.');
      if (mb_strlen($body) > BODY_MAX)     throw new RuntimeException('Reply is too long (' . BODY_MAX . ' max).');

      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO posts (topic_id, author_id, body, created_at)
                     VALUES (?,?,?,NOW())')->execute([$topic, $pid, $body]);
      $pdo->prepare('UPDATE topics SET last_post_at = NOW() WHERE id = ?')->execute([$topic]);
      $pdo->commit();
      $tid = $topic; $msg = 'Reply posted.'; $jumpLast = true;
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$flash = $msg ? '<div class="flash">' . e($msg) . '</div>' : '';

/* ============================ TOPIC VIEW ============================ */
if ($tid) {
  $tq = $pdo->prepare('SELECT t.id, t.title, t.board_id, b.name AS board_name, pl.username AS author
                       FROM topics t JOIN boards b ON b.id = t.board_id
                       JOIN players pl ON pl.id = t.author_id WHERE t.id = ?');
  $tq->execute([$tid]);
  $topic = $tq->fetch();

  if (!$topic) {
    echo '<div class="panel"><h2>Message Boards</h2>' . $flash
       . '<p class="muted">That topic doesn\'t exist. <a href="index.php?p=boards">Back to the boards.</a></p></div>';
    return;
  }

  $total = (int)$pdo->query('SELECT COUNT(*) FROM posts WHERE topic_id = ' . (int)$tid)->fetchColumn();
  $pages = max(1, (int)ceil($total / POSTS_PER_PAGE));
  if ($jumpLast) $pg = $pages;
  $pg = min($pg, $pages);
  $off = ($pg - 1) * POSTS_PER_PAGE;

  $pq = $pdo->prepare('SELECT p.body, p.created_at, pl.username AS author, pl.level AS lvl
                       FROM posts p JOIN players pl ON pl.id = p.author_id
                       WHERE p.topic_id = ? ORDER BY p.created_at ASC, p.id ASC
                       LIMIT ' . (int)POSTS_PER_PAGE . ' OFFSET ' . (int)$off);
  $pq->execute([$tid]);
  $rows = $pq->fetchAll();
  $base = 'index.php?p=boards&t=' . (int)$tid;
  ?>
  <div class="panel">
    <h2><?= e($topic['title']) ?></h2>
    <p class="muted"><a href="index.php?p=boards">Message Boards</a> &raquo;
      <a href="index.php?p=boards&b=<?= (int)$topic['board_id'] ?>"><?= e($topic['board_name']) ?></a></p>
    <?= $flash ?>
  </div>

  <?= pager($base, $pg, $pages) ?>

  <?php foreach ($rows as $r): ?>
  <div class="panel">
    <p class="muted" style="border-bottom:1px solid var(--line);padding-bottom:6px;margin-top:0">
      <b style="color:var(--neon)"><?= e($r['author']) ?></b>
      &middot; Lv <?= (int)$r['lvl'] ?> &middot; <?= e($r['created_at']) ?>
    </p>
    <div><?= nl2br(e($r['body'])) ?></div>
  </div>
  <?php endforeach; ?>

  <?= pager($base, $pg, $pages) ?>

  <div class="panel">
    <h3>Post a Reply</h3>
    <form method="post">
      <input type="hidden" name="action" value="reply">
      <input type="hidden" name="topic_id" value="<?= (int)$tid ?>">
      <p><textarea name="body" maxlength="<?= BODY_MAX ?>"
         style="width:100%;min-height:120px;background:#080812;border:1px solid var(--line);color:var(--text);padding:6px;border-radius:3px"></textarea></p>
      <p><button type="submit">Transmit</button></p>
    </form>
  </div>
  <?php
  return;
}

/* ============================ BOARD VIEW ============================ */
if ($bid) {
  $bq = $pdo->prepare('SELECT id, name, descr FROM boards WHERE id = ?');
  $bq->execute([$bid]);
  $board = $bq->fetch();

  if (!$board) {
    echo '<div class="panel"><h2>Message Boards</h2>' . $flash
       . '<p class="muted">No such board. <a href="index.php?p=boards">Back to the boards.</a></p></div>';
    return;
  }

  $total = (int)$pdo->query('SELECT COUNT(*) FROM topics WHERE board_id = ' . (int)$bid)->fetchColumn();
  $pages = max(1, (int)ceil($total / TOPICS_PER_PAGE));
  $pg = min($pg, $pages);
  $off = ($pg - 1) * TOPICS_PER_PAGE;

  $tq = $pdo->prepare('SELECT t.id, t.title, t.last_post_at, pl.username AS author,
                         (SELECT COUNT(*) - 1 FROM posts p WHERE p.topic_id = t.id) AS replies
                       FROM topics t JOIN players pl ON pl.id = t.author_id
                       WHERE t.board_id = ? ORDER BY t.last_post_at DESC
                       LIMIT ' . (int)TOPICS_PER_PAGE . ' OFFSET ' . (int)$off);
  $tq->execute([$bid]);
  $topics = $tq->fetchAll();
  $base = 'index.php?p=boards&b=' . (int)$bid;
  ?>
  <div class="panel">
    <h2><?= e($board['name']) ?></h2>
    <p class="muted"><a href="index.php?p=boards">Message Boards</a> &raquo; <?= e($board['descr']) ?></p>
    <?= $flash ?>
  </div>

  <div class="panel">
    <?php if ($topics): ?>
    <table>
      <tr><th>Topic</th><th>Posted By</th><th>Replies</th><th>Last Post</th></tr>
      <?php foreach ($topics as $t): ?>
      <tr>
        <td><a href="index.php?p=boards&t=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a></td>
        <td><?= e($t['author']) ?></td>
        <td><?= (int)$t['replies'] ?></td>
        <td class="muted"><?= e($t['last_post_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
      <p class="muted">No topics here yet. Be the first ghost to break the silence.</p>
    <?php endif; ?>
  </div>

  <?= pager($base, $pg, $pages) ?>

  <div class="panel">
    <h3>New Topic</h3>
    <form method="post">
      <input type="hidden" name="action" value="new_topic">
      <input type="hidden" name="board_id" value="<?= (int)$bid ?>">
      <p><label>Title</label><input type="text" name="title" maxlength="160"></p>
      <p><label>Body</label><textarea name="body" maxlength="<?= BODY_MAX ?>"
         style="width:100%;min-height:140px;background:#080812;border:1px solid var(--line);color:var(--text);padding:6px;border-radius:3px"></textarea></p>
      <p><button type="submit">Post Topic</button></p>
    </form>
  </div>
  <?php
  return;
}

/* ============================ INDEX VIEW ============================ */
$cats = $pdo->query('SELECT id, name FROM board_cats ORDER BY sort, id')->fetchAll();
$boards = $pdo->query(
  'SELECT b.id, b.cat_id, b.name, b.descr,
     (SELECT COUNT(*) FROM topics t WHERE t.board_id = b.id) AS topics,
     (SELECT COUNT(*) FROM posts p JOIN topics t ON t.id = p.topic_id WHERE t.board_id = b.id) AS posts
   FROM boards b ORDER BY b.sort, b.id')->fetchAll();

$byCat = [];
foreach ($boards as $b) $byCat[$b['cat_id']][] = $b;
?>
<div class="panel">
  <h2>Message Boards</h2>
  <p class="muted">The Sprawl talks. Some of it's even true.</p>
  <?= $flash ?>
</div>

<?php foreach ($cats as $c): ?>
<div class="panel">
  <h3><?= e($c['name']) ?></h3>
  <table>
    <tr><th>Board</th><th>Topics</th><th>Posts</th></tr>
    <?php foreach ($byCat[$c['id']] ?? [] as $b): ?>
    <tr>
      <td>
        <a href="index.php?p=boards&b=<?= (int)$b['id'] ?>"><?= e($b['name']) ?></a><br>
        <span class="muted" style="font-size:11px"><?= e($b['descr']) ?></span>
      </td>
      <td><?= number_format($b['topics']) ?></td>
      <td><?= number_format($b['posts']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endforeach; ?>
