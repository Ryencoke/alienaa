<?php /* pages/daemon.php — The Undervolt: The Lucky Daemon */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgType    = 'ok';
$slotReels  = null;
$diceResult = null;
$activeTab  = 'dice';

const MAX_BET = 1000000;

function place_bet($pid, $bet) {
  $u = db()->prepare('UPDATE players SET creds_pocket = creds_pocket - ?
                      WHERE id = ? AND creds_pocket >= ?');
  $u->execute([$bet, $pid, $bet]);
  return $u->rowCount() === 1;
}

function settle($pid, $game, $bet, $detail, $payout) {
  $pdo = db();
  if ($payout > 0)
    $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$payout, $pid]);
  $pdo->prepare('INSERT INTO casino_log (player_id, game, bet, detail, payout, net) VALUES (?,?,?,?,?,?)')
      ->execute([$pid, $game, $bet, $detail, $payout, $payout - $bet]);
}

function dice_face($n) { return ['','⚀','⚁','⚂','⚃','⚄','⚅'][$n] ?? '?'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action    = $_POST['action'] ?? '';
  $activeTab = $_POST['_tab']   ?? 'dice';
  try {
    $bet = (int)($_POST['bet'] ?? 0);
    if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
    if ($bet > MAX_BET) throw new RuntimeException('The Daemon caps single bets at '.number_format(MAX_BET).' creds.');

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
      $diceResult = ['d1'=>$d1,'d2'=>$d2,'sum'=>$sum,'band'=>$band,'choice'=>$choice];
      $net = $payout - $bet;
      if ($net > 0)       $msg = "You called ".ucfirst($choice)." — rolled {$sum}. Won +".number_format($net)." creds!";
      else                { $msg = "Rolled {$sum} (".ucfirst($band).") — you called ".ucfirst($choice).". The Daemon feeds."; $msgType = 'err'; }
    }

    elseif ($action === 'slots') {
      $symbols = ['🍒','🔔','💎','⚡','7️⃣'];
      $r = [$symbols[random_int(0,4)], $symbols[random_int(0,4)], $symbols[random_int(0,4)]];
      $slotReels = $r;
      if     ($r[0]===$r[1] && $r[1]===$r[2]) $mult = $r[0]==='7️⃣' ? 25 : 5;
      elseif ($r[0]===$r[1] || $r[1]===$r[2] || $r[0]===$r[2]) $mult = 1;
      else   $mult = 0;
      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $payout = $bet * $mult;
      settle($pid, 'slots', $bet, implode(' ', $r), $payout);
      $pdo->commit();
      $net = $payout - $bet;
      if      ($mult >= 25) { $msg = "⚡ JACKPOT! +".number_format($net)." creds!"; }
      elseif  ($mult >= 5)  { $msg = "Three of a kind! +".number_format($net)." creds!"; }
      elseif  ($mult === 1) { $msg = "Pair — push. Bet returned."; }
      else                  { $msg = "No match. The reels eat your ".number_format($bet)."."; $msgType = 'err'; }
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgType = 'err';
  }
  $player = current_player();
}

$rl = $pdo->prepare('SELECT * FROM casino_log WHERE player_id = ? ORDER BY played_at DESC LIMIT 10');
$rl->execute([$pid]);
$recent = $rl->fetchAll();

$quickBets  = [100, 500, 1000, 5000, 10000];
$maxBet     = min(MAX_BET, $player['creds_pocket']);
$prevChoice = ($_POST['action'] ?? '') === 'dice' ? ($_POST['choice'] ?? 'low') : 'low';
$defaultBet = (int)min(100, max(1, $maxBet));
?>

<div class="panel daemon-header">
  <div>
    <h2>&#127920; The Lucky Daemon</h2>
    <p class="muted" style="margin:0;font-size:12px">The house always wins. The house is also on fire.</p>
  </div>
  <div class="daemon-balance">
    <div class="daemon-balance-label">Pocket</div>
    <div class="daemon-balance-val"><?= number_format($player['creds_pocket']) ?></div>
    <div class="daemon-balance-unit">creds</div>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash flash-<?= $msgType ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div class="daemon-tabs">
  <button class="daemon-tab <?= $activeTab==='dice' ?'active':'' ?>" data-tab="dice">&#127922; Daemon Dice</button>
  <button class="daemon-tab <?= $activeTab==='slots'?'active':'' ?>" data-tab="slots">&#127920; Neon Reels</button>
  <button class="daemon-tab <?= $activeTab==='history'?'active':'' ?>" data-tab="history">&#128202; History</button>
</div>

