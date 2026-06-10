<?php /* pages/poker.php — Lucky Daemon: Video Poker (Jacks or Better) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$MAXBET = 10000;

if (!function_exists('vp_eval')) {
  function vp_rankval($r) { $m = ['J'=>11,'Q'=>12,'K'=>13,'A'=>14]; return $m[$r] ?? (int)$r; }
  function vp_deck() {
    $d = [];
    foreach (['S','H','D','C'] as $s)
      foreach (['2','3','4','5','6','7','8','9','10','J','Q','K','A'] as $r) $d[] = [$r, $s];
    shuffle($d);
    return $d;
  }
  // Returns [hand name, payout multiplier of the bet].
  function vp_eval($hand) {
    $vals = []; $suits = [];
    foreach ($hand as $c) { $vals[] = vp_rankval($c[0]); $suits[] = $c[1]; }
    sort($vals);
    $flush = count(array_unique($suits)) === 1;
    $straight = false; $high = 0;
    if (count(array_unique($vals)) === 5) {
      if ($vals[4] - $vals[0] === 4) { $straight = true; $high = $vals[4]; }
      elseif ($vals === [2,3,4,5,14]) { $straight = true; $high = 5; }   // A-2-3-4-5
    }
    $counts = array_count_values($vals); arsort($counts); $cv = array_values($counts);
    if ($flush && $straight && $high === 14) return ['Royal Flush', 250];
    if ($flush && $straight)                 return ['Straight Flush', 50];
    if ($cv[0] === 4)                        return ['Four of a Kind', 25];
    if ($cv[0] === 3 && ($cv[1] ?? 0) === 2) return ['Full House', 9];
    if ($flush)                              return ['Flush', 6];
    if ($straight)                           return ['Straight', 4];
    if ($cv[0] === 3)                        return ['Three of a Kind', 3];
    if ($cv[0] === 2 && ($cv[1] ?? 0) === 2) return ['Two Pair', 2];
    if ($cv[0] === 2) { $pr = array_search(2, $counts); return $pr >= 11 ? ['Jacks or Better', 1] : ['Low pair', 0]; }
    return ['Nothing', 0];
  }
}

$vp =& $_SESSION['vp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'deal') {
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
      if ($bet > $MAXBET) throw new RuntimeException('That bet is too rich for this machine.');
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$bet, $pid, $bet]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough creds in pocket.');
      $deck = vp_deck();
      $hand = [array_pop($deck), array_pop($deck), array_pop($deck), array_pop($deck), array_pop($deck)];
      $vp = ['deck' => $deck, 'hand' => $hand, 'bet' => $bet, 'phase' => 'draw', 'result' => '', 'held' => [false,false,false,false,false]];
    }
    elseif ($action === 'draw') {
      if (empty($vp) || $vp['phase'] !== 'draw') throw new RuntimeException('No hand to draw.');
      for ($i = 0; $i < 5; $i++) {
        $held = isset($_POST['hold'][$i]);
        $vp['held'][$i] = $held;
        if (!$held) $vp['hand'][$i] = array_pop($vp['deck']);
      }
      [$name, $mult] = vp_eval($vp['hand']);
      $payout = $vp['bet'] * $mult;
      if ($payout > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$payout, $pid]);
      $vp['phase'] = 'done';
      $vp['result'] = $mult > 0
        ? "$name — " . ($mult === 1 ? 'push, bet returned.' : 'won ' . number_format($payout - $vp['bet']) . ' creds!')
        : "$name. You lose " . number_format($vp['bet']) . '.';
      try { $pdo->prepare('INSERT INTO casino_log (player_id, game, bet, detail, payout, net) VALUES (?,?,?,?,?,?)')
                ->execute([$pid, 'poker', $vp['bet'], $name, $payout, $payout - $vp['bet']]); } catch (Throwable $e) {}
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
  $player = current_player();
}
?>
<div class="panel">
  <h2>The Lucky Daemon &mdash; Video Poker</h2>
  <p class="muted"><a href="index.php?p=daemon">&laquo; back to the casino floor</a> &middot; Jacks or Better</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Pocket: <b><?= number_format($player['creds_pocket']) ?></b> creds</p>

  <?php if (!empty($vp)): $phase = $vp['phase']; ?>
    <div class="felt">
    <div class="label"><?= $phase === 'draw' ? 'Hold the cards you want, then draw' : 'Final hand' ?></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
    <?php for ($i = 0; $i < 5; $i++): $c = $vp['hand'][$i]; ?>
      <div style="text-align:center">
        <?= card_svg($c[0], $c[1]) ?>
        <?php if ($phase === 'draw'): ?>
          <div style="font-size:11px"><label><input type="checkbox" form="vpdraw" name="hold[<?= $i ?>]" value="1" style="width:auto"> Hold</label></div>
        <?php elseif (!empty($vp['held'][$i])): ?>
          <div class="muted" style="font-size:10px">held</div>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
    </div>
    </div>

    <?php if ($phase === 'draw'): ?>
      <form method="post" id="vpdraw">
        <input type="hidden" name="action" value="draw">
        <p class="muted">Tick the cards to keep, then draw new ones for the rest.</p>
        <button type="submit">Draw</button>
      </form>
    <?php else: ?>
      <div class="flash"><?= e($vp['result']) ?></div>
      <form method="post">
        <input type="hidden" name="action" value="deal">
        <label>Bet</label>
        <p><input type="number" name="bet" min="1" value="<?= (int)$vp['bet'] ?>" max="<?= (int)$player['creds_pocket'] ?>"></p>
        <p><button type="submit">Deal Again</button></p>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <h3>New Hand</h3>
    <p class="muted">Five cards dealt. Hold the ones you want, draw the rest. A pair of Jacks or better pays.</p>
    <form method="post">
      <input type="hidden" name="action" value="deal">
      <label>Bet</label>
      <p><input type="number" name="bet" min="1" value="10" max="<?= (int)$player['creds_pocket'] ?>"></p>
      <p><button type="submit">Deal</button></p>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Payouts <span class="muted">(× your bet)</span></h3>
  <table>
    <tr><th>Hand</th><th>Pays</th></tr>
    <tr><td>Royal Flush</td><td>250&times;</td></tr>
    <tr><td>Straight Flush</td><td>50&times;</td></tr>
    <tr><td>Four of a Kind</td><td>25&times;</td></tr>
    <tr><td>Full House</td><td>9&times;</td></tr>
    <tr><td>Flush</td><td>6&times;</td></tr>
    <tr><td>Straight</td><td>4&times;</td></tr>
    <tr><td>Three of a Kind</td><td>3&times;</td></tr>
    <tr><td>Two Pair</td><td>2&times;</td></tr>
    <tr><td>Jacks or Better</td><td>push (bet back)</td></tr>
  </table>
</div>
