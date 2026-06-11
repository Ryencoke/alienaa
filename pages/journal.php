<?php /* pages/journal.php — view a player's public journal */
$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$pq = $pdo->prepare('SELECT id, username, role, chat_color, gender, country, mortality FROM players WHERE id = ?');
$pq->execute([$id]);
$jPlayer = $pq->fetch();

if (!$jPlayer) {
  echo '<div class="panel"><h2>Journal</h2><p class="muted">No such ghost on the Grid.</p></div>';
  return;
}

$journalText = '';
try {
  $jq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $jq->execute(['journal:'.$id]);
  $journalText = (string)($jq->fetchColumn() ?: '');
} catch (Throwable $e) {}

$col     = chat_color($jPlayer['role'], $jPlayer['chat_color'] ?? '');
$country = strtolower(trim($jPlayer['country'] ?? ''));
$isMe    = ((int)$jPlayer['id'] === (int)($_SESSION['pid'] ?? 0));
?>

<?= scene_header('jn-canvas', '&#128214;', 'Ghost Journal',
      'A public log, written in the first person. The Grid remembers everything.', 'ink', '#19f0c7') ?>
<?= scene_header_js() ?>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),transparent)"></div>
  <div style="padding:14px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-weight:700;font-size:15px;color:<?= e($col) ?>"><?= e($jPlayer['username']) ?></span>
        <?= flag_img($country) ?>
        <?= gender_icon($jPlayer['gender'] ?? '') ?>
        <?= mortality_icon((int)($jPlayer['mortality'] ?? 0)) ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px">&#128214; Personal Journal</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="index.php?p=profile&id=<?= $id ?>" style="padding:5px 14px;font-size:11px;border:1px solid var(--line);color:var(--muted);border-radius:5px;text-decoration:none;background:var(--panel2)">&#8592; Profile</a>
      <?php if ($isMe): ?>
      <a href="index.php?p=account&sec=journal" style="padding:5px 14px;font-size:11px;border:1px solid rgba(25,240,199,.3);color:var(--accent);border-radius:5px;text-decoration:none;background:rgba(25,240,199,.04)">&#9998; Edit</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel">
  <?php if ($journalText !== ''): ?>
  <div style="font-size:13px;line-height:1.7;color:var(--text);white-space:pre-wrap;word-break:break-word"><?= bbcode($journalText) ?></div>
  <?php else: ?>
  <div style="text-align:center;padding:30px 0;color:var(--muted)">
    <div style="font-size:32px;opacity:.2;margin-bottom:8px">&#128214;</div>
    <div style="font-size:13px">This ghost hasn't written anything yet.</div>
    <?php if ($isMe): ?>
    <a href="index.php?p=account&sec=journal" style="display:inline-block;margin-top:10px;font-size:12px;color:var(--accent);padding:6px 16px;border:1px solid rgba(25,240,199,.3);border-radius:5px;text-decoration:none">Start writing &rarr;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