<!-- ======= DICE ======= -->
<div class="game-pane <?= $activeTab==='dice'?'active':'' ?>" id="pane-dice">
  <div class="panel">
    <div class="felt">
      <div class="dice-display">
        <?php if ($diceResult): $won = $diceResult['choice']===$diceResult['band']; ?>
          <div class="dice-box <?= $won?'win':'lose' ?>"><?= dice_face($diceResult['d1']) ?></div>
          <span class="dice-plus">+</span>
          <div class="dice-box <?= $won?'win':'lose' ?>"><?= dice_face($diceResult['d2']) ?></div>
        <?php else: ?>
          <div class="dice-box idle">&#9779;</div>
          <span class="dice-plus">+</span>
          <div class="dice-box idle">&#9779;</div>
        <?php endif; ?>
      </div>
      <?php if ($diceResult): ?>
        <p class="dice-sum"><?= $diceResult['d1'] ?> + <?= $diceResult['d2'] ?> = <b><?= $diceResult['sum'] ?></b>
          <span class="muted">(<?= ucfirst($diceResult['band']) ?>)</span></p>
      <?php else: ?>
        <p class="dice-sum muted">Low &middot; Seven &middot; High</p>
      <?php endif; ?>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="dice">
      <input type="hidden" name="_tab" value="dice">
      <div class="dchoice-row">
        <label class="dchoice">
          <input type="radio" name="choice" value="low" <?= $prevChoice==='low'?'checked':'' ?>>
          <span>Low (2&ndash;6)</span><small>pays 2&times;</small>
        </label>
        <label class="dchoice">
          <input type="radio" name="choice" value="seven" <?= $prevChoice==='seven'?'checked':'' ?>>
          <span>Seven</span><small>pays 5&times;</small>
        </label>
        <label class="dchoice">
          <input type="radio" name="choice" value="high" <?= $prevChoice==='high'?'checked':'' ?>>
          <span>High (8&ndash;12)</span><small>pays 2&times;</small>
        </label>
      </div>
      <div class="quickbets">
        <?php foreach ($quickBets as $qb): ?>
          <button type="button" class="bet-chip" data-amt="<?= $qb ?>" <?= $qb > $maxBet ? 'disabled' : '' ?>><?= number_format($qb) ?></button>
        <?php endforeach; ?>
        <?php if ($maxBet > 0): ?><button type="button" class="bet-chip" data-amt="<?= $maxBet ?>">MAX</button><?php endif; ?>
      </div>
      <div class="bet-input-row">
        <span class="muted">Bet:</span>
        <input type="number" name="bet" class="bet-input" min="1" max="<?= (int)$maxBet ?>" value="<?= $defaultBet ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-block daemon-go-btn">&#127922; Roll the Dice</button>
    </form>
  </div>
</div>

<!-- ======= SLOTS ======= -->
<div class="game-pane <?= $activeTab==='slots'?'active':'' ?>" id="pane-slots">
  <div class="panel">
    <div class="felt">
      <div class="reel-display">
        <?php $display = $slotReels ?? ['&#127920;','&#127920;','&#127920;'];
              $isResult = (bool)$slotReels;
              foreach ($display as $sym): ?>
          <div class="reel-box <?= $isResult?($msgType==='ok'?'reel-win':'reel-lose'):'idle' ?>"><?= $sym ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="post" id="slots-form">
      <input type="hidden" name="action" value="slots">
      <input type="hidden" name="_tab" value="slots">
      <div class="quickbets">
        <?php foreach ($quickBets as $qb): ?>
          <button type="button" class="bet-chip" data-amt="<?= $qb ?>" <?= $qb > $maxBet ? 'disabled' : '' ?>><?= number_format($qb) ?></button>
        <?php endforeach; ?>
        <?php if ($maxBet > 0): ?><button type="button" class="bet-chip" data-amt="<?= $maxBet ?>">MAX</button><?php endif; ?>
      </div>
      <div class="bet-input-row">
        <span class="muted">Bet:</span>
        <input type="number" name="bet" class="bet-input" min="1" max="<?= (int)$maxBet ?>" value="<?= $defaultBet ?>">
      </div>
      <button type="submit" id="spin-btn" class="btn btn-primary btn-block daemon-go-btn">&#127920; Spin the Reels</button>
    </form>

    <div class="daemon-pays">
      <span>&#128142;&#128142;&#128142; &middot; &#128276;&#128276;&#128276; &middot; &#127826;&#127826;&#127826; = 5&times;</span>
      <span>7&#65039;&#8419;7&#65039;&#8419;7&#65039;&#8419; = 25&times;</span>
      <span>Any pair = push</span>
    </div>
  </div>
</div>

<!-- ======= HISTORY ======= -->
<div class="game-pane <?= $activeTab==='history'?'active':'' ?>" id="pane-history">
  <div class="panel">
    <h3>Recent Plays</h3>
    <?php if ($recent): ?>
    <table>
      <tr><th>Game</th><th>Bet</th><th>Result</th><th>Net</th><th>When</th></tr>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e(ucfirst($r['game'])) ?></td>
        <td><?= number_format($r['bet']) ?></td>
        <td><?= e($r['detail']) ?></td>
        <td class="<?= $r['net'] >= 0 ? 'net-win' : 'net-lose' ?>">
          <?= $r['net'] >= 0 ? '+' : '' ?><?= number_format($r['net']) ?>
        </td>
        <td class="muted" style="font-size:11px"><?= e($r['played_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?><p class="muted">No plays yet &mdash; time to test your luck.</p><?php endif; ?>
  </div>
</div>

<script>
(function(){
  /* Tab switching */
  var tabs  = document.querySelectorAll('.daemon-tab');
  var panes = document.querySelectorAll('.game-pane');
  tabs.forEach(function(btn){
    btn.addEventListener('click', function(){
      var t = btn.dataset.tab;
      tabs.forEach(function(b){ b.classList.toggle('active', b.dataset.tab===t); });
      panes.forEach(function(p){ p.classList.toggle('active', p.id==='pane-'+t); });
    });
  });

  /* Quick-bet chips */
  document.querySelectorAll('.bet-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      var input = chip.closest('form').querySelector('input[name="bet"]');
      if (input) input.value = chip.dataset.amt;
      chip.closest('.quickbets').querySelectorAll('.bet-chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
    });
  });

  /* Slots spin animation while AJAX is in flight */
  var sf = document.getElementById('slots-form');
  if (sf) {
    sf.addEventListener('submit', function(){
      document.querySelectorAll('.reel-box').forEach(function(r){ r.classList.add('spinning'); r.classList.remove('idle'); });
      var btn = document.getElementById('spin-btn');
      if (btn){ btn.disabled = true; btn.textContent = 'Spinning...'; }
    });
  }
})();
</script>
