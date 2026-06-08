<?php /* state_api.php — live player state + online list (outside the router). */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$p = current_player();
if (!$p) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$pdo = db();
$pdo->prepare('UPDATE players SET last_seen = NOW() WHERE id = ?')->execute([$p['id']]);

$online = [];
foreach ($pdo->query("SELECT id, username, role, chat_color FROM players
                      WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)
                      ORDER BY username LIMIT 50") as $o) {
  $online[] = ['id' => (int)$o['id'], 'name' => $o['username'], 'color' => chat_color($o['role'], $o['chat_color'])];
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
