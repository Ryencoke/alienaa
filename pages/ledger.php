<?php /* pages/ledger.php — Bank (deposit / withdraw / transfer / loan) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

define('BANK_INTEREST_RATE', 0.005); // 0.5% per day on bank balance
// Withdrawal fee: 0.5% under Commerce Accord, 2% otherwise
$_today = date('Y-m-d');
$_isMerchant = !empty($player['merchant_until']) && $player['merchant_until'] >= $_today;
define('WITHDRAW_FEE_PCT', $_isMerchant ? 0.005 : 0.02);

$loanCap = 500 + (int)$player['level'] * 250;   // borrowing limit scales with level

// Auto-apply daily interest when player visits the bank
$interestEarned = 0;
try {
  $today = date('Y-m-d');
  $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $q->execute(["bank_interest:{$pid}"]);
  $lastInterest = $q->fetchColumn();
  if ($lastInterest !== $today && (int)$player['creds_bank'] > 0) {
    $q->execute(["apt_bank_bonus:{$pid}"]); $bankBonus = (int)$q->fetchColumn();
    $rate = BANK_INTEREST_RATE + ($bankBonus > 0 ? $bankBonus / 10000 : 0); // perk_val 25 = +0.0025 = +0.25%
    $interestEarned = (int)max(1, floor((int)$player['creds_bank'] * $rate));
    $pdo->prepare('UPDATE players SET creds_bank = creds_bank + ? WHERE id = ?')->execute([$interestEarned, $pid]);
    $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["bank_interest:{$pid}", $today]);
    $player = current_player();
    $ratePct = round($rate * 100, 3);
    $msg = '&#9733; Daily interest applied: +' . number_format($interestEarned) . ' creds (' . $ratePct . '% on your balance).';
  }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {

    if ($action === 'deposit') {
      $amt = isset($_POST['all']) ? (int)$player['creds_pocket'] : (int)($_POST['amount'] ?? 0);
      if ($amt <= 0) throw new RuntimeException('Enter an amount above zero.');
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ?, creds_bank = creds_bank + ?
                          WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$amt, $amt, $pid, $amt]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not that many creds in pocket.');
      $msg = number_format($amt) . ' creds deposited.';
    }

    elseif ($action === 'withdraw') {
      $amt = isset($_POST['all']) ? (int)$player['creds_bank'] : (int)($_POST['amount'] ?? 0);
      if ($amt <= 0) throw new RuntimeException('Enter an amount above zero.');
      $fee = (int)max(1, ceil($amt * WITHDRAW_FEE_PCT));
      $total = $amt + $fee;
      $u = $pdo->prepare('UPDATE players SET creds_bank = creds_bank - ?, creds_pocket = creds_pocket + ?
                          WHERE id = ? AND creds_bank >= ?');
      $u->execute([$total, $amt, $pid, $total]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not that many creds in the bank (including the ' . number_format($fee) . ' cr withdrawal fee).');
      $msg = 'Withdrew ' . number_format($amt) . ' creds (' . number_format($fee) . ' cr fee deducted from your bank balance).';
    }

    elseif ($action === 'transfer') {
      $to  = trim($_POST['to'] ?? '');
      $amt = (int)($_POST['amount'] ?? 0);
      if ($amt <= 0)   throw new RuntimeException('Enter an amount above zero.');
      if ($to === '')  throw new RuntimeException('Enter a handle or ID to send to.');
      if (ctype_digit($to)) {
        $t = $pdo->prepare('SELECT id, username FROM players WHERE id = ?'); $t->execute([(int)$to]);
      } else {
        $t = $pdo->prepare('SELECT id, username FROM players WHERE username = ?'); $t->execute([$to]);
      }
      $toRow = $t->fetch();
      $toId   = $toRow ? $toRow['id']       : null;
      $toName = $toRow ? $toRow['username']  : $to;
      if (!$toId)                       throw new RuntimeException('No ghost by that handle or ID.');
      if ((int)$toId === (int)$pid)     throw new RuntimeException("You can't transfer to yourself.");

      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$amt, $pid, $amt]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$amt, $toId]);
      $pdo->commit();
      try { $pdo->prepare('INSERT INTO tx_log (from_id, to_id, kind, amount, note) VALUES (?,?,?,?,?)')
                ->execute([$pid, $toId, 'transfer', $amt, $toName]); } catch (Throwable $e) {}
      $msg = 'Sent ' . number_format($amt) . ' creds to ' . e($toName) . '.';
    }

    elseif ($action === 'borrow') {
      $amt   = (int)($_POST['amount'] ?? 0);
      $dest  = ($_POST['dest'] ?? 'pocket') === 'bank' ? 'creds_bank' : 'creds_pocket'; // whitelisted
      $avail = max(0, $loanCap - (int)($player['loan'] ?? 0));
      if ($amt <= 0)      throw new RuntimeException('Enter an amount above zero.');
      if ($amt > $avail)  throw new RuntimeException('You can only borrow up to ' . number_format($avail) . ' creds.');
      $owe = (int)ceil($amt * 1.045); // 4.5% surcharge
      $b = $pdo->prepare("UPDATE players SET loan = loan + ?, {$dest} = {$dest} + ? WHERE id = ? AND loan + ? <= ?");
      $b->execute([$owe, $amt, $pid, $amt, $loanCap]);
      if ($b->rowCount() !== 1) throw new RuntimeException('That would push you past your loan cap of ' . number_format($loanCap) . ' creds.');
      $msg = 'Borrowed ' . number_format($amt) . ' creds. You now owe ' . number_format($owe) . '.';
    }

    elseif ($action === 'repay') {
      $amt = (int)($_POST['amount'] ?? 0);
      if ($amt <= 0) throw new RuntimeException('Enter an amount above zero.');
      $pay = min($amt, (int)($player['loan'] ?? 0));
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ?, loan = loan - ?
                          WHERE id = ? AND creds_pocket >= ? AND loan >= ?');
      $u->execute([$pay, $pay, $pid, $pay, $pay]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough creds in pocket to repay that.');
      $msg = 'Repaid ' . number_format($pay) . ' creds.';
    }

  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
  $player = current_player();
}

$loan  = (int)($player['loan'] ?? 0);
$avail = max(0, $loanCap - $loan);
$feePct = WITHDRAW_FEE_PCT;
?>
<style>
#bank-canvas{display:block;width:100%;height:190px;border-radius:9px 9px 0 0}
#bank-head h2{text-shadow:0 0 14px rgba(25,240,199,.35)}
.bank-chip{display:inline-flex;flex-direction:column;align-items:center;padding:7px 16px;background:rgba(6,6,14,.78);border:1px solid var(--line);border-radius:8px;backdrop-filter:blur(3px)}
.bank-chip b{font-family:'Orbitron',sans-serif;font-size:16px}
.bank-chip span{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:1px}
.bank-card{transition:transform .12s,border-color .15s,box-shadow .15s}
.bank-card:hover{transform:translateY(-2px);border-color:var(--bk-col,var(--accent));box-shadow:0 4px 14px rgba(0,0,0,.3),0 0 12px var(--bk-glow,rgba(25,240,199,.1))}
.bank-card h3{display:flex;align-items:center;gap:7px}
.loan-track{height:7px;border-radius:4px;background:rgba(255,255,255,.07);overflow:hidden;margin:8px 0 4px}
.loan-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#e8a33d,#ff6b35);transition:width .4s ease}
.stat-card{transition:transform .12s,border-color .15s}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(25,240,199,.3)}
@keyframes bankIntDrop{0%{transform:translateY(-10px);opacity:0}60%{transform:translateY(2px);opacity:1}100%{transform:none;opacity:1}}
#bank-interest{animation:bankIntDrop .5s ease-out}
</style>

<div class="panel" id="bank-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="bank-canvas"></canvas>
    <div style="position:absolute;left:16px;top:10px;pointer-events:none">
      <h2 style="margin:0">&#127974; Iron Ledger</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Skimming a little off the top since the blackout.</p>
    </div>
    <div style="position:absolute;left:50%;transform:translateX(-50%);bottom:8px;display:flex;gap:10px">
      <div class="bank-chip"><b style="color:var(--accent)"><?= number_format($player['creds_bank']) ?></b><span>Bank</span></div>
      <div class="bank-chip"><b style="color:#e8a33d"><?= number_format($player['creds_pocket']) ?></b><span>Pocket</span></div>
      <?php if ($loan > 0): ?>
      <div class="bank-chip" style="border-color:rgba(255,45,149,.35)"><b style="color:var(--neon2)"><?= number_format($loan) ?></b><span>Owed</span></div>
      <?php endif; ?>
    </div>
    <button id="bank-mute" onclick="toggleBankSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
  <div style="padding:10px 14px 12px">
    <?php if ($_isMerchant): ?><div style="background:rgba(232,163,61,.08);border:1px solid rgba(232,163,61,.25);border-radius:6px;padding:7px 12px;font-size:12px;text-align:center;margin-bottom:8px">&#9878; <b style="color:#e8a33d">Commerce Accord active</b> — withdrawal fee reduced to 0.5%. <a href="index.php?p=accord" style="color:#e8a33d">View</a></div><?php endif; ?>
    <?php if ($interestEarned > 0): ?>
    <div id="bank-interest" style="background:rgba(232,212,77,.07);border:1px solid rgba(232,212,77,.3);border-radius:6px;padding:8px 12px;font-size:12px;text-align:center;margin-bottom:8px">
      &#9733; <b style="color:#e8d44d">Daily interest paid:</b> <b style="color:#3bcf63;font-family:'Orbitron',sans-serif">+<?= number_format($interestEarned) ?> cr</b>
      <span class="muted">— your balance works while you sleep.</span>
    </div>
    <?php endif; ?>
    <?php if ($msg && ($interestEarned === 0 || $_SERVER['REQUEST_METHOD'] === 'POST')): ?>
    <div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>" style="margin-bottom:0"><?= e($msg) ?></div>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">

  <div class="panel bank-card" style="--bk-col:#3bcf63;--bk-glow:rgba(59,207,99,.1);margin:0">
    <h3><span style="color:#3bcf63">&#11015;</span> Deposit</h3>
    <p class="muted">Move creds from pocket into the bank. Earn <b style="color:var(--accent)"><?= rtrim(rtrim(number_format(BANK_INTEREST_RATE*100, 2), '0'), '.') ?>% daily interest</b> on your balance.</p>
    <form method="post" data-bankfx="deposit">
      <input type="hidden" name="action" value="deposit">
      <div class="field">
        <span>Amount <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(pocket: <?= number_format($player['creds_pocket']) ?> cr)</span></span>
        <div class="num-wrap">
          <input type="number" name="amount" id="dep-amt" min="1" max="<?= (int)$player['creds_pocket'] ?>" value="<?= (int)$player['creds_pocket'] ?>" oninput="updateDepHint()">
          <button type="button" class="fill-max" onclick="document.getElementById('dep-amt').value=<?= (int)$player['creds_pocket'] ?>;updateDepHint()">Max</button>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px" id="dep-hint"></div>
      </div>
      <button type="submit" style="margin-top:4px;border-color:rgba(59,207,99,.4);color:#3bcf63">Deposit</button>
    </form>
  </div>

  <div class="panel bank-card" style="--bk-col:#e8a33d;--bk-glow:rgba(232,163,61,.1);margin:0">
    <h3><span style="color:#e8a33d">&#11014;</span> Withdraw</h3>
    <p class="muted">Pull creds from the bank into your pocket.</p>
    <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:5px;padding:7px 10px;font-size:11px;margin-bottom:12px">
      <span style="color:var(--neon2)">&#9888; <?= rtrim(rtrim(number_format($feePct*100, 2), '0'), '.') ?>% withdrawal fee</span> <span class="muted">deducted from your bank balance on top of the amount.</span>
    </div>
    <form method="post" data-bankfx="withdraw">
      <input type="hidden" name="action" value="withdraw">
      <div class="field">
        <span>Amount <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(bank: <?= number_format($player['creds_bank']) ?> cr)</span></span>
        <div class="num-wrap">
          <input type="number" name="amount" id="wd-amt" min="1" max="<?= (int)$player['creds_bank'] ?>" oninput="updateWdFee()">
          <button type="button" class="fill-max" id="wd-max-btn">Max</button>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px" id="wd-fee-preview"></div>
      </div>
      <button type="submit" style="margin-top:4px;border-color:rgba(232,163,61,.4);color:#e8a33d">Withdraw</button>
    </form>
  </div>

  <div class="panel bank-card" style="--bk-col:#19f0c7;--bk-glow:rgba(25,240,199,.1);margin:0">
    <h3><span style="color:var(--accent)">&#10148;</span> Transfer</h3>
    <p class="muted">Send pocket creds to another ghost. No fee.</p>
    <form method="post" data-bankfx="transfer">
      <input type="hidden" name="action" value="transfer">
      <div class="field">
        <span>To (handle or ID)</span>
        <div class="xfer-to-wrap">
          <input type="text" name="to" id="xferTo" autocomplete="off" data-no-counter>
          <div class="ac-list" id="xferAcList" style="display:none"></div>
          <div id="xfer-id-hint" style="font-size:11px;color:var(--accent);margin-top:3px;display:none"></div>
        </div>
      </div>
      <div class="field">
        <span>Amount <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(pocket: <?= number_format($player['creds_pocket']) ?> cr)</span></span>
        <div class="num-wrap">
          <input type="number" name="amount" id="xfer-amt" min="1" max="<?= (int)$player['creds_pocket'] ?>">
          <button type="button" class="fill-max" onclick="document.getElementById('xfer-amt').value=<?= (int)$player['creds_pocket'] ?>">Max</button>
        </div>
      </div>
      <button type="submit" style="margin-top:4px">Transfer</button>
    </form>
    <script>
    (function(){
      var inp=document.getElementById('xferTo'),list=document.getElementById('xferAcList'),hint=document.getElementById('xfer-id-hint');
      if(!inp||!list) return;
      var cur=-1,items=[];
      function setHint(name){if(hint){hint.textContent=name?'✓ Sending to: '+name:'';hint.style.display=name?'block':'none';}}
      function show(names,fromId){items=names;cur=-1;
        if(!names.length){list.style.display='none';if(fromId)setHint('');return;}
        if(fromId&&names.length===1){inp.value=names[0];setHint(names[0]);list.style.display='none';return;}
        list.innerHTML='';names.forEach(function(n,i){var d=document.createElement('div');d.className='ac-item';d.textContent=n;
          d.addEventListener('mousedown',function(e){e.preventDefault();inp.value=n;setHint(n);list.style.display='none';});list.appendChild(d);});
        list.style.display='block';}
      inp.addEventListener('input',function(){var q=inp.value.trim();setHint('');if(q.length<1){list.style.display='none';return;}
        var isId=/^\d+$/.test(q);
        fetch('players_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){show(r,isId);}).catch(function(){});});
      inp.addEventListener('keydown',function(e){if(!items.length) return;var rows=list.querySelectorAll('.ac-item');
        if(e.key==='ArrowDown'){e.preventDefault();cur=Math.min(cur+1,rows.length-1);rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);});}
        else if(e.key==='ArrowUp'){e.preventDefault();cur=Math.max(cur-1,-1);rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);});}
        else if(e.key==='Enter'&&cur>=0){e.preventDefault();inp.value=items[cur];setHint(items[cur]);list.style.display='none';}
        else if(e.key==='Escape'){list.style.display='none';}});
      document.addEventListener('click',function(e){if(!inp.contains(e.target)&&!list.contains(e.target)) list.style.display='none';});
    })();
    </script>
  </div>

  <div class="panel bank-card" style="--bk-col:#ff6b35;--bk-glow:rgba(255,107,53,.1);margin:0">
    <h3><span style="color:#ff6b35">&#128184;</span> Bank Loan</h3>
    <p class="muted">Borrow up to <b><?= number_format($avail) ?></b> creds. +4.5% surcharge on repayment.</p>
    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--muted)">
      <span>Loan used</span><span><?= number_format($loan) ?> / <?= number_format($loanCap) ?> cr</span>
    </div>
    <div class="loan-track"><div class="loan-fill" style="width:<?= $loanCap > 0 ? min(100, round($loan / $loanCap * 100)) : 0 ?>%"></div></div>
    <?php if ($avail > 0): ?>
    <form method="post" data-bankfx="borrow" style="margin-top:10px">
      <input type="hidden" name="action" value="borrow">
      <div class="field">
        <span>Amount</span>
        <div class="num-wrap">
          <input type="number" name="amount" id="loan-amt" min="1" max="<?= (int)$avail ?>" oninput="updateLoanHint()">
          <button type="button" class="fill-max" onclick="document.getElementById('loan-amt').value=<?= (int)$avail ?>;updateLoanHint()">Max</button>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px" id="loan-hint"></div>
      </div>
      <div class="field">
        <span>Deposit to</span>
        <div style="display:flex;gap:16px;margin-top:4px">
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0"><input type="radio" name="dest" value="pocket" checked style="width:auto;accent-color:var(--accent)"> Pocket</label>
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0"><input type="radio" name="dest" value="bank" style="width:auto;accent-color:var(--accent)"> Bank</label>
        </div>
      </div>
      <button type="submit" style="border-color:rgba(255,107,53,.4);color:#ff6b35">Borrow</button>
    </form>
    <?php else: ?>
    <p class="muted" style="font-size:12px;margin-top:10px">You've reached your borrowing limit. Repay your existing loan to borrow more.</p>
    <?php endif; ?>
    <?php if ($loan > 0): ?>
    <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:14px">
      <div style="font-size:12px;color:var(--neon2);margin-bottom:10px">&#9888; Outstanding loan: <b><?= number_format($loan) ?></b> cr</div>
      <form method="post" data-bankfx="repay" data-bank-loan="<?= $loan ?>">
        <input type="hidden" name="action" value="repay">
        <div class="field">
          <span>Repay from pocket <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(pocket: <?= number_format($player['creds_pocket']) ?> cr)</span></span>
          <div class="num-wrap">
            <?php $repayMax = min((int)$player['creds_pocket'], $loan); ?>
            <input type="number" name="amount" id="repay-amt" min="1" max="<?= $repayMax ?>">
            <button type="button" class="fill-max" onclick="document.getElementById('repay-amt').value=<?= $repayMax ?>">Max</button>
          </div>
        </div>
        <button type="submit" style="border-color:rgba(59,207,99,.4);color:#3bcf63">Repay</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

</div>

<div class="panel">
  <h3>Economic Data</h3>
  <?php
    $ec = $pdo->query("SELECT COUNT(*) players, COALESCE(SUM(creds_pocket),0) pocket, COALESCE(SUM(creds_bank),0) bank, COALESCE(AVG(level),0) avglvl FROM players")->fetch();
    $subs = 0; try { $subs = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE sub_until >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
    $totalW = (int)$ec['pocket'] + (int)$ec['bank'];
    $avgW = $ec['players'] > 0 ? round($totalW / $ec['players']) : 0;
    $tiles = [
      'Total Players'       => number_format($ec['players']),
      'Total Pocket Creds'  => number_format($ec['pocket']),
      'Total Bank Creds'    => number_format($ec['bank']),
      'Total Wealth'        => number_format($totalW),
      'Avg Wealth / Player' => number_format($avgW),
      'Avg Level'           => number_format(round($ec['avglvl'])),
    ];
  ?>
  <div class="stat-grid">
    <?php foreach ($tiles as $lbl => $val): ?>
      <div class="stat-card">
        <div class="val"><?= $val ?></div>
        <div class="lbl"><?= e($lbl) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
'use strict';

/* theme colors for the canvas (player themes vary) */
function cssVar(n,fb){
  try{ var v=getComputedStyle(document.documentElement).getPropertyValue(n).trim(); return v||fb; }catch(e){ return fb; }
}
var ACCENT=cssVar('--accent','#19f0c7'), NEON2=cssVar('--neon2','#ff2d95');

