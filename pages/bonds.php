<?php /* pages/bonds.php — Credit Bonds */
/*
  CREATE TABLE IF NOT EXISTS bonds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    amount BIGINT NOT NULL,
    term_days TINYINT NOT NULL,
    interest_rate DECIMAL(5,4) NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    matures_at DATETIME NOT NULL,
    status ENUM('active','matured','withdrawn') NOT NULL DEFAULT 'active',
    INDEX idx_player (player_id),
    INDEX idx_status (status)
  ) ENGINE=InnoDB;
*/
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS bonds (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL,
    amount BIGINT NOT NULL, term_days TINYINT NOT NULL,
    interest_rate DECIMAL(5,4) NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    matures_at DATETIME NOT NULL,
    status ENUM('active','matured','withdrawn') NOT NULL DEFAULT 'active',
    INDEX idx_player (player_id), INDEX idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

const BOND_TERMS = [
  30 => ['rate' => 0.03,  'label' => '30 Days',  'pct' => '3%'],
  60 => ['rate' => 0.07,  'label' => '60 Days',  'pct' => '7%'],
  90 => ['rate' => 0.15,  'label' => '90 Days',  'pct' => '15%'],
];
const BOND_EARLY_PENALTY = 0.10; // 10% of principal forfeited on early withdrawal

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'invest') {
      $amt  = (int)($_POST['amount'] ?? 0);
      $term = (int)($_POST['term'] ?? 0);
      if (!isset(BOND_TERMS[$term])) throw new RuntimeException('Invalid term selected.');
      if ($amt < 100) throw new RuntimeException('Minimum bond amount is 100 credits.');
      if ($amt > (int)$player['creds_bank']) throw new RuntimeException('Not enough credits in bank.');

      $rate     = BOND_TERMS[$term]['rate'];
      $matureAt = date('Y-m-d H:i:s', strtotime("+{$term} days"));

      $pdo->beginTransaction();
      $u = $pdo->prepare('UPDATE players SET creds_bank = creds_bank - ? WHERE id = ? AND creds_bank >= ?');
      $u->execute([$amt, $pid, $amt]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Bank balance changed. Try again.'); }
      $pdo->prepare('INSERT INTO bonds (player_id, amount, term_days, interest_rate, matures_at) VALUES (?,?,?,?,?)')->execute([$pid, $amt, $term, $rate, $matureAt]);
      $pdo->commit();
      $payout = (int)round($amt * (1 + $rate));
      $msg = number_format($amt) . ' credits locked in a ' . $term . '-day bond. Payout on maturity: ' . number_format($payout) . ' credits.';
      $player = current_player();

    } elseif ($act === 'collect') {
      $bid = (int)($_POST['bond_id'] ?? 0);
      $q = $pdo->prepare("SELECT * FROM bonds WHERE id = ? AND player_id = ? AND status = 'active'");
      $q->execute([$bid, $pid]); $bond = $q->fetch();
      if (!$bond) throw new RuntimeException('Bond not found.');
      $matured  = strtotime($bond['matures_at']) <= time();
      $principal = (int)$bond['amount'];

      if ($matured) {
        $payout = (int)round($principal * (1 + (float)$bond['interest_rate']));
        $pdo->beginTransaction();
        $flip = $pdo->prepare("UPDATE bonds SET status='matured' WHERE id=? AND status='active'");
        $flip->execute([$bid]);
        if ($flip->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Bond already collected.'); }
        $pdo->prepare('UPDATE players SET creds_bank = creds_bank + ? WHERE id = ?')->execute([$payout, $pid]);
        $pdo->commit();
        $interest = $payout - $principal;
        $msg = 'Bond matured! Collected ' . number_format($payout) . ' credits (+' . number_format($interest) . ' interest).';
      } else {
        $penalty = (int)ceil($principal * BOND_EARLY_PENALTY);
        $payout  = $principal - $penalty;
        $pdo->beginTransaction();
        $flip = $pdo->prepare("UPDATE bonds SET status='withdrawn' WHERE id=? AND status='active'");
        $flip->execute([$bid]);
        if ($flip->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Bond already collected.'); }
        $pdo->prepare('UPDATE players SET creds_bank = creds_bank + ? WHERE id = ?')->execute([$payout, $pid]);
        $pdo->commit();
        $msg = 'Early withdrawal: received ' . number_format($payout) . ' credits. Penalty: ' . number_format($penalty) . ' credits (10% of principal).';
      }
      $player = current_player();
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

$myBonds = [];
try {
  $q = $pdo->prepare("SELECT * FROM bonds WHERE player_id = ? AND status = 'active' ORDER BY started_at DESC");
  $q->execute([$pid]); $myBonds = $q->fetchAll();
} catch (Throwable $e) {}

$history = [];
try {
  $q = $pdo->prepare("SELECT * FROM bonds WHERE player_id = ? AND status != 'active' ORDER BY started_at DESC LIMIT 10");
  $q->execute([$pid]); $history = $q->fetchAll();
} catch (Throwable $e) {}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8a33d,var(--accent),transparent)"></div>
  <div style="padding:16px 20px">
    <h2 style="margin:0 0 4px">&#128138; Credit Bonds</h2>
    <p class="muted" style="margin:0;font-size:13px">Lock your credits for guaranteed returns. The longer you hold, the more you earn.</p>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>" style="font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Bond plans -->
<div class="panel">
  <h3 style="margin-top:0">Purchase a Bond</h3>
  <p class="muted" style="font-size:13px;margin-bottom:14px">Credits are drawn from your bank. Early withdrawal incurs a <b style="color:var(--neon2)">10% principal penalty</b>.</p>
  <div style="background:rgba(255,255,255,.02);border:1px solid var(--line);border-radius:6px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:14px">
    Bank Balance: <b style="color:var(--accent)"><?= number_format($player['creds_bank']) ?> credits</b>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px">
    <?php foreach (BOND_TERMS as $days => $t):
      $eg = 1000; $payout = (int)round($eg * (1 + $t['rate']));
    ?>
    <div style="background:var(--panel2);border:2px solid <?= $days===90?'rgba(232,163,61,.5)':'var(--line)' ?>;border-radius:8px;padding:16px;position:relative;<?= $days===90?'box-shadow:0 0 14px rgba(232,163,61,.08)':'' ?>">
      <?php if ($days === 90): ?>
        <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:rgba(232,163,61,.2);color:#e8a33d;border:1px solid rgba(232,163,61,.4);border-radius:20px;padding:2px 10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;white-space:nowrap">BEST RETURN</div>
      <?php endif; ?>
      <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:#e8a33d;margin-bottom:4px"><?= $t['label'] ?></div>
      <div style="font-size:28px;font-weight:900;color:var(--accent);font-family:'Orbitron',sans-serif;margin-bottom:4px"><?= $t['pct'] ?></div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:12px">Example: 1,000 cr → <b style="color:var(--text)"><?= number_format($payout) ?> cr</b></div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="invest">
        <input type="hidden" name="term" value="<?= $days ?>">
        <input type="number" name="amount" min="100" max="<?= (int)$player['creds_bank'] ?>" placeholder="Amount (min 100)" style="width:100%;margin-bottom:8px">
        <button type="submit" style="width:100%;font-size:12px">&#128138; Lock In Bond</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Active bonds -->
<?php if (!empty($myBonds)): ?>
<div class="panel">
  <h3 style="margin-top:0">Your Active Bonds</h3>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($myBonds as $b):
      $matured  = strtotime($b['matures_at']) <= time();
      $payout   = (int)round((int)$b['amount'] * (1 + (float)$b['interest_rate']));
      $interest = $payout - (int)$b['amount'];
      $penalty  = (int)ceil((int)$b['amount'] * BOND_EARLY_PENALTY);
      $daysLeft = max(0, (int)ceil((strtotime($b['matures_at']) - time()) / 86400));
      $pct      = $matured ? 100 : max(0, min(100, round((time() - strtotime($b['started_at'])) / ((int)$b['term_days'] * 86400) * 100)));
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $matured ? 'rgba(59,207,99,.4)' : 'var(--line)' ?>;border-radius:8px;padding:14px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:<?= $matured ? '#3bcf63' : '#e8a33d' ?>">
            <?= (int)$b['term_days'] ?>-Day Bond <?= $matured ? '— MATURED &#10003;' : '' ?>
          </div>
          <div style="font-size:12px;color:var(--muted);margin-top:3px">
            Locked: <b style="color:var(--text)"><?= number_format($b['amount']) ?> cr</b>
            &middot; Payout: <b style="color:var(--accent)"><?= number_format($payout) ?> cr</b>
            (+<?= number_format($interest) ?>)
            <?php if (!$matured): ?>
              &middot; <?= $daysLeft ?> day<?= $daysLeft!==1?'s':'' ?> left
            <?php endif; ?>
          </div>
          <div style="margin-top:7px;height:4px;background:rgba(0,0,0,.3);border-radius:2px;width:200px">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $matured ? '#3bcf63' : '#e8a33d' ?>;border-radius:2px"></div>
          </div>
        </div>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="collect">
          <input type="hidden" name="bond_id" value="<?= (int)$b['id'] ?>">
          <button type="submit" style="font-size:12px;<?= $matured ? 'background:rgba(59,207,99,.12);border-color:rgba(59,207,99,.4);color:#3bcf63' : 'background:rgba(255,45,149,.08);border-color:rgba(255,45,149,.25);color:var(--neon2)' ?>" <?= !$matured ? 'onclick="return confirm(\'Early withdrawal: you will pay a 10% principal penalty ('.$penalty.' cr). Continue?\')"' : '' ?>>
            <?= $matured ? '&#9989; Collect' : '&#9888; Withdraw Early' ?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- History -->
<?php if (!empty($history)): ?>
<div class="panel">
  <h3 style="margin-top:0">Bond History</h3>
  <div style="overflow-x:auto">
    <table>
      <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">
        <th>Term</th><th>Principal</th><th>Status</th><th>Date</th>
      </tr>
      <?php foreach ($history as $b): $statusColor = $b['status']==='matured' ? '#3bcf63' : 'var(--neon2)'; ?>
      <tr>
        <td><?= (int)$b['term_days'] ?> days</td>
        <td><?= number_format($b['amount']) ?> cr</td>
        <td style="color:<?= $statusColor ?>;font-weight:700"><?= ucfirst($b['status']) ?></td>
        <td class="muted"><?= e(date('M j Y', strtotime($b['started_at']))) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>
