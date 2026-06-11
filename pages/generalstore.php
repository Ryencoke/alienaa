<?php /* pages/generalstore.php — The Supply Node: consumables and gear */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Ensure inventory table exists (player_general_items)
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS player_general_items (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    item_id   VARCHAR(64) NOT NULL,
    quantity  INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_pi (player_id, item_id),
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// Catalog  [id, name, icon, desc, price, effect, amount]
// effect: 'integrity' | 'signal' | 'cycles' | null (collectible)
$CATALOG = [
  ['patch_kit',      'Field Patch Kit',      '&#129657;', 'Nano-polymer bandages. Seals hull breaches fast.',             120,  'integrity',  20],
  ['signal_boost',   'Signal Booster',       '&#128246;', 'Broadband relay chip. Instant signal surge.',                  90,  'signal',     15],
  ['cycle_chip',     'Drive Recharger',      '&#128297;', 'Micro-reactor cartridge. Tops up your Drive.',    110,  'cycles',     15],
  ['stim_pack',      'Combat Stim Pack',     '&#9889;',   'Fast-acting adrenaline compound. Health burst in a pinch.', 200, 'integrity',  40],
  ['overclk_chip',   'Overclock Chip',       '&#128301;', 'Pushes Drive capacity into the red zone. Temporary boost.',   180,  'cycles',     30],
  ['booster_array',  'Signal Array',         '&#128225;', 'Dedicated comms module. Massive signal restoration.',         240,  'signal',     40],
  ['ration_bar',     'Synth Ration Bar',     '&#129365;', 'Tasteless. Calorie-dense. Restores a bit of Health.',                 30,  'integrity',   8],
  ['data_spike',     'Data Spike',           '&#128300;', 'Disposable hacking tool. Has non-combat utility.',             80,  null,          0],
  ['smoke_canister', 'Smoke Canister',       '&#128168;', 'Thermal obscurant. Useful for exiting bad situations.',        60,  null,          0],
  ['burner_chip',    'Burner ID Chip',       '&#128083;', 'Pre-loaded false identity. Single use.',                      150,  null,          0],
  ['duct_tape',      'Industrial Duct Tape', '&#129683;', 'Fixes everything. No, really. Everything.',                    25,  null,          0],
  ['energy_drink',   'Reactor Brew',         '&#127866;', 'High-voltage synth caffeine. Cycle recovery bonus.',           45,  'cycles',     10],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
  $iid = $_POST['item_id'] ?? '';
  $item = null;
  foreach ($CATALOG as $c) { if ($c[0] === $iid) { $item = $c; break; } }
  try {
    if (!$item) throw new RuntimeException('Item not found.');
    $price = $item[4];
    $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
    $u->execute([$price, $pid, $price]);
    if ($u->rowCount() !== 1) throw new RuntimeException('Not enough creds. Need ' . number_format($price) . ' &#9670;');
    $effect = $item[5]; $amount = (int)$item[6];
    if ($effect) {
      // Apply stat up to its max
      $cols = ['integrity'=>['integrity','integrity_max'],'signal'=>['signal','signal_max'],'cycles'=>['cycles','cycles_max']];
      if (isset($cols[$effect])) {
        [$col, $maxCol] = $cols[$effect];
        $pdo->prepare("UPDATE players SET {$col} = LEAST({$maxCol}, {$col} + ?) WHERE id = ?")->execute([$amount, $pid]);
      }
    } else {
      // Collectible — add to player_general_items
      $pdo->prepare('INSERT INTO player_general_items (player_id, item_id, quantity) VALUES (?,?,1)
                     ON DUPLICATE KEY UPDATE quantity = quantity + 1')->execute([$pid, $iid]);
    }
    $player = current_player();
    $msg = 'Bought: ' . e($item[1]) . ($effect ? '. Effect applied.' : '. Added to stash.');
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

// Load inventory quantities
$invQ = $pdo->prepare('SELECT item_id, quantity FROM player_general_items WHERE player_id = ?');
$invQ->execute([$pid]);
$inv = [];
foreach ($invQ as $r) $inv[$r['item_id']] = (int)$r['quantity'];
?>
<div class="panel">
  <h2>&#127978; The Supply Node &mdash; General Store</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">"We stock what the Sprawl needs. Mostly."</p>
  <?php if ($msg): ?><div class="flash flash-ok"><?= $msg ?></div><?php endif; ?>
  <div style="text-align:center;margin:8px 0">
    <span class="muted" style="font-size:12px">Creds:&nbsp;</span>
    <span style="font-family:'Orbitron',sans-serif;font-weight:bold;color:var(--accent);font-size:1.3rem"><?= number_format($player['creds_pocket']) ?></span>
  </div>
</div>

<div class="shop-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
<?php foreach ($CATALOG as $c): [$iid,$name,$icon,$desc,$price,$effect,$amount] = $c; ?>
<div class="panel" style="margin-bottom:0;padding:12px">
  <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px">
    <span style="font-size:28px;flex:none"><?= $icon ?></span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:bold;font-size:13px;color:var(--accent)"><?= e($name) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= e($desc) ?></div>
      <?php if ($effect && $amount > 0):
          $effectLabel = ['integrity'=>'Health','cycles'=>'Drive','signal'=>'Signal'];
          $eLabel = $effectLabel[$effect] ?? ucfirst($effect); ?>
        <div style="font-size:10px;color:var(--neon2);margin-top:3px">
          +<?= $amount ?> <?= $eLabel ?>
        </div>
      <?php elseif (!$effect): ?>
        <div style="font-size:10px;color:var(--muted);margin-top:3px">
          <?php if (isset($inv[$iid]) && $inv[$iid] > 0): ?>Owned: &times;<?= $inv[$iid] ?><?php else: ?>Collectible<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between">
    <span style="font-weight:bold;color:var(--accent);font-size:13px"><?= number_format($price) ?> creds</span>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="buy">
      <input type="hidden" name="item_id" value="<?= e($iid) ?>">
      <button type="submit" <?= (int)$player['creds_pocket'] < $price ? 'disabled style="opacity:.4"' : '' ?>>Buy</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>

<p class="muted" style="text-align:center;margin-top:14px"><a href="index.php?p=city">&larr; Back to The Sprawl</a></p>
