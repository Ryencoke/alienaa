<?php /* pages/boards.php — Message Boards: categories -> boards -> threaded topics */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

const TOPICS_PER_PAGE = 20;
const BODY_MAX        = 8000;

$bid = (int)($_GET['b'] ?? 0);   // viewing a board
$tid = (int)($_GET['t'] ?? 0);   // viewing a topic
$pg  = max(1, (int)($_GET['pg'] ?? 1));

function pager($base, $pg, $pages) {
  if ($pages <= 1) return '';
  $o = '<p class="muted" style="text-align:center">';
  if ($pg > 1)      $o .= '<a href="' . $base . '&pg=' . ($pg - 1) . '">&laquo; Prev</a> &nbsp; ';
  $o .= "Page {$pg} of {$pages}";
  if ($pg < $pages) $o .= ' &nbsp; <a href="' . $base . '&pg=' . ($pg + 1) . '">Next &raquo;</a>';
  return $o . '</p>';
}

// Vote arrows for a post (two tiny POST forms).
function votebox_html($postId, $score) {
  $o  = '<div class="votebox">';
  $o .= '<form method="post" style="margin:0"><input type="hidden" name="action" value="vote">'
      . '<input type="hidden" name="post_id" value="' . (int)$postId . '">'
      . '<input type="hidden" name="dir" value="up"><button class="vote" title="up">&#9650;</button></form>';
  $o .= '<div class="score">' . (int)$score . '</div>';
  $o .= '<form method="post" style="margin:0"><input type="hidden" name="action" value="vote">'
      . '<input type="hidden" name="post_id" value="' . (int)$postId . '">'
      . '<input type="hidden" name="dir" value="down"><button class="vote" title="down">&#9660;</button></form>';
  $o .= '</div>';
  return $o;
}

// Recursively render a reply and its children.
if (!function_exists('render_post')) {
  function render_post($p, $children, $scores, $tid, $depth) {
    $col = chat_color($p['role'], $p['chat_color']);
    echo '<div class="post" style="margin-left:' . ($depth * 22) . 'px">';
    echo votebox_html($p['id'], $scores[$p['id']] ?? 0);
    echo '<div class="postbody"><div class="posthead">';
    echo '<a href="index.php?p=profile&id=' . (int)$p['author_id'] . '" style="color:' . e($col) . ';font-weight:bold">' . e($p['author']) . '</a>';
    if ($p['role'] !== 'member') echo ' <span class="muted">[' . e(role_label($p['role'])) . ']</span>';
    echo ' <span class="muted">' . e($p['created_at']) . '</span>';
    echo ' &middot; <a href="index.php?p=boards&t=' . (int)$tid . '&reply=' . (int)$p['id'] . '#replyform">Reply</a>';
    echo '</div><div>' . bbcode($p['body']) . '</div>';
    if (!empty($p['signature'])) echo '<div class="muted" style="border-top:1px solid var(--line);margin-top:6px;padding-top:4px;font-size:11px">' . bbcode($p['signature']) . '</div>';
    echo '</div></div>';
    if (!empty($children[$p['id']])) {
      foreach ($children[$p['id']] as $c) render_post($c, $children, $scores, $tid, $depth + 1);
    }
  }
}

