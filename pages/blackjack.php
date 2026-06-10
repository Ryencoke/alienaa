<?php /* pages/blackjack.php — Lucky Daemon: Blackjack */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$MAXBET = 10000;

if (!function_exists('bj_value')) {
  function bj_value($hand) {
    $v = 0; $aces = 0;
    foreach ($hand as $c) {
      $r = $c[0];
      if ($r === 'A') { $aces++; $v += 11; }
      elseif ($r === 'K' || $r === 'Q' || $r === 'J') { $v += 10; }
      else { $v += (int)$r; }
    }
    while ($v > 21 && $aces > 0) { $v -= 10; $aces--; }
    return $v;
  }
  function bj_deck() {
    $d = [];
    foreach (['S','H','D','C'] as $s)
      foreach (['2','3','4','5','6','7','8','9','10','J','Q','K','A'] as $r) $d[] = [$r, $s];
    shuffle($d);
    return $d;
  }
  function bj_render($hand) { $o = ''; foreach ($hand as $c) $o .= card_svg($c[0], $c[1]); return $o; }
  function bj_resolve($pid, $pdo) {
    $bj =& $_SESSION['bj'];
    $bet = (int)$bj['bet'];
    $pv = bj_value($bj['player']); $dv = bj_value($bj['dealer']);
    $pBJ = (count($bj['player']) === 2 && $pv === 21);
    $dBJ = (count($bj['dealer']) === 2 && $dv === 21);
    $payout = 0;
    if     ($pv > 21)        $res = 'Bust! You lose ' . number_format($bet) . '.';
    elseif ($pBJ && $dBJ)  { $res = 'Push — both blackjack.'; $payout = $bet; }
    elseif ($pBJ)          { $res = 'Blackjack! Pays 3:2.';   $payout = (int)floor($bet * 2.5); }
    elseif ($dBJ)            $res = 'Dealer has blackjack. You lose.';
    elseif ($dv > 21)      { $res = 'Dealer busts. You win!'; $payout = $bet * 2; }
    elseif ($pv > $dv)     { $res = 'You win ' . number_format($bet) . '!'; $payout = $bet * 2; }
    elseif ($pv < $dv)       $res = 'Dealer wins. You lose ' . number_format($bet) . '.';
    else                   { $res = 'Push. Bet returned.';    $payout = $bet; }
    if ($payout > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$payout, $pid]);
    $bj['done'] = true; $bj['result'] = $res;
    try { $pdo->prepare('INSERT INTO casino_log (player_id, game, bet, detail, payout, net) VALUES (?,?,?,?,?,?)')
              ->execute([$pid, 'blackjack', $bet, "P{$pv}/D{$dv}", $payout, $payout - $bet]); } catch (Throwable $e) {}
  }
}

$bj =& $_SESSION['bj'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'deal') {
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)        throw new RuntimeException('Place a bet above zero.');
      if ($bet > $MAXBET)   throw new RuntimeException('That bet is too rich for this table.');
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$bet, $pid, $bet]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough creds in pocket.');
      $deck = bj_deck();
      $bj = ['deck' => $deck, 'player' => [array_pop($deck), array_pop($deck)],
             'dealer' => [array_pop($deck), array_pop($deck)], 'bet' => $bet, 'done' => false, 'result' => ''];
      $bj['deck'] = $deck;
      if (bj_value($bj['player']) === 21 || bj_value($bj['dealer']) === 21) bj_resolve($pid, $pdo);
    }
    elseif ($action === 'hit') {
      if (empty($bj) || $bj['done']) throw new RuntimeException('No hand in play.');
      $bj['player'][] = array_pop($bj['deck']);
      if (bj_value($bj['player']) > 21) bj_resolve($pid, $pdo);
    }
    elseif ($action === 'stand') {
      if (empty($bj) || $bj['done']) throw new RuntimeException('No hand in play.');
      while (bj_value($bj['dealer']) < 17) $bj['dealer'][] = array_pop($bj['deck']);
      bj_resolve($pid, $pdo);
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
  $player = current_player();
}
?>
<div class="panel">
  <h2>The Lucky Daemon &mdash; Blackjack</h2>
  <p class="muted"><a href="index.php?p=daemon">&laquo; back to the casino floor</a></p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Pocket: <b><?= number_format($player['creds_pocket']) ?></b> creds</p>

  <?php if (!empty($bj)): $done = $bj['done']; $pv = bj_value($bj['player']); ?>
    <div class="felt">
      <div class="label">Dealer<?php if ($done) echo ' &mdash; ' . bj_value($bj['dealer']); ?></div>
      <div><?php
        if ($done) echo bj_render($bj['dealer']);
        else echo card_svg($bj['dealer'][0][0], $bj['dealer'][0][1]) . card_back_svg();
      ?></div>
      <div class="label" style="margin-top:16px">You &mdash; <?= $pv ?></div>
      <div><?= bj_render($bj['player']) ?></div>
    </div>

    <?php if ($done): ?>
      <div class="flash" style="margin-top:10px"><?= e($bj['result']) ?></div>
      <form method="post">
        <input type="hidden" name="action" value="deal">
        <label>Bet</label>
        <p><input type="number" name="bet" min="1" value="<?= (int)$bj['bet'] ?>" max="<?= (int)$player['creds_pocket'] ?>"></p>
        <p><button type="submit">Deal Again</button></p>
      </form>
    <?php else: ?>
      <p style="margin-top:10px">
        <form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="hit"><button type="submit">Hit</button></form>
        <form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="stand"><button type="submit">Stand</button></form>
      </p>
    <?php endif; ?>
  <?php else: ?>
    <h3>New Hand</h3>
    <p class="muted">Blackjack pays 3:2. Dealer stands on 17. Get closer to 21 than the dealer without busting.</p>
    <form method="post">
      <input type="hidden" name="action" value="deal">
      <label>Bet</label>
      <p><input type="number" name="bet" min="1" value="10" max="<?= (int)$player['creds_pocket'] ?>"></p>
      <p><button type="submit">Deal</button></p>
    </form>
  <?php endif; ?>
</div>
