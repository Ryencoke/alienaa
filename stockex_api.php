<?php /* stockex_api.php — JSON polling endpoint for live Stock Exchange quotes
   (price, change, shares available). Standalone like notifications_api.php/
   chat_api.php — called directly via fetch, not through index.php's router,
   so it never needs the AJAX gateway allowlist. Read-only: does not run the
   price-drift engine itself, that still only runs from stockex.php's own
   page load — this just snapshots whatever the DB currently holds so the
   Market/Detail tabs can reflect other players' trades and the periodic
   drift tick without the viewer having to reload the page. */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
csrf_guard();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$player = current_player();
if (!$player) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$pdo = db();
$rows = [];
try {
  $rows = $pdo->query("SELECT id, price, prev_price, shares_available, shares_total FROM stocks")->fetchAll();
} catch (Throwable $e) {}

$quotes = [];
foreach ($rows as $r) {
  $diff = (int)$r['price'] - (int)$r['prev_price'];
  $quotes[(int)$r['id']] = [
    'price'     => (int)$r['price'],
    'diffPct'   => (int)$r['prev_price'] > 0 ? round($diff / (int)$r['prev_price'] * 100, 1) : 0,
    'dir'       => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
    'avail'     => (int)$r['shares_available'],
    'total'     => (int)$r['shares_total'],
  ];
}
echo json_encode(['ok' => true, 'quotes' => $quotes]);
