<?php /* pages/home.php — Hideout: player dashboard */
$pdo  = db();
$pid  = (int)$player['id'];
$role = $player['role'] ?? 'member';
$col  = chat_color($role, $player['chat_color'] ?? '#c9d1e0');
$isSub = is_subscribed($player);

// ── Equipped gear ──
$ew = $ea = null; $gearAtk = $gearDef = 0;
try {
  $geq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $geq->execute(['equipped_weapon:'.$pid]); $wid = (int)($geq->fetchColumn() ?: 0);
  if ($wid > 0) {
    $gq = $pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def FROM player_gear WHERE id=? AND player_id=?');
    $gq->execute([$wid,$pid]); $ew = $gq->fetch() ?: null;
    if (!$ew) { $gq2 = $pdo->prepare('SELECT i.id,i.name,i.atk,0 AS def FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0'); $gq2->execute([$pid,$wid]); $ew = $gq2->fetch() ?: null; }
    $gearAtk = $ew ? (int)$ew['atk'] : 0;
  }
  $geq->execute(['equipped_armor:'.$pid]); $aid = (int)($geq->fetchColumn() ?: 0);
  if ($aid > 0) {
    $gq = $pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def FROM player_gear WHERE id=? AND player_id=?');
    $gq->execute([$aid,$pid]); $ea = $gq->fetch() ?: null;
    if (!$ea) { $gq2 = $pdo->prepare('SELECT i.id,i.name,0 AS atk,i.def FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0'); $gq2->execute([$pid,$aid]); $ea = $gq2->fetch() ?: null; }
    $gearDef = $ea ? (int)$ea['def'] : 0;
  }
} catch (Throwable $e) {}

// ── Combat stats ──
$cStats = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];
try {
  $csq = $pdo->prepare('SELECT str_pts,spd_pts,end_pts,unspent FROM player_stats WHERE pid=?');
  $csq->execute([$pid]); $cs = $csq->fetch(); if ($cs) $cStats = array_merge($cStats,$cs);
} catch (Throwable $e) {}

