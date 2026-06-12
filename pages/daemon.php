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
$fxEvent = null; // reveal/celebration event handed to the client after a play
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
      $fxEvent = ['g'=>'dice','won'=>$net>0,'net'=>$net,'big'=>($mult>=5&&$net>0)];
      $player = current_player(); // refresh so the balance + MAX chip reflect this spin
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
      $fxEvent = ['g'=>'slots','mult'=>$mult,'net'=>$net,'jackpot'=>$mult>=25,'big'=>$mult>=5];
      $player = current_player(); // refresh so the balance + MAX chip reflect this spin
    }

    // ── Blackjack ──
    elseif ($action === 'bj_deal') {
      // Refuse to deal over a live hand — otherwise the escrowed bet on the
      // hand in progress is silently lost (never settled, never logged).
      if (($_SESSION['daemon_bj']['phase'] ?? '') === 'playing') throw new RuntimeException('Finish your current hand first.');
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
        $fxEvent = ['g'=>'bj','ev'=>'deal','result'=>($pv===21&&$dv===21)?'push':($dv===21?'lose':'bigwin')];
      } else {
        $msg = 'Cards dealt. Hit or Stand?'; $activeTab = 'blackjack';
        $fxEvent = ['g'=>'bj','ev'=>'deal','result'=>null];
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
        $fxEvent = ['g'=>'bj','ev'=>'hit','bust'=>true];
      } else {
        $msg = "Hit — your hand is now {$pv}.";
        $fxEvent = ['g'=>'bj','ev'=>'hit','bust'=>false];
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
      $fxEvent = ['g'=>'bj','ev'=>'stand',
        'result'=>($dv>21||$pv>$dv)?'win':($pv<$dv?'lose':'push'),
        'net'=>($dv>21||$pv>$dv)?$bet:($pv<$dv?-$bet:0)];
      $_SESSION['daemon_bj'] = $bj; $activeTab = 'blackjack'; $player = current_player();
    }

    elseif ($action === 'bj_reset') {
      unset($_SESSION['daemon_bj']); $activeTab = 'blackjack';
    }

    // ── Video Poker ──
    elseif ($action === 'vp_deal') {
      // Refuse to deal over a hand still in the hold phase — its bet is escrowed
      if (($_SESSION['daemon_vp']['phase'] ?? '') === 'hold') throw new RuntimeException('Finish your current hand first.');
      $bet = (int)($_POST['bet'] ?? 0);
      if ($bet <= 0)      throw new RuntimeException('Place a bet above zero.');
      if ($bet > MAX_BET) throw new RuntimeException('Max single bet is '.number_format(MAX_BET).'.');
      $pdo->beginTransaction();
      if (!place_bet($pid, $bet)) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $pdo->commit();
      $deck = vp_deck();
      $_SESSION['daemon_vp'] = ['hand'=>array_slice($deck,0,5),'deck'=>array_slice($deck,5),'bet'=>$bet,'phase'=>'hold'];
      $msg = 'Cards dealt — select which to hold, then Draw.'; $activeTab = 'vp';
      $fxEvent = ['g'=>'vp','ev'=>'deal'];
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
      $fxEvent = ['g'=>'vp','ev'=>'draw','mult'=>$mult,'net'=>$net,'name'=>$hName,'jackpot'=>$mult>=25,'big'=>$mult>=6];
      $_SESSION['daemon_vp'] = ['hand'=>$hand,'bet'=>$bet,'phase'=>'done','name'=>$hName,'mult'=>$mult,'net'=>$net];
      $activeTab = 'vp'; $player = current_player();
    }

    elseif ($action === 'vp_reset') {
      unset($_SESSION['daemon_vp']); $activeTab = 'vp';
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgType = 'err';
    $fxEvent = null; // failed plays get no reveal/celebration
  }
}

// Active blackjack / video poker sessions
$bj = $_SESSION['daemon_bj'] ?? null;
$vp = $_SESSION['daemon_vp'] ?? null;

// History + stats — casino_log ships in schema_casino.sql; guard so a missing
// table degrades to an empty history instead of failing the whole page
$recent = [];
try {
  $rl = $pdo->prepare('SELECT * FROM casino_log WHERE player_id = ? ORDER BY played_at DESC LIMIT 50');
  $rl->execute([$pid]);
  $recent = $rl->fetchAll();
} catch (Throwable $e) {}

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

