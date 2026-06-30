<?php
/* lib.php — shared view helpers (committed & deployed; config.php is gitignored). */

// Sessions created before BATCH-28 stored pid as a string; pages compare it
// with strict === against (int) ids. Normalise once so every comparison works.
if (isset($_SESSION['pid'])) $_SESSION['pid'] = (int)$_SESSION['pid'];

// ---- chat / roles ----

// Staff roles get fixed colors; members use their own picked color.
function chat_color($role, $member_color) {
  switch ($role) {
    case 'manager':   return '#e23b3b'; // red
    case 'admin':     return '#e8d44d'; // yellow
    case 'moderator': return '#4d9be8'; // blue
    case 'chatmod':   return '#3bcf63'; // green
  }
  return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$member_color) ? $member_color : '#c9d1e0';
}

function role_label($role) {
  $map = ['manager'=>'Manager','admin'=>'Admin','moderator'=>'Mod','chatmod'=>'Chat Mod'];
  return $map[$role] ?? '';
}

function role_tag($role, $extraStyle = '') {
  $label = role_label($role);
  if (!$label) return '';
  $color = chat_color($role, '');
  return '<em style="font-style:italic;color:' . $color . ';' . $extraStyle . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</em>';
}

// True if the player row has an active subscription (sub_until today or later).
function is_subscribed($row) {
  return !empty($row['sub_until']) && $row['sub_until'] >= date('Y-m-d');
}

// True if the player row has an active Commerce Accord enlistment (merchant_until
// today or later) — combat pages lock out fighting while this is true.
function is_merchant($row) {
  return !empty($row['merchant_until']) && $row['merchant_until'] >= date('Y-m-d');
}

// Generic per-player settings k/v read/write (settings table). Centralises the
// try/SELECT/catch-default pattern that was repeated ad hoc across pages.
function setting_get(PDO $pdo, string $key, string $default = ''): string {
  try {
    $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $q->execute([$key]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (string)$v;
  } catch (Throwable $e) { return $default; }
}
function setting_set(PDO $pdo, string $key, string $value): void {
  $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$key, $value]);
}

