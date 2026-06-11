<?php /* pages/daemon.php — The Undervolt: The Lucky Daemon */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgType    = 'ok';
$slotReels  = null;
$diceResult = null;
$bjResult   = null;
$activeTab  = 'dice';

const MAX_BET = 1000000;

// ── Helpers ─────────────────────────────────────────────────────────────────
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

// Blackjack helpers
// Video Poker helpers
function vp_deck(): array {
  $ranks = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
  $suits = ['S','H','D','C'];
  $deck = [];
  foreach ($suits as $s) foreach ($ranks as $r) $deck[] = ['r'=>$r,'s'=>$s];
  shuffle($deck); return $deck;
}
function vp_evaluate(array $hand): array {
  $rankVals = ['2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'10'=>10,'J'=>11,'Q'=>12,'K'=>13,'A'=>14];
  $vals = array_map(fn($c) => $rankVals[$c['r']] ?? 0, $hand);
  $suits = array_column($hand, 's');
  sort($vals);
  $isFlush    = count(array_unique($suits)) === 1;
  $isStr      = ($vals[4]-$vals[0]===4 && count(array_unique($vals))===5) || ($vals===[2,3,4,5,14]);
  $counts     = array_count_values(array_column($hand,'r'));
  arsort($counts); $cv = array_values($counts);
  if ($isFlush && $isStr && $vals[0]===10)                       return ['Royal Flush', 800];
  if ($isFlush && $isStr)                                        return ['Straight Flush', 50];
  if ($cv[0]===4)                                                return ['Four of a Kind', 25];
  if ($cv[0]===3 && ($cv[1]??0)===2)                             return ['Full House', 9];
  if ($isFlush)                                                  return ['Flush', 6];
  if ($isStr)                                                    return ['Straight', 4];
  if ($cv[0]===3)                                                return ['Three of a Kind', 3];
  if ($cv[0]===2 && ($cv[1]??0)===2)                             return ['Two Pair', 2];
  if ($cv[0]===2) { foreach ($counts as $rk=>$c) { if ($c===2 && in_array($rk,['J','Q','K','A'],true)) return ['Jacks or Better', 1]; } }
  return ['No Win', 0];
}

function bj_hand_value(array $hand): int {
  $total = 0; $aces = 0;
  foreach ($hand as $c) {
    $r = $c['r'];
    if (in_array($r, ['J','Q','K'], true)) $total += 10;
    elseif ($r === 'A') { $total += 11; $aces++; }
    else $total += (int)$r;
  }
  while ($total > 21 && $aces > 0) { $total -= 10; $aces--; }
  return $total;
}
function bj_card(): array {
  $ranks = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
  $suits = ['♠','♥','♦','♣'];
  return ['r'=>$ranks[random_int(0,12)], 's'=>$suits[random_int(0,3)]];
}
function bj_render_card(array $c, bool $hidden = false): string {
  $red = in_array($c['s'], ['♥','♦'], true);
  $col = $hidden ? '#555' : ($red ? '#e23b3b' : '#ffffff');
  if ($hidden) return '<div class="bj-card bj-hidden">??</div>';
  return '<div class="bj-card" style="color:'.$col.'">' . e($c['r']) . '<span style="font-size:10px">' . e($c['s']) . '</span></div>';
}

// ── POST handling ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action    = $_POST['action'] ?? '';
  $activeTab = $_POST['_tab']   ?? 'dice';
  try {

    if ($action === 'dice') {
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
      if ($bet > MAX_BET) throw new RuntimeException('Max single bet is '.number_format(MAX_BET).'.');
      $choice = $_POST['choice'] ?? '';
      if (!in_array($choice, ['low','high','seven'], true)) throw new RuntimeException('Pick Low, High, or Seven.');
      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $d1 = random_int(1,6); $d2 = random_int(1,6); $sum = $d1 + $d2;
      $band = $sum <= 6 ? 'low' : ($sum >= 8 ? 'high' : 'seven');
      $mult = ($choice === $band) ? ($band === 'seven' ? 5 : 2) : 0;
      $payout = $bet * $mult;
      settle($pid, 'dice', $bet, "Rolled {$sum} (".ucfirst($band).")", $payout);
      $pdo->commit();
      $diceResult = ['d1'=>$d1,'d2'=>$d2,'sum'=>$sum,'band'=>$band,'choice'=>$choice];
      $net = $payout - $bet;
      if ($net > 0) $msg = "Rolled {$sum} — you called ".ucfirst($choice).". Won +".number_format($net)." creds!";
      else          { $msg = "Rolled {$sum} (".ucfirst($band).") — you called ".ucfirst($choice).". The Daemon feeds."; $msgType = 'err'; }
    }

    elseif ($action === 'slots') {
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
      if ($bet > MAX_BET) throw new RuntimeException('Max single bet is '.number_format(MAX_BET).'.');
      $symbols = ['🍒','🔔','💎','⚡','7️⃣'];
      $r = [$symbols[random_int(0,4)], $symbols[random_int(0,4)], $symbols[random_int(0,4)]];
      $slotReels = $r;
      if     ($r[0]===$r[1] && $r[1]===$r[2]) $mult = $r[0]==='7️⃣' ? 25 : 5;
      elseif ($r[0]===$r[1] || $r[1]===$r[2] || $r[0]===$r[2]) $mult = 1;
      else   $mult = 0;
      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $payout = $bet * $mult;
      settle($pid, 'slots', $bet, implode(' ',$r), $payout);
      $pdo->commit();
      $net = $payout - $bet;
      if      ($mult >= 25) { $msg = "⚡ JACKPOT! 7-7-7! +".number_format($net)." creds!"; }
      elseif  ($mult >= 5)  { $msg = "Three of a kind! +".number_format($net)." creds!"; }
      elseif  ($mult === 1) { $msg = "Pair — push. Bet returned."; }
      else                  { $msg = "No match. The reels eat your ".number_format($bet)."."; $msgType = 'err'; }
    }

    // ── Blackjack ──
    elseif ($action === 'bj_deal') {
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
      if ($bet > MAX_BET) throw new RuntimeException('Max single bet is '.number_format(MAX_BET).'.');
      if (!place_bet($pid, $bet)) throw new RuntimeException('Not enough creds in pocket.');
      $pHand = [bj_card(), bj_card()];
      $dHand = [bj_card(), bj_card()];
      $_SESSION['daemon_bj'] = ['phase'=>'playing','phand'=>$pHand,'dhand'=>$dHand,'bet'=>$bet];
      $pv = bj_hand_value($pHand);
      $dv = bj_hand_value($dHand);
      if ($pv === 21 || $dv === 21) {
        // Resolve naturals immediately: both = push, dealer only = loss, player only = 3:2
        $_SESSION['daemon_bj']['phase'] = 'done';
        if ($pv === 21 && $dv === 21) {
          $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$bet, $pid]);
          settle($pid, 'blackjack', $bet, 'Both natural — push', $bet);
          $msg = "Both drew natural 21 — push. Bet returned.";
        } elseif ($dv === 21) {
          settle($pid, 'blackjack', $bet, 'Dealer natural blackjack', 0);
          $msg = "Dealer flips a natural 21. The Daemon feeds."; $msgType = 'err';
        } else {
          $win = (int)round($bet * 1.5);
          $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$bet + $win, $pid]);
          settle($pid, 'blackjack', $bet, 'Natural Blackjack', $bet + $win);
          $msg = "&#127881; Blackjack! +".number_format($win)." creds!";
        }
        $activeTab = 'blackjack';
      } else {
        $msg = 'Cards dealt. Hit or Stand?'; $activeTab = 'blackjack';
      }
      $player = current_player();
    }

    elseif ($action === 'bj_hit') {
      $bj = $_SESSION['daemon_bj'] ?? null;
      if (!$bj || $bj['phase'] !== 'playing') throw new RuntimeException('No active hand.');
      $bj['phand'][] = bj_card();
      $pv = bj_hand_value($bj['phand']);
      if ($pv > 21) {
        $bj['phase'] = 'done';
        settle($pid, 'blackjack', $bj['bet'], 'Bust ('.implode(' ', array_map(fn($c)=>$c['r'].$c['s'], $bj['phand'])).')', 0);
        $msg = "Bust! You drew to {$pv}. The Daemon wins."; $msgType = 'err';
      } else {
        $msg = "Hit — your hand is now {$pv}.";
      }
      $_SESSION['daemon_bj'] = $bj; $activeTab = 'blackjack'; $player = current_player();
    }

    elseif ($action === 'bj_stand') {
      $bj = $_SESSION['daemon_bj'] ?? null;
      if (!$bj || $bj['phase'] !== 'playing') throw new RuntimeException('No active hand.');
      $dHand = $bj['dhand'];
      while (bj_hand_value($dHand) < 17) $dHand[] = bj_card();
      $bj['dhand'] = $dHand; $bj['phase'] = 'done';
      $pv = bj_hand_value($bj['phand']); $dv = bj_hand_value($dHand);
      $bet = $bj['bet'];
      $pCards = implode(' ', array_map(fn($c)=>$c['r'].$c['s'], $bj['phand']));
      $dCards = implode(' ', array_map(fn($c)=>$c['r'].$c['s'], $dHand));
      if ($dv > 21) {
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$bet * 2, $pid]);
        settle($pid, 'blackjack', $bet, "Dealer bust {$dv} | P:{$pCards} D:{$dCards}", $bet * 2);
        $msg = "Dealer busted at {$dv}! +".number_format($bet)." creds!";
      } elseif ($pv > $dv) {
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$bet * 2, $pid]);
        settle($pid, 'blackjack', $bet, "Win {$pv} vs {$dv} | P:{$pCards} D:{$dCards}", $bet * 2);
        $msg = "You win — {$pv} vs dealer's {$dv}! +".number_format($bet)." creds!";
      } elseif ($pv < $dv) {
        settle($pid, 'blackjack', $bet, "Lose {$pv} vs {$dv} | P:{$pCards} D:{$dCards}", 0);
        $msg = "Dealer wins — {$dv} vs your {$pv}. Better luck next hand."; $msgType = 'err';
      } else {
        $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$bet, $pid]);
        settle($pid, 'blackjack', $bet, "Push {$pv} | P:{$pCards} D:{$dCards}", $bet);
        $msg = "Push — {$pv} each. Bet returned.";
      }
      $_SESSION['daemon_bj'] = $bj; $activeTab = 'blackjack'; $player = current_player();
    }

    elseif ($action === 'bj_reset') {
      unset($_SESSION['daemon_bj']); $activeTab = 'blackjack';
    }

    // ── Video Poker ──
    elseif ($action === 'vp_deal') {
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
      if ($bet > MAX_BET) throw new RuntimeException('Max single bet is '.number_format(MAX_BET).'.');
      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $pdo->commit();
      $deck = vp_deck();
      $_SESSION['daemon_vp'] = ['hand'=>array_slice($deck,0,5),'deck'=>array_slice($deck,5),'bet'=>$bet,'phase'=>'hold'];
      $msg = 'Cards dealt — select which to hold, then Draw.'; $activeTab = 'vp';
      $player = current_player();
    }

    elseif ($action === 'vp_draw') {
      $vp = $_SESSION['daemon_vp'] ?? null;
      if (!$vp || $vp['phase'] !== 'hold') throw new RuntimeException('No active hand.');
      $holdKeys = array_map('intval', (array)($_POST['hold'] ?? []));
      $hand = $vp['hand']; $deck = $vp['deck']; $di = 0;
      for ($i = 0; $i < 5; $i++) { if (!in_array($i, $holdKeys, true)) $hand[$i] = $deck[$di++]; }
      [$hName, $mult] = vp_evaluate($hand);
      $bet = (int)$vp['bet']; $payout = $bet * $mult;
      settle($pid, 'video_poker', $bet, $hName, $payout);
      $net = $payout - $bet;
      if ($net > 0)      $msg = "&#9654; {$hName}! Won +".number_format($net)." creds!";
      elseif ($net === 0) $msg = "{$hName} — push. Bet returned.";
      else               { $msg = "{$hName}. The Daemon takes your ".number_format($bet)."."; $msgType = 'err'; }
      $_SESSION['daemon_vp'] = ['hand'=>$hand,'bet'=>$bet,'phase'=>'done','name'=>$hName,'mult'=>$mult,'net'=>$net];
      $activeTab = 'vp'; $player = current_player();
    }

    elseif ($action === 'vp_reset') {
      unset($_SESSION['daemon_vp']); $activeTab = 'vp';
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgType = 'err';
  }
}