<style>
#dmn-canvas{display:block;width:100%;height:112px;border-radius:9px 9px 0 0}
#dmn-head h2{text-shadow:0 0 16px rgba(255,45,149,.5)}
@keyframes dmnDealIn{0%{opacity:0;transform:translateY(-14px) rotateY(85deg)}100%{opacity:1;transform:none}}
.bj-hand .bj-card,.vp-card-wrap .bj-card{animation:dmnDealIn .3s ease-out backwards}
.felt{position:relative}
.felt::after{content:'';position:absolute;inset:0;border-radius:inherit;pointer-events:none;box-shadow:inset 0 0 40px rgba(0,0,0,.45)}
.daemon-go-btn{transition:transform .08s,box-shadow .15s}
.daemon-go-btn:hover{box-shadow:0 0 14px rgba(25,240,199,.25)}
.daemon-go-btn:active{transform:scale(.97)}
.dice-box{transition:transform .12s}
.dice-box.rolling{animation:dmnDiceShake .12s linear infinite}
@keyframes dmnDiceShake{0%{transform:translate(0,0) rotate(0)}25%{transform:translate(2px,-2px) rotate(4deg)}50%{transform:translate(-2px,1px) rotate(-3deg)}75%{transform:translate(1px,2px) rotate(2deg)}}
.dice-box.landed{animation:dmnDiceLand .3s cubic-bezier(.2,1.8,.4,1)}
@keyframes dmnDiceLand{0%{transform:scale(1.35)}100%{transform:scale(1)}}
.reel-box.rolling{animation:dmnReelBlur .09s linear infinite}
@keyframes dmnReelBlur{0%{transform:translateY(-2px);filter:blur(1px)}100%{transform:translateY(2px);filter:blur(1.5px)}}
.reel-box.landed{animation:dmnDiceLand .25s cubic-bezier(.2,1.8,.4,1)}
.hist-row{transition:background .12s}
.hist-row:hover{background:rgba(255,255,255,.025)}
@keyframes dmnWinPulse{0%{box-shadow:inset 0 0 0 rgba(59,207,99,0)}30%{box-shadow:inset 0 0 60px rgba(59,207,99,.25)}100%{box-shadow:inset 0 0 0 rgba(59,207,99,0)}}
.felt.winflash{animation:dmnWinPulse 1.1s ease-out}
@keyframes dmnLosePulse{0%{box-shadow:inset 0 0 0 rgba(255,45,149,0)}30%{box-shadow:inset 0 0 50px rgba(255,45,149,.18)}100%{box-shadow:inset 0 0 0 rgba(255,45,149,0)}}
.felt.loseflash{animation:dmnLosePulse 1s ease-out}
</style>

