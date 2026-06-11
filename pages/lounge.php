<?php /* pages/lounge.php — The Static Lounge: buffs, intel, synthahol */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Schema for buff table (uses existing settings k/v pattern)
// buff:atk:{pid} = "bonus|expire_unix"
// buff:def:{pid} = same

// Load current buffs
$buffAtk = 0; $buffAtkExp = 0; $buffDef = 0; $buffDefExp = 0;
try {
  $bq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $bq->execute(["buff:atk:{$pid}"]); $bv = $bq->fetchColumn();
  if ($bv !== false && $bv !== '') { [$bon,$exp]=explode('|',$bv,2); if(time()<(int)$exp){$buffAtk=(int)$bon;$buffAtkExp=(int)$exp;} }
  $bq->execute(["buff:def:{$pid}"]); $bv = $bq->fetchColumn();
  if ($bv !== false && $bv !== '') { [$bon,$exp]=explode('|',$bv,2); if(time()<(int)$exp){$buffDef=(int)$bon;$buffDefExp=(int)$exp;} }
} catch (Throwable $e) {}

$STIMS = [
  'stim_patch'    => ['name'=>'Stim Patch',      'desc'=>'+10 ATK for 1 hour. Combat enhancement, no side effects listed.',  'atk'=>10,'def'=>0,'mins'=>60, 'cost'=>150, 'icon'=>'&#9889;', 'color'=>'var(--neon2)'],
  'armor_gel'     => ['name'=>'Armor Gel',        'desc'=>'+8 DEF for 1 hour. Hardened subdermal layer, disposable.',         'atk'=>0,'def'=>8,'mins'=>60,  'cost'=>150, 'icon'=>'&#128737;','color'=>'var(--accent)'],
  'rush_cocktail' => ['name'=>'Rush Cocktail',    'desc'=>'+15 ATK, +10 DEF for 30 min. High-intensity combat compound.',     'atk'=>15,'def'=>10,'mins'=>30,'cost'=>350, 'icon'=>'&#127864;','color'=>'#e8a33d'],
  'clarity_chip'  => ['name'=>'Clarity Chip',     'desc'=>'+20 ATK for 45 min. Neural overclocking — cortex spike included.','atk'=>20,'def'=>0,'mins'=>45, 'cost'=>500, 'icon'=>'&#128256;','color'=>'var(--neon2)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'buy_stim') {
      $stimKey = $_POST['stim'] ?? '';
      if (!isset($STIMS[$stimKey])) throw new RuntimeException('Unknown compound.');
      $stim = $STIMS[$stimKey];
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$stim['cost'], $pid, $stim['cost']]);
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough credits. Need ' . number_format($stim['cost']) . ' cr.');
      $exp = time() + $stim['mins'] * 60;
      if ($stim['atk'] > 0) {
        // Stack with existing if same type, take higher value
        $newAtk = max($buffAtk, $stim['atk']);
        $newAtkExp = $stim['atk'] >= $buffAtk ? $exp : $buffAtkExp;
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["buff:atk:{$pid}", $newAtk.'|'.$newAtkExp]);
        $buffAtk = $newAtk; $buffAtkExp = $newAtkExp;
      }
      if ($stim['def'] > 0) {
        $newDef = max($buffDef, $stim['def']);
        $newDefExp = $stim['def'] >= $buffDef ? $exp : $buffDefExp;
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["buff:def:{$pid}", $newDef.'|'.$newDefExp]);
        $buffDef = $newDef; $buffDefExp = $newDefExp;
      }
      $player = current_player();
      $msg = $stim['name'] . ' administered. Effect active for ' . $stim['mins'] . ' minutes.';
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

