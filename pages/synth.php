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
  // Herb potions — ingredients grown at the Hydrofarms (vats.php)
  ['id'=>'verdant_tonic','name'=>'Verdant Tonic',       'icon'=>'&#129716;','desc'=>'Algae infusion — restores 40 Health',                'effect'=>'integrity',  'amount'=>40,  'recipe'=>[['herb'=>'nutriblast','q'=>6],['id'=>'hemosyn','q'=>1]],          'skill'=>0],
  ['id'=>'kelp_elixir', 'name'=>'Kelp Elixir',          'icon'=>'&#129380;','desc'=>'Frond distillate — restores 25 Signal',              'effect'=>'signal',     'amount'=>25,  'recipe'=>[['herb'=>'synth_kelp','q'=>5],['id'=>'nanoweave','q'=>1]],        'skill'=>1],
  ['id'=>'fungal_brew', 'name'=>'Fungal Draught',       'icon'=>'&#127862;','desc'=>'Mycelium ferment — restores 60 Drive',               'effect'=>'cycles',     'amount'=>60,  'recipe'=>[['herb'=>'hydro_fungi','q'=>5],['id'=>'lumiphyte','q'=>1]],       'skill'=>2],
  ['id'=>'bio_catalyst','name'=>'Bio-Catalyst Draught', 'icon'=>'&#129514;','desc'=>'Living culture — restores 60 Health + 30 Drive',     'effect'=>'bio',        'amount'=>60,  'recipe'=>[['herb'=>'bio_culture','q'=>4],['id'=>'ferrocrystal','q'=>1]],    'skill'=>3],
];
// Potion liquid colors (used by the brew animation)
$STIM_COLORS = ['integrity'=>'#3bcf63','signal'=>'#ff2d95','cycles'=>'#e8a33d','crisis'=>'#e8d44d','atk_temp'=>'#ff6b35','def_temp'=>'#4d9be8','bio'=>'#a66de8'];

// ── Herbs (grown at the Hydrofarms grow bay — vats.php) ───────────────────
$HERBS = [
  'nutriblast'  => ['name'=>'Nutriblast Algae', 'icon'=>'&#127807;', 'col'=>'#3bcf63'],
  'synth_kelp'  => ['name'=>'Kelp Frond',       'icon'=>'&#127804;', 'col'=>'#19f0c7'],
  'hydro_fungi' => ['name'=>'Fungal Cap',       'icon'=>'&#127812;', 'col'=>'#e8a33d'],
  'bio_culture' => ['name'=>'Bio-Culture Pod',  'icon'=>'&#129514;', 'col'=>'#a66de8'],
];

$RARITY_COLORS = ['common'=>'var(--muted)','uncommon'=>'var(--accent)','rare'=>'var(--neon2)','legendary'=>'#e8d44d'];

