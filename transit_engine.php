<?php
/* transit_engine.php — pure game logic for The Switchyard (pages/transit.php).
   No DB, no session, no output: every function takes state in and returns
   state out, so the whole rule set can be exercised headlessly by a CLI
   test harness (map connectivity, drone determinism, bust rules, demand
   pool bounds) — the page wires these to Drive charges and payouts.

   Grid codes: 0 = wall (container stack), 1 = floor, 3 = terminal (exit),
   4 = depot (spawn). Drones patrol fixed ping-pong paths over floor cells
   and advance one step per player action (two while the player is
   overloaded). All information is visible to the player — the game is
   deterministic planning, not hidden rolls. */

const TY_W = 13;
const TY_H = 11;
const TY_CRATES = 7;
const TY_DRONES = 3;
const TY_START_DRIVE = 6;
const TY_DASH_DRIVE  = 3;
const TY_JAM_DRIVE   = 5;

const TY_TIERS = [
  1 => ['name'=>'Standard Freight',  'vmin'=>20, 'vmax'=>32,  'col'=>'#8fa3c8'],
  2 => ['name'=>'Sealed Cargo',      'vmin'=>40, 'vmax'=>60,  'col'=>'#e8a33d'],
  3 => ['name'=>'Volatile Shipment', 'vmin'=>75, 'vmax'=>105, 'col'=>'#ff2d95'],
];

const TY_ROUTES = [
  'docks'   => ['name'=>'Neon Docks',    'sub'=>'Harbor freight terminal', 'icon'=>'&#9875;',   'col'=>'#19f0c7'],
  'ashfall' => ['name'=>'Ashfall Yards', 'sub'=>'Industrial reclaim belt', 'icon'=>'&#127981;', 'col'=>'#e8a33d'],
  'crown'   => ['name'=>'Crown Terminal','sub'=>'Uptown corporate depot',  'icon'=>'&#127963;', 'col'=>'#ff2d95'],
];

// Shared freight-demand pool (settings key 'transit_demand', global across
// all players): each route's payout multiplier. Deliveries soften the route
// they land at and nudge the other two up; everything drifts back toward
// 1.00 over real hours. Bounds keep it from pinning.
const TY_DEMAND_MIN = 0.60;
const TY_DEMAND_MAX = 1.60;
const TY_DEMAND_DRIFT_HR = 0.02; // per-hour pull toward 1.00

// ── Map generation ───────────────────────────────────────────────────────────

// BFS over the grid treating any non-wall tile as passable.
// Returns ["x,y" => distance-from-start].
function ty_bfs(array $g, int $sx, int $sy): array {
  $h = count($g); $w = count($g[0]);
  $seen = ["{$sx},{$sy}" => 0]; $q = [[$sx, $sy]];
  while ($q) {
    [$x, $y] = array_shift($q);
    $d = $seen["{$x},{$y}"];
    foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx, $dy]) {
      $nx = $x + $dx; $ny = $y + $dy;
      if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) continue;
      if ($g[$ny][$nx] === 0 || isset($seen["{$nx},{$ny}"])) continue;
      $seen["{$nx},{$ny}"] = $d + 1;
      $q[] = [$nx, $ny];
    }
  }
  return $seen;
}

