<?php
/* lib.php — shared view helpers (committed & deployed; config.php is gitignored). */

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
