<?php
/* lib.php — shared view helpers (committed & deployed; config.php is gitignored). */

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

// CSRF mitigation: reject POSTs whose Origin/Referer host doesn't match ours.
// (Same-origin form/fetch posts pass; a cross-site attacker's posted form is blocked.)
function csrf_guard() {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
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
    'updates'  => ['Updates',        'index.php?p=updates'],
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
