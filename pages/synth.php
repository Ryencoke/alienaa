<?php /* pages/synth.php — The Synthesis Den: compounds & stims */
/*
  Schema needed (run once):
  -- No new tables required; compounds and stims stored as JSON in settings table.
  -- synth_comp:{pid}  = JSON {"compound_id": qty, ...}
  -- synth_stim:{pid}  = JSON {"stim_id": qty, ...}
  -- buff:atk:{pid}    = "bonus|expiry" (existing)
  -- buff:def:{pid}    = "bonus|expiry" (existing)
*/
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// ── Compound definitions ──────────────────────────────────────────────────
$COMPOUNDS = [
  ['id'=>'nanoweave',     'name'=>'Nanoweave Fiber',       'icon'=>'&#129747;', 'desc'=>'Synthetic polymer thread harvested from grid conduits',       'cost'=>8,  'rarity'=>'common',    'skill'=>0],
  ['id'=>'hemosyn',       'name'=>'Hemosynthate',           'icon'=>'&#128420;', 'desc'=>'Dark synthetic plasma found in abandoned med-labs',           'cost'=>8,  'rarity'=>'common',    'skill'=>0],
  ['id'=>'lumiphyte',     'name'=>'Lumiphyte Extract',      'icon'=>'&#127880;', 'desc'=>'Bio-luminescent fungus from flooded sub-sectors',             'cost'=>12, 'rarity'=>'uncommon',  'skill'=>1],
  ['id'=>'toxicore',      'name'=>'Toxicore Spore',         'icon'=>'&#128037;', 'desc'=>'Mutated mold cultivated in the undercity vents',             'cost'=>14, 'rarity'=>'uncommon',  'skill'=>1],
  ['id'=>'ferrocrystal',  'name'=>'Ferrosynth Crystal',     'icon'=>'&#128142;', 'desc'=>'Metallic crystalline compound mined from derelict structures','cost'=>10, 'rarity'=>'common',    'skill'=>0],
  ['id'=>'phantom_sub',   'name'=>'Phantom Substrate',      'icon'=>'&#128376;', 'desc'=>'Spectral compound from corrupted grid nodes — very rare',     'cost'=>16, 'rarity'=>'rare',      'skill'=>3],
  ['id'=>'thermostrand',  'name'=>'Thermoweave Strand',     'icon'=>'&#127811;', 'desc'=>'Heat-reactive fiber from thermal exhaust vents',             'cost'=>12, 'rarity'=>'uncommon',  'skill'=>2],
  ['id'=>'neuralsting',   'name'=>'Neural Sting Compound',  'icon'=>'&#9889;',   'desc'=>'Mild neurotoxin with stimulative side effects',              'cost'=>8,  'rarity'=>'common',    'skill'=>0],
  ['id'=>'abyss_poly',    'name'=>'Abyss Polymer',          'icon'=>'&#128020;', 'desc'=>'Dense polymer mined from sub-ocean data cables',             'cost'=>18, 'rarity'=>'rare',      'skill'=>3],
  ['id'=>'q_catalyst',    'name'=>'Quantum Catalyst',       'icon'=>'&#9881;',   'desc'=>'Legendary compound theorized to interface with the Grid itself','cost'=>22, 'rarity'=>'legendary','skill'=>5],
];