// player_gear table used by both the Forge (blacksmith.php) and the Fabrication
// Lab (weaponcraft.php) — was independently re-declared in each file and had
// already begun to drift; centralised here as the single definition.
function ensure_player_gear_table(PDO $pdo): void {
  try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS player_gear (
      id         INT AUTO_INCREMENT PRIMARY KEY,
      player_id  INT NOT NULL,
      recipe_id  VARCHAR(32) NOT NULL,
      name       VARCHAR(64) NOT NULL,
      gear_type  ENUM(\'weapon\',\'armor\') NOT NULL,
      atk_bonus  INT NOT NULL DEFAULT 0,
      def_bonus  INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_player (player_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  } catch (Throwable $e) {}
}

// Shared numbered pager (boards.php + updates.php). $base is the URL prefix
// (must already end in a query string this can append "&pg=N" to).
function pager($base, $pg, $pages) {
  if ($pages <= 1) return '';
  $o = '<div class="pager">';
  if ($pg > 1) $o .= '<a href="' . $base . '&pg=' . ($pg - 1) . '">&lsaquo;</a>';
  $start = max(1, $pg - 2); $end = min($pages, $pg + 2);
  if ($start > 1) { $o .= '<a href="' . $base . '&pg=1">1</a>'; if ($start > 2) $o .= '<span class="dots">&hellip;</span>'; }
  for ($i = $start; $i <= $end; $i++)
    $o .= $i === $pg ? '<span class="cur">' . $i . '</span>' : '<a href="' . $base . '&pg=' . $i . '">' . $i . '</a>';
  if ($end < $pages) { if ($end < $pages - 1) $o .= '<span class="dots">&hellip;</span>'; $o .= '<a href="' . $base . '&pg=' . $pages . '">' . $pages . '</a>'; }
  if ($pg < $pages) $o .= '<a href="' . $base . '&pg=' . ($pg + 1) . '">&rsaquo;</a>';
  return $o . '</div>';
}

// CSRF mitigation: reject POSTs whose Origin/Referer host doesn't match ours.
// (Same-origin form/fetch posts pass; a cross-site attacker's posted form is blocked.)
//
// Sec-Fetch-Site is sent by all modern browsers and — unlike Origin/Referer —
// cannot be suppressed by the requesting page (no meta tag, no fetch option
// turns it off). When present it's checked first and is authoritative: a
// 'cross-site' value is rejected outright even if Origin/Referer are blank.
// Origin/Referer remain the fallback for older browsers that omit it. Only
// when none of the three headers are present at all do we allow the request,
// to avoid false rejects from privacy tools that strip all of them.
function csrf_guard() {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
  $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
  if ($fetchSite !== '' && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
    http_response_code(403);
    exit('Forbidden: cross-origin request blocked.');
  }
  $src = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
  if ($src === '') return;                       // no header to check — allow (avoid false rejects)
  $h = parse_url($src, PHP_URL_HOST);
  if ($h !== null && strcasecmp($h, $_SERVER['HTTP_HOST'] ?? '') !== 0) {
    http_response_code(403);
    exit('Forbidden: cross-origin request blocked.');
  }
}

// Flag emoji from a 2-letter country code ('' if none/invalid).
function country_flag($code) {
  $code = strtoupper(trim((string)$code));
  if (!preg_match('/^[A-Z]{2}$/', $code)) return '';
  return mb_chr(127397 + ord($code[0]), 'UTF-8') . mb_chr(127397 + ord($code[1]), 'UTF-8');
}

// Gender icon: ♂ blue for male, ♀ pink for female, '' for unset.
function gender_icon($gender) {
  if ($gender === 'M') return '<span title="Male"   style="color:#5fa8e8;font-size:12px;margin:0 2px;vertical-align:middle">&#9794;</span>';
  if ($gender === 'F') return '<span title="Female" style="color:#ff75b5;font-size:12px;margin:0 2px;vertical-align:middle">&#9792;</span>';
  return '';
}

// Mortality alignment icon: ☀ for good, ☠ for evil, '' for neutral.
function mortality_icon($score) {
  $score = (int)$score;
  if ($score > 0) return '<span title="Good alignment +'.$score.'" style="font-size:12px;color:#e8d44d;margin:0 2px;vertical-align:middle">&#9728;</span>';
  if ($score < 0) return '<span title="Evil alignment '.$score.'" style="font-size:12px;color:#ff2d95;margin:0 2px;vertical-align:middle">&#9760;</span>';
  return '';
}

// Flag <img> tag using flagcdn.com (20×15 px). Returns '' if no valid code.
function flag_img($code) {
  $code = strtolower(trim((string)$code));
  if (!preg_match('/^[a-z]{2}$/', $code)) return '';
  $name = strtoupper($code);
  return '<img src="https://flagcdn.com/20x15/'.e($code).'.png" width="20" height="15" alt="'.e($name).'" title="'.e($name).'" style="border-radius:2px;vertical-align:middle;box-shadow:0 1px 3px rgba(0,0,0,.3);margin-right:4px">';
}

function countries() {
  return ['US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom','IE'=>'Ireland','AU'=>'Australia','NZ'=>'New Zealand',
    'DE'=>'Germany','FR'=>'France','ES'=>'Spain','IT'=>'Italy','PT'=>'Portugal','NL'=>'Netherlands','BE'=>'Belgium',
    'SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark','FI'=>'Finland','PL'=>'Poland','CZ'=>'Czechia','AT'=>'Austria',
    'CH'=>'Switzerland','GR'=>'Greece','RU'=>'Russia','UA'=>'Ukraine','TR'=>'Turkey','BR'=>'Brazil','MX'=>'Mexico',
    'AR'=>'Argentina','CL'=>'Chile','CO'=>'Colombia','JP'=>'Japan','KR'=>'South Korea','CN'=>'China','IN'=>'India',
    'ID'=>'Indonesia','PH'=>'Philippines','TH'=>'Thailand','VN'=>'Vietnam','ZA'=>'South Africa','EG'=>'Egypt',
    'NG'=>'Nigeria','SA'=>'Saudi Arabia','AE'=>'UAE','IL'=>'Israel'];
}

// ---- customizable sidebar ----
function nav_links() {
  return [
    'home'     => ['Hideout',        'index.php?p=home'],
    'stash'    => ['Inventory',      'index.php?p=stash'],
    'ledger'   => ['Bank',           'index.php?p=ledger&act=bank'],
    'city'     => ['The Sprawl',     'index.php?p=city'],
    'bazaar'   => ['Bazaar',         'index.php?p=bazaar'],
    'boards'   => ['Message Boards', 'index.php?p=boards'],
    'messages' => ['Messages',       'index.php?p=messages'],
    'chat'     => ['Public Channel', 'index.php?p=chat'],
    'datacore' => ['Datacore',       'index.php?p=datacore&act=lab'],
    'library'  => ['Library',        'index.php?p=library'],
    'account'  => ['Account',        'index.php?p=account'],
    'guilds'    => ['Syndicate',       'index.php?p=guilds'],
    'updates'   => ['Updates',         'index.php?p=updates'],
    'tickets'   => ['Tickets',         'index.php?p=tickets'],
    'cityhall'  => ['City Hall',       'index.php?p=cityhall'],
    'friends'   => ['Friends',         'index.php?p=friends'],
    'daemon'    => ['Lucky Daemon',    'index.php?p=daemon'],
    'stockex'   => ['Stock Exchange',  'index.php?p=stockex'],
    'auction'   => ['Auction House',   'index.php?p=auction'],
    'apartments'=> ['Apartments',      'index.php?p=apartments'],
    'training'  => ['Neural Training', 'index.php?p=training'],
    'pvp'       => ['Combat Arena',    'index.php?p=pvp'],
    'accord'    => ['Commerce Accord', 'index.php?p=accord'],
    'trade'     => ['Secure Trade',    'index.php?p=trade'],
  ];
}
function default_sidebar() { return ['home','stash','ledger','city','bazaar','boards','messages','account','updates']; }
function player_sidebar($player) {
  $valid = nav_links();
  $s = trim((string)($player['sidebar'] ?? ''));
  $keys = $s !== '' ? explode(',', $s) : default_sidebar();
  $out = [];
  foreach ($keys as $k) { $k = trim($k); if (isset($valid[$k]) && !in_array($k, $out, true)) $out[] = $k; }
  return $out ?: default_sidebar();
}

// Render user text safely: escape EVERYTHING first, then convert a tiny BBCode
// whitelist. Because we escape before converting, no raw HTML can ever slip in.
function bbcode($text) {
  $s = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('/\[b\](.*?)\[\/b\]/is', '<b>$1</b>', $s);
  $s = preg_replace('/\[i\](.*?)\[\/i\]/is', '<i>$1</i>', $s);
  $s = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $s);
  $s = preg_replace('/\[s\](.*?)\[\/s\]/is', '<s>$1</s>', $s);
  return $s;
}

// ---- themes ----

// Each theme is a set of CSS-variable values. The Account page lets players pick one.
function themes() {
  return [
    'neon' => ['name'=>'Neon', 'vars'=>[
      '--bg'=>'#0a0a12','--panel'=>'#12121f','--panel2'=>'#181828','--line'=>'#241f3a',
      '--neon'=>'#19f0c7','--neon2'=>'#ff2d95','--text'=>'#c9d1e0','--muted'=>'#5d6680',
      '--bar1'=>'#2b2b3d','--bar2'=>'#13131d','--accent'=>'#19f0c7']],
    'grey' => ['name'=>'Classic Grey', 'vars'=>[
      '--bg'=>'#0d0d0f','--panel'=>'#1a1a1e','--panel2'=>'#232329','--line'=>'#34343c',
      '--neon'=>'#cfd4dc','--neon2'=>'#9aa0ab','--text'=>'#dcdfe5','--muted'=>'#82868f',
      '--bar1'=>'#3a3a42','--bar2'=>'#202024','--accent'=>'#6fb3e0']],
    'amber' => ['name'=>'Amber', 'vars'=>[
      '--bg'=>'#0f0d08','--panel'=>'#1a160e','--panel2'=>'#221c12','--line'=>'#3a2f1a',
      '--neon'=>'#e8a33d','--neon2'=>'#ff7a18','--text'=>'#e8dcc0','--muted'=>'#8a7d5f',
      '--bar1'=>'#3a2f1a','--bar2'=>'#1a160e','--accent'=>'#e8a33d']],
    'mono' => ['name'=>'Mono', 'vars'=>[
      '--bg'=>'#0a0a0a','--panel'=>'#161616','--panel2'=>'#1f1f1f','--line'=>'#2e2e2e',
      '--neon'=>'#ffffff','--neon2'=>'#bdbdbd','--text'=>'#e6e6e6','--muted'=>'#7a7a7a',
      '--bar1'=>'#333333','--bar2'=>'#1a1a1a','--accent'=>'#ffffff']],
    'matrix' => ['name'=>'Matrix', 'vars'=>[
      '--bg'=>'#020a02','--panel'=>'#08140a','--panel2'=>'#0c1c0e','--line'=>'#143a1a',
      '--neon'=>'#39ff6a','--neon2'=>'#1fbf4a','--text'=>'#bfeac0','--muted'=>'#4f7a52',
      '--bar1'=>'#143a1a','--bar2'=>'#08140a','--accent'=>'#39ff6a']],
  ];
}

// Build a <style> block overriding :root vars for the chosen theme + optional accent.
function theme_css($theme, $accent) {
  $themes = themes();
  $t = $themes[$theme] ?? $themes['neon'];
  $vars = $t['vars'];
  if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)$accent)) {
    $vars['--accent'] = $accent;   // personal accent overrides the theme's
  }
  $css = ':root{';
  foreach ($vars as $k => $v) $css .= $k . ':' . $v . ';';
  $css .= '}';
  return '<style>' . $css . '</style>';
}