// ── Load player data ──────────────────────────────────────────────────────
$biochem = 0;
try {
  $pdo->prepare('INSERT IGNORE INTO player_skills (player_id, skill_id, points) SELECT ?, id, 0 FROM skills')->execute([$pid]);
  $sq = $pdo->prepare("SELECT FLOOR(ps.points / 100) FROM player_skills ps JOIN skills s ON s.id = ps.skill_id WHERE ps.player_id = ? AND s.code = 'chem'");
  $sq->execute([$pid]);
  $biochem = (int)($sq->fetchColumn() ?: 0);
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

// Load herbs (grown at the Hydrofarms)
$myHerbs = [];
try {
  $sq->execute(["vat_herbs:{$pid}"]); $hv = $sq->fetchColumn();
  if ($hv) $myHerbs = json_decode($hv, true) ?: [];
} catch (Throwable $e) {}

// Signal hotspots must persist server-side (session) rather than being
// regenerated fresh on every load — otherwise the value is purely decorative
// and a forged gather POST can claim a max bonus chance regardless of what
// was actually shown. Generated once per compound, then carried forward.
if (!isset($_SESSION['synth_signals']) || !is_array($_SESSION['synth_signals'])) {
  $_SESSION['synth_signals'] = [];
}
foreach ($COMPOUNDS as $c) {
  if (!isset($_SESSION['synth_signals'][$c['id']])) $_SESSION['synth_signals'][$c['id']] = random_int(0, 3);
}
$signals = $_SESSION['synth_signals'];

// ── Handle actions ────────────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['gather','brew','stims']) ? $_GET['tab'] : 'gather';

// Lock a settings JSON blob for the current transaction and return it decoded.
// The page-top reads above are display-only; mutations must re-read under
// FOR UPDATE so two parallel brews/uses can't both pass validation and
// last-write-wins each other (material dupes / one stim used N times).
$synth_lock_blob = function (string $key) use ($pdo): array {
  $pdo->prepare('INSERT IGNORE INTO settings (k,v) VALUES (?,?)')->execute([$key, '{}']);
  $q = $pdo->prepare('SELECT v FROM settings WHERE k=? FOR UPDATE');
  $q->execute([$key]);
  return json_decode($q->fetchColumn() ?: '{}', true) ?: [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'gather') {
      $compId  = $_POST['comp_id'] ?? '';
      $comp    = null;
      foreach ($COMPOUNDS as $c) { if ($c['id'] === $compId) { $comp = $c; break; } }
      if (!$comp) throw new RuntimeException('Invalid compound.');
      if ($biochem < $comp['skill']) throw new RuntimeException('Requires Streetchem Lv.' . $comp['skill'] . '.');
      $qty = max(1, (int)($_POST['qty'] ?? 1));
      $totalCost = $comp['cost'] * $qty;
      if ($cycles < $totalCost) throw new RuntimeException('Not enough Drive (' . $totalCost . ' needed).');
      // Authoritative bonus chance is the server-held signal value for this
      // compound (session), not whatever the client submitted in $_POST['sig'].
      $sigBonus = (int)($signals[$compId] ?? 0);
      // Signal hotspot bonus: each signal bar = chance of +1 extra per gather
      $bonus = 0;
      for ($i = 0; $i < $sigBonus; $i++) { if (random_int(1, 100) <= 35) $bonus++; }
      $got = $qty + $bonus;
      // Drive deduction and the compound grant must commit-or-fail together —
      // previously these were two separate operations, so a crash between them
      // could charge Drive with nothing granted.
      $pdo->beginTransaction();
      $du = $pdo->prepare('UPDATE players SET cycles = cycles - ? WHERE id = ? AND cycles >= ?');
      $du->execute([$totalCost, $pid, $totalCost]);
      if ($du->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough Drive (' . $totalCost . ' needed).'); }
      $myComps = $synth_lock_blob("synth_comp:{$pid}");
      $myComps[$compId] = ($myComps[$compId] ?? 0) + $got;
      $pdo->prepare('UPDATE settings SET v=? WHERE k=?')->execute([json_encode($myComps), "synth_comp:{$pid}"]);
      $pdo->commit();
      $msg = 'Acquired ' . $got . 'x ' . $comp['name'] . ($bonus ? " (+{$bonus} from signal hotspot!)" : '') . '.';
      $player = current_player(); $cycles = (int)$player['cycles'];
      // Refresh signals on new gather (server-side, persisted for the next request)
      foreach ($COMPOUNDS as $c2) { $_SESSION['synth_signals'][$c2['id']] = random_int(0, 3); }
      $signals = $_SESSION['synth_signals'];
      $tab = 'gather';

    } elseif ($act === 'brew') {
      $stimId = $_POST['stim_id'] ?? '';
      $stim   = null;
      foreach ($STIMS as $s) { if ($s['id'] === $stimId) { $stim = $s; break; } }
      if (!$stim) throw new RuntimeException('Invalid stim.');
      if ($biochem < $stim['skill']) throw new RuntimeException('Requires Pharmacology Lv.' . $stim['skill'] . '.');
      $pdo->beginTransaction();
      $myComps = $synth_lock_blob("synth_comp:{$pid}");
      $myHerbs = $synth_lock_blob("vat_herbs:{$pid}");
      $myStims = $synth_lock_blob("synth_stim:{$pid}");
      foreach ($stim['recipe'] as $r) {
        if (isset($r['herb'])) {
          if (($myHerbs[$r['herb']] ?? 0) < $r['q']) throw new RuntimeException('Missing herbs — grow more at the Hydrofarms.');
        } elseif (($myComps[$r['id']] ?? 0) < $r['q']) {
          throw new RuntimeException('Missing compounds for this formula.');
        }
      }
      $usedHerbs = false;
      foreach ($stim['recipe'] as $r) {
        if (isset($r['herb'])) { $myHerbs[$r['herb']] -= $r['q']; $usedHerbs = true; }
        else $myComps[$r['id']] -= $r['q'];
      }
      $myStims[$stimId] = ($myStims[$stimId] ?? 0) + 1;
      $wq = $pdo->prepare('UPDATE settings SET v=? WHERE k=?');
      $wq->execute([json_encode($myComps), "synth_comp:{$pid}"]);
      if ($usedHerbs) $wq->execute([json_encode($myHerbs), "vat_herbs:{$pid}"]);
      $wq->execute([json_encode($myStims), "synth_stim:{$pid}"]);
      $pdo->commit();
      $msg = 'Synthesized 1x ' . $stim['name'] . '!';
      $tab = 'brew';

    } elseif ($act === 'use') {
      $stimId = $_POST['stim_id'] ?? '';
      $stim   = null;
      foreach ($STIMS as $s) { if ($s['id'] === $stimId) { $stim = $s; break; } }
      if (!$stim) throw new RuntimeException('Invalid stim.');
      // Consume the stim under lock BEFORE applying effects — parallel uses of
      // a qty-1 stim previously each applied the effect (last write won the count)
      $pdo->beginTransaction();
      $myStims = $synth_lock_blob("synth_stim:{$pid}");
      if (($myStims[$stimId] ?? 0) < 1) { $pdo->rollBack(); throw new RuntimeException("You don't have that stim."); }
      $myStims[$stimId]--;
      if ($myStims[$stimId] <= 0) unset($myStims[$stimId]);
      $pdo->prepare('UPDATE settings SET v=? WHERE k=?')->execute([json_encode($myStims), "synth_stim:{$pid}"]);

      $effect = $stim['effect'];
      $amt    = (int)$stim['amount'];

      if ($effect === 'integrity') {
        $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'signal') {
        $pdo->prepare('UPDATE players SET `signal` = LEAST(signal_max, `signal` + ?) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'cycles') {
        $pdo->prepare('UPDATE players SET cycles = LEAST(cycles_max, cycles + ?) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'crisis') {
        $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?), `signal` = LEAST(signal_max, `signal` + 50) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'bio') {
        $pdo->prepare('UPDATE players SET integrity = LEAST(integrity_max, integrity + ?), cycles = LEAST(cycles_max, cycles + 30) WHERE id = ?')->execute([$amt, $pid]);
      } elseif ($effect === 'atk_temp') {
        $expiry = time() + 1800;
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["buff:atk:{$pid}", "{$amt}|{$expiry}"]);
      } elseif ($effect === 'def_temp') {
        $expiry = time() + 1800;
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["buff:def:{$pid}", "{$amt}|{$expiry}"]);
      }

      $pdo->commit();
      $msg = 'Used ' . $stim['name'] . '. ' . $stim['desc'];
      $player = current_player(); $cycles = (int)$player['cycles'];
      $tab = 'stims';
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage();
  }
}

$totalComps = array_sum($myComps);
$totalStims = array_sum($myStims);
?>

<style>
.syn-card{position:relative;overflow:hidden;background:var(--panel2);border:1px solid var(--line);border-radius:9px;padding:12px;transition:transform .12s,border-color .15s,box-shadow .15s}
.syn-card:hover:not(.locked){transform:translateY(-2px)}
.syn-card.brewable{border-color:rgba(25,240,199,.35);box-shadow:0 0 14px rgba(25,240,199,.07)}
.syn-card.locked{opacity:.5}
.syn-tab{padding:7px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s}
.syn-tab.on{border-color:var(--accent);background:rgba(25,240,199,.1);color:var(--accent);box-shadow:0 0 10px rgba(25,240,199,.12)}
.recipe-chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:2px 7px;border-radius:10px;border:1px solid}
.syn-head h2{text-shadow:0 0 14px rgba(25,240,199,.25)}
@keyframes synSigPulse{0%,100%{opacity:1}50%{opacity:.55}}
.sig-hot{animation:synSigPulse 1.2s ease-in-out infinite}
</style>