// Active blackjack / video poker sessions
$bj = $_SESSION['daemon_bj'] ?? null;
$vp = $_SESSION['daemon_vp'] ?? null;

// History + stats
$rl = $pdo->prepare('SELECT * FROM casino_log WHERE player_id = ? ORDER BY played_at DESC LIMIT 50');
$rl->execute([$pid]);
$recent = $rl->fetchAll();

// Stats calculation (recent 50)
$statsTotal = count($recent);
$statWagered = array_sum(array_column($recent, 'bet'));
$statNet     = array_sum(array_column($recent, 'net'));
$statWins    = count(array_filter($recent, fn($r) => $r['net'] > 0));
$statWinRate = $statsTotal > 0 ? round($statWins / $statsTotal * 100, 1) : 0;
$statBigWin  = $statsTotal > 0 ? max(array_column($recent, 'net')) : 0;
$statBigLoss = $statsTotal > 0 ? min(array_column($recent, 'net')) : 0;

// True all-time stats
$atStats = ['games'=>0,'wagered'=>0,'net'=>0,'wins'=>0,'bigwin'=>0,'bigloss'=>0];
try {
  $ats = $pdo->prepare("SELECT COUNT(*) games, COALESCE(SUM(bet),0) wagered, COALESCE(SUM(net),0) net, SUM(net>0) wins, COALESCE(MAX(net),0) bigwin, COALESCE(MIN(net),0) bigloss FROM casino_log WHERE player_id=?");
  $ats->execute([$pid]); $atRow = $ats->fetch();
  if ($atRow) $atStats = ['games'=>(int)$atRow['games'],'wagered'=>(int)$atRow['wagered'],'net'=>(int)$atRow['net'],'wins'=>(int)$atRow['wins'],'bigwin'=>(int)$atRow['bigwin'],'bigloss'=>(int)$atRow['bigloss']];
} catch (Throwable $e) {}
$atWinRate = $atStats['games'] > 0 ? round($atStats['wins'] / $atStats['games'] * 100, 1) : 0;