// ---- shared catalogs / defs (single source of truth) ----
// These were duplicated (and drifting) across blacksmith/generalstore/datacore
// and the Library. Centralised so the Library always documents what shops sell.

// Skill metadata keyed by the real skills.code values seeded in schema.sql.
function skill_defs(): array {
  return [
    'scav'   => ['icon'=>'&#128270;', 'name'=>'Scavenging',     'effect'=>'+yield & unlock higher-tier gather nodes per level',     'color'=>'var(--accent)'],
    'hydro'  => ['icon'=>'&#127807;', 'name'=>'Hydroponics',    'effect'=>'+crop yield & unlock hydrofarm growth vats per level',   'color'=>'#3bcf63'],
    'fab'    => ['icon'=>'&#9881;',   'name'=>'Fabrication',    'effect'=>'+crafting output & unlock advanced recipes per level',   'color'=>'var(--neon2)'],
    'combat' => ['icon'=>'&#9876;',   'name'=>'Combat',         'effect'=>'+damage & crit chance in combat sims per level',         'color'=>'#ff6b35'],
    'drone'  => ['icon'=>'&#129458;', 'name'=>'Drone Ops',      'effect'=>'+mining yield & unlock remote transit nodes per level',  'color'=>'#4d6be8'],
    'netrun' => ['icon'=>'&#128187;', 'name'=>'Netrunning',     'effect'=>'+hack success rate & unlock higher-tier net intrusions', 'color'=>'#a66de8'],
    'chem'   => ['icon'=>'&#9879;',   'name'=>'Streetchem',     'effect'=>'+stim potency & unlock compound synthesis recipes',      'color'=>'#e8d44d'],
    'hack'   => ['icon'=>'&#128272;', 'name'=>'Cryptocracking', 'effect'=>'+crypto yield & unlock encrypted vault targets',         'color'=>'#4de8b8'],
  ];
}