<!-- Header -->
<div class="panel syn-head" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,#3bcf63,var(--accent),var(--neon2),transparent)"></div>
  <div style="padding:14px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <h2 style="margin:0 0 2px">&#9879; The Synthesis Den</h2>
        <p class="muted" style="margin:0;font-size:12px">Acquire compounds, brew herbs from the <a href="index.php?p=vats" style="color:#3bcf63">Hydrofarms</a> into potions, stay alive longer.</p>
      </div>
      <div style="display:flex;flex-direction:column;align-items:center;padding:6px 18px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.3);border-radius:8px;box-shadow:0 0 12px rgba(25,240,199,.08)">
        <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:700;color:var(--accent);text-shadow:0 0 10px rgba(25,240,199,.4)">Lv <?= $biochem ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:1px">&#9879; Streetchem</div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <?php $tabs = ['gather'=>'&#127807; Acquire ('.number_format($totalComps).')','brew'=>'&#9879; Brew Potions','stims'=>'&#128138; My Stims ('.$totalStims.')']; foreach ($tabs as $tid=>$tl): ?>
  <a href="index.php?p=synth&tab=<?= $tid ?>" class="syn-tab <?= $tab===$tid ? 'on' : '' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($msg && $msg !== ''): ?>
<div style="background:rgba(25,240,199,.08);border:1px solid rgba(25,240,199,.25);border-radius:6px;padding:10px 14px;font-size:13px"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ===================== GATHER ===================== -->
<?php if ($tab === 'gather'): ?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
    <p class="muted" style="font-size:12px;margin:0">Spend Drive to acquire raw compounds. Each compound node has a random <b>Signal Hotspot</b> level — higher signal = bonus yield chance (35% per bar).</p>
    <div style="font-size:11px;color:var(--muted);white-space:nowrap">
      &#128267; <span style="color:#4d6be8">&#9646;&#9646;&#9646;</span>=Strong &nbsp;
      <span style="color:#e8a33d">&#9646;&#9646;&#9647;</span>=Med &nbsp;
      <span style="color:var(--muted)">&#9646;&#9647;&#9647;</span>=Weak &nbsp;
      <span style="color:var(--line)">&#9647;&#9647;&#9647;</span>=None
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">
    <?php foreach ($COMPOUNDS as $c):
      $locked  = $biochem < $c['skill'];
      $owned   = $myComps[$c['id']] ?? 0;
      $maxQty  = $c['cost'] > 0 ? max(1, (int)floor($cycles / $c['cost'])) : 1;
      $rarCol  = $RARITY_COLORS[$c['rarity']] ?? 'var(--muted)';
      $sig     = $signals[$c['id']] ?? 0;
      $sigColors= [0=>'var(--line)', 1=>'var(--muted)', 2=>'#e8a33d', 3=>'#4d6be8'];
      $sigBars = '';
      for ($bb=1;$bb<=3;$bb++) $sigBars .= '<span style="color:'.($bb<=$sig ? $sigColors[$sig] : 'var(--line)').'">&#9646;</span>';
    ?>
    <div style="background:var(--panel2);border:1px solid <?= ($sig>=3) ? '#4d6be8' : (($sig>=2) ? 'rgba(232,163,61,.4)' : ($locked ? 'var(--line)' : 'rgba(25,240,199,.15)')) ?>;border-radius:8px;padding:12px;<?= $locked ? 'opacity:.5' : '' ?>">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="font-size:22px"><?= $c['icon'] ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:13px;color:<?= $rarCol ?>"><?= e($c['name']) ?></div>
          <div style="font-size:10px;color:var(--muted);margin-top:1px"><?= e($c['desc']) ?></div>
        </div>
        <div style="text-align:right;flex:none">
          <div style="font-size:13px;letter-spacing:1px" <?= $sig>=3 ? 'class="sig-hot"' : '' ?>><?= $sigBars ?></div>
          <div style="font-size:10px;color:var(--muted)">signal</div>
          <div style="font-size:11px;color:var(--accent);font-weight:700;margin-top:2px">x<?= $owned ?></div>
        </div>
      </div>
      <?php if ($locked): ?>
        <div style="font-size:11px;color:var(--neon2)">&#128274; Streetchem Lv.<?= $c['skill'] ?></div>
      <?php else: ?>
        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
          <input type="hidden" name="action" value="gather">
          <input type="hidden" name="comp_id" value="<?= e($c['id']) ?>">
          <input type="number" name="qty" value="1" min="1" max="<?= $maxQty ?>" style="width:60px;padding:4px 6px;font-size:12px">
          <span style="font-size:10px;color:var(--muted);flex:1"><?= $c['cost'] ?> drive/unit</span>
          <button type="submit" style="padding:5px 12px;font-size:11px;<?= $sig>=3 ? 'border-color:#4d6be8;color:#4d6be8;background:rgba(77,107,232,.1)' : ($sig>=2 ? 'border-color:rgba(232,163,61,.5);color:#e8a33d;background:rgba(232,163,61,.08)' : '') ?>" <?= $cycles < $c['cost'] ? 'disabled' : '' ?>>Acquire</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================== BREW ===================== -->
