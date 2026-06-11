<?php /* pages/mining.php — The Sump: interactive mine crawler */
$pdo = db();
$pid = (int)$player['id'];

// grant_xp() lives in lib.php (shared, concurrency-safe)

// ── Schema ─────────────────────────────────────────────────────────────────
try { $pdo->exec('CREATE TABLE IF NOT EXISTS player_ore (
  id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, ore_type VARCHAR(32) NOT NULL,
  quantity INT NOT NULL DEFAULT 0, UNIQUE KEY uq_po (player_id, ore_type), KEY idx_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch(Throwable $e) {}

// ── Config ─────────────────────────────────────────────────────────────────
$MINE_DEPTHS = [
  1 => ['name'=>'Surface Seam',    'req'=>0, 'size'=>16, 'ore_pct'=>0.13, 'dmult'=>1.0, 'col'=>'#3bcf63', 'desc'=>'Shallow cracks in the Grid substrate.'],
  2 => ['name'=>'Copper Vein',     'req'=>2, 'size'=>18, 'ore_pct'=>0.15, 'dmult'=>1.3, 'col'=>'#c87941', 'desc'=>'Conductive deposits in old conduit lines.'],
  3 => ['name'=>'Iron Shaft',      'req'=>4, 'size'=>20, 'ore_pct'=>0.17, 'dmult'=>1.7, 'col'=>'#b0b8cc', 'desc'=>'Collapsed structural columns, rich in alloy.'],
  4 => ['name'=>'Titanium Core',   'req'=>6, 'size'=>22, 'ore_pct'=>0.20, 'dmult'=>2.2, 'col'=>'#19f0c7', 'desc'=>'Ancient reinforced bunker levels.'],
  5 => ['name'=>'Void Deep',       'req'=>8, 'size'=>24, 'ore_pct'=>0.23, 'dmult'=>3.0, 'col'=>'#e8d44d', 'desc'=>'Unstable Grid anomaly zone. Bring Drive.'],
];
$MINE_ORES = [
  'scrap'     => ['name'=>'Junk Metal',        'drive'=>3,  'xp'=>2,  'col'=>'#8a8fa8','glyph'=>'◈'],
  'copper'    => ['name'=>'Copper Wire',        'drive'=>4,  'xp'=>5,  'col'=>'#e8a33d','glyph'=>'◈'],
  'iron'      => ['name'=>'Iron Alloy',         'drive'=>5,  'xp'=>10, 'col'=>'#b0b8cc','glyph'=>'◈'],
  'titanium'  => ['name'=>'Titanium Core',      'drive'=>7,  'xp'=>18, 'col'=>'#19f0c7','glyph'=>'◆'],
  'nanocarbon'=> ['name'=>'Nano-Carbon Fiber',  'drive'=>10, 'xp'=>30, 'col'=>'#ff2d95','glyph'=>'◆'],
  'quantum'   => ['name'=>'Quantum Crystal',    'drive'=>13, 'xp'=>50, 'col'=>'#a66de8','glyph'=>'◇'],
  'void'      => ['name'=>'Void Metal',         'drive'=>18, 'xp'=>80, 'col'=>'#e8d44d','glyph'=>'◇'],
];
$MINE_TABLES = [
  1 => [['scrap',70],['copper',30]],
  2 => [['copper',55],['iron',35],['titanium',10]],
  3 => [['iron',50],['titanium',35],['nanocarbon',15]],
  4 => [['titanium',38],['nanocarbon',37],['quantum',25]],
  5 => [['nanocarbon',30],['quantum',42],['void',28]],
];
const MINE_SENSOR_R = 2.5; // ore scanner range (tiles) — ore outside this is masked as floor

// ── Helpers ─────────────────────────────────────────────────────────────────
function mine_pick(array $t):string { $s=array_sum(array_column($t,1));$r=mt_rand(1,$s);$c=0;foreach($t as[$k,$w]){$c+=$w;if($r<=$c)return $k;}return $t[0][0]; }
function mine_fill(array $g,int $sx,int $sy,int $w,int $h):array {
  $v=[];$q=[[$sx,$sy]];
  while($q){[$x,$y]=array_shift($q);$k="$x,$y";if(isset($v[$k]))continue;if($x<0||$y<0||$x>=$w||$y>=$h||$g[$y][$x]===0)continue;$v[$k]=1;foreach([[1,0],[-1,0],[0,1],[0,-1]]as[$dx,$dy])$q[]=[$x+$dx,$y+$dy];}
  return $v;
}
function mine_gen(int $d,array $tables,array $depths):array {
  $cfg=$depths[$d];$w=$cfg['size'];$h=$cfg['size'];$tries=0;
  do {
    $tries++;mt_srand(mt_rand());
    $g=[];for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++)$g[$y][$x]=(mt_rand(0,99)<44)?0:1;
    for($x=0;$x<$w;$x++){$g[0][$x]=0;$g[$h-1][$x]=0;}for($y=0;$y<$h;$y++){$g[$y][0]=0;$g[$y][$w-1]=0;}
    for($i=0;$i<5;$i++){$ng=$g;for($y=1;$y<$h-1;$y++)for($x=1;$x<$w-1;$x++){$wc=0;for($dy=-1;$dy<=1;$dy++)for($dx=-1;$dx<=1;$dx++)if($g[$y+$dy][$x+$dx]===0)$wc++;$ng[$y][$x]=($wc>=5)?0:1;}$g=$ng;}
    $fl=[];for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++)if($g[$y][$x]===1)$fl[]=[$x,$y];
  } while(count($fl)<28&&$tries<20);
  shuffle($fl);$sp=$fl[0];
  $conn=mine_fill($g,$sp[0],$sp[1],$w,$h);
  for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++)if($g[$y][$x]===1&&!isset($conn["$x,$y"]))$g[$y][$x]=0;
  $fl=[];for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++)if($g[$y][$x]===1)$fl[]=[$x,$y];
  $ex=$sp;$md=0;foreach($fl as $c){$dd=abs($c[0]-$sp[0])+abs($c[1]-$sp[1]);if($dd>$md){$md=$dd;$ex=$c;}}
  $g[$sp[1]][$sp[0]]=4;$g[$ex[1]][$ex[0]]=3;
  $ore=[];$cands=array_values(array_filter($fl,function($c)use($sp,$ex){return!($c[0]===$sp[0]&&$c[1]===$sp[1])&&!($c[0]===$ex[0]&&$c[1]===$ex[1]);}));
  shuffle($cands);$n=(int)(count($fl)*$cfg['ore_pct']);
  foreach(array_slice($cands,0,$n) as $c){$t=mine_pick($tables[$d]);$ore["{$c[0]},{$c[1]}"]=$t;$g[$c[1]][$c[0]]=2;}
  $rev=[];for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++)$rev[$y][$x]=false;
  return ['grid'=>$g,'ore'=>$ore,'w'=>$w,'h'=>$h,'spawn'=>$sp,'exit_pos'=>$ex,'rev'=>$rev];
}
function mine_reveal(array &$rev,int $px,int $py,int $w,int $h,int $r=4):void {
  for($dy=-$r;$dy<=$r;$dy++)for($dx=-$r;$dx<=$r;$dx++){$nx=$px+$dx;$ny=$py+$dy;if($nx>=0&&$ny>=0&&$nx<$w&&$ny<$h&&sqrt($dx*$dx+$dy*$dy)<=$r+.5)$rev[$ny][$nx]=true;}
}
// Two-tier visibility: explored terrain (walls/floor/exit) persists as map memory,
// but ORE is only included while inside the scanner radius around the player.
// The masking happens server-side, so the client payload never leaks ore positions.
function mine_to_client(array $run,array $ores):array {
  $px=(int)$run['px'];$py=(int)$run['py'];
  $cg=[];
  for($y=0;$y<$run['h'];$y++){$cg[$y]=[];for($x=0;$x<$run['w'];$x++){
    if(!$run['rev'][$y][$x]){$cg[$y][$x]=['t'=>-1];continue;}
    $t=$run['grid'][$y][$x];
    if($t===2){
      $d=sqrt(($x-$px)*($x-$px)+($y-$py)*($y-$py));
      if($d<=MINE_SENSOR_R){
        $ot=$run['ore']["{$x},{$y}"]??'scrap';$od=$ores[$ot]??$ores['scrap'];
        $cg[$y][$x]=['t'=>2,'o'=>$ot,'c'=>$od['col'],'g'=>$od['glyph'],'n'=>$od['name']];
      } else {
        $cg[$y][$x]=['t'=>1]; // masked: reads as plain floor until you get close
      }
      continue;
    }
    $cg[$y][$x]=['t'=>$t];
  }}
  return ['grid'=>$cg,'px'=>$px,'py'=>$py,'w'=>$run['w'],'h'=>$run['h'],
          'depth'=>$run['depth'],'collected'=>$run['collected'],'steps'=>$run['steps'],
          'sensor'=>MINE_SENSOR_R,
          'on_exit'=>($run['grid'][$py][$px]===3)];
}

