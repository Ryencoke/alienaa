<?php
/* combat_engine.php — pure turn-based combat logic for the Combat Sim
   (pages/sim.php), shaped so pvp.php can adopt it later. No DB, no session,
   no output — fully exercisable by a headless CLI harness.

   THE GAME: each round the enemy TELEGRAPHS its next move (mostly honestly —
   clarity scales with the Combat skill and drops on higher tiers), and the
   player answers with one of four actions. It's rock-paper-scissors with
   information plus a stamina economy:

     player STRIKE (15 stam) — solid hit; INTERRUPTS a charging enemy;
                               blunted by guard (and lightly countered)
     player GUARD  (+25 stam) — blocks; reading a HEAVY with guard STAGGERS
                               the enemy for a free round; regenerates stamina
     player FEINT  (10 stam) — beats guard: OPENS the enemy (next strike/
                               burst ×1.5); dances into heavies and quicks
     player BURST  (35 stam) — huge hit; devastating on staggered/charging
                               enemies; badly punished by guard

   enemy actions: HEAVY ×1.6 · QUICK ×0.7 · GUARD (counter-jabs) ·
   CHARGE (winds up: next round UNLEASH ×2.5 unless interrupted) ·
   STAGGER (forced, does nothing — the reward for guarding a heavy).

   Player damage lands first: a killed enemy never swings back (the same
   rule BATCH-69 fixed in the old auto-resolver). Round cap = draw. */

const CB_ROUND_CAP  = 25;
const CB_STAM_MAX   = 100;
const CB_STAM_COST  = ['strike' => 15, 'guard' => 0, 'feint' => 10, 'burst' => 35, 'stim' => 0];
const CB_GUARD_REGEN = 25;
const CB_STIM_HEAL  = 25;  // fight-HP restored per Patch Kit
const CB_STIM_MAX   = 2;   // uses per fight
// Fight HP scale: the Sim runs on construct HP = integrity × CB_HP_SCALE, and
// real integrity damage on exit is ceil(taken / CB_HP_SCALE). integrity_max
// never grows in this game (schema default 15, nothing raises it), so without
// this headroom every fight was binary — DEF >= enemy ATK meant chip-proof,
// anything else meant dead in two rounds.
const CB_HP_SCALE   = 5;

// Enemy personalities — weighted action tables. Assigned per enemy code
// (deterministic hash) so each drone keeps a learnable temperament.
const CB_PERSONAS = [
  'brawler'    => ['heavy' => 40, 'quick' => 40, 'guard' => 15, 'charge' => 5],
  'sentinel'   => ['heavy' => 25, 'quick' => 25, 'guard' => 40, 'charge' => 10],
  'stalker'    => ['heavy' => 20, 'quick' => 50, 'guard' => 20, 'charge' => 10],
  'juggernaut' => ['heavy' => 35, 'quick' => 15, 'guard' => 20, 'charge' => 30],
];

function cb_persona(string $enemyCode): string {
  $keys = array_keys(CB_PERSONAS);
  return $keys[crc32($enemyCode) % count($keys)];
}

// Telegraph honesty %: higher enemy tiers bluff more, Combat skill reads
// through it. Forced actions (unleash/stagger) are always honest.
function cb_tele_pct(int $tier, int $combatLv): int {
  return max(55, min(95, 92 - $tier * 4 + $combatLv * 2));
}

function cb_roll_intent(array $st): array {
  if ($st['estag'] > 0) return ['true' => 'stagger', 'show' => 'stagger'];
  if ($st['charge'] > 0) return ['true' => 'unleash', 'show' => 'unleash'];
  $tbl = CB_PERSONAS[$st['persona']] ?? CB_PERSONAS['brawler'];
  // A charged unleash already spent this fight's charge? charge stays available.
  $sum = array_sum($tbl); $r = mt_rand(1, $sum); $c = 0; $act = 'heavy';
  foreach ($tbl as $k => $wgt) { $c += $wgt; if ($r <= $c) { $act = $k; break; } }
  $show = $act;
  if (mt_rand(1, 100) > $st['telepct']) {
    $others = array_values(array_diff(array_keys($tbl), [$act]));
    $show = $others[mt_rand(0, count($others) - 1)];
  }
  return ['true' => $act, 'show' => $show];
}