// Maximal straight floor runs (horizontal + vertical) usable as drone patrol
// beats: length >= 5, never containing the spawn or terminal cell. Long runs
// are trimmed to a random 5-7 cell window so patrols feel present, not lost
// on a full-width aisle.
function ty_corridors(array $g, int $w, int $h, array $sp, array $ex): array {
  $bad = ["{$sp[0]},{$sp[1]}" => 1, "{$ex[0]},{$ex[1]}" => 1];
  $out = [];
  $flush = function(array $run) use (&$out, $bad) {
    if (count($run) < 5) return;
    foreach ($run as $c) if (isset($bad["{$c[0]},{$c[1]}"])) return;
    if (count($run) > 7) {
      $win = mt_rand(5, 7);
      $off = mt_rand(0, count($run) - $win);
      $run = array_slice($run, $off, $win);
    }
    $out[] = $run;
  };
  for ($y = 1; $y < $h - 1; $y++) {
    $run = [];
    for ($x = 1; $x < $w - 1; $x++) {
      if ($g[$y][$x] !== 0) { $run[] = [$x, $y]; }
      else { $flush($run); $run = []; }
    }
    $flush($run);
  }
  for ($x = 1; $x < $w - 1; $x++) {
    $run = [];
    for ($y = 1; $y < $h - 1; $y++) {
      if ($g[$y][$x] !== 0) { $run[] = [$x, $y]; }
      else { $flush($run); $run = []; }
    }
    $flush($run);
  }
  return $out;
}

// Generate a run. Skills shape the run at creation time:
//   Scavenging  -> +2% crate value per level (cap +20%)
//   Drone Ops   -> +1 overload threshold per 3 levels (base 4, cap 6)
//   Netrunning  -> +1 jammer turn per level (base 3, cap +3)
// Returns [] on the (vanishingly rare) failure to build a valid yard.
function ty_gen(int $scavLv = 0, int $droneLv = 0, int $netrunLv = 0): array {
  for ($try = 0; $try < 40; $try++) {
    $w = TY_W; $h = TY_H;
    $g = [];
    for ($y = 0; $y < $h; $y++)
      for ($x = 0; $x < $w; $x++)
        $g[$y][$x] = ($x === 0 || $y === 0 || $x === $w - 1 || $y === $h - 1) ? 0 : 1;

    // Container rows on even aisles: runs of 2-4 stacks with 1-2 cell gaps.
    for ($y = 2; $y <= $h - 3; $y += 2) {
      $x = 2;
      while ($x < $w - 2) {
        $len = mt_rand(2, 4);
        if (mt_rand(0, 99) < 72) {
          for ($i = 0; $i < $len && $x + $i < $w - 2; $i++) $g[$y][$x + $i] = 0;
        }
        $x += $len + mt_rand(1, 2);
      }
    }
    // A few vertical stacks to break the open aisles into segments.
    for ($i = 0; $i < 4; $i++) {
      $vx = mt_rand(2, $w - 3); $vy = mt_rand(2, $h - 4);
      if ($g[$vy][$vx] === 1 && $g[$vy + 1][$vx] === 1) { $g[$vy][$vx] = 0; $g[$vy + 1][$vx] = 0; }
    }

    $sp = [1, $h - 2];      // depot: bottom-left
    $ex = [$w - 2, 1];      // terminal: top-right
    $g[$sp[1]][$sp[0]] = 1; $g[$ex[1]][$ex[0]] = 1;
    $g[$h - 3][1] = 1; $g[$h - 2][2] = 1;   // keep the corners breathable
    $g[1][$w - 3] = 1; $g[2][$w - 2] = 1;

    $reach = ty_bfs($g, $sp[0], $sp[1]);
    if (!isset($reach["{$ex[0]},{$ex[1]}"])) continue;
    // Seal unreachable floor pockets so nothing spawns somewhere the player can't go.
    for ($y = 1; $y < $h - 1; $y++)
      for ($x = 1; $x < $w - 1; $x++)
        if ($g[$y][$x] === 1 && !isset($reach["{$x},{$y}"])) $g[$y][$x] = 0;

    $floor = [];
    for ($y = 1; $y < $h - 1; $y++)
      for ($x = 1; $x < $w - 1; $x++)
        if ($g[$y][$x] === 1) $floor[] = [$x, $y];
    if (count($floor) < 45) continue;

    $corr = ty_corridors($g, $w, $h, $sp, $ex);
    if (count($corr) < 2) continue;

    // Drones first: pick patrol beats, start each at its path cell farthest
    // from the depot. Crates are placed after so none can sit directly on a
    // patrol beat — loot NEAR the rail is the risk; loot ON the rail is a trap.
    shuffle($corr);
    $drones = []; $onPatrol = [];
    foreach (array_slice($corr, 0, min(TY_DRONES, count($corr))) as $path) {
      $best = 0; $bd = -1;
      foreach ($path as $ii => $cell) {
        $d = abs($cell[0] - $sp[0]) + abs($cell[1] - $sp[1]);
        if ($d > $bd) { $bd = $d; $best = $ii; }
        $onPatrol["{$cell[0]},{$cell[1]}"] = 1;
      }
      $drones[] = ['path' => $path, 'i' => $best, 'dir' => (mt_rand(0, 1) ? 1 : -1)];
    }
    if (count($drones) < 2) continue;

    // Crates: reachable floor, at least 3 steps out from the depot, never on a beat.
    $cands = array_values(array_filter($floor, function ($c) use ($sp, $ex, $reach, $onPatrol) {
      if ($c[0] === $sp[0] && $c[1] === $sp[1]) return false;
      if ($c[0] === $ex[0] && $c[1] === $ex[1]) return false;
      if (isset($onPatrol["{$c[0]},{$c[1]}"])) return false;
      return ($reach["{$c[0]},{$c[1]}"] ?? 0) >= 3;
    }));
    if (count($cands) < TY_CRATES) continue;
    shuffle($cands);
    $crates = []; $hasT3 = false;
    $picked = array_slice($cands, 0, TY_CRATES);
    foreach ($picked as $i => $c) {
      $r = mt_rand(1, 100);
      $tier = $r <= 55 ? 1 : ($r <= 85 ? 2 : 3);
      if ($i === TY_CRATES - 1 && !$hasT3) $tier = 3; // every yard holds one big score
      if ($tier === 3) $hasT3 = true;
      $t = TY_TIERS[$tier];
      $val = (int)round(mt_rand($t['vmin'], $t['vmax']) * (1 + 0.02 * min(10, $scavLv)));
      $crates["{$c[0]},{$c[1]}"] = ['t' => $tier, 'v' => $val];
    }

    $g[$sp[1]][$sp[0]] = 4;
    $g[$ex[1]][$ex[0]] = 3;
    return [
      'grid' => $g, 'w' => $w, 'h' => $h,
      'px' => $sp[0], 'py' => $sp[1],
      'spawn' => $sp, 'exit_pos' => $ex,
      'crates' => $crates, 'carried' => [], 'weight' => 0,
      'drones' => $drones, 'frozen' => 0, 'jam_used' => false,
      'moves' => 0,
      'ovl' => min(6, 4 + intdiv($droneLv, 3)),
      'jam_turns' => 3 + min(3, $netrunLv),
    ];
  }
  return [];
}

