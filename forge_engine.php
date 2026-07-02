<?php
/* forge_engine.php — pure logic for the Fabrication Lab forge minigame
   (pages/weaponcraft.php). No DB, no session, no output — exercised headless
   by a CLI harness; the page wires it to ore reservation and the gear insert.

   THE GAME: heat-and-strike. The billet has a TEMPERATURE; you must land
   FORGE_STRIKES hammer blows, and each blow scores on how close the temp sits
   to the sweet spot when it lands. Every action is a DECISION, not a reflex
   (no timing bars) — the temperature, the sweet band, and the cooling per
   action are all known, so it's planning:
     STRIKE — land a blow (scores, consumes a strike, cools the billet)
     STOKE  — pump the bellows: temperature UP (no strike)
     DRAW   — pull it from the coals: temperature DOWN a little (no strike)
   Strike while too cold and the metal cracks; too hot and it burns — both
   tank that blow's score. You have an action budget; run out before all
   strikes land and the unfinished blows score zero.

   The average blow score maps to a QUALITY percent (FORGE_QMIN..ceiling).
   Fabrication skill widens the sweet band and raises the ceiling, so skilled
   crafters roll better gear. The page stores stats scaled by quality/100 plus
   a quality label, so combat/display are quality-aware with no reader changes. */

const FORGE_STRIKES    = 5;
const FORGE_ACTION_CAP = 16;
const FORGE_START      = 62;
const FORGE_BULLSEYE   = 62;
const FORGE_PERF_WIN   = 8;   // ± this from bullseye = a perfect blow (widened by Fabrication)
const FORGE_GOOD_WIN   = 20;  // ± this = a decent blow
const FORGE_STRIKE_COOL = 15;
const FORGE_STOKE_HEAT  = 22;
const FORGE_DRAW_COOL   = 8;
const FORGE_COLD = 24;  // below this, a strike cracks the metal
const FORGE_HOT  = 92;  // above this, a strike scorches it
const FORGE_QMIN = 80;  // quality floor (a botched forge still yields usable gear)
const FORGE_QBASE_CEIL = 115;

function forge_perf_win(int $fabLv): int { return FORGE_PERF_WIN + min(6, max(0, $fabLv)); }
function forge_ceiling(int $fabLv): int { return FORGE_QBASE_CEIL + min(10, max(0, $fabLv)); }

// Quality percent -> label. Shared with lib.php's display via identical bands.
function forge_grade(int $q): string {
  if ($q >= 118) return 'Masterwork';
  if ($q >= 106) return 'Fine';
  if ($q >= 96)  return 'Standard';
  if ($q >= 88)  return 'Crude';
  return 'Flawed';
}

// Small deterministic-ish jitter on heat changes (never on scoring windows,
// which stay fully readable). Pure function of the engine's own state so the
// harness can drive it; the live page seeds mt_rand normally.
function forge_jit(int $lo, int $hi): int { return mt_rand($lo, $hi); }

function forge_start(int $fabLv = 0): array {
  return [
    'v' => 1,
    'temp' => FORGE_START,
    'fab' => max(0, $fabLv),
    'perf_win' => forge_perf_win(max(0, $fabLv)),
    'ceiling'  => forge_ceiling(max(0, $fabLv)),
    'strikes' => 0, 'need' => FORGE_STRIKES,
    'actions' => 0, 'cap' => FORGE_ACTION_CAP,
    'scores' => [],
    'over' => false, 'quality' => 0, 'grade' => '',
  ];
}