// Start a fight. $pstats: ['hp','atk','def','combat_lv','kits'];
// $enemy: ['code','name','tier','hp','attack','defense'] plus optional
// overrides 'persona' (one of CB_PERSONAS — pvp derives it from the
// defender's build instead of a code hash) and 'telepct' (pvp derives
// read clarity from the SPEED gap instead of tier/Combat skill).
// 'hp' is FIGHT hp — the page passes its own scaled pool.
function cb_start(array $pstats, array $enemy): array {
  $st = [
    'v' => 1,
    'php' => (int)$pstats['hp'], 'pstart' => (int)$pstats['hp'],
    'kits' => max(0, (int)($pstats['kits'] ?? 0)), 'stims_used' => 0,
    'patk' => (int)$pstats['atk'], 'pdef' => (int)$pstats['def'],
    'stam' => CB_STAM_MAX,
    'ehp' => (int)$enemy['hp'], 'emax' => (int)$enemy['hp'],
    'eatk' => (int)$enemy['attack'], 'edef' => (int)$enemy['defense'],
    'ecode' => (string)$enemy['code'], 'ename' => (string)$enemy['name'],
    'tier' => (int)$enemy['tier'],
    'persona' => (isset($enemy['persona']) && isset(CB_PERSONAS[$enemy['persona']]))
                   ? $enemy['persona'] : cb_persona((string)$enemy['code']),
    'telepct' => isset($enemy['telepct'])
                   ? max(55, min(95, (int)$enemy['telepct']))
                   : cb_tele_pct((int)$enemy['tier'], (int)$pstats['combat_lv']),
    'charge' => 0, 'estag' => 0, 'popen' => 0,
    'round' => 1, 'dealt' => 0, 'taken' => 0,
    'over' => false, 'outcome' => null,
  ];
  $st['intent'] = cb_roll_intent($st);
  return $st;
}

// Soft mitigation: defense can blunt a hit to 25% of the attack but never
// below it — stacking DEF past ATK no longer trivializes a fight (the old
// max(1, atk-def) made every matchup binary).
function cb_dmg(int $atk, int $def, float $mult): int {
  $base = max($atk * 0.25, $atk - $def);
  return max(1, (int)round($base * $mult * mt_rand(85, 115) / 100));
}

function cb_err(array $st, string $m): array {
  return ['ok' => false, 'err' => $m, 'st' => $st, 'events' => []];
}

/* One round. $act: strike | guard | feint | burst | stim | flee.
   Returns ['ok','err','st','events'] — events is an ordered list of
   ['t'=>type, ...] the client animates: pdmg edmg stim stagger interrupt
   open openhit charge unleash counter regen fled draw win loss.
   NOTE for the caller: a successful 'stim' round consumed one Patch Kit —
   the page must atomically deduct the kit BEFORE persisting the new state,
   and discard the round if the deduction loses. */