// ── AJAX ────────────────────────────────────────────────────────────────────
if (!empty($_POST['mine_ajax'])) {
  header('Content-Type: application/json');
  $act = $_POST['mine_action'] ?? '';
  $run = $_SESSION['mine_run'] ?? null;
  try {
    $pl = current_player(); $plid = (int)$pl['id'];

    if ($act === 'enter') {
      $d = max(1,min(5,(int)($_POST['depth']??1)));
      if ($d < 1 || $d > 5) throw new RuntimeException('Bad depth.');
      if ((int)$pl['level'] < 1) throw new RuntimeException('Need level 1.');
      // Check drone skill
      $sq=$pdo->prepare("SELECT FLOOR(ps.points/100) FROM player_skills ps JOIN skills s ON s.id=ps.skill_id WHERE ps.player_id=? AND s.code='drone'");
      $sq->execute([$plid]); $droneLv=(int)($sq->fetchColumn()?:0);
      if ($droneLv < $MINE_DEPTHS[$d]['req']) throw new RuntimeException("Requires Drone Ops Level {$MINE_DEPTHS[$d]['req']}.");
      $m=mine_gen($d,$MINE_TABLES,$MINE_DEPTHS);
      $run=['depth'=>$d,'grid'=>$m['grid'],'ore'=>$m['ore'],'w'=>$m['w'],'h'=>$m['h'],
            'rev'=>$m['rev'],'px'=>$m['spawn'][0],'py'=>$m['spawn'][1],
            'spawn'=>$m['spawn'],'exit_pos'=>$m['exit_pos'],'collected'=>[],'steps'=>0];
      mine_reveal($run['rev'],$run['px'],$run['py'],$run['w'],$run['h']);
      $_SESSION['mine_run']=$run;
      echo json_encode(['ok'=>true,'state'=>mine_to_client($run,$MINE_ORES)]);exit;
    }

    if (!$run) throw new RuntimeException('No active run.');

    if ($act === 'move') {
      $dir=$_POST['dir']??'';
      [$dx,$dy]=['up'=>[0,-1],'down'=>[0,1],'left'=>[-1,0],'right'=>[1,0]][$dir]??[0,0];
      if(!$dx&&!$dy) throw new RuntimeException('Bad dir.');
      $nx=$run['px']+$dx;$ny=$run['py']+$dy;
      if($nx<0||$ny<0||$nx>=$run['w']||$ny>=$run['h']||$run['grid'][$ny][$nx]===0)throw new RuntimeException('Blocked.');
      $run['px']=$nx;$run['py']=$ny;$run['steps']++;
      mine_reveal($run['rev'],$nx,$ny,$run['w'],$run['h']);
      $_SESSION['mine_run']=$run;
      echo json_encode(['ok'=>true,'state'=>mine_to_client($run,$MINE_ORES)]);exit;
    }

    if ($act === 'harvest') {
      $tx=(int)($_POST['tx']??-1);$ty=(int)($_POST['ty']??-1);
      if(abs($tx-$run['px'])>1||abs($ty-$run['py'])>1||($tx===$run['px']&&$ty===$run['py']))throw new RuntimeException('Too far away.');
      if($tx<0||$ty<0||$tx>=$run['w']||$ty>=$run['h']||$run['grid'][$ty][$tx]!==2)throw new RuntimeException('No ore here.');
      $ok=$run['ore']["{$tx},{$ty}"]??'scrap';$od=$MINE_ORES[$ok]??$MINE_ORES['scrap'];
      $cost=(int)ceil($od['drive']*$MINE_DEPTHS[$run['depth']]['dmult']);
      $dc=$pdo->prepare('UPDATE players SET cycles=cycles-? WHERE id=? AND cycles>=?');
      $dc->execute([$cost,$plid,$cost]);
      if($dc->rowCount()!==1)throw new RuntimeException("Need {$cost} Drive to extract this.");
      $run['grid'][$ty][$tx]=1;unset($run['ore']["{$tx},{$ty}"]);
      $run['collected'][$ok]=($run['collected'][$ok]??0)+1;
      if($od['xp']>0)grant_xp($plid,$od['xp']);
      $_SESSION['mine_run']=$run;$pl=current_player();
      echo json_encode(['ok'=>true,'state'=>mine_to_client($run,$MINE_ORES),
        'mined'=>['x'=>$tx,'y'=>$ty,'col'=>$od['col'],'name'=>$od['name'],'cost'=>$cost,'xp'=>(int)$od['xp']],
        'msg'=>"Extracted {$od['name']}! (-{$cost} Drive)",'drive'=>(int)$pl['cycles'],'drive_max'=>(int)$pl['cycles_max']]);exit;
    }

    if ($act === 'exit_mine') {
      if($run['grid'][$run['py']][$run['px']]!==3)throw new RuntimeException('Not at the exit shaft.');
      $summary=[];
      foreach($run['collected'] as $ot=>$qty){
        $od=$MINE_ORES[$ot]??$MINE_ORES['scrap'];
        $pdo->prepare('INSERT INTO player_ore(player_id,ore_type,quantity)VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')->execute([$plid,$ot,$qty,$qty]);
        $summary[$ot]=['name'=>$od['name'],'qty'=>$qty,'col'=>$od['col'],'glyph'=>$od['glyph'],'xp'=>(int)$od['xp']];
      }
      $depth=$run['depth'];$steps=$run['steps'];
      unset($_SESSION['mine_run']);
      echo json_encode(['ok'=>true,'complete'=>true,'summary'=>$summary,'depth'=>$depth,'steps'=>$steps]);exit;
    }

    if ($act === 'leave') { unset($_SESSION['mine_run']); echo json_encode(['ok'=>true,'left'=>true]); exit; }

    throw new RuntimeException('Unknown action.');
  } catch (Throwable $e) { echo json_encode(['ok'=>false,'err'=>$e->getMessage()]); }
  exit;
}

