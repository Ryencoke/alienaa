<?php /* pages/accord.php — Commerce Accord: become a protected merchant */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Add merchant_until column if missing
try { $pdo->exec("ALTER TABLE players ADD COLUMN merchant_until DATE NULL DEFAULT NULL"); } catch (Throwable $e) {}

$today = date('Y-m-d');
$isMerchant = !empty($player['merchant_until']) && $player['merchant_until'] >= $today;
$daysLeft   = $isMerchant ? (int)((strtotime($player['merchant_until']) - strtotime($today)) / 86400) + 1 : 0;

$durations = [5=>'5 Days',10=>'10 Days',30=>'30 Days',60=>'60 Days',90=>'90 Days'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'enlist') {
      if ($isMerchant) throw new RuntimeException('You are already under the Commerce Accord.');
      $days = (int)($_POST['days'] ?? 5);
      if (!isset($durations[$days])) throw new RuntimeException('Invalid duration.');
      $until = date('Y-m-d', strtotime("+{$days} days"));
      $pdo->prepare('UPDATE players SET merchant_until = ? WHERE id = ?')->execute([$until, $pid]);
      // Auto-add accord to sidebar if not present
      $sb = trim((string)($player['sidebar'] ?? ''));
      $keys = $sb !== '' ? explode(',', $sb) : [];
      if (!in_array('accord', $keys, true)) {
        $keys[] = 'accord';
        $pdo->prepare('UPDATE players SET sidebar = ? WHERE id = ?')->execute([implode(',', $keys), $pid]);
      }
      $msg = "Commerce Accord signed for {$days} days (until {$until}). Protect your ledger — no combat, no training.";
      $player = current_player();
      $isMerchant = true; $daysLeft = $days;
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}
?>
<?= scene_header('ac-canvas', '&#9878;', 'COMMERCE ACCORD',
      'Register as a neutral trader. Lower fees. No combat. No training.', 'seal', '#e8a33d') ?>
<?= scene_header_js() ?>

<?php if ($msg): ?>
<div style="background:rgba(232,163,61,.08);border:1px solid rgba(232,163,61,.3);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($isMerchant): ?>
<!-- Active accord status -->
<div class="panel" style="border:1px solid rgba(232,163,61,.4);background:rgba(232,163,61,.04)">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
    <span style="font-size:26px">&#9989;</span>
    <div>
      <div style="font-weight:700;font-size:14px;color:#e8a33d">Accord Active</div>
      <div style="font-size:12px;color:var(--muted)"><?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> remaining &middot; expires <?= e($player['merchant_until']) ?></div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
    <div style="background:rgba(59,207,99,.06);border:1px solid rgba(59,207,99,.2);border-radius:6px;padding:10px 12px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Active Benefit</div>
      <div style="font-size:13px;color:#3bcf63">&#10003; 0.5% bank withdrawal fee (was 2%)</div>
    </div>
    <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:6px;padding:10px 12px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Restrictions</div>
      <div style="font-size:12px;color:var(--neon2)">&#9888; No combat, PvP, or training</div>
    </div>
  </div>
  <div style="font-size:12px;color:var(--muted);padding:8px 0 0;border-top:1px solid var(--line)">&#128274; The Commerce Accord cannot be withdrawn early. Your status expires automatically on <strong><?= e($player['merchant_until']) ?></strong>.</div>
</div>

<?php else: ?>
<!-- Enlist form -->
<div class="panel">
  <h3 style="margin-top:0">Enlist in the Commerce Accord</h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px">
    <div style="background:rgba(59,207,99,.06);border:1px solid rgba(59,207,99,.2);border-radius:6px;padding:12px">
      <div style="font-weight:700;font-size:12px;color:#3bcf63;margin-bottom:6px">&#10003; Benefits</div>
      <ul style="margin:0;padding:0 0 0 14px;font-size:12px;color:var(--text);line-height:1.7">
        <li>Bank withdrawal fee drops to <b>0.5%</b> (from 2%)</li>
        <li>No duration cost — free to join</li>
        <li>Renew anytime after expiry</li>
      </ul>
    </div>
    <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:6px;padding:12px">
      <div style="font-weight:700;font-size:12px;color:var(--neon2);margin-bottom:6px">&#9888; Restrictions</div>
      <ul style="margin:0;padding:0 0 0 14px;font-size:12px;color:var(--text);line-height:1.7">
        <li>Cannot enter combat (PvE or PvP)</li>
        <li>Cannot use the training gym</li>
        <li>Cannot be withdrawn — permanent until expiry</li>
      </ul>
    </div>
  </div>
  <div style="background:rgba(232,163,61,.08);border:1px solid rgba(232,163,61,.35);border-radius:6px;padding:10px 14px;font-size:12px;color:#e8a33d;margin-bottom:14px">
    <strong>&#9888; Warning:</strong> The Commerce Accord is <strong>permanent for the chosen duration</strong>. Once signed, it cannot be withdrawn early.
  </div>
  <form method="post" id="accord-form" style="max-width:280px">
    <input type="hidden" name="action" value="enlist">
    <div class="field">
      <span>Duration</span>
      <select name="days">
        <?php foreach ($durations as $d => $label): ?>
        <option value="<?= $d ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="button" id="accord-sign-btn" style="background:rgba(232,163,61,.1);border-color:rgba(232,163,61,.4);color:#e8a33d">&#9878; Sign the Accord</button>
    <div id="accord-confirm" style="display:none;margin-top:10px;background:rgba(255,45,149,.07);border:1px solid rgba(255,45,149,.3);border-radius:6px;padding:12px">
      <div style="font-size:12px;color:var(--neon2);font-weight:700;margin-bottom:8px">&#9888; This is permanent. You cannot exit the Accord early.</div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:10px">Confirm to proceed — this action cannot be undone.</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" style="background:rgba(232,163,61,.1);border-color:rgba(232,163,61,.4);color:#e8a33d">&#9878; Confirm — Sign Permanently</button>
        <button type="button" id="accord-cancel-btn" style="background:var(--panel2);border-color:var(--line);color:var(--muted)">Cancel</button>
      </div>
    </div>
  </form>
  <script>
  (function(){
    var btn=document.getElementById('accord-sign-btn');
    var box=document.getElementById('accord-confirm');
    var can=document.getElementById('accord-cancel-btn');
    if(!btn||!box) return;
    btn.addEventListener('click',function(){ btn.style.display='none'; box.style.display='block'; });
    can.addEventListener('click',function(){ box.style.display='none'; btn.style.display=''; });
  })();
  </script>
</div>
<?php endif; ?>
