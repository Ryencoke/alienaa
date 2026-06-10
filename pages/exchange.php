<?php /* pages/exchange.php — The Exchange: Buy Shards + Subscription */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$today = date('Y-m-d');

const SUB_COST_30  = 30;
const SUB_COST_90  = 80;
const SUB_DAYS_30  = 30;
const SUB_DAYS_90  = 90;

$SHARD_PACKAGES = [
  ['id'=>'pack_10',  'shards'=>10,   'price'=>'$1.99',  'bonus'=>0,   'popular'=>false],
  ['id'=>'pack_50',  'shards'=>50,   'price'=>'$7.99',  'bonus'=>5,   'popular'=>false],
  ['id'=>'pack_100', 'shards'=>100,  'price'=>'$14.99', 'bonus'=>15,  'popular'=>true ],
  ['id'=>'pack_250', 'shards'=>250,  'price'=>'$29.99', 'bonus'=>50,  'popular'=>false],
  ['id'=>'pack_500', 'shards'=>500,  'price'=>'$49.99', 'bonus'=>125, 'popular'=>false],
  ['id'=>'pack_1k',  'shards'=>1000, 'price'=>'$89.99', 'bonus'=>300, 'popular'=>true ],
];

$SUB_PERKS = [
  '+25% XP from all combat and training',
  '+500 max Drive capacity',
  '&#9733; Subscriber badge on your handle',
  'Daily Vault gives double Shards',
  'Access to subscriber-only Lounge tab',
  'Priority customer service queue',
  'Exclusive account themes &amp; accent colors',
  'Subscriber star in chat and profiles',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {
    if ($a === 'pull') {
      if (($player['shard_pull_at'] ?? null) === $today) throw new RuntimeException('Already cracked today. Back tomorrow.');
      $isSub = is_subscribed($player);
      $got = random_int(0, $isSub ? 10 : 6);
      $pdo->prepare('UPDATE players SET shards = shards + ?, shard_pull_at = ? WHERE id = ?')->execute([$got, $today, $pid]);
      $msg = $got > 0 ? "Cracked it — {$got} Shard" . ($got === 1 ? '' : 's') . " secured." : 'Vault was empty today. Come back tomorrow.';
    }
    elseif ($a === 'subscribe30') {
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SUB_COST_30, $pid, SUB_COST_30]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Need ' . SUB_COST_30 . ' Shards to subscribe.');
      $cur   = $player['sub_until'] ?? null;
      $base  = ($cur && $cur >= $today) ? $cur : $today;
      $until = date('Y-m-d', strtotime("$base +" . SUB_DAYS_30 . " days"));
      $pdo->prepare('UPDATE players SET sub_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Subscribed for 30 days! Active until {$until}.";
    }
    elseif ($a === 'subscribe90') {
      $u = $pdo->prepare('UPDATE players SET shards = shards - ? WHERE id = ? AND shards >= ?');
      $u->execute([SUB_COST_90, $pid, SUB_COST_90]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Need ' . SUB_COST_90 . ' Shards to subscribe.');
      $cur   = $player['sub_until'] ?? null;
      $base  = ($cur && $cur >= $today) ? $cur : $today;
      $until = date('Y-m-d', strtotime("$base +" . SUB_DAYS_90 . " days"));
      $pdo->prepare('UPDATE players SET sub_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Subscribed for 90 days! Active until {$until}.";
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
  $player = current_player();
}

$sub    = is_subscribed($player);
$pulled = (($player['shard_pull_at'] ?? null) === $today);
?>

<!-- Header -->
<div class="panel" style="text-align:center">
  <h2>&#9670; The Exchange <span style="color:var(--muted);font-size:13px;font-weight:400;font-family:inherit">&mdash; The Exchange Block</span></h2>
  <p class="muted" style="margin-bottom:12px">Shards are hard currency. Scarce, shiny, and the only thing the Grid truly respects.</p>
  <div style="display:inline-flex;gap:20px;padding:10px 20px;background:var(--panel2);border:1px solid var(--line);border-radius:7px">
    <div>
      <div style="font-family:'Orbitron',sans-serif;font-size:22px;font-weight:700;color:var(--accent)"><?= number_format($player['shards']) ?></div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Shards</div>
    </div>
    <div style="width:1px;background:var(--line)"></div>
    <div>
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:<?= $sub ? '#e8d44d' : 'var(--muted)' ?>"><?= $sub ? '&#9733; Active' : 'None' ?></div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Subscription</div>
    </div>
  </div>
  <?php if ($sub): ?>
    <p style="font-size:12px;color:#e8d44d;margin:8px 0 0">Active until <?= e($player['sub_until']) ?> &mdash; <a href="index.php?p=exchange&sec=sub" style="color:#e8d44d">extend</a></p>
  <?php endif; ?>
  <?php if ($msg): ?><div class="flash flash-ok" style="margin-top:12px"><?= e($msg) ?></div><?php endif; ?>
</div>

<!-- ===================== BUY SHARDS ===================== -->
<div class="panel">
  <h3 style="margin-top:0;text-align:center;color:var(--accent)">&#9670; Buy Shards</h3>
  <p class="muted" style="text-align:center;font-size:13px;margin-bottom:16px">Purchase Shard packs to unlock subscriptions, premium features, and exclusive items.</p>

  <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:7px;padding:10px 14px;display:flex;align-items:center;gap:12px;margin-bottom:16px">
    <span style="font-size:22px">&#128274;</span>
    <div>
      <div style="font-weight:bold;font-size:13px;color:var(--neon2)">Payment Processing — Coming Soon</div>
      <div style="font-size:12px;color:var(--muted);margin-top:2px">Real-money purchases are not yet active. Contact staff or crack the daily Vault to earn Shards for now.</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">
    <?php foreach ($SHARD_PACKAGES as $pkg): ?>
    <div style="background:var(--panel2);border:2px solid <?= $pkg['popular'] ? 'rgba(25,240,199,.5)' : 'var(--line)' ?>;border-radius:8px;padding:14px;text-align:center;position:relative;<?= $pkg['popular'] ? 'box-shadow:0 0 14px rgba(25,240,199,.12)' : '' ?>">
      <?php if ($pkg['popular']): ?>
        <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--accent);color:#000;border-radius:20px;padding:2px 10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;letter-spacing:.5px;white-space:nowrap">BEST VALUE</div>
      <?php endif; ?>
      <div style="font-size:26px;margin-bottom:6px">&#9670;</div>
      <div style="font-family:'Orbitron',sans-serif;font-size:18px;font-weight:700;color:var(--accent)"><?= number_format($pkg['shards']) ?></div>
      <?php if ($pkg['bonus'] > 0): ?>
        <div style="font-size:11px;color:#3bcf63;font-weight:bold;margin-top:2px">+<?= $pkg['bonus'] ?> bonus!</div>
      <?php endif; ?>
      <div style="font-size:16px;font-weight:bold;color:var(--text);margin-top:8px"><?= $pkg['price'] ?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:2px">
        <?php $total = $pkg['shards'] + $pkg['bonus']; ?>
        <?= $total ?> total &mdash; <?= number_format(($pkg['shards'] > 0 ? (float)ltrim($pkg['price'],'$') / $total : 0), 3) ?>¢/shard
      </div>
      <button disabled style="width:100%;margin-top:10px;opacity:.4;font-size:12px">Coming Soon</button>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:14px;margin-top:16px">
    <h3 style="margin:0 0 10px;font-size:13px;color:var(--neon2)">FAQ</h3>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:12px">
      <div><b style="color:var(--text)">Are Shards refundable?</b><br><span class="muted">No. All Shard purchases are final.</span></div>
      <div><b style="color:var(--text)">Do Shards expire?</b><br><span class="muted">No. Shards stay on your account indefinitely.</span></div>
      <div><b style="color:var(--text)">Can I trade Shards?</b><br><span class="muted">Shards cannot be traded between players.</span></div>
      <div><b style="color:var(--text)">How else can I get Shards?</b><br><span class="muted">Crack the daily Vault below for 0–6 Shards per day (double for subscribers).</span></div>
    </div>
  </div>
</div>

<!-- ===================== SUBSCRIPTION ===================== -->
<div class="panel">
  <h3 style="margin-top:0;text-align:center;color:#e8d44d">&#9733; Subscriber Benefits</h3>
  <?php if ($sub): ?>
    <div style="background:rgba(59,207,99,.06);border:1px solid rgba(59,207,99,.3);border-radius:7px;padding:10px 14px;text-align:center;margin-bottom:14px">
      <b style="color:#3bcf63">&#10003; You are subscribed</b> &mdash; active until <b><?= e($player['sub_until']) ?></b>
      <br><span class="muted" style="font-size:12px">Purchase again to extend. Days stack on your current sub.</span>
    </div>
  <?php endif; ?>

  <div style="background:rgba(232,212,77,.06);border:1px solid rgba(232,212,77,.2);border-radius:7px;padding:14px;margin-bottom:14px">
    <div style="font-weight:bold;font-size:13px;color:#e8d44d;text-align:center;margin-bottom:10px">Subscriber Perks</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px">
      <?php foreach ($SUB_PERKS as $perk): ?>
        <div style="display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--muted)">
          <span style="color:#3bcf63;flex:none">&#10003;</span>
          <span><?= $perk ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <?php
    $plans = [
      ['action'=>'subscribe30', 'label'=>'30 Days', 'cost'=>SUB_COST_30, 'days'=>SUB_DAYS_30, 'badge'=>''],
      ['action'=>'subscribe90', 'label'=>'90 Days', 'cost'=>SUB_COST_90, 'days'=>SUB_DAYS_90, 'badge'=>'Best Value'],
    ];
    foreach ($plans as $plan):
      $perDay = round($plan['cost'] / $plan['days'], 1);
      $canBuy = (int)$player['shards'] >= $plan['cost'];
    ?>
    <div style="background:var(--panel2);border:2px solid <?= $plan['badge'] ? 'rgba(232,212,77,.5)' : 'var(--line)' ?>;border-radius:8px;padding:16px;text-align:center;position:relative;<?= $plan['badge'] ? 'box-shadow:0 0 14px rgba(232,212,77,.08)' : '' ?>">
      <?php if ($plan['badge']): ?>
        <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:rgba(232,212,77,.2);color:#e8d44d;border:1px solid rgba(232,212,77,.5);border-radius:20px;padding:2px 10px;font-size:10px;font-family:'Orbitron',sans-serif;font-weight:700;letter-spacing:.5px;white-space:nowrap"><?= e($plan['badge']) ?></div>
      <?php endif; ?>
      <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:var(--text)"><?= $plan['label'] ?></div>
      <div style="font-family:'Orbitron',sans-serif;font-size:24px;font-weight:700;color:#e8d44d;margin:8px 0">&#9670; <?= $plan['cost'] ?></div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:12px">(<?= $perDay ?> &#9670; / day)</div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="<?= $plan['action'] ?>">
        <button type="submit" <?= $canBuy ? '' : 'disabled' ?> style="width:100%;<?= !$canBuy ? 'opacity:.4' : '' ?>">
          <?= $sub ? '&#9733; Extend' : '&#9733; Subscribe' ?> &mdash; <?= $plan['cost'] ?> &#9670;
        </button>
      </form>
      <?php if (!$canBuy): ?><p class="muted" style="font-size:11px;margin:6px 0 0">You have <?= number_format($player['shards']) ?> &#9670; — need <?= $plan['cost'] - (int)$player['shards'] ?> more.</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================== DAILY VAULT ===================== -->
<div class="panel">
  <h3 style="margin-top:0">&#128073; Daily Shard Vault</h3>
  <p class="muted" style="font-size:13px;margin-bottom:10px">Crack the vault once a day for <b>0–<?= is_subscribed($player) ? '10' : '6' ?> Shards</b>. Subscribers get double the range. Resets at midnight server time.</p>
  <?php if ($pulled): ?>
    <div style="text-align:center;padding:14px;background:var(--panel2);border:1px solid var(--line);border-radius:7px">
      <div style="font-size:28px;margin-bottom:6px">&#128274;</div>
      <div style="color:var(--muted);font-size:13px">Already cracked today. Come back tomorrow.</div>
    </div>
  <?php else: ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="pull">
      <button type="submit" style="background:rgba(25,240,199,.1);border-color:rgba(25,240,199,.3);color:var(--accent)">&#128273; Crack the Vault</button>
    </form>
  <?php endif; ?>
</div>