/* ── Animated bank facade ── */
var bk=document.getElementById('bank-canvas');
if(bk){
  var c=bk.getContext('2d');
  var BW=560, BH=190;
  var dpr=Math.min(2,window.devicePixelRatio||1);
  bk.width=BW*dpr; bk.height=BH*dpr;
  c.scale(dpr,dpr);
  // skyline window lights (deterministic)
  var wins=[];
  (function(){ var s=11; function r(){ s=(s*16807)%2147483647; return s/2147483647; }
    [[14,60,52,130],[74,92,40,98],[470,50,46,140],[524,86,30,104]].forEach(function(b){
      for(var i=0;i<10;i++) wins.push({x:b[0]+6+Math.floor(r()*(b[2]-12)),y:b[1]+8+Math.floor(r()*(b[3]-16)),p:r()*9,on:r()<.6});
    });
  })();
  var glyphs=[]; var buzzUntil=0;
  function bLoop(t){
    if(!document.body.contains(bk)) return;
    requestAnimationFrame(bLoop);
    c.clearRect(0,0,BW,BH);
    var bg=c.createLinearGradient(0,0,0,BH);
    bg.addColorStop(0,'#07070f'); bg.addColorStop(1,'#0c0c18');
    c.fillStyle=bg; c.fillRect(0,0,BW,BH);

    // skyline blocks
    c.fillStyle='#10101e';
    c.fillRect(14,60,52,130); c.fillRect(74,92,40,98);
    c.fillRect(470,50,46,140); c.fillRect(524,86,30,104);
    for(var wi=0;wi<wins.length;wi++){
      var W2=wins[wi];
      if(Math.random()<.002) W2.on=!W2.on;
      if(!W2.on) continue;
      c.fillStyle='rgba(25,240,199,'+(0.25+0.2*Math.sin(t/900+W2.p))+')';
      c.fillRect(W2.x,W2.y,4,4);
    }

    // facade: pediment + architrave + columns + steps
    var cx=BW/2;
    c.fillStyle='#141426'; c.strokeStyle='rgba(255,255,255,.12)';
    c.beginPath(); c.moveTo(cx-150,86); c.lineTo(cx+150,86); c.lineTo(cx,38); c.closePath(); c.fill(); c.stroke();
    c.fillStyle='#191930'; c.fillRect(cx-145,86,290,15); c.strokeRect(cx-145,86,290,15);
    for(var col=0;col<6;col++){
      var colx=cx-127+col*46+(col>2?44:0);
      c.fillStyle='#0d0d1d'; c.fillRect(colx,101,13,62);
      c.strokeStyle='rgba(255,255,255,.08)'; c.strokeRect(colx+.5,101.5,13,62);
    }
    c.fillStyle='#141426';
    c.fillRect(cx-150,163,300,6); c.fillRect(cx-162,169,324,6); c.fillRect(cx-174,175,348,6);

    // vault doorway + rotating dial
    c.fillStyle='#080812'; c.strokeStyle=ACCENT; c.lineWidth=1.6;
    c.fillRect(cx-27,108,54,55); c.strokeRect(cx-27,108,54,55);
    c.save(); c.translate(cx,128); c.rotate(t/4000);
    c.beginPath(); c.arc(0,0,11,0,Math.PI*2); c.stroke();
    for(var sp=0;sp<3;sp++){ var a=sp*Math.PI*2/3;
      c.beginPath(); c.moveTo(0,0); c.lineTo(Math.cos(a)*11,Math.sin(a)*11); c.stroke(); }
    c.restore();
    c.lineWidth=1;

    // credit glyphs drifting up from the vault
    if(Math.random()<.045) glyphs.push({x:cx+(Math.random()-.5)*36,y:150,life:1});
    for(var gi=glyphs.length-1;gi>=0;gi--){
      var G=glyphs[gi];
      G.y-=.3; G.life-=.011;
      if(G.life<=0){ glyphs.splice(gi,1); continue; }
      c.globalAlpha=Math.max(0,G.life)*.6;
      c.fillStyle=ACCENT; c.font='10px monospace'; c.textAlign='center';
      c.fillText('¤',G.x,G.y);
    }
    c.globalAlpha=1;

    // neon BANK sign with rare flicker
    var on=t>buzzUntil;
    if(on&&Math.random()<.008) buzzUntil=t+80+Math.random()*300;
    var fl=on?(.8+.2*Math.sin(t/420)):.2;
    c.shadowColor=ACCENT; c.shadowBlur=14*fl;
    c.fillStyle=ACCENT; c.globalAlpha=fl;
    c.font='700 24px Orbitron, Verdana, sans-serif'; c.textAlign='center'; c.textBaseline='middle';
    c.fillText('B A N K',cx,68);
    c.shadowBlur=6*fl; c.globalAlpha=fl*.9;
    c.fillStyle=NEON2; c.font='700 8px Verdana, sans-serif';
    c.fillText('S P R A W L - 9   C R E D I T',cx,94);
    c.shadowBlur=0; c.globalAlpha=1;
  }
  requestAnimationFrame(bLoop);
}

