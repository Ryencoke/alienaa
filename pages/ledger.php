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