<div class="panel" id="dmn-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="dmn-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:12px;pointer-events:none">
      <h2 style="margin:0">&#127920; The Lucky Daemon</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">The house always wins. The house is also on fire.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:12px;text-align:right">
      <div class="muted" style="font-size:10px;text-shadow:0 1px 4px #000">POCKET</div>
      <div style="font-size:19px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent);text-shadow:0 1px 6px #000"><?= number_format($player['creds_pocket']) ?> <span style="font-size:11px;font-weight:400">cr</span></div>
    </div>
    <button id="dmn-mute" onclick="toggleDmnSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
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
/* Casino FX kit — bound once; overlays live on document.body and survive AJAX swaps. */
(function(){
  if(window._dmnFxBound) return;
  window._dmnFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#dmnjp{position:fixed;inset:0;z-index:10001;pointer-events:none;opacity:0;transition:opacity .2s;overflow:hidden}'
    +'#dmnjp.show{opacity:1}'
    +'#dmnjp .jp-txt{position:absolute;left:50%;top:42%;transform:translate(-50%,-50%) scale(.4);text-align:center;'
    +'font-family:Orbitron,monospace;font-weight:900;font-size:38px;color:#e8d44d;letter-spacing:.1em;'
    +'text-shadow:0 0 24px #e8d44d,0 0 70px rgba(232,212,77,.7);animation:dmnJpIn .5s cubic-bezier(.2,1.7,.4,1) forwards}'
    +'@keyframes dmnJpIn{to{transform:translate(-50%,-50%) scale(1)}}'
    +'#dmnjp .jp-sub{display:block;font-size:16px;color:#fff;letter-spacing:.06em;margin-top:8px;text-shadow:0 0 14px rgba(255,255,255,.6)}'
    +'.dmn-coin{position:absolute;top:-24px;width:14px;height:14px;border-radius:50%;'
    +'background:radial-gradient(circle at 35% 30%,#fff2b0,#e8d44d 55%,#9a8420);box-shadow:0 0 8px rgba(232,212,77,.6);'
    +'animation:dmnCoinFall linear forwards}'
    +'@keyframes dmnCoinFall{to{transform:translateY(110vh) rotate(720deg)}}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('daemonMuted')==='1';
  function tone(freq,dur,type,vol,slide){
    if(muted) return;
    try{
      ac=ac||new (window.AudioContext||window.webkitAudioContext)();
      var o=ac.createOscillator(),g=ac.createGain();
      o.type=type||'sine'; o.frequency.value=freq;
      if(slide) o.frequency.exponentialRampToValueAtTime(slide,ac.currentTime+dur);
      g.gain.value=vol||.05;
      g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+dur);
      o.connect(g); g.connect(ac.destination);
      o.start(); o.stop(ac.currentTime+dur);
    }catch(e){}
  }
  window.toggleDmnSound=function(){
    muted=!muted; localStorage.setItem('daemonMuted',muted?'1':'0');
    var b=document.getElementById('dmn-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };

  window.daemonFX={
    tone:tone,
    tick:function(){ tone(1100+Math.random()*500,.025,'square',.02); },
    flip:function(){ tone(700+Math.random()*250,.04,'square',.025); },
    win:function(){ tone(523,.1,'sine',.05); setTimeout(function(){tone(659,.1,'sine',.05);},90); setTimeout(function(){tone(784,.18,'sine',.05);},180); },
    bigwin:function(){ tone(523,.1,'sine',.05); setTimeout(function(){tone(659,.1,'sine',.05);},80); setTimeout(function(){tone(784,.1,'sine',.05);},160); setTimeout(function(){tone(1047,.24,'sine',.055);},240); },
    lose:function(){ tone(220,.22,'sine',.045,110); },
    push:function(){ tone(440,.1,'sine',.04); },
    thud:function(){ tone(110,.12,'square',.05); },
    jackpot:function(label,sub){
      var old=document.getElementById('dmnjp'); if(old) old.remove();
      var o=document.createElement('div'); o.id='dmnjp';
      o.innerHTML='<div class="jp-txt">'+(label||'JACKPOT')+(sub?'<span class="jp-sub">'+sub+'</span>':'')+'</div>';
      for(var i=0;i<44;i++){
        var coin=document.createElement('div'); coin.className='dmn-coin';
        coin.style.left=(Math.random()*100)+'%';
        coin.style.animationDuration=(1.3+Math.random()*1.4)+'s';
        coin.style.animationDelay=(Math.random()*0.9)+'s';
        coin.style.width=coin.style.height=(9+Math.random()*9)+'px';
        o.appendChild(coin);
      }
      document.body.appendChild(o);
      requestAnimationFrame(function(){o.classList.add('show');});
      var n=[523,659,784,1047,1319];
      n.forEach(function(f,i){ setTimeout(function(){tone(f,.16,'sine',.055);},i*110); });
      setTimeout(function(){tone(1568,.4,'sine',.05);},n.length*110);
      setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},250);},2900);
    }
  };
})();
</script>

<script>window._dmnEvent = <?= json_encode($fxEvent) ?>;</script>

