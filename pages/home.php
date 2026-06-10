<?php /* pages/home.php — Hideout: player dashboard */
$pdo  = db();
$role = $player['role'] ?? 'member';
$col  = chat_color($role, $player['chat_color'] ?? '#c9d1e0');
$isSub = is_subscribed($player);

// Equipped gear (from Fabrication Lab)
$ew = $ea = null; $gearAtk = $gearDef = 0;
try {
  $geq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $geq->execute(['equipped_weapon:'.(int)$player['id']]); $wid=(int)$geq->fetchColumn();
  if ($wid>0) { $gq=$pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def FROM player_gear WHERE id=? AND player_id=?'); $gq->execute([$wid,$player['id']]); $ew=$gq->fetch()?:null; $gearAtk=$ew?(int)$ew['atk']:0; }
  $geq->execute(['equipped_armor:'.(int)$player['id']]);  $aid=(int)$geq->fetchColumn();
  if ($aid>0) { $gq=$pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def FROM player_gear WHERE id=? AND player_id=?'); $gq->execute([$aid,$player['id']]); $ea=$gq->fetch()?:null; $gearDef=$ea?(int)$ea['def']:0; }
} catch (Throwable $e) {}

// Online players
$online = 0; $recentOnline = [];
try {
  $recentOnline = $pdo->query("SELECT id,username,chat_color,role,level FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_seen DESC LIMIT 12")->fetchAll();
  $online = count($recentOnline);
} catch (Throwable $e) {}

// Today's combat summary
$todayWins = $todayLoss = $todayXp = $todayCredits = 0;
try {
  $tc = $pdo->prepare("SELECT outcome, SUM(xp_won) xp, SUM(creds_won) cr, COUNT(*) n FROM combat_log WHERE player_id = ? AND DATE(fought_at) = CURDATE() GROUP BY outcome");
  $tc->execute([(int)$player['id']]);
  foreach ($tc as $row) {
    if ($row['outcome'] === 'win') { $todayWins = (int)$row['n']; $todayXp = (int)$row['xp']; $todayCredits = (int)$row['cr']; }
    else $todayLoss = (int)$row['n'];
  }
} catch (Throwable $e) {}

$xpPct = (int)$player['xp_next'] > 0 ? min(100, round((int)$player['xp'] / (int)$player['xp_next'] * 100)) : 0;
$intPct = (int)$player['integrity_max'] > 0 ? min(100, round((int)$player['integrity'] / (int)$player['integrity_max'] * 100)) : 0;
$sigPct = (int)$player['signal_max'] > 0 ? min(100, round((int)$player['signal'] / (int)$player['signal_max'] * 100)) : 0;
$cyPct  = (int)$player['cycles_max'] > 0 ? min(100, round((int)$player['cycles'] / (int)$player['cycles_max'] * 100)) : 0;
?>

<!-- ===================== HERO ===================== -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),var(--neon2),transparent)"></div>
  <div style="padding:18px 20px">
    <div class="hh-wrap">
      <div class="hh-av"><?= mb_strtoupper(mb_substr($player['username'], 0, 1)) ?></div>
      <div class="hh-info">
        <div class="hh-name" style="color:<?= e($col) ?>">
          <?= e($player['username']) ?>
          <?php echo flag_img($player['country'] ?? ''); ?>
          <?php if ($isSub): ?><span title="Subscriber" style="color:#e8d44d;font-size:15px">&#9733;</span><?php endif; ?>
          <?php if ($role !== 'member'): ?>
            <span style="background:rgba(255,45,149,.12);border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:4px;padding:2px 8px;font-size:11px;font-family:'Orbitron',sans-serif;font-size:10px"><?= e(role_label($role)) ?></span>
          <?php endif; ?>
        </div>
        <div class="hh-badges">
          <span class="hh-badge">Lv <?= (int)$player['level'] ?></span>
          <span class="muted" style="font-size:11px">Ghost #<?= (int)$player['id'] ?></span>
          <?php if (!empty($player['created_at'])): ?>
            <span class="muted" style="font-size:11px">&#128197; <?= e(date('M Y', strtotime($player['created_at']))) ?></span>
          <?php endif; ?>
        </div>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:3px">
            <span>XP to Level <?= (int)$player['level'] + 1 ?></span>
            <span><?= number_format($player['xp']) ?> / <?= number_format($player['xp_next']) ?> <span style="color:var(--accent)">(<?= $xpPct ?>%)</span></span>
          </div>
          <div style="height:8px;background:#080812;border-radius:5px;overflow:hidden">
            <div style="width:<?= $xpPct ?>%;height:100%;background:linear-gradient(90deg,var(--accent),var(--neon2));border-radius:5px;transition:width .4s"></div>
          </div>
        </div>
      </div>
      <div class="hh-creds">
        <div class="hh-cv"><?= number_format($player['creds_pocket']) ?><span> cr</span></div>
        <div class="hh-cl">Pocket</div>
        <div class="hh-cv"><?= number_format($player['creds_bank']) ?><span> cr</span></div>
        <div class="hh-cl">Bank</div>
        <?php if (!empty($player['shards'])): ?>
          <div class="hh-cv" style="color:var(--neon2)"><?= number_format($player['shards']) ?><span style="color:var(--neon2)"> &#9670;</span></div>
          <div class="hh-cl">Shards</div>
        <?php endif; ?>
        <?php if (($player['loan'] ?? 0) > 0): ?>
          <div class="hh-cv" style="color:var(--neon2)"><?= number_format($player['loan']) ?><span> owed</span></div>
          <div class="hh-cl" style="color:var(--neon2)">Loan</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===================== GEAR ===================== -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">

  <!-- Gear -->
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:12px">&#9876; Loadout
      <span style="float:right;font-size:12px;font-weight:400">
        <?php if ($gearAtk): ?><span style="color:var(--neon2)">+<?= $gearAtk ?> ATK</span><?php endif; ?>
        <?php if ($gearAtk && $gearDef): ?> &nbsp; <?php endif; ?>
        <?php if ($gearDef): ?><span style="color:var(--accent)">+<?= $gearDef ?> DEF</span><?php endif; ?>
      </span>
    </h3>
    <?php foreach ([['weapon','&#9876;','Weapon',$ew,'rgba(255,45,149,.08)','rgba(255,45,149,.25)'],['armor','&#128737;','Armor',$ea,'rgba(25,240,199,.06)','rgba(25,240,199,.2)']] as [$slot,$icon,$label,$item,$ibg,$ibord]): ?>
    <div style="background:<?= $item ? $ibg : 'var(--panel2)' ?>;border:1px solid <?= $item ? $ibord : 'var(--line)' ?>;border-radius:7px;padding:10px 12px;margin-bottom:8px;display:flex;align-items:center;gap:10px">
      <span style="font-size:22px;opacity:<?= $item?'1':'.25' ?>"><?= $icon ?></span>
      <div style="flex:1;min-width:0">
        <?php if ($item): ?>
          <div style="font-weight:700;font-size:13px"><?= e($item['name']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= $slot==='weapon'?'&#9876; +'.((int)$item['atk']).' ATK':'&#128737; +'.((int)$item['def']).' DEF' ?></div>
        <?php else: ?>
          <div style="color:var(--muted);font-size:12px;font-style:italic">No <?= $label ?> equipped</div>
          <div style="font-size:11px;color:var(--muted);margin-top:1px"><a href="index.php?p=weaponcraft">Craft &amp; equip at Fabrication Lab</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div style="display:flex;gap:6px">
      <a href="index.php?p=weaponcraft" class="btn btn-ghost btn-sm btn-block" style="text-align:center;flex:1">&#9874; Fabrication Lab</a>
      <a href="index.php?p=pvp" class="btn btn-ghost btn-sm btn-block" style="text-align:center;flex:1">&#9876; Combat Arena</a>
    </div>
  </div>

</div>

<!-- ===================== TODAY'S ACTIVITY ===================== -->
<?php if ($todayWins + $todayLoss > 0): ?>
<div class="panel">
  <h3 style="margin-top:0;margin-bottom:12px">&#128202; Today</h3>
  <div style="display:flex;flex-wrap:wrap;gap:12px">
    <?php
    $acts = [
      ['Fights', $todayWins + $todayLoss, 'var(--text)'],
      ['Wins', $todayWins, 'var(--accent)'],
      ['Losses', $todayLoss, 'var(--neon2)'],
      ['XP Earned', '+' . number_format($todayXp), '#e8a33d'],
      ['Creds Won', '+' . number_format($todayCredits), 'var(--accent)'],
    ];
    foreach ($acts as [$lbl, $v, $c]):
    ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px 14px;text-align:center;min-width:80px">
      <div style="font-family:'Orbitron',sans-serif;font-size:15px;font-weight:700;color:<?= $c ?>"><?= $v ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
    <div style="flex:1;min-width:160px;display:flex;align-items:center">
      <a href="index.php?p=sim" style="font-size:12px;color:var(--accent)">&#9889; Go to Combat Sim &rarr;</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===================== NEWS FEED ===================== -->
<?php
$newsFeed = [];
try {
  // Messages received
  $q = $pdo->prepare("SELECT 'message' AS type, CONCAT('New message from ', s.username) AS text, m.created_at AS ts
    FROM messages m JOIN players s ON s.id = m.from_id
    WHERE m.to_id = ? AND m.is_read = 0 ORDER BY m.created_at DESC LIMIT 10");
  $q->execute([$pid]);
  foreach ($q as $r) $newsFeed[] = $r;
} catch (Throwable $e) {}
try {
  // Incoming transfers
  $q = $pdo->prepare("SELECT 'transfer' AS type, CONCAT('+', FORMAT(amount,0), ' credits received from ', p.username) AS text, t.created_at AS ts
    FROM tx_log t JOIN players p ON p.id = t.from_id
    WHERE t.to_id = ? AND t.kind = 'transfer' ORDER BY t.created_at DESC LIMIT 5");
  $q->execute([$pid]);
  foreach ($q as $r) $newsFeed[] = $r;
} catch (Throwable $e) {}
// Staff notes intentionally excluded from user notifications

// Attribute points alert
$attrPoints = 0;
try {
  $aq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $aq->execute(["attr_points:{$pid}"]);
  $attrPoints = (int)($aq->fetchColumn() ?: 0);
} catch (Throwable $e) {}
if ($attrPoints > 0) {
  $newsFeed[] = ['type'=>'levelup','text'=>"You have <b>{$attrPoints} unspent attribute points</b> from leveling up! <a href='index.php?p=training'>Visit Training &rarr;</a>",'ts'=>date('Y-m-d H:i:s')];
}

// Sort all items by ts desc
usort($newsFeed, fn($a, $b) => strtotime($b['ts'] ?? 0) <=> strtotime($a['ts'] ?? 0));
$newsGeneral  = array_filter($newsFeed, fn($r) => in_array($r['type'],['news','transfer','levelup']));
$newsPersonal = array_filter($newsFeed, fn($r) => in_array($r['type'],['message']));
$hasNews = !empty($newsFeed);
?>
<?php if ($hasNews): ?>
<div class="panel" id="home-news">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
    <h3 style="margin:0">&#128276; Notifications</h3>
    <div style="display:flex;gap:6px" id="news-tabs">
      <button onclick="switchNews('all')" class="news-tab active" data-tab="all" style="padding:4px 12px;font-size:11px;border-radius:4px;border:1px solid var(--accent);background:rgba(25,240,199,.1);color:var(--accent);cursor:pointer">All</button>
      <button onclick="switchNews('personal')" class="news-tab" data-tab="personal" style="padding:4px 12px;font-size:11px;border-radius:4px;border:1px solid var(--line);background:var(--panel2);color:var(--muted);cursor:pointer">Personal</button>
      <button onclick="switchNews('minimized')" class="news-tab" data-tab="minimized" style="padding:4px 12px;font-size:11px;border-radius:4px;border:1px solid var(--line);background:var(--panel2);color:var(--muted);cursor:pointer">&#8211;</button>
    </div>
  </div>
  <div id="news-body">
    <?php foreach ($newsFeed as $item):
      $icon = ['message'=>'&#9993;','transfer'=>'&#128178;','news'=>'&#128203;','levelup'=>'&#11088;'][$item['type']] ?? '&#8226;';
      $col  = ['message'=>'var(--accent)','transfer'=>'#3bcf63','news'=>'#e8d44d','levelup'=>'#e8d44d'][$item['type']] ?? 'var(--muted)';
      $rawText = $item['type'] === 'levelup'; // allow HTML in levelup items
    ?>
    <div class="news-item" data-ntype="<?= e($item['type']) ?>" style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04)<?= $item['type']==='levelup' ? ';background:rgba(232,212,77,.05);margin:-1px -4px;padding:8px 4px;border-radius:5px' : '' ?>">
      <span style="font-size:14px;flex:none;margin-top:1px;color:<?= $col ?>"><?= $icon ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px"><?= $rawText ? $item['text'] : e($item['text']) ?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= !empty($item['ts']) ? e(date('M j, g:ia', strtotime($item['ts']))) : '' ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($newsFeed)): ?>
      <div style="color:var(--muted);font-size:12px;padding:8px 0">No recent notifications.</div>
    <?php endif; ?>
  </div>
</div>
<script>
function switchNews(tab){
  document.querySelectorAll('.news-tab').forEach(function(b){
    var active = b.dataset.tab===tab;
    b.style.borderColor = active ? 'var(--accent)' : 'var(--line)';
    b.style.background  = active ? 'rgba(25,240,199,.1)' : 'var(--panel2)';
    b.style.color       = active ? 'var(--accent)' : 'var(--muted)';
    if(active) b.classList.add('active'); else b.classList.remove('active');
  });
  var body = document.getElementById('news-body');
  var items = body ? body.querySelectorAll('.news-item') : [];
  if(tab==='minimized'){
    if(body) body.style.display='none';
  } else {
    if(body) body.style.display='';
    items.forEach(function(el){
      if(tab==='all') el.style.display='';
      else el.style.display = el.dataset.ntype==='message' ? '' : 'none';
    });
  }
}
</script>
<?php endif; ?>


<!-- ===================== ONLINE NOW ===================== -->
<?php if ($online > 0): ?>
<div class="panel">
  <h3 style="margin-top:0;margin-bottom:10px">&#128992; Online Now
    <span class="muted" style="font-size:13px;font-weight:400">&nbsp;<?= $online ?> ghost<?= $online !== 1 ? 's' : '' ?></span>
  </h3>
  <div style="display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($recentOnline as $r): $rc = chat_color($r['role'], $r['chat_color']); ?>
    <a href="index.php?p=profile&id=<?= (int)$r['id'] ?>" class="online-pill" style="color:<?= e($rc) ?>">
      <span class="online-dot"></span><?= e($r['username']) ?><span class="muted" style="font-size:10px"> Lv<?= (int)$r['level'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