function forge_score_blow(array $st): array {
  $temp = $st['temp']; $d = abs($temp - FORGE_BULLSEYE);
  $band = 'poor'; $score = 0;
  if ($temp < FORGE_COLD)      { $band = 'cold'; $score = max(5, 30 - (FORGE_COLD - $temp)); }
  elseif ($temp > FORGE_HOT)   { $band = 'hot';  $score = max(5, 40 - ($temp - FORGE_HOT) * 2); }
  elseif ($d <= $st['perf_win']) { $band = 'perfect'; $score = 100; }
  elseif ($d <= FORGE_GOOD_WIN)  {
    $band = 'good';
    $span = FORGE_GOOD_WIN - $st['perf_win'];
    $score = $span > 0 ? (int)round(95 - ($d - $st['perf_win']) / $span * 35) : 80; // 95..60
  } else {
    $band = 'poor';
    $score = max(15, 55 - ($d - FORGE_GOOD_WIN));
  }
  return ['score' => (int)$score, 'band' => $band];
}

function forge_finish(array &$st): array {
  // Unlanded strikes count as zero — running out of budget ruins the piece.
  $sum = array_sum($st['scores']);
  $avg = $sum / FORGE_STRIKES;
  $q = (int)round(FORGE_QMIN + $avg / 100 * ($st['ceiling'] - FORGE_QMIN));
  $q = max(FORGE_QMIN, min($st['ceiling'], $q));
  $st['over'] = true; $st['quality'] = $q; $st['grade'] = forge_grade($q);
  return ['t' => 'done', 'quality' => $q, 'grade' => $st['grade'],
          'strikes_landed' => count($st['scores'])];
}

function forge_err(array $st, string $m): array {
  return ['ok' => false, 'err' => $m, 'st' => $st, 'events' => []];
}

/* One action: strike | stoke | draw. Returns ['ok','err','st','events'].
   Event types: strike {score,band,temp}, heat {temp,dir}, done {quality,grade}. */
function forge_step(array $st, string $act): array {
  if ($st['over']) return forge_err($st, 'The billet is already finished.');
  if (!in_array($act, ['strike', 'stoke', 'draw'], true)) return forge_err($st, 'Unknown action.');
  $ev = [];

  if ($act === 'stoke') {
    $st['temp'] = min(100, $st['temp'] + FORGE_STOKE_HEAT + forge_jit(-2, 2));
    $st['actions']++;
    $ev[] = ['t' => 'heat', 'dir' => 'up', 'temp' => $st['temp']];
  } elseif ($act === 'draw') {
    $st['temp'] = max(0, $st['temp'] - FORGE_DRAW_COOL - forge_jit(-2, 2));
    $st['actions']++;
    $ev[] = ['t' => 'heat', 'dir' => 'down', 'temp' => $st['temp']];
  } else { // strike
    $blow = forge_score_blow($st);
    $st['scores'][] = $blow['score'];
    $st['strikes']++;
    $st['actions']++;
    $ev[] = ['t' => 'strike', 'score' => $blow['score'], 'band' => $blow['band'], 'temp' => $st['temp']];
    $st['temp'] = max(0, $st['temp'] - FORGE_STRIKE_COOL - forge_jit(-2, 2));
    if ($st['strikes'] >= FORGE_STRIKES) { $ev[] = forge_finish($st); return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev]; }
  }

  // Out of budget with strikes still needed → finish (ruined by the zeros).
  if ($st['actions'] >= $st['cap'] && $st['strikes'] < FORGE_STRIKES) {
    $ev[] = forge_finish($st);
  }
  return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
}

function forge_to_client(array $st): array {
  return [
    'temp' => $st['temp'], 'bullseye' => FORGE_BULLSEYE,
    'perf_win' => $st['perf_win'], 'good_win' => FORGE_GOOD_WIN,
    'cold' => FORGE_COLD, 'hot' => FORGE_HOT,
    'strikes' => $st['strikes'], 'need' => $st['need'],
    'actions' => $st['actions'], 'cap' => $st['cap'],
    'scores' => $st['scores'],
    'stoke_heat' => FORGE_STOKE_HEAT, 'draw_cool' => FORGE_DRAW_COOL, 'strike_cool' => FORGE_STRIKE_COOL,
    'over' => $st['over'], 'quality' => $st['quality'], 'grade' => $st['grade'],
    'ceiling' => $st['ceiling'],
  ];
}
