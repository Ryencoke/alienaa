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
$msgOk = false; // true only on a genuine success — drives flash-ok vs flash-err

// grant_xp() lives in lib.php (shared, concurrency-safe)

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
  if ($wg = equipped_gear($pdo, $pid2, 'weapon')) { $weaponAtk = $wg['atk']; $weaponName = $wg['name']; }
  if ($ag = equipped_gear($pdo, $pid2, 'armor'))  { $armorDef  = $ag['def']; $armorName  = $ag['name']; }

  // Active buffs
  $buffAtk = 0; $buffDef = 0;
  try {
    $bq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $bq->execute(["buff:atk:{$pid2}"]); $bv = $bq->fetchColumn();
    if ($bv !== false && $bv !== '') { [$bon,$exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffAtk = (int)$bon; }
    $bq->execute(["buff:def:{$pid2}"]); $bv = $bq->fetchColumn();
    if ($bv !== false && $bv !== '') { [$bon,$exp] = explode('|', $bv, 2); if (time() < (int)$exp) $buffDef = (int)$bon; }
  } catch (Throwable $e) {}

  // Territory perk: each district the fighter's syndicate holds adds a small
  // ATK/DEF edge (both sides get their own, since this runs per fighter).
  // Capped at +10% — map dominance is an edge, not an autowin.
  $terrMult = syn_territory_combat_mult($pdo, $pid2);
  return [
    'atk'        => max(1, (int)round(($str * 3 + $weaponAtk + $buffAtk + (int)($p['atk'] ?? 0)) * $terrMult)),
    'def'        => max(1, (int)round(($end * 2 + $armorDef  + $buffDef  + (int)($p['def'] ?? 0)) * $terrMult)),
    'spd'        => $spd,
    'hp'         => max(10, (int)$p['integrity'] + $end * 5),
    'terr_mult'  => $terrMult,
    'str'        => $str, 'end' => $end,
    'weapon_atk' => $weaponAtk, 'armor_def'   => $armorDef,
    'buff_atk'   => $buffAtk,   'buff_def'    => $buffDef,
    'weapon_name'=> $weaponName, 'armor_name' => $armorName,
  ];
}

/* Fights run on combat_engine.php (the same telegraph/stamina engine the
   Combat Sim uses — headless-tested). The defender is driven by the engine's
   AI, with two PvP-specific mappings:
   - TEMPERAMENT comes from the defender's real build (STR-heavy = Brawler,
     SPD = Stalker, END = Sentinel, balanced = Juggernaut) — deterministic,
     so scouting a target genuinely teaches you how they'll fight.
   - TELEGRAPH CLARITY comes from the SPEED gap (75 ± 1%/point, clamped
     55-95): fast attackers read tells better, fast defenders are harder to
     read. This replaces the old dodge roll as SPD's combat role. */
require_once __DIR__ . '/../combat_engine.php';