// The Forge catalog. Row: [code, name, icon, type, sub, atk, def, spd, price, desc]
function blacksmith_catalog(): array {
  return [
    // ==================== WEAPONS ====================
    ['mono_blade',     'Mono-Edge Blade',     '&#9876;',  'weapon', 'Melee',   3,  0,  1,   800,  'Monomolecular edge. Whisper-quiet, lethal up close.'],
    ['ceramic_blade',  'Ceramic Blade',       '&#9876;',  'weapon', 'Melee',   2,  0,  3,   600,  'Heat-treated ceramic edge. Light, fast, disposable.'],
    ['corrosive_dart', 'Corrosive Dart Gun',  '&#128300;','weapon', 'Ranged',  4,  0,  2,  1800,  'Industrial acid in every round. Eats through light armor.'],
    ['plasma_pistol',  'Plasma Pistol',       '&#128299;','weapon', 'Ranged',  5,  0,  0,  2200,  'Single-shot plasma rounds. Standard Sprawl enforcer sidearm.'],
    ['shock_baton',    'Shock Baton',         '&#9889;',  'weapon', 'Melee',   4,  2,  0,  3500,  'Electromagnetic stunner. Crowd control with a nasty bite.'],
    ['entropy_knife',  'Entropy Knife',       '&#9876;',  'weapon', 'Stealth', 6,  0,  3,  4000,  'Quantum edge decays chemical bonds on contact. Banned.'],
    ['synapse_spike',  'Synapse Spike',       '&#128296;','weapon', 'Stealth', 7,  0,  1,  4200,  'Jams cortical implants on critical hits. Illegal citywide.'],
    ['neuro_injector', 'Neurotoxin Injector', '&#128300;','weapon', 'Stealth', 6,  0,  3,  5000,  'Fast-acting synthetic toxin. Hits before they feel it.'],
    ['pulse_smg',      'Pulse SMG',           '&#128299;','weapon', 'Ranged',  7,  0,  2,  5500,  'High-ROF electromagnetic burst. Drains shield stacks fast.'],
    ['data_lance',     'Data Lance',          '&#9876;',  'weapon', 'Melee',   8,  1,  1,  6500,  'Carbon-ceramic spike loaded with intrusion code. Smart kill.'],
    ['sonic_driver',   'Sonic Driver',        '&#128299;','weapon', 'Tech',    9,  0, -1,  7000,  'Focused directional sound cannon. Shatters internal organs.'],
    ['arc_rifle',      'Arc Rifle',           '&#127775;','weapon', 'Ranged',  9,  0, -1,  7500,  'High-voltage pulse weapon. Expensive, loud, devastating.'],
    ['ghost_pistol',   'Ghost Pistol',        '&#128299;','weapon', 'Stealth', 8,  1,  2,  9500,  'Subsonic rounds — no muzzle flash, no heat signature.'],
    ['void_blade',     'Void Blade',          '&#9876;',  'weapon', 'Melee',  11,  0,  2,  9000,  'Phase-shifted edge. Slips through most barrier fields.'],
    ['breacher_axe',   'Breacher Axe',        '&#9876;',  'weapon', 'Heavy',  11,  0, -1, 10000,  'Hydraulic spike-axe engineered for armored breach ops.'],
    ['taser_web',      'Taser Web Launcher',  '&#9889;',  'weapon', 'Tech',    6,  0,  0,  3800,  'Filament net at 50,000V. Immobilizes and burns through mesh.'],
    ['nano_swarm',     'Nano Swarm',          '&#128296;','weapon', 'Tech',   10,  0,  0,  8000,  'Micro-bots deployed on impact. Dissolve soft tissue from inside.'],
    ['thermal_lance',  'Thermal Lance',       '&#9876;',  'weapon', 'Heavy',  14,  0, -1, 15000,  'Plasma-focused cutting beam at melee range. Melts hardpoints.'],
    ['scatter_cannon', 'Scatter Cannon',      '&#128299;','weapon', 'Heavy',  13,  0, -2, 12000,  'Wide spread burst. Devastating in tight corridors.'],
    ['smart_carbine',  'Smart Carbine',       '&#128299;','weapon', 'Ranged', 16,  0,  0, 19000,  'Target-lock AI integrated into the stock and barrel assembly.'],
    ['gauss_rifle',    'Gauss Rifle',         '&#128299;','weapon', 'Heavy',  18,  0, -2, 22000,  'Electromagnetic rail accelerator. Long range, brutal velocity.'],
    ['hyper_katana',   'Hyper Katana',        '&#9876;',  'weapon', 'Melee',  20,  0,  3, 28000,  'Vibro-edged titanium blade tuned to 12kHz resonance frequency.'],
    ['void_cannon',    'Void Cannon',         '&#128299;','weapon', 'Heavy',  25,  0, -3, 35000,  'Annihilation-class weapon. Banned in six city-states.'],
    ['hex_blade',      'Hex Blade',           '&#9876;',  'weapon', 'Legendary',22, 2, 1, 45000,  'Cursed edge from an illegal foundry. It whispers back.'],
    ['ghost_katana',   'Ghost Katana',        '&#9876;',  'weapon', 'Legendary',24, 0, 4, 55000,  'Phased monomolecular edge. Passes through all known alloys.'],
    // ==================== ARMOR ====================
    ['ferro_coat',      'Ferro Coat',         '&#129413;','armor', 'Light',    0,  5,  0,  1200,  'Ferromagnetic fiber coat. Deflects shrapnel and debris.'],
    ['combat_vest',     'Combat Vest',        '&#128737;','armor', 'Light',    0,  3,  1,   600,  'Lightweight ballistic weave. Move fast, take a hit.'],
    ['camo_wraps',      'Camo Wraps',         '&#129399;','armor', 'Stealth',  0,  4,  4,  4200,  'Adaptive camouflage fabric. Hard to spot, harder to hit.'],
    ['kinetic_weave',   'Kinetic Weave',      '&#128737;','armor', 'Medium',   0,  8,  1,  3500,  'Dissipates kinetic energy across the entire suit surface.'],
    ['ablative_coat',   'Ablative Coat',      '&#128737;','armor', 'Medium',   0,  8,  0,  3200,  'Sacrificial layers burn away on impact. Disposable protection.'],
    ['riot_shell',      'Riot Shell',         '&#128737;','armor', 'Medium',   0,  7,  0,  2800,  'Hardened polymer shell. Standard perimeter security issue.'],
    ['shadow_suit',     'Shadow Suit',        '&#128737;','armor', 'Stealth',  0,  6,  3,  5500,  'Stealth-optimized flexweave. Blocks thermal imaging.'],
    ['carbon_bodysuit', 'Carbon Bodysuit',    '&#128737;','armor', 'Medium',   1, 10,  1,  7000,  'Full-body carbon-nanotube weave. Light and highly protective.'],
    ['reflex_suit',     'Reflex Suit',        '&#128737;','armor', 'Tech',     2,  8,  2,  8500,  'Myomer-lined suit. Augments reaction speed under fire.'],
    ['exo_frame',       'Exo-Frame',          '&#129302;','armor', 'Heavy',    1, 12, -1,  6000,  'Powered exoskeletal rig. Near-impenetrable, slows footwork.'],
    ['neural_weave',    'Neural Weave',       '&#129504;','armor', 'Tech',     0,  9,  2,  9500,  'Bio-integrated smart armor. Predicts strikes before they land.'],
    ['nano_skin',       'Nano Skin',          '&#128737;','armor', 'Tech',     1,  9,  1, 11000,  'Living mesh that redistributes impact force over the body.'],
    ['phantom_wrap',    'Phantom Wrap',       '&#128737;','armor', 'Stealth',  0, 12,  2, 14000,  'Chameleon fabric shifts pattern to match surroundings.'],
    ['subdermal_mesh',  'Subdermal Mesh',     '&#128737;','armor', 'Stealth',  0, 11,  0, 12000,  'Embedded under-skin weave. Invisible to scanners.'],
    ['ghost_cloak',     'Ghost Cloak',        '&#128737;','armor', 'Stealth',  0, 13,  2, 16000,  'Phase-shifting coat — partial optical camouflage.'],
    ['circuit_shroud',  'Circuit Shroud',     '&#128737;','armor', 'Tech',     2, 10,  0, 13000,  'EMP-hardened longcoat with embedded counter-intrusion mesh.'],
    ['surge_plate',     'Surge Plate',        '&#128737;','armor', 'Heavy',    0, 14, -1, 17000,  'Capacitor-fed active armor — discharges on every solid impact.'],
    ['biomech_skin',    'Biomech Skin',       '&#128737;','armor', 'Legendary',3, 13,  1, 18000,  'Genetically-engineered symbiotic armor organism. It breathes.'],
    ['reactor_jacket',  'Reactor Jacket',     '&#128737;','armor', 'Heavy',    0, 17, -1, 22000,  'Fusion-cell powered shield emitters in the shoulder pads.'],
    ['chrome_shell',    'Chrome Shell',       '&#128737;','armor', 'Heavy',    0, 19, -1, 26000,  'Mirror-polished deflection plating. Bounces directed energy.'],
    ['juggernaut_plate','Juggernaut Plate',   '&#128737;','armor', 'Heavy',    0, 20, -3, 30000,  'Military-grade ceramic-titanium. Near-impenetrable citadel.'],
    ['pulse_shield',    'Pulse Shield Rig',   '&#128737;','armor', 'Heavy',    0, 16, -2, 20000,  'Electrostatic barrier shell. Regenerates between engagements.'],
    ['quantum_vest',    'Quantum Vest',       '&#128737;','armor', 'Legendary',0, 23,  1, 40000,  'Quantum-locked protection field. Probability-based defense grid.'],
    ['aegis_frame',     'Aegis Frame',        '&#128737;','armor', 'Legendary',0, 25,  0, 45000,  'Corporate elite bodyguard issue. Rarely leaves the vault.'],
    ['void_carapace',   'Void Carapace',      '&#128737;','armor', 'Legendary',0, 28, -2, 50000,  'Salvaged from a downed warship. Absorbs almost any impact.'],
  ];
}