<?php elseif ($tab === 'brew'): ?>

<!-- Herb stock from the Hydrofarms -->
<div class="panel" style="padding:11px 14px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);flex:none">&#129716; Herb Stock</span>
    <?php $anyHerb=false; foreach ($HERBS as $hk=>$hd): $hq3=(int)($myHerbs[$hk]??0); if($hq3<=0) continue; $anyHerb=true; ?>
    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;border:1px solid <?= $hd['col'] ?>40;border-radius:14px;padding:2px 9px;color:<?= $hd['col'] ?>">
      <?= $hd['icon'] ?> <span style="color:var(--text)"><?= e($hd['name']) ?></span> <b style="font-family:'Orbitron',sans-serif;font-size:10px">×<?= $hq3 ?></b>
    </span>
    <?php endforeach; if(!$anyHerb): ?>
    <span class="muted" style="font-size:11px">None — grow some at the <a href="index.php?p=vats" style="color:#3bcf63">Hydrofarms</a>.</span>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <p class="muted" style="font-size:12px;margin-top:0;margin-bottom:12px">Combine compounds and Hydrofarm herbs into potions. Each formula requires a minimum Streetchem level.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px">
    <?php foreach ($STIMS as $s):
      $locked  = $biochem < $s['skill'];
      $canMake = !$locked;
      foreach ($s['recipe'] as $r) {
        $have = isset($r['herb']) ? (int)($myHerbs[$r['herb']] ?? 0) : (int)($myComps[$r['id']] ?? 0);
        if ($have < $r['q']) { $canMake = false; break; }
      }
      $scol = $STIM_COLORS[$s['effect']] ?? 'var(--accent)';
    ?>
    <div class="syn-card <?= $canMake ? 'brewable' : '' ?> <?= $locked ? 'locked' : '' ?>">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $scol ?>,transparent)"></div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <span style="font-size:20px;text-shadow:0 0 8px <?= $scol ?>66"><?= $s['icon'] ?></span>
        <div>
          <div style="font-weight:700;font-size:13px;color:<?= $scol ?>"><?= e($s['name']) ?></div>
          <?php if ($locked): ?><div style="font-size:10px;color:var(--neon2)">&#128274; Streetchem Lv.<?= $s['skill'] ?></div><?php endif; ?>
        </div>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-bottom:8px"><?= e($s['desc']) ?></div>
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px">
        <?php foreach ($s['recipe'] as $r):
          if (isset($r['herb'])) {
            $hd2 = $HERBS[$r['herb']] ?? ['name'=>$r['herb'],'icon'=>'&#127807;','col'=>'#3bcf63'];
            $have = (int)($myHerbs[$r['herb']] ?? 0);
            $ing  = $hd2['icon'].' '.$hd2['name'];
          } else {
            $comp = null; foreach ($COMPOUNDS as $cc) { if ($cc['id']===$r['id']) { $comp=$cc; break; } }
            $have = (int)($myComps[$r['id']] ?? 0);
            $ing  = ($comp['icon'] ?? '').' '.($comp['name'] ?? $r['id']);
          }
          $ok = $have >= $r['q'];
        ?>
        <span class="recipe-chip" style="border-color:<?= $ok ? 'rgba(25,240,199,.3)' : 'rgba(255,45,149,.3)' ?>;color:<?= $ok ? 'var(--accent)' : 'var(--neon2)' ?>">
          <?= $r['q'] ?>× <?= $ing ?> <span style="opacity:.7">(<?= $have ?>)</span>
        </span>
        <?php endforeach; ?>
      </div>
      <form method="post" style="margin:0" <?= $canMake ? 'data-brewfx="1" data-brew-col="'.e($scol).'" data-brew-icon="'.$s['icon'].'" data-brew-name="'.e($s['name']).'"' : '' ?>>
        <input type="hidden" name="action" value="brew">
        <input type="hidden" name="stim_id" value="<?= e($s['id']) ?>">
        <button type="submit" <?= (!$canMake || $locked) ? 'disabled' : '' ?> style="width:100%;font-size:12px;<?= !$canMake ? 'opacity:.4' : 'border-color:'.$scol.'66;color:'.$scol ?>">
          <?= $locked ? 'Locked' : ($canMake ? 'Brew' : 'Missing Ingredients') ?>
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
      <?php $scol2 = $STIM_COLORS[$s['effect']] ?? 'var(--accent)'; ?>
      <div class="syn-card" style="display:flex;align-items:center;gap:10px;border-color:<?= $scol2 ?>33">
        <span style="font-size:24px;text-shadow:0 0 10px <?= $scol2 ?>66"><?= $s['icon'] ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:13px;color:<?= $scol2 ?>"><?= e($s['name']) ?> <span style="color:var(--muted);font-weight:400">x<?= $qty ?></span></div>
          <div style="font-size:11px;color:var(--muted)"><?= e($s['desc']) ?></div>
        </div>
        <form method="post" style="margin:0;flex:none" data-usefx="1" data-brew-col="<?= e($scol2) ?>" data-brew-icon="<?= $s['icon'] ?>">
          <input type="hidden" name="action" value="use">
          <input type="hidden" name="stim_id" value="<?= e($s['id']) ?>">
          <button type="submit" style="font-size:11px;padding:6px 12px;border-color:<?= $scol2 ?>66;color:<?= $scol2 ?>">Use</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($biochem === 0): ?>
