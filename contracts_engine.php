<?php
/* contracts_engine.php — pure logic for the Contract Board (pages/contracts.php).
   No DB, no session, no output — the catalog, the deterministic daily rotation,
   and the event-matching are exercised by a headless CLI harness. lib.php's
   contract_record() wires activity completions to progress; contracts.php
   renders + claims.

   THE IDEA: the game is a sandbox of activities (Sim, mining, cargo runs, The
   Net, forging, the Arena) with no unifying goals. Contracts give direction:
   each Mountain-Time day the board offers a fixed set of objectives (the same
   set for everyone that day, rotating daily), progress ticks as you play the
   activities you already play, and finished contracts pay credits + XP. */

const CONTRACT_DAILY_COUNT = 3;

/* The catalog. Each contract watches ONE event type emitted by an activity's
   completion (see lib.php contract_record call sites):
     pve_win        — win a Combat Sim fight            (sim.php)
     pvp_win        — win an Arena fight                (pvp.php)
     ore_mined      — units of ore extracted            (mining.php, transit tunnel)
     cargo_delivered— crates delivered in the Switchyard (transit.php)
     net_dive       — a Net dive that banked/ejected    (thenet.php)
     net_core       — crack a Core on a banked dive     (thenet.php)
     gear_forged    — finish a forge at the Fab Lab      (weaponcraft.php)
     stim_brewed    — brew a stim at the Synth Den       (synth.php)
   goal is the count to complete; amount per event may be >1 (e.g. ore). */
const CONTRACT_POOL = [
  'drone_hunt'  => ['type'=>'pve_win',         'goal'=>5,  'cr'=>420, 'xp'=>40, 'icon'=>'&#128737;', 'label'=>'Drone Hunt',        'blurb'=>'Neutralize 5 targets in the Combat Sim.'],
  'arena_blood' => ['type'=>'pvp_win',         'goal'=>2,  'cr'=>360, 'xp'=>34, 'icon'=>'&#9876;',    'label'=>'Blood in the Arena', 'blurb'=>'Win 2 fights in the Combat Arena.'],
  'prospector'  => ['type'=>'ore_mined',       'goal'=>30, 'cr'=>340, 'xp'=>28, 'icon'=>'&#9935;',    'label'=>'Prospector',        'blurb'=>'Extract 30 units of ore.'],
  'dockhand'    => ['type'=>'cargo_delivered', 'goal'=>8,  'cr'=>320, 'xp'=>26, 'icon'=>'&#128230;',  'label'=>'Dockhand',          'blurb'=>'Deliver 8 cargo crates through the Switchyard.'],
  'ghost_run'   => ['type'=>'net_dive',        'goal'=>2,  'cr'=>360, 'xp'=>34, 'icon'=>'&#128225;',  'label'=>'Ghost in the Grid',  'blurb'=>'Complete 2 Net dives (bank or eject).'],
  'core_raid'   => ['type'=>'net_core',        'goal'=>1,  'cr'=>520, 'xp'=>52, 'icon'=>'&#9679;',     'label'=>'Core Raid',          'blurb'=>'Crack a Core and walk it out of the Net.'],
  'weaponsmith' => ['type'=>'gear_forged',     'goal'=>2,  'cr'=>300, 'xp'=>30, 'icon'=>'&#9874;',    'label'=>'Weaponsmith',       'blurb'=>'Forge 2 pieces of gear at the Fabrication Lab.'],
  'streetchem'  => ['type'=>'stim_brewed',     'goal'=>3,  'cr'=>260, 'xp'=>22, 'icon'=>'&#9879;',     'label'=>'Streetchem',        'blurb'=>'Brew 3 stims at the Synthesis Den.'],
  'grinder'     => ['type'=>'pve_win',         'goal'=>10, 'cr'=>760, 'xp'=>80, 'icon'=>'&#128165;',  'label'=>'The Grinder',       'blurb'=>'Neutralize 10 targets in the Combat Sim.'],
];

function contract_def(string $id): ?array { return CONTRACT_POOL[$id] ?? null; }

/* Deterministic daily rotation: the same CONTRACT_DAILY_COUNT contracts for
   everyone on a given Y-m-d, varying day to day, never repeating an id within
   a day. Ranked by md5(day:id) so it's stable and well-shuffled without any
   seeded RNG (which isn't portable across PHP builds). */
function daily_contract_ids(string $day): array {
  $keys = array_keys(CONTRACT_POOL);
  usort($keys, function ($a, $b) use ($day) {
    return strcmp(md5($day . ':' . $a), md5($day . ':' . $b));
  });
  return array_slice($keys, 0, CONTRACT_DAILY_COUNT);
}
function daily_contracts(string $day): array {
  $out = [];
  foreach (daily_contract_ids($day) as $id) $out[$id] = CONTRACT_POOL[$id];
  return $out;
}

function contract_matches(array $def, string $eventType): bool {
  return ($def['type'] ?? '') === $eventType;
}
// Progress after applying an event amount, capped at the goal.
function contract_progress_after(int $cur, int $goal, int $amount): int {
  return max(0, min($goal, $cur + max(0, $amount)));
}