// ── Read player state ───────────────────────────────────────────────────────
$run = $_SESSION['mine_run'] ?? null;
$miningLevel = 0;
try {
  $sq=$pdo->prepare("SELECT FLOOR(ps.points/100) FROM player_skills ps JOIN skills s ON s.id=ps.skill_id WHERE ps.player_id=? AND s.code='drone'");
  $sq->execute([$pid]); $miningLevel=(int)($sq->fetchColumn()?:0);
} catch(Throwable $e){}
$initialState = $run ? json_encode(mine_to_client($run, $MINE_ORES)) : 'null';
$oreDefsJson  = json_encode($MINE_ORES);
$depthsJson   = json_encode(array_map(function($d){return['name'=>$d['name'],'col'=>$d['col']];},$MINE_DEPTHS));
?>
<style>
#mine-wrap{max-width:560px;margin:0 auto}
#mine-stage{position:relative;display:inline-block;max-width:100%}
#mine-canvas{display:block;background:#04040a;border-radius:10px;cursor:crosshair;touch-action:none;border:1px solid rgba(25,240,199,.18);box-shadow:0 0 30px rgba(25,240,199,.06),inset 0 0 60px rgba(0,0,0,.6);max-width:100%;height:auto}
#mine-minimap{position:absolute;top:8px;right:8px;border:1px solid rgba(255,255,255,.15);border-radius:4px;background:rgba(3,3,8,.82);image-rendering:pixelated;width:86px;height:86px;pointer-events:none}
.mine-ctrl-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:var(--text);border-radius:6px;padding:8px 14px;cursor:pointer;font-size:14px;line-height:1;user-select:none;-webkit-user-select:none;transition:background .1s,transform .06s}
.mine-ctrl-btn:active{background:rgba(25,240,199,.15);border-color:var(--accent);transform:scale(.94)}
.mine-ctrl-btn.harvest-btn{background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.3);color:var(--accent)}
.mine-ctrl-btn.exit-btn{background:rgba(59,207,99,.12);border-color:rgba(59,207,99,.35);color:#3bcf63;animation:minePulse 1.4s ease-in-out infinite}
@keyframes minePulse{0%,100%{box-shadow:0 0 0 0 rgba(59,207,99,.25)}50%{box-shadow:0 0 12px 3px rgba(59,207,99,.25)}}
.mine-level-card{position:relative;overflow:hidden;background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:14px;cursor:pointer;transition:border-color .15s,background .15s,transform .15s}
.mine-level-card:hover:not(.locked){border-color:rgba(25,240,199,.4);background:rgba(25,240,199,.04);transform:translateY(-2px)}
.mine-level-card.locked{opacity:.45;cursor:not-allowed}
.ore-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600}
#mine-msg{min-height:22px;font-size:12px;text-align:center;transition:opacity .3s}
@keyframes oreChipPop{0%{transform:scale(.6);opacity:0}70%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}
.ore-chip.pop{animation:oreChipPop .28s ease-out backwards}
#mine-scan{position:absolute;inset:0;border-radius:10px;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(255,255,255,.02) 0 1px,transparent 1px 3px)}
#mine-lobby h2{text-shadow:0 0 14px rgba(25,240,199,.3)}
.mine-level-card::after{content:'';position:absolute;top:0;left:-65%;width:45%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.07),transparent);transform:skewX(-20deg);transition:left .45s ease;pointer-events:none}
.mine-level-card:hover:not(.locked)::after{left:125%}
#hud-drivebar-track{height:4px;border-radius:2px;background:rgba(255,255,255,.07);overflow:hidden;margin-top:7px}
#hud-drivebar{height:100%;border-radius:2px;background:linear-gradient(90deg,#e8a33d,#ffce6b);box-shadow:0 0 8px rgba(232,163,61,.5);transition:width .35s ease}
</style>

<!-- ══ LOBBY ══ -->
<div id="mine-lobby" <?= $run ? 'style="display:none"' : '' ?>>
<div class="panel">
  <h2 style="margin-top:0">&#9935; The Sump &mdash; Mine Crawler</h2>
  <p class="muted" style="margin:-8px 0 14px">"The Grid has layers. Each one darker than the last. Go deep enough and you might not come back up."</p>
  <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
    <div><div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.5px">Drive</div>
      <div style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:20px;color:#e8a33d"><?= number_format((int)$player['cycles']) ?><span style="font-size:11px;color:var(--muted);font-weight:400"> / <?= number_format((int)$player['cycles_max']) ?></span></div></div>
    <div><div class="muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.5px">Drone Ops</div>
      <div style="font-family:'Orbitron',sans-serif;font-weight:700;font-size:20px;color:#4d6be8">Lv <?= $miningLevel ?></div></div>
    <div style="flex:1;min-width:180px;font-size:11px;color:var(--muted);line-height:1.5;border-left:1px solid var(--line);padding-left:14px">
      Your rig's short-range scanner only pings ore within <b style="color:var(--accent)">2.5 tiles</b>.
      Explored tunnels stay mapped, but deposits fade off-scope &mdash; mark your finds and move fast.
    </div>
  </div>
</div>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px">Select Depth</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
    <?php foreach ($MINE_DEPTHS as $d => $cfg):
      $locked = $miningLevel < $cfg['req'];
    ?>
    <div class="mine-level-card <?= $locked ? 'locked' : '' ?>" onclick="<?= !$locked ? "enterMine($d)" : '' ?>">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $cfg['col'] ?>,transparent)"></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <span style="font-size:11px;font-family:'Orbitron',sans-serif;font-weight:700;color:<?= $cfg['col'] ?>">DEPTH <?= $d ?></span>
        <?php if ($locked): ?>
          <span style="font-size:9px;background:rgba(255,45,149,.1);border:1px solid rgba(255,45,149,.3);color:var(--neon2);padding:1px 6px;border-radius:10px">Drone <?= $cfg['req'] ?>+</span>
        <?php else: ?>
          <span style="font-size:9px;background:rgba(59,207,99,.1);border:1px solid rgba(59,207,99,.3);color:#3bcf63;padding:1px 6px;border-radius:10px">Unlocked</span>
        <?php endif; ?>
      </div>
      <div style="font-weight:700;font-size:13px;margin-bottom:4px"><?= $cfg['name'] ?></div>
      <div style="font-size:11px;color:var(--muted);line-height:1.4"><?= $cfg['desc'] ?></div>
      <div style="display:flex;gap:6px;margin-top:9px;align-items:center">
        <span style="font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Yields</span>
        <?php foreach ($MINE_TABLES[$d] as [$ok,$w]): $od=$MINE_ORES[$ok]; ?>
        <span title="<?= e($od['name']) ?> &mdash; <?= $w ?>%" style="color:<?= $od['col'] ?>;font-size:14px;text-shadow:0 0 6px <?= $od['col'] ?>66;cursor:help"><?= $od['glyph'] ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<!-- Stockpile -->
<?php
$oreInv=[];
try{$oq=$pdo->prepare('SELECT ore_type,quantity FROM player_ore WHERE player_id=? AND quantity>0 ORDER BY ore_type');$oq->execute([$pid]);foreach($oq as $r)$oreInv[$r['ore_type']]=(int)$r['quantity'];}catch(Throwable $e){}
if(!empty($oreInv)):?>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128219; Your Stockpile</h3>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach($MINE_ORES as $ok=>$od):if(!isset($oreInv[$ok]))continue;?>
    <div class="ore-chip" style="color:<?= $od['col'] ?>;border-color:<?= $od['col'] ?>40">
      <span><?= $od['glyph'] ?></span>
      <span style="color:var(--text)"><?= e($od['name']) ?></span>
      <span style="font-family:'Orbitron',sans-serif;font-size:11px">×<?= number_format($oreInv[$ok]) ?></span>
    </div>
    <?php endforeach;?>
  </div>
  <p style="margin:10px 0 0;font-size:12px"><a href="index.php?p=weaponcraft" style="color:var(--accent)">&#9874; Fabrication Lab &rarr;</a></p>
</div>
<?php endif;?>
</div>

