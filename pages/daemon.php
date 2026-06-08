<?php /* pages/daemon.php — The Undervolt: The Lucky Daemon (casino) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

const MAX_BET = 1000000;   // sanity cap per play

// Deduct the stake up front (concurrency-safe). Returns true if the bet was covered.
function place_bet($pid, $bet) {
  $u = db()->prepare('UPDATE players SET creds_pocket = creds_pocket - ?
                      WHERE id = ? AND creds_pocket >= ?');
  $u->execute([$bet, $pid, $bet]);
  return $u->rowCount() === 1;
}

// Credit winnings + log the play.
function settle($pid, $game, $bet, $detail, $payout) {
  $pdo = db();
  if ($payout > 0) {
    $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')
        ->execute([$payout, $pid]);
  }
  $pdo->prepare('INSERT INTO casino_log (player_id, game, bet, detail, payout, net)
                 VALUES (?,?,?,?,?,?)')
      ->execute([$pid, $game, $bet, $detail, $payout, $payout - $bet]);
}

/* ---------- action handling (inline, no redirect) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    $bet = (int)($_POST['bet'] ?? 0);
    if ($bet <= 0)        throw new RuntimeException('Place a bet above zero.');
    if ($bet > MAX_BET)   throw new RuntimeException('The Daemon caps single bets at ' . number_format(MAX_BET) . ' creds.');

    if ($action === 'dice') {
      $choice = $_POST['choice'] ?? '';
      if (!in_array($choice, ['low','high','seven'], true)) throw new RuntimeException('Pick Low, High, or Seven.');

      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }

      $d1 = random_int(1, 6); $d2 = random_int(1, 6); $sum = $d1 + $d2;
      $band = $sum <= 6 ? 'low' : ($sum >= 8 ? 'high' : 'seven');
      $mult = ($choice === $band) ? ($band === 'seven' ? 5 : 2) : 0;
      $payout = $bet * $mult;

      settle($pid, 'dice', $bet, "rolled {$sum} (".ucfirst($band).")", $payout);
      $pdo->commit();

      $msg = "Dice: {$d1}+{$d2} = {$sum}. "
           . ($payout > 0
               ? "You called " . ucfirst($choice) . " — won " . number_format($payout - $bet) . " creds!"
               : "You called " . ucfirst($choice) . " — the Daemon keeps your " . number_format($bet) . ".");
    }

    elseif ($action === 'slots') {
      $symbols = ['$','#','%','*','7'];
      $r = [$symbols[random_int(0,4)], $symbols[random_int(0,4)], $symbols[random_int(0,4)]];

      if ($r[0] === $r[1] && $r[1] === $r[2]) {
        $mult = ($r[0] === '7') ? 25 : 5;
      } elseif ($r[0] === $r[1] || $r[1] === $r[2] || $r[0] === $r[2]) {
        $mult = 1; // pair -> push (bet returned)
      } else {
        $mult = 0;
      }
      $line = implode(' ', $r);

      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $payout = $bet * $mult;
      settle($pid, 'slots', $bet, $line, $payout);
      $pdo->commit();

      if     ($mult >= 25) $tag = "JACKPOT! +" . number_format($payout - $bet) . " creds!";
      elseif ($mult >= 5)  $tag = "Three of a kind! +" . number_format($payout - $bet) . " creds!";
      elseif ($mult === 1) $tag = "A pair — push, bet returned.";
      else                 $tag = "Nothing. The reels eat your " . number_format($bet) . ".";
      $msg = "Reels: [ {$line} ] — {$tag}";
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
  $player = current_player(); // refresh pocket balance
}

/* ---------- recent plays ---------- */
$rl = $pdo->prepare('SELECT * FROM casino_log WHERE player_id = ? ORDER BY played_at DESC LIMIT 8');
$rl->execute([$pid]);
$recent = $rl->fetchAll();
?>
<div class="panel">
  <h2>The Lucky Daemon <span class="muted">&mdash; The Undervolt</span></h2>
  <p class="muted">The house always wins. The house is also on fire. Place your bets accordingly.</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p>Pocket: <b><?= number_format($player['creds_pocket']) ?></b> creds</p>
</div>

<div class="panel">
  <h3>Daemon Dice</h3>
  <p class="muted">Two dice. Bet the sum lands Low (2&ndash;6), High (8&ndash;12), or exactly Seven.
     Low/High pay 1:1 &middot; Seven pays 4:1.</p>
  <form method="post">
    <input type="hidden" name="action" value="dice">
    <p><label>Bet</label><input type="number" name="bet" min="1" max="<?= (int)min(MAX_BET, $player['creds_pocket']) ?>" value="10"></p>
    <p><label>Call</label>
      <select name="choice" style="width:100%;background:#080812;border:1px solid var(--line);color:var(--text);padding:6px;border-radius:3px">
        <option value="low">Low (2&ndash;6) &mdash; pays 1:1</option>
        <option value="high">High (8&ndash;12) &mdash; pays 1:1</option>
        <option value="seven">Seven &mdash; pays 4:1</option>
      </select></p>
    <p><button type="submit">Roll</button></p>
  </form>
</div>

<div class="panel">
  <h3>Neon Reels</h3>
  <p class="muted">Three reels. Three 7s pay 25&times; &middot; any other three-of-a-kind pays 5&times; &middot;
     a pair returns your bet &middot; anything else, you lose.</p>
  <form method="post">
    <input type="hidden" name="action" value="slots">
    <p><label>Bet</label><input type="number" name="bet" min="1" max="<?= (int)min(MAX_BET, $player['creds_pocket']) ?>" value="10"></p>
    <p><button type="submit">Spin</button></p>
  </form>
</div>

<?php if ($recent): ?>
<div class="panel">
  <h3>Recent Plays</h3>
  <table>
    <tr><th>Game</th><th>Bet</th><th>Result</th><th>Net</th><th>When</th></tr>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td><?= e(ucfirst($r['game'])) ?></td>
      <td><?= number_format($r['bet']) ?></td>
      <td><?= e($r['detail']) ?></td>
      <td style="<?= $r['net'] >= 0 ? 'color:var(--neon)' : 'color:var(--neon2)' ?>">
        <?= $r['net'] >= 0 ? '+' : '' ?><?= number_format($r['net']) ?>
      </td>
      <td class="muted"><?= e($r['played_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
