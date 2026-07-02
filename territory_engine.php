<?php
/* territory_engine.php — pure logic for Syndicate Territory (guilds.php).
   No DB, no session, no output — the math (garrison scaling, assault damage,
   control flips, fortification regen, credit yield, reinforce cost) is
   exercised by a headless CLI harness. Assaults themselves reuse
   combat_engine.php: a district's garrison is just a cb_start() enemy whose
   stats scale with the district's fortification. guilds.php wires this to the
   syndicate_territory table, the guild bank, and Signal.

   THE LOOP: the Sprawl's contestable districts can be held by one syndicate
   each. A held district passively accrues credits into a claimable pot (the
   reward) and regenerates fortification over time (the defense). A rival's
   member ASSAULTS by fighting the garrison (interactive combat); each win
   knocks the fortification down, and when it hits zero control FLIPS to the
   attacker's syndicate. Holders REINFORCE by spending the guild bank. */

const TERR_FORT_MAX      = 100;
const TERR_CLAIM_BASE    = 30;   // fortification when first claimed from unclaimed
const TERR_FLIP_FORT     = 40;   // fortification the conqueror inherits on a flip
const TERR_ASSAULT_DMG   = 22;   // base fortification knocked off per won assault
const TERR_REGEN_PER_HR  = 1.0;  // passive fortification regen for a held district
const TERR_YIELD_PER_HR  = 12;   // credits/hr into the pot at full fortification (scales down with fort)
const TERR_POT_CAP_PER_D = 4000; // pot cap per held district
const TERR_REINFORCE_COST = 40;  // guild-bank credits per +1 fortification
const TERR_REINFORCE_MAX  = 20;  // fortification added per reinforce action
const TERR_ASSAULT_SIGNAL = 1;   // Signal charged per assault (like a PvP fight)

// Contestable districts — a curated subset of the city map (home turf like
// The Stacks is deliberately not contestable). key => [name, flavor perk blurb].
const TERR_DISTRICTS = [
  'docks'      => ['The Loading Docks', 'Skim the cargo lanes — richest credit yield in the Sprawl.'],
  'exchange'   => ['The Exchange Block', 'Control the money floors; the tithe box fills fast here.'],
  'neonstrip'  => ['Neon Strip',        'Vice pays nightly — steady, sticky income.'],
  'undervolt'  => ['The Undervolt',      'The house cut from every table down here.'],
  'forge'      => ['The Forge Quarter',  'Tax the hot metal trade.'],
  'datacore'   => ['The Datacore',       'Sell access to the stacks of knowledge.'],
  'foundry'    => ['Foundry Sector',     'Scrap in, credits out.'],
  'hydrofarms' => ['The Hydrofarms',     'Skim the growbeds under glass.'],
];

function terr_is_district(string $k): bool { return isset(TERR_DISTRICTS[$k]); }
function terr_name(string $k): string { return TERR_DISTRICTS[$k][0] ?? $k; }

// Garrison combat stats from fortification (0-100). Fed straight to
// combat_engine's cb_start() as the enemy block. Higher fortification = a
// tougher, more defensive garrison (Sentinel temperament: it turtles).
function terr_garrison(string $districtKey, float $fort): array {
  $f = max(0.0, min((float)TERR_FORT_MAX, $fort));
  return [
    'code'    => 'garrison:' . $districtKey,
    'name'    => terr_name($districtKey) . ' Garrison',
    'tier'    => 0,
    'hp'      => (int)round(40 + $f * 3.6),   // 40 .. 400 fight HP
    'attack'  => (int)round(10 + $f * 0.32),  // 10 .. 42
    'defense' => (int)round(4  + $f * 0.14),  // 4 .. 18
    'persona' => 'sentinel',
    // Read clarity is fixed (no SPD duel) — a shade below the PvP baseline so
    // a garrison is a touch harder to read than an even ghost.
    'telepct' => 70,
  ];
}

// Fortification knocked off by a won assault. A dominant win (little HP lost)
// hits harder; a squeaker chips less. $hpFrac = attacker HP remaining / start.
function terr_assault_damage(float $hpFrac): int {
  $hpFrac = max(0.0, min(1.0, $hpFrac));
  return (int)round(TERR_ASSAULT_DMG * (0.6 + 0.4 * $hpFrac)); // 60%..100% of base
}

// Apply a won assault to a district's fortification. Returns
// ['fort'=>newFort, 'flipped'=>bool] — flipped means control transfers to the
// attacker and fortification resets to the conqueror's inherited base.
function terr_apply_assault(float $fort, int $dmg): array {
  $nf = $fort - $dmg;
  if ($nf <= 0) return ['fort' => (float)TERR_FLIP_FORT, 'flipped' => true];
  return ['fort' => $nf, 'flipped' => false];
}

// Passive fortification regen for a held district over elapsed real hours,
// capped at TERR_FORT_MAX.
function terr_regen(float $fort, float $hours): float {
  if ($hours <= 0) return $fort;
  return min((float)TERR_FORT_MAX, $fort + $hours * TERR_REGEN_PER_HR);
}

// Credits accrued into a district's pot over elapsed hours. Scales with
// fortification (a well-held district yields more), capped per district.
function terr_yield(float $fort, float $hours, float $potNow): int {
  if ($hours <= 0) return (int)$potNow;
  $rate = TERR_YIELD_PER_HR * (0.4 + 0.6 * (max(0.0, min(100.0, $fort)) / 100.0)); // 40%..100%
  return (int)min((float)TERR_POT_CAP_PER_D, $potNow + $hours * $rate);
}

// Reinforce: credits required to add $pts fortification (clamped to the max
// per action and to the fortification ceiling from the current level).
function terr_reinforce_points(float $fort, int $want): int {
  $room = (int)floor(TERR_FORT_MAX - $fort);
  return max(0, min($want, TERR_REINFORCE_MAX, $room));
}
function terr_reinforce_cost(int $pts): int { return $pts * TERR_REINFORCE_COST; }
