<?php
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// ensure player has skill rows
$pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points)
               SELECT ?, id, 0 FROM skills')->execute([$pid]);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $invest = $_POST['pts'] ?? [];   // [skill_id => points]
  $total = array_sum(array_map('intval',$invest));
  if ($total > 0 && $total <= $player['cycles']) {
    foreach ($invest as $sid=>$pts) {
      $pts=(int)$pts; if($pts<=0) continue;
      $pdo->prepare('UPDATE player_skills ps JOIN skills s ON s.id=ps.skill_id
                     SET ps.points = LEAST(s.max_pts, ps.points + ?)
                     WHERE ps.player_id=? AND ps.skill_id=?')
          ->execute([$pts,$pid,(int)$sid]);
    }
    $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id=?')
        ->execute([$total,$pid]);
    $msg = "Burned $total cycles into the Skillsoft Lab.";
    $player = current_player();
  } else { $msg='Not enough cycles for that.'; }
}

$rows = $pdo->prepare('SELECT s.id,s.name,s.max_pts,ps.points
                       FROM skills s JOIN player_skills ps
                       ON ps.skill_id=s.id AND ps.player_id=? ORDER BY s.name');
$rows->execute([$pid]);
?>
<div class="panel">
  <h2>Datacore: Skillsoft Lab</h2>
  <p class="muted">Jack a skillsoft into your cortex. Costs Cycles. The deeper you go, the more the Sprawl opens up.</p>
  <?php if($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Cycles available: <b><?= number_format($player['cycles']) ?></b></p>
  <form method="post">
    <table>
      <tr><th>Skillsoft</th><th>Loaded</th><th>Burn</th></tr>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= $r['points'] ?> / <?= $r['max_pts'] ?></td>
        <td><input type="number" name="pts[<?= $r['id'] ?>]" min="0" value="0" style="width:80px"></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p><button type="submit">Burn Cycles</button></p>
  </form>
</div>
