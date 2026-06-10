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

$online = [];
try {
  $oq = $pdo->prepare("SELECT p.id, p.username, p.role, p.chat_color
    FROM friends f JOIN players p ON p.id = f.friend_id
    WHERE f.player_id = ? AND p.last_seen >= (NOW() - INTERVAL 5 MINUTE)
    ORDER BY p.username LIMIT 50");
  $oq->execute([$p['id']]);
  foreach ($oq as $o) $online[] = ['id'=>(int)$o['id'],'name'=>$o['username'],'color'=>chat_color($o['role'],'')];
} catch (Throwable $e) {
  foreach ($pdo->query("SELECT id, username, role FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE) ORDER BY username LIMIT 50") as $o)
    $online[] = ['id'=>(int)$o['id'],'name'=>$o['username'],'color'=>chat_color($o['role'],'')];
}

echo json_encode([
  'ok' => true,
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