$quickBets   = [100, 500, 1000, 5000, 10000];
$maxBet      = min(MAX_BET, $player['creds_pocket']);
$prevChoice  = ($_POST['action'] ?? '') === 'dice' ? ($_POST['choice'] ?? 'low') : 'low';
$defaultBet  = (int)min(100, max(1, $maxBet));
$bjBet       = $bj['bet'] ?? $defaultBet;
$gameIcons   = ['dice'=>'&#127922;', 'slots'=>'&#127920;', 'blackjack'=>'&#127921;', 'video_poker'=>'&#9830;'];
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

<?php if ($atStats['games'] > 0): ?>
<div class="panel" style="padding:12px 14px">
  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px">&#128202; All-Time Stats</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:6px">
    <?php foreach ([
      ['Games',       number_format($atStats['games']),  'var(--text)'],
      ['Wagered',     number_format($atStats['wagered']),'var(--muted)'],
      ['Net',         ($atStats['net']>=0?'+':'').number_format($atStats['net']), $atStats['net']>=0?'#3bcf63':'var(--neon2)'],
      ['Win Rate',    $atWinRate.'%', $atWinRate>=50?'#3bcf63':'var(--neon2)'],
      ['Best Win',    $atStats['bigwin']>0?'+'.number_format($atStats['bigwin']):'—', '#3bcf63'],
      ['Worst Loss',  $atStats['bigloss']<0?number_format($atStats['bigloss']):'—', 'var(--neon2)'],
    ] as [$lbl,$v,$c]): ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 8px;text-align:center">
      <div style="font-size:13px;font-weight:700;color:<?= $c ?>"><?= $v ?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="daemon-tabs">
  <button class="daemon-tab <?= $activeTab==='dice'       ?'active':'' ?>" data-tab="dice">&#127922; Daemon Dice</button>
  <button class="daemon-tab <?= $activeTab==='slots'      ?'active':'' ?>" data-tab="slots">&#127920; Neon Reels</button>
  <button class="daemon-tab <?= $activeTab==='blackjack'  ?'active':'' ?>" data-tab="blackjack">&#127921; Blackjack</button>
  <button class="daemon-tab <?= $activeTab==='vp'         ?'active':'' ?>" data-tab="vp">&#9830; Video Poker</button>
  <button class="daemon-tab <?= $activeTab==='history'    ?'active':'' ?>" data-tab="history">&#128202; History</button>
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
    <?php if ($msg && $activeTab === 'dice'): ?>
    <div class="daemon-result daemon-result-<?= $msgType ?>"><?= e($msg) ?></div>
    <?php endif; ?>

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
    <?php if ($msg && $activeTab === 'slots'): ?>
    <div class="daemon-result daemon-result-<?= $msgType ?>"><?= e($msg) ?></div>
    <?php endif; ?>

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