<script>
(function(){
  /* Mute button icon (button re-renders on every swap; pref persists) */
  var mb=document.getElementById('dmn-mute');
  if(mb) mb.innerHTML=localStorage.getItem('daemonMuted')==='1'?'&#128263;':'&#128266;';

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

  /* ── Marquee header: chasing bulbs, falling suits, daemon glow ── */
  var mc=document.getElementById('dmn-canvas');
  if(mc){
    var c=mc.getContext('2d');
    var MW=560, MH=112;
    var dpr=Math.min(2,window.devicePixelRatio||1);
    mc.width=MW*dpr; mc.height=MH*dpr;
    c.scale(dpr,dpr);
    var SUITS=['♠','♥','♦','♣','7'];
    var SCOLS=['#dde2f0','#ff5555','#ff5555','#dde2f0','#e8d44d'];
    var fall=[];
    for(var i=0;i<14;i++){ var k=Math.floor(Math.random()*5);
      fall.push({x:Math.random()*MW,y:Math.random()*MH,v:.2+Math.random()*.4,s:10+Math.random()*9,k:k,r:Math.random()*Math.PI,vr:(Math.random()-.5)*.02}); }
    // bulb positions around the border
    var bulbs=[];
    var step=22;
    for(var bx=12;bx<MW-6;bx+=step) bulbs.push([bx,8]);
    for(var by2=8+step;by2<MH-6;by2+=step) bulbs.push([MW-12,by2]);
    for(var bx2=MW-12-step;bx2>6;bx2-=step) bulbs.push([bx2,MH-8]);
    for(var by3=MH-8-step;by3>8;by3-=step) bulbs.push([12,by3]);

    function mLoop(t){
      if(!document.body.contains(mc)) return;
      requestAnimationFrame(mLoop);
      c.clearRect(0,0,MW,MH);
      var bg=c.createLinearGradient(0,0,0,MH);
      bg.addColorStop(0,'#0d0712'); bg.addColorStop(1,'#120a16');
      c.fillStyle=bg; c.fillRect(0,0,MW,MH);

      // falling suit glyphs
      for(var fi=0;fi<fall.length;fi++){
        var F=fall[fi];
        F.y+=F.v; F.r+=F.vr;
        if(F.y>MH+12){ F.y=-12; F.x=Math.random()*MW; }
        c.save(); c.translate(F.x,F.y); c.rotate(F.r);
        c.globalAlpha=.16;
        c.fillStyle=SCOLS[F.k];
        c.font='700 '+F.s+'px monospace'; c.textAlign='center'; c.textBaseline='middle';
        c.fillText(SUITS[F.k],0,0);
        c.restore();
      }
      c.globalAlpha=1;

      // daemon sigil glow (center-right)
      var dg=.6+.4*Math.sin(t/600);
      c.shadowColor='#ff2d95'; c.shadowBlur=18*dg;
      c.fillStyle='rgba(255,45,149,'+(0.75*dg+0.2)+')';
      c.font='700 38px monospace'; c.textAlign='center'; c.textBaseline='middle';
      c.fillText('👹',MW-86,46);
      c.shadowBlur=0;

      // chasing marquee bulbs
      var phase=Math.floor(t/110);
      for(var bi=0;bi<bulbs.length;bi++){
        var on=((bi+phase)%3)===0;
        var col=on?'#e8d44d':'rgba(232,212,77,.16)';
        c.shadowColor='#e8d44d'; c.shadowBlur=on?7:0;
        c.fillStyle=col;
        c.beginPath(); c.arc(bulbs[bi][0],bulbs[bi][1],2.6,0,Math.PI*2); c.fill();
      }
      c.shadowBlur=0;
    }
    requestAnimationFrame(mLoop);
  }

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

  /* Slots spin animation (pre-submit feedback) */
  var sf = document.getElementById('slots-form');
  if (sf) {
    sf.addEventListener('submit', function(){
      document.querySelectorAll('.reel-box').forEach(function(r){ r.classList.add('rolling'); r.classList.remove('idle'); });
      var btn = document.getElementById('spin-btn');
      if (btn){ btn.disabled = true; btn.textContent = 'Spinning...'; }
    });
  }

  /* ── Result reveals (server result is already in the DOM; we re-perform it) ── */
  var FX=window.daemonFX||null;
  var ev=window._dmnEvent||null;
  window._dmnEvent=null; // consume once — tab clicks shouldn't replay it
  if(!FX||!ev) { staggerCards(0); return; }

  function staggerCards(sound){
    var di=0;
    document.querySelectorAll('.bj-hand .bj-card, .vp-card-wrap .bj-card').forEach(function(card){
      card.style.animationDelay=(di*110)+'ms';
      if(sound) setTimeout(FX?FX.flip:function(){},di*110);
      di++;
    });
    return di;
  }

  function celebrate(kind,net,feltSel){
    var felt=document.querySelector(feltSel);
    if(kind==='jackpot'){ FX.jackpot('JACKPOT','+'+Number(net).toLocaleString('en-US')+' cr'); if(felt) felt.classList.add('winflash'); }
    else if(kind==='bigwin'){ FX.bigwin(); if(felt) felt.classList.add('winflash'); }
    else if(kind==='win'){ FX.win(); if(felt) felt.classList.add('winflash'); }
    else if(kind==='push'){ FX.push(); }
    else if(kind==='lose'){ FX.lose(); if(felt) felt.classList.add('loseflash'); }
  }
  function hideResultUntil(pane,ms){
    var res=document.querySelector(pane+' .daemon-result');
    if(!res) return;
    res.style.opacity='0';
    setTimeout(function(){ res.style.transition='opacity .3s'; res.style.opacity='1'; },ms);
  }

  if(ev.g==='dice'){
    var boxes=document.querySelectorAll('#pane-dice .dice-box');
    var sum=document.querySelector('#pane-dice .dice-sum');
    if(boxes.length===2){
      var faces=['⚀','⚁','⚂','⚃','⚄','⚅'];
      var finals=[boxes[0].innerHTML,boxes[1].innerHTML];
      var finalCls=[boxes[0].className,boxes[1].className];
      if(sum) sum.style.visibility='hidden';
      hideResultUntil('#pane-dice',1150);
      boxes.forEach(function(b){ b.className='dice-box idle rolling'; });
      var n=0;
      var iv=setInterval(function(){
        n++;
        boxes.forEach(function(b){ b.innerHTML=faces[Math.floor(Math.random()*6)]; });
        FX.tick();
        if(n>=9){
          clearInterval(iv);
          boxes[0].innerHTML=finals[0]; boxes[0].className=finalCls[0]+' landed';
          boxes[1].innerHTML=finals[1]; boxes[1].className=finalCls[1]+' landed';
          FX.thud();
          if(sum) sum.style.visibility='';
          celebrate(ev.big?'bigwin':(ev.won?'win':'lose'),ev.net,'#pane-dice .felt');
        }
      },100);
    }
  }
  else if(ev.g==='slots'){
    var reels=document.querySelectorAll('#pane-slots .reel-box');
    if(reels.length===3){
      var syms=['🍒','🔔','💎','⚡','7️⃣'];
      var rFinals=[],rCls=[];
      reels.forEach(function(r){ rFinals.push(r.innerHTML); rCls.push(r.className); r.className='reel-box idle rolling'; });
      hideResultUntil('#pane-slots',1500);
      var stopped=0;
      var spinIv=setInterval(function(){
        reels.forEach(function(r,i){ if(i>=stopped) r.innerHTML=syms[Math.floor(Math.random()*5)]; });
        FX.tick();
      },90);
      [550,950,1350].forEach(function(ms,i){
        setTimeout(function(){
          reels[i].innerHTML=rFinals[i]; reels[i].className=rCls[i]+' landed';
          FX.thud();
          stopped++;
          if(stopped===3){
            clearInterval(spinIv);
            if(ev.jackpot) celebrate('jackpot',ev.net,'#pane-slots .felt');
            else if(ev.big) celebrate('bigwin',ev.net,'#pane-slots .felt');
            else if(ev.mult===1) celebrate('push',0,null);
            else celebrate('lose',ev.net,'#pane-slots .felt');
          }
        },ms);
      });
    }
  }
  else if(ev.g==='bj'){
    var dealt=staggerCards(1);
    var doneAt=dealt*110+200;
    if(ev.ev==='deal'&&ev.result==='bigwin') setTimeout(function(){ celebrate('bigwin',0,'#pane-blackjack .felt'); },doneAt);
    else if(ev.ev==='deal'&&ev.result==='push') setTimeout(function(){ celebrate('push',0,null); },doneAt);
    else if(ev.ev==='deal'&&ev.result==='lose') setTimeout(function(){ celebrate('lose',0,'#pane-blackjack .felt'); },doneAt);
    else if(ev.ev==='hit') setTimeout(function(){ if(ev.bust) celebrate('lose',0,'#pane-blackjack .felt'); },doneAt);
    else if(ev.ev==='stand') setTimeout(function(){ celebrate(ev.result==='win'?'win':(ev.result==='push'?'push':'lose'),ev.net,'#pane-blackjack .felt'); },doneAt);
  }
  else if(ev.g==='vp'){
    var vdealt=staggerCards(1);
    var vDoneAt=vdealt*110+200;
    if(ev.ev==='draw') setTimeout(function(){
      if(ev.jackpot) celebrate('jackpot',ev.net,'#pane-vp .felt');
      else if(ev.big) celebrate('bigwin',ev.net,'#pane-vp .felt');
      else if(ev.net>0) celebrate('win',ev.net,'#pane-vp .felt');
      else if(ev.net===0) celebrate('push',0,null);
      else celebrate('lose',ev.net,'#pane-vp .felt');
    },vDoneAt);
  }
})();
</script>
