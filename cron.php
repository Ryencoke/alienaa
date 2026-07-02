<?php
/* cron.php — scheduled bulk maintenance for Sprawl-9.
   Everything in the game already self-heals lazily (the daily reset runs
   per-player on page load, interest/Drive/territory all accrue on access),
   so this endpoint is OPTIONAL — but running it from a real cron keeps
   OFFLINE players fresh too (their Signal/Health reset so they're valid PvP
   targets, etc.) rather than only updating when they next log in.

   Idempotent: safe to run as often as you like (e.g. hourly). It only resets
   players whose daily_reset marker isn't already today (Mountain Time), so a
   second run in the same day does nothing.

   Set CRON_TOKEN in config.php and call:
     curl "https://your.host/cron.php?token=YOUR_TOKEN"
   or a cPanel cron job:  wget -qO- "https://your.host/cron.php?token=YOUR_TOKEN"
*/

require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

// Token gate — never run maintenance for an unauthenticated caller.
$token = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
if (!defined('CRON_TOKEN') || CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, (string)$token)) {
  http_response_code(403);
  echo "forbidden\n";
  exit;
}

$pdo = db();
$mtDate = (new DateTime('now', new DateTimeZone('America/Denver')))->format('Y-m-d');
$done = [];

/* ── Daily reset for anyone not yet reset today ──────────────────────────────
   Order matters: reset stats + decay skills for players still on an OLD marker
   FIRST, then stamp every marker to today. A concurrent lazy per-player reset
   (index.php) uses the same marker, so at worst one of the two claims the day
   and the other no-ops — never a double reset. */
try {
  $pdo->beginTransaction();

  // Health / Signal / Drive top-up for players whose marker isn't today.
  $r1 = $pdo->prepare("UPDATE players p
      LEFT JOIN settings s ON s.k = CONCAT('daily_reset:', p.id)
      SET p.integrity = p.integrity_max,
          p.`signal`  = p.signal_max,
          p.cycles    = LEAST(CASE WHEN p.sub_until >= CURDATE() THEN 1500 ELSE 500 END, p.cycles + 250)
      WHERE s.v IS NULL OR s.v <> ?");
  $r1->execute([$mtDate]);
  $done['players_reset'] = $r1->rowCount();

  // Skillsoft decay: flat -1 per skill, floored at 0 — same rule as the lazy
  // path. Only for players still on an old marker.
  $r2 = $pdo->prepare("UPDATE player_skills ps
      LEFT JOIN settings s ON s.k = CONCAT('daily_reset:', ps.player_id)
      SET ps.points = GREATEST(0, ps.points - 1)
      WHERE s.v IS NULL OR s.v <> ?");
  $r2->execute([$mtDate]);
  $done['skills_decayed'] = $r2->rowCount();

  // Stamp every player's marker to today (idempotent; already-today rows are
  // a harmless no-op write).
  $pdo->prepare("INSERT INTO settings (k, v)
      SELECT CONCAT('daily_reset:', id), ? FROM players
      ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$mtDate]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "error: " . $e->getMessage() . "\n";
  exit;
}

// Housekeeping: expired Contract Board rows (older than 7 days) are dead weight
// — the board only ever shows today's. Bounded cleanup so the table can't grow
// without limit. Harmless if the table doesn't exist yet.
try {
  $c = $pdo->prepare("DELETE FROM player_contracts WHERE day < (CURDATE() - INTERVAL 7 DAY)");
  $c->execute();
  $done['contracts_pruned'] = $c->rowCount();
} catch (Throwable $e) {}

echo "ok $mtDate\n";
foreach ($done as $k => $v) echo "$k: $v\n";