<!-- ══ ACTIVE MINE ══ -->
<div id="mine-active" <?= !$run ? 'style="display:none"' : '' ?>>
<div id="mine-wrap">

  <!-- HUD -->
  <div class="panel" style="margin-bottom:8px;padding:10px 14px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <div style="font-size:11px;color:var(--muted)">DEPTH <span id="hud-depth" style="font-family:'Orbitron',sans-serif;color:var(--accent);font-weight:700">?</span></div>
        <div style="font-size:11px;color:var(--muted)">DRIVE <span id="hud-drive" style="font-family:'Orbitron',sans-serif;color:#e8a33d;font-weight:700"><?= (int)$player['cycles'] ?></span></div>
        <div style="font-size:11px;color:var(--muted)">STEPS <span id="hud-steps" style="font-family:'Orbitron',sans-serif;color:var(--text);font-weight:700">0</span></div>
        <div style="font-size:11px;color:var(--muted)">ORE <span id="hud-ore" style="font-family:'Orbitron',sans-serif;color:#3bcf63;font-weight:700">0</span></div>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <button id="mine-mute" onclick="toggleMineSound()" title="Toggle sound" style="font-size:12px;padding:4px 9px;background:transparent;border:1px solid rgba(255,255,255,.15);color:var(--muted);border-radius:4px;cursor:pointer">&#128266;</button>
        <button onclick="leaveMine()" style="font-size:11px;padding:4px 10px;background:transparent;border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:4px;cursor:pointer">✕ Abandon</button>
      </div>
    </div>
    <div id="hud-drivebar-track"><div id="hud-drivebar" style="width:<?= (int)$player['cycles_max']>0 ? min(100,round((int)$player['cycles']/(int)$player['cycles_max']*100)) : 0 ?>%"></div></div>
  </div>

  <!-- Message bar -->
  <div id="mine-msg" class="muted" style="margin-bottom:6px;padding:0 4px">&nbsp;</div>

  <!-- Canvas + minimap -->
  <div style="text-align:center;margin-bottom:8px">
    <div id="mine-stage">
      <canvas id="mine-canvas"></canvas>
      <canvas id="mine-minimap"></canvas>
      <div id="mine-scan"></div>
    </div>
  </div>

  <!-- Controls -->
  <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">
    <div style="display:grid;grid-template-columns:repeat(3,38px);grid-template-rows:repeat(3,38px);gap:3px">
      <div></div>
      <button class="mine-ctrl-btn" onclick="doMove('up')" title="W / ↑">▲</button>
      <div></div>
      <button class="mine-ctrl-btn" onclick="doMove('left')" title="A / ←">◄</button>
      <button class="mine-ctrl-btn" style="font-size:10px;opacity:.4" disabled>●</button>
      <button class="mine-ctrl-btn" onclick="doMove('right')" title="D / →">►</button>
      <div></div>
      <button class="mine-ctrl-btn" onclick="doMove('down')" title="S / ↓">▼</button>
      <div></div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px">
      <button id="btn-harvest" class="mine-ctrl-btn harvest-btn" onclick="doHarvestAdjacent()" title="E — mine nearest adjacent ore">◈ Mine Ore <span style="font-size:9px;opacity:.7">[E]</span></button>
      <button id="btn-exit" class="mine-ctrl-btn exit-btn" onclick="doExit()" style="display:none" title="Exit the mine">⬆ Exit Shaft <span style="font-size:9px;opacity:.7">[X]</span></button>
    </div>
  </div>

  <!-- Collected bar -->
  <div id="mine-collected" class="panel" style="padding:10px 14px;min-height:46px">
    <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px">Extracted This Run</div>
    <div id="mine-ore-list" style="display:flex;flex-wrap:wrap;gap:6px">
      <span class="muted" style="font-size:12px">Nothing yet — get close enough for your scanner to ping a deposit.</span>
    </div>
  </div>

  <!-- Legend -->
  <div style="display:flex;justify-content:center;gap:14px;flex-wrap:wrap;padding:8px 0;font-size:10px;color:var(--muted)">
    <span><span style="color:var(--accent)">&#9679;</span> You</span>
    <span style="color:#3bcf63">⬆ Exit</span>
    <span><span style="color:var(--accent)">◈</span> Ore <span style="opacity:.6">(scanner range only)</span></span>
    <span style="opacity:.4">░ Unexplored</span>
    <span style="opacity:.7">Click a tile to auto-path &middot; click ore to mine</span>
  </div>
</div>
</div>

<!-- ══ RESULT ══ -->
<div id="mine-result" style="display:none">
<div class="panel" style="text-align:center">
  <div style="font-size:36px;margin-bottom:8px">&#9935;</div>
  <h2 style="margin:0 0 6px">Run Complete</h2>
  <div id="result-depth" class="muted" style="font-size:13px;margin-bottom:16px"></div>
  <div id="result-ore" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:18px"></div>
  <div id="result-empty" class="muted" style="display:none;margin-bottom:16px">You made it out but found nothing.</div>
  <div style="display:flex;gap:10px;justify-content:center">
    <button onclick="enterMine(window._lastDepth||1)" class="mine-ctrl-btn harvest-btn" style="padding:10px 20px">&#9935; Dive Again</button>
    <button onclick="showLobby()" class="mine-ctrl-btn" style="padding:10px 20px">⬆ Return to Surface</button>
  </div>
</div>
</div>

