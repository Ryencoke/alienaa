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
        $pdo->prepare('INSERT INTO player_stats (pid, unspent) VALUES (?,?) ON DUPLICATE KEY UPDATE unspent = unspent + ?')
            ->execute([$pid, $gained * 5, $gained * 5]);
      } catch(Throwable $e) {}
    }
  }
}

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_stats (
    pid INT PRIMARY KEY, str_pts INT NOT NULL DEFAULT 5,
    spd_pts INT NOT NULL DEFAULT 5, end_pts INT NOT NULL DEFAULT 5,
    unspent INT NOT NULL DEFAULT 0, training_gains INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB");
  try { $pdo->exec("ALTER TABLE player_stats ADD COLUMN training_gains INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_log (
    id INT AUTO_INCREMENT PRIMARY KEY, attacker_id INT NOT NULL, defender_id INT NOT NULL,
    winner_id INT NOT NULL, rounds INT NOT NULL DEFAULT 1,
    atk_xp INT NOT NULL DEFAULT 0, def_xp INT NOT NULL DEFAULT 0,
    credits_looted INT NOT NULL DEFAULT 0,
    log_data TEXT NULL,
    fought_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_atk (attacker_id), INDEX idx_def (defender_id)
  ) ENGINE=InnoDB");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE pvp_log ADD COLUMN log_data TEXT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE pvp_log ADD COLUMN credits_looted INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE players ADD COLUMN mortality INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Load / auto-init my stats
try {
  $qs = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $qs->execute([$pid]); $myStats = $qs->fetch();
  if (!$myStats) {
    $pdo->prepare('INSERT IGNORE INTO player_stats (pid) VALUES (?)')->execute([$pid]);
    $qs->execute([$pid]); $myStats = $qs->fetch();
  }
} catch (Throwable $e) { $myStats = ['pid'=>$pid,'str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0]; }

// One-time migration: copy legacy settings attr_points to player_stats.unspent if needed
if ((int)$myStats['unspent'] === 0) {
  try {
    $aq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $aq->execute(["attr_points:{$pid}"]);
    $legacyPts = (int)($aq->fetchColumn() ?: 0);
    if ($legacyPts > 0) {
      $pdo->prepare('UPDATE player_stats SET unspent=? WHERE pid=?')->execute([$legacyPts, $pid]);
      $pdo->prepare('UPDATE settings SET v=0 WHERE k=?')->execute(["attr_points:{$pid}"]);
      $myStats['unspent'] = $legacyPts;
    }
  } catch(Throwable $e) {}
}

// ── Combat calculations ───────────────────────────────────────────────────
function pvp_calc_stats($p, $stats, $pdo) {
  $pid2 = (int)$p['id'];
  $str  = (int)($stats['str_pts'] ?? 5);
  $spd  = (int)($stats['spd_pts'] ?? 5);
  $end  = (int)($stats['end_pts'] ?? 5);

  // Gear bonuses — also retrieve names for battle log display
  $weaponAtk = 0; $armorDef = 0; $weaponName = null; $armorName = null;
  try {
    $gq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $gq->execute(["equipped_weapon:{$pid2}"]); $wid = (int)$gq->fetchColumn();
    if ($wid > 0) { $gq2 = $pdo->prepare('SELECT name, atk_bonus FROM player_gear WHERE id=? AND player_id=?'); $gq2->execute([$wid,$pid2]); $wr=$gq2->fetch(); if($wr){$weaponAtk=(int)$wr['atk_bonus'];$weaponName=$wr['name'];} }
    $gq->execute(["equipped_armor:{$pid2}"]); $aid = (int)$gq->fetchColumn();
    if ($aid > 0) { $gq2 = $pdo->prepare('SELECT name, def_bonus FROM player_gear WHERE id=? AND player_id=?'); $gq2->execute([$aid,$pid2]); $ar=$gq2->fetch(); if($ar){$armorDef=(int)$ar['def_bonus'];$armorName=$ar['name'];} }
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
    'atk'        => max(1, $str * 3 + $weaponAtk + $buffAtk + (int)($p['atk'] ?? 0)),
    'def'        => max(1, $end * 2 + $armorDef  + $buffDef  + (int)($p['def'] ?? 0)),
    'spd'        => $spd,
    'hp'         => max(10, (int)$p['integrity'] + $end * 5),
    'str'        => $str, 'end' => $end,
    'weapon_atk' => $weaponAtk, 'armor_def'   => $armorDef,
    'buff_atk'   => $buffAtk,   'buff_def'    => $buffDef,
    'weapon_name'=> $weaponName, 'armor_name' => $armorName,
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

$_pvpMerchantUntil = $player['merchant_until'] ?? null;
$_pvpIsMerchant = !empty($_pvpMerchantUntil) && $_pvpMerchantUntil >= date('Y-m-d');

// ── Handle POST ────────────────────────────────────────────────────────────
$battleResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'spend_stat') {
      $stat = $_POST['stat'] ?? '';
      if ($stat === 'signal_max') {
        if ((int)$myStats['unspent'] < 1) throw new RuntimeException('No unspent stat points.');
        if ((int)$player['signal_max'] >= 50) throw new RuntimeException('Signal is already at maximum capacity (50).');
        $pdo->prepare('UPDATE players SET signal_max = LEAST(50, signal_max + 1) WHERE id=?')->execute([$pid]);
        $pdo->prepare('UPDATE player_stats SET unspent = unspent - 1 WHERE pid=?')->execute([$pid]);
        $qs->execute([$pid]); $myStats = $qs->fetch(); $player = current_player();
        $msg = 'Signal bandwidth upgraded!';
      } else {
        if (!in_array($stat, ['str_pts','spd_pts','end_pts'], true)) throw new RuntimeException('Invalid stat.');
        if ((int)$myStats['unspent'] < 1) throw new RuntimeException('No unspent stat points.');
        $pdo->prepare("UPDATE player_stats SET {$stat} = {$stat} + 1, unspent = unspent - 1 WHERE pid=?")->execute([$pid]);
        $qs->execute([$pid]); $myStats = $qs->fetch();
        $msg = 'Stat point spent!';
      }

    } elseif ($act === 'challenge') {
      if ($_pvpIsMerchant) throw new RuntimeException('Commerce Accord active — combat is locked until ' . $_pvpMerchantUntil . '.');
      $target = trim($_POST['target'] ?? '');
      if ($target === '') throw new RuntimeException('Enter a ghost\'s handle.');
      $q = $pdo->prepare('SELECT * FROM players WHERE username=?'); $q->execute([$target]); $defPlayer = $q->fetch();
      if (!$defPlayer) throw new RuntimeException('"' . htmlspecialchars($target, ENT_QUOTES) . '" is not a ghost in the Sprawl.');
      if ((int)$defPlayer['id'] === $pid) throw new RuntimeException("You can't fight yourself.");
      if ((int)$player['integrity'] < 10) throw new RuntimeException('Your Health is too low to fight. Rest first.');
      if ((int)$player['signal'] < 1) throw new RuntimeException('Signal depleted — daily combat limit reached. Signal resets at midnight.');

      // Load defender stats
      $qd = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $qd->execute([(int)$defPlayer['id']]); $defStats = $qd->fetch();
      if (!$defStats) {
        $pdo->prepare('INSERT IGNORE INTO player_stats (pid) VALUES (?)')->execute([(int)$defPlayer['id']]);
        $qd->execute([(int)$defPlayer['id']]); $defStats = $qd->fetch();
      }
      if (!$defStats) $defStats = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];

      $atkStats  = pvp_calc_stats($player, $myStats, $pdo);
      $defStats2 = pvp_calc_stats($defPlayer, $defStats, $pdo);
      $result    = pvp_simulate($player, $atkStats, $defPlayer, $defStats2);
      $won       = $result['winner'] === 'atk';

      // XP rewards
      $atkXp = $won ? mt_rand(8, 20) + (int)$defPlayer['level'] * 2 : mt_rand(2, 6);
      $defXp = !$won ? mt_rand(5, 15) + (int)$player['level'] * 2   : mt_rand(1, 4);

      // Credit transfer — loser drops 10% of pocket credits to winner
      $creditsLooted = 0;
      if ($won) {
        $creditsLooted = max(0, (int)floor((int)$defPlayer['creds_pocket'] * 0.10));
        if ($creditsLooted > 0) {
          $pdo->prepare('UPDATE players SET creds_pocket = GREATEST(0, creds_pocket - ?) WHERE id=?')->execute([$creditsLooted, (int)$defPlayer['id']]);
          $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$creditsLooted, $pid]);
        }
      } else {
        $creditsLooted = max(0, (int)floor((int)$player['creds_pocket'] * 0.10));
        if ($creditsLooted > 0) {
          $pdo->prepare('UPDATE players SET creds_pocket = GREATEST(0, creds_pocket - ?) WHERE id=?')->execute([$creditsLooted, $pid]);
          $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$creditsLooted, (int)$defPlayer['id']]);
        }
      }

      // Integrity damage — proportional to HP lost in combat, applied to both players
      $atkHpLost  = max(0, $atkStats['hp'] - max(0, (int)$result['atk_final_hp']));
      $defHpLost  = max(0, $defStats2['hp'] - max(0, (int)$result['def_final_hp']));
      $atkIntLoss = min((int)$player['integrity'], max(1, $atkHpLost));
      $defIntLoss = min((int)$defPlayer['integrity'], max(1, $defHpLost));
      $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id=?')->execute([$atkIntLoss, $pid]);
      $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id=?')->execute([$defIntLoss, (int)$defPlayer['id']]);

      // Store round log + record fight
      $logJson = json_encode(['rounds'=>$result['rounds'],'atk_s'=>$atkStats,'def_s'=>$defStats2]);
      $pdo->prepare('INSERT INTO pvp_log (attacker_id,defender_id,winner_id,rounds,atk_xp,def_xp,credits_looted,log_data) VALUES (?,?,?,?,?,?,?,?)')->execute([$pid,(int)$defPlayer['id'],$won?$pid:(int)$defPlayer['id'],$result['total_rounds'],$atkXp,$defXp,$creditsLooted,$logJson]);
      $logId = (int)$pdo->lastInsertId();

      grant_xp($pid, $atkXp);

      // Mortality alignment: beating a good player gains evil, beating an evil player gains good
      $winnerId2 = $won ? $pid : (int)$defPlayer['id'];
      $loserMortality = $won ? (int)($defPlayer['mortality'] ?? 0) : (int)($player['mortality'] ?? 0);
      if ($loserMortality > 0) {
        $mortalityDelta = -(int)max(2, min(8, (int)round($loserMortality / 10)));
      } elseif ($loserMortality < 0) {
        $mortalityDelta = (int)max(2, min(8, (int)round(-$loserMortality / 10)));
      } else {
        $mortalityDelta = -2; // fighting a neutral player = slight evil shift
      }
      try { $pdo->prepare('UPDATE players SET mortality = GREATEST(-200, LEAST(200, mortality + ?)) WHERE id=?')->execute([$mortalityDelta, $winnerId2]); } catch (Throwable $ex) {}
      $pdo->prepare('UPDATE players SET `signal` = GREATEST(0, `signal` - 1) WHERE id=?')->execute([$pid]);

      $player = current_player();

      // Notify defender
      try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_notifications (id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, type VARCHAR(40) NOT NULL DEFAULT "info", body TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_player_read (player_id, is_read)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $atkName = $player['username'];
        if ($won) {
          $notifBody = $atkName . ' attacked and defeated you. -' . $defIntLoss . ' HP' . ($creditsLooted > 0 ? ', -' . number_format($creditsLooted) . ' credits' : '') . '.';
        } else {
          $notifBody = $atkName . ' attacked you and was defeated. +' . $defXp . ' XP' . ($creditsLooted > 0 ? ', +' . number_format($creditsLooted) . ' credits looted' : '') . '.';
        }
        $pdo->prepare("INSERT INTO player_notifications (player_id,type,body) VALUES (?,?,?)")->execute([(int)$defPlayer['id'],'pvp',$notifBody]);
      } catch (Throwable $ex) {}

      $battleResult = array_merge($result, [
        'won'            => $won,
        'atk_xp'         => $atkXp,        'def_xp'    => $defXp,
        'atk_p'          => $player,        'def_p'     => $defPlayer,
        'atk_s'          => $atkStats,      'def_s'     => $defStats2,
        'int_lost'       => $atkIntLoss,    'def_int_lost' => $defIntLoss,
        'credits_looted' => $creditsLooted, 'log_id'    => $logId,
        'mortality_delta'=> $mortalityDelta ?? 0,
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

// Log detail view
$logDetail = null;
if (($tab === 'log') && isset($_GET['detail'])) {
  $did = (int)$_GET['detail'];
  try {
    $dq = $pdo->prepare("SELECT l.*, a.username AS atk_name, d.username AS def_name, w.username AS winner_name
      FROM pvp_log l JOIN players a ON a.id=l.attacker_id JOIN players d ON d.id=l.defender_id JOIN players w ON w.id=l.winner_id
      WHERE l.id=? AND (l.attacker_id=? OR l.defender_id=?)");
    $dq->execute([$did, $pid, $pid]); $logDetail = $dq->fetch();
  } catch (Throwable $e) {}
}
?>

<!-- Header -->
<?php if ($_pvpIsMerchant): ?>
<div class="flash flash-err">&#9878; Commerce Accord active — combat is locked until <?= e($_pvpMerchantUntil) ?>.</div>
<?php endif; ?>
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
  <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:center;margin-bottom:12px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px">
    <div style="text-align:center">
      <div style="font-weight:700;font-size:14px;color:var(--accent)"><?= e($r['atk_p']['username']) ?></div>
      <div style="font-size:11px;color:var(--muted)">STR <?= $r['atk_s']['str'] ?> &middot; END <?= $r['atk_s']['end'] ?> &middot; SPD <?= $r['atk_s']['spd'] ?></div>
      <div style="font-size:11px;color:var(--muted)">ATK <?= $r['atk_s']['atk'] ?> &middot; DEF <?= $r['atk_s']['def'] ?></div>
      <div style="font-size:11px;color:<?= $r['atk_final_hp'] > 0 ? 'var(--accent)' : 'var(--neon2)' ?>">HP: <?= max(0,$r['atk_final_hp']) ?> / <?= $r['atk_s']['hp'] ?></div>
    </div>
    <div style="font-size:24px;text-align:center">&#9876;</div>
    <div style="text-align:center">
      <div style="font-weight:700;font-size:14px;color:var(--neon2)"><?= e($r['def_p']['username']) ?></div>
      <div style="font-size:11px;color:var(--muted)">STR <?= $r['def_s']['str'] ?> &middot; END <?= $r['def_s']['end'] ?> &middot; SPD <?= $r['def_s']['spd'] ?></div>
      <div style="font-size:11px;color:var(--muted)">ATK <?= $r['def_s']['atk'] ?> &middot; DEF <?= $r['def_s']['def'] ?></div>
      <div style="font-size:11px;color:<?= $r['def_final_hp'] > 0 ? 'var(--accent)' : 'var(--neon2)' ?>">HP: <?= max(0,$r['def_final_hp']) ?> / <?= $r['def_s']['hp'] ?></div>
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
        <?= e($r['atk_p']['username']) ?>: <?= max(0,(int)$rnd['atk_hp']) ?> HP &nbsp;|&nbsp;
        <?= e($r['def_p']['username']) ?>: <?= max(0,(int)$rnd['def_hp']) ?> HP
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Equipment effects -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;padding:10px;background:rgba(0,0,0,.15);border-radius:6px">
    <?php foreach ([['atk_p','atk_s','var(--accent)'],['def_p','def_s','var(--neon2)']] as [$pp,$ss,$cc]): ?>
    <div style="font-size:11px">
      <div style="color:<?= $cc ?>;font-weight:700;margin-bottom:4px"><?= e($r[$pp]['username']) ?> — Loadout</div>
      <?php if ($r[$ss]['weapon_name']): ?>
        <div>&#9876; <?= e($r[$ss]['weapon_name']) ?> <span style="color:var(--neon2)">+<?= $r[$ss]['weapon_atk'] ?> ATK</span></div>
      <?php else: ?><div style="color:var(--muted)">&#9876; No weapon</div><?php endif; ?>
      <?php if ($r[$ss]['armor_name']): ?>
        <div>&#128737; <?= e($r[$ss]['armor_name']) ?> <span style="color:var(--accent)">+<?= $r[$ss]['armor_def'] ?> DEF</span></div>
      <?php else: ?><div style="color:var(--muted)">&#128737; No armor</div><?php endif; ?>
      <?php if ($r[$ss]['buff_atk'] || $r[$ss]['buff_def']): ?>
        <div style="color:#e8a33d">&#9889; Buff: +<?= $r[$ss]['buff_atk'] ?> ATK / +<?= $r[$ss]['buff_def'] ?> DEF</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Rewards -->
  <div style="display:flex;justify-content:center;gap:20px;margin-top:14px;flex-wrap:wrap">
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:#e8a33d">+<?= $r['atk_xp'] ?> XP</div><div style="font-size:10px;color:var(--muted)">XP Earned</div></div>
    <div style="text-align:center"><div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--neon2)">-<?= $r['int_lost'] ?> HP</div><div style="font-size:10px;color:var(--muted)">Health Lost</div></div>
    <?php if (($r['credits_looted'] ?? 0) > 0): ?>
    <div style="text-align:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:<?= $r['won'] ? '#3bcf63' : 'var(--neon2)' ?>">
        <?= $r['won'] ? '+' : '-' ?><?= number_format($r['credits_looted']) ?>¢
      </div>
      <div style="font-size:10px;color:var(--muted)">Credits <?= $r['won'] ? 'Looted' : 'Lost' ?></div>
    </div>
    <?php endif; ?>
    <?php $md = (int)($r['mortality_delta'] ?? 0); if ($md !== 0): ?>
    <div style="text-align:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:<?= $md > 0 ? '#e8d44d' : '#ff2d95' ?>">
        <?= $md > 0 ? '&#9728; +' : '&#9760; ' ?><?= $md ?>
      </div>
      <div style="font-size:10px;color:var(--muted)"><?= $md > 0 ? 'Good' : 'Evil' ?> Alignment</div>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!empty($r['log_id'])): ?>
  <div style="text-align:center;margin-top:10px"><a href="index.php?p=pvp&tab=log&detail=<?= (int)$r['log_id'] ?>" style="font-size:11px;color:var(--muted);text-decoration:underline">&#128203; View in combat log</a></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── ARENA ── -->
<?php if ($tab === 'arena' && !$battleResult): ?>
<?php if ((int)$myStats['unspent'] > 0): ?>
<div style="background:rgba(232,212,77,.06);border:1px solid rgba(232,212,77,.3);border-radius:6px;padding:10px 14px;font-size:13px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
  <span>&#11088; You have <b style="color:#e8d44d"><?= (int)$myStats['unspent'] ?></b> unspent combat stat <?= $myStats['unspent']!=1?'points':'point' ?>.</span>
  <a href="index.php?p=pvp&tab=stats" style="font-size:12px;color:#e8d44d;font-weight:700">Spend now &rarr;</a>
</div>
<?php endif; ?>
<div class="panel">
  <h3 style="margin-top:0">Challenge a Ghost</h3>
  <p class="muted" style="font-size:13px;margin-bottom:12px">Combat draws from your STR, SPD, END, gear, and active stim buffs. Your stats remain hidden from others unless they use a <b>Spy Protocol</b> item. Each fight costs Health — rest to recover.</p>
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:10px 14px;font-size:12px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:16px;align-items:center">
    <span>Health: <b style="color:<?= (int)$player['integrity'] < 20 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= number_format($player['integrity']) ?> / <?= number_format($player['integrity_max']) ?></b>
    <?php if ((int)$player['integrity'] < 10): ?><span style="color:var(--neon2);margin-left:4px">&#9888; Too low to fight</span><?php endif; ?></span>
    <span>Signal: <b style="color:<?= (int)$player['signal'] < 1 ? 'var(--neon2)' : 'var(--neon2)' ?>"><?= (int)$player['signal'] ?> / <?= (int)$player['signal_max'] ?></b>
    <span class="muted" style="font-size:11px"> fights remaining today</span>
    <?php if ((int)$player['signal'] < 1): ?><span style="color:var(--neon2);margin-left:4px">&#9888; Depleted</span><?php endif; ?></span>
  </div>
  <form method="post" style="display:flex;gap:8px;align-items:center;max-width:400px">
    <input type="hidden" name="action" value="challenge">
    <div class="ac-wrap" style="flex:1;position:relative">
      <input type="text" id="pvpTarget" name="target" placeholder="Ghost's handle"
             autocomplete="off" maxlength="32" data-no-counter
             style="width:100%" <?= ((int)$player['integrity'] < 10 || (int)$player['signal'] < 1) ? 'disabled' : '' ?>>
      <div class="ac-list" id="pvpAcList" style="display:none"></div>
    </div>
    <button type="submit" style="padding:10px 20px;flex:none" <?= ((int)$player['integrity'] < 10 || (int)$player['signal'] < 1) ? 'disabled' : '' ?>>&#9876; Fight</button>
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
if (!empty($arenaLatest) && !$battleResult): ?>
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

  <!-- Signal bandwidth upgrade -->
  <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:160px">
      <div style="font-weight:700;font-size:14px;color:var(--neon2)">Signal <span style="font-family:'Orbitron',sans-serif;font-size:16px"><?= (int)$player['signal_max'] ?></span> <span class="muted" style="font-size:11px;font-weight:400">/ 50 max</span></div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px">Daily combat limit. Resets each midnight. +1 capacity per point spent.</div>
    </div>
    <div style="height:6px;flex:1;min-width:80px;background:rgba(0,0,0,.3);border-radius:3px;overflow:hidden">
      <div style="width:<?= min(100, (int)round((int)$player['signal_max']/50*100)) ?>%;height:100%;background:var(--neon2);border-radius:3px"></div>
    </div>
    <?php if ((int)$myStats['unspent'] > 0 && (int)$player['signal_max'] < 50): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="spend_stat">
      <input type="hidden" name="stat" value="signal_max">
      <button type="submit" style="font-size:12px;padding:5px 14px;background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.25);color:var(--accent)">+ Spend</button>
    </form>
    <?php elseif ((int)$player['signal_max'] >= 50): ?>
    <span style="font-size:11px;color:var(--muted);font-style:italic">Max reached</span>
    <?php endif; ?>
  </div>

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

<?php if ($logDetail): ?>
<?php
  $ldData = [];
  try { $ldData = json_decode($logDetail['log_data'] ?? '', true) ?: []; } catch (Throwable $e) {}
  $ldRounds = $ldData['rounds'] ?? [];
  $ldAtkS   = $ldData['atk_s']  ?? [];
  $ldDefS   = $ldData['def_s']  ?? [];
  $ldIAtk   = (int)$logDetail['attacker_id'] === $pid;
  $ldIWon   = (int)$logDetail['winner_id']   === $pid;
?>
<div style="margin-bottom:8px"><a href="index.php?p=pvp&tab=log" style="font-size:12px;color:var(--muted)">&#8592; Back to log</a></div>
<div class="panel" style="border:1px solid <?= $ldIWon?'rgba(25,240,199,.3)':'rgba(255,45,149,.3)' ?>">
  <h3 style="margin-top:0;font-size:14px">&#128203; Battle Detail — <?= e($logDetail['atk_name']) ?> vs <?= e($logDetail['def_name']) ?></h3>
  <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
    <?= e(date('M j, Y g:ia', strtotime($logDetail['fought_at']))) ?> &middot;
    <?= (int)$logDetail['rounds'] ?> round<?= $logDetail['rounds']!=1?'s':'' ?> &middot;
    Winner: <strong style="color:<?= $ldIWon?'var(--accent)':'var(--neon2)' ?>"><?= e($logDetail['winner_name']) ?></strong>
    <?php if ((int)$logDetail['credits_looted'] > 0): ?>
    &middot; <?= number_format((int)$logDetail['credits_looted']) ?>¢ looted
    <?php endif; ?>
  </div>
  <?php if ($ldAtkS): ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;padding:10px;background:rgba(0,0,0,.15);border-radius:6px">
    <?php foreach ([[$logDetail['atk_name'],$ldAtkS,'var(--accent)'],[$logDetail['def_name'],$ldDefS,'var(--neon2)']] as [$un,$us,$uc]): ?>
    <div style="font-size:11px">
      <div style="color:<?= $uc ?>;font-weight:700;margin-bottom:3px"><?= e($un) ?></div>
      <div style="color:var(--muted)">STR <?= (int)($us['str']??0) ?> &middot; END <?= (int)($us['end']??0) ?> &middot; SPD <?= (int)($us['spd']??0) ?></div>
      <div style="color:var(--muted)">ATK <?= (int)($us['atk']??0) ?> &middot; DEF <?= (int)($us['def']??0) ?> &middot; HP <?= (int)($us['hp']??0) ?></div>
      <?php if (!empty($us['weapon_name'])): ?><div>&#9876; <?= e($us['weapon_name']) ?> <span style="color:var(--neon2)">+<?= (int)$us['weapon_atk'] ?> ATK</span></div><?php endif; ?>
      <?php if (!empty($us['armor_name'])): ?><div>&#128737; <?= e($us['armor_name']) ?> <span style="color:var(--accent)">+<?= (int)$us['armor_def'] ?> DEF</span></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($ldRounds): ?>
  <div style="max-height:400px;overflow-y:auto;border:1px solid var(--line);border-radius:6px;padding:10px">
    <?php foreach ($ldRounds as $rnd): ?>
    <div style="margin-bottom:10px">
      <div style="font-size:10px;font-family:'Orbitron',sans-serif;color:var(--muted);text-transform:uppercase;margin-bottom:3px">Round <?= $rnd['num'] ?></div>
      <?php foreach ($rnd['events'] as $ev): ?>
      <div style="font-size:12px;color:<?= $ev['color'] ?>;margin-bottom:2px;padding-left:10px">&#8250; <?= e($ev['text']) ?></div>
      <?php endforeach; ?>
      <div style="font-size:10px;color:var(--muted);margin-top:2px;padding-left:10px">
        <?= e($logDetail['atk_name']) ?>: <?= max(0,(int)$rnd['atk_hp']) ?> HP &nbsp;|&nbsp;
        <?= e($logDetail['def_name']) ?>: <?= max(0,(int)$rnd['def_hp']) ?> HP
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="muted" style="font-size:12px">No round data recorded for this fight.</p>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="panel" style="padding:0;overflow:hidden">
  <?php if (empty($recentPvp)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">No battles yet.</div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:110px 110px 110px 55px 90px 80px 70px;padding:8px 14px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--line);font-weight:700">
    <span>Attacker</span><span>Defender</span><span>Winner</span><span>Rds</span><span>XP</span><span>Date</span><span></span>
  </div>
  <?php foreach ($recentPvp as $l):
    $iAtk = (int)$l['attacker_id'] === $pid; $iWon = (int)$l['winner_id'] === $pid;
    $myXp = $iAtk ? $l['atk_xp'] : $l['def_xp'];
  ?>
  <div style="display:grid;grid-template-columns:110px 110px 110px 55px 90px 80px 70px;padding:9px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;align-items:center;background:<?= $iWon?'rgba(25,240,199,.03)':'transparent' ?>">
    <span><a href="index.php?p=profile&id=<?= (int)$l['attacker_id'] ?>"><?= e($l['atk_name']) ?></a><?= $iAtk?' <em style="font-size:10px;color:var(--muted)">(you)</em>':'' ?></span>
    <span><a href="index.php?p=profile&id=<?= (int)$l['defender_id'] ?>"><?= e($l['def_name']) ?></a><?= !$iAtk?' <em style="font-size:10px;color:var(--muted)">(you)</em>':'' ?></span>
    <span style="font-weight:700;color:<?= $iWon?'var(--accent)':'var(--neon2)' ?>"><?= e($l['winner_name']) ?><?= $iWon?' &#10003;':'' ?></span>
    <span><?= (int)$l['rounds'] ?></span>
    <span style="color:#e8a33d">+<?= (int)$myXp ?> XP</span>
    <span class="muted"><?= e(date('M j', strtotime($l['fought_at']))) ?></span>
    <span><a href="index.php?p=pvp&tab=log&detail=<?= (int)$l['id'] ?>" style="font-size:11px;color:var(--muted);text-decoration:underline">Details</a></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
