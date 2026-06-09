<?php /* pages/ledger.php — Bank (deposit / withdraw / transfer / loan) */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

$loanCap = 500 + (int)$player['level'] * 250;   // borrowing limit scales with level

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
      $u = $pdo->prepare('UPDATE players SET creds_bank = creds_bank - ?, creds_pocket = creds_pocket + ?
                          WHERE id = ? AND creds_bank >= ?');
      $u->execute([$amt, $amt, $pid, $amt]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not that many creds in the bank.');
      $msg = number_format($amt) . ' creds withdrawn.';
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

  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p><b>Teller:</b> You have <b><?= number_format($player['creds_bank']) ?></b> creds in the bank and
     <b><?= number_format($player['creds_pocket']) ?></b> in pocket.
     <?php if ($loan > 0): ?> You owe the bank <b style="color:var(--neon2)"><?= number_format($loan) ?></b> creds.<?php endif; ?></p>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">

  <div class="panel">
    <h3>Deposit</h3>
    <p class="muted">Move creds from pocket into the bank.</p>
    <form method="post">
      <input type="hidden" name="action" value="deposit">
      <input type="number" name="amount" min="1" max="<?= $player['creds_pocket'] ?>" value="<?= $player['creds_pocket'] ?>">
      <p><button type="submit">Deposit</button>
         <button type="submit" name="all" value="1">Deposit All</button></p>
    </form>
  </div>

  <div class="panel">
    <h3>Withdraw</h3>
    <p class="muted">Pull creds from the bank into your pocket.</p>
    <form method="post">
      <input type="hidden" name="action" value="withdraw">
      <input type="number" name="amount" min="1" max="<?= $player['creds_bank'] ?>">
      <p><button type="submit">Withdraw</button>
         <button type="submit" name="all" value="1">Withdraw All</button></p>
    </form>
  </div>

  <div class="panel">
    <h3>Transfer</h3>
    <p class="muted">Send creds to another ghost (from your pocket).</p>
    <form method="post">
      <input type="hidden" name="action" value="transfer">
      <label>To (handle)</label>
      <p><input type="text" name="to" maxlength="32"></p>
      <label>Amount</label>
      <p><input type="number" name="amount" min="1" max="<?= $player['creds_pocket'] ?>"></p>
      <p><button type="submit">Transfer</button></p>
    </form>
  </div>

  <div class="panel">
    <h3>Bank Loan</h3>
    <p class="muted">Borrow up to <b><?= number_format($avail) ?></b> creds (+4.5% surcharge).</p>
    <form method="post">
      <input type="hidden" name="action" value="borrow">
      <label>Amount</label>
      <p><input type="number" name="amount" min="1" max="<?= $avail ?>"></p>
      <p>
        <label style="display:inline"><input type="radio" name="dest" value="pocket" checked style="width:auto"> Pocket</label>
        &nbsp;
        <label style="display:inline"><input type="radio" name="dest" value="bank" style="width:auto"> Bank</label>
      </p>
      <p><button type="submit">Borrow</button></p>
    </form>
    <?php if ($loan > 0): ?>
    <form method="post" style="border-top:1px solid var(--line);margin-top:10px;padding-top:10px">
      <input type="hidden" name="action" value="repay">
      <label>Repay from pocket</label>
      <p><input type="number" name="amount" min="1" max="<?= min((int)$player['creds_pocket'], $loan) ?>"></p>
      <p><button type="submit">Repay</button></p>
    </form>
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
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
    <?php foreach ($tiles as $lbl => $val): ?>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:12px;text-align:center">
        <div class="muted" style="font-size:11px"><?= e($lbl) ?></div>
        <div style="font-size:18px;font-weight:bold;color:var(--accent);margin-top:3px"><?= $val ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