// ── Stim definitions ──────────────────────────────────────────────────────
$STIMS = [
  ['id'=>'patch_25',   'name'=>'Integrity Patch',      'icon'=>'&#10084;', 'desc'=>'Quick-seal nanobots — restores 25 Health',         'effect'=>'integrity',  'amount'=>25,  'recipe'=>[['id'=>'hemosyn','q'=>2],['id'=>'nanoweave','q'=>1]],          'skill'=>1],
  ['id'=>'nano_stim',  'name'=>'Nano-Stim',            'icon'=>'&#128138;','desc'=>'Cellular repair stim — restores 50 Health',        'effect'=>'integrity',  'amount'=>50,  'recipe'=>[['id'=>'hemosyn','q'=>3],['id'=>'lumiphyte','q'=>1]],           'skill'=>2],
  ['id'=>'antitox',    'name'=>'Antitox Shot',          'icon'=>'&#9879;',  'desc'=>'Purges toxins, restores 15 Health',                'effect'=>'integrity',  'amount'=>15,  'recipe'=>[['id'=>'toxicore','q'=>1],['id'=>'neuralsting','q'=>2]],        'skill'=>1],
  ['id'=>'shell_proto','name'=>'Shell Protocol',        'icon'=>'&#128737;','desc'=>'Dermal hardener — +5 DEF for next fight (30 min)',    'effect'=>'def_temp',   'amount'=>5,   'recipe'=>[['id'=>'ferrocrystal','q'=>3],['id'=>'abyss_poly','q'=>1]],    'skill'=>3],
  ['id'=>'berserker',  'name'=>'Berserker Chip',        'icon'=>'&#128165;','desc'=>'Neural aggressor — +5 ATK for next fight (30 min)',   'effect'=>'atk_temp',   'amount'=>5,   'recipe'=>[['id'=>'thermostrand','q'=>2],['id'=>'hemosyn','q'=>2]],        'skill'=>2],
  ['id'=>'phantom_sv', 'name'=>'Phantom Salve',         'icon'=>'&#128376;','desc'=>'Spectral repair agent — restores 75 Health',      'effect'=>'integrity',  'amount'=>75,  'recipe'=>[['id'=>'phantom_sub','q'=>2],['id'=>'lumiphyte','q'=>2]],       'skill'=>4],
  ['id'=>'overdrive',  'name'=>'Overdrive Pulse',       'icon'=>'&#128171;','desc'=>'Signal amplifier — restores 30 Signal',              'effect'=>'signal',     'amount'=>30,  'recipe'=>[['id'=>'thermostrand','q'=>1],['id'=>'nanoweave','q'=>3]],       'skill'=>2],
  ['id'=>'crisis',     'name'=>'Crisis Protocol',       'icon'=>'&#128170;','desc'=>'Emergency kit — restores 100 Health + 50 Signal', 'effect'=>'crisis',     'amount'=>100, 'recipe'=>[['id'=>'q_catalyst','q'=>1],['id'=>'phantom_sub','q'=>1],['id'=>'abyss_poly','q'=>1]],'skill'=>5],
  ['id'=>'cycle_boost','name'=>'Drive Boost',           'icon'=>'&#9889;',  'desc'=>'Stimulant — restores 30 Drive',                     'effect'=>'cycles',     'amount'=>30,  'recipe'=>[['id'=>'neuralsting','q'=>2],['id'=>'ferrocrystal','q'=>1]],     'skill'=>1],
  ['id'=>'black_resolve','name'=>'Blacksite Protocol',  'icon'=>'&#127918;','desc'=>'Heavy stim — restores 100 Drive',                   'effect'=>'cycles',     'amount'=>100, 'recipe'=>[['id'=>'q_catalyst','q'=>1],['id'=>'thermostrand','q'=>2],['id'=>'toxicore','q'=>1]],'skill'=>4],
  ['id'=>'berserker2', 'name'=>'Berserker Rush',        'icon'=>'&#128481;','desc'=>'+8 ATK, −3 DEF for next fight (30 min)',             'effect'=>'atk_temp',   'amount'=>8,   'recipe'=>[['id'=>'thermostrand','q'=>3],['id'=>'q_catalyst','q'=>1]],      'skill'=>5],
  ['id'=>'iron_wall',  'name'=>'Iron Wall',             'icon'=>'&#127968;','desc'=>'+8 DEF for next fight (30 min)',                     'effect'=>'def_temp',   'amount'=>8,   'recipe'=>[['id'=>'abyss_poly','q'=>2],['id'=>'ferrocrystal','q'=>2],['id'=>'phantom_sub','q'=>1]],'skill'=>5],
];

$RARITY_COLORS = ['common'=>'var(--muted)','uncommon'=>'var(--accent)','rare'=>'var(--neon2)','legendary'=>'#e8d44d'];