// ── Turn resolution ──────────────────────────────────────────────────────────

function ty_drone_cells(array $run): array {
  $o = [];
  foreach ($run['drones'] as $i => $dr) {
    $c = $dr['path'][$dr['i']];
    $o["{$c[0]},{$c[1]}"] = $i;
  }
  return $o;
}

function ty_drone_advance(array &$dr): void {
  $len = count($dr['path']);
  if ($len < 2) return;
  $n = $dr['i'] + $dr['dir'];
  if ($n < 0 || $n >= $len) { $dr['dir'] = -$dr['dir']; $n = $dr['i'] + $dr['dir']; }
  $dr['i'] = $n;
}

function ty_err(array $run, string $m): array {
  return ['ok' => false, 'err' => $m, 'run' => $run, 'bust' => null,
          'delivered' => false, 'picked' => null, 'cost' => 0, 'jammed' => false];
}

function ty_bust(array $run, string $reason): array {
  $dmg = min(16, 8 + 2 * (int)$run['weight']); // heavier loader, harder crash
  return ['ok' => true, 'err' => '', 'run' => $run,
          'bust' => ['dmg' => $dmg, 'reason' => $reason],
          'delivered' => false, 'picked' => null, 'cost' => 0, 'jammed' => false];
}

/* One player action. $act: move | dash | hold | jam ($dir for move/dash).
   Turn order: player acts -> pickup -> terminal check -> drones advance
   (1 step, or 2 while overloaded; frozen drones sit still and burn a jam
   turn instead). Bust = any drone ends a substep on the player, or the
   player walks onto a drone. Dashing never busts: a drone anywhere on the
   lane just blocks the dash (the pilot can see it — no accidental suicide).
   Returns ['ok','err','run','bust','delivered','picked','cost','jammed'];
   'cost' is the Drive price the caller must successfully charge BEFORE
   persisting the returned state. */