/* ── live previews ── */
var FEE=<?= json_encode($feePct) ?>, POCKET=<?= (int)$player['creds_pocket'] ?>, BANK=<?= (int)$player['creds_bank'] ?>, RATE=<?= json_encode(BANK_INTEREST_RATE) ?>;
window.updateWdFee=function(){
  var a=parseInt(document.getElementById('wd-amt').value)||0;
  var el=document.getElementById('wd-fee-preview');
  if(!el) return;
  if(a>0){var fee=Math.max(1,Math.ceil(a*FEE));el.innerHTML='You receive: <b>'+a.toLocaleString()+'</b> cr &mdash; Fee: <b style="color:var(--neon2)">'+fee.toLocaleString()+'</b> cr &mdash; Bank pays out: <b>'+(a+fee).toLocaleString()+'</b> cr';}
  else el.innerHTML='';
};
window.updateDepHint=function(){
  var a=parseInt(document.getElementById('dep-amt').value)||0;
  var el=document.getElementById('dep-hint');
  if(!el) return;
  if(a>0){var tomorrow=Math.max(1,Math.floor((BANK+a)*RATE));el.innerHTML='Tomorrow’s interest on '+(BANK+a).toLocaleString()+' cr: <b style="color:#3bcf63">+'+tomorrow.toLocaleString()+'</b> cr';}
  else el.innerHTML='';
};
window.updateLoanHint=function(){
  var a=parseInt(document.getElementById('loan-amt').value)||0;
  var el=document.getElementById('loan-hint');
  if(!el) return;
  if(a>0){var owe=Math.ceil(a*1.045);el.innerHTML='You’ll owe: <b style="color:#ff6b35">'+owe.toLocaleString()+'</b> cr (+'+(owe-a).toLocaleString()+' surcharge)';}
  else el.innerHTML='';
};
/* withdraw Max that actually fits the fee (old Max always over-drew) */
var wdMax=document.getElementById('wd-max-btn');
if(wdMax) wdMax.addEventListener('click',function(){
  var amt=Math.floor(BANK/(1+FEE));
  while(amt>0&&amt+Math.max(1,Math.ceil(amt*FEE))>BANK) amt--;
  var inp=document.getElementById('wd-amt');
  inp.value=Math.max(0,amt);
  window.updateWdFee();
});
updateDepHint();
})();
</script>

