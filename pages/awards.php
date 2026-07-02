<?php /* pages/awards.php — The Grid Rankings */
$pdo = db();

$categories = [
  'xp'     => ['label' => 'Top XP',      'desc' => 'Total experience earned'],
  'level'  => ['label' => 'Level',        'desc' => 'Highest character level'],
  'wealth' => ['label' => 'Wealth',       'desc' => 'Combined pocket + bank creds'],
  'wins'   => ['label' => 'Combat Wins',  'desc' => 'Total combat victories'],
  'bank'   => ['label' => 'Banker',       'desc' => 'Largest bank balance'],
];

$tab = in_array($_GET['cat'] ?? '', array_keys($categories)) ? $_GET['cat'] : 'xp';

$rows = [];
try {
  if ($tab === 'xp') {
    $rows = $pdo->query("SELECT id, username, level, xp, role, chat_color FROM players ORDER BY xp DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$r) $r['_val'] = number_format($r['xp']) . ' XP';

  } elseif ($tab === 'level') {
    $rows = $pdo->query("SELECT id, username, level, xp, role, chat_color FROM players ORDER BY level DESC, xp DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$r) $r['_val'] = 'Lv ' . (int)$r['level'];

  } elseif ($tab === 'wealth') {
    $rows = $pdo->query("SELECT id, username, level, role, chat_color, (creds_pocket + creds_bank) AS total FROM players ORDER BY total DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$r) $r['_val'] = number_format($r['total']) . ' cr';

  } elseif ($tab === 'bank') {
    $rows = $pdo->query("SELECT id, username, level, role, chat_color, creds_bank AS total FROM players ORDER BY creds_bank DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$r) $r['_val'] = number_format($r['total']) . ' cr';

  } elseif ($tab === 'wins') {
    $rows = $pdo->query("SELECT p.id, p.username, p.level, p.role, p.chat_color, COUNT(c.id) AS wins
      FROM players p LEFT JOIN pvp_log c ON c.winner_id = p.id
      GROUP BY p.id ORDER BY wins DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$r) $r['_val'] = number_format($r['wins']) . ' W';
  }
  unset($r);
} catch (Throwable $e) { $rows = []; }

$myRank = null;
$myId = (int)$_SESSION['pid'];
foreach ($rows as $i => $r) {
  if ((int)$r['id'] === $myId) { $myRank = $i + 1; break; }
}

$medals = ['&#127945;','&#129352;','&#129353;'];
?>

<!-- Header -->
<?= scene_header('aw-canvas', '&#127942;', 'The Grid Rankings',
      'The Sprawl keeps score. How do you stack up?', 'podium', '#e8d44d') ?>
<?= scene_header_js() ?>

<!-- Category Tabs -->
<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center">
  <?php foreach ($categories as $id => $cat): ?>
  <a href="index.php?p=awards&cat=<?= $id ?>" style="padding:6px 16px;border-radius:20px;font-size:12px;font-family:'Orbitron',sans-serif;font-weight:600;text-decoration:none;border:1px solid <?= $tab===$id ? 'var(--accent)' : 'var(--line)' ?>;background:<?= $tab===$id ? 'rgba(25,240,199,.12)' : 'var(--panel2)' ?>;color:<?= $tab===$id ? 'var(--accent)' : 'var(--muted)' ?>;transition:all .15s">
    <?= e($cat['label']) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Category header -->
<div style="text-align:center">
  <div style="font-size:12px;color:var(--muted)"><?= e($categories[$tab]['desc']) ?>
    <?php if ($myRank): ?>
      &mdash; <span style="color:var(--accent)">You're ranked #<?= $myRank ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- Leaderboard -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="display:grid;grid-template-columns:52px 1fr 100px;padding:8px 16px;border-bottom:1px solid var(--line);font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:700">
    <span>Rank</span>
    <span>Ghost</span>
    <span style="text-align:right"><?= e($categories[$tab]['label']) ?></span>
  </div>

  <?php if (empty($rows)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">No data yet.</div>
  <?php else: ?>
    <?php foreach ($rows as $i => $r):
      $rank = $i + 1;
      $isMe = (int)$r['id'] === $myId;
      $col  = chat_color($r['role'] ?? 'member', '');
      $bg   = $isMe ? 'rgba(25,240,199,.04)' : ($rank % 2 === 0 ? 'var(--panel2)' : 'transparent');
    ?>
    <div style="display:grid;grid-template-columns:52px 1fr 100px;padding:8px 16px;border-bottom:1px solid rgba(255,255,255,.04);align-items:center;background:<?= $bg ?>;<?= $isMe ? 'border-left:3px solid var(--accent)' : '' ?>">
      <span style="font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:<?= $rank<=3 ? '#e8d44d' : 'var(--muted)' ?>">
        <?= isset($medals[$rank-1]) ? $medals[$rank-1] : '#' . $rank ?>
      </span>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:28px;height:28px;border-radius:6px;background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.15);display:flex;align-items:center;justify-content:center;font-family:'Orbitron',sans-serif;font-size:11px;font-weight:700;color:var(--accent);flex:none">
          <?= mb_strtoupper(mb_substr($r['username'],0,1)) ?>
        </div>
        <div>
          <a href="index.php?p=profile&id=<?= (int)$r['id'] ?>" style="font-weight:700;font-size:13px;color:<?= e($col) ?>;text-decoration:none"><?= e($r['username']) ?></a>
          <?php if ($isMe): ?><span style="font-size:10px;color:var(--accent);margin-left:4px">(you)</span><?php endif; ?>
          <div style="font-size:10px;color:var(--muted)">Lv <?= (int)$r['level'] ?></div>
        </div>
      </div>
      <div style="text-align:right;font-family:'Orbitron',sans-serif;font-size:12px;font-weight:700;color:<?= $rank<=3 ? '#e8d44d' : 'var(--text)' ?>"><?= e($r['_val']) ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