<!-- ======= BLACKJACK ======= -->
<div class="game-pane <?= $activeTab==='blackjack'?'active':'' ?>" id="pane-blackjack">
  <div class="panel">
    <?php if ($msg && $activeTab === 'blackjack'): ?>
    <div class="daemon-result daemon-result-<?= $msgType ?>"><?= e($msg) ?></div>
    <?php endif; ?>

    <?php if ($bj && $bj['phase'] !== 'done'): ?>
    <!-- Active hand -->
    <div class="felt">
      <div style="margin-bottom:14px">
        <div class="bj-label">Dealer&rsquo;s Hand <?= $bj['phase']==='done'?'('.bj_hand_value($bj['dhand']).')':'' ?></div>
        <div class="bj-hand">
          <?php foreach ($bj['dhand'] as $i => $c): ?>
            <?= bj_render_card($c, $bj['phase']==='playing' && $i===1) ?>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="bj-divider"></div>
      <div style="margin-top:14px">
        <div class="bj-label">Your Hand (<?= bj_hand_value($bj['phand']) ?>)
          <?php $pv = bj_hand_value($bj['phand']); if ($pv === 21): ?><span style="color:var(--accent)"> &#9733; 21!</span><?php endif; ?></div>
        <div class="bj-hand">
          <?php foreach ($bj['phand'] as $c): echo bj_render_card($c); endforeach; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:14px">
      <form method="post" style="margin:0"><input type="hidden" name="action" value="bj_hit"><input type="hidden" name="_tab" value="blackjack">
        <button type="submit" class="btn btn-primary" style="min-width:90px">Hit</button>
      </form>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="bj_stand"><input type="hidden" name="_tab" value="blackjack">
        <button type="submit" style="min-width:90px">Stand</button>
      </form>
    </div>
    <p class="muted" style="text-align:center;font-size:12px;margin-top:10px">Bet: <b style="color:var(--accent)"><?= number_format($bj['bet']) ?> creds</b></p>

    <?php elseif ($bj && $bj['phase'] === 'done'): ?>
    <!-- Show final state then deal-again -->
    <div class="felt">
      <div style="margin-bottom:14px">
        <div class="bj-label">Dealer&rsquo;s Hand (<?= bj_hand_value($bj['dhand']) ?>)</div>
        <div class="bj-hand"><?php foreach ($bj['dhand'] as $c): echo bj_render_card($c); endforeach; ?></div>
      </div>
      <div class="bj-divider"></div>
      <div style="margin-top:14px">
        <div class="bj-label">Your Hand (<?= bj_hand_value($bj['phand']) ?>)</div>
        <div class="bj-hand"><?php foreach ($bj['phand'] as $c): echo bj_render_card($c); endforeach; ?></div>
      </div>
    </div>
    <form method="post" style="text-align:center;margin-top:14px">
      <input type="hidden" name="action" value="bj_reset">
      <input type="hidden" name="_tab" value="blackjack">
      <button type="submit" class="btn btn-primary">&#127921; Deal Again</button>
    </form>

    <?php else: ?>
    <!-- Betting screen -->
    <div class="felt" style="text-align:center;padding:24px 16px">
      <p style="font-size:32px;margin-bottom:6px">&#127921;</p>
      <p class="muted">Get to 21 without going over. Beat the dealer.</p>
    </div>
    <form method="post" style="margin-top:14px">
      <input type="hidden" name="action" value="bj_deal">
      <input type="hidden" name="_tab" value="blackjack">
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
      <button type="submit" class="btn btn-primary btn-block daemon-go-btn">&#127921; Deal Cards</button>
    </form>
    <div class="daemon-pays">
      <span>Blackjack pays 3:2</span>
      <span>Dealer stands on 17</span>
      <span>Push returns bet</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ======= VIDEO POKER ======= -->