/* ---------- POST: new topic / reply / vote ---------- */
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
      if (mb_strlen($body) > BODY_MAX)      throw new RuntimeException('Body is too long.');

      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO topics (board_id, author_id, title, created_at, last_post_at)
                     VALUES (?,?,?,NOW(),NOW())')->execute([$board, $pid, $title]);
      $tid = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO posts (topic_id, parent_id, author_id, body, created_at)
                     VALUES (?,NULL,?,?,NOW())')->execute([$tid, $pid, $body]);
      $pdo->commit();
      $msg = 'Topic posted.';
    }

    elseif ($action === 'reply') {
      $topic  = (int)($_POST['topic_id'] ?? 0);
      $parent = (int)($_POST['parent_id'] ?? 0);
      $body   = trim($_POST['body'] ?? '');
      $chk = $pdo->prepare('SELECT 1 FROM topics WHERE id = ?'); $chk->execute([$topic]);
      if (!$chk->fetchColumn())          throw new RuntimeException('That topic is gone.');
      if ($body === '')                  throw new RuntimeException('Write something first.');
      if (mb_strlen($body) > BODY_MAX)   throw new RuntimeException('Reply is too long.');
      // parent must belong to this topic, else treat as top-level
      if ($parent) {
        $pc = $pdo->prepare('SELECT 1 FROM posts WHERE id = ? AND topic_id = ?');
        $pc->execute([$parent, $topic]);
        if (!$pc->fetchColumn()) $parent = 0;
      }

      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO posts (topic_id, parent_id, author_id, body, created_at)
                     VALUES (?,?,?,?,NOW())')->execute([$topic, $parent ?: null, $pid, $body]);
      $pdo->prepare('UPDATE topics SET last_post_at = NOW() WHERE id = ?')->execute([$topic]);
      $pdo->commit();
      $tid = $topic; $msg = 'Reply posted.';
    }

    elseif ($action === 'vote') {
      $post_id = (int)($_POST['post_id'] ?? 0);
      $dir = ($_POST['dir'] ?? '') === 'down' ? -1 : 1;
      $chk = $pdo->prepare('SELECT topic_id FROM posts WHERE id = ?'); $chk->execute([$post_id]);
      $owner_topic = $chk->fetchColumn();
      if ($owner_topic) {
        $pdo->prepare('INSERT INTO post_votes (post_id, player_id, value) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$post_id, $pid, $dir]);
        $tid = (int)$owner_topic;
      }
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$flash = $msg ? '<div class="flash">' . e($msg) . '</div>' : '';

/* ============================ TOPIC VIEW ============================ */
if ($tid) {
  $tq = $pdo->prepare('SELECT t.id, t.title, t.board_id, t.views, b.name AS board_name
                       FROM topics t JOIN boards b ON b.id = t.board_id WHERE t.id = ?');
  $tq->execute([$tid]);
  $topic = $tq->fetch();
  if (!$topic) {
    echo '<div class="panel"><h2>Message Boards</h2>' . $flash
       . '<p class="muted">That topic doesn\'t exist. <a href="index.php?p=boards">Back to the boards.</a></p></div>';
    return;
  }
  $pdo->prepare('UPDATE topics SET views = views + 1 WHERE id = ?')->execute([$tid]);

  $pq = $pdo->prepare('SELECT p.id, p.parent_id, p.body, p.created_at, p.author_id,
                         pl.username AS author, pl.role, pl.chat_color, pl.signature
                       FROM posts p JOIN players pl ON pl.id = p.author_id
                       WHERE p.topic_id = ? ORDER BY p.id ASC');
  $pq->execute([$tid]);
  $posts = $pq->fetchAll();

  $scores = [];
  $sv = $pdo->prepare('SELECT pv.post_id, SUM(pv.value) s FROM post_votes pv
                       JOIN posts p ON p.id = pv.post_id WHERE p.topic_id = ? GROUP BY pv.post_id');
  $sv->execute([$tid]);
  foreach ($sv as $r) $scores[$r['post_id']] = (int)$r['s'];

  $op = $posts ? $posts[0] : null;
  $opId = $op ? $op['id'] : 0;
  $children = [];
  foreach ($posts as $i => $p) {
    if ($i === 0) continue;
    $par = $p['parent_id'] ? (int)$p['parent_id'] : $opId; // legacy NULL -> reply to OP
    $children[$par][] = $p;
  }

  // OP author post-count + reply target
  $opc = 0;
  if ($op) { $q = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?'); $q->execute([$op['author_id']]); $opc = (int)$q->fetchColumn(); }
  $replyTo = (int)($_GET['reply'] ?? 0);
  $replyParent = $opId; $replyToName = '';
  foreach ($posts as $p) { if ($p['id'] == $replyTo) { $replyParent = $replyTo; $replyToName = $p['author']; break; } }
  ?>
  <div class="panel">
    <h2><?= e($topic['title']) ?></h2>
    <p class="muted"><a href="index.php?p=boards">Message Boards</a> &raquo;
      <a href="index.php?p=boards&b=<?= (int)$topic['board_id'] ?>"><?= e($topic['board_name']) ?></a></p>
    <?= $flash ?>
    <?php if ($op): $col = chat_color($op['role'], $op['chat_color']); ?>
    <div class="post">
      <?= votebox_html($op['id'], $scores[$op['id']] ?? 0) ?>
      <div class="postbody">
        <div class="posthead">
          <a href="index.php?p=profile&id=<?= (int)$op['author_id'] ?>" style="color:<?= e($col) ?>;font-weight:bold"><?= e($op['author']) ?></a>
          <?php if ($op['role'] !== 'member'): ?> <span class="muted">[<?= e(role_label($op['role'])) ?>]</span><?php endif; ?>
          <span class="muted">&middot; #<?= (int)$op['id'] ?> &middot; <?= $opc ?> posts
            &middot; <?= e($op['created_at']) ?> &middot; <?= (int)$topic['views'] ?> views
            &middot; <a href="index.php?p=boards&t=<?= (int)$tid ?>&reply=<?= (int)$op['id'] ?>#replyform">Reply</a></span>
        </div>
        <div><?= bbcode($op['body']) ?></div>
        <?php if (!empty($op['signature'])): ?><div class="muted" style="border-top:1px solid var(--line);margin-top:6px;padding-top:4px;font-size:11px"><?= bbcode($op['signature']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="bar">Replies To This Message</div>
  <?php
    if (!empty($children[$opId])) { foreach ($children[$opId] as $c) render_post($c, $children, $scores, $tid, 0); }
    else { echo '<p class="muted">No replies yet.</p>'; }
  ?>

  <div class="panel" id="replyform">
    <h3><?= $replyToName ? 'Reply to ' . e($replyToName) : 'Post a Reply' ?></h3>
    <form method="post">
      <input type="hidden" name="action" value="reply">
      <input type="hidden" name="topic_id" value="<?= (int)$tid ?>">
      <input type="hidden" name="parent_id" value="<?= (int)$replyParent ?>">
      <p><textarea name="body" maxlength="<?= BODY_MAX ?>" style="min-height:110px"></textarea></p>
      <p class="muted" style="font-size:11px">Formatting: <code>[b]bold[/b] [i]italics[/i] [u]underline[/u]</code></p>
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

  $tq = $pdo->prepare('SELECT t.id, t.title, t.last_post_at, pl.username AS author, pl.id AS author_id,
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
        <td><a href="index.php?p=profile&id=<?= (int)$t['author_id'] ?>"><?= e($t['author']) ?></a></td>
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
      <p><label>Body</label><textarea name="body" maxlength="<?= BODY_MAX ?>" style="min-height:130px"></textarea></p>
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
