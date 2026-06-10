<?php /* pages/pvp.php — Combat Arena: Player vs Player */
/*
  Schema:
  CREATE TABLE IF NOT EXISTS player_stats (
    pid INT PRIMARY KEY, str_pts INT DEFAULT 5, spd_pts INT DEFAULT 5,
    end_pts INT DEFAULT 5, unspent INT DEFAULT 0
  );
  CREATE TABLE IF NOT EXISTS pvp_log (
    id INT AUTO_INCREMENT PRIMARY KEY, attacker_id INT NOT NULL, defender_id INT NOT NULL,
    winner_id INT NOT NULL, rounds INT NOT NULL, atk_xp INT NOT NULL DEFAULT 0,
    def_xp INT NOT NULL DEFAULT 0, fought_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_atk (attacker_id), INDEX idx_def (defender_id)
  );
*/
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

if (!function_exists('grant_xp')) {
  function grant_xp($pid, $amount) {
    $pdo = db();
    try { $bq=$pdo->prepare('SELECT v FROM settings WHERE k=?'); $bq->execute(["apt_xp_bonus:{$pid}"]); $b=(int)$bq->fetchColumn(); if($b>0) $amount=(int)ceil($amount*(1+$b/100)); } catch(Throwable $e){}
    $r = $pdo->prepare('SELECT level, xp, xp_next FROM players WHERE id = ?');
    $r->execute([$pid]); $p = $r->fetch();
    $level = (int)$p['level']; $xp = (int)$p['xp'] + $amount; $next = (int)$p['xp_next'];
    $gained = 0;
    while ($xp >= $next && $level < 999) { $xp -= $next; $level++; $next = (int)round($next * 1.5); $gained++; }
    $pdo->prepare('UPDATE players SET level = ?, xp = ?, xp_next = ? WHERE id = ?')->execute([$level, $xp, $next, $pid]);
    if ($gained > 0) {
      try {
        $aq = $pdo->prepare('SELECT COALESCE(v,0) FROM settings WHERE k=?'); $aq->execute(["attr_points:{$pid}"]);
        $cur = (int)($aq->fetchColumn() ?: 0);
        $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute(["attr_points:{$pid}", $cur + $gained * 5]);
      } catch(Throwable $e) {}
    }
  }
}

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_stats (
    pid INT PRIMARY KEY, str_pts INT NOT NULL DEFAULT 5,
    spd_pts INT NOT NULL DEFAULT 5, end_pts INT NOT NULL DEFAULT 5,
    unspent INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_log (
    id INT AUTO_INCREMENT PRIMARY KEY, attacker_id INT NOT NULL, defender_id INT NOT NULL,
    winner_id INT NOT NULL, rounds INT NOT NULL DEFAULT 1,
    atk_xp INT NOT NULL DEFAULT 0, def_xp INT NOT NULL DEFAULT 0,
    fought_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_atk (attacker_id), INDEX idx_def (defender_id)
  ) ENGINE=InnoDB");
} catch (Throwable $e) {}