function ty_step(array $run, string $act, string $dir = ''): array {
  $res = ['ok' => true, 'err' => '', 'run' => $run, 'bust' => null,
          'delivered' => false, 'picked' => null, 'cost' => 0, 'jammed' => false];
  $D = ['up' => [0, -1], 'down' => [0, 1], 'left' => [-1, 0], 'right' => [1, 0]];
  $g = $run['grid']; $w = $run['w']; $h = $run['h'];
  $occ = ty_drone_cells($run);
  $skipDrones = false;

  if ($act === 'move') {
    if (!isset($D[$dir])) return ty_err($run, 'Bad direction.');
    [$dx, $dy] = $D[$dir];
    $nx = $run['px'] + $dx; $ny = $run['py'] + $dy;
    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h || $g[$ny][$nx] === 0) return ty_err($run, 'Blocked.');
    if (isset($occ["{$nx},{$ny}"])) return ty_bust($run, 'Walked straight into a patrol drone');
    $run['px'] = $nx; $run['py'] = $ny;

  } elseif ($act === 'dash') {
    if (!isset($D[$dir])) return ty_err($run, 'Bad direction.');
    [$dx, $dy] = $D[$dir];
    $mx = $run['px'] + $dx;     $my = $run['py'] + $dy;
    $nx = $run['px'] + 2 * $dx; $ny = $run['py'] + 2 * $dy;
    if ($mx < 0 || $my < 0 || $mx >= $w || $my >= $h || $g[$my][$mx] === 0) return ty_err($run, 'Blocked.');
    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h || $g[$ny][$nx] === 0) return ty_err($run, 'No room to dash there.');
    if (isset($occ["{$mx},{$my}"]) || isset($occ["{$nx},{$ny}"])) return ty_err($run, 'A drone blocks that lane.');
    $res['cost'] = TY_DASH_DRIVE;
    $run['px'] = $nx; $run['py'] = $ny; // dashes over a mid-lane crate without grabbing it

  } elseif ($act === 'hold') {
    // Deliberate no-op: stand still and let the patrols tick.

  } elseif ($act === 'jam') {
    if ($run['jam_used']) return ty_err($run, 'Jammer already spent this run.');
    $run['jam_used'] = true;
    $run['frozen'] = $run['jam_turns'];
    $res['cost'] = TY_JAM_DRIVE;
    $res['jammed'] = true;
    $skipDrones = true; // the freeze lands before the patrols can twitch

  } else {
    return ty_err($run, 'Unknown action.');
  }

  // Pickup: moving onto a crate scoops it automatically.
  $k = "{$run['px']},{$run['py']}";
  if (isset($run['crates'][$k])) {
    $c = $run['crates'][$k];
    $res['picked'] = ['t' => $c['t'], 'v' => $c['v'], 'x' => $run['px'], 'y' => $run['py']];
    $run['carried'][] = $c;
    $run['weight'] = count($run['carried']);
    unset($run['crates'][$k]);
  }
  $run['moves']++;

  // Terminal: delivery ends the run before the drone phase.
  if ($run['px'] === $run['exit_pos'][0] && $run['py'] === $run['exit_pos'][1]) {
    $res['delivered'] = true;
    $res['run'] = $run;
    return $res;
  }

  // Drone phase.
  if (!$skipDrones) {
    if ($run['frozen'] > 0) {
      $run['frozen']--;
    } else {
      $steps = ($run['weight'] >= $run['ovl']) ? 2 : 1;
      for ($s = 0; $s < $steps; $s++) {
        foreach ($run['drones'] as $di => $_) {
          ty_drone_advance($run['drones'][$di]);
          $c = $run['drones'][$di]['path'][$run['drones'][$di]['i']];
          if ($c[0] === $run['px'] && $c[1] === $run['py']) {
            return ty_bust($run, 'A patrol drone ran you down');
          }
        }
      }
    }
  }

  $res['run'] = $run;
  return $res;
}

