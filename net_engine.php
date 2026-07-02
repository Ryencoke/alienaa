<?php
/* net_engine.php — pure game logic for The Net (pages/thenet.php), the
   Netrunning intrusion dive. No DB, no session, no output — the whole rule
   set is exercised by a headless CLI harness; the page wires it to Drive
   charges, Signal damage, and payouts.

   THE GAME: a corporate grid rendered as a layered node graph — entry port
   on the left, the CORE on the far right, caches / crypto vaults / relay
   nodes between, with ICE sentinels ping-ponging along fixed walks. Every
   action advances the TRACE (the dive's clock): move +3, crack +4..+7 per
   turn, cloak +2, hold +1, an ICE clash +25. At 100% you're DUMPED — all
   unbanked loot gone and the trace burns your Signal. Loot only banks when
   you jack out at the entry or a relay PORT; a panic EJECT anywhere keeps
   40% for a smaller Signal hit. Push deeper for the core, or cash out early
   — the trace never stops climbing.

   Skill hooks: Cryptocracking (hack) gates vault tiers and fattens vault
   yields; Netrunning grants cloak charges (ICE passes through you). */

const ND_COST = ['move' => 3, 'cloak' => 2, 'hold' => 1];
const ND_CRACK_COST  = ['cache' => 4, 'vault' => 6, 'core' => 7];
const ND_CRACK_NEED  = ['cache' => 1, 'vault' => 2, 'core' => 3];
const ND_CLASH_TRACE = 25;
const ND_CLASH_LOOT  = 0.25; // unbanked loot lost per clash
const ND_EJECT_KEEP  = 0.40;
const ND_SIGNAL_DUMP  = 8;
const ND_SIGNAL_EJECT = 5;
const ND_XP = ['cache' => 2, 'vault' => 4, 'core' => 8];

/* Dive depth tiers — gated by Netrunning level (skill_defs promises "unlock
   higher-tier net intrusions"). Deeper grids are bigger (more middle layers +
   denser rows → longer routes), carry more ICE and hotter loot, cost more
   Drive to enter, and expose higher-tier crypto vaults (vault tier = layer,
   so Blacksite vaults need Cryptocracking 4-5). Trace ceiling rises with
   depth so the longer route stays completable; the extra ICE keeps it tense.
   'vault' is a per-tier [lo,hi] value table indexed by vault tier (= layer). */
const NET_TIERS = [
  1 => ['name'=>'Perimeter Grid',   'req'=>0, 'mid'=>3, 'width'=>[3,4], 'ice'=>[2,3], 'drive'=>8,  'trace'=>100,
        'cache'=>[30,60],  'core'=>[300,500],   'want_v'=>[2,3], 'want_c'=>[3,4], 'ports'=>2,
        'vault'=>[1=>[80,130],  2=>[140,220], 3=>[220,350]]],
  2 => ['name'=>'Corporate Subnet', 'req'=>2, 'mid'=>4, 'width'=>[3,5], 'ice'=>[3,4], 'drive'=>12, 'trace'=>125,
        'cache'=>[45,85],  'core'=>[540,820],   'want_v'=>[3,4], 'want_c'=>[4,5], 'ports'=>2,
        'vault'=>[1=>[110,170], 2=>[180,280], 3=>[280,430], 4=>[380,560]]],
  3 => ['name'=>'Blacksite Core',   'req'=>5, 'mid'=>5, 'width'=>[4,5], 'ice'=>[4,5], 'drive'=>16, 'trace'=>155,
        'cache'=>[60,110], 'core'=>[900,1400],  'want_v'=>[4,5], 'want_c'=>[5,6], 'ports'=>3,
        'vault'=>[1=>[150,220], 2=>[240,360], 3=>[350,520], 4=>[480,700], 5=>[620,880]]],
];
function net_tier_cfg(int $tier): array { return NET_TIERS[$tier] ?? NET_TIERS[1]; }
function net_max_tier(int $netrunLv): int {
  $mx = 1; foreach (NET_TIERS as $t => $c) if ($netrunLv >= $c['req']) $mx = max($mx, $t);
  return $mx;
}

