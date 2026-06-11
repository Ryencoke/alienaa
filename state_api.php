<?php /* state_api.php — live player state + online list (outside the router). */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$p = current_player();
if (!$p) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$pdo = db();
$pdo->prepare('UPDATE players SET last_seen = NOW() WHERE id = ?')->execute([$p['id']]);

// Drive regen: +1 every 5 minutes, capped at 500 (non-sub) or 1500 (sub).
try {
  $driveCap = is_subscribed($p) ? 1500 : 500;
  $regenKey = 'drive_regen_at:' . $p['id'];
  $rq = $pdo->prepare('SELECT v FROM settings WHERE k=?'); $rq->execute([$regenKey]);
  $lastRegen = (int)($rq->fetchColumn() ?: 0);
  $ticks = (int)floor((time() - $lastRegen) / 300); // 1 tick per 5 min
  if ($ticks > 0 && (int)$p['cycles'] < $driveCap) {
    $add = min($ticks, $driveCap - (int)$p['cycles']);
    $pdo->prepare('UPDATE players SET cycles = LEAST(?, cycles + ?) WHERE id=?')->execute([$driveCap, $add, $p['id']]);
    $newLastRegen = $lastRegen + ($ticks * 300);
    $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$regenKey, (string)$newLastRegen]);
    $p = current_player();
  } elseif ($lastRegen === 0) {
    // First time: seed the timer
    $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$regenKey, (string)time()]);
  }
  // Enforce cap if sub expired and cycles over 500
  if (!is_subscribed($p) && (int)$p['cycles'] > 500) {
    $pdo->prepare('UPDATE players SET cycles = 500 WHERE id=?')->execute([$p['id']]);
    $p = current_player();
  }
} catch (Throwable $e) {}

$online = ['friends'=>[], 'syndicate'=>[], 'staff'=>[]];
// Friends online
try {
  $oq1 = $pdo->prepare("SELECT p.id, p.username, p.role, p.chat_color, COALESCE(p.mortality,0) AS mortality
    FROM friends f JOIN players p ON p.id = f.friend_id
    WHERE f.player_id = ? AND p.last_seen >= (NOW() - INTERVAL 5 MINUTE)
    ORDER BY p.username LIMIT 30");
  $oq1->execute([$p['id']]);
  foreach ($oq1 as $o) $online['friends'][] = ['id'=>(int)$o['id'],'name'=>$o['username'],'color'=>chat_color($o['role'],$o['chat_color']),'mortality'=>(int)$o['mortality']];
} catch (Throwable $e) {}
// Syndicate members online
try {
  $oq2 = $pdo->prepare("SELECT p.id, p.username, p.role, p.chat_color, COALESCE(p.mortality,0) AS mortality
    FROM syndicate_members sm1
    JOIN syndicate_members sm2 ON sm2.syndicate_id=sm1.syndicate_id AND sm2.player_id != ?
    JOIN players p ON p.id = sm2.player_id
    WHERE sm1.player_id = ? AND p.last_seen >= (NOW() - INTERVAL 5 MINUTE)
    ORDER BY p.username LIMIT 30");
  $oq2->execute([$p['id'], $p['id']]);
  foreach ($oq2 as $o) $online['syndicate'][] = ['id'=>(int)$o['id'],'name'=>$o['username'],'color'=>chat_color($o['role'],$o['chat_color']),'mortality'=>(int)$o['mortality']];
} catch (Throwable $e) {}
// Staff online
try {
  $oq3 = $pdo->query("SELECT id, username, role, chat_color, COALESCE(mortality,0) AS mortality FROM players
    WHERE role IN ('manager','admin','moderator','chatmod') AND last_seen >= (NOW() - INTERVAL 5 MINUTE)
    ORDER BY username LIMIT 20");
  foreach ($oq3 as $o) $online['staff'][] = ['id'=>(int)$o['id'],'name'=>$o['username'],'color'=>chat_color($o['role'],$o['chat_color']),'mortality'=>(int)$o['mortality']];
} catch (Throwable $e) {}

echo json_encode([
  'ok'  => true,
  'pid' => (int)$p['id'],
  's'  => [
    'level'  => (int)$p['level'],
    'pocket' => (int)$p['creds_pocket'], 'bank' => (int)$p['creds_bank'], 'shards' => (int)$p['shards'],
    'integrity' => (int)$p['integrity'], 'integrity_max' => (int)$p['integrity_max'],
    'xp'     => (int)$p['xp'],     'xp_next'    => (int)$p['xp_next'],
    'signal' => (int)$p['signal'], 'signal_max' => (int)$p['signal_max'],
    'cycles' => (int)$p['cycles'], 'cycles_max' => (int)$p['cycles_max'],
  ],
  'online' => $online,
]);