function cb_round(array $st, string $act): array {
  if ($st['over']) return cb_err($st, 'The fight is already over.');
  $valid = ['strike', 'guard', 'feint', 'burst', 'stim', 'flee'];
  if (!in_array($act, $valid, true)) return cb_err($st, 'Unknown action.');
  if ($act === 'stim') {
    if ($st['kits'] <= 0) return cb_err($st, 'No Patch Kits left.');
    if ($st['stims_used'] >= CB_STIM_MAX) return cb_err($st, 'Stim limit reached this fight.');
    if ($st['php'] >= $st['pstart']) return cb_err($st, 'Already at full construct integrity.');
  }
  $ev = [];

  if ($act === 'flee') {
    // Parting shot unless the enemy is staggered or busy winding up.
    $ea = $st['intent']['true'];
    if (!in_array($ea, ['stagger', 'charge'], true)) {
      $d = cb_dmg($st['eatk'], $st['pdef'], 0.7);
      $st['php'] -= $d; $st['taken'] += $d;
      $ev[] = ['t' => 'edmg', 'v' => $d, 'note' => 'parting shot'];
    }
    $st['php'] = max(0, $st['php']);
    $st['over'] = true;
    $st['outcome'] = $st['php'] <= 0 ? 'loss' : 'fled';
    $ev[] = ['t' => $st['outcome'] === 'loss' ? 'loss' : 'fled'];
    return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
  }

  $cost = CB_STAM_COST[$act];
  if ($cost > $st['stam']) return cb_err($st, 'Not enough stamina — Guard to recover.');
  $st['stam'] -= $cost;

  $ea = $st['intent']['true'];

  // ── Player damage multiplier ──
  $pm = 0.0; $openBonus = ($st['popen'] > 0 && in_array($act, ['strike', 'burst'], true)) ? 1.5 : 1.0;
  if ($act === 'strike') {
    $pm = ['heavy' => 1.0, 'quick' => 1.0, 'guard' => 0.35, 'charge' => 1.25, 'unleash' => 1.0, 'stagger' => 1.3][$ea];
  } elseif ($act === 'burst') {
    $pm = ['heavy' => 1.8, 'quick' => 1.4, 'guard' => 0.25, 'charge' => 2.0, 'unleash' => 1.8, 'stagger' => 2.0][$ea];
  } elseif ($act === 'feint') {
    $pm = ['heavy' => 0.35, 'quick' => 0.0, 'guard' => 0.35, 'charge' => 0.35, 'unleash' => 0.35, 'stagger' => 0.35][$ea];
  } // guard and stim deal nothing

  // ── Enemy damage multiplier (what lands on the player) ──
  // Stim leaves you as exposed as a feint — inject on a safe round.
  $em = 0.0;
  if ($ea === 'heavy')   $em = ['strike' => 1.6, 'burst' => 1.6, 'guard' => 0.25 * 1.6, 'feint' => 1.6, 'stim' => 1.6][$act];
  if ($ea === 'quick')   $em = ['strike' => 0.7, 'burst' => 0.7, 'guard' => 0.35 * 0.7, 'feint' => 1.0, 'stim' => 1.0][$act];
  if ($ea === 'guard')   $em = ['strike' => 0.4, 'burst' => 0.8, 'guard' => 0.0, 'feint' => 0.0, 'stim' => 0.0][$act];
  if ($ea === 'unleash') $em = ['strike' => 2.5, 'burst' => 2.5, 'guard' => 1.25, 'feint' => 2.5, 'stim' => 2.5][$act];
  // charge / stagger: no enemy damage this round

  if ($act === 'stim') {
    $heal = min(CB_STIM_HEAL, $st['pstart'] - $st['php']);
    $st['php'] += $heal;
    $st['kits']--; $st['stims_used']++;
    $ev[] = ['t' => 'stim', 'v' => $heal];
  }

  // ── Resolve: player hits first; a dead enemy never swings back ──
  if ($pm > 0) {
    $d = cb_dmg($st['patk'], $st['edef'], $pm * $openBonus);
    if ($openBonus > 1.0) { $ev[] = ['t' => 'openhit']; }
    $st['ehp'] -= $d; $st['dealt'] += $d;
    $ev[] = ['t' => 'pdmg', 'v' => $d, 'act' => $act];
  }
  if (in_array($act, ['strike', 'burst'], true)) $st['popen'] = 0; // opening consumed (hit or not)

  if ($st['ehp'] <= 0) {
    $st['ehp'] = 0; $st['over'] = true; $st['outcome'] = 'win';
    $ev[] = ['t' => 'win'];
    return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
  }

  // Interactions that change enemy state before its swing:
  if ($ea === 'charge') {
    if (in_array($act, ['strike', 'burst'], true)) {
      $st['charge'] = 0;
      $ev[] = ['t' => 'interrupt'];        // wind-up broken by the hit above
    } else {
      $st['charge'] = 1;
      $ev[] = ['t' => 'charge'];           // it finished winding up — brace
    }
  }
  if ($ea === 'unleash') { $st['charge'] = 0; $ev[] = ['t' => 'unleash']; }
  if ($ea === 'heavy' && $act === 'guard') { $st['estag'] = 1; $ev[] = ['t' => 'stagger']; }
  if ($ea === 'guard' && $act === 'feint') { $st['popen'] = 1; $ev[] = ['t' => 'open']; }
  if ($ea === 'stagger') { $st['estag'] = max(0, $st['estag'] - 1); }

  if ($em > 0) {
    $d = cb_dmg($st['eatk'], $st['pdef'], $em);
    $st['php'] -= $d; $st['taken'] += $d;
    $ev[] = ['t' => 'edmg', 'v' => $d, 'act' => $ea,
             'blocked' => ($act === 'guard')];
    if ($ea === 'guard') $ev[] = ['t' => 'counter'];
  }
  if ($act === 'guard') {
    $st['stam'] = min(CB_STAM_MAX, $st['stam'] + CB_GUARD_REGEN);
    $ev[] = ['t' => 'regen', 'v' => CB_GUARD_REGEN];
  }

  if ($st['php'] <= 0) {
    $st['php'] = 0; $st['over'] = true; $st['outcome'] = 'loss';
    $ev[] = ['t' => 'loss'];
    return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
  }

  $st['round']++;
  if ($st['round'] > CB_ROUND_CAP) {
    $st['over'] = true; $st['outcome'] = 'draw';
    $ev[] = ['t' => 'draw'];
    return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
  }

  $st['intent'] = cb_roll_intent($st);
  return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
}

// Client payload — the TRUE intent stays server-side; only the telegraph
// (which may be a bluff) is exposed.
function cb_to_client(array $st): array {
  return [
    'php' => $st['php'], 'pstart' => $st['pstart'],
    'stam' => $st['stam'], 'stam_max' => CB_STAM_MAX,
    'ehp' => $st['ehp'], 'emax' => $st['emax'],
    'ename' => $st['ename'], 'tier' => $st['tier'], 'persona' => $st['persona'],
    'tele' => $st['intent']['show'], 'telepct' => $st['telepct'],
    'charge' => $st['charge'], 'estag' => $st['estag'], 'popen' => $st['popen'],
    'round' => $st['round'], 'cap' => CB_ROUND_CAP,
    'dealt' => $st['dealt'], 'taken' => $st['taken'],
    'kits' => $st['kits'], 'stims_used' => $st['stims_used'], 'stim_max' => CB_STIM_MAX,
    'over' => $st['over'], 'outcome' => $st['outcome'],
    'costs' => CB_STAM_COST,
  ];
}