// Load / auto-init my stats
try {
  $qs = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $qs->execute([$pid]); $myStats = $qs->fetch();
  if (!$myStats) {
    $pdo->prepare('INSERT IGNORE INTO player_stats (pid) VALUES (?)')->execute([$pid]);
    $qs->execute([$pid]); $myStats = $qs->fetch();
  }
} catch (Throwable $e) { $myStats = ['pid'=>$pid,'str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0]; }

// Level-up: each level grants 1 stat point (check against current level vs spent+unspent)
try {
  $totalPoints  = 5 + 5 + 5 + (int)$myStats['unspent'];
  $spentPoints  = (int)$myStats['str_pts'] + (int)$myStats['spd_pts'] + (int)$myStats['end_pts'];
  $earnedPoints = 15 + max(0, (int)$player['level'] - 1); // base 15 + 1 per level above 1
  $newUnspent   = max(0, $earnedPoints - $spentPoints);
  if ($newUnspent !== (int)$myStats['unspent']) {
    $pdo->prepare('UPDATE player_stats SET unspent=? WHERE pid=?')->execute([$newUnspent, $pid]);
    $myStats['unspent'] = $newUnspent;
  }
} catch (Throwable $e) {}

// ── Combat calculations ───────────────────────────────────────────────────
function pvp_calc_stats($p, $stats, $pdo) {
  $pid2 = (int)$p['id'];
  $str  = (int)($stats['str_pts'] ?? 5);
  $spd  = (int)($stats['spd_pts'] ?? 5);
  $end  = (int)($stats['end_pts'] ?? 5);

  // Gear bonuses (from Fabrication Lab crafted equipment)
  $weaponAtk = 0; $armorDef = 0;
  try {
    $gq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $gq->execute(["equipped_weapon:{$pid2}"]); $wid = (int)$gq->fetchColumn();
    if ($wid > 0) { $gq2 = $pdo->prepare('SELECT atk_bonus FROM player_gear WHERE id=? AND player_id=?'); $gq2->execute([$wid,$pid2]); $v=$gq2->fetchColumn(); $weaponAtk=$v!==false?(int)$v:0; }
    $gq->execute(["equipped_armor:{$pid2}"]); $aid = (int)$gq->fetchColumn();
    if ($aid > 0) { $gq2 = $pdo->prepare('SELECT def_bonus FROM player_gear WHERE id=? AND player_id=?'); $gq2->execute([$aid,$pid2]); $v=$gq2->fetchColumn(); $armorDef=$v!==false?(int)$v:0; }
  } catch (Throwable $e) {}

  // Active buffs
  $buffAtk = 0; $buffDef = 0;
  try {
    $bq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $bq->execute(["buff:atk:{$pid2}"]); $bv = $bq->fetchColumn();
    if ($bv !== false && $bv !== '') { [$bon,$exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffAtk = (int)$bon; }
    $bq->execute(["buff:def:{$pid2}"]); $bv = $bq->fetchColumn();
    if ($bv !== false && $bv !== '') { [$bon,$exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffDef = (int)$bon; }
  } catch (Throwable $e) {}

  return [
    'atk'   => max(1, $str * 3 + $weaponAtk + $buffAtk + (int)$p['atk']),
    'def'   => max(1, $end * 2 + $armorDef  + $buffDef  + (int)$p['def']),
    'spd'   => $spd,
    'hp'    => max(1, (int)$p['integrity_max'] + $end * 5),
    'str'   => $str, 'end'=>$end,
    'weapon_atk'=>$weaponAtk, 'armor_def'=>$armorDef,
    'buff_atk'=>$buffAtk, 'buff_def'=>$buffDef,
  ];
}

function pvp_simulate($atk_p, $atk_s, $def_p, $def_s) {
  $atkHp = $atk_s['hp']; $defHp = $def_s['hp'];
  $atkAtk = $atk_s['atk']; $atkDef = $atk_s['def']; $atkSpd = $atk_s['spd'];
  $defAtk = $def_s['atk']; $defDef = $def_s['def']; $defSpd = $def_s['spd'];
  $rounds = []; $roundNum = 0;
  while ($atkHp > 0 && $defHp > 0 && $roundNum < 20) {
    $roundNum++;
    $round = ['num'=>$roundNum,'events'=>[]];
    // Determine who strikes first this round based on speed
    $atkFirst = ($atkSpd + mt_rand(0,4)) >= ($defSpd + mt_rand(0,4));

    $doPunch = function($offAtk, $offName, $defDef, &$defHp, $defName) use (&$round) {
      $variance = mt_rand(80, 120) / 100;
      $dmg = max(1, (int)round(($offAtk - $defDef * 0.4) * $variance));
      $defHp = max(0, $defHp - $dmg);
      $dodge = mt_rand(1,100) <= 8; // 8% dodge chance
      if ($dodge) {
        $round['events'][] = ['type'=>'dodge','text'=>"{$defName} dodged the attack!",'color'=>'#e8d44d'];
      } else {
        $round['events'][] = ['type'=>'hit','text'=>"{$offName} struck for {$dmg} damage.",'color'=>$dmg>15?'var(--neon2)':'var(--text)','dmg'=>$dmg];
      }
      if (!$dodge) return $dmg;
      return 0;
    };

    $atkName = $atk_p['username']; $defName = $def_p['username'];
    if ($atkFirst) {
      $doPunch($atkAtk, $atkName, $defDef, $defHp, $defName);
      if ($defHp > 0) $doPunch($defAtk, $defName, $atkDef, $atkHp, $atkName);
    } else {
      $doPunch($defAtk, $defName, $atkDef, $atkHp, $atkName);
      if ($atkHp > 0) $doPunch($atkAtk, $atkName, $defDef, $defHp, $defName);
    }
    $round['atk_hp'] = $atkHp; $round['def_hp'] = $defHp;
    $rounds[] = $round;
    if ($atkHp <= 0 || $defHp <= 0) break;
  }
  $winner = $atkHp > $defHp ? 'atk' : 'def';
  return ['rounds'=>$rounds, 'winner'=>$winner, 'atk_final_hp'=>$atkHp, 'def_final_hp'=>$defHp, 'total_rounds'=>$roundNum];
}

// ── Handle POST ────────────────────────────────────────────────────────────
$battleResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'spend_stat') {
      $stat = $_POST['stat'] ?? '';
      if (!in_array($stat, ['str_pts','spd_pts','end_pts'], true)) throw new RuntimeException('Invalid stat.');
      if ((int)$myStats['unspent'] < 1) throw new RuntimeException('No unspent stat points.');
      $pdo->prepare("UPDATE player_stats SET {$stat} = {$stat} + 1, unspent = unspent - 1 WHERE pid=?")->execute([$pid]);
      $qs->execute([$pid]); $myStats = $qs->fetch();
      $msg = 'Stat point spent!';

    } elseif ($act === 'challenge') {
      $target = trim($_POST['target'] ?? '');
      if ($target === '') throw new RuntimeException('Enter a ghost\'s handle.');
      $q = $pdo->prepare('SELECT * FROM players WHERE username=?'); $q->execute([$target]); $defPlayer = $q->fetch();
      if (!$defPlayer) throw new RuntimeException('"' . htmlspecialchars($target, ENT_QUOTES) . '" is not a ghost in the Sprawl.');
      if ((int)$defPlayer['id'] === $pid) throw new RuntimeException("You can't fight yourself.");
      if ((int)$player['integrity'] < 10) throw new RuntimeException('Your Health is too low to fight. Rest first.');

      // Load defender stats
      $qd = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $qd->execute([(int)$defPlayer['id']]); $defStats = $qd->fetch();
      if (!$defStats) {
        $pdo->prepare('INSERT IGNORE INTO player_stats (pid) VALUES (?)')->execute([(int)$defPlayer['id']]);
        $qd->execute([(int)$defPlayer['id']]); $defStats = $qd->fetch();
      }
      if (!$defStats) $defStats = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];

      $atkStats = pvp_calc_stats($player, $myStats, $pdo);
      $defStats2 = pvp_calc_stats($defPlayer, $defStats, $pdo);
      $result    = pvp_simulate($player, $atkStats, $defPlayer, $defStats2);
      $won       = $result['winner'] === 'atk';

      // XP rewards
      $atkXp = $won ? mt_rand(8, 20) + (int)$defPlayer['level'] * 2 : mt_rand(2, 6);
      $defXp = !$won ? mt_rand(5, 15) + (int)$player['level'] * 2   : mt_rand(1, 4);

      $pdo->prepare('INSERT INTO pvp_log (attacker_id, defender_id, winner_id, rounds, atk_xp, def_xp) VALUES (?,?,?,?,?,?)')->execute([$pid, (int)$defPlayer['id'], $won ? $pid : (int)$defPlayer['id'], $result['total_rounds'], $atkXp, $defXp]);
      grant_xp($pid, $atkXp);
      // Apply integrity damage (attacker loses 5–15% of max)
      $intLoss = max(1, (int)round((int)$player['integrity_max'] * mt_rand(5,15) / 100));
      $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id=?')->execute([$intLoss, $pid]);
      $player = current_player();

      $battleResult = array_merge($result, [
        'won'=>$won, 'atk_xp'=>$atkXp, 'def_xp'=>$defXp,
        'atk_p'=>$player, 'def_p'=>$defPlayer,
        'atk_s'=>$atkStats, 'def_s'=>$defStats2,
        'int_lost'=>$intLoss,
      ]);
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

$tab = in_array($_GET['tab'] ?? '', ['arena','stats','log']) ? $_GET['tab'] : 'arena';

// Recent PvP log
$recentPvp = [];
try {
  $q = $pdo->prepare("SELECT l.*, a.username AS atk_name, d.username AS def_name, w.username AS winner_name
    FROM pvp_log l JOIN players a ON a.id=l.attacker_id JOIN players d ON d.id=l.defender_id JOIN players w ON w.id=l.winner_id
    WHERE l.attacker_id=? OR l.defender_id=? ORDER BY l.fought_at DESC LIMIT 20");
  $q->execute([$pid, $pid]); $recentPvp = $q->fetchAll();
} catch (Throwable $e) {}
?>

<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--neon2),#e8a33d,transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#9876; Combat Arena</h2>
    <p class="muted" style="margin:0;font-size:12px">Ghost vs Ghost. Stats, gear, and buffs determine the outcome. Your stats are hidden — others cannot see them.</p>
  </div>
</div>

<?php if ($msg): ?>
<div style="background:rgba(255,45,149,.08);border:1px solid rgba(255,45,149,.3);border-radius:6px;padding:10px 14px;font-size:13px;color:var(--neon2)"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:flex;gap:6px;flex-wrap:wrap">
  <?php foreach (['arena'=>'&#9876; Arena','stats'=>'&#128202; My Stats','log'=>'&#128203; Combat Log ('.count($recentPvp).')'] as $tid=>$tl): ?>
  <a href="index.php?p=pvp&tab=<?= $tid ?>" style="padding:7px 14px;border-radius:6px;font-size:12px;text-decoration:none;border:1px solid <?= $tab===$tid ? 'var(--neon2)' : 'var(--line)' ?>;background:<?= $tab===$tid ? 'rgba(255,45,149,.1)' : 'var(--panel2)' ?>;color:<?= $tab===$tid ? 'var(--neon2)' : 'var(--muted)' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<!-- ── BATTLE RESULT ── -->
<?php if ($battleResult): $r = $battleResult; ?>
<div class="panel" style="border:2px solid <?= $r['won'] ? 'rgba(25,240,199,.5)' : 'rgba(255,45,149,.5)' ?>;background:<?= $r['won'] ? 'rgba(25,240,199,.04)' : 'rgba(255,45,149,.04)' ?>">
  <div style="text-align:center;margin-bottom:16px">
    <div style="font-size:36px;margin-bottom:6px"><?= $r['won'] ? '&#9989;' : '&#10060;' ?></div>
    <div style="font-family:'Orbitron',sans-serif;font-size:20px;font-weight:900;color:<?= $r['won'] ? 'var(--accent)' : 'var(--neon2)' ?>"><?= $r['won'] ? 'VICTORY' : 'DEFEAT' ?></div>
    <div style="font-size:13px;color:var(--muted);margin-top:4px">vs. <?= e($r['def_p']['username']) ?> &mdash; <?= (int)$r['total_rounds'] ?> round<?= $r['total_rounds']!==1?'s':'' ?></div>
  </div>

  <!-- Combatant comparison -->
  <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:center;margin-bottom:16px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px">
    <div style="text-align:center">
      <div style="font-weight:700;font-size:14px;color:var(--accent)"><?= e($player['username']) ?></div>
      <div style="font-size:11px;color:var(--muted)">ATK: <?= $r['atk_s']['atk'] ?> &middot; DEF: <?= $r['atk_s']['def'] ?></div>
      <div style="font-size:11px;color:var(--muted)">HP: <?= $r['atk_final_hp'] ?>/<?= $r['atk_s']['hp'] ?></div>
    </div>
    <div style="font-size:24px;text-align:center">&#9876;</div>
    <div style="text-align:center">
      <div style="font-weight:700;font-size:14px;color:var(--neon2)"><?= e($r['def_p']['username']) ?></div>
      <div style="font-size:11px;color:var(--muted)">ATK: <?= $r['def_s']['atk'] ?> &middot; DEF: <?= $r['def_s']['def'] ?></div>
      <div style="font-size:11px;color:var(--muted)">HP: <?= $r['def_final_hp'] ?>/<?= $r['def_s']['hp'] ?></div>
    </div>
  </div>

  <!-- Round-by-round log -->
  <div style="max-height:320px;overflow-y:auto;border:1px solid var(--line);border-radius:7px;padding:10px">
    <?php foreach ($r['rounds'] as $rnd): ?>
    <div style="margin-bottom:10px">
      <div style="font-size:10px;font-family:'Orbitron',sans-serif;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Round <?= $rnd['num'] ?></div>
      <?php foreach ($rnd['events'] as $ev): ?>
      <div style="font-size:12px;color:<?= $ev['color'] ?>;margin-bottom:2px;padding-left:10px">&#8250; <?= e($ev['text']) ?></div>
      <?php endforeach; ?>
      <div style="font-size:10px;color:var(--muted);margin-top:3px;padding-left:10px">
        <?= e($player['username']) ?>: <?= max(0,(int)$rnd['atk_hp']) ?> HP &nbsp;&nbsp;
        <?= e($r['def_p']['username']) ?>: <?= max(0,(int)$rnd['def_hp']) ?> HP
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Rewards -->
  <div style="display:flex;justify-content:center;gap:20px;margin-top:14px;flex-wrap:wrap">
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:#e8a33d">+<?= $r['atk_xp'] ?> XP</div><div style="font-size:10px;color:var(--muted)">XP Earned</div></div>
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--neon2)">-<?= $r['int_lost'] ?> INT</div><div style="font-size:10px;color:var(--muted)">Health Lost</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── ARENA ── -->
<?php if ($tab === 'arena'): ?>
<div class="panel">
  <h3 style="margin-top:0">Challenge a Ghost</h3>
  <p class="muted" style="font-size:13px;margin-bottom:12px">Combat draws from your STR, SPD, END, gear, and active stim buffs. Your stats remain hidden from others unless they use a <b>Spy Protocol</b> item. Each fight costs Health — rest to recover.</p>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:10px 14px;font-size:12px;margin-bottom:14px">
    Current Health: <b style="color:<?= (int)$player['integrity'] < 20 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= number_format($player['integrity']) ?> / <?= number_format($player['integrity_max']) ?></b>
    <?php if ((int)$player['integrity'] < 10): ?>
    <span style="color:var(--neon2);margin-left:8px">&#9888; Too low to fight — use a stim or wait for daily reset</span>
    <?php endif; ?>
  </div>
  <form method="post" style="display:flex;gap:8px;align-items:center;max-width:400px">
    <input type="hidden" name="action" value="challenge">
    <div class="ac-wrap" style="flex:1;position:relative">
      <input type="text" id="pvpTarget" name="target" placeholder="Ghost's handle"
             autocomplete="off" maxlength="32"
             style="width:100%" <?= (int)$player['integrity'] < 10 ? 'disabled' : '' ?>>
      <div class="ac-list" id="pvpAcList" style="display:none"></div>
    </div>
    <button type="submit" style="padding:10px 20px;flex:none" <?= (int)$player['integrity'] < 10 ? 'disabled' : '' ?>>&#9876; Fight</button>
  </form>
  <script>
  (function(){
    var inp=document.getElementById('pvpTarget'), list=document.getElementById('pvpAcList');
    if(!inp||!list) return;
    var cur=-1, items=[];
    function show(names){
      items=names; cur=-1;
      if(!names.length){ list.style.display='none'; return; }
      list.innerHTML=''; names.forEach(function(n,i){
        var d=document.createElement('div'); d.className='ac-item'; d.textContent=n;
        d.addEventListener('mousedown',function(e){ e.preventDefault(); inp.value=n; list.style.display='none'; });
        list.appendChild(d);
      }); list.style.display='block';
    }
    inp.addEventListener('input',function(){
      var q=inp.value.trim(); if(q.length<1){ list.style.display='none'; return; }
      fetch('players_search.php?q='+encodeURIComponent(q),{credentials:'same-origin'})
        .then(function(r){return r.json();}).then(show).catch(function(){});
    });
    inp.addEventListener('keydown',function(e){
      if(!items.length) return;
      var rows=list.querySelectorAll('.ac-item');
      if(e.key==='ArrowDown'){ e.preventDefault(); cur=Math.min(cur+1,rows.length-1); rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);}); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); cur=Math.max(cur-1,-1); rows.forEach(function(r,i){r.classList.toggle('focused',i===cur);}); }
      else if(e.key==='Enter'&&cur>=0){ e.preventDefault(); inp.value=items[cur]; list.style.display='none'; }
      else if(e.key==='Escape'){ list.style.display='none'; }
    });
    document.addEventListener('click',function(e){ if(!inp.contains(e.target)&&!list.contains(e.target)) list.style.display='none'; });
  })();
  </script>
