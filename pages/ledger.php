<?php /* pages/ledger.php — Bank (deposit / withdraw / transfer / loan) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

define('BANK_INTEREST_RATE', 0.005); // 0.5% per day on bank balance
define('WITHDRAW_FEE_PCT',   0.02);  // 2% fee on withdrawals to pocket

$loanCap = 500 + (int)$player['level'] * 250;   // borrowing limit scales with level

// Auto-apply daily interest when player visits the bank
$interestEarned = 0;
try {
  $today = date('Y-m-d');
  $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $q->execute(["bank_interest:{$pid}"]);
  $lastInterest = $q->fetchColumn();
  if ($lastInterest !== $today && (int)$player['creds_bank'] > 0) {
    $interestEarned = (int)max(1, floor((int)$player['creds_bank'] * BANK_INTEREST_RATE));
    $pdo->prepare('UPDATE players SET creds_bank = creds_bank + ? WHERE id = ?')->execute([$interestEarned, $pid]);
    $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["bank_interest:{$pid}", $today]);
    $player = current_player();
    $msg = '&#9733; Daily interest applied: +' . number_format($interestEarned) . ' creds (0.5% on your balance).';
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
      if ($to === '')  throw new RuntimeException('Enter a handle to send to.');
      $t = $pdo->prepare('SELECT id FROM players WHERE username = ?'); $t->execute([$to]);
      $toId = $t->fetchColumn();
      if (!$toId)                       throw new RuntimeException('No ghost by that handle.');
      if ((int)$toId === (int)$pid)     throw new RuntimeException("You can't transfer to yourself.");

      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$amt, $pid, $amt]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough creds in pocket.'); }
      $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id = ?')->execute([$amt, $toId]);
      $pdo->commit();
      try { $pdo->prepare('INSERT INTO tx_log (from_id, to_id, kind, amount, note) VALUES (?,?,?,?,?)')
                ->execute([$pid, $toId, 'transfer', $amt, $to]); } catch (Throwable $e) {}
      $msg = 'Sent ' . number_format($amt) . ' creds to ' . $to . '.';
    }

    elseif ($action === 'borrow') {
      $amt   = (int)($_POST['amount'] ?? 0);
      $dest  = ($_POST['dest'] ?? 'pocket') === 'bank' ? 'creds_bank' : 'creds_pocket'; // whitelisted
      $avail = max(0, $loanCap - (int)($player['loan'] ?? 0));
      if ($amt <= 0)      throw new RuntimeException('Enter an amount above zero.');
      if ($amt > $avail)  throw new RuntimeException('You can only borrow up to ' . number_format($avail) . ' creds.');
      $owe = (int)ceil($amt * 1.045); // 4.5% surcharge
      $pdo->prepare("UPDATE players SET loan = loan + ?, {$dest} = {$dest} + ? WHERE id = ?")->execute([$owe, $amt, $pid]);
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
    $msg = $ex->getMessage();
  }
  $player = current_player();
}

$loan  = (int)($player['loan'] ?? 0);
$avail = max(0, $loanCap - $loan);
?>
<div class="panel">
  <h2>Bank</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">Skimming a little off the top since the blackout.</p>

  <div style="margin:0 -14px 6px">
  <svg viewBox="0 0 800 220" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"
       style="width:100%;height:auto;max-height:240px;display:block;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
    <style>
      .bg{fill:var(--bg)}
      .panln{fill:var(--panel);stroke:var(--line);stroke-width:2}
      .pan2ln{fill:var(--panel2);stroke:var(--line);stroke-width:2}
      .bgln{fill:var(--bg);stroke:var(--line);stroke-width:1.5}
      .accln{fill:none;stroke:var(--accent);stroke-width:2}
      .accbg{fill:var(--bg);stroke:var(--accent);stroke-width:2}
      .sign{fill:var(--accent);font:bold 26px Verdana,Arial,sans-serif;letter-spacing:8px}
      .sub{fill:var(--neon2);font:9px Verdana,Arial,sans-serif;letter-spacing:3px}
      .win{fill:var(--accent);opacity:.7}
    </style>
    <defs>
      <filter id="bglow" x="-40%" y="-40%" width="180%" height="180%">
        <feGaussianBlur stdDeviation="2.5" result="b"/>
        <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <rect width="800" height="220" class="bg"/>
    <!-- distant Sprawl skyline -->
    <rect x="18" y="70" width="60" height="150" class="panln"/>
    <rect x="86" y="104" width="44" height="116" class="panln"/>
    <rect x="664" y="58" width="54" height="162" class="panln"/>
    <rect x="726" y="98" width="56" height="122" class="panln"/>
    <rect x="32" y="86" width="6" height="6" class="win"/><rect x="50" y="86" width="6" height="6" class="win"/>
    <rect x="32" y="104" width="6" height="6" class="win"/><rect x="56" y="122" width="6" height="6" class="win"/>
    <rect x="678" y="76" width="6" height="6" class="win"/><rect x="696" y="76" width="6" height="6" class="win"/>
    <rect x="678" y="98" width="6" height="6" class="win"/><rect x="742" y="114" width="6" height="6" class="win"/>
    <!-- pediment + architrave -->
    <polygon points="190,106 610,106 400,42" class="panln"/>
    <rect x="196" y="106" width="408" height="20" class="pan2ln"/>
    <!-- columns -->
    <rect x="222" y="126" width="16" height="76" class="bgln"/>
    <rect x="270" y="126" width="16" height="76" class="bgln"/>
    <rect x="318" y="126" width="16" height="76" class="bgln"/>
    <rect x="466" y="126" width="16" height="76" class="bgln"/>
    <rect x="514" y="126" width="16" height="76" class="bgln"/>
    <rect x="562" y="126" width="16" height="76" class="bgln"/>
    <!-- vault doorway -->
    <rect x="364" y="132" width="72" height="70" class="accbg"/>
    <circle cx="400" cy="152" r="15" class="accln"/>
    <line x1="400" y1="152" x2="400" y2="137" class="accln"/>
    <line x1="400" y1="152" x2="413" y2="160" class="accln"/>
    <!-- steps -->
    <rect x="200" y="202" width="400" height="6" class="panln"/>
    <rect x="184" y="208" width="432" height="6" class="panln"/>
    <rect x="168" y="214" width="464" height="6" class="panln"/>
    <!-- neon signage -->
    <text x="400" y="96" text-anchor="middle" class="sign" filter="url(#bglow)">BANK</text>
    <text x="400" y="120" text-anchor="middle" class="sub">SPRAWL-9 CREDIT</text>
  </svg>
  </div>
  <p class="muted" style="text-align:center;font-style:italic;font-size:11px;margin:0 0 8px">A typical day at the Bank.</p>

  <?php if ($msg): ?><div class="flash flash-ok"><?= e($msg) ?></div><?php endif; ?>
  <p><b>Teller:</b> You have <b><?= number_format($player['creds_bank']) ?></b> creds in the bank and
     <b><?= number_format($player['creds_pocket']) ?></b> in pocket.
     <?php if ($loan > 0): ?> You owe the bank <b style="color:var(--neon2)"><?= number_format($loan) ?></b> creds.<?php endif; ?></p>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">

  <div class="panel">
    <h3>Deposit</h3>
    <p class="muted">Move creds from pocket into the bank. Earn <b style="color:var(--accent)">0.5% daily interest</b> on your balance.</p>
    <form method="post">
      <input type="hidden" name="action" value="deposit">
      <div class="field">
        <span>Amount <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(pocket: <?= number_format($player['creds_pocket']) ?> cr)</span></span>
        <div class="num-wrap">
          <input type="number" name="amount" id="dep-amt" min="1" max="<?= (int)$player['creds_pocket'] ?>" value="<?= (int)$player['creds_pocket'] ?>">
          <button type="button" class="fill-max" onclick="document.getElementById('dep-amt').value=<?= (int)$player['creds_pocket'] ?>">Max</button>
        </div>
      </div>
      <button type="submit" style="margin-top:4px">Deposit</button>
    </form>
  </div>

  <div class="panel">
    <h3>Withdraw</h3>
    <p class="muted">Pull creds from the bank into your pocket.</p>
    <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:5px;padding:7px 10px;font-size:11px;margin-bottom:12px">
      <span style="color:var(--neon2)">&#9888; 2% withdrawal fee</span> <span class="muted">deducted from your bank balance on top of the amount.</span>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="withdraw">
      <div class="field">
        <span>Amount <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(bank: <?= number_format($player['creds_bank']) ?> cr)</span></span>
        <div class="num-wrap">
          <input type="number" name="amount" id="wd-amt" min="1" max="<?= (int)$player['creds_bank'] ?>" oninput="updateWdFee()">
          <button type="button" class="fill-max" onclick="document.getElementById('wd-amt').value=<?= (int)$player['creds_bank'] ?>;updateWdFee()">Max</button>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px" id="wd-fee-preview"></div>
      </div>
      <button type="submit" style="margin-top:4px">Withdraw</button>
    </form>
    <script>
    function updateWdFee(){
      var a=parseInt(document.getElementById('wd-amt').value)||0;
      var el=document.getElementById('wd-fee-preview');
      if(a>0){var fee=Math.max(1,Math.ceil(a*0.02));el.innerHTML='You receive: <b>'+a.toLocaleString()+'</b> cr &mdash; Fee: <b style="color:var(--neon2)">'+fee.toLocaleString()+'</b> cr';}
      else el.innerHTML='';
    }
    </script>
  </div>

  <div class="panel">
    <h3>Transfer</h3>
    <p class="muted">Send pocket creds to another ghost. No fee.</p>
    <form method="post">
      <input type="hidden" name="action" value="transfer">
      <div class="field">
        <span>To (handle)</span>
        <div class="xfer-to-wrap">
          <input type="text" name="to" id="xferTo" maxlength="32" autocomplete="off">
          <div class="ac-list" id="xferAcList" style="display:none"></div>
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
      var inp=document.getElementById('xferTo'),list=document.getElementById('xferAcList');
      if(!inp||!list) return;
      var cur=-1,items=[];
      function show(names){items=names;cur=-1;
        if(!names.length){list.style.display='none';return;}
        list.innerHTML='';names.forEach(function(n,i){var d=document.createElement('div');d.className='ac-item';d.textContent=n;
          d.addEventListener('mousedown',function(e){e.preventDefault();inp.value=n;list.style.display='none';});list.appendChild(d);});
        list.style.display='block';}
      inp.addEventListener('input',function(){var q=inp.value.trim();if(q.length<1){list.style.display='none';return;}
        fetch('players_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(show).catch(function(){});});
      inp.addEventListener('keydown',function(e){if(!items.length) return;var rows=list.querySelectorAll('.ac-item');
        if(e.key==='ArrowDown'){e.preventDefault();cur=Math.min(cur+1,rows.length-1);rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);});}
        else if(e.key==='ArrowUp'){e.preventDefault();cur=Math.max(cur-1,-1);rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);});}
        else if(e.key==='Enter'&&cur>=0){e.preventDefault();inp.value=items[cur];list.style.display='none';}
        else if(e.key==='Escape'){list.style.display='none';}});
      document.addEventListener('click',function(e){if(!inp.contains(e.target)&&!list.contains(e.target)) list.style.display='none';});
    })();
    </script>
  </div>

  <div class="panel">
    <h3>Bank Loan</h3>
    <p class="muted">Borrow up to <b><?= number_format($avail) ?></b> creds. +4.5% surcharge on repayment.</p>
    <?php if ($avail > 0): ?>
    <form method="post">
      <input type="hidden" name="action" value="borrow">
      <div class="field">
        <span>Amount</span>
        <div class="num-wrap">
          <input type="number" name="amount" id="loan-amt" min="1" max="<?= (int)$avail ?>">
          <button type="button" class="fill-max" onclick="document.getElementById('loan-amt').value=<?= (int)$avail ?>">Max</button>
        </div>
      </div>
      <div class="field">
        <span>Deposit to</span>
        <div style="display:flex;gap:16px;margin-top:4px">
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0"><input type="radio" name="dest" value="pocket" checked style="width:auto;accent-color:var(--accent)"> Pocket</label>
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0"><input type="radio" name="dest" value="bank" style="width:auto;accent-color:var(--accent)"> Bank</label>
        </div>
      </div>
      <button type="submit">Borrow</button>
    </form>
    <?php else: ?>
    <p class="muted" style="font-size:12px">You've reached your borrowing limit. Repay your existing loan to borrow more.</p>
    <?php endif; ?>
    <?php if ($loan > 0): ?>
    <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:14px">
      <div style="font-size:12px;color:var(--neon2);margin-bottom:10px">&#9888; Outstanding loan: <b><?= number_format($loan) ?></b> cr</div>
      <form method="post">
        <input type="hidden" name="action" value="repay">
        <div class="field">
          <span>Repay from pocket <span class="muted" style="text-transform:none;letter-spacing:0;font-size:10px">(pocket: <?= number_format($player['creds_pocket']) ?> cr)</span></span>
          <div class="num-wrap">
            <?php $repayMax = min((int)$player['creds_pocket'], $loan); ?>
            <input type="number" name="amount" id="repay-amt" min="1" max="<?= $repayMax ?>">
            <button type="button" class="fill-max" onclick="document.getElementById('repay-amt').value=<?= $repayMax ?>">Max</button>
          </div>
        </div>
        <button type="submit">Repay</button>
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
      'Subscribers'         => number_format($subs),
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