// Client payload — the whole yard is visible by design (floodlit, deterministic
// planning game); there is nothing to mask. Rewards are still computed purely
// server-side from the session copy.
function ty_to_client(array $run): array {
  $crates = [];
  foreach ($run['crates'] as $k => $c) {
    [$x, $y] = array_map('intval', explode(',', $k));
    $t = TY_TIERS[$c['t']];
    $crates[] = ['x' => $x, 'y' => $y, 't' => $c['t'], 'v' => $c['v'], 'col' => $t['col'], 'name' => $t['name']];
  }
  $drones = [];
  foreach ($run['drones'] as $dr) {
    $c = $dr['path'][$dr['i']];
    if ($run['frozen'] > 0) { $n = $c; }
    else { $tmp = $dr; ty_drone_advance($tmp); $n = $tmp['path'][$tmp['i']]; }
    $drones[] = ['x' => $c[0], 'y' => $c[1], 'nx' => $n[0], 'ny' => $n[1], 'path' => $dr['path']];
  }
  $carVal = 0;
  foreach ($run['carried'] as $c) $carVal += $c['v'];
  return [
    'grid' => $run['grid'], 'w' => $run['w'], 'h' => $run['h'],
    'px' => $run['px'], 'py' => $run['py'],
    'crates' => $crates, 'drones' => $drones,
    'weight' => $run['weight'], 'ovl' => $run['ovl'],
    'carried_val' => $carVal, 'carried_n' => count($run['carried']),
    'frozen' => $run['frozen'], 'jam_used' => $run['jam_used'], 'jam_turns' => $run['jam_turns'],
    'moves' => $run['moves'], 'left' => count($run['crates']),
    'route' => $run['route'] ?? '',
  ];
}

// ── Freight demand pool ──────────────────────────────────────────────────────

// Pull every route's demand toward 1.00 by elapsed real time, clamp, restamp.
function ty_demand_drift(array $d, int $now): array {
  $hrs = max(0, ($now - (int)($d['ts'] ?? $now)) / 3600);
  foreach (TY_ROUTES as $rk => $_) {
    $v = (float)($d[$rk] ?? 1.0);
    $delta = min(abs(1.0 - $v), $hrs * TY_DEMAND_DRIFT_HR);
    $v += ($v < 1.0 ? $delta : -$delta);
    $d[$rk] = round(max(TY_DEMAND_MIN, min(TY_DEMAND_MAX, $v)), 4);
  }
  $d['ts'] = $now;
  return $d;
}

// A delivery of $crates crates softens the delivered route's demand and
// nudges the other two up by half the hit each.
function ty_demand_apply(array $d, string $route, int $crates): array {
  if (!isset(TY_ROUTES[$route]) || $crates <= 0) return $d;
  $hit = 0.03 + 0.01 * $crates;
  $d[$route] = round(max(TY_DEMAND_MIN, (float)($d[$route] ?? 1.0) - $hit), 4);
  foreach (TY_ROUTES as $rk => $_) {
    if ($rk === $route) continue;
    $d[$rk] = round(min(TY_DEMAND_MAX, (float)($d[$rk] ?? 1.0) + $hit / 2), 4);
  }
  return $d;
}