<script>
(function(){
'use strict';

var CELL=24, VP=17, HALF=Math.floor(VP/2), W=CELL*VP; // 408 logical px
var MOVE_MS=110;
var canvas=document.getElementById('mine-canvas');
if(!canvas) return;
var ctx=canvas.getContext('2d');
var dpr=Math.min(2,window.devicePixelRatio||1);
canvas.width=W*dpr; canvas.height=W*dpr;
canvas.style.width=W+'px';
ctx.scale(dpr,dpr);

var state=<?= $initialState ?>;
var oreDefs=<?= $oreDefsJson ?>;
var depthNames=<?= $depthsJson ?>;
var busy=false, msgTimer=null;

// Animation state
var anim={x:0,y:0};            // player world pos (float, lerped)
var cam={x:0,y:0};             // camera centre (float, trails player)
var moveAnim=null;             // {fx,fy,tx,ty,start}
var particles=[], floaters=[];
var shake=0;
var hover=null;                // {wx,wy}
var path=[];                   // queued auto-path dirs
var pendingHarvest=null;       // {x,y} mine this once adjacent
var keysDown=[];               // held movement keys
var exitSeen=false;
var flash=0;                   // white-out on mine entry, decays
var lastDir='down';            // player heading tick
var motes=[];                  // ambient dust
var themeDepth=0;
var theme={wallTop:'#0a0a18',wallFace:'#10102a',floor:'#0d0d26',edge:'#19f0c7'};

function hexMix(a,b,t){
  var pa=parseInt(a.slice(1),16), pb=parseInt(b.slice(1),16);
  var r=Math.round(((pa>>16)&255)+((((pb>>16)&255)-((pa>>16)&255))*t));
  var g=Math.round(((pa>>8)&255)+((((pb>>8)&255)-((pa>>8)&255))*t));
  var bl=Math.round((pa&255)+(((pb&255)-(pa&255))*t));
  return 'rgb('+r+','+g+','+bl+')';
}
function setTheme(){
  var dcol=(depthNames[state.depth]||{}).col||'#19f0c7';
  theme={
    wallTop:hexMix('#0a0a18',dcol,.06),
    wallFace:hexMix('#10102a',dcol,.10),
    floor:hexMix('#0d0d26',dcol,.06),
    edge:dcol
  };
}
function initMotes(){
  motes=[];
  for(var i=0;i<26;i++) motes.push({
    x:state.px+(Math.random()-.5)*VP, y:state.py+(Math.random()-.5)*VP,
    vx:(Math.random()-.5)*.005, vy:(Math.random()-.5)*.004-.001,
    s:.6+Math.random()*1.3, p:Math.random()*Math.PI*2
  });
}

// ── Sound (tiny synth, no assets) ─────────────────────────────────────────
var ac=null, muted=localStorage.getItem('mineMuted')==='1';
function sfx(freq,dur,type,vol,slide){
  if(muted) return;
  try{
    ac=ac||new (window.AudioContext||window.webkitAudioContext)();
    var o=ac.createOscillator(),g=ac.createGain();
    o.type=type||'sine'; o.frequency.value=freq;
    if(slide) o.frequency.exponentialRampToValueAtTime(slide,ac.currentTime+dur);
    g.gain.value=vol||.05;
    g.gain.exponentialRampToValueAtTime(.0001,ac.currentTime+dur);
    o.connect(g); g.connect(ac.destination);
    o.start(); o.stop(ac.currentTime+dur);
  }catch(e){}
}
function updateMuteBtn(){ var b=document.getElementById('mine-mute'); if(b) b.innerHTML=muted?'&#128263;':'&#128266;'; }
window.toggleMineSound=function(){ muted=!muted; localStorage.setItem('mineMuted',muted?'1':'0'); updateMuteBtn(); if(!muted) sfx(660,.08,'sine',.05); };
updateMuteBtn();

// ── Utils ──────────────────────────────────────────────────────────────────
function h2(x,y){ var n=x*374761393+y*668265263; n=(n^(n>>13))*1274126177; return ((n^(n>>16))>>>0)/4294967295; }
function dist(ax,ay,bx,by){ var dx=ax-bx,dy=ay-by; return Math.sqrt(dx*dx+dy*dy); }
function setState(s){
  var first=!state;
  state=s;
  if(first){anim.x=s.px;anim.y=s.py;cam.x=s.px;cam.y=s.py;}
  if(s&&themeDepth!==s.depth){ themeDepth=s.depth; setTheme(); initMotes(); }
  updateHUD(); drawMini();
  // exit discovered?
  if(!exitSeen&&s){
    for(var y=0;y<s.h&&!exitSeen;y++)for(var x=0;x<s.w;x++)if(s.grid[y][x].t===3){exitSeen=true;showMsg('Exit shaft located — marked on your map.','#3bcf63');sfx(440,.25,'sine',.06,880);break;}
  }
  // pending harvest now adjacent?
  if(pendingHarvest&&state){
    var dx=Math.abs(pendingHarvest.x-state.px), dy=Math.abs(pendingHarvest.y-state.py);
    if(dx<=1&&dy<=1&&!(dx===0&&dy===0)){ var t=pendingHarvest; pendingHarvest=null; doHarvest(t.x,t.y); }
    else if(!path.length&&!busy){ pendingHarvest=null; }
  }
}

// ── Render loop ─────────────────────────────────────────────────────────────
var vignette=null;
function makeVignette(){
  vignette=ctx.createRadialGradient(W/2,W/2,W*.34,W/2,W/2,W*.72);
  vignette.addColorStop(0,'rgba(0,0,0,0)');
  vignette.addColorStop(1,'rgba(0,0,0,.55)');
}
makeVignette();

function loop(now){
  if(!document.body.contains(canvas)) return; // page swapped away — stop
  requestAnimationFrame(loop);
  if(!state){ return; }

  // queued inputs
  if(!busy){
    if(path.length){ doMove(path.shift()); }
    else if(keysDown.length){ doMove(keysDown[keysDown.length-1]); }
  }

  // player lerp
  if(moveAnim){
    var t=(now-moveAnim.start)/MOVE_MS;
    if(t>=1){ anim.x=moveAnim.tx; anim.y=moveAnim.ty; moveAnim=null; }
    else { var e=t*(2-t); anim.x=moveAnim.fx+(moveAnim.tx-moveAnim.fx)*e; anim.y=moveAnim.fy+(moveAnim.ty-moveAnim.fy)*e; }
  } else { anim.x=state.px; anim.y=state.py; }
  cam.x+=(anim.x-cam.x)*.18;
  cam.y+=(anim.y-cam.y)*.18;
  shake*=.85; if(shake<.3) shake=0;
  flash*=.90;

  draw(now);
}

function tileT(wx,wy){
  if(!state||wx<0||wy<0||wx>=state.w||wy>=state.h) return -2;
  return state.grid[wy][wx].t;
}
function pathHex(cx,cy,r){
  ctx.beginPath();
  for(var i=0;i<6;i++){var a=Math.PI/6+i*Math.PI/3;var X=cx+Math.cos(a)*r,Y=cy+Math.sin(a)*r;i?ctx.lineTo(X,Y):ctx.moveTo(X,Y);}
  ctx.closePath();
}
function pathStar(cx,cy,r){
  ctx.beginPath();
  for(var i=0;i<8;i++){var a=i*Math.PI/4-Math.PI/2;var rr=i%2?r*.42:r;var X=cx+Math.cos(a)*rr,Y=cy+Math.sin(a)*rr;i?ctx.lineTo(X,Y):ctx.moveTo(X,Y);}
  ctx.closePath();
}

function draw(now){
  ctx.clearRect(0,0,W,W);
  var shx=shake?(Math.random()-.5)*shake:0, shy=shake?(Math.random()-.5)*shake:0;
  var ox=cam.x-HALF, oy=cam.y-HALF;
  var x0=Math.floor(ox)-1, y0=Math.floor(oy)-1;
  var grid=state.grid, w=state.w, h=state.h;
  var sensor=state.sensor||2.5;

  for(var wy=y0; wy<=y0+VP+2; wy++){
    for(var wx=x0; wx<=x0+VP+2; wx++){
      var sx=(wx-ox)*CELL+shx, sy=(wy-oy)*CELL+shy;
      if(sx<-CELL||sy<-CELL||sx>W||sy>W) continue;

      // outside map / fog
      if(wx<0||wy<0||wx>=w||wy>=h){ ctx.fillStyle='#04040a'; ctx.fillRect(sx,sy,CELL+1,CELL+1); continue; }
      var cell=grid[wy][wx], t=cell.t;
      if(t===-1){
        ctx.fillStyle='#04040a'; ctx.fillRect(sx,sy,CELL+1,CELL+1);
        if(h2(wx,wy)>.93){ ctx.fillStyle='rgba(255,255,255,.025)'; ctx.fillRect(sx+CELL*.4,sy+CELL*.4,1.5,1.5); }
        continue;
      }

      var r=h2(wx,wy);

      if(t===0){ // wall — edge-lit toward open cave
        ctx.fillStyle=theme.wallTop; ctx.fillRect(sx,sy,CELL+1,CELL+1);
        ctx.fillStyle=theme.wallFace; ctx.fillRect(sx+1,sy+1,CELL-2,CELL-2);
        ctx.fillStyle='rgba(255,255,255,.05)'; ctx.fillRect(sx+1,sy+1,CELL-2,1.5);
        if(r>.55){ ctx.fillStyle='rgba(0,0,0,.4)'; ctx.fillRect(sx+3+r*8,sy+5+r*6,CELL*.34,1.5); }
        if(r<.22){ ctx.fillStyle='rgba(255,255,255,.035)'; ctx.fillRect(sx+4,sy+CELL*.55,1.5,CELL*.3); }
        // rim light on faces adjacent to walkable cave
        ctx.fillStyle=theme.edge;
        ctx.globalAlpha=.14;
        if(tileT(wx,wy+1)>0) ctx.fillRect(sx,sy+CELL-1.6,CELL,1.6);
        ctx.globalAlpha=.08;
        if(tileT(wx,wy-1)>0) ctx.fillRect(sx,sy,CELL,1.4);
        if(tileT(wx-1,wy)>0) ctx.fillRect(sx,sy,1.4,CELL);
        if(tileT(wx+1,wy)>0) ctx.fillRect(sx+CELL-1.4,sy,1.4,CELL);
        ctx.globalAlpha=1;
      } else { // floor-ish
        ctx.fillStyle=theme.floor; ctx.fillRect(sx,sy,CELL+1,CELL+1);
        ctx.strokeStyle='rgba(255,255,255,.03)'; ctx.strokeRect(sx+.5,sy+.5,CELL-1,CELL-1);
        if(r>.84){ ctx.fillStyle='rgba(255,255,255,.045)'; ctx.fillRect(sx+r*14,sy+(1-r)*16,1.5,1.5); }
        else if(r>.74){ // hairline crack
          ctx.strokeStyle='rgba(0,0,0,.3)'; ctx.beginPath();
          ctx.moveTo(sx+r*10,sy+4); ctx.lineTo(sx+r*16,sy+CELL*.6); ctx.stroke();
        } else if(r<.05){ // faint conduit glow detail
          ctx.fillStyle=theme.edge; ctx.globalAlpha=.06+.03*Math.sin(now/700+r*40);
          ctx.fillRect(sx+CELL*.3,sy+CELL*.45,CELL*.4,1.5); ctx.globalAlpha=1;
        }
        if(t===4){ ctx.fillStyle='rgba(25,240,199,.06)'; ctx.fillRect(sx,sy,CELL,CELL);
          ctx.strokeStyle='rgba(25,240,199,.18)'; ctx.strokeRect(sx+3.5,sy+3.5,CELL-7,CELL-7); }
      }

      if(t===2){ // ore — shape varies by tier glyph
        var oc=cell.c||'#888';
        var pulse=.78+.22*Math.sin(now/280+r*9);
        var cx2=sx+CELL/2, cy2=sy+CELL/2;
        ctx.shadowColor=oc; ctx.shadowBlur=11*pulse;
        if(cell.g==='◇'){ // rare: star crystal + light beam
          ctx.globalAlpha=.16*pulse; ctx.fillStyle=oc;
          ctx.fillRect(cx2-2,sy-CELL*1.1,4,CELL*1.6);
          ctx.globalAlpha=1;
          ctx.fillStyle=oc+'99'; pathStar(cx2,cy2,CELL*.40*pulse); ctx.fill();
          ctx.fillStyle='#fff'; pathStar(cx2,cy2,CELL*.15); ctx.fill();
        } else if(cell.g==='◆'){ // mid: hex crystal
          ctx.fillStyle=oc+'90'; pathHex(cx2,cy2,CELL*.38*pulse); ctx.fill();
          ctx.fillStyle=oc; pathHex(cx2,cy2,CELL*.20); ctx.fill();
          ctx.fillStyle='rgba(255,255,255,.55)'; ctx.fillRect(cx2-1,cy2-CELL*.16,2,CELL*.12);
        } else { // common: rotated diamond
          ctx.save(); ctx.translate(cx2,cy2); ctx.rotate(Math.PI/4);
          ctx.fillStyle=oc+'88'; var s1=CELL*.36*pulse; ctx.fillRect(-s1/2,-s1/2,s1,s1);
          ctx.fillStyle=oc; var s2=CELL*.18; ctx.fillRect(-s2/2,-s2/2,s2,s2);
          ctx.restore();
        }
        ctx.shadowBlur=0;
        if(((now/600+r*7)%4)<.25){
          ctx.fillStyle='rgba(255,255,255,.9)';
          ctx.fillRect(cx2-CELL*.22+r*6,sy+CELL*.25,1.6,1.6);
        }
      } else if(t===3){ // exit shaft — light column + rising chevrons
        var ep=.6+.4*Math.sin(now/350);
        var bx=sx+CELL/2;
        var beam=ctx.createLinearGradient(0,sy-CELL*2.1,0,sy+CELL);
        beam.addColorStop(0,'rgba(59,207,99,0)');
        beam.addColorStop(1,'rgba(59,207,99,'+(0.16*ep+0.06)+')');
        ctx.fillStyle=beam; ctx.fillRect(bx-CELL*.34,sy-CELL*2.1,CELL*.68,CELL*3.1);
        ctx.shadowColor='#3bcf63'; ctx.shadowBlur=12*ep;
        ctx.strokeStyle='rgba(59,207,99,'+(0.4*ep)+')';
        ctx.beginPath(); ctx.arc(bx,sy+CELL/2,CELL*.40*ep+3,0,Math.PI*2); ctx.stroke();
        ctx.font='bold '+(CELL-8)+'px monospace'; ctx.textAlign='center'; ctx.textBaseline='middle';
        for(var ci=0;ci<3;ci++){
          var cp=((now/900)+ci/3)%1;
          ctx.fillStyle='rgba(59,207,99,'+(0.85*(1-cp))+')';
          ctx.fillText('↑',bx,sy+CELL/2-cp*CELL*1.6);
        }
        ctx.shadowBlur=0;
      }
    }
  }

  // ambient dust motes (drift through the lit cave)
  ctx.fillStyle='rgba(190,230,255,.5)';
  for(var mi=0;mi<motes.length;mi++){
    var M=motes[mi];
    M.x+=M.vx; M.y+=M.vy; M.p+=.02;
    if(M.x<cam.x-HALF-1) M.x+=VP+2; if(M.x>cam.x+HALF+1) M.x-=VP+2;
    if(M.y<cam.y-HALF-1) M.y+=VP+2; if(M.y>cam.y+HALF+1) M.y-=VP+2;
    var md=dist(M.x,M.y,anim.x,anim.y);
    var ma=Math.max(0,(1-md/4.5))*.30*(.6+.4*Math.sin(M.p));
    if(ma<=0.01) continue;
    ctx.globalAlpha=ma;
    ctx.fillRect((M.x-ox)*CELL+shx,(M.y-oy)*CELL+Math.sin(M.p)*2+shy,M.s,M.s);
  }
  ctx.globalAlpha=1;

  // auto-path preview dots
  if(path.length){
    var px=state.px, py=state.py;
    ctx.fillStyle='rgba(25,240,199,.4)';
    var D={up:[0,-1],down:[0,1],left:[-1,0],right:[1,0]};
    for(var i=0;i<path.length;i++){
      var dd=D[path[i]]; px+=dd[0]; py+=dd[1];
      var pr=1.8+.8*Math.sin(now/200-i*.7);
      ctx.beginPath(); ctx.arc((px-ox)*CELL+CELL/2+shx,(py-oy)*CELL+CELL/2+shy,pr,0,Math.PI*2); ctx.fill();
    }
  }

  var prx=(anim.x-ox)*CELL+CELL/2+shx, pry=(anim.y-oy)*CELL+CELL/2+shy;

  // smooth darkness — radial light around the player, map memory dims at range
  var lg=ctx.createRadialGradient(prx,pry,CELL*1.15,prx,pry,CELL*4.9);
  lg.addColorStop(0,'rgba(2,2,8,0)');
  lg.addColorStop(.55,'rgba(2,2,8,.30)');
  lg.addColorStop(1,'rgba(2,2,8,.85)');
  ctx.fillStyle=lg; ctx.fillRect(0,0,W,W);

  // warm headlamp glow (additive)
  ctx.globalCompositeOperation='lighter';
  var pg=ctx.createRadialGradient(prx,pry,0,prx,pry,CELL*2.5);
  pg.addColorStop(0,'rgba(25,240,199,.09)');
  pg.addColorStop(1,'rgba(25,240,199,0)');
  ctx.fillStyle=pg; ctx.fillRect(prx-CELL*2.5,pry-CELL*2.5,CELL*5,CELL*5);
  ctx.globalCompositeOperation='source-over';

  // scanner: static dashed range ring + expanding ping
  ctx.save();
  ctx.strokeStyle='rgba(25,240,199,'+(0.07+0.04*Math.sin(now/500))+')';
  ctx.setLineDash([4,7]); ctx.lineWidth=1;
  ctx.beginPath(); ctx.arc(prx,pry,sensor*CELL+CELL/2,0,Math.PI*2); ctx.stroke();
  ctx.restore();
  var pt=(now%2400)/2400;
  ctx.strokeStyle='rgba(25,240,199,'+(0.22*(1-pt))+')';
  ctx.lineWidth=1.2;
  ctx.beginPath(); ctx.arc(prx,pry,pt*(sensor*CELL+CELL/2),0,Math.PI*2); ctx.stroke();
  ctx.lineWidth=1;

  // hover highlight
  if(hover&&hover.wx>=0&&hover.wy>=0&&hover.wx<w&&hover.wy<h){
    var hc=grid[hover.wy][hover.wx];
    if(hc.t>0){
      var hx=(hover.wx-ox)*CELL+shx, hy=(hover.wy-oy)*CELL+shy;
      var isOre=hc.t===2;
      ctx.strokeStyle=isOre?(hc.c||'#19f0c7'):'rgba(255,255,255,.25)';
      ctx.lineWidth=isOre?1.6:1;
      ctx.strokeRect(hx+1.5,hy+1.5,CELL-3,CELL-3);
      if(isOre&&hc.n){
        ctx.font='600 10px sans-serif'; ctx.textAlign='center'; ctx.textBaseline='bottom';
        var label=hc.n, tw=ctx.measureText(label).width+10;
        var lx=Math.min(W-tw/2-2,Math.max(tw/2+2,hx+CELL/2));
        ctx.fillStyle='rgba(3,3,10,.88)';
        ctx.fillRect(lx-tw/2,hy-16,tw,13);
        ctx.strokeStyle=(hc.c||'#19f0c7')+'66'; ctx.lineWidth=1;
        ctx.strokeRect(lx-tw/2+.5,hy-15.5,tw-1,12);
        ctx.fillStyle=hc.c||'#c9d1e0';
        ctx.fillText(label,lx,hy-5);
      }
      ctx.lineWidth=1;
    }
  }

  // player drone — hover bob, orbiting rotor lights, heading tick
  var bob=Math.sin(now/300)*1.4;
  var pyd=pry+bob;
  var pp=.8+.2*Math.sin(now/220);
  ctx.fillStyle='rgba(0,0,0,.35)'; // ground shadow
  ctx.beginPath(); ctx.ellipse(prx,pry+CELL*.34,CELL*.22,CELL*.08,0,0,Math.PI*2); ctx.fill();
  ctx.shadowColor='#19f0c7'; ctx.shadowBlur=16*pp;
  ctx.fillStyle='rgba(25,240,199,.16)';
  ctx.beginPath(); ctx.arc(prx,pyd,CELL*.46,0,Math.PI*2); ctx.fill();
  ctx.fillStyle='#ffffff';
  ctx.beginPath(); ctx.arc(prx,pyd,CELL*.26,0,Math.PI*2); ctx.fill();
  ctx.fillStyle='#19f0c7';
  ctx.beginPath(); ctx.arc(prx,pyd,CELL*.26*.55*pp+1.5,0,Math.PI*2); ctx.fill();
  ctx.shadowBlur=0;
  for(var ri=0;ri<3;ri++){ // rotor lights
    var ra=now/260+ri*(Math.PI*2/3);
    ctx.fillStyle='rgba(255,255,255,.7)';
    ctx.beginPath(); ctx.arc(prx+Math.cos(ra)*CELL*.40,pyd+Math.sin(ra)*CELL*.40*.45,1.4,0,Math.PI*2); ctx.fill();
  }
  var HD={up:[0,-1],down:[0,1],left:[-1,0],right:[1,0]}[lastDir]||[0,1];
  ctx.fillStyle='#0a0a12';
  ctx.beginPath(); ctx.arc(prx+HD[0]*CELL*.13,pyd+HD[1]*CELL*.13,2.2,0,Math.PI*2); ctx.fill();

  // particles
  for(var pi=particles.length-1;pi>=0;pi--){
    var P=particles[pi];
    P.x+=P.vx; P.y+=P.vy; P.vy+=.045; P.life-=.025;
    if(P.life<=0){ particles.splice(pi,1); continue; }
    ctx.globalAlpha=Math.max(0,P.life);
    ctx.fillStyle=P.col;
    ctx.fillRect((P.x-ox)*CELL+shx,(P.y-oy)*CELL+shy,P.s,P.s);
  }
  ctx.globalAlpha=1;

  // floating texts
  for(var fi=floaters.length-1;fi>=0;fi--){
    var F=floaters[fi];
    F.dy-=.35; F.life-=.013;
    if(F.life<=0){ floaters.splice(fi,1); continue; }
    ctx.globalAlpha=Math.min(1,F.life*2);
    ctx.font='700 12px sans-serif'; ctx.textAlign='center';
    ctx.fillStyle='rgba(0,0,0,.6)';
    ctx.fillText(F.txt,(F.x-ox)*CELL+CELL/2+1+shx,(F.y-oy)*CELL+F.dy+1+shy);
    ctx.fillStyle=F.col;
    ctx.fillText(F.txt,(F.x-ox)*CELL+CELL/2+shx,(F.y-oy)*CELL+F.dy+shy);
  }
  ctx.globalAlpha=1;

  // entry flash
  if(flash>0.02){
    ctx.fillStyle='rgba(170,255,235,'+(flash*.8).toFixed(3)+')';
    ctx.fillRect(0,0,W,W);
  }

  // vignette
  ctx.fillStyle=vignette; ctx.fillRect(0,0,W,W);
}
requestAnimationFrame(loop);

function burst(wx,wy,col){
  for(var i=0;i<16;i++){
    var a=Math.random()*Math.PI*2, sp=.04+Math.random()*.09;
    particles.push({x:wx+.5,y:wy+.5,vx:Math.cos(a)*sp,vy:Math.sin(a)*sp-.05,col:i%3?col:'#ffffff',s:1.5+Math.random()*2.5,life:1});
  }
}

// ── Minimap (terrain memory only — never shows ore) ───────────────────────
function drawMini(){
  var mm=document.getElementById('mine-minimap');
  if(!mm||!state) return;
  var s=3, mw=state.w*s, mh=state.h*s;
  mm.width=mw; mm.height=mh;
  var c=mm.getContext('2d');
  c.fillStyle='rgba(3,3,8,.9)'; c.fillRect(0,0,mw,mh);
  for(var y=0;y<state.h;y++)for(var x=0;x<state.w;x++){
    var t=state.grid[y][x].t;
    if(t===-1) continue;
    if(t===0) c.fillStyle='#101024';
    else if(t===3) c.fillStyle='#3bcf63';
    else c.fillStyle='#1d1d40';
    c.fillRect(x*s,y*s,s,s);
  }
  c.fillStyle='#19f0c7';
  c.fillRect(state.px*s-1,state.py*s-1,s+2,s+2);
}

// ── HUD update ───────────────────────────────────────────────────────────
function updateHUD(){
  if(!state) return;
  var st=document.getElementById('hud-steps'); if(st) st.textContent=state.steps;
  var total=0; for(var k in state.collected) total+=state.collected[k];
  var oe=document.getElementById('hud-ore'); if(oe) oe.textContent=total;
  var btn=document.getElementById('btn-exit');
  if(btn) btn.style.display=state.on_exit?'':'none';

  var list=document.getElementById('mine-ore-list');
  if(list){
    if(!total){ list.innerHTML='<span class="muted" style="font-size:12px">Nothing yet — get close enough for your scanner to ping a deposit.</span>'; }
    else {
      var html='';
      for(var ot in state.collected){
        var od=oreDefs[ot]||{name:ot,col:'#888',glyph:'◈'};
        html+='<div class="ore-chip" style="color:'+od.col+';border-color:'+od.col+'40"><span>'+od.glyph+'</span>'
             +'<span style="color:var(--text)">'+od.name+'</span>'
             +'<span style="font-family:Orbitron,sans-serif;font-size:11px">×'+state.collected[ot]+'</span></div>';
      }
      list.innerHTML=html;
    }
  }
  var depthName=(depthNames[state.depth]||{}).name||'Depth '+state.depth;
  var hd=document.getElementById('hud-depth'); if(hd) hd.textContent=state.depth+' — '+depthName;
}

function showMsg(txt,col){
  var el=document.getElementById('mine-msg');
  if(!el) return;
  el.style.opacity='1'; el.style.color=col||'var(--accent)'; el.textContent=txt;
  if(msgTimer) clearTimeout(msgTimer);
  msgTimer=setTimeout(function(){el.style.opacity='0';},2800);
}

// ── AJAX ──────────────────────────────────────────────────────────────────
function minePost(data,cb){
  if(busy) return;
  busy=true;
  data.mine_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;path=[];showMsg('Network error','var(--neon2)');});
}