function pvp_persona(int $str, int $spd, int $end): string {
  $mx = max($str, $spd, $end);
  if ($mx - min($str, $spd, $end) <= 3) return 'juggernaut';
  if ($mx === $str) return 'brawler';
  if ($mx === $spd) return 'stalker';
  return 'sentinel';
}
function pvp_telepct(int $atkSpd, int $defSpd): int {
  return max(55, min(95, 75 + ($atkSpd - $defSpd)));
}
function pvp_patch_count(PDO $pdo, int $pid2): int {
  try {
    $pc = $pdo->prepare("SELECT COALESCE(pi.qty,0) FROM player_items pi JOIN items i ON i.id = pi.item_id
                         WHERE pi.player_id = ? AND i.code = 'patch_kit'");
    $pc->execute([$pid2]);
    return (int)($pc->fetchColumn() ?: 0);
  } catch (Throwable $e) { return 0; }
}
// pvp_log-compatible replay entries (the tab=log&detail viewer renders these
// for old and new fights alike) — built up round by round during the fight.
function pvp_round_events(array $events, string $atkName, string $defName): array {
  $out = [];
  foreach ($events as $ev) {
    switch ($ev['t']) {
      case 'pdmg':
        $verb = $ev['act'] === 'burst' ? 'bursts' : ($ev['act'] === 'feint' ? 'jabs' : 'strikes');
        $out[] = ['type'=>'hit','text'=>"{$atkName} {$verb} for {$ev['v']} damage.",'color'=>$ev['v']>25?'var(--neon2)':'var(--text)','dmg'=>$ev['v']];
        break;
      case 'edmg':
        if (!empty($ev['blocked']))
          $out[] = ['type'=>'hit','text'=>"{$atkName} blocks — {$ev['v']} chips through.",'color'=>'var(--muted)','dmg'=>$ev['v']];
        else {
          $what = $ev['act'] === 'unleash' ? 'UNLEASHES a charged blow' : ($ev['act'] === 'heavy' ? 'lands a heavy blow' : ($ev['act'] === 'guard' ? 'counter-jabs' : 'hits'));
          $out[] = ['type'=>'hit','text'=>"{$defName} {$what} for {$ev['v']} damage.",'color'=>$ev['v']>25?'var(--neon2)':'var(--text)','dmg'=>$ev['v']];
        }
        break;
      case 'stagger':  $out[] = ['type'=>'dodge','text'=>"{$defName} is STAGGERED by the block!",'color'=>'#3bcf63']; break;
      case 'open':     $out[] = ['type'=>'dodge','text'=>"{$atkName}'s feint opens {$defName}'s guard.",'color'=>'#19f0c7']; break;
      case 'interrupt':$out[] = ['type'=>'dodge','text'=>"{$atkName} interrupts the wind-up.",'color'=>'#19f0c7']; break;
      case 'charge':   $out[] = ['type'=>'dodge','text'=>"{$defName} charges a devastating blow.",'color'=>'#9d6bff']; break;
      case 'stim':     $out[] = ['type'=>'dodge','text'=>"{$atkName} slams a stim (+{$ev['v']}).",'color'=>'#3bcf63']; break;
    }
  }
  return $out;
}

$_pvpMerchantUntil = $player['merchant_until'] ?? null;
$_pvpIsMerchant = is_merchant($player);

/* ── Fight AJAX ─────────────────────────────────────────────────────────── */
if (!empty($_POST['pv_ajax'])) {
  header('Content-Type: application/json');
  $act = $_POST['pv_action'] ?? '';
  $fight = $_SESSION['pvp_fight'] ?? null;
  if ($fight && (($fight['v'] ?? 0) !== 1)) { $fight = null; unset($_SESSION['pvp_fight']); }
  try {
    $pl = current_player(); $plid = (int)$pl['id'];
    if (is_merchant($pl)) throw new RuntimeException('Commerce Accord active — combat is locked until ' . ($pl['merchant_until'] ?? '') . '.');

    if ($act === 'challenge') {
      if ($fight) { // resume instead of double-charging Signal
        echo json_encode(['ok'=>true,'state'=>cb_to_client($fight),'foe'=>$fight['pvp']['foe']]); exit;
      }
      $target = trim($_POST['target'] ?? '');
      if ($target === '') throw new RuntimeException('Enter a ghost\'s handle.');
      $q = $pdo->prepare('SELECT * FROM players WHERE username=?'); $q->execute([$target]); $defPlayer = $q->fetch();
      if (!$defPlayer) throw new RuntimeException('"' . htmlspecialchars($target, ENT_QUOTES) . '" is not a ghost in the Sprawl.');
      if ((int)$defPlayer['id'] === $plid) throw new RuntimeException("You can't fight yourself.");
      // Hard exclusions — mirror the Target Search tab: staff, banned, jailed,
      // and active Commerce Accord merchants are never attackable.
      if (in_array($defPlayer['role'], ['chatmod','moderator','admin','manager'], true)) throw new RuntimeException("You can't attack game staff.");
      if ($defPlayer['role'] === 'banned') throw new RuntimeException("That account is banned and can't be attacked.");
      $jq = $pdo->prepare("SELECT COUNT(*) FROM jail_records WHERE player_id=? AND status='active' AND release_at > NOW()");
      $jq->execute([(int)$defPlayer['id']]);
      if ((int)$jq->fetchColumn() > 0) throw new RuntimeException('That target is in the Confinement Grid.');
      if (is_merchant($defPlayer)) throw new RuntimeException("Registered merchants can't be attacked.");
      if ((int)$pl['integrity'] < 10) throw new RuntimeException('Your Health is too low to fight. Rest first.');
      if ((int)$defPlayer['integrity'] < 10) throw new RuntimeException('That ghost is already flatlined. Let them recover.');
      // Charge Signal atomically BEFORE the fight starts — parallel challenges
      // can't pass a stale read and fight past the daily cap.
      $sg = $pdo->prepare('UPDATE players SET `signal` = `signal` - 1 WHERE id=? AND `signal` >= 1');
      $sg->execute([$plid]);
      if ($sg->rowCount() !== 1) throw new RuntimeException('Signal depleted — daily combat limit reached. Signal resets at midnight.');

      $qd = $pdo->prepare('SELECT * FROM player_stats WHERE pid=?'); $qd->execute([(int)$defPlayer['id']]); $defStats = $qd->fetch();
      if (!$defStats) {
        $pdo->prepare('INSERT IGNORE INTO player_stats (pid) VALUES (?)')->execute([(int)$defPlayer['id']]);
        $qd->execute([(int)$defPlayer['id']]); $defStats = $qd->fetch();
      }
      if (!$defStats) $defStats = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];

      $atkStats  = pvp_calc_stats($pl, $myStats, $pdo);
      $defStats2 = pvp_calc_stats($defPlayer, $defStats, $pdo);
      $persona = pvp_persona((int)$defStats2['str'], (int)$defStats2['spd'], (int)$defStats2['end']);
      $fight = cb_start([
        'hp' => $atkStats['hp'], 'atk' => $atkStats['atk'], 'def' => $atkStats['def'],
        'combat_lv' => 0, 'kits' => min(9, pvp_patch_count($pdo, $plid)),
      ], [
        'code' => 'pvp:' . (int)$defPlayer['id'], 'name' => $defPlayer['username'], 'tier' => 0,
        'hp' => $defStats2['hp'], 'attack' => $defStats2['atk'], 'defense' => $defStats2['def'],
        'persona' => $persona,
        'telepct' => pvp_telepct((int)$atkStats['spd'], (int)$defStats2['spd']),
      ]);
      // PvP settle payload rides along in the session (engine passes unknown keys through).
      $fight['pvp'] = [
        'def_id' => (int)$defPlayer['id'],
        'def_name' => $defPlayer['username'],
        'def_integrity' => (int)$defPlayer['integrity'],
        'def_pocket' => (int)$defPlayer['creds_pocket'],
        'def_level' => (int)$defPlayer['level'],
        'def_mortality' => (int)($defPlayer['mortality'] ?? 0),
        'atk_s' => $atkStats, 'def_s' => $defStats2,
        'rounds' => [],
        'foe' => ['name' => $defPlayer['username'], 'level' => (int)$defPlayer['level'], 'persona' => $persona],
      ];
      $_SESSION['pvp_fight'] = $fight;
      $pl = current_player();
      echo json_encode(['ok'=>true,'state'=>cb_to_client($fight),'foe'=>$fight['pvp']['foe'],'signal'=>(int)$pl['signal']]); exit;
    }

    if (!$fight) throw new RuntimeException('No active fight.');

    if ($act === 'round') {
      $a = $_POST['act'] ?? '';
      $preRound = (int)$fight['round'];
      $r = cb_round($fight, $a);
      if (!$r['ok']) { echo json_encode(['ok'=>false,'err'=>$r['err']]); exit; }

      // Stim consumed a kit in the engine — mirror atomically BEFORE persisting.
      if ($a === 'stim') {
        $pkId = 0;
        try { $pkId = (int)($pdo->query("SELECT id FROM items WHERE code = 'patch_kit'")->fetchColumn() ?: 0); } catch (Throwable $e2) {}
        $u = $pkId ? $pdo->prepare('UPDATE player_items SET qty = qty - 1 WHERE player_id = ? AND item_id = ? AND qty >= 1') : null;
        if ($u) $u->execute([$plid, $pkId]);
        if (!$u || $u->rowCount() !== 1) { echo json_encode(['ok'=>false,'err'=>'No Patch Kits in your stash.']); exit; }
        try { $pdo->prepare('DELETE FROM player_items WHERE player_id = ? AND item_id = ? AND qty = 0')->execute([$plid, $pkId]); } catch (Throwable $e2) {}
      }

      $st = $r['st'];
      // Accumulate the pvp_log-compatible replay (capped — draws can run 25 rounds).
      if (count($st['pvp']['rounds']) < 30) {
        $st['pvp']['rounds'][] = [
          'num' => $preRound,
          'events' => pvp_round_events($r['events'], $pl['username'], $st['pvp']['def_name']),
          'atk_hp' => max(0, (int)$st['php']), 'def_hp' => max(0, (int)$st['ehp']),
        ];
      }

      if (!$st['over']) {
        $_SESSION['pvp_fight'] = $st;
        echo json_encode(['ok'=>true,'events'=>$r['events'],'state'=>cb_to_client($st)]); exit;
      }

      // ── Settle. Session keeps the PRE-round fight until the commit lands
      // (retryable, never double-granted). Outcomes: win | loss | fled | draw.
      $pv = $st['pvp'];
      $defId = (int)$pv['def_id'];
      $won  = $st['outcome'] === 'win';
      $lost = $st['outcome'] === 'loss';
      $atkXp = $won ? mt_rand(8, 20) + $pv['def_level'] * 2 : mt_rand(2, 6);
      $defXp = !$won ? mt_rand(5, 15) + (int)$pl['level'] * 2 : mt_rand(1, 4);

      $pdo->beginTransaction();
      // Credit transfer — only a decisive outcome moves credits. Deduct with a
      // balance guard and credit only what was actually deducted (a stale
      // pocket read can never mint credits).
      $creditsLooted = 0;
      if ($won || $lost) {
        $loserId    = $won ? $defId : $plid;
        $winnerGets = $won ? $plid : $defId;
        $loserPocket = (int)($won ? $pv['def_pocket'] : $pl['creds_pocket']);
        $loot = max(0, (int)floor($loserPocket * 0.10));
        if ($loot > 0) {
          $d = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id=? AND creds_pocket >= ?');
          $d->execute([$loot, $loserId, $loot]);
          if ($d->rowCount() !== 1) {
            $lp = $pdo->prepare('SELECT creds_pocket FROM players WHERE id=?');
            $lp->execute([$loserId]);
            $loot = max(0, (int)floor((int)$lp->fetchColumn() * 0.10));
            if ($loot > 0) { $d->execute([$loot, $loserId, $loot]); if ($d->rowCount() !== 1) $loot = 0; }
          }
          if ($loot > 0) $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket + ? WHERE id=?')->execute([$loot, $winnerGets]);
          $creditsLooted = $loot;
        }
      }
      // Integrity damage — proportional to fight HP lost, both sides.
      $atkIntLoss = min((int)$pl['integrity'], max(1, (int)$st['taken']));
      $defIntLoss = min((int)$pv['def_integrity'], max(1, (int)$st['dealt']));
      $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id=?')->execute([$atkIntLoss, $plid]);
      $pdo->prepare('UPDATE players SET integrity = GREATEST(0, integrity - ?) WHERE id=?')->execute([$defIntLoss, $defId]);

      $logJson = json_encode(['rounds'=>$pv['rounds'],'atk_s'=>$pv['atk_s'],'def_s'=>$pv['def_s']]);
      $pdo->prepare('INSERT INTO pvp_log (attacker_id,defender_id,winner_id,rounds,atk_xp,def_xp,credits_looted,log_data) VALUES (?,?,?,?,?,?,?,?)')
          ->execute([$plid, $defId, $won ? $plid : $defId, (int)$st['round'], $atkXp, $defXp, $creditsLooted, $logJson]);
      $logId = (int)$pdo->lastInsertId();
      $pdo->commit();

      $atkXpRes = grant_battle_xp($plid, $atkXp);
      grant_battle_xp($defId, $defXp);
      $bountyCollected = 0;
      if ($won) {
        contract_record($pdo, $plid, 'pvp_win');
        // Bounty Board: beating this ghost collects every standing bounty on
        // them (escrow-only; non-fatal, its own transaction).
        $bountyCollected = bounty_settle_on_beat($pdo, $defId, $plid, $pl['username']);
        if ($bountyCollected > 0) $pl = current_player();
      }

      // Mortality alignment — only a decisive outcome shifts it.
      $mortalityDelta = 0;
      if ($won || $lost) {
        $loserMortality = $won ? $pv['def_mortality'] : (int)($pl['mortality'] ?? 0);
        if ($loserMortality > 0)      $mortalityDelta = -(int)max(2, min(8, (int)round($loserMortality / 10)));
        elseif ($loserMortality < 0)  $mortalityDelta = (int)max(2, min(8, (int)round(-$loserMortality / 10)));
        else                          $mortalityDelta = -2;
        $winnerId2 = $won ? $plid : $defId;
        try { $pdo->prepare('UPDATE players SET mortality = GREATEST(-200, LEAST(200, mortality + ?)) WHERE id=?')->execute([$mortalityDelta, $winnerId2]); } catch (Throwable $ex) {}
      }

      // Notify the defender.
      try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_notifications (id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, type VARCHAR(40) NOT NULL DEFAULT "info", body TEXT NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_player_read (player_id, is_read)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $atkName = $pl['username'];
        if ($won)                         $notifBody = $atkName . ' attacked and defeated you. -' . $defIntLoss . ' HP' . ($creditsLooted > 0 ? ', -' . number_format($creditsLooted) . ' credits' : '') . '.';
        elseif ($lost)                    $notifBody = $atkName . ' attacked you and was defeated. +' . $defXp . ' XP' . ($creditsLooted > 0 ? ', +' . number_format($creditsLooted) . ' credits looted' : '') . '.';
        elseif ($st['outcome'] === 'fled') $notifBody = $atkName . ' attacked you, then jacked out and ran. +' . $defXp . ' XP.';
        else                              $notifBody = $atkName . ' attacked you — the fight timed out with both standing. +' . $defXp . ' XP.';
        $pdo->prepare("INSERT INTO player_notifications (player_id,type,body) VALUES (?,?,?)")->execute([$defId, 'pvp', $notifBody]);
      } catch (Throwable $ex) {}

      unset($_SESSION['pvp_fight']);
      $pl = current_player();
      echo json_encode(['ok'=>true,'events'=>$r['events'],'state'=>cb_to_client($st),
        'settle'=>[
          'outcome'=>$st['outcome'],'foe'=>$pv['def_name'],
          'creds'=>$creditsLooted,'xp'=>$atkXp,
          'donated'=>(int)$atkXpRes['donated'],'guild_levelup'=>(int)$atkXpRes['guild_levels'],
          'levelup'=>(int)$atkXpRes['levels'],
          'hp_lost'=>$atkIntLoss,'hp'=>(int)$pl['integrity'],
          'mortality'=>$mortalityDelta,'log_id'=>$logId,'bounty'=>$bountyCollected,
          'dealt'=>(int)$st['dealt'],'taken'=>(int)$st['taken'],
        ]]); exit;
    }

    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
  }
  exit;
}

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'spend_stat') {
      $stat = $_POST['stat'] ?? '';
      if ($stat === 'signal_max') {
        if ((int)$myStats['unspent'] < 1) throw new RuntimeException('No unspent stat points.');
        if ((int)$player['signal_max'] >= 50) throw new RuntimeException('Signal is already at maximum capacity (50).');
        $sp = $pdo->prepare('UPDATE player_stats SET unspent = unspent - 1 WHERE pid=? AND unspent > 0');
        $sp->execute([$pid]);
        if ($sp->rowCount() !== 1) throw new RuntimeException('No unspent stat points.');
        $pdo->prepare('UPDATE players SET signal_max = LEAST(50, signal_max + 1) WHERE id=?')->execute([$pid]);
        $qs->execute([$pid]); $myStats = $qs->fetch(); $player = current_player();
        $msg = 'Signal bandwidth upgraded!'; $msgOk = true;
      } else {
        if (!in_array($stat, ['str_pts','spd_pts','end_pts'], true)) throw new RuntimeException('Invalid stat.');
        if ((int)$myStats['unspent'] < 1) throw new RuntimeException('No unspent stat points.');
        $sp = $pdo->prepare("UPDATE player_stats SET {$stat} = {$stat} + 1, unspent = unspent - 1 WHERE pid=? AND unspent > 0");
        $sp->execute([$pid]);
        if ($sp->rowCount() !== 1) throw new RuntimeException('No unspent stat points.');
        $qs->execute([$pid]); $myStats = $qs->fetch();
        $msg = 'Stat point spent!'; $msgOk = true;
      }

    } elseif ($act === 'save_search') {
      $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_saved_searches (
        id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, slot TINYINT NOT NULL,
        name VARCHAR(40) NOT NULL, filters TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pss (player_id, slot)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $slot = (int)($_POST['slot'] ?? 0);
      if ($slot < 1 || $slot > 5) throw new RuntimeException('Pick a save slot (1-5).');
      $sname = trim($_POST['search_name'] ?? '');
      if ($sname === '') $sname = 'Search ' . $slot;
      $sname = mb_substr($sname, 0, 40);
      $filters = [
        'lvl_min' => max(0, (int)($_POST['lvl_min'] ?? 0)),
        'lvl_max' => max(0, (int)($_POST['lvl_max'] ?? 0)),
        'hp_max'  => max(0, min(100, (int)($_POST['hp_max'] ?? 100))),
        'align'   => in_array($_POST['align'] ?? '', ['any','good','evil','neutral'], true) ? $_POST['align'] : 'any',
        'exclude_friends' => !empty($_POST['exclude_friends']),
        'guild_mode' => in_array($_POST['guild_mode'] ?? '', ['any','none','mine_exclude','has_guild','specific'], true) ? $_POST['guild_mode'] : 'any',
        'guild_id' => (int)($_POST['guild_id'] ?? 0),
      ];
      $pdo->prepare('INSERT INTO pvp_saved_searches (player_id, slot, name, filters) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE name=VALUES(name), filters=VALUES(filters)')
          ->execute([$pid, $slot, $sname, json_encode($filters)]);
      $msg = 'Search saved to slot ' . $slot . '.'; $msgOk = true;

    } elseif ($act === 'blacklist_add') {
      $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_blacklist (
        id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, target_id INT NOT NULL,
        note VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pbl (player_id, target_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $handle = trim($_POST['handle'] ?? '');
      if ($handle === '') throw new RuntimeException('Enter a ghost\'s handle.');
      $tq = $pdo->prepare('SELECT id FROM players WHERE username = ?'); $tq->execute([$handle]);
      $targetId = (int)$tq->fetchColumn();
      if (!$targetId) throw new RuntimeException('"' . htmlspecialchars($handle, ENT_QUOTES) . '" is not a ghost in the Sprawl.');
      if ($targetId === $pid) throw new RuntimeException("You can't blacklist yourself.");
      $note = mb_substr(trim($_POST['note'] ?? ''), 0, 80);
      $pdo->prepare('INSERT IGNORE INTO pvp_blacklist (player_id, target_id, note) VALUES (?,?,?)')->execute([$pid, $targetId, $note]);
      $msg = e($handle) . ' added to your blacklist.'; $msgOk = true;

    } elseif ($act === 'blacklist_remove') {
      $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_blacklist (
        id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, target_id INT NOT NULL,
        note VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pbl (player_id, target_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $targetId = (int)($_POST['target_id'] ?? 0);
      $pdo->prepare('DELETE FROM pvp_blacklist WHERE player_id=? AND target_id=?')->execute([$pid, $targetId]);
      $msg = 'Removed from blacklist.'; $msgOk = true;

    } elseif ($act === 'delete_search') {
      $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_saved_searches (
        id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, slot TINYINT NOT NULL,
        name VARCHAR(40) NOT NULL, filters TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pss (player_id, slot)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $slot = (int)($_POST['slot'] ?? 0);
      $pdo->prepare('DELETE FROM pvp_saved_searches WHERE player_id=? AND slot=?')->execute([$pid, $slot]);
      $msg = 'Saved search cleared.'; $msgOk = true;
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); $msgOk = false; }
}

$tab = in_array($_GET['tab'] ?? '', ['arena','stats','log','search']) ? $_GET['tab'] : 'arena';

// Active interactive fight (resume across reloads/AJAX swaps)
$pvActiveFight = $_SESSION['pvp_fight'] ?? null;
if ($pvActiveFight && (($pvActiveFight['v'] ?? 0) !== 1)) { unset($_SESSION['pvp_fight']); $pvActiveFight = null; }
$pvClientCfg = [
  'state' => $pvActiveFight ? cb_to_client($pvActiveFight) : null,
  'foe'   => $pvActiveFight ? ($pvActiveFight['pvp']['foe'] ?? null) : null,
];

// Arriving here via a target=username link (from search results or a
// profile's Attack quick action) with no clear feedback if the fight can't
// actually happen was reported as "clicking Attack does nothing" — the
// link navigates fine, but a disabled, unexplained form looks like nothing
// happened. Surface why, explicitly, right when they land.
$targetWarn = null;
if ($tab === 'arena' && !empty($_GET['target'])) {
  if ((int)$player['integrity'] < 10) {
    $targetWarn = 'Your Health is too low to fight — rest first.';
  } elseif ((int)$player['signal'] < 1) {
    $targetWarn = 'Signal depleted — daily combat limit reached. Signal resets at midnight.';
  } else {
    try {
      $twq = $pdo->prepare('SELECT integrity FROM players WHERE username = ?');
      $twq->execute([trim($_GET['target'])]);
      $twInt = $twq->fetchColumn();
      if ($twInt === false) $targetWarn = '"' . e(trim($_GET['target'])) . '" is not a ghost in the Sprawl.';
      elseif ((int)$twInt < 10) $targetWarn = e(trim($_GET['target'])) . ' is already flatlined — let them recover before attacking again.';
    } catch (Throwable $e) {}
  }
}

// ── Target Search ────────────────────────────────────────────────────────
$savedSearches = [];
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_saved_searches (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, slot TINYINT NOT NULL,
    name VARCHAR(40) NOT NULL, filters TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pss (player_id, slot)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $ssq = $pdo->prepare('SELECT * FROM pvp_saved_searches WHERE player_id=? ORDER BY slot');
  $ssq->execute([$pid]);
  foreach ($ssq as $row) { $row['filters'] = json_decode($row['filters'], true) ?: []; $savedSearches[(int)$row['slot']] = $row; }
} catch (Throwable $e) {}

// Blacklist — a standing list of specific ghosts the player wants quick
// re-attack access to, independent of any search filter (e.g. still shown
// even if "exclude friends" would otherwise hide them from search results).
$blacklist = [];
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS pvp_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, target_id INT NOT NULL,
    note VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pbl (player_id, target_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $blq = $pdo->prepare("SELECT b.target_id, b.note, p.username, p.role, p.level, p.integrity, p.integrity_max, p.merchant_until,
      (SELECT COUNT(*) FROM jail_records j WHERE j.player_id=p.id AND j.status='active' AND j.release_at > NOW()) AS is_jailed
    FROM pvp_blacklist b JOIN players p ON p.id = b.target_id
    WHERE b.player_id=? ORDER BY b.created_at DESC");
  $blq->execute([$pid]);
  $blacklist = $blq->fetchAll();
} catch (Throwable $e) {}

$myGuildRow = null;
try {
  $mgq = $pdo->prepare('SELECT syndicate_id FROM syndicate_members WHERE player_id=?');
  $mgq->execute([$pid]); $myGuildRow = $mgq->fetch();
} catch (Throwable $e) {}
$myGuildId = $myGuildRow ? (int)$myGuildRow['syndicate_id'] : 0;

$guildList = [];
try { $guildList = $pdo->query('SELECT id, name, tag FROM syndicates ORDER BY name')->fetchAll(); } catch (Throwable $e) {}

$searchResults = [];
$searchApplied = false;
if ($tab === 'search') {
  // Loading a saved search (?load=N) or running the inline form (?run=1) — a
  // GET-driven search, not POST, so clicking Attack from results doesn't need
  // to resubmit anything and the results survive a page refresh.
  $loadSlot = (int)($_GET['load'] ?? 0);
  $f = ($loadSlot && isset($savedSearches[$loadSlot])) ? $savedSearches[$loadSlot]['filters'] : [];
  if (isset($_GET['run']) || $loadSlot) {
    $searchApplied = true;
    $lvlMin = max(0, (int)($_GET['lvl_min'] ?? $f['lvl_min'] ?? 0));
    $lvlMax = (int)($_GET['lvl_max'] ?? $f['lvl_max'] ?? 0);
    $hpMax  = max(0, min(100, (int)($_GET['hp_max'] ?? $f['hp_max'] ?? 100)));
    $align  = $_GET['align'] ?? $f['align'] ?? 'any';
    $excludeFriends = isset($_GET['run']) ? !empty($_GET['exclude_friends']) : !empty($f['exclude_friends']);
    $guildMode = $_GET['guild_mode'] ?? $f['guild_mode'] ?? 'any';
    $guildIdFilter = (int)($_GET['guild_id'] ?? $f['guild_id'] ?? 0);

    $sql = "SELECT p.*,
              (SELECT COUNT(*) FROM jail_records j WHERE j.player_id=p.id AND j.status='active' AND j.release_at > NOW()) AS is_jailed,
              sm.syndicate_id AS guild_id, s.name AS guild_name, s.tag AS guild_tag
            FROM players p
            LEFT JOIN syndicate_members sm ON sm.player_id = p.id
            LEFT JOIN syndicates s ON s.id = sm.syndicate_id
            WHERE p.id != ? AND p.role NOT IN ('admin','manager','moderator','chatmod','banned')
              AND (p.merchant_until IS NULL OR p.merchant_until < CURDATE())";
    $params = [$pid];
    if ($lvlMin > 0)  { $sql .= ' AND p.level >= ?'; $params[] = $lvlMin; }
    if ($lvlMax > 0)  { $sql .= ' AND p.level <= ?'; $params[] = $lvlMax; }
    if ($align === 'good')    $sql .= ' AND p.mortality > 0';
    elseif ($align === 'evil') $sql .= ' AND p.mortality < 0';
    elseif ($align === 'neutral') $sql .= ' AND p.mortality = 0';
    if ($guildMode === 'none') $sql .= ' AND sm.syndicate_id IS NULL';
    elseif ($guildMode === 'has_guild') $sql .= ' AND sm.syndicate_id IS NOT NULL';
    elseif ($guildMode === 'mine_exclude' && $myGuildId) { $sql .= ' AND (sm.syndicate_id IS NULL OR sm.syndicate_id != ?)'; $params[] = $myGuildId; }
    elseif ($guildMode === 'specific' && $guildIdFilter) { $sql .= ' AND sm.syndicate_id = ?'; $params[] = $guildIdFilter; }
    $sql .= ' ORDER BY p.level DESC LIMIT 60';

    try {
      $rq = $pdo->prepare($sql);
      $rq->execute($params);
      $rows = $rq->fetchAll();
      $friendIds = [];
      try {
        $fq = $pdo->prepare('SELECT friend_id FROM friends WHERE player_id=?'); $fq->execute([$pid]);
        $friendIds = array_map('intval', $fq->fetchAll(PDO::FETCH_COLUMN));
      } catch (Throwable $e) {}
      foreach ($rows as $r) {
        $hpPct = (int)$r['integrity_max'] > 0 ? round((int)$r['integrity'] / (int)$r['integrity_max'] * 100) : 0;
        if ($hpPct > $hpMax) continue;
        if ((int)$r['is_jailed'] > 0) continue;
        if ($excludeFriends && in_array((int)$r['id'], $friendIds, true)) continue;
        $searchResults[] = $r;
      }
    } catch (Throwable $e) {}
  }
}

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

<style>
#pvp-canvas{display:block;width:100%;height:108px;border-radius:9px 9px 0 0}
#pvp-head h2{text-shadow:0 0 16px rgba(255,45,149,.45)}
.pvp-tab{padding:7px 14px;border-radius:20px;font-size:12px;text-decoration:none;border:1px solid var(--line);background:var(--panel2);color:var(--muted);transition:border-color .15s,color .15s}
.pvp-tab.on{border-color:var(--neon2);background:rgba(255,45,149,.1);color:var(--neon2);box-shadow:0 0 10px rgba(255,45,149,.14)}
/* battle stage */
#pvp-stage{position:relative;display:grid;grid-template-columns:1fr 70px 1fr;gap:10px;align-items:stretch;background:radial-gradient(ellipse at 50% 120%,rgba(255,45,149,.07),transparent 60%),#0a0712;border:1px solid var(--line);border-radius:10px;padding:18px 14px 14px;overflow:hidden}
.pf{position:relative;background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:9px;padding:12px;text-align:center;transition:transform .14s ease,filter .4s,opacity .4s}
.pf.me{border-color:rgba(25,240,199,.35)}
.pf.foe{border-color:rgba(255,45,149,.35)}
.pf.lunge-r{transform:translateX(16px) rotate(1.2deg)}
.pf.lunge-l{transform:translateX(-16px) rotate(-1.2deg)}
.pf.hitflash{animation:pfHit .25s ease-out}
@keyframes pfHit{0%{filter:brightness(2.2) saturate(.2)}100%{filter:none}}
.pf.ko{filter:grayscale(1) brightness(.5);opacity:.55;transform:rotate(-2deg) translateY(5px)}
.pf-ava{width:46px;height:46px;border-radius:50%;margin:0 auto 7px;display:flex;align-items:center;justify-content:center;font-family:'Orbitron',sans-serif;font-weight:900;font-size:20px;background:var(--panel2);border:2px solid currentColor}
.pf.me .pf-ava{color:var(--accent);box-shadow:0 0 14px rgba(25,240,199,.3)}
.pf.foe .pf-ava{color:var(--neon2);box-shadow:0 0 14px rgba(255,45,149,.3)}
.pf-name{font-weight:700;font-size:13px;margin-bottom:7px}
.pf-hpbar{height:9px;border-radius:5px;background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.12);overflow:hidden}
.pf-hpfill{height:100%;border-radius:5px;width:100%;transition:width .35s cubic-bezier(.2,.8,.3,1)}
.pf.me .pf-hpfill{background:linear-gradient(90deg,#19f0c7,#3bcf63)}
.pf.foe .pf-hpfill{background:linear-gradient(90deg,#ff2d95,#ff6b35)}
.pf-hpfill.low{background:linear-gradient(90deg,#ff2d95,#e8a33d)!important}
.pf-hpt{font-size:10px;font-family:'Orbitron',sans-serif;margin-top:4px;color:var(--muted)}
.pf-stats{font-size:10px;color:var(--muted);margin-top:6px;line-height:1.6}
.pvp-mid{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px}
#pvp-vs{font-family:'Orbitron',sans-serif;font-weight:900;font-size:22px;color:var(--neon2);text-shadow:0 0 14px rgba(255,45,149,.6)}
#pvp-round{font-size:10px;font-family:'Orbitron',sans-serif;color:var(--muted);letter-spacing:.1em;min-height:14px}
.pvp-float{position:absolute;font-family:'Orbitron',sans-serif;font-weight:900;pointer-events:none;animation:pvpFloat 1s ease-out forwards;z-index:5;text-shadow:0 2px 6px #000}
@keyframes pvpFloat{0%{transform:translateY(0) scale(.7);opacity:0}15%{opacity:1;transform:translateY(-8px) scale(1.15)}100%{transform:translateY(-44px) scale(1);opacity:0}}
#pvp-stage.shake{animation:pvpShake .26s linear}
@keyframes pvpShake{0%,100%{transform:translate(0,0)}25%{transform:translate(-5px,3px)}50%{transform:translate(4px,-3px)}75%{transform:translate(-3px,-2px)}}
#pvp-ticker{min-height:20px;text-align:center;font-size:12px;padding:8px 6px 0;transition:opacity .2s}
#pvp-banner{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;background:rgba(5,3,8,.72);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .25s;z-index:8}
#pvp-banner.show{opacity:1}
#pvp-banner .pb-txt{font-family:'Orbitron',sans-serif;font-weight:900;font-size:34px;letter-spacing:.14em;transform:scale(2.6);opacity:0}
#pvp-banner.show .pb-txt{animation:pbSlam .4s cubic-bezier(.2,1.6,.4,1) forwards}
@keyframes pbSlam{to{transform:scale(1);opacity:1}}
#pvp-banner .pb-sub{font-size:12px;color:var(--text);opacity:0;margin-top:8px}
#pvp-banner.show .pb-sub{animation:pbSub .3s .35s forwards}
@keyframes pbSub{to{opacity:.85}}
#pvp-details.veiled{display:none}
#pvp-skip{font-size:10px;padding:3px 10px;background:transparent;border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:10px;cursor:pointer}
.pvp-reward{text-align:center}
.pvp-reward b{font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700}
</style>

<!-- Header -->
<?php if ($_pvpIsMerchant): ?>
<div class="flash flash-err">&#9878; Commerce Accord active — combat is locked until <?= e($_pvpMerchantUntil) ?>.</div>
<?php endif; ?>
<div class="panel" id="pvp-head" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="pvp-canvas"></canvas>
    <div style="position:absolute;left:16px;bottom:12px;pointer-events:none">
      <h2 style="margin:0">&#9876; Combat Arena</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">Ghost vs Ghost. Stats, gear, and buffs decide it. Your numbers stay hidden.</p>
    </div>
    <div style="position:absolute;right:14px;bottom:12px;text-align:right;font-size:11px;color:var(--muted)">
      <div>HP <b style="font-family:'Orbitron',sans-serif;color:<?= (int)$player['integrity'] < 20 ? 'var(--neon2)' : 'var(--accent)' ?>"><?= number_format($player['integrity']) ?></b><span style="opacity:.6">/<?= number_format($player['integrity_max']) ?></span></div>
      <div style="margin-top:2px">SIGNAL <b style="font-family:'Orbitron',sans-serif;color:var(--neon2)"><?= (int)$player['signal'] ?></b><span style="opacity:.6">/<?= (int)$player['signal_max'] ?></span></div>
    </div>
    <button id="pvp-mute" onclick="togglePvpSound()" title="Toggle sound" style="position:absolute;top:8px;right:10px;font-size:11px;padding:3px 8px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.18);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgOk ? 'flash-ok' : 'flash-err' ?>"><?= $msg ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <?php foreach (['arena'=>'&#9876; Arena','search'=>'&#128269; Target Search','stats'=>'&#128202; My Stats','log'=>'&#128203; Combat Log ('.count($recentPvp).')'] as $tid=>$tl): ?>
  <a href="index.php?p=pvp&tab=<?= $tid ?>" class="pvp-tab <?= $tab===$tid ? 'on' : '' ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>


<!-- ══ ACTIVE FIGHT ══ -->
<style>
.pv-bar{height:12px;border-radius:6px;background:#080812;overflow:hidden}
.pv-bar>div{height:100%;border-radius:6px;transition:width .35s ease}
.pv-act{flex:1;min-width:86px;padding:9px 6px;border-radius:7px;border:1px solid var(--line);background:var(--panel2);color:var(--text);cursor:pointer;font-size:12px;line-height:1.35;transition:transform .07s,border-color .15s,background .15s}
.pv-act:hover:not(:disabled){border-color:var(--neon2);background:rgba(255,45,149,.06)}
.pv-act:active:not(:disabled){transform:scale(.96)}
.pv-act:disabled{opacity:.38;cursor:not-allowed}
.pv-act b{display:block;font-size:13px}
.pv-act span{color:var(--muted);font-size:10px}
#pv-tele{border-radius:8px;padding:10px 14px;text-align:center;font-family:'Orbitron',sans-serif;font-size:13px;letter-spacing:.08em;transition:background .2s,border-color .2s}
#pv-panel.shake{animation:pvpShake .26s linear}
@keyframes pvFlashRed{0%{box-shadow:inset 0 0 60px rgba(255,45,149,.5)}100%{}}
#pv-panel.hitflash{animation:pvFlashRed .45s ease-out}
@keyframes pvEnemyHit{0%{filter:brightness(2.4) saturate(2)}100%{}}
#pv-foe-card.hit{animation:pvEnemyHit .35s ease-out}
@keyframes pvPulseGreen{0%{box-shadow:0 0 0 0 rgba(59,207,99,.5)}100%{box-shadow:0 0 22px 8px rgba(59,207,99,0)}}
#pv-you-card.heal{animation:pvPulseGreen .6s ease-out}
#pv-feed{max-height:150px;overflow-y:auto;font-size:12px;display:flex;flex-direction:column;gap:3px;text-align:left}
#pv-feed div{animation:pvIn .25s ease-out backwards}
@keyframes pvIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1}}
.pv-badge{display:inline-block;border-radius:4px;padding:2px 8px;font-size:10px;font-family:'Orbitron',sans-serif;letter-spacing:.06em}
</style>
<div id="pv-panel" class="panel" style="display:none">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <div>
      <div style="font-size:14px;font-weight:700">&#9876; <span id="pv-foe-name">&mdash;</span></div>
      <div style="font-size:11px;color:var(--muted)"><span id="pv-foe-meta"></span></div>
    </div>
    <div style="font-size:11px;color:var(--muted)">Round <b id="pv-round" style="color:var(--text)">1</b>/<span id="pv-cap">25</span></div>
  </div>

  <div id="pv-foe-card" style="background:var(--panel2);border:1px solid rgba(255,45,149,.25);border-radius:8px;padding:10px 12px;margin-bottom:10px">
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:5px">
      <span>Their Fight HP</span><span><b id="pv-ehp" style="color:var(--neon2)">0</b> / <span id="pv-emax">0</span></span>
    </div>
    <div class="pv-bar"><div id="pv-ehp-bar" style="width:100%;background:linear-gradient(90deg,var(--neon2),#ff7070)"></div></div>
    <div id="pv-badges" style="margin-top:7px;min-height:18px"></div>
  </div>

  <div id="pv-tele" style="background:rgba(255,255,255,.03);border:1px solid var(--line);margin-bottom:10px">&mdash;</div>

  <div id="pv-you-card" style="background:var(--panel2);border:1px solid rgba(25,240,199,.25);border-radius:8px;padding:10px 12px;margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:5px">
      <span>Your Fight HP</span><span><b id="pv-php" style="color:var(--accent)">0</b> / <span id="pv-pmax">0</span></span>
    </div>
    <div class="pv-bar"><div id="pv-php-bar" style="width:100%;background:linear-gradient(90deg,var(--accent),#3bcf63)"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin:8px 0 5px">
      <span>Stamina</span><span><b id="pv-stam" style="color:#e8a33d">100</b> / 100</span>
    </div>
    <div class="pv-bar" style="height:8px"><div id="pv-stam-bar" style="width:100%;background:linear-gradient(90deg,#e8a33d,#ffce6b)"></div></div>
  </div>

  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px" id="pv-actions">
    <button type="button" class="pv-act" data-act="strike" onclick="pvAct('strike')"><b>&#9876; Strike</b><span>15 stam &middot; interrupts charges</span></button>
    <button type="button" class="pv-act" data-act="guard"  onclick="pvAct('guard')"><b>&#128737; Guard</b><span>+25 stam &middot; staggers heavies</span></button>
    <button type="button" class="pv-act" data-act="feint"  onclick="pvAct('feint')"><b>&#127917; Feint</b><span>10 stam &middot; opens guards</span></button>
    <button type="button" class="pv-act" data-act="burst"  onclick="pvAct('burst')"><b>&#128165; Burst</b><span>35 stam &middot; huge on openings</span></button>
    <button type="button" class="pv-act" data-act="stim"   onclick="pvAct('stim')"><b>&#128137; Stim</b><span id="pv-stim-sub">uses a Patch Kit</span></button>
    <button type="button" class="pv-act" data-act="flee"   onclick="pvAct('flee')" style="border-color:rgba(255,45,149,.3);color:var(--neon2)"><b>&#127939; Flee</b><span>eat a parting shot</span></button>
  </div>

  <div id="pv-result" style="display:none;text-align:center;margin-bottom:10px"></div>

  <div id="pv-feed" style="background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:7px;padding:8px 10px;margin-bottom:8px"></div>
  <details style="font-size:11px;color:var(--muted)">
    <summary style="cursor:pointer">How to read the tells</summary>
    <div style="margin-top:6px;line-height:1.7">
      <b style="color:#ff9090">Heavy wind-up</b> &rarr; Guard it (staggers them &mdash; then Burst).&nbsp;
      <b style="color:#e8a33d">Quick jab</b> &rarr; trade with Strike, or Guard if hurting.&nbsp;
      <b style="color:var(--accent)">Raised guard</b> &rarr; Feint to open them, then Strike/Burst hits &times;1.5.&nbsp;
      <b style="color:#9d6bff">Core charge</b> &rarr; Strike to interrupt &mdash; or Guard and brace.&nbsp;
      Temperament comes from their real build (STR = Brawler, SPD = Stalker, END = Sentinel, balanced = Juggernaut) &mdash; scouting a target teaches you the matchup.
      Your read clarity (<span id="pv-conf">~75%</span>) is your SPD against theirs. Keys: <b>1-6</b>.
    </div>
  </details>
</div>

<!-- ── ARENA ── -->
<?php if ($tab === 'arena'): ?>
<?php if ($targetWarn): ?>
<div class="flash flash-err"><?= $targetWarn ?></div>
<?php endif; ?>
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
  <div id="pv-arena-form" style="display:flex;gap:8px;align-items:center;max-width:400px">
    <div class="ac-wrap" style="flex:1;position:relative">
      <input type="text" id="pvpTarget" name="target" placeholder="Ghost's handle"
             autocomplete="off" maxlength="32" data-no-counter value="<?= e($_GET['target'] ?? '') ?>"
             style="width:100%" <?= ((int)$player['integrity'] < 10 || (int)$player['signal'] < 1) ? 'disabled' : '' ?>>
      <div class="ac-list" id="pvpAcList" style="display:none"></div>
    </div>
    <button type="button" onclick="pvChallenge()" style="padding:10px 20px;flex:none" <?= ((int)$player['integrity'] < 10 || (int)$player['signal'] < 1) ? 'disabled' : '' ?>>Fight</button>
  </div>
  <div id="pvpConfirm" style="display:none;margin-top:6px;max-width:400px;background:rgba(25,240,199,.06);border:1px solid rgba(25,240,199,.2);border-radius:5px;padding:7px 10px;font-size:12px"></div>
  <script>
  (function(){
    PlayerAC.attach(document.getElementById('pvpTarget'), document.getElementById('pvpAcList'), {confirm: document.getElementById('pvpConfirm')});
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

<!-- ── TARGET SEARCH ── -->
<?php elseif ($tab === 'search'):
  $f = ($loadSlot && isset($savedSearches[$loadSlot])) ? $savedSearches[$loadSlot]['filters'] : [];
  $curLvlMin = $f['lvl_min'] ?? ($_GET['lvl_min'] ?? '');
  $curLvlMax = $f['lvl_max'] ?? ($_GET['lvl_max'] ?? '');
  $curHpMax  = $f['hp_max']  ?? ($_GET['hp_max'] ?? 100);
  $curAlign  = $f['align']   ?? ($_GET['align'] ?? 'any');
  $curExFriends = $f['exclude_friends'] ?? !empty($_GET['exclude_friends']);
  $curGuildMode = $f['guild_mode'] ?? ($_GET['guild_mode'] ?? 'any');
  $curGuildId   = $f['guild_id'] ?? ($_GET['guild_id'] ?? 0);
?>
<?php if ($savedSearches): ?>
<div class="panel">
  <h3 style="margin-top:0">&#128190; Saved Searches</h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php for ($s = 1; $s <= 5; $s++): if (!isset($savedSearches[$s])) continue; $sv = $savedSearches[$s]; ?>
    <div style="display:flex;align-items:center;gap:6px;background:var(--panel2);border:1px solid var(--line);border-radius:20px;padding:5px 6px 5px 14px">
      <a href="index.php?p=pvp&tab=search&load=<?= $s ?>" style="font-size:12px;color:var(--accent);text-decoration:none;font-weight:700"><?= e($sv['name']) ?></a>
      <form method="post" style="margin:0" onsubmit="return confirm('Delete this saved search?')">
        <input type="hidden" name="action" value="delete_search">
        <input type="hidden" name="slot" value="<?= $s ?>">
        <button type="submit" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;line-height:1;padding:2px 4px" title="Delete">&times;</button>
      </form>
    </div>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
    <h3 style="margin:0">&#128278; Blacklist</h3>
    <span class="muted" style="font-size:11px">Always shown here, regardless of search filters</span>
  </div>
  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:<?= $blacklist ? '14px' : '0' ?>">
    <input type="hidden" name="action" value="blacklist_add">
    <div class="ac-wrap" style="flex:1;min-width:160px;position:relative">
      <input type="text" id="pvpBlHandle" name="handle" placeholder="Ghost's handle" autocomplete="off" maxlength="32" data-no-counter style="width:100%">
      <div class="ac-list" id="pvpBlAcList" style="display:none"></div>
    </div>
    <input type="text" name="note" placeholder="Note (optional)" maxlength="80" data-no-counter style="flex:1;min-width:140px">
    <button type="submit" style="padding:8px 16px;flex:none">Add</button>
  </form>
  <script>
    PlayerAC.attach(document.getElementById('pvpBlHandle'), document.getElementById('pvpBlAcList'));
  </script>
  <?php if ($blacklist): ?>
  <div style="display:flex;flex-direction:column;gap:6px">
    <?php foreach ($blacklist as $bl):
      $blHpPct = (int)$bl['integrity_max'] > 0 ? round((int)$bl['integrity'] / (int)$bl['integrity_max'] * 100) : 0;
      $blMerchant = !empty($bl['merchant_until']) && $bl['merchant_until'] >= date('Y-m-d');
      $blAttackable = !$bl['is_jailed'] && !$blMerchant && (int)$bl['integrity'] >= 10 && !in_array($bl['role'], ['admin','manager','moderator','chatmod','banned'], true);
      $blCol = chat_color($bl['role'] ?? 'member', '');
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:6px;flex-wrap:wrap">
      <a href="index.php?p=profile&id=<?= (int)$bl['target_id'] ?>" style="font-weight:700;font-size:13px;color:<?= e($blCol) ?>;text-decoration:none;min-width:120px"><?= e($bl['username']) ?></a>
      <span style="font-size:11px;color:var(--muted)">Lv <?= (int)$bl['level'] ?></span>
      <span style="font-size:11px;color:<?= $blHpPct <= 30 ? 'var(--neon2)' : 'var(--muted)' ?>">HP <?= $blHpPct ?>%</span>
      <?php if ($bl['note']): ?><span style="font-size:11px;color:var(--muted);font-style:italic">&ldquo;<?= e($bl['note']) ?>&rdquo;</span><?php endif; ?>
      <?php if (!$blAttackable): ?>
      <span style="font-size:11px;color:var(--neon2)"><?= $bl['is_jailed'] ? 'Jailed' : ($blMerchant ? 'Commerce Accord' : ((int)$bl['integrity'] < 10 ? 'Flatlined' : 'Protected')) ?></span>
      <?php endif; ?>
      <div style="margin-left:auto;display:flex;gap:6px">
        <?php if ($blAttackable): ?>
        <a href="index.php?p=pvp&tab=arena&target=<?= urlencode($bl['username']) ?>" style="padding:5px 14px;font-size:12px;text-decoration:none;border:1px solid var(--neon2);color:var(--neon2);border-radius:6px;background:rgba(255,45,149,.05)">Attack</a>
        <?php endif; ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="blacklist_remove">
          <input type="hidden" name="target_id" value="<?= (int)$bl['target_id'] ?>">
          <button type="submit" style="padding:5px 10px;font-size:11px;background:var(--panel2);border:1px solid var(--line);color:var(--muted);border-radius:6px;cursor:pointer">Remove</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h3 style="margin-top:0">&#128269; Search for a Target</h3>
  <p class="muted" style="font-size:12px;margin-bottom:12px">Results exclude jailed ghosts, active Commerce Accord merchants, game staff, and banned accounts automatically — those are never attackable.</p>
  <form method="get" id="pvpSearchForm" style="display:flex;flex-direction:column;gap:12px">
    <input type="hidden" name="p" value="pvp">
    <input type="hidden" name="tab" value="search">
    <input type="hidden" name="run" value="1">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;align-items:stretch">
      <div style="display:flex;flex-direction:column;justify-content:flex-end">
        <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Level min</label>
        <input type="number" name="lvl_min" min="0" value="<?= e((string)$curLvlMin) ?>" style="width:100%">
      </div>
      <div style="display:flex;flex-direction:column;justify-content:flex-end">
        <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Level max</label>
        <input type="number" name="lvl_max" min="0" value="<?= e((string)$curLvlMax) ?>" style="width:100%">
      </div>
      <div style="display:flex;flex-direction:column;justify-content:flex-end">
        <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Max Health remaining (%)</label>
        <input type="number" name="hp_max" min="0" max="100" value="<?= e((string)$curHpMax) ?>" style="width:100%">
      </div>
      <div style="display:flex;flex-direction:column;justify-content:flex-end">
        <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Alignment</label>
        <select name="align" style="width:100%">
          <?php foreach (['any'=>'Any','good'=>'Good','evil'=>'Evil','neutral'=>'Neutral'] as $ak=>$al): ?>
          <option value="<?= $ak ?>" <?= $curAlign===$ak?'selected':'' ?>><?= $al ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;justify-content:flex-end">
        <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Syndicate</label>
        <select name="guild_mode" id="pvpGuildMode" style="width:100%">
          <?php foreach (['any'=>'Any','none'=>'No syndicate','has_guild'=>'In a syndicate','mine_exclude'=>'Not in my syndicate','specific'=>'Specific syndicate'] as $gk=>$gl): ?>
          <option value="<?= $gk ?>" <?= $curGuildMode===$gk?'selected':'' ?>><?= $gl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="pvpGuildSpecificWrap" style="display:flex;flex-direction:column;justify-content:flex-end;<?= $curGuildMode==='specific' ? '' : 'display:none' ?>">
        <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Which syndicate</label>
        <select name="guild_id" style="width:100%">
          <?php foreach ($guildList as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= (int)$curGuildId===(int)$g['id']?'selected':'' ?>>[<?= e($g['tag']) ?>] <?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;justify-content:flex-end">
        <div style="display:flex;align-items:center;gap:6px;height:26px">
          <input type="checkbox" name="exclude_friends" id="pvpExFriends" value="1" <?= $curExFriends?'checked':'' ?>>
          <label for="pvpExFriends" style="font-size:12px;margin:0">Exclude friends</label>
        </div>
      </div>
    </div>
    <script>
      document.getElementById('pvpGuildMode').addEventListener('change', function(){
        document.getElementById('pvpGuildSpecificWrap').style.display = this.value === 'specific' ? 'flex' : 'none';
      });
    </script>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <button type="submit" style="padding:9px 22px">Search</button>
      <span style="font-size:12px;color:var(--muted)">Save this search:</span>
      <?php for ($s = 1; $s <= 5; $s++): ?>
      <button type="button" class="pvp-savebtn" data-slot="<?= $s ?>" style="padding:5px 10px;font-size:11px;background:var(--panel2);border:1px solid var(--line);border-radius:5px;color:var(--muted);cursor:pointer"><?= isset($savedSearches[$s]) ? 'Edit' : 'Save' ?> <?= $s ?></button>
      <?php endfor; ?>
    </div>
  </form>
  <form method="post" id="pvpSaveSearchForm" style="display:none">
    <input type="hidden" name="action" value="save_search">
    <input type="hidden" name="slot" id="pvpSaveSlot" value="1">
    <input type="hidden" name="search_name" id="pvpSaveName" value="">
    <input type="hidden" name="lvl_min" id="pvpSaveLvlMin" value="">
    <input type="hidden" name="lvl_max" id="pvpSaveLvlMax" value="">
    <input type="hidden" name="hp_max" id="pvpSaveHpMax" value="">
    <input type="hidden" name="align" id="pvpSaveAlign" value="">
    <input type="hidden" name="exclude_friends" id="pvpSaveExFriends" value="">
    <input type="hidden" name="guild_mode" id="pvpSaveGuildMode" value="">
    <input type="hidden" name="guild_id" id="pvpSaveGuildId" value="">
  </form>
  <script>
  (function(){
    document.querySelectorAll('.pvp-savebtn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var slot = btn.getAttribute('data-slot');
        var name = prompt('Name this saved search (slot ' + slot + '):', '');
        if (name === null) return;
        var f = document.getElementById('pvpSearchForm');
        document.getElementById('pvpSaveSlot').value = slot;
        document.getElementById('pvpSaveName').value = name;
        document.getElementById('pvpSaveLvlMin').value = f.lvl_min.value;
        document.getElementById('pvpSaveLvlMax').value = f.lvl_max.value;
        document.getElementById('pvpSaveHpMax').value = f.hp_max.value;
        document.getElementById('pvpSaveAlign').value = f.align.value;
        document.getElementById('pvpSaveExFriends').value = f.exclude_friends.checked ? '1' : '';
        document.getElementById('pvpSaveGuildMode').value = f.guild_mode.value;
        document.getElementById('pvpSaveGuildId').value = f.guild_id ? f.guild_id.value : '';
        document.getElementById('pvpSaveSearchForm').submit();
      });
    });
  })();
  </script>
</div>

<?php if ($searchApplied): ?>
<div class="panel">
  <h3 style="margin-top:0">Results (<?= count($searchResults) ?>)</h3>
  <?php if (!$searchResults): ?>
  <p class="muted" style="text-align:center;padding:20px 0">No attackable ghosts match those filters.</p>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:6px">
    <?php foreach ($searchResults as $rr):
      $rHpPct = (int)$rr['integrity_max'] > 0 ? round((int)$rr['integrity'] / (int)$rr['integrity_max'] * 100) : 0;
      $rMort = (int)($rr['mortality'] ?? 0);
      $rCol = chat_color($rr['role'] ?? 'member', '');
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--panel2);border:1px solid var(--line);border-radius:6px;flex-wrap:wrap">
      <a href="index.php?p=profile&id=<?= (int)$rr['id'] ?>" style="font-weight:700;font-size:13px;color:<?= e($rCol) ?>;text-decoration:none;min-width:120px"><?= e($rr['username']) ?></a>
      <span style="font-size:11px;color:var(--muted)">Lv <?= (int)$rr['level'] ?></span>
      <span style="font-size:11px;color:<?= $rHpPct <= 30 ? 'var(--neon2)' : 'var(--muted)' ?>">HP <?= $rHpPct ?>%</span>
      <?php if ($rMort !== 0): ?><span style="font-size:11px;color:<?= $rMort>0?'#e8d44d':'#ff2d95' ?>"><?= $rMort>0?'Good':'Evil' ?></span><?php endif; ?>
      <?php if ($rr['guild_tag']): ?><span style="font-size:11px;color:var(--accent)">[<?= e($rr['guild_tag']) ?>]</span><?php endif; ?>
      <a href="index.php?p=pvp&tab=arena&target=<?= urlencode($rr['username']) ?>" style="margin-left:auto;padding:5px 14px;font-size:12px;text-decoration:none;border:1px solid var(--neon2);color:var(--neon2);border-radius:6px;background:rgba(255,45,149,.05)">&#9876; Attack</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
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
    'spd_pts' => ['Speed',     'How clearly you read opponents\' tells — and how hard yours are to read.', '#e8a33d', (int)$myStats['spd_pts']],
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
  <?php if ($ldAtkS):
    $ldSides = [
      ['name'=>$logDetail['atk_name'], 'stats'=>$ldAtkS, 'isYou'=>$ldIAtk],
      ['name'=>$logDetail['def_name'], 'stats'=>$ldDefS, 'isYou'=>!$ldIAtk],
    ];
  ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
    <?php foreach ($ldSides as $side):
      $us = $side['stats'];
      $sc = $side['isYou'] ? 'var(--accent)' : 'var(--neon2)';
      $sBg = $side['isYou'] ? 'rgba(25,240,199,.05)' : 'rgba(255,45,149,.05)';
      $sBd = $side['isYou'] ? 'rgba(25,240,199,.25)' : 'rgba(255,45,149,.25)';
      $hasGear = !empty($us['weapon_name']) || !empty($us['armor_name']);
    ?>
    <div style="background:<?= $sBg ?>;border:1px solid <?= $sBd ?>;border-radius:8px;padding:12px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div style="width:32px;height:32px;border-radius:50%;flex:none;background:var(--panel2);border:2px solid <?= $sc ?>;display:flex;align-items:center;justify-content:center;font-family:'Orbitron',sans-serif;font-weight:900;font-size:13px;color:<?= $sc ?>"><?= e(mb_strtoupper(mb_substr($side['name'],0,1))) ?></div>
        <div style="font-weight:700;font-size:13px;color:<?= $sc ?>"><?= e($side['name']) ?><?php if ($side['isYou']): ?> <span style="font-size:9px;color:var(--muted);font-weight:400;text-transform:uppercase;letter-spacing:.4px">(you)</span><?php endif; ?></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;<?= $hasGear ? 'margin-bottom:8px' : '' ?>">
        <?php foreach ([['STR',(int)($us['str']??0),'var(--neon2)'],['END',(int)($us['end']??0),'var(--accent)'],['SPD',(int)($us['spd']??0),'#e8a33d'],['ATK',(int)($us['atk']??0),'var(--neon2)'],['DEF',(int)($us['def']??0),'var(--accent)'],['HP',(int)($us['hp']??0),'#3bcf63']] as [$stl,$stv,$stc]): ?>
        <div style="text-align:center;background:rgba(0,0,0,.25);border-radius:5px;padding:5px 2px">
          <div style="font-family:'Orbitron',sans-serif;font-size:13px;font-weight:700;color:<?= $stc ?>"><?= $stv ?></div>
          <div style="font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px"><?= $stl ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($us['weapon_name'])): ?><div style="font-size:11px;margin-top:3px">&#9876; <?= e($us['weapon_name']) ?> <span style="color:var(--neon2)">+<?= (int)$us['weapon_atk'] ?> ATK</span></div><?php endif; ?>
      <?php if (!empty($us['armor_name'])): ?><div style="font-size:11px;margin-top:2px">&#128737; <?= e($us['armor_name']) ?> <span style="color:var(--accent)">+<?= (int)$us['armor_def'] ?> DEF</span></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($ldRounds): ?>
  <div style="max-height:420px;overflow-y:auto;border:1px solid var(--line);border-radius:6px;padding:10px;background:rgba(0,0,0,.12)">
    <?php foreach ($ldRounds as $rnd): ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:8px 10px;margin-bottom:8px">
      <div style="font-size:10px;font-family:'Orbitron',sans-serif;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px;padding-bottom:5px;border-bottom:1px solid rgba(255,255,255,.06)">&#9876; Round <?= $rnd['num'] ?></div>
      <?php foreach ($rnd['events'] as $ev):
        $evText = $ev['text'] ?? '';
        $evIsYou = mb_strpos($evText, $player['username'] . ' ') === 0;
        $evColor = $evIsYou ? 'var(--accent)' : 'var(--neon2)';
        $evIsDodge = ($ev['type'] ?? '') === 'dodge';
      ?>
      <div style="font-size:12px;color:<?= $evColor ?>;font-weight:<?= $evIsDodge ? 400 : 600 ?>;font-style:<?= $evIsDodge ? 'italic' : 'normal' ?>;margin-bottom:3px;padding-left:10px"><?= $evIsDodge ? '&#8635;' : '&#8250;' ?> <?= e($evText) ?></div>
      <?php endforeach; ?>
      <div style="font-size:10px;color:var(--muted);margin-top:5px;padding-left:10px;padding-top:5px;border-top:1px solid rgba(255,255,255,.06)">
        <span style="color:<?= $ldIAtk ? 'var(--accent)' : 'var(--neon2)' ?>;font-weight:700"><?= e($logDetail['atk_name']) ?></span>: <?= max(0,(int)$rnd['atk_hp']) ?> HP &nbsp;|&nbsp;
        <span style="color:<?= !$ldIAtk ? 'var(--accent)' : 'var(--neon2)' ?>;font-weight:700"><?= e($logDetail['def_name']) ?></span>: <?= max(0,(int)$rnd['def_hp']) ?> HP
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

<script>
/* PvP sound kit — bound once, persists across AJAX swaps. */
(function(){
  if(window._pvpFxBound) return;
  window._pvpFxBound=true;
  var ac=null, muted=localStorage.getItem('pvpMuted')==='1';
  function tone(freq,dur,type,vol,slide){
    if(muted) return;
    try{
      ac=ac||new (window.AudioContext||window.webkitAudioContext)();
      var o=ac.createOscillator(),g=ac.createGain();
      o.type=type||'sine'; o.frequency.value=freq;
      if(slide) o.frequency.exponentialRampToValueAtTime(slide,ac.currentTime+dur);
      g.gain.value=vol||.05;
      g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+dur);
      o.connect(g); g.connect(ac.destination);
      o.start(); o.stop(ac.currentTime+dur);
    }catch(e){}
  }
  window.togglePvpSound=function(){
    muted=!muted; localStorage.setItem('pvpMuted',muted?'1':'0');
    var b=document.getElementById('pvp-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;';
    if(!muted) tone(660,.08,'sine',.05);
  };
  window.pvpFX={
    tone:tone,
    hit:function(big){ tone(big?90:130,.1,'square',big?.07:.05); tone(big?700:900,.04,'sine',.03); },
    miss:function(){ tone(500,.12,'sine',.035,180); },
    intro:function(){ tone(196,.18,'square',.05); setTimeout(function(){tone(262,.22,'square',.05);},150); },
    victory:function(){ [523,659,784,1047].forEach(function(f,i){ setTimeout(function(){tone(f,.16,'sine',.055);},i*120); }); },
    defeat:function(){ tone(330,.3,'sine',.05,140); setTimeout(function(){tone(110,.4,'sine',.05);},250); }
  };
})();
</script>

<script>
(function(){
  /* mute icon re-init per swap */
  var mb=document.getElementById('pvp-mute');
  if(mb) mb.innerHTML=localStorage.getItem('pvpMuted')==='1'?'&#128263;':'&#128266;';

  /* ── Arena header: sweeping spotlights, crossed sabers, crowd ── */
  var pc=document.getElementById('pvp-canvas');
  if(pc){
    var c=pc.getContext('2d');
    var PW=560, PH=108;
    var dpr=Math.min(2,window.devicePixelRatio||1);
    pc.width=PW*dpr; pc.height=PH*dpr;
    c.scale(dpr,dpr);
    var crowd=[];
    for(var i=0;i<60;i++) crowd.push({x:8+Math.random()*(PW-16),y:PH-26+Math.random()*16,p:Math.random()*9,s:1.4+Math.random()*1.6});
    function pLoop(t){
      if(!document.body.contains(pc)) return;
      requestAnimationFrame(pLoop);
      c.clearRect(0,0,PW,PH);
      var bg=c.createLinearGradient(0,0,0,PH);
      bg.addColorStop(0,'#0c0710'); bg.addColorStop(1,'#120816');
      c.fillStyle=bg; c.fillRect(0,0,PW,PH);
      // sweeping spotlights
      for(var sp=0;sp<2;sp++){
        var ang=Math.sin(t/2400+sp*2.4)*.55;
        var ox=PW*(.3+.4*sp);
        c.save(); c.translate(ox,-6); c.rotate(ang);
        var cone=c.createLinearGradient(0,0,0,PH+20);
        var col=sp?'255,45,149':'25,240,199';
        cone.addColorStop(0,'rgba('+col+',.12)'); cone.addColorStop(1,'rgba('+col+',0)');
        c.fillStyle=cone;
        c.beginPath(); c.moveTo(0,0); c.lineTo(-34,PH+20); c.lineTo(34,PH+20); c.closePath(); c.fill();
        c.restore();
      }
      // crossed energy sabers (center)
      var gl=.6+.4*Math.sin(t/520);
      c.save(); c.translate(PW/2,52);
      [[-1,'#19f0c7'],[1,'#ff2d95']].forEach(function(sb){
        c.save(); c.rotate(sb[0]*Math.PI/5);
        c.shadowColor=sb[1]; c.shadowBlur=12*gl;
        c.strokeStyle=sb[1]; c.globalAlpha=.5+.4*gl; c.lineWidth=3; c.lineCap='round';
        c.beginPath(); c.moveTo(0,22); c.lineTo(0,-30); c.stroke();
        c.lineWidth=1; c.globalAlpha=1; c.restore();
      });
      c.shadowBlur=0; c.restore();
      // clash spark at intersection
      if(Math.random()<.06){
        c.fillStyle='rgba(255,255,255,.9)';
        c.fillRect(PW/2-1+(Math.random()-.5)*8,44+(Math.random()-.5)*8,2,2);
      }
      // crowd silhouettes (front rows, flickering phone-lights)
      for(var ci=0;ci<crowd.length;ci++){
        var K=crowd[ci];
        var bob=Math.sin(t/300+K.p)*1.1;
        c.fillStyle='rgba(0,0,0,.6)';
        c.beginPath(); c.arc(K.x,K.y+bob,K.s+1.4,0,Math.PI*2); c.fill();
        if(((t/700+K.p)%6)<.25){ c.fillStyle='rgba(180,220,255,.65)'; c.fillRect(K.x+2,K.y+bob-3,1.2,1.2); }
      }
      // floor line
      c.fillStyle='rgba(255,45,149,.08)'; c.fillRect(0,PH-8,PW,8);
    }
    requestAnimationFrame(pLoop);
  }
})();
</script>

<script>
/* ══ Interactive PvP fight client ══ */
(function(){
'use strict';
var PV = <?= json_encode($pvClientCfg) ?>;
var state=PV.state||null, foe=PV.foe||null;
var busy=false, ending=false;

var TELE={
  heavy:  {txt:'&#9888; WINDING UP A HEAVY STRIKE', col:'#ff9090', bg:'rgba(255,100,100,.08)', bd:'rgba(255,100,100,.35)'},
  quick:  {txt:'&#9889; COILING FOR A QUICK JAB',   col:'#e8a33d', bg:'rgba(232,163,61,.08)',  bd:'rgba(232,163,61,.35)'},
  guard:  {txt:'&#128737; RAISING THEIR GUARD',     col:'#19f0c7', bg:'rgba(25,240,199,.06)',  bd:'rgba(25,240,199,.3)'},
  charge: {txt:'&#9762; CHARGING A DEVASTATING BLOW',col:'#9d6bff',bg:'rgba(157,107,255,.1)',  bd:'rgba(157,107,255,.4)'},
  unleash:{txt:'&#9762;&#9762; UNLEASHING — BRACE!',col:'#ff2d95', bg:'rgba(255,45,149,.14)',  bd:'rgba(255,45,149,.55)'},
  stagger:{txt:'&#128171; STAGGERED — THEY\'RE OPEN!',col:'#3bcf63',bg:'rgba(59,207,99,.1)',   bd:'rgba(59,207,99,.45)'}
};
var PERSONA={brawler:'Brawler — swings first, thinks later',sentinel:'Sentinel — turtles behind their guard',stalker:'Stalker — fast jabs, hard to pin',juggernaut:'Juggernaut — loves charging up'};

function el(id){ return document.getElementById(id); }
function feed(html,col){
  var f=el('pv-feed'); if(!f) return;
  var d=document.createElement('div');
  d.innerHTML=html; if(col) d.style.color=col;
  f.insertBefore(d,f.firstChild);
  while(f.children.length>16) f.removeChild(f.lastChild);
}
function esc(s){ var d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }

function render(){
  if(!state||!el('pv-panel')) return;
  el('pv-foe-name').textContent=(foe&&foe.name)||state.ename;
  el('pv-foe-meta').textContent='Level '+((foe&&foe.level)||'?')+' · '+(PERSONA[state.persona]||state.persona);
  el('pv-round').textContent=state.round;
  el('pv-cap').textContent=state.cap;
  el('pv-ehp').textContent=state.ehp;
  el('pv-emax').textContent=state.emax;
  el('pv-ehp-bar').style.width=Math.max(0,Math.min(100,state.ehp/state.emax*100))+'%';
  el('pv-php').textContent=state.php;
  el('pv-pmax').textContent=state.pstart;
  el('pv-php-bar').style.width=Math.max(0,Math.min(100,state.php/state.pstart*100))+'%';
  el('pv-stam').textContent=state.stam;
  el('pv-stam-bar').style.width=state.stam+'%';
  el('pv-conf').textContent='~'+state.telepct+'%';
  var t=TELE[state.tele]||TELE.quick;
  var tb=el('pv-tele');
  tb.innerHTML=t.txt+' <span style="font-size:10px;color:var(--muted);letter-spacing:0">(read ~'+state.telepct+'%)</span>';
  tb.style.color=t.col; tb.style.background=t.bg; tb.style.borderColor=t.bd;
  var badges='';
  if(state.charge>0) badges+='<span class="pv-badge" style="background:rgba(157,107,255,.12);border:1px solid rgba(157,107,255,.4);color:#9d6bff">CHARGED</span> ';
  if(state.estag>0)  badges+='<span class="pv-badge" style="background:rgba(59,207,99,.12);border:1px solid rgba(59,207,99,.4);color:#3bcf63">STAGGERED</span> ';
  if(state.popen>0)  badges+='<span class="pv-badge" style="background:rgba(25,240,199,.1);border:1px solid rgba(25,240,199,.4);color:var(--accent)">OPENED — next hit &times;1.5</span>';
  el('pv-badges').innerHTML=badges;
  document.querySelectorAll('.pv-act').forEach(function(b){
    var a=b.getAttribute('data-act');
    if(a==='flee'){ b.disabled=ending; return; }
    if(a==='stim'){
      var can=!ending&&state.kits>0&&state.stims_used<state.stim_max&&state.php<state.pstart;
      b.disabled=!can;
      el('pv-stim-sub').textContent=state.kits+' kit'+(state.kits===1?'':'s')+' · '+(state.stim_max-state.stims_used)+' use'+((state.stim_max-state.stims_used)===1?'':'s')+' left';
      return;
    }
    b.disabled=ending||(state.costs[a]||0)>state.stam;
  });
}

function showFight(){
  el('pv-panel').style.display='';
  el('pv-result').style.display='none';
  el('pv-result').innerHTML='';
  var af=el('pv-arena-form'); if(af) af.style.display='none';
}

function pvPost(data,cb){
  if(busy) return;
  busy=true;
  data.pv_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch('index.php?p=pvp',{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;feed('Network error','var(--neon2)');});
}

var EVTXT={
  interrupt:['&#9876; You smash the wind-up — charge INTERRUPTED','#19f0c7'],
  charge:['&#9762; They finished charging. BRACE for the unleash.','#9d6bff'],
  unleash:['&#9762; CHARGED BLOW UNLEASHED','#ff2d95'],
  stagger:['&#128171; Your guard STAGGERS them — wide open','#3bcf63'],
  open:['&#127917; Feint lands — their guard is OPEN (next hit &times;1.5)','#19f0c7'],
  openhit:['&#128165; You exploit the opening','#19f0c7'],
  counter:['They counter-jab through your swing','#e8a33d']
};
function animate(events,done){
  var i=0;
  (function next(){
    if(i>=events.length){ if(done) done(); return; }
    var ev=events[i++]; var panel=el('pv-panel');
    if(ev.t==='pdmg'){
      var fc=el('pv-foe-card');
      fc.classList.remove('hit'); void fc.offsetWidth; fc.classList.add('hit');
      window.pvpFX&&window.pvpFX.hit(ev.v>25);
      feed('You '+(ev.act==='burst'?'BURST for':ev.act==='feint'?'jab for':'strike for')+' <b style="color:#19f0c7">'+ev.v+'</b>');
    } else if(ev.t==='edmg'){
      panel.classList.remove('hitflash'); panel.classList.remove('shake'); void panel.offsetWidth;
      panel.classList.add(ev.blocked?'hitflash':'shake');
      window.pvpFX&&window.pvpFX.hit(!ev.blocked&&ev.v>25);
      if(ev.blocked) feed('You block — <b style="color:#ff9090">'+ev.v+'</b> chips through','var(--muted)');
      else feed('They '+(ev.act==='unleash'?'UNLEASH on you for':ev.act==='heavy'?'land a HEAVY for':'hit for')+' <b style="color:#ff9090">'+ev.v+'</b>'+(ev.note?' ('+ev.note+')':''));
    } else if(ev.t==='stim'){
      var yc=el('pv-you-card');
      yc.classList.remove('heal'); void yc.offsetWidth; yc.classList.add('heal');
      window.pvpFX&&window.pvpFX.tone(500,.1,'sine',.045);
      feed('&#128137; Stim hits — <b style="color:#3bcf63">+'+ev.v+'</b> fight HP');
    } else if(ev.t==='regen'){
      feed('+'+ev.v+' stamina','var(--muted)');
    } else if(EVTXT[ev.t]){
      feed(EVTXT[ev.t][0],EVTXT[ev.t][1]);
    }
    setTimeout(next, ev.t==='pdmg'||ev.t==='edmg'?380:220);
  })();
}

window.pvChallenge=function(){
  var inp=el('pvpTarget');
  var target=inp?inp.value.trim():'';
  if(!target){ alert('Enter a ghost\'s handle.'); return; }
  pvPost({pv_action:'challenge',target:target},function(d){
    if(!d.ok){ alert(d.err||'Error'); return; }
    state=d.state; foe=d.foe||null;
    var f=el('pv-feed'); if(f) f.innerHTML='';
    showFight();
    render();
    window.pvpFX&&window.pvpFX.intro();
    feed('Squaring up against <b>'+esc((foe&&foe.name)||state.ename)+'</b>. Watch their tells.','#19f0c7');
  });
};

window.pvAct=function(a){
  if(!state||busy||ending) return;
  pvPost({pv_action:'round',act:a},function(d){
    if(!d.ok){ feed(d.err||'Error','var(--neon2)'); return; }
    state=d.state;
    animate(d.events,function(){
      render();
      if(d.settle){
        ending=true;
        render();
        var s=d.settle;
        var won=s.outcome==='win';
        var head, col;
        if(won){ head='VICTORY'; col='var(--accent)'; window.pvpFX&&window.pvpFX.victory(); }
        else if(s.outcome==='loss'){ head='DEFEAT'; col='var(--neon2)'; window.pvpFX&&window.pvpFX.defeat(); }
        else if(s.outcome==='fled'){ head='FLED THE FIGHT'; col='#e8a33d'; window.pvpFX&&window.pvpFX.miss(); }
        else { head='STALEMATE'; col='#e8a33d'; window.pvpFX&&window.pvpFX.miss(); }
        var bits='<div style="font-family:\'Orbitron\',sans-serif;font-weight:900;font-size:26px;letter-spacing:.12em;color:'+col+';text-shadow:0 0 18px '+col+'">'+head+'</div>'
          +'<div style="font-size:12px;color:var(--muted);margin:4px 0 10px">vs. '+esc(s.foe)+' — dealt '+s.dealt+', took '+s.taken+'</div>'
          +'<div style="display:flex;justify-content:center;gap:18px;flex-wrap:wrap">'
          +'<div class="pvp-reward"><b style="color:#e8a33d">+'+s.xp+' XP</b><div style="font-size:10px;color:var(--muted)">XP Earned'+(s.donated?' · '+s.donated+' to guild':'')+'</div></div>'
          +'<div class="pvp-reward"><b style="color:var(--neon2)">-'+s.hp_lost+' HP</b><div style="font-size:10px;color:var(--muted)">Health Lost</div></div>';
        if(s.creds>0) bits+='<div class="pvp-reward"><b style="color:'+(won?'#3bcf63':'var(--neon2)')+'">'+(won?'+':'-')+Number(s.creds).toLocaleString('en-US')+'¢</b><div style="font-size:10px;color:var(--muted)">Credits '+(won?'Looted':'Lost')+'</div></div>';
        if(s.bounty>0) bits+='<div class="pvp-reward"><b style="color:#e8d44d">&#127919; +'+Number(s.bounty).toLocaleString('en-US')+'¢</b><div style="font-size:10px;color:var(--muted)">Bounty Collected</div></div>';
        if(s.mortality) bits+='<div class="pvp-reward"><b style="color:'+(s.mortality>0?'#e8d44d':'#ff2d95')+'">'+(s.mortality>0?'&#9728; +':'&#9760; ')+s.mortality+'</b><div style="font-size:10px;color:var(--muted)">'+(s.mortality>0?'Good':'Evil')+' Alignment</div></div>';
        bits+='</div>'
          +'<div style="margin-top:12px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap">'
          +(s.log_id?'<a href="index.php?p=pvp&tab=log&detail='+s.log_id+'" style="font-size:11px;color:var(--muted);text-decoration:underline;align-self:center">&#128203; View in combat log</a>':'')
          +'<button type="button" class="pv-act" style="flex:none;min-width:0;padding:8px 18px" onclick="pvDone()">Back to Arena</button>'
          +'</div>';
        var res=el('pv-result');
        res.innerHTML=bits;
        res.style.display='';
        if(s.levelup) feed('<b style="color:#e8d44d">LEVEL UP! (+'+s.levelup+')</b>');
      }
    });
  });
};
window.pvDone=function(){
  // A full reload refreshes the header HP/Signal chips and Recent Battles honestly.
  window.location.href='index.php?p=pvp&tab=arena';
};

// keyboard 1-6 — bound once, guards against detached panel + form fields
if(!window._pvKeysBound){
  window._pvKeysBound=true;
  document.addEventListener('keydown',function(e){
    var p=document.getElementById('pv-panel');
    if(!p||!document.body.contains(p)||p.style.display==='none') return;
    if(/INPUT|TEXTAREA|SELECT/.test((e.target&&e.target.tagName)||'')) return;
    var map={'1':'strike','2':'guard','3':'feint','4':'burst','5':'stim','6':'flee'};
    if(map[e.key]){ e.preventDefault(); window.pvAct&&window.pvAct(map[e.key]); }
  });
}

if(state){ showFight(); render(); feed('Fight resumed. Watch their tells.','#19f0c7'); }
})();
</script>