// Corp grids you can raid — flavor names for the dive header.
const ND_CORPS = ['Nexus Corp', 'ArmaTech Industries', 'DataVault Ltd', 'CreditFlow Bank', 'Infect-X Security', 'NeonGrid Energy'];

function nd_cloak_charges(int $netrunLv): int { return min(4, 1 + intdiv($netrunLv, 2)); }

// ── Generation ───────────────────────────────────────────────────────────────

// Returns [] on failure (caller refunds); otherwise a full dive state.
// $tier is clamped to what Netrunning unlocks so a forged request can't dive deep.
function nd_gen(int $hackLv = 0, int $netrunLv = 0, int $tier = 1): array {
  $tier = max(1, min($tier, net_max_tier($netrunLv)));
  $cfg = net_tier_cfg($tier);
  $mid = $cfg['mid']; $lastLayer = $mid + 1;
  for ($try = 0; $try < 50; $try++) {
    $counts = [1];
    for ($L = 1; $L <= $mid; $L++) $counts[] = mt_rand($cfg['width'][0], $cfg['width'][1]);
    $counts[] = 1;
    $nodes = []; $byLayer = [];
    $id = 0;
    foreach ($counts as $L => $n) {
      $byLayer[$L] = [];
      for ($r = 0; $r < $n; $r++) {
        $nodes[$id] = ['id' => $id, 'layer' => $L, 'y' => $r - ($n - 1) / 2,
                       'type' => 'relay', 'tier' => 0, 'val' => 0, 'port' => false,
                       'cracked' => false, 'prog' => 0];
        $byLayer[$L][] = $id;
        $id++;
      }
    }
    // Edges: every node in layer L+1 hooks back to 1-2 nodes in L; every node
    // in L gets at least one forward edge.
    $adj = array_fill(0, $id, []);
    $link = function ($a, $b) use (&$adj) {
      if (!in_array($b, $adj[$a], true)) { $adj[$a][] = $b; $adj[$b][] = $a; }
    };
    for ($L = 0; $L < $lastLayer; $L++) {
      foreach ($byLayer[$L + 1] as $b) {
        $back = $byLayer[$L];
        shuffle($back);
        foreach (array_slice($back, 0, mt_rand(1, 2)) as $a) $link($a, $b);
      }
      foreach ($byLayer[$L] as $a) {
        $fwd = false;
        foreach ($adj[$a] as $nb) if ($nodes[$nb]['layer'] === $L + 1) { $fwd = true; break; }
        if (!$fwd) $link($a, $byLayer[$L + 1][mt_rand(0, count($byLayer[$L + 1]) - 1)]);
      }
    }
    // A couple of same-layer shortcuts for route variety.
    for ($L = 1; $L <= $mid; $L++) {
      if (count($byLayer[$L]) >= 2 && mt_rand(0, 99) < 60) {
        $pair = $byLayer[$L]; shuffle($pair);
        $link($pair[0], $pair[1]);
      }
    }
    $entry = $byLayer[0][0]; $core = $byLayer[$lastLayer][0];
    // Connectivity check.
    $seen = [$entry => true]; $q = [$entry];
    while ($q) { $x = array_shift($q); foreach ($adj[$x] as $nb) if (!isset($seen[$nb])) { $seen[$nb] = true; $q[] = $nb; } }
    if (count($seen) !== $id) continue;

    // Types. Middle nodes: ports near the front, vaults (tier = layer, deeper =
    // hotter and needing more Cryptocracking), and caches fill the rest.
    $nodes[$entry]['type'] = 'entry'; $nodes[$entry]['port'] = true;
    $nodes[$core]['type'] = 'core';
    $nodes[$core]['val'] = mt_rand($cfg['core'][0], $cfg['core'][1]);
    $midNodes = [];
    for ($L = 1; $L <= $mid; $L++) $midNodes = array_merge($midNodes, $byLayer[$L]);
    shuffle($midNodes);
    $ports = 0; $vaults = 0; $caches = 0;
    $wantPorts = $cfg['ports']; $wantVaults = mt_rand($cfg['want_v'][0], $cfg['want_v'][1]); $wantCaches = mt_rand($cfg['want_c'][0], $cfg['want_c'][1]);
    foreach ($midNodes as $nid) {
      $L = $nodes[$nid]['layer'];
      if ($ports < $wantPorts && $nodes[$nid]['type'] === 'relay' && $L <= max(2, $mid - 2)) { $nodes[$nid]['port'] = true; $ports++; continue; }
      if ($vaults < $wantVaults && $nodes[$nid]['type'] === 'relay' && isset($cfg['vault'][$L])) {
        [$lo, $hi] = $cfg['vault'][$L];
        $nodes[$nid]['type'] = 'vault'; $nodes[$nid]['tier'] = $L;
        $nodes[$nid]['val'] = (int)round(mt_rand($lo, $hi) * (1 + 0.04 * min(10, $hackLv)));
        $vaults++; continue;
      }
      if ($caches < $wantCaches && $nodes[$nid]['type'] === 'relay') {
        $nodes[$nid]['type'] = 'cache'; $nodes[$nid]['val'] = mt_rand($cfg['cache'][0], $cfg['cache'][1]);
        $caches++;
      }
    }
    if ($ports < 1 || $vaults < 2 || $caches < 3) continue;

    // ICE sentinels on simple random walks (length 3-5) over middle nodes —
    // never the entry, never the core (crack tension comes from adjacency, not
    // an oscillating camper on a 3-turn crack).
    $iceCount = mt_rand($cfg['ice'][0], $cfg['ice'][1]);
    $ice = [];
    $walkTries = 0;
    while (count($ice) < $iceCount && $walkTries < 80) {
      $walkTries++;
      $start = $midNodes[mt_rand(0, count($midNodes) - 1)];
      $path = [$start]; $cur = $start;
      $len = mt_rand(3, 5);
      while (count($path) < $len) {
        $opts = array_values(array_filter($adj[$cur], function ($nb) use ($nodes, $path, $entry, $core) {
          return $nb !== $entry && $nb !== $core && !in_array($nb, $path, true);
        }));
        if (!$opts) break;
        $cur = $opts[mt_rand(0, count($opts) - 1)];
        $path[] = $cur;
      }
      if (count($path) < 3) continue;
      // Start at the path index farthest (graph-wise crude: layer distance) from entry.
      $best = 0; $bd = -1;
      foreach ($path as $ii => $nid) {
        $d = $nodes[$nid]['layer'] + abs($nodes[$nid]['y']);
        if ($d > $bd) { $bd = $d; $best = $ii; }
      }
      $ice[] = ['path' => $path, 'i' => $best, 'dir' => (mt_rand(0, 1) ? 1 : -1)];
    }
    if (count($ice) < 2) continue;

    return [
      'v' => 1,
      'nodes' => $nodes, 'adj' => $adj,
      'entry' => $entry, 'core' => $core,
      'pos' => $entry, 'trace' => 0, 'trace_max' => $cfg['trace'], 'loot' => 0,
      'cracked' => ['cache' => 0, 'vault' => 0, 'core' => 0],
      'ice' => $ice,
      'cloaks' => nd_cloak_charges($netrunLv), 'cloaked' => false,
      'hack' => $hackLv, 'netrun' => $netrunLv,
      'tier' => $tier, 'tier_name' => $cfg['name'], 'start_drive' => $cfg['drive'],
      'corp' => ND_CORPS[mt_rand(0, count(ND_CORPS) - 1)],
      'moves' => 0, 'over' => false, 'outcome' => null,
    ];
  }
  return [];
}

