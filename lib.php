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