// ── Load player data ──────────────────────────────────────────────────────
$biochem = 0;
try {
  $sq = $pdo->prepare("SELECT v FROM settings WHERE k=?");
  $sq->execute(["skill:biochem:{$pid}"]); $sv = $sq->fetchColumn();
  if ($sv !== false) $biochem = (int)$sv;
  // also try player_skills table if it exists
} catch (Throwable $e) {}
try {
  // Try skills table used by datacore
  $sq = $pdo->prepare("SELECT pts FROM player_skills WHERE pid=? AND code='pharmacology'");
  $sq->execute([$pid]); $sv = $sq->fetchColumn();
  if ($sv !== false && (int)$sv > $biochem) $biochem = (int)$sv;
} catch (Throwable $e) {}

$cycles = (int)$player['cycles'];

// Load compounds
$myComps = [];
try {
  $sq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $sq->execute(["synth_comp:{$pid}"]); $cv = $sq->fetchColumn();
  if ($cv) $myComps = json_decode($cv, true) ?: [];
} catch (Throwable $e) {}

// Load stims
$myStims = [];
try {
  $sq->execute(["synth_stim:{$pid}"]); $sv2 = $sq->fetchColumn();
  if ($sv2) $myStims = json_decode($sv2, true) ?: [];
} catch (Throwable $e) {}

// ── Handle actions ────────────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['gather','brew','stims']) ? $_GET['tab'] : 'gather';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'gather') {
      $compId = $_POST['comp_id'] ?? '';
      $comp   = null;
      foreach ($COMPOUNDS as $c) { if ($c['id'] === $compId) { $comp = $c; break; } }
      if (!$comp) throw new RuntimeException('Invalid compound.');
      if ($biochem < $comp['skill']) throw new RuntimeException('Requires Pharmacology Lv.' . $comp['skill'] . '.');
      $qty = max(1, (int)($_POST['qty'] ?? 1));
      $totalCost = $comp['cost'] * $qty;
      if ($cycles < $totalCost) throw new RuntimeException('Not enough Drive (' . $totalCost . ' needed).');
      $bonus = ($biochem >= 3 && random_int(1, 100) <= ($biochem * 8)) ? 1 : 0;
      $got   = $qty + $bonus;
      $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?')->execute([$totalCost, $pid, $totalCost]);
      $myComps[$compId] = ($myComps[$compId] ?? 0) + $got;
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["synth_comp:{$pid}", json_encode($myComps)]);
      $msg = 'Acquired ' . $got . 'x ' . $comp['name'] . ($bonus ? ' (+1 bonus!)' : '') . '.';
      $player = current_player(); $cycles = (int)$player['cycles'];
      $tab = 'gather';

    } elseif ($act === 'brew') {
      $stimId = $_POST['stim_id'] ?? '';
      $stim   = null;
      foreach ($STIMS as $s) { if ($s['id'] === $stimId) { $stim = $s; break; } }
      if (!$stim) throw new RuntimeException('Invalid stim.');
      if ($biochem < $stim['skill']) throw new RuntimeException('Requires Pharmacology Lv.' . $stim['skill'] . '.');
      foreach ($stim['recipe'] as $r) {
        if (($myComps[$r['id']] ?? 0) < $r['q']) throw new RuntimeException('Missing compounds for this formula.');
      }
      foreach ($stim['recipe'] as $r) { $myComps[$r['id']] -= $r['q']; }
      $myStims[$stimId] = ($myStims[$stimId] ?? 0) + 1;
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["synth_comp:{$pid}", json_encode($myComps)]);
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["synth_stim:{$pid}", json_encode($myStims)]);
      $msg = 'Synthesized 1x ' . $stim['name'] . '!';
      $tab = 'brew';

    } elseif ($act === 'use') {
      $stimId = $_POST['stim_id'] ?? '';
      $stim   = null;
      foreach ($STIMS as $s) { if ($s['id'] === $stimId) { $stim = $s; break; } }
      if (!$stim) throw new RuntimeException('Invalid stim.');
      if (($myStims[$stimId] ?? 0) < 1) throw new RuntimeException("You don't have that stim.");

      $effect = $stim['effect'];
      $amt    = (int)$stim['amount'];

      if ($effect === 'integrity') {
        $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'signal') {
        $pdo->prepare('UPDATE players SET signal = LEAST(signal_max, signal + ?) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'cycles') {
        $pdo->prepare('UPDATE players SET cycles = LEAST(cycles_max, cycles + ?) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'crisis') {
        $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?), signal = LEAST(signal_max, signal + 50) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'atk_temp') {
        $expiry = time() + 1800;
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["buff:atk:{$pid}", "{$amt}|{$expiry}"]);
      } elseif ($effect === 'def_temp') {
        $expiry = time() + 1800;
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["buff:def:{$pid}", "{$amt}|{$expiry}"]);
      }

      $myStims[$stimId]--;
      if ($myStims[$stimId] <= 0) unset($myStims[$stimId]);
      $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["synth_stim:{$pid}", json_encode($myStims)]);
      $msg = 'Used ' . $stim['name'] . '. ' . $stim['desc'];
      $player = current_player(); $cycles = (int)$player['cycles'];
      $tab = 'stims';
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$totalComps = array_sum($myComps);
$totalStims = array_sum($myStims);
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#3bcf63,var(--accent),transparent)"></div>
  <div style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h2 style="margin:0 0 2px">&#9879; The Synthesis Den</h2>
        <p class="muted" style="margin:0;font-size:12px">Acquire compounds, synthesize stims, stay alive longer.</p>
      </div>
      <div style="display:flex;gap:12px;font-size:12px">
        <span>Drive: <b style="color:#e8a33d"><?= number_format($cycles) ?></b></span>
        <span>Pharmacology: <b style="color:var(--accent)">Lv <?= $biochem ?></b></span>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:8px">
  <?php $tabs = ['gather'=>'&#127807; Acquire ('.number_format($totalComps).')','brew'=>'&#9879; Synthesize','stims'=>'&#128138; My Stims ('.$totalStims.')']; foreach ($tabs as $tid=>$tl): ?>
  <a href="index.php?p=synth&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? 'var(--accent)' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(25,240,199,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? 'var(--accent)' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($msg && $msg !== ''): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ===================== GATHER ===================== -->