// ── Turn resolution ──────────────────────────────────────────────────────────

function nd_ice_cells(array $st): array {
  $o = [];
  foreach ($st['ice'] as $i => $ic) $o[$ic['path'][$ic['i']]] = $i;
  return $o;
}
function nd_ice_advance(array &$ic): void {
  $len = count($ic['path']);
  if ($len < 2) return;
  $n = $ic['i'] + $ic['dir'];
  if ($n < 0 || $n >= $len) { $ic['dir'] = -$ic['dir']; $n = $ic['i'] + $ic['dir']; }
  $ic['i'] = $n;
}
function nd_err(array $st, string $m): array {
  return ['ok' => false, 'err' => $m, 'st' => $st, 'events' => []];
}
function nd_end(array &$st, string $outcome): void {
  $st['over'] = true; $st['outcome'] = $outcome;
}

/* One action. $act: move (arg = node id) | crack | cloak | hold | jackout | eject.
   Returns ['ok','err','st','events']. Event types: moved, crackprog,
   cracked (node type/val), clash (trace+loot lost), cloak, banked, ejected,
   dumped, trace. The dive ALWAYS terminates: every action adds >=1 trace and
   trace never decreases, so <=100 actions reach the dump. */
function nd_step(array $st, string $act, int $arg = -1): array {
  if ($st['over']) return nd_err($st, 'The dive is already over.');
  $ev = [];
  $st['cloaked'] = false;
  $iceNow = nd_ice_cells($st);

  if ($act === 'move') {
    if ($arg < 0 || !isset($st['nodes'][$arg])) return nd_err($st, 'No such node.');
    if (!in_array($arg, $st['adj'][$st['pos']], true)) return nd_err($st, 'Not linked to your node.');
    $st['trace'] += ND_COST['move'];
    if (isset($iceNow[$arg])) {
      // Walking into ICE — clash on the spot, but the move completes.
      $st['pos'] = $arg;
      nd_clash($st, $ev);
    } else {
      $st['pos'] = $arg;
    }
    $ev[] = ['t' => 'moved', 'to' => $arg];

  } elseif ($act === 'crack') {
    $n = $st['nodes'][$st['pos']];
    if (!in_array($n['type'], ['cache', 'vault', 'core'], true)) return nd_err($st, 'Nothing crackable here.');
    if ($n['cracked']) return nd_err($st, 'Already cracked.');
    if ($n['type'] === 'vault' && $st['hack'] < $n['tier'])
      return nd_err($st, "Encryption tier {$n['tier']} — needs Cryptocracking level {$n['tier']}.");
    $st['trace'] += ND_CRACK_COST[$n['type']];
    $st['nodes'][$st['pos']]['prog']++;
    if ($st['nodes'][$st['pos']]['prog'] >= ND_CRACK_NEED[$n['type']]) {
      $st['nodes'][$st['pos']]['cracked'] = true;
      $st['loot'] += $n['val'];
      $st['cracked'][$n['type']]++;
      $ev[] = ['t' => 'cracked', 'node' => $st['pos'], 'type' => $n['type'], 'v' => $n['val']];
    } else {
      $ev[] = ['t' => 'crackprog', 'node' => $st['pos'],
               'prog' => $st['nodes'][$st['pos']]['prog'], 'need' => ND_CRACK_NEED[$n['type']]];
    }

  } elseif ($act === 'cloak') {
    if ($st['cloaks'] <= 0) return nd_err($st, 'No cloak charges left.');
    $st['cloaks']--;
    $st['cloaked'] = true;
    $st['trace'] += ND_COST['cloak'];
    $ev[] = ['t' => 'cloak', 'left' => $st['cloaks']];

  } elseif ($act === 'hold') {
    $st['trace'] += ND_COST['hold'];

  } elseif ($act === 'jackout') {
    if (!$st['nodes'][$st['pos']]['port']) return nd_err($st, 'No exit port on this node.');
    nd_end($st, 'banked');
    $ev[] = ['t' => 'banked', 'v' => $st['loot']];
    return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];

  } elseif ($act === 'eject') {
    $st['loot'] = (int)floor($st['loot'] * ND_EJECT_KEEP);
    nd_end($st, 'ejected');
    $ev[] = ['t' => 'ejected', 'v' => $st['loot']];
    return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];

  } else {
    return nd_err($st, 'Unknown action.');
  }

  $st['moves']++;

  // ICE phase.
  foreach ($st['ice'] as $i => $_) {
    nd_ice_advance($st['ice'][$i]);
    if (!$st['cloaked'] && $st['ice'][$i]['path'][$st['ice'][$i]['i']] === $st['pos']) {
      nd_clash($st, $ev);
    }
  }

  // Trace check.
  $traceMax = (int)($st['trace_max'] ?? 100);
  if ($st['trace'] >= $traceMax) {
    $st['trace'] = $traceMax;
    $st['loot'] = 0;
    nd_end($st, 'dumped');
    $ev[] = ['t' => 'dumped'];
  }

  return ['ok' => true, 'err' => '', 'st' => $st, 'events' => $ev];
}

