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
