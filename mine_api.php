<?php /* mine_api.php — AJAX endpoint for the mine crawler */
require 'config.php';
require 'lib.php';
csrf_guard();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$player = current_player();
if (!$player) { http_response_code(401); echo json_encode(['ok'=>false,'err'=>'auth']); exit; }
$pdo = db();
$pid = (int)$player['id'];

if (!function_exists('grant_xp')) {
  function grant_xp($pid, $amount) {
    $p2 = db();
    $r = $p2->prepare('SELECT level,xp,xp_next FROM players WHERE id=?'); $r->execute([$pid]); $p = $r->fetch();
    if (!$p) return;
    $lv=(int)$p['level']; $xp=(int)$p['xp']+$amount; $nx=(int)$p['xp_next']; $g=0;
    while($xp>=$nx&&$lv<999){$xp-=$nx;$lv++;$nx=(int)round($nx*1.5);$g++;}
    $p2->prepare('UPDATE players SET level=?,xp=?,xp_next=? WHERE id=?')->execute([$lv,$xp,$nx,$pid]);
    if($g>0) try{$p2->prepare('INSERT INTO player_stats(pid,unspent)VALUES(?,?)ON DUPLICATE KEY UPDATE unspent=unspent+?')->execute([$pid,$g*5,$g*5]);}catch(Throwable $e){}
  }
}

try { $pdo->exec('CREATE TABLE IF NOT EXISTS player_ore (
  id INT AUTO_INCREMENT PRIMARY KEY, player_id INT NOT NULL, ore_type VARCHAR(32) NOT NULL,
  quantity INT NOT NULL DEFAULT 0, UNIQUE KEY uq_po (player_id, ore_type), KEY idx_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch(Throwable $e) {}

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
function mine_to_client(array $run,array $ores):array {
  $cg=[];for($y=0;$y<$run['h'];$y++){$cg[$y]=[];for($x=0;$x<$run['w'];$x++){
    if(!$run['rev'][$y][$x]){$cg[$y][$x]=['t'=>-1];continue;}
    $t=$run['grid'][$y][$x];$cell=['t'=>$t];
    if($t===2){$ot=$run['ore']["{$x},{$y}"]??'scrap';$od=$ores[$ot]??$ores['scrap'];$cell+=['o'=>$ot,'c'=>$od['col'],'g'=>$od['glyph'],'n'=>$od['name']];}
    $cg[$y][$x]=$cell;
  }}
  return ['grid'=>$cg,'px'=>$run['px'],'py'=>$run['py'],'w'=>$run['w'],'h'=>$run['h'],
          'depth'=>$run['depth'],'collected'=>$run['collected'],'steps'=>$run['steps'],
          'on_exit'=>($run['grid'][$run['py']][$run['px']]===3)];
}

$act = $_POST['mine_action'] ?? '';
$run = $_SESSION['mine_run'] ?? null;

try {
  if ($act === 'enter') {
    $d = max(1,min(5,(int)($_POST['depth']??1)));
    $sq=$pdo->prepare("SELECT FLOOR(ps.points/100) FROM player_skills ps JOIN skills s ON s.id=ps.skill_id WHERE ps.player_id=? AND s.code='drone'");
    $sq->execute([$pid]); $droneLv=(int)($sq->fetchColumn()?:0);
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
    if($nx<0||$ny<0||$nx>=$run['w']||$ny>=$run['h']||$run['grid'][$ny][$nx]===0) throw new RuntimeException('Blocked.');
    $run['px']=$nx;$run['py']=$ny;$run['steps']++;
    mine_reveal($run['rev'],$nx,$ny,$run['w'],$run['h']);
    $_SESSION['mine_run']=$run;
    echo json_encode(['ok'=>true,'state'=>mine_to_client($run,$MINE_ORES)]);exit;
  }

  if ($act === 'harvest') {
    $tx=(int)($_POST['tx']??-1);$ty=(int)($_POST['ty']??-1);
    if(abs($tx-$run['px'])>1||abs($ty-$run['py'])>1||($tx===$run['px']&&$ty===$run['py'])) throw new RuntimeException('Too far away.');
    if($tx<0||$ty<0||$tx>=$run['w']||$ty>=$run['h']||$run['grid'][$ty][$tx]!==2) throw new RuntimeException('No ore here.');
    $ok=$run['ore']["{$tx},{$ty}"]??'scrap';$od=$MINE_ORES[$ok]??$MINE_ORES['scrap'];
    $cost=(int)ceil($od['drive']*$MINE_DEPTHS[$run['depth']]['dmult']);
    $freshQ=$pdo->prepare('SELECT cycles,cycles_max FROM players WHERE id=?');$freshQ->execute([$pid]);$plrow=$freshQ->fetch();
    if((int)$plrow['cycles']<$cost) throw new RuntimeException("Need {$cost} Drive to extract this.");
    $pdo->prepare('UPDATE players SET cycles=GREATEST(0,cycles-?) WHERE id=?')->execute([$cost,$pid]);
    $run['grid'][$ty][$tx]=1;unset($run['ore']["{$tx},{$ty}"]);
    $run['collected'][$ok]=($run['collected'][$ok]??0)+1;
    if($od['xp']>0) grant_xp($pid,$od['xp']);
    $_SESSION['mine_run']=$run;
    $freshQ->execute([$pid]);$plrow=$freshQ->fetch();
    echo json_encode(['ok'=>true,'state'=>mine_to_client($run,$MINE_ORES),
      'msg'=>"Extracted {$od['name']}! (-{$cost} Drive)",'drive'=>(int)$plrow['cycles'],'drive_max'=>(int)$plrow['cycles_max']]);exit;
  }

  if ($act === 'exit_mine') {
    if($run['grid'][$run['py']][$run['px']]!==3) throw new RuntimeException('Not at the exit shaft.');
    $summary=[];
    foreach($run['collected'] as $ot=>$qty){
      $od=$MINE_ORES[$ot]??$MINE_ORES['scrap'];
      $pdo->prepare('INSERT INTO player_ore(player_id,ore_type,quantity)VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')->execute([$pid,$ot,$qty,$qty]);
      $summary[$ot]=['name'=>$od['name'],'qty'=>$qty,'col'=>$od['col'],'glyph'=>$od['glyph']];
    }
    $depth=$run['depth'];$steps=$run['steps'];
    unset($_SESSION['mine_run']);
    echo json_encode(['ok'=>true,'complete'=>true,'summary'=>$summary,'depth'=>$depth,'steps'=>$steps]);exit;
  }

  if ($act === 'leave') { unset($_SESSION['mine_run']); echo json_encode(['ok'=>true,'left'=>true]); exit; }

  throw new RuntimeException('Unknown action.');
} catch (Throwable $e) { echo json_encode(['ok'=>false,'err'=>$e->getMessage()]); }