function nd_clash(array &$st, array &$ev): void {
  $lost = (int)ceil($st['loot'] * ND_CLASH_LOOT);
  $st['loot'] -= $lost;
  $st['trace'] += ND_CLASH_TRACE;
  $ev[] = ['t' => 'clash', 'lost' => $lost, 'trace' => ND_CLASH_TRACE];
}

// XP for a settled dive (banked or ejected — dumped earns nothing).
function nd_xp(array $st): int {
  return ND_XP['cache'] * $st['cracked']['cache']
       + ND_XP['vault'] * $st['cracked']['vault']
       + ND_XP['core']  * $st['cracked']['core'];
}

// Shard chance (percent) when the core was cracked and the dive banked.
function nd_shard_pct(array $st): int {
  return min(55, 35 + 2 * min(10, (int)$st['hack']));
}

// Client payload — the whole grid is visible by design (deterministic
// planning); loot math stays server-side.
function nd_to_client(array $st): array {
  $nodes = [];
  foreach ($st['nodes'] as $n) {
    $nodes[] = ['id' => $n['id'], 'layer' => $n['layer'], 'y' => $n['y'],
                'type' => $n['type'], 'tier' => $n['tier'],
                'val' => $n['cracked'] ? 0 : $n['val'],
                'port' => $n['port'], 'cracked' => $n['cracked'],
                'prog' => $n['prog'], 'need' => ND_CRACK_NEED[$n['type']] ?? 0];
  }
  $ice = [];
  foreach ($st['ice'] as $ic) {
    $cur = $ic['path'][$ic['i']];
    $tmp = $ic; nd_ice_advance($tmp);
    $ice[] = ['at' => $cur, 'next' => $tmp['path'][$tmp['i']], 'path' => $ic['path']];
  }
  return [
    'nodes' => $nodes, 'adj' => $st['adj'], 'pos' => $st['pos'],
    'entry' => $st['entry'], 'core' => $st['core'],
    'trace' => $st['trace'], 'trace_max' => (int)($st['trace_max'] ?? 100),
    'loot' => $st['loot'], 'cracked' => $st['cracked'],
    'ice' => $ice, 'cloaks' => $st['cloaks'],
    'hack' => $st['hack'], 'corp' => $st['corp'],
    'tier' => (int)($st['tier'] ?? 1), 'tier_name' => $st['tier_name'] ?? 'Perimeter Grid',
    'moves' => $st['moves'], 'over' => $st['over'], 'outcome' => $st['outcome'],
  ];
}