<?php if ($tab === 'gather'): ?>
<div class="panel">
  <p class="muted" style="font-size:12px;margin-top:0;margin-bottom:12px">Spend Drive to acquire raw compounds. Higher Pharmacology level gives a bonus chance to gain extra units.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">
    <?php foreach ($COMPOUNDS as $c):
      $locked = $biochem < $c['skill'];
      $owned  = $myComps[$c['id']] ?? 0;
      $maxQty = $c['cost'] > 0 ? max(1, (int)floor($cycles / $c['cost'])) : 1;
      $rarCol = $RARITY_COLORS[$c['rarity']] ?? 'var(--muted)';
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $locked ? 'var(--line)' : 'rgba(25,240,199,.15)' ?>;border-radius:8px;padding:12px;<?= $locked ? 'opacity:.5' : '' ?>">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="font-size:22px"><?= $c['icon'] ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:13px;color:<?= $rarCol ?>"><?= e($c['name']) ?></div>
          <div style="font-size:10px;color:var(--muted);margin-top:1px"><?= e($c['desc']) ?></div>
        </div>
        <div style="text-align:right;flex:none">
          <div style="font-size:11px;color:var(--accent);font-weight:700">x<?= $owned ?></div>
          <div style="font-size:10px;color:var(--muted)">owned</div>
        </div>
      </div>
      <?php if ($locked): ?>
        <div style="font-size:11px;color:var(--neon2)">&#128274; Pharmacology Lv.<?= $c['skill'] ?></div>
      <?php else: ?>
        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
          <input type="hidden" name="action" value="gather">
          <input type="hidden" name="comp_id" value="<?= e($c['id']) ?>">
          <input type="number" name="qty" value="1" min="1" max="<?= $maxQty ?>" style="width:60px;padding:4px 6px;font-size:12px">
          <span style="font-size:10px;color:var(--muted);flex:1"><?= $c['cost'] ?> cy/unit</span>
          <button type="submit" style="padding:5px 12px;font-size:11px" <?= $cycles < $c['cost'] ? 'disabled' : '' ?>>Acquire</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================== BREW ===================== -->