</div>

<!-- Recent arena activity -->
<?php
try {
  $arenaLatest = $pdo->query("SELECT l.*, a.username AS atk_name, d.username AS def_name, w.username AS winner_name FROM pvp_log l JOIN players a ON a.id=l.attacker_id JOIN players d ON d.id=l.defender_id JOIN players w ON w.id=l.winner_id ORDER BY l.fought_at DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) { $arenaLatest = []; }
if (!empty($arenaLatest)): ?>
<div class="panel">
  <h3 style="margin-top:0">Recent Battles</h3>
  <div style="overflow-x:auto">
    <table>
      <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.5px"><th>Attacker</th><th>Defender</th><th>Winner</th><th>Rounds</th><th>When</th></tr>
      <?php foreach ($arenaLatest as $l):
        $iAtk = (int)$l['attacker_id'] === $pid; $iDef = (int)$l['defender_id'] === $pid;
        $iWon = (int)$l['winner_id'] === $pid;
      ?>
      <tr style="<?= ($iAtk||$iDef)?'background:rgba(25,240,199,.03)':'' ?>">
        <td><a href="index.php?p=profile&id=<?= (int)$l['attacker_id'] ?>"><?= e($l['atk_name']) ?></a></td>
        <td><a href="index.php?p=profile&id=<?= (int)$l['defender_id'] ?>"><?= e($l['def_name']) ?></a></td>
        <td style="font-weight:700;color:<?= (int)$l['winner_id']===(int)$l['attacker_id'] ? 'var(--accent)' : 'var(--neon2)' ?>"><?= e($l['winner_name']) ?></td>
        <td><?= (int)$l['rounds'] ?></td>
        <td class="muted"><?= e(date('M j g:ia', strtotime($l['fought_at']))) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── MY STATS ── -->
<?php elseif ($tab === 'stats'): ?>
<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h3 style="margin:0">&#128274; Your Combat Stats <span class="muted" style="font-size:12px;font-weight:400">(private)</span></h3>
    <?php if ((int)$myStats['unspent'] > 0): ?><span style="background:rgba(232,163,61,.12);border:1px solid rgba(232,163,61,.4);color:#e8a33d;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700"><?= (int)$myStats['unspent'] ?> unspent point<?= $myStats['unspent']!==1?'s':'' ?></span><?php endif; ?>
  </div>
  <?php
  $statDefs = [
    'str_pts' => ['Strength',  'Increases ATK power. Each point = +3 ATK.',  'var(--neon2)', (int)$myStats['str_pts']],
    'spd_pts' => ['Speed',     'Determines strike order and dodge chance.',   '#e8a33d',      (int)$myStats['spd_pts']],
    'end_pts' => ['Endurance', 'Increases max HP and DEF. +5 HP, +2 DEF.',   'var(--accent)', (int)$myStats['end_pts']],
  ];
  foreach ($statDefs as $key => [$label,$desc,$color,$val]):
  ?>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:160px">
      <div style="font-weight:700;font-size:14px;color:<?= $color ?>"><?= $label ?> <span style="font-family:'Orbitron',sans-serif;font-size:16px"><?= $val ?></span></div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px"><?= $desc ?></div>
    </div>
    <div style="height:6px;flex:1;min-width:80px;background:rgba(0,0,0,.3);border-radius:3px;overflow:hidden">
      <div style="width:<?= min(100, (int)round($val/25*100)) ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
    </div>
    <?php if ((int)$myStats['unspent'] > 0): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="spend_stat">
      <input type="hidden" name="stat" value="<?= $key ?>">
      <button type="submit" style="font-size:12px;padding:5px 14px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.25);color:var(--accent)">+ Spend</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- Derived stats panel -->
  <?php $calcStats = pvp_calc_stats($player, $myStats, $pdo); ?>
  <div style="background:rgba(0,0,0,.2);border:1px solid var(--line);border-radius:8px;padding:12px;margin-top:12px">
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Derived Combat Stats</div>
    <div style="display:flex;flex-wrap:wrap;gap:12px">
      <?php foreach ([['ATK',$calcStats['atk'],'var(--neon2)'],['DEF',$calcStats['def'],'var(--accent)'],['HP',$calcStats['hp'],'#3bcf63'],['SPD',$calcStats['spd'],'#e8a33d']] as [$l,$v,$c]): ?>
      <div style="text-align:center;min-width:60px">
        <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:<?= $c ?>"><?= $v ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase"><?= $l ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:8px">Weapon: +<?= $calcStats['weapon_atk'] ?> ATK &nbsp;&middot;&nbsp; Armor: +<?= $calcStats['armor_def'] ?> DEF &nbsp;&middot;&nbsp; Buffs: +<?= $calcStats['buff_atk'] ?> ATK / +<?= $calcStats['buff_def'] ?> DEF</div>
  </div>
</div>

<!-- ── LOG ── -->
<?php elseif ($tab === 'log'): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($recentPvp)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No battles yet.</div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:120px 120px 120px 60px 100px 80px;padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line);font-weight:700">
    <span>Attacker</span><span>Defender</span><span>Winner</span><span>Rounds</span><span>XP</span><span>Date</span>
  </div>
  <?php foreach ($recentPvp as $l):
    $iAtk = (int)$l['attacker_id'] === $pid; $iWon = (int)$l['winner_id'] === $pid;
    $myXp = $iAtk ? $l['atk_xp'] : $l['def_xp'];
  ?>
  <div style="display:grid;grid-template-columns:120px 120px 120px 60px 100px 80px;padding:9px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center;background:<?= $iWon?'rgba(25,240,199,.03)':($iAtk||true?'transparent':'') ?>">
    <span><a href="index.php?p=profile&id=<?= (int)$l['attacker_id'] ?>"><?= e($l['atk_name']) ?></a><?= $iAtk?' (you)':'' ?></span>
    <span><a href="index.php?p=profile&id=<?= (int)$l['defender_id'] ?>"><?= e($l['def_name']) ?></a><?= !$iAtk?' (you)':'' ?></span>
    <span style="font-weight:700;color:<?= $iWon?'var(--accent)':'var(--neon2)' ?>"><?= e($l['winner_name']) ?><?= $iWon?' &#10003;':'' ?></span>
    <span><?= (int)$l['rounds'] ?></span>
    <span style="color:#e8a33d">+<?= (int)$myXp ?> XP</span>
    <span class="muted"><?= e(date('M j', strtotime($l['fought_at']))) ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