// The General Store catalog. Row: [id, name, icon, desc, price, effect, amount]
// effect: 'integrity' | 'signal' | 'cycles' | null (collectible)
function generalstore_catalog(): array {
  return [
    ['patch_kit',      'Field Patch Kit',      '&#129657;', 'Nano-polymer bandages. Seals hull breaches fast.',          120,  'integrity',  20],
    ['signal_boost',   'Signal Booster',       '&#128246;', 'Broadband relay chip. Instant signal surge.',                90,  'signal',     15],
    ['cycle_chip',     'Drive Recharger',      '&#128297;', 'Micro-reactor cartridge. Tops up your Drive.',              110,  'cycles',     15],
    ['stim_pack',      'Combat Stim Pack',     '&#9889;',   'Fast-acting adrenaline compound. Health burst in a pinch.', 200, 'integrity',  40],
    ['overclk_chip',   'Overclock Chip',       '&#128301;', 'Pushes Drive capacity into the red zone. Temporary boost.', 180,  'cycles',     30],
    ['booster_array',  'Signal Array',         '&#128225;', 'Dedicated comms module. Massive signal restoration.',       240,  'signal',     40],
    ['ration_bar',     'Synth Ration Bar',     '&#129365;', 'Tasteless. Calorie-dense. Restores a bit of Health.',        30,  'integrity',   8],
    ['data_spike',     'Data Spike',           '&#128300;', 'Disposable hacking tool. Has non-combat utility.',           80,  null,          0],
    ['smoke_canister', 'Smoke Canister',       '&#128168;', 'Thermal obscurant. Useful for exiting bad situations.',      60,  null,          0],
    ['burner_chip',    'Burner ID Chip',       '&#128083;', 'Pre-loaded false identity. Single use.',                    150,  null,          0],
    ['duct_tape',      'Industrial Duct Tape', '&#129683;', 'Fixes everything. No, really. Everything.',                  25,  null,          0],
    ['energy_drink',   'Reactor Brew',         '&#127866;', 'High-voltage synth caffeine. Cycle recovery bonus.',         45,  'cycles',     10],
  ];
}

// ---- XP / level-ups (shared, concurrency-safe) ----

// Atomic xp increment + single-winner level bumps. The old per-page copies did
// read-modify-write on (level, xp), so two concurrent grants near a threshold
// could both award level-up stat points while losing one grant's xp.
function grant_xp(int $pid, int $amount): int {
  $pdo = db();
  if ($amount <= 0) return 0;
  try {
    $bq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $bq->execute(["apt_xp_bonus:{$pid}"]);
    $b = (int)$bq->fetchColumn();
    if ($b > 0) $amount = (int)ceil($amount * (1 + $b / 100));
  } catch (Throwable $e) {}
  $pdo->prepare('UPDATE players SET xp = xp + ? WHERE id = ?')->execute([$amount, $pid]);
  $gained = 0;
  $r = $pdo->prepare('SELECT level, xp, xp_next FROM players WHERE id = ?');
  for ($i = 0; $i < 60; $i++) {
    $r->execute([$pid]); $p = $r->fetch();
    if (!$p || (int)$p['xp'] < (int)$p['xp_next'] || (int)$p['level'] >= 999) break;
    // WHERE level=? makes each bump single-winner: a concurrent caller's bump
    // fails the gate, loops, and re-reads instead of double-awarding.
    $u = $pdo->prepare('UPDATE players SET level = level + 1, xp = xp - ?, xp_next = ? WHERE id = ? AND level = ? AND xp >= ?');
    $u->execute([(int)$p['xp_next'], (int)round((int)$p['xp_next'] * 1.5), $pid, (int)$p['level'], (int)$p['xp_next']]);
    if ($u->rowCount() === 1) $gained++;
  }
  if ($gained > 0) {
    try {
      $pdo->prepare('INSERT INTO player_stats (pid, unspent) VALUES (?,?) ON DUPLICATE KEY UPDATE unspent = unspent + ?')
          ->execute([$pid, $gained * 5, $gained * 5]);
    } catch (Throwable $e) {}
  }
  return $gained;
}

// ---- syndicate (guild) XP / leveling (shared) ----

// Cumulative XP required to REACH a given syndicate level.
// Per-level cost is level*500, so cumulative = 250 * L * (L-1):
// L2=500, L3=1500, L4=3000, L5=5000, L10=22500 …
function syn_xp_for_level(int $level): int {
  if ($level <= 1) return 0;
  return 250 * $level * ($level - 1);
}