<?php elseif ($tab === 'brew'): ?>
<div class="panel">
  <p class="muted" style="font-size:12px;margin-top:0;margin-bottom:12px">Combine compounds into stims. Each formula requires a minimum Pharmacology level.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px">
    <?php foreach ($STIMS as $s):
      $locked  = $biochem < $s['skill'];
      $canMake = !$locked && array_reduce($s['recipe'], fn($ok,$r) => $ok && ($myComps[$r['id']] ?? 0) >= $r['q'], true);
    ?>
    <div style="background:var(--panel2);border:1px solid <?= $canMake ? 'rgba(25,240,199,.3)' : 'var(--line)' ?>;border-radius:8px;padding:12px;<?= $locked ? 'opacity:.5' : '' ?>">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <span style="font-size:20px"><?= $s['icon'] ?></span>
        <div>
          <div style="font-weight:700;font-size:13px;color:var(--accent)"><?= e($s['name']) ?></div>
          <?php if ($locked): ?><div style="font-size:10px;color:var(--neon2)">&#128274; Pharmacology Lv.<?= $s['skill'] ?></div><?php endif; ?>
        </div>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:8px"><?= e($s['desc']) ?></div>
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px">
        <?php foreach ($s['recipe'] as $r):
          $comp = null; foreach ($COMPOUNDS as $cc) { if ($cc['id']===$r['id']) { $comp=$cc; break; } }
          $have = $myComps[$r['id']] ?? 0;
          $ok   = $have >= $r['q'];
        ?>
        <span style="font-size:10px;padding:2px 6px;border-radius:4px;border:1px solid <?= $ok ? 'rgba(25,240,199,.3)' : 'rgba(255,45,149,.3)' ?>;color:<?= $ok ? 'var(--accent)' : 'var(--neon2)' ?>">
          <?= $r['q'] ?>x <?= e($comp['name'] ?? $r['id']) ?> (<?= $have ?>)
        </span>
        <?php endforeach; ?>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="brew">
        <input type="hidden" name="stim_id" value="<?= e($s['id']) ?>">
        <button type="submit" <?= (!$canMake || $locked) ? 'disabled' : '' ?> style="width:100%;font-size:12px;<?= !$canMake ? 'opacity:.4' : '' ?>">
          <?= $locked ? '&#128274; Locked' : ($canMake ? '&#9879; Synthesize' : 'Missing Compounds') ?>
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================== STIMS ===================== -->
<?php elseif ($tab === 'stims'): ?>
<div class="panel">
  <p class="muted" style="font-size:12px;margin-top:0;margin-bottom:12px">Your synthesized stims. Use them before battle or during recovery.</p>
  <?php
  $hasStims = false;
  foreach ($STIMS as $s) { if (($myStims[$s['id']] ?? 0) > 0) { $hasStims = true; break; } }
  ?>
  <?php if (!$hasStims): ?>
    <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No stims in inventory. Head to the Synthesize tab to brew some.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">
      <?php foreach ($STIMS as $s):
        $qty = $myStims[$s['id']] ?? 0;
        if ($qty <= 0) continue;
      ?>
      <div style="background:var(--panel2);border:1px solid rgba(25,240,199,.2);border-radius:8px;padding:12px;display:flex;align-items:center;gap:10px">
        <span style="font-size:24px"><?= $s['icon'] ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:13px;color:var(--accent)"><?= e($s['name']) ?> <span style="color:var(--muted);font-weight:400">x<?= $qty ?></span></div>
          <div style="font-size:11px;color:var(--muted)"><?= e($s['desc']) ?></div>
        </div>
        <form method="post" style="margin:0;flex:none">
          <input type="hidden" name="action" value="use">
          <input type="hidden" name="stim_id" value="<?= e($s['id']) ?>">
          <button type="submit" style="font-size:11px;padding:6px 12px">Use</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($biochem === 0): ?>
<div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:7px;padding:12px;font-size:12px;color:var(--muted);text-align:center">
  &#128161; Train <b>Pharmacology</b> at the <a href="index.php?p=datacore&act=lab">Skillsoft Lab</a> to unlock higher-tier compounds and stims.
</div>
<?php endif; ?>
<?php endif; ?>