<script>
/* Bank teller FX — overlay on document.body so it survives the AJAX swap. */
(function(){
  if(window._bankFxBound) return;
  window._bankFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#bankfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(4,4,10,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s;pointer-events:none}'
    +'#bankfx.show{opacity:1}'
    +'.bkfx-stage{position:relative;width:220px;height:120px}'
    +'.bkfx-vault{position:absolute;right:18px;top:50%;transform:translateY(-50%);width:58px;height:58px;border-radius:50%;'
    +'border:3px solid var(--bk-col);background:#0c0c18;box-shadow:0 0 18px var(--bk-col-a),inset 0 0 12px rgba(0,0,0,.7)}'
    +'.bkfx-vault::after{content:"";position:absolute;inset:12px;border-radius:50%;border:2px solid var(--bk-col);opacity:.5}'
    +'.bkfx-chips{position:absolute;left:14px;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:3px}'
    +'.bkfx-chip{width:46px;height:9px;border-radius:4px;background:linear-gradient(90deg,var(--bk-col),var(--bk-col-d));'
    +'box-shadow:0 0 8px var(--bk-col-a);animation:bkfxChip .5s ease-in forwards;opacity:0}'
    +'@keyframes bkfxChip{0%{opacity:0;transform:translateX(0)}25%{opacity:1}100%{opacity:0;transform:translateX(124px) scale(.5)}}'
    +'#bankfx.rev .bkfx-chip{animation-name:bkfxChipRev}'
    +'@keyframes bkfxChipRev{0%{opacity:0;transform:translateX(124px) scale(.5)}35%{opacity:1}100%{opacity:0;transform:translateX(0)}}'
    +'.bkfx-label{position:absolute;left:50%;top:104%;transform:translateX(-50%);white-space:nowrap;text-align:center;'
    +'font-size:13px;font-weight:900;letter-spacing:.1em;color:var(--bk-col);text-shadow:0 0 12px var(--bk-col);'
    +'opacity:0;animation:bkfxLbl .3s .55s forwards}'
    +'@keyframes bkfxLbl{to{opacity:1}}'
    +'.bkfx-sub{display:block;font-size:10px;font-weight:600;color:var(--text);opacity:.7;margin-top:3px;letter-spacing:.03em}';
  document.head.appendChild(css);

  var ac=null, muted=localStorage.getItem('bankMuted')==='1';
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
  window.toggleBankSound=function(){
    muted=!muted; localStorage.setItem('bankMuted',muted?'1':'0');
    var b=document.getElementById('bank-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };
  (function(){ var b=document.getElementById('bank-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; })();

  function coins(){ tone(1320,.06,'sine',.04); setTimeout(function(){tone(1760,.09,'sine',.035);},70); }
  function thunk(){ tone(120,.14,'square',.055); }

  function hexA(hex,a){
    if(hex.charAt(0)!=='#') return hex;
    var n=parseInt(hex.slice(1),16);
    return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';
  }

  function teller(label,sub,col,reverse){
    var old=document.getElementById('bankfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='bankfx';
    if(reverse) o.classList.add('rev');
    o.style.setProperty('--bk-col',col);
    o.style.setProperty('--bk-col-a',hexA(col,.4));
    o.style.setProperty('--bk-col-d',hexA(col,.55));
    var chips='';
    for(var i=0;i<4;i++) chips+='<div class="bkfx-chip" style="animation-delay:'+(i*120)+'ms"></div>';
    o.innerHTML='<div class="bkfx-stage"><div class="bkfx-chips">'+chips+'</div><div class="bkfx-vault"></div>'
      +'<div class="bkfx-label">'+label+(sub?'<span class="bkfx-sub">'+sub+'</span>':'')+'</div></div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    coins();
    setTimeout(thunk,520);
    setTimeout(function(){tone(523,.09,'sine',.04);setTimeout(function(){tone(784,.14,'sine',.04);},80);},650);
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},220);},1900);
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute) return;
    var kind=f.getAttribute('data-bankfx');
    if(!kind) return;
    var amtIn=f.querySelector('input[name=amount]');
    var amt=Math.max(0,parseInt(amtIn&&amtIn.value,10)||0);
    var fmt=amt.toLocaleString('en-US')+' cr';
    if(kind==='deposit')      teller('DEPOSITED',fmt,'#3bcf63',false);
    else if(kind==='withdraw')teller('WITHDRAWN',fmt,'#e8a33d',true);
    else if(kind==='transfer'){
      var toIn=f.querySelector('input[name=to]');
      teller('TRANSFER SENT',fmt+(toIn&&toIn.value?' → '+toIn.value:''),'#19f0c7',false);
    }
    else if(kind==='borrow')  teller('LOAN ISSUED',fmt+' (+4.5% owed)','#ff6b35',true);
    else if(kind==='repay'){
      var owed=parseInt(f.getAttribute('data-bank-loan'),10)||0;
      var cleared=amt>=owed;
      teller(cleared?'DEBT CLEARED':'DEBT REPAID',fmt,'#3bcf63',false);
    }
  },true);
})();
</script>