<?php
$vpPayTable = [['Royal Flush',800],['Straight Flush',50],['Four of a Kind',25],['Full House',9],['Flush',6],['Straight',4],['Three of a Kind',3],['Two Pair',2],['Jacks or Better',1]];
function vp_render_card(array $c, bool $hidden=false, bool $held=false): string {
  $red = in_array($c['s'],['H','D'],true);
  $sGlyph = ['S'=>'&#9824;','H'=>'&#9829;','D'=>'&#9830;','C'=>'&#9827;'][$c['s']]??'';
  $col = $hidden ? '#555' : ($red ? '#ff5555' : '#dde2f0');
  $ring = $held ? 'box-shadow:0 0 0 2px var(--accent);' : '';
  if ($hidden) return '<div class="bj-card bj-hidden">??</div>';
  return '<div class="bj-card" style="color:'.$col.';'.$ring.'">'.e($c['r']).'<span style="font-size:10px">'.$sGlyph.'</span></div>';
}
?>
<div class="game-pane <?= $activeTab==='vp'?'active':'' ?>" id="pane-vp">
  <div class="panel">
    <?php if ($msg && $activeTab === 'vp'): ?>
    <div class="daemon-result daemon-result-<?= $msgType ?>"><?= e($msg) ?></div>
    <?php endif; ?>

    <?php if ($vp && $vp['phase'] === 'hold'): ?>
    <!-- Hold phase: select which cards to keep -->
    <div class="felt" style="text-align:center">
      <p class="muted" style="font-size:11px;margin-bottom:10px">Click cards to toggle HOLD, then Draw.</p>
      <form method="post" id="vp-hold-form">
        <input type="hidden" name="action" value="vp_draw">
        <input type="hidden" name="_tab" value="vp">
        <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-bottom:12px">
          <?php foreach ($vp['hand'] as $i => $c): ?>
          <div class="vp-card-wrap" data-idx="<?= $i ?>" data-held="1" style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px">
            <?= vp_render_card($c, false, true) ?>
            <div class="vp-hold-label" style="font-size:9px;font-family:'Orbitron',sans-serif;color:var(--accent);text-align:center;margin-top:2px">HOLD</div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary daemon-go-btn">&#9830; Draw</button>
      </form>
      <p class="muted" style="margin-top:10px;font-size:12px">Bet: <b style="color:var(--accent)"><?= number_format($vp['bet']) ?> creds</b></p>
    </div>

    <?php elseif ($vp && $vp['phase'] === 'done'): ?>
    <!-- Result phase -->
    <div class="felt" style="text-align:center">
      <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-bottom:12px">
        <?php foreach ($vp['hand'] as $c): echo vp_render_card($c); endforeach; ?>
      </div>
      <div style="font-size:16px;font-weight:700;color:<?= ($vp['net']??0)>=0?'var(--accent)':'var(--neon2)' ?>;margin-bottom:4px"><?= e($vp['name']) ?></div>
      <?php if ($vp['mult'] > 0): ?><div style="font-size:12px;color:var(--muted)">Pays <?= $vp['mult'] ?>× &nbsp;&middot;&nbsp; <span style="color:var(--accent)">+<?= number_format($vp['mult']*$vp['bet']) ?> cr</span></div><?php endif; ?>
    </div>
    <form method="post" style="text-align:center;margin-top:14px">
      <input type="hidden" name="action" value="vp_reset">
      <input type="hidden" name="_tab" value="vp">
      <button type="submit" class="btn btn-primary">&#9830; Deal Again</button>
    </form>

    <?php else: ?>
    <!-- Betting screen -->
    <div class="felt" style="text-align:center;padding:20px 16px">
      <p style="font-size:32px;margin-bottom:4px">&#9830;</p>
      <p class="muted" style="margin-bottom:4px">Jacks or Better. Pick your holds, then draw.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;max-width:260px;margin:12px auto 0;font-size:11px">
        <?php foreach ($vpPayTable as [$hn,$hm]): ?>
        <span style="text-align:right;color:var(--muted)"><?= $hn ?></span>
        <span style="color:<?= $hm>=50?'#e8d44d':($hm>=9?'var(--accent)':'var(--text)') ?>;font-weight:<?= $hm>=9?'700':'400' ?>"><?= $hm ?>×</span>
        <?php endforeach; ?>
      </div>
    </div>
    <form method="post" style="margin-top:14px">
      <input type="hidden" name="action" value="vp_deal">
      <input type="hidden" name="_tab" value="vp">
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
      <button type="submit" class="btn btn-primary btn-block daemon-go-btn">&#9830; Deal Cards</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- ======= HISTORY ======= -->