// ── Actions ──────────────────────────────────────────────────────────────
window.enterMine=function(depth){
  window._lastDepth=depth;
  minePost({mine_action:'enter',depth:depth},function(d){
    if(!d.ok){showMsg(d.err||'Error','var(--neon2)');return;}
    exitSeen=false; path=[]; pendingHarvest=null; particles=[]; floaters=[]; flash=1;
    state=null; themeDepth=0; setState(d.state);
    document.getElementById('mine-lobby').style.display='none';
    document.getElementById('mine-active').style.display='';
    document.getElementById('mine-result').style.display='none';
    sfx(180,.3,'sine',.06,70);
    showMsg('Dropped into '+((depthNames[state.depth]||{}).name||'the mine')+'. Find the exit shaft.','var(--accent)');
  });
};

window.doMove=function(dir){
  if(!state||busy) return;
  lastDir=dir;
  var fx=anim.x, fy=anim.y;
  minePost({mine_action:'move',dir:dir},function(d){
    if(!d.ok){
      path=[]; pendingHarvest=null;
      if((d.err||'')!=='Blocked.') showMsg(d.err||'Blocked','rgba(255,255,255,.4)');
      else sfx(90,.06,'square',.025);
      return;
    }
    setState(d.state);
    moveAnim={fx:fx,fy:fy,tx:state.px,ty:state.py,start:performance.now()};
    if(state.on_exit) showMsg('Exit shaft reached! Press ⬆ Exit to surface.','#3bcf63');
  });
};