// ── Online players ──
$recentOnline = [];
try { $recentOnline = $pdo->query("SELECT id,username,chat_color,role,level FROM players WHERE last_seen>=(NOW()-INTERVAL 5 MINUTE) ORDER BY last_seen DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) {}
$online = count($recentOnline);

// ── Today's combat ──
$todayWins = $todayLoss = $todayXp = $todayCredits = 0;
try {
  $tc = $pdo->prepare("SELECT outcome,SUM(xp_won) xp,SUM(creds_won) cr,COUNT(*) n FROM combat_log WHERE player_id=? AND DATE(fought_at)=CURDATE() GROUP BY outcome");
  $tc->execute([$pid]);
  foreach ($tc as $r) { if ($r['outcome']==='win'){ $todayWins=(int)$r['n']; $todayXp=(int)$r['xp']; $todayCredits=(int)$r['cr']; } else $todayLoss=(int)$r['n']; }
} catch (Throwable $e) {}

// ── Vital pcts ──
$xpPct  = $player['xp_next']>0         ? min(100,round($player['xp']/$player['xp_next']*100))                   : 0;
$hpPct  = $player['integrity_max']>0   ? min(100,round($player['integrity']/$player['integrity_max']*100))       : 0;
$sigPct = $player['signal_max']>0      ? min(100,round($player['signal']/$player['signal_max']*100))             : 0;
$drvPct = $player['cycles_max']>0      ? min(100,round($player['cycles']/$player['cycles_max']*100))             : 0;

// ── Notifications ──
$newsFeed = [];
try {
  $q = $pdo->prepare("SELECT 'message' AS type,CONCAT('New message from ',s.username) AS text,m.created_at AS ts FROM messages m JOIN players s ON s.id=m.from_id WHERE m.to_id=? AND m.is_read=0 ORDER BY m.created_at DESC LIMIT 10");
  $q->execute([$pid]); foreach ($q as $r) $newsFeed[] = $r;
} catch (Throwable $e) {}
try {
  $q = $pdo->prepare("SELECT 'transfer' AS type,CONCAT('+',FORMAT(amount,0),' credits received from ',p.username) AS text,t.created_at AS ts FROM tx_log t JOIN players p ON p.id=t.from_id WHERE t.to_id=? AND t.kind='transfer' ORDER BY t.created_at DESC LIMIT 5");
  $q->execute([$pid]); foreach ($q as $r) $newsFeed[] = $r;
} catch (Throwable $e) {}
$attrPoints = 0;
try { $aq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $aq->execute(["attr_points:{$pid}"]); $attrPoints = (int)($aq->fetchColumn() ?: 0); } catch (Throwable $e) {}
if ($attrPoints > 0) $newsFeed[] = ['type'=>'levelup','text'=>"You have <b>{$attrPoints} unspent attribute point".($attrPoints!==1?'s':'')."</b> from leveling up! <a href='index.php?p=training'>Visit Training &rarr;</a>",'ts'=>date('Y-m-d H:i:s')];
usort($newsFeed, fn($a,$b) => strtotime($b['ts']??0) <=> strtotime($a['ts']??0));
?>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),var(--neon2),transparent)"></div>
  <div style="padding:18px 20px">
    <div class="hh-wrap">
      <div class="hh-av"><?= mb_strtoupper(mb_substr($player['username'],0,1)) ?></div>
      <div class="hh-info">
        <div class="hh-name" style="color:<?= e($col) ?>">
          <?= e($player['username']) ?>
          <?= flag_img($player['country']??'') ?>
          <?php if ($isSub): ?><span title="Subscriber" style="color:#e8d44d;font-size:15px">&#9733;</span><?php endif; ?>
          <?php if ($role!=='member'): ?><?= role_tag($role,'font-size:10px;font-family:\'Orbitron\',sans-serif;border:1px solid rgba(255,255,255,.15);border-radius:4px;padding:2px 8px') ?><?php endif; ?>
        </div>
        <div class="hh-badges">
          <span class="hh-badge">Lv <?= (int)$player['level'] ?></span>
          <span class="muted" style="font-size:11px">Ghost #<?= $pid ?></span>
          <?php if (!empty($player['created_at'])): ?><span class="muted" style="font-size:11px">&#128197; <?= e(date('M Y',strtotime($player['created_at']))) ?></span><?php endif; ?>
        </div>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:3px">
            <span>XP &mdash; Level <?= (int)$player['level'] ?> &rarr; <?= (int)$player['level']+1 ?></span>
            <span><?= number_format((int)$player['xp']) ?> / <?= number_format((int)$player['xp_next']) ?> <span style="color:var(--accent)">(<?= $xpPct ?>%)</span></span>
          </div>
          <div style="height:7px;background:#080812;border-radius:5px;overflow:hidden">
            <div style="width:<?= $xpPct ?>%;height:100%;background:linear-gradient(90deg,var(--accent),var(--neon2));border-radius:5px;transition:width .4s"></div>
          </div>
        </div>
      </div>
      <div class="hh-creds">
        <div class="hh-cv"><?= number_format((int)$player['creds_pocket']) ?><span> cr</span></div>
        <div class="hh-cl">Pocket</div>
        <div class="hh-cv"><?= number_format((int)$player['creds_bank']) ?><span> cr</span></div>
        <div class="hh-cl">Bank</div>
        <?php if (!empty($player['shards'])): ?>
          <div class="hh-cv" style="color:var(--neon2)"><?= number_format((int)$player['shards']) ?><span style="color:var(--neon2)"> &#9670;</span></div>
          <div class="hh-cl">Shards</div>
        <?php endif; ?>
        <?php if (($player['loan']??0)>0): ?>
          <div class="hh-cv" style="color:var(--neon2)"><?= number_format((int)$player['loan']) ?><span> owed</span></div>
          <div class="hh-cl" style="color:var(--neon2)">Loan</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ STATS GRID ══════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">

  <!-- Vitals -->
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128293; Vitals</h3>
    <?php foreach ([
      ['Health', $player['integrity'],$player['integrity_max'],$hpPct, '#3bcf63'],
      ['Signal', $player['signal'],   $player['signal_max'],   $sigPct,'var(--neon2)'],
      ['Drive',  $player['cycles'],   $player['cycles_max'],   $drvPct,'#e8a33d'],
    ] as [$vl,$vv,$vm,$vp,$vc]): ?>
    <div style="margin-bottom:11px">
      <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
        <span style="color:var(--muted)"><?= $vl ?></span>
        <span style="color:<?= $vc ?>;font-weight:600"><?= number_format((int)$vv) ?><span style="color:var(--muted);font-weight:400"> / <?= number_format((int)$vm) ?></span></span>
      </div>
      <div style="height:5px;background:rgba(0,0,0,.4);border-radius:3px;overflow:hidden">
        <div style="width:<?= $vp ?>%;height:100%;background:<?= $vc ?>;border-radius:3px;transition:width .4s"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <div style="border-top:1px solid var(--line);margin-top:6px;padding-top:10px">
      <a href="index.php?p=city" style="display:block;text-align:center;font-size:11px;color:var(--muted);padding:5px;border:1px solid var(--line);border-radius:5px;text-decoration:none">&#127760; Enter the Sprawl</a>
    </div>
  </div>

  <!-- Combat Stats -->
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#9876; Combat Stats</h3>
    <?php
    $lvCap = min(30, max(5, (int)$player['level'] * 2));
    foreach ([
      ['Strength',  'str_pts','var(--neon2)','&#9889;','ATK'],
      ['Speed',     'spd_pts','#e8a33d',    '&#128168;','SPD'],
      ['Endurance', 'end_pts','#3bcf63',    '&#128158;','HP/DEF'],
    ] as [$sl,$sk,$sc,$si,$sef]):
      $sv = (int)($cStats[$sk] ?? 5);
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="font-size:18px;width:24px;text-align:center;flex:none"><?= $si ?></span>
      <div style="flex:1;min-width:0">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px">
          <span style="color:var(--muted)"><?= $sl ?></span>
          <span style="color:<?= $sc ?>;font-weight:700"><?= $sv ?><span style="color:var(--muted);font-weight:400"> / <?= $lvCap ?></span></span>
        </div>
        <div style="height:4px;background:rgba(0,0,0,.4);border-radius:2px;overflow:hidden">
          <div style="width:<?= min(100,round($sv/$lvCap*100)) ?>%;height:100%;background:<?= $sc ?>;border-radius:2px"></div>
        </div>
      </div>
      <span style="font-size:10px;color:var(--muted);flex:none;width:46px;text-align:right"><?= $sef ?></span>
    </div>
    <?php endforeach; ?>
    <?php if ((int)$cStats['unspent'] > 0): ?>
    <div style="background:rgba(232,212,77,.07);border:1px solid rgba(232,212,77,.25);border-radius:6px;padding:7px 10px;margin-top:2px;font-size:12px">
      &#11088; <b style="color:#e8d44d"><?= (int)$cStats['unspent'] ?></b> unspent point<?= $cStats['unspent']!=1?'s':'' ?> &mdash; <a href="index.php?p=training" style="color:#e8d44d">Spend &rarr;</a>
    </div>
    <?php endif; ?>
    <div style="border-top:1px solid var(--line);margin-top:8px;padding-top:8px">
      <a href="index.php?p=training" style="display:block;text-align:center;font-size:11px;color:var(--muted);padding:5px;border:1px solid var(--line);border-radius:5px;text-decoration:none">&#128168; Neural Training Center</a>
    </div>
  </div>

  <!-- Loadout -->
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128737; Loadout
      <?php if ($gearAtk||$gearDef): ?>
        <span style="float:right;font-size:11px;font-weight:400">
          <?php if ($gearAtk): ?><span style="color:var(--neon2)">+<?= $gearAtk ?> ATK</span><?php endif; ?>
          <?php if ($gearAtk&&$gearDef): ?> <?php endif; ?>
          <?php if ($gearDef): ?><span style="color:var(--accent)">+<?= $gearDef ?> DEF</span><?php endif; ?>
        </span>
      <?php endif; ?>
    </h3>
    <?php foreach ([
      ['weapon','&#9876;','Weapon',$ew,'rgba(255,45,149,.08)','rgba(255,45,149,.25)'],
      ['armor','&#128737;','Armor',$ea,'rgba(25,240,199,.06)','rgba(25,240,199,.2)'],
    ] as [$slot,$icon,$label,$item,$ibg,$ibord]): ?>
    <div style="background:<?= $item?$ibg:'var(--panel2)' ?>;border:1px solid <?= $item?$ibord:'var(--line)' ?>;border-radius:7px;padding:10px 12px;margin-bottom:8px;display:flex;align-items:center;gap:8px">
      <span style="font-size:20px;opacity:<?= $item?'1':'.2' ?>;flex:none"><?= $icon ?></span>
      <div style="flex:1;min-width:0">
        <?php if ($item): ?>
          <div style="font-weight:700;font-size:13px"><?= e($item['name']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= $slot==='weapon'?'+'.((int)$item['atk']).' ATK':'+'.((int)$item['def']).' DEF' ?></div>
        <?php else: ?>
          <div style="color:var(--muted);font-size:12px;font-style:italic">No <?= $label ?> equipped</div>
          <a href="index.php?p=weaponcraft" style="font-size:11px;color:var(--accent)">Craft at Fabrication Lab &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div style="display:flex;gap:6px;margin-top:4px">
      <a href="index.php?p=weaponcraft" style="flex:1;text-align:center;padding:6px;font-size:11px;border:1px solid var(--line);border-radius:5px;color:var(--muted);text-decoration:none;background:var(--panel2)">&#9874; Fabrication</a>
      <a href="index.php?p=pvp" style="flex:1;text-align:center;padding:6px;font-size:11px;border:1px solid var(--line);border-radius:5px;color:var(--muted);text-decoration:none;background:var(--panel2)">&#9876; Combat Arena</a>
    </div>
  </div>

</div>

<!-- ══ QUICK ACTIONS ══════════════════════════════════════════════ -->
<div class="panel" style="padding:14px">
  <h3 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#9889; Quick Nav</h3>
  <div class="hh-actions">
    <?php foreach ([
      ['index.php?p=sim',       '&#127918;','Combat Sim'],
      ['index.php?p=pvp',       '&#9876;',  'Arena'],
      ['index.php?p=weaponcraft','&#9874;', 'Fabrication'],
      ['index.php?p=synth',     '&#9879;',  'Synth Den'],
      ['index.php?p=bazaar',    '&#128722;','Bazaar'],
      ['index.php?p=stockex',   '&#128200;','Stock Ex'],
      ['index.php?p=boards',    '&#128172;','Boards'],
      ['index.php?p=guilds',    '&#128202;','Syndicates'],
    ] as [$href,$ico,$lbl]): ?>
    <a href="<?= $href ?>" class="hh-act">
      <span class="hha-icon"><?= $ico ?></span>
      <span class="hha-name"><?= $lbl ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ ACTIVITY + NOTIFICATIONS ══════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">

  <!-- Today's Combat -->
  <div class="panel" style="margin-bottom:0">
    <h3 style="margin-top:0;margin-bottom:10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128202; Today's Combat</h3>
    <?php if ($todayWins+$todayLoss > 0): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:8px;margin-bottom:12px">
      <?php foreach ([
        ['Fights', $todayWins+$todayLoss,'var(--text)'],
        ['Wins',   $todayWins,           'var(--accent)'],
        ['Losses', $todayLoss,           'var(--neon2)'],
        ['XP',     '+'.number_format($todayXp), '#e8d44d'],
        ['Creds',  '+'.number_format($todayCredits),'#3bcf63'],
      ] as [$lbl,$v,$c]): ?>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center">
        <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:<?= $c ?>"><?= $v ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <a href="index.php?p=sim" style="display:block;text-align:center;font-size:11px;color:var(--accent);padding:6px;border:1px solid rgba(25,240,199,.2);border-radius:5px;text-decoration:none">&#9889; Launch Combat Sim &rarr;</a>
    <?php else: ?>
    <div style="text-align:center;padding:18px 0;color:var(--muted)">
      <div style="font-size:30px;margin-bottom:6px;opacity:.25">&#9876;</div>
      <div style="font-size:13px">No combat logged today</div>
      <a href="index.php?p=sim" style="display:inline-block;margin-top:10px;font-size:11px;color:var(--accent);padding:6px 14px;border:1px solid rgba(25,240,199,.2);border-radius:5px;text-decoration:none">&#9889; Go Fight &rarr;</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Notifications -->
  <div class="panel" style="margin-bottom:0" id="home-news">
    <h3 style="margin-top:0;margin-bottom:10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128276; Notifications
      <?php if (!empty($newsFeed)): ?><span style="float:right;background:var(--accent);color:#0b0c1a;border-radius:10px;font-size:10px;padding:1px 7px;font-weight:700"><?= count($newsFeed) ?></span><?php endif; ?>
    </h3>
    <?php if (!empty($newsFeed)):
      foreach (array_slice($newsFeed,0,8) as $item):
        $nicon = ['message'=>'&#9993;','transfer'=>'&#128178;','news'=>'&#128203;','levelup'=>'&#11088;'][$item['type']] ?? '&#8226;';
        $ncol  = ['message'=>'var(--accent)','transfer'=>'#3bcf63','news'=>'#e8d44d','levelup'=>'#e8d44d'][$item['type']] ?? 'var(--muted)';
        $rawHtml = $item['type']==='levelup';
    ?>
    <div style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)<?= $rawHtml?';background:rgba(232,212,77,.04);margin:-1px -4px;padding:7px 4px;border-radius:4px':'' ?>">
      <span style="font-size:13px;flex:none;color:<?= $ncol ?>;margin-top:1px"><?= $nicon ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:12px"><?= $rawHtml ? $item['text'] : e($item['text']) ?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:1px"><?= !empty($item['ts'])?e(date('M j, g:ia',strtotime($item['ts']))):'' ?></div>
      </div>
    </div>
    <?php endforeach; else: ?>
    <div style="text-align:center;padding:18px 0;color:var(--muted)">
      <div style="font-size:30px;margin-bottom:6px;opacity:.25">&#128276;</div>
      <div style="font-size:13px">No notifications</div>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ══ ONLINE NOW ══════════════════════════════════════════════ -->
<div class="panel">
  <h3 style="margin-top:0;margin-bottom:10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128992; Online Now
    <span class="muted" style="font-size:12px;font-weight:400">&nbsp;<?= $online ?> ghost<?= $online!==1?'s':'' ?></span>
  </h3>
  <?php if ($online > 0): ?>
  <div style="display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($recentOnline as $r): $rc=chat_color($r['role'],$r['chat_color']); ?>
    <a href="index.php?p=profile&id=<?= (int)$r['id'] ?>" class="online-pill" style="color:<?= e($rc) ?>">
      <span class="online-dot"></span><?= e($r['username']) ?><span class="muted" style="font-size:10px"> Lv<?= (int)$r['level'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <p class="muted" style="font-size:12px;margin:0">Nobody else online right now. The city is quiet.</p>
  <?php endif; ?>
</div>