<div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:7px;padding:12px;font-size:12px;color:var(--muted);text-align:center">
  &#128161; Train <b>Streetchem</b> at the <a href="index.php?p=datacore&act=lab">Skillsoft Lab</a> to unlock higher-tier compounds and stims.
</div>
<?php endif; ?>
<?php endif; ?>

<script>
/* Brew/drink FX — overlay lives on document.body so it survives the AJAX page swap. */
(function(){
  if(window._synthFxBound) return;
  window._synthFxBound=true;

  var css=document.createElement('style');
  css.textContent=
    '#brewfx{position:fixed;inset:0;z-index:10001;display:flex;align-items:center;justify-content:center;'
    +'background:rgba(3,3,10,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .2s;pointer-events:none}'
    +'#brewfx.show{opacity:1}'
    +'.bfx-flask{position:relative;width:90px;height:120px}'
    +'.bfx-glass{position:absolute;left:15px;top:0;width:60px;height:110px;border:2px solid rgba(255,255,255,.35);'
    +'border-radius:10px 10px 28px 28px;overflow:hidden;background:rgba(10,14,24,.6);box-shadow:0 0 24px var(--bfx-col)}'
    +'.bfx-liquid{position:absolute;left:0;right:0;bottom:0;height:0;background:linear-gradient(180deg,var(--bfx-col-a),var(--bfx-col));'
    +'animation:bfxFill 1s ease-out forwards}'
    +'@keyframes bfxFill{to{height:72%}}'
    +'.bfx-bub{position:absolute;bottom:6px;width:5px;height:5px;border-radius:50%;border:1px solid rgba(255,255,255,.7);'
    +'animation:bfxBub 1s ease-in infinite}'
    +'@keyframes bfxBub{0%{transform:translateY(0);opacity:0}25%{opacity:.9}100%{transform:translateY(-70px);opacity:0}}'
    +'.bfx-icon{position:absolute;left:50%;top:42%;transform:translate(-50%,-50%) scale(0);font-size:30px;'
    +'text-shadow:0 0 16px var(--bfx-col);animation:bfxPop .45s .95s cubic-bezier(.2,1.6,.4,1) forwards}'
    +'@keyframes bfxPop{to{transform:translate(-50%,-50%) scale(1)}}'
    +'.bfx-name{position:absolute;left:50%;top:118%;transform:translateX(-50%);white-space:nowrap;font-size:12px;'
    +'font-weight:700;color:var(--bfx-col);text-shadow:0 0 10px var(--bfx-col);opacity:0;animation:bfxName .3s 1s forwards}'
    +'@keyframes bfxName{to{opacity:1}}'
    +'#usefx{position:fixed;inset:0;z-index:10001;pointer-events:none;opacity:0;'
    +'background:radial-gradient(circle at 50% 55%,var(--bfx-col-a),transparent 60%)}'
    +'@keyframes usePulse{0%{opacity:0}25%{opacity:.85}100%{opacity:0}}';
  document.head.appendChild(css);

  function hexA(hex,a){
    if(hex.charAt(0)!=='#') return hex;
    var n=parseInt(hex.slice(1),16);
    return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';
  }

  function brewOverlay(col,icon,name){
    var old=document.getElementById('brewfx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='brewfx';
    o.style.setProperty('--bfx-col',col);
    o.style.setProperty('--bfx-col-a',hexA(col,.45));
    var bubs='';
    for(var i=0;i<6;i++) bubs+='<span class="bfx-bub" style="left:'+(12+Math.random()*36)+'px;animation-delay:'+(Math.random()*0.9)+'s"></span>';
    o.innerHTML='<div class="bfx-flask"><div class="bfx-glass"><div class="bfx-liquid"></div>'+bubs+'</div>'
      +'<div class="bfx-icon">'+icon+'</div>'
      +(name?'<div class="bfx-name">'+name+'</div>':'')+'</div>';
    document.body.appendChild(o);
    requestAnimationFrame(function(){o.classList.add('show');});
    setTimeout(function(){o.classList.remove('show');setTimeout(function(){o.remove();},250);},1700);
  }

  function usePulse(col){
    var old=document.getElementById('usefx'); if(old) old.remove();
    var o=document.createElement('div'); o.id='usefx';
    o.style.setProperty('--bfx-col',col);
    o.style.setProperty('--bfx-col-a',hexA(col,.35));
    o.style.animation='usePulse .9s ease-out forwards';
    document.body.appendChild(o);
    setTimeout(function(){o.remove();},1000);
  }

  document.addEventListener('submit',function(ev){
    var f=ev.target;
    if(!f||!f.getAttribute) return;
    if(f.getAttribute('data-brewfx')) brewOverlay(f.getAttribute('data-brew-col')||'#19f0c7',f.getAttribute('data-brew-icon')||'&#9879;',f.getAttribute('data-brew-name')||'');
    else if(f.getAttribute('data-usefx')) usePulse(f.getAttribute('data-brew-col')||'#19f0c7');
  },true);
})();
</script>
