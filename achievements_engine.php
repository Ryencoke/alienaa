<?php
/* achievements_engine.php — pure logic for the Achievements system
   (pages/achievements.php). No DB, no session, no output — the catalog and the
   completion evaluation are exercised by a headless CLI harness. The page
   feeds it a player's stat map (lifetime activity counters + a few derived
   values) and renders / claims from the result.

   Long-term progression to complement the short-term Contract Board and the
   player-driven Bounty Board: permanent milestones across every activity,
   each a one-time badge + credit/shard reward. Most groups are TIERED
   (Bronze/Silver/Gold at rising thresholds of the same lifetime stat).

   Metrics come from two places (merged by the page before evaluation):
   - lifetime activity counters bumped in lib.php contract_record()/bounty
     settle: pve_win, pvp_win, ore_mined, cargo_delivered, net_dive, net_core,
     gear_forged, stim_brewed, bounty_collected
   - derived live values: level, net_worth (pocket+bank), territory_held */

const ACH_TIER_NAMES = ['Bronze', 'Silver', 'Gold'];

// group => [category, metric, icon, label, unit, tiers=[[threshold, cr, shards], ...]]
const ACH_GROUPS = [
  'pve'      => ['Combat',      'pve_win',          '&#128737;', 'Drone Slayer',   'wins',      [[10,500,0],[100,2000,0],[500,8000,3]]],
  'pvp'      => ['Combat',      'pvp_win',          '&#9876;',    'Arena Killer',   'wins',      [[5,600,0],[25,2500,0],[100,9000,3]]],
  'ore'      => ['Industry',    'ore_mined',        '&#9935;',    'Prospector',     'ore',       [[100,500,0],[1000,2200,0],[5000,8000,3]]],
  'cargo'    => ['Industry',    'cargo_delivered',  '&#128230;',  'Hauler',         'crates',    [[25,500,0],[150,2200,0],[750,8000,3]]],
  'forge'    => ['Industry',    'gear_forged',      '&#9874;',    'Weaponsmith',    'forged',    [[5,500,0],[25,2200,0],[100,8000,3]]],
  'chem'     => ['Industry',    'stim_brewed',      '&#9879;',    'Chemist',        'brewed',    [[10,450,0],[50,2000,0],[200,7500,2]]],
  'net'      => ['Netrunning',  'net_dive',         '&#128225;',  'Ghost',          'dives',     [[10,600,0],[50,2500,0],[200,9000,3]]],
  'core'     => ['Netrunning',  'net_core',         '&#9679;',     'Core Breaker',   'cores',     [[1,800,0],[10,3000,1],[50,12000,4]]],
  'bounty'   => ['Reputation',  'bounty_collected', '&#127919;',  'Bounty Hunter',  'bounties',  [[1,700,0],[5,3000,1],[25,11000,4]]],
  'level'    => ['Progression', 'level',            '&#127894;',  'Ascendant',      'level',     [[10,600,0],[25,3000,0],[50,12000,5]]],
  'wealth'   => ['Progression', 'net_worth',        '&#128176;',  'Magnate',        'cr',        [[50000,0,1],[500000,0,3],[5000000,0,10]]],
  'territory'=> ['Syndicate',   'territory_held',   '&#127937;',  'Warlord',        'districts', [[1,1000,0],[3,4000,1],[5,15000,5]]],
];

// Flatten to individual achievements: id "group.tierIndex" (1-based).
function achievements_flat(): array {
  $out = [];
  foreach (ACH_GROUPS as $gid => $g) {
    [$cat, $metric, $icon, $label, $unit, $tiers] = $g;
    foreach ($tiers as $i => $t) {
      $tierNo = $i + 1;
      $out["$gid.$tierNo"] = [
        'id' => "$gid.$tierNo", 'group' => $gid, 'tier' => $tierNo,
        'tier_name' => ACH_TIER_NAMES[$i] ?? ('T' . $tierNo),
        'category' => $cat, 'metric' => $metric, 'icon' => $icon,
        'label' => $label . ' ' . (ACH_TIER_NAMES[$i] ?? $tierNo),
        'unit' => $unit, 'threshold' => (int)$t[0], 'cr' => (int)$t[1], 'shards' => (int)$t[2],
      ];
    }
  }
  return $out;
}

function achievement_def(string $id): ?array {
  $flat = achievements_flat();
  return $flat[$id] ?? null;
}

// Every metric key the catalog references (so the page knows what to gather).
function achievements_metrics(): array {
  $m = [];
  foreach (ACH_GROUPS as $g) $m[$g[1]] = true;
  return array_keys($m);
}

// Evaluate the whole catalog against a stat map. Returns per-achievement
// ['value','threshold','complete'] keyed by id. Missing metric => value 0.
function achievements_status(array $stats): array {
  $out = [];
  foreach (achievements_flat() as $id => $a) {
    $val = (int)($stats[$a['metric']] ?? 0);
    $out[$id] = ['value' => $val, 'threshold' => $a['threshold'], 'complete' => $val >= $a['threshold']];
  }
  return $out;
}