window.doHarvestAdjacent=function(){
  if(!state) return;
  var px=state.px, py=state.py, grid=state.grid, w=state.w, h=state.h;
  var dirs=[[0,-1],[0,1],[-1,0],[1,0],[1,1],[-1,1],[1,-1],[-1,-1]];
  for(var i=0;i<dirs.length;i++){
    var nx=px+dirs[i][0], ny=py+dirs[i][1];
    if(nx>=0&&ny>=0&&nx<w&&ny<h&&grid[ny][nx].t===2){doHarvest(nx,ny);return;}
  }
  showMsg('No ore in reach. Follow the scanner pings.','rgba(255,255,255,.4)');
};

function doHarvest(tx,ty){
  if(busy) return;
  minePost({mine_action:'harvest',tx:tx,ty:ty},function(d){
    if(!d.ok){showMsg(d.err||'Error','var(--neon2)');return;}
    setState(d.state);
    if(d.mined){
      burst(d.mined.x,d.mined.y,d.mined.col||'#19f0c7');
      floaters.push({x:d.mined.x,y:d.mined.y,dy:0,txt:'+1 '+d.mined.name,col:d.mined.col||'#3bcf63',life:1});
      floaters.push({x:d.mined.x,y:d.mined.y,dy:14,txt:'-'+d.mined.cost+' Drive',col:'#e8a33d',life:.85});
      shake=5;
      sfx(520,.09,'square',.04); setTimeout(function(){sfx(780,.12,'square',.04);},70);
    }
    if(d.drive!==undefined){
      var hd=document.getElementById('hud-drive'); if(hd) hd.textContent=d.drive;
      var bar=document.getElementById('hud-drivebar');
      if(bar&&d.drive_max>0) bar.style.width=Math.min(100,Math.round(d.drive/d.drive_max*100))+'%';
    }
    var chips=document.querySelectorAll('#mine-ore-list .ore-chip');
    if(chips.length){ chips[chips.length-1].classList.add('pop'); }
  });
}