// The player's syndicate id, or 0 if none.
function player_guild_id(PDO $pdo, int $pid): int {
  try {
    $q = $pdo->prepare('SELECT syndicate_id FROM syndicate_members WHERE player_id = ?');
    $q->execute([$pid]);
    return (int)$q->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

// Add XP to a syndicate and raise its level to match. Idempotent and
// race-safe: xp increments atomically, level is recomputed from the live
// cumulative xp and only ever raised. Returns levels gained.
function syn_grant_xp(PDO $pdo, int $sid, int $amount): int {
  if ($sid <= 0 || $amount <= 0) return 0;
  $pdo->prepare('UPDATE syndicates SET xp = xp + ? WHERE id = ?')->execute([$amount, $sid]);
  $q = $pdo->prepare('SELECT xp, level FROM syndicates WHERE id = ?');
  $q->execute([$sid]);
  $s = $q->fetch();
  if (!$s) return 0;
  $xp = (int)$s['xp']; $cur = (int)$s['level']; $lvl = max(1, $cur);
  while ($lvl < 999 && $xp >= syn_xp_for_level($lvl + 1)) $lvl++;
  if ($lvl > $cur) {
    // Only raise — never lower (guards against a stale concurrent read).
    $pdo->prepare('UPDATE syndicates SET level = ? WHERE id = ? AND level < ?')->execute([$lvl, $sid, $lvl]);
    return $lvl - $cur;
  }
  return 0;
}

// A player's chosen battle-XP donation rate (0-100), clamped.
function guild_xp_donate_rate(PDO $pdo, int $pid): int {
  try {
    $q = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $q->execute(["guild_xp_donate:{$pid}"]);
    return max(0, min(100, (int)$q->fetchColumn()));
  } catch (Throwable $e) { return 0; }
}

// Award XP earned from a battle, honouring the player's guild-donation rate.
// The donated cut goes to their syndicate's XP (and may level it up); the
// remainder is granted to the player (and gets their apartment XP bonus).
// Returns ['kept','donated','levels','guild_levels'].
function grant_battle_xp(int $pid, int $xp): array {
  $pdo = db();
  if ($xp <= 0) return ['kept'=>0,'donated'=>0,'levels'=>grant_xp($pid, $xp),'guild_levels'=>0];
  $rate = guild_xp_donate_rate($pdo, $pid);
  $sid  = $rate > 0 ? player_guild_id($pdo, $pid) : 0;
  if ($rate > 0 && $sid > 0) {
    $donated = (int)floor($xp * $rate / 100);
    $kept    = $xp - $donated;
    $gLevels = $donated > 0 ? syn_grant_xp($pdo, $sid, $donated) : 0;
    return ['kept'=>$kept,'donated'=>$donated,'levels'=>grant_xp($pid, $kept),'guild_levels'=>$gLevels];
  }
  return ['kept'=>$xp,'donated'=>0,'levels'=>grant_xp($pid, $xp),'guild_levels'=>0];
}

// ---- equipped gear resolution (shared) ----

// The equipped_weapon/equipped_armor settings can hold an id from either
// player_gear (Fabrication Lab crafts) or items (stash-equipped drops/buys).
// Resolve in the same order everywhere so combat matches what stash/home show.
function equipped_gear(PDO $pdo, int $pid, string $slot): ?array {
  if ($slot !== 'weapon' && $slot !== 'armor') return null;
  try {
    $q = $pdo->prepare('SELECT v FROM settings WHERE k=?');
    $q->execute(["equipped_{$slot}:{$pid}"]);
    $id = (int)$q->fetchColumn();
    if ($id <= 0) return null;
    $g = $pdo->prepare('SELECT id, name, atk_bonus AS atk, def_bonus AS def FROM player_gear WHERE id=? AND player_id=?');
    $g->execute([$id, $pid]);
    if ($row = $g->fetch()) return ['id'=>(int)$row['id'],'name'=>$row['name'],'atk'=>(int)$row['atk'],'def'=>(int)$row['def'],'src'=>'gear'];
    $i = $pdo->prepare('SELECT i.id, i.name, i.atk, i.def FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0');
    $i->execute([$pid, $id]);
    if ($row = $i->fetch()) return ['id'=>(int)$row['id'],'name'=>$row['name'],'atk'=>(int)$row['atk'],'def'=>(int)$row['def'],'src'=>'item'];
  } catch (Throwable $e) {}
  return null;
}

// ---- animated scene headers (shared canvas engine) ----

// Panel header with a themed animated canvas. Themes are drawn by the shared
// engine emitted via scene_header_js(): pulse | podium | seal | cell | shelf |
// blueprint | ink. $rightHtml renders bottom-right over the canvas.
function scene_header(string $id, string $icon, string $title, string $sub, string $theme, string $accent, string $rightHtml = ''): string {
  return '<div class="panel" style="padding:0;overflow:hidden">'
    . '<div style="position:relative">'
    . '<canvas id="' . e($id) . '" class="scene-hdr" data-scene="' . e($theme) . '" data-accent="' . e($accent) . '"'
    . ' style="display:block;width:100%;height:96px;border-radius:9px 9px 0 0"></canvas>'
    . '<div style="position:absolute;left:16px;bottom:10px;pointer-events:none">'
    . '<h2 style="margin:0;text-shadow:0 0 14px ' . e($accent) . '55">' . $icon . ' ' . $title . '</h2>'
    . '<p class="muted" style="margin:2px 0 0;font-size:11px;text-shadow:0 1px 4px #000">' . $sub . '</p>'
    . '</div>'
    . ($rightHtml !== '' ? '<div style="position:absolute;right:14px;bottom:10px;text-align:right">' . $rightHtml . '</div>' : '')
    . '</div></div>';
}

function scene_header_js(): string {
  return <<<'SCENEJS'
<script>
(function(){
  function hexA(h,a){ var n=parseInt(h.slice(1),16); return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')'; }
  document.querySelectorAll('canvas.scene-hdr:not([data-scene-live])').forEach(function(cv){
    cv.setAttribute('data-scene-live','1');
    var theme=cv.dataset.scene||'pulse', AC=cv.dataset.accent||'#19f0c7';
    var c=cv.getContext('2d');
    var W=560,H=96,dpr=Math.min(2,window.devicePixelRatio||1);
    cv.width=W*dpr; cv.height=H*dpr; c.scale(dpr,dpr);
    var P=[]; // generic particle pool
    for(var i=0;i<16;i++) P.push({x:Math.random()*W,y:Math.random()*H,v:.1+Math.random()*.3,p:Math.random()*9,s:1+Math.random()*2});
    var rings=[];
    function loop(t){
      if(!document.body.contains(cv)) return;
      requestAnimationFrame(loop);
      c.clearRect(0,0,W,H);
      var bg=c.createLinearGradient(0,0,0,H);
      bg.addColorStop(0,'#090a10'); bg.addColorStop(1,'#0d0e16');
      c.fillStyle=bg; c.fillRect(0,0,W,H);

      if(theme==='pulse'){
        // neural core with expanding rings + rising sparks
        var cx=W-92, cy=H/2;
        if(Math.random()<.03) rings.push({r:6,a:.45});
        for(var ri=rings.length-1;ri>=0;ri--){ var R=rings[ri]; R.r+=.6; R.a-=.005;
          if(R.a<=0){ rings.splice(ri,1); continue; }
          c.strokeStyle=hexA(AC,R.a); c.beginPath(); c.arc(cx,cy,R.r,0,Math.PI*2); c.stroke(); }
        var pp=.6+.4*Math.sin(t/420);
        c.shadowColor=AC; c.shadowBlur=16*pp;
        c.fillStyle=hexA(AC,.85);
        c.beginPath(); c.arc(cx,cy,7+2*pp,0,Math.PI*2); c.fill();
        c.shadowBlur=0;
        // synapse lines
        for(var s2=0;s2<5;s2++){ var a2=s2*1.256+t/3000;
          c.strokeStyle=hexA(AC,.18);
          c.beginPath(); c.moveTo(cx,cy);
          c.lineTo(cx+Math.cos(a2)*42,cy+Math.sin(a2)*30); c.stroke();
          c.fillStyle=hexA(AC,.5);
          c.beginPath(); c.arc(cx+Math.cos(a2)*42,cy+Math.sin(a2)*30,2,0,Math.PI*2); c.fill(); }
        P.forEach(function(q){ q.y-=q.v; if(q.y<-3){q.y=H+3;q.x=Math.random()*W;}
          c.fillStyle=hexA(AC,.12+.1*Math.sin(t/600+q.p)); c.fillRect(q.x,q.y,1.6,1.6); });
      }
      else if(theme==='podium'){
        // spotlights + glitter over a podium silhouette
        [[-1,'#e8d44d'],[1,'#ff2d95']].forEach(function(sp,i2){
          var ang=Math.sin(t/2200+i2*2.2)*.5;
          c.save(); c.translate(W*(.35+.3*i2),-4); c.rotate(ang*sp[0]);
          var cone=c.createLinearGradient(0,0,0,H+20);
          cone.addColorStop(0,hexA(sp[1],.12)); cone.addColorStop(1,hexA(sp[1],0));
          c.fillStyle=cone; c.beginPath(); c.moveTo(0,0); c.lineTo(-30,H+20); c.lineTo(30,H+20); c.closePath(); c.fill();
          c.restore(); });
        c.fillStyle='#101020';
        c.fillRect(W/2-78,H-26,52,18); c.fillRect(W/2-26,H-36,52,28); c.fillRect(W/2+26,H-20,52,12);
        c.strokeStyle='rgba(255,255,255,.12)';
        c.strokeRect(W/2-78.5,H-26.5,52,18); c.strokeRect(W/2-26.5,H-36.5,52,28); c.strokeRect(W/2+25.5,H-20.5,52,12);
        c.font='700 11px monospace'; c.textAlign='center'; c.fillStyle='rgba(255,255,255,.5)';
        c.fillText('2',W/2-52,H-13); c.fillText('1',W/2,H-20); c.fillText('3',W/2+52,H-10);
        P.forEach(function(q){ q.y+=q.v*.7; if(q.y>H+3){q.y=-3;q.x=Math.random()*W;}
          c.fillStyle='rgba(232,212,77,'+(0.15+0.2*Math.sin(t/300+q.p))+')';
          c.fillRect(q.x,q.y,1.8,1.8); });
      }
      else if(theme==='seal'){
        // swaying scales + drifting ledger lines
        var sx=W-100, sy=26, sway=Math.sin(t/1500)*.08;
        c.save(); c.translate(sx,sy); c.rotate(sway);
        c.strokeStyle=hexA(AC,.7); c.lineWidth=2;
        c.beginPath(); c.moveTo(0,-8); c.lineTo(0,30); c.moveTo(-34,2); c.lineTo(34,2); c.stroke();
        [[-34],[34]].forEach(function(arm){
          c.beginPath(); c.moveTo(arm[0],2); c.lineTo(arm[0]-9,18); c.moveTo(arm[0],2); c.lineTo(arm[0]+9,18); c.stroke();
          c.beginPath(); c.arc(arm[0],20,10,0,Math.PI); c.stroke(); });
        c.lineWidth=1; c.restore();
        for(var L=0;L<5;L++){ var ly=20+L*15, lx=((t/40)+L*150)%(W+160)-160;
          c.strokeStyle=hexA(AC,.10);
          c.beginPath(); c.moveTo(lx,ly); c.lineTo(lx+110,ly); c.stroke(); }
        P.forEach(function(q){ q.x+=q.v*.6; if(q.x>W+3){q.x=-3;q.y=Math.random()*H;}
          c.fillStyle=hexA(AC,.14); c.fillRect(q.x,q.y,1.6,1.6); });
      }
      else if(theme==='cell'){
        // searchlight behind cell bars
        var lx2=((t/26)%(W+300))-150;
        var lg=c.createLinearGradient(lx2-80,0,lx2+80,0);
        lg.addColorStop(0,'rgba(255,255,255,0)'); lg.addColorStop(.5,'rgba(255,255,255,.08)'); lg.addColorStop(1,'rgba(255,255,255,0)');
        c.fillStyle=lg; c.fillRect(0,0,W,H);
        c.fillStyle='#05050a';
        for(var b2=20;b2<W;b2+=34){ c.fillRect(b2,0,7,H); }
        c.fillStyle='rgba(255,255,255,.06)';
        for(var b3=20;b3<W;b3+=34){ c.fillRect(b3,0,1.6,H); }
        c.fillStyle=hexA(AC,.5+.3*Math.sin(t/600));
        c.fillRect(W-44,12,8,8);
      }
      else if(theme==='shelf'){
        // stocked shelves with blinking goods
        for(var sh=0;sh<3;sh++){
          var shy=24+sh*26;
          c.fillStyle='rgba(255,255,255,.05)'; c.fillRect(14,shy+14,W-28,2);
          for(var it2=0;it2<16;it2++){
            var ix=24+it2*34, hsh=(it2*7+sh*13)%5;
            var col=['#19f0c7','#e8a33d','#ff2d95','#a66de8','#3bcf63'][hsh];
            var lit2=((t/1000+it2*1.3+sh*2.7)%6)<4;
            c.fillStyle=lit2?hexA(col,.45):'rgba(255,255,255,.06)';
            c.fillRect(ix,shy+(it2%2?2:0),9,12-(it2%2?2:0));
          }
        }
        var swx=((t/36)%(W+220))-110;
        var sg2=c.createLinearGradient(swx-60,0,swx+60,0);
        sg2.addColorStop(0,hexA(AC,0)); sg2.addColorStop(.5,hexA(AC,.05)); sg2.addColorStop(1,hexA(AC,0));
        c.fillStyle=sg2; c.fillRect(0,0,W,H);
      }
      else if(theme==='blueprint'){
        // schematic grid with dash-drawn outlines
        c.strokeStyle=hexA(AC,.08);
        for(var gx=0;gx<W;gx+=24){ c.beginPath(); c.moveTo(gx,0); c.lineTo(gx,H); c.stroke(); }
        for(var gy=0;gy<H;gy+=24){ c.beginPath(); c.moveTo(0,gy); c.lineTo(W,gy); c.stroke(); }
        var dash=(t/30)%24;
        c.save(); c.setLineDash([10,7]); c.lineDashOffset=-dash;
        c.strokeStyle=hexA(AC,.5);
        c.strokeRect(W-150,22,76,40);
        c.beginPath(); c.arc(W-112,42,26,0,Math.PI*2); c.stroke();
        c.beginPath(); c.moveTo(W-180,70); c.lineTo(W-150,42); c.stroke();
        c.restore();
        c.fillStyle=hexA(AC,.6);
        [[W-150,22],[W-74,22],[W-150,62],[W-74,62]].forEach(function(pt){
          c.fillRect(pt[0]-2,pt[1]-2,4,4); });
        P.forEach(function(q){ q.x-=q.v*.5; if(q.x<-3){q.x=W+3;q.y=Math.random()*H;}
          c.fillStyle=hexA(AC,.12); c.fillRect(q.x,q.y,1.5,1.5); });
      }
      else if(theme==='desk'){
        // service counter windows with status lamps + a printing ticket stub
        for(var w3=0;w3<4;w3++){
          var wx3=36+w3*92;
          c.fillStyle='#101020'; c.fillRect(wx3,22,68,52);
          c.strokeStyle='rgba(255,255,255,.1)'; c.strokeRect(wx3+.5,22.5,68,52);
          c.fillStyle='rgba(255,255,255,.04)'; c.fillRect(wx3+6,30,56,24);
          var open2=((t/1600+w3*1.9)%5)<3.4;
          c.fillStyle=open2?hexA('#3bcf63',.8):hexA('#ff2d95',.7);
          c.shadowColor=open2?'#3bcf63':'#ff2d95'; c.shadowBlur=6;
          c.beginPath(); c.arc(wx3+60,29,2.6,0,Math.PI*2); c.fill();
          c.shadowBlur=0;
          c.font='700 7px monospace'; c.textAlign='center';
          c.fillStyle='rgba(255,255,255,.3)';
          c.fillText(open2?'OPEN':'BUSY',wx3+34,68);
        }
        // ticket stub spooling out of a dispenser (right)
        var dx3=W-96, cyc=(t%3000)/3000;
        c.fillStyle='#181828'; c.fillRect(dx3-20,24,40,16);
        c.strokeStyle=hexA(AC,.45); c.strokeRect(dx3-20.5,24.5,40,16);
        var stubLen=Math.min(26,cyc*38);
        if(stubLen>2){
          c.fillStyle=hexA(AC,.25);
          c.fillRect(dx3-11,40,22,stubLen);
          c.strokeStyle=hexA(AC,.5);
          c.strokeRect(dx3-11.5,40.5,22,stubLen);
          c.setLineDash([2,2]);
          c.beginPath(); c.moveTo(dx3-11,40+stubLen-3); c.lineTo(dx3+11,40+stubLen-3); c.stroke();
          c.setLineDash([]);
        }
        // queue dots shuffling forward
        for(var q3=0;q3<6;q3++){
          var qx=W-200+q3*16-((t/120)%16);
          c.fillStyle='rgba(255,255,255,'+(0.10+0.07*Math.sin(t/400+q3))+')';
          c.beginPath(); c.arc(qx,H-14,3,0,Math.PI*2); c.fill();
        }
      }
      else if(theme==='ink'){
        // ruled datapad lines + blinking cursor + drifting letters
        c.strokeStyle='rgba(255,255,255,.05)';
        for(var ln=26;ln<H-8;ln+=16){ c.beginPath(); c.moveTo(18,ln); c.lineTo(W-18,ln); c.stroke(); }
        c.strokeStyle=hexA(AC,.25);
        c.beginPath(); c.moveTo(42,10); c.lineTo(42,H-10); c.stroke();
        if(((t/600)%2)<1.2){ c.fillStyle=hexA(AC,.8); c.fillRect(54+((t/90)%180),H-26,2,11); }
        c.font='10px monospace';
        P.forEach(function(q,qi){ q.y-=q.v*.5; if(q.y<-4){q.y=H+4;q.x=50+Math.random()*(W-90);}
          c.fillStyle=hexA(AC,.10+.08*Math.sin(t/500+q.p));
          c.fillText('aeionrst'[qi%8],q.x,q.y); });
      }
    }
    requestAnimationFrame(loop);
  });
})();
</script>
SCENEJS;
}

// ---- playing cards (SVG) ----

function card_svg($rank, $suit) {
  $glyphs = ['S'=>'&#9824;', 'H'=>'&#9829;', 'D'=>'&#9830;', 'C'=>'&#9827;'];
  $g      = $glyphs[$suit] ?? '?';
  $isRed  = ($suit === 'H' || $suit === 'D');
  $col    = $isRed ? '#ff5555' : '#dde2f0';
  $border = $isRed ? '#cc3333' : '#3a3f5c';
  return '<svg class="pcard" viewBox="0 0 60 84" style="width:46px;height:64px;display:inline-block;vertical-align:middle;margin:1px" xmlns="http://www.w3.org/2000/svg">'
    . '<rect x="1" y="1" width="58" height="82" rx="6" fill="#0e0e1a" stroke="'.$border.'" stroke-width="1.5"/>'
    . '<text x="6" y="18" font-family="Verdana,Arial,sans-serif" font-size="14" font-weight="bold" fill="'.$col.'">'.e($rank).'</text>'
    . '<text x="30" y="52" text-anchor="middle" font-size="26" fill="'.$col.'">'.$g.'</text>'
    . '<text x="54" y="78" text-anchor="end" font-family="Verdana,Arial,sans-serif" font-size="14" font-weight="bold" fill="'.$col.'">'.e($rank).'</text>'
    . '</svg>';
}

function card_back_svg() {
  return '<svg class="pcardback" viewBox="0 0 60 84" style="width:46px;height:64px;display:inline-block;vertical-align:middle;margin:1px" xmlns="http://www.w3.org/2000/svg">'
    . '<rect x="1" y="1" width="58" height="82" rx="6" fill="#12121f" stroke="var(--accent)"/>'
    . '<rect x="7" y="7" width="46" height="70" rx="3" fill="none" stroke="var(--accent)" stroke-dasharray="3 3"/>'
    . '<text x="30" y="50" text-anchor="middle" font-size="22" fill="var(--accent)">&#9760;</text>'
    . '</svg>';
}