// Public PvP intel feed (last 15 fights)
$pvpFeed = [];
try {
  $pvpFeed = $pdo->query("SELECT l.fought_at, a.username AS atk, d.username AS def, w.username AS winner, l.rounds, l.credits_looted
    FROM pvp_log l JOIN players a ON a.id=l.attacker_id JOIN players d ON d.id=l.defender_id JOIN players w ON w.id=l.winner_id
    ORDER BY l.fought_at DESC LIMIT 15")->fetchAll();
} catch (Throwable $e) {}
?>
<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#e8a33d,var(--neon2),transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#127864; The Static Lounge</h2>
    <p class="muted" style="margin:0;font-size:12px">Cheap synthahol. Expensive company. Combat stims, street intel, and the Sprawl's dirty gossip — all under one roof.</p>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= str_contains($msg,'Not enough')||str_contains($msg,'Unknown') ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- CHEM BAR -->
<div>
  <div class="panel" style="padding:14px 16px">
    <h3 style="margin:0 0 4px">&#9879; Chem Bar</h3>
    <p class="muted" style="font-size:12px;margin:0 0 12px">Temporary combat compounds. Effects stack with gear but not each other — higher value wins.</p>

    <?php if ($buffAtk > 0 || $buffDef > 0): ?>
    <div style="background:rgba(232,163,61,.08);border:1px solid rgba(232,163,61,.25);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px">
      <div style="font-weight:700;color:#e8a33d;margin-bottom:4px">&#9889; Active Effects</div>
      <?php if ($buffAtk > 0): ?><div>+<?= $buffAtk ?> ATK — expires <span id="buffAtkTimer" data-exp="<?= $buffAtkExp ?>"></span></div><?php endif; ?>
      <?php if ($buffDef > 0): ?><div>+<?= $buffDef ?> DEF — expires <span id="buffDefTimer" data-exp="<?= $buffDefExp ?>"></span></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($STIMS as $key => $stim): ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:11px 13px;margin-bottom:8px;display:flex;align-items:center;gap:12px">
      <div style="font-size:24px;flex:none"><?= $stim['icon'] ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:13px;color:<?= $stim['color'] ?>"><?= e($stim['name']) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= e($stim['desc']) ?></div>
        <div style="font-size:11px;margin-top:4px">
          <?php if ($stim['atk'] > 0): ?><span style="color:var(--neon2)">+<?= $stim['atk'] ?> ATK</span> <?php endif; ?>
          <?php if ($stim['def'] > 0): ?><span style="color:var(--accent)">+<?= $stim['def'] ?> DEF</span> <?php endif; ?>
          <span class="muted">&middot; <?= $stim['mins'] ?> min &middot; <?= number_format($stim['cost']) ?> cr</span>
        </div>
      </div>
      <form method="post" style="margin:0;flex:none">
        <input type="hidden" name="action" value="buy_stim">
        <input type="hidden" name="stim" value="<?= $key ?>">
        <button type="submit" style="font-size:11px;padding:5px 12px;background:rgba(232,163,61,.08);border-color:rgba(232,163,61,.3);color:#e8a33d">Buy</button>
      </form>
    </div>
    <?php endforeach; ?>

    <div style="font-size:11px;color:var(--muted);margin-top:6px">Pocket: <b style="color:var(--accent)"><?= number_format($player['creds_pocket']) ?> cr</b></div>
  </div>
</div>

<!-- STREET INTEL -->
<div>
  <div class="panel" style="padding:14px 16px">
    <h3 style="margin:0 0 4px">&#128065; Street Intel</h3>
    <p class="muted" style="font-size:12px;margin:0 0 12px">The Sprawl talks. Last <?= count($pvpFeed) ?> confirmed street engagements.</p>
    <?php if (empty($pvpFeed)): ?>
    <p class="muted" style="text-align:center;padding:20px 0">The streets are quiet.</p>
    <?php else: ?>
    <?php foreach ($pvpFeed as $f):
      $ago = time() - strtotime($f['fought_at']);
      $agoStr = $ago < 60 ? 'just now' : ($ago < 3600 ? round($ago/60).'m ago' : ($ago < 86400 ? round($ago/3600).'h ago' : round($ago/86400).'d ago'));
    ?>
    <div style="border-bottom:1px solid rgba(255,255,255,.05);padding:7px 0;font-size:12px;display:flex;align-items:baseline;gap:6px;flex-wrap:wrap">
      <span style="font-size:10px;color:var(--muted);flex:none;min-width:52px"><?= $agoStr ?></span>
      <span><b style="color:var(--accent)"><?= e($f['atk']) ?></b> <span class="muted">vs</span> <b style="color:var(--neon2)"><?= e($f['def']) ?></b></span>
      <span class="muted" style="font-size:11px">&rarr; <b><?= e($f['winner']) ?></b> won (<?= (int)$f['rounds'] ?> rds<?= (int)$f['credits_looted'] > 0 ? ', '.number_format((int)$f['credits_looted']).'¢ looted' : '' ?>)</span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="panel" style="padding:14px 16px;margin-top:0">
    <h3 style="margin:0 0 8px">&#128483; Bartender Tip</h3>
    <p class="muted" style="font-size:12px;margin:0;line-height:1.7">
    <?php $tips=[
      "Stim effects are invisible to opponents until the first strike lands.",
      "Rush Cocktail is single-use but the edge it gives can end a fight in round two.",
      "Cleared intel: fixer by the transit hub pays double for high-level gear. The bazaar doesn't ask questions.",
      "Signal isn't just a stat — it's how many times you can hit the streets before the Sprawl shuts you down for the day.",
      "Spend unspent combat points. Every level you sit on them is a level your enemies aren't.",
    ]; echo e($tips[date('G') % count($tips)]); ?>
    </p>
  </div>
</div>

</div>

<script>
(function(){
  function fmtCountdown(secs){
    if(secs<=0) return 'expired';
    var m=Math.floor(secs/60), s=secs%60;
    return (m>0?m+'m ':'')+s+'s';
  }
  function tickBuffs(){
    var now=Math.floor(Date.now()/1000);
    ['buffAtkTimer','buffDefTimer'].forEach(function(id){
      var el=document.getElementById(id); if(!el) return;
      var exp=parseInt(el.dataset.exp)||0;
      el.textContent=fmtCountdown(exp-now);
    });
  }
  tickBuffs(); setInterval(tickBuffs,1000);
})();
</script>