window.doExit=function(){
  if(!state||!state.on_exit) return;
  minePost({mine_action:'exit_mine'},function(d){
    if(!d.ok){showMsg(d.err||'Error','var(--neon2)');return;}
    if(d.complete){
      sfx(523,.12,'sine',.05); setTimeout(function(){sfx(659,.12,'sine',.05);},110); setTimeout(function(){sfx(784,.2,'sine',.05);},220);
      showResult(d);
    }
  });
};

window.leaveMine=function(){
  if(!confirm('Abandon this run? All extracted ore will be lost.')) return;
  minePost({mine_action:'leave'},function(d){
    state=null; path=[]; pendingHarvest=null; showLobby();
  });
};

function showResult(d){
  document.getElementById('mine-active').style.display='none';
  document.getElementById('mine-result').style.display='';
  var depthName=(depthNames[d.depth]||{}).name||'Depth '+d.depth;
  var oreDiv=document.getElementById('result-ore');
  var empty=document.getElementById('result-empty');
  var summary=d.summary||{};
  var keys=Object.keys(summary);
  var totalXp=0, totalOre=0;
  keys.forEach(function(k){ totalXp+=(summary[k].xp||0)*summary[k].qty; totalOre+=summary[k].qty; });
  // count-up animation on the summary line
  (function(){
    var el=document.getElementById('result-depth'), t0=performance.now(), DUR=700;
    function tick(n){
      var t=Math.min(1,(n-t0)/DUR), e=t*(2-t);
      el.textContent='Surfaced from '+depthName+' after '+d.steps+' steps — '
        +Math.round(totalOre*e)+' ore, +'+Math.round(totalXp*e)+' XP.';
      if(t<1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  })();
  if(!keys.length){oreDiv.innerHTML='';empty.style.display='';}
  else{
    empty.style.display='none';
    var html='';
    keys.forEach(function(k,idx){
      var o=summary[k];
      html+='<div class="ore-chip pop" style="color:'+o.col+';border-color:'+o.col+'40;padding:6px 14px;animation-delay:'+(idx*90)+'ms;box-shadow:0 0 14px '+o.col+'22">'
           +'<span style="font-size:16px;text-shadow:0 0 8px '+o.col+'">'+o.glyph+'</span>'
           +'<div><div style="font-size:11px;color:var(--text)">'+o.name+'</div>'
           +'<div style="font-family:Orbitron,sans-serif;font-size:14px;font-weight:700">×'+o.qty+'</div></div></div>';
    });
    oreDiv.innerHTML=html;
  }
  state=null;
}

window.showLobby=function(){
  document.getElementById('mine-lobby').style.display='';
  document.getElementById('mine-active').style.display='none';
  document.getElementById('mine-result').style.display='none';
};

// ── Auto-pathing (BFS over EXPLORED, passable tiles only) ─────────────────
function findPath(tx,ty){
  if(!state) return null;
  var w=state.w,h=state.h,grid=state.grid;
  var sx=state.px, sy=state.py;
  if(tx===sx&&ty===sy) return [];
  var prev={}, seen={}; seen[sx+','+sy]=true;
  var q=[[sx,sy]];
  var D=[['up',0,-1],['down',0,1],['left',-1,0],['right',1,0]];
  while(q.length){
    var cur=q.shift(), cx=cur[0], cy=cur[1];
    for(var i=0;i<4;i++){
      var nx=cx+D[i][1], ny=cy+D[i][2], key=nx+','+ny;
      if(nx<0||ny<0||nx>=w||ny>=h||seen[key]) continue;
      var t=grid[ny][nx].t;
      if(t<=0) continue; // fog or wall — only path through what you've explored
      seen[key]=true; prev[key]=[cx,cy,D[i][0]];
      if(nx===tx&&ny===ty){
        var out=[], k=key;
        while(prev[k]){ out.unshift(prev[k][2]); k=prev[k][0]+','+prev[k][1]; }
        return out;
      }
      q.push([nx,ny]);
    }
  }
  return null;
}

// ── Canvas pointer ─────────────────────────────────────────────────────────
function eventCell(e){
  var rect=canvas.getBoundingClientRect();
  var mx=(e.clientX-rect.left)*(W/rect.width);
  var my=(e.clientY-rect.top)*(W/rect.height);
  return {wx:Math.floor(cam.x-HALF+mx/CELL), wy:Math.floor(cam.y-HALF+my/CELL)};
}
canvas.addEventListener('mousemove',function(e){
  hover=state?eventCell(e):null;
  if(hover&&state&&hover.wx>=0&&hover.wy>=0&&hover.wx<state.w&&hover.wy<state.h){
    var t=state.grid[hover.wy][hover.wx].t;
    canvas.style.cursor=(t===2||t===3)?'pointer':(t===1||t===4)?'crosshair':'default';
  }
});
canvas.addEventListener('mouseleave',function(){hover=null;});
canvas.addEventListener('click',function(e){
  if(!state) return;
  var c=eventCell(e), wx=c.wx, wy=c.wy;
  if(wx<0||wy<0||wx>=state.w||wy>=state.h) return;
  var cell=state.grid[wy][wx];
  if(cell.t===-1||cell.t===0) return; // fog / wall
  var dx=wx-state.px, dy=wy-state.py;
  if(dx===0&&dy===0) return;
  path=[]; pendingHarvest=null;

  // adjacent ore → mine immediately
  if(cell.t===2&&Math.abs(dx)<=1&&Math.abs(dy)<=1){ doHarvest(wx,wy); return; }

  // visible ore further out → path to a neighbouring tile, then mine
  if(cell.t===2){
    var best=null,bestLen=1e9;
    var N=[[0,-1],[0,1],[-1,0],[1,0],[1,1],[-1,1],[1,-1],[-1,-1]];
    for(var i=0;i<N.length;i++){
      var ax=wx+N[i][0], ay=wy+N[i][1];
      if(ax<0||ay<0||ax>=state.w||ay>=state.h) continue;
      if(state.grid[ay][ax].t<=0) continue;
      var p=findPath(ax,ay);
      if(p&&p.length<bestLen){best=p;bestLen=p.length;}
    }
    if(best){ path=best; pendingHarvest={x:wx,y:wy}; if(!best.length){doHarvest(wx,wy);} }
    else showMsg('No clear route to that deposit.','rgba(255,255,255,.4)');
    return;
  }

  // floor / exit → auto-path
  var p2=findPath(wx,wy);
  if(p2&&p2.length) path=p2;
  else if(p2===null) showMsg('No known route — explore further.','rgba(255,255,255,.4)');
});

// ── Keyboard ─────────────────────────────────────────────────────────────
var KEYMAP={ArrowUp:'up',w:'up',W:'up',ArrowDown:'down',s:'down',S:'down',ArrowLeft:'left',a:'left',A:'left',ArrowRight:'right',d:'right',D:'right'};
document.addEventListener('keydown',function(e){
  if(!state||!document.body.contains(canvas)) return;
  var tag=document.activeElement?document.activeElement.tagName:'';
  if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT') return;
  var dir=KEYMAP[e.key];
  if(dir){ e.preventDefault(); path=[]; pendingHarvest=null; if(keysDown.indexOf(dir)===-1) keysDown.push(dir); }
  else if(e.key==='e'||e.key==='E'){ e.preventDefault(); doHarvestAdjacent(); }
  else if((e.key==='x'||e.key==='X')&&state.on_exit){ e.preventDefault(); doExit(); }
  else if(e.key==='Escape'){ path=[]; pendingHarvest=null; keysDown=[]; }
});
document.addEventListener('keyup',function(e){
  var dir=KEYMAP[e.key];
  if(dir){ var i=keysDown.indexOf(dir); if(i!==-1) keysDown.splice(i,1); }
});
window.addEventListener('blur',function(){ keysDown=[]; });

// ── Init ─────────────────────────────────────────────────────────────────
if(state){
  var s0=state; state=null; setState(s0);
  document.getElementById('mine-lobby').style.display='none';
  document.getElementById('mine-active').style.display='';
}

})();
</script>