<div class="game-pane <?= $activeTab==='history'?'active':'' ?>" id="pane-history">

  <!-- Stats grid -->
  <?php if ($statsTotal > 0): ?>
  <div class="panel">
    <h3 style="margin-top:0">&#128202; All-Time Stats</h3>
    <div class="casino-stats-grid">
      <div class="cs-box"><div class="cs-lbl">Games</div><div class="cs-val"><?= number_format($statsTotal) ?></div></div>
      <div class="cs-box"><div class="cs-lbl">Wagered</div><div class="cs-val"><?= number_format($statWagered) ?></div></div>
      <div class="cs-box"><div class="cs-lbl">Net Profit</div>
        <div class="cs-val <?= $statNet >= 0 ? 'cs-pos' : 'cs-neg' ?>"><?= $statNet >= 0 ? '+' : '' ?><?= number_format($statNet) ?></div></div>
      <div class="cs-box"><div class="cs-lbl">Win Rate</div>
        <div class="cs-val <?= $statWinRate >= 50 ? 'cs-pos' : 'cs-neg' ?>"><?= $statWinRate ?>%</div></div>
      <div class="cs-box"><div class="cs-lbl">Biggest Win</div>
        <div class="cs-val cs-pos"><?= $statBigWin > 0 ? '+'.number_format($statBigWin) : '&mdash;' ?></div></div>
      <div class="cs-box"><div class="cs-lbl">Biggest Loss</div>
        <div class="cs-val cs-neg"><?= $statBigLoss < 0 ? number_format($statBigLoss) : '&mdash;' ?></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent plays -->
  <div class="panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <h3 style="margin:0">Recent Plays</h3>
      <div style="display:flex;gap:4px">
        <button class="bet-chip hist-filter active" data-game="all">All</button>
        <button class="bet-chip hist-filter" data-game="dice">&#127922;</button>
        <button class="bet-chip hist-filter" data-game="slots">&#127920;</button>
        <button class="bet-chip hist-filter" data-game="blackjack">&#127921;</button>
        <button class="bet-chip hist-filter" data-game="video_poker">&#9830;</button>
      </div>
    </div>
    <?php if ($recent): ?>
    <div class="hist-list">
      <?php foreach ($recent as $r): $icon = $gameIcons[$r['game']] ?? '&#127918;'; ?>
      <div class="hist-row" data-game="<?= e($r['game']) ?>">
        <span class="hist-ic"><?= $icon ?></span>
        <div class="hist-info">
          <span class="hist-game"><?= e(ucfirst($r['game'])) ?></span>
          <span class="hist-detail muted"><?= e($r['detail']) ?></span>
        </div>
        <div class="hist-right">
          <div class="hist-net <?= $r['net'] >= 0 ? 'net-win' : 'net-lose' ?>">
            <?= $r['net'] >= 0 ? '+' : '' ?><?= number_format($r['net']) ?>
          </div>
          <div class="hist-time muted"><?= e(substr($r['played_at'], 5, 11)) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="muted">No plays yet &mdash; test your luck.</p>
    <?php endif; ?>
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
  document.querySelectorAll('.bet-chip:not(.hist-filter)').forEach(function(chip){
    chip.addEventListener('click', function(){
      var input = chip.closest('form,div').querySelector('input[name="bet"]');
      if(!input){ input = chip.closest('.panel').querySelector('input[name="bet"]'); }
      if (input) input.value = chip.dataset.amt;
    });
  });

  /* History filter */
  document.querySelectorAll('.hist-filter').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.hist-filter').forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      var g = btn.dataset.game;
      document.querySelectorAll('.hist-row').forEach(function(r){
        r.style.display = (g==='all' || r.dataset.game===g) ? '' : 'none';
      });
    });
  });

  /* Video Poker hold toggle */
  document.querySelectorAll('.vp-card-wrap').forEach(function(wrap){
    wrap.addEventListener('click', function(){
      var held = wrap.dataset.held === '1';
      held = !held;
      wrap.dataset.held = held ? '1' : '0';
      var holdLbl = wrap.querySelector('.vp-hold-label');
      var card = wrap.querySelector('.bj-card');
      if (card) card.style.boxShadow = held ? '0 0 0 2px var(--accent)' : 'none';
      if (holdLbl) { holdLbl.textContent = held ? 'HOLD' : ''; holdLbl.style.color = held ? 'var(--accent)' : 'transparent'; }
    });
  });
  var vpForm = document.getElementById('vp-hold-form');
  if (vpForm) vpForm.addEventListener('submit', function(){
    vpForm.querySelectorAll('input[name="hold[]"]').forEach(function(i){ i.remove(); });
    document.querySelectorAll('.vp-card-wrap[data-held="1"]').forEach(function(wrap){
      var inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'hold[]'; inp.value = wrap.dataset.idx;
      vpForm.appendChild(inp);
    });
  });

  /* Slots spin animation */
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
