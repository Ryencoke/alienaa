<?php /* pages/mining.php — The Sump: interactive mine crawler */
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
  $ore=[];$cands=array_values(array_filter($fl,fn($c)=>!($c[0]===$sp[0]&&$c[1]===$sp[1])&&!($c[0]===$ex[0]&&$c[1]===$ex[1])));
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
      if((int)$pl['cycles']<$cost)throw new RuntimeException("Need {$cost} Drive to extract this.");
      $pdo->prepare('UPDATE players SET cycles=GREATEST(0,cycles-?) WHERE id=?')->execute([$cost,$plid]);
      $run['grid'][$ty][$tx]=1;unset($run['ore']["{$tx},{$ty}"]);
      $run['collected'][$ok]=($run['collected'][$ok]??0)+1;
      if($od['xp']>0)grant_xp($plid,$od['xp']);
      $_SESSION['mine_run']=$run;$pl=current_player();
      echo json_encode(['ok'=>true,'state'=>mine_to_client($run,$MINE_ORES),
        'msg'=>"Extracted {$od['name']}! (-{$cost} Drive)",'drive'=>(int)$pl['cycles'],'drive_max'=>(int)$pl['cycles_max']]);exit;
    }

    if ($act === 'exit_mine') {
      if($run['grid'][$run['py']][$run['px']]!==3)throw new RuntimeException('Not at the exit shaft.');
      $summary=[];
      foreach($run['collected'] as $ot=>$qty){
        $od=$MINE_ORES[$ot]??$MINE_ORES['scrap'];
        $pdo->prepare('INSERT INTO player_ore(player_id,ore_type,quantity)VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+?')->execute([$plid,$ot,$qty,$qty]);
        $summary[$ot]=['name'=>$od['name'],'qty'=>$qty,'col'=>$od['col'],'glyph'=>$od['glyph']];
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
$depthsJson   = json_encode(array_map(fn($d)=>['name'=>$d['name'],'col'=>$d['col']],$MINE_DEPTHS));
?>
<style>
#mine-wrap{max-width:520px;margin:0 auto}
#mine-canvas{display:block;background:#030306;border-radius:8px;cursor:crosshair;touch-action:none;border:1px solid rgba(25,240,199,.15);image-rendering:pixelated}
.mine-ctrl-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:var(--text);border-radius:6px;padding:8px 14px;cursor:pointer;font-size:14px;line-height:1;user-select:none;-webkit-user-select:none;transition:background .1s}
.mine-ctrl-btn:active{background:rgba(25,240,199,.15);border-color:var(--accent)}
.mine-ctrl-btn.harvest-btn{background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.3);color:var(--accent)}
.mine-ctrl-btn.exit-btn{background:rgba(59,207,99,.12);border-color:rgba(59,207,99,.35);color:#3bcf63}
.mine-level-card{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:14px;cursor:pointer;transition:border-color .15s,background .15s}
.mine-level-card:hover:not(.locked){border-color:rgba(25,240,199,.4);background:rgba(25,240,199,.04)}
.mine-level-card.locked{opacity:.45;cursor:not-allowed}
.ore-chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600}
#mine-msg{min-height:22px;font-size:12px;text-align:center;transition:opacity .3s}
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
  </div>
</div>
<div class="panel">
  <h3 style="margin-top:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px">Select Depth</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
    <?php foreach ($MINE_DEPTHS as $d => $cfg):
      $locked = $miningLevel < $cfg['req'];
    ?>
    <div class="mine-level-card <?= $locked ? 'locked' : '' ?>" onclick="<?= !$locked ? "enterMine($d)" : '' ?>">
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
      <div style="display:flex;gap:16px;align-items:center">
        <div style="font-size:11px;color:var(--muted)">DEPTH <span id="hud-depth" style="font-family:'Orbitron',sans-serif;color:var(--accent);font-weight:700">?</span></div>
        <div style="font-size:11px;color:var(--muted)">DRIVE <span id="hud-drive" style="font-family:'Orbitron',sans-serif;color:#e8a33d;font-weight:700"><?= (int)$player['cycles'] ?></span></div>
        <div style="font-size:11px;color:var(--muted)">STEPS <span id="hud-steps" style="font-family:'Orbitron',sans-serif;color:var(--text);font-weight:700">0</span></div>
        <div style="font-size:11px;color:var(--muted)">ORE <span id="hud-ore" style="font-family:'Orbitron',sans-serif;color:#3bcf63;font-weight:700">0</span></div>
      </div>
      <button onclick="leaveMine()" style="font-size:11px;padding:4px 10px;background:transparent;border:1px solid rgba(255,45,149,.3);color:var(--neon2);border-radius:4px;cursor:pointer">✕ Abandon</button>
    </div>
  </div>

  <!-- Message bar -->
  <div id="mine-msg" class="muted" style="margin-bottom:6px;padding:0 4px">&nbsp;</div>

  <!-- Canvas -->
  <div style="text-align:center;margin-bottom:8px">
    <canvas id="mine-canvas" width="340" height="340"></canvas>
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
      <span class="muted" style="font-size:12px">Nothing yet — find ore and mine it.</span>
    </div>
  </div>

  <!-- Legend -->
  <div style="display:flex;justify-content:center;gap:14px;flex-wrap:wrap;padding:8px 0;font-size:10px;color:var(--muted)">
    <span>&#9679; You</span>
    <span style="color:#3bcf63">⬆ Exit</span>
    <span style="color:var(--muted)">◈ Ore</span>
    <span style="opacity:.4">░ Fog</span>
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
  <button onclick="showLobby()" class="btn btn-primary">⬆ Return to Surface</button>
</div>
</div>

<script>
(function(){
'use strict';

var CELL = 20, VP = 17, HALF = Math.floor(VP/2);
var canvas = document.getElementById('mine-canvas');
var ctx = canvas.getContext('2d');
var state = <?= $initialState ?>;
var oreDefs = <?= $oreDefsJson ?>;
var depthNames = <?= $depthsJson ?>;
var busy = false;
var msgTimer = null;

// ── Render ────────────────────────────────────────────────────────────────
function render() {
  if (!state) return;
  ctx.clearRect(0,0,340,340);
  var px=state.px, py=state.py, grid=state.grid, w=state.w, h=state.h;
  var vpox = px - HALF, vpoy = py - HALF;

  for (var vy=0; vy<VP; vy++) {
    for (var vx=0; vx<VP; vx++) {
      var wx=vpox+vx, wy=vpoy+vy;
      var cx=vx*CELL, cy=vy*CELL;

      // Out of bounds
      if (wx<0||wy<0||wx>=w||wy>=h) { ctx.fillStyle='#030306'; ctx.fillRect(cx,cy,CELL,CELL); continue; }

      var cell=grid[wy][wx];
      var t=cell.t;

      // Fog
      if (t===-1) {
        ctx.fillStyle='#030306';
        ctx.fillRect(cx,cy,CELL,CELL);
        // dim border hint
        ctx.strokeStyle='rgba(255,255,255,.02)';ctx.strokeRect(cx+.5,cy+.5,CELL-1,CELL-1);
        continue;
      }

      // Base tile
      if (t===0) { // wall
        ctx.fillStyle='#0b0b1a'; ctx.fillRect(cx,cy,CELL,CELL);
        ctx.fillStyle='#0f0f22'; ctx.fillRect(cx+1,cy+1,CELL-2,CELL-2);
        ctx.fillStyle='rgba(255,255,255,.025)';
        ctx.fillRect(cx+1,cy+1,CELL-2,1); ctx.fillRect(cx+1,cy+1,1,CELL-2);
      } else { // floor, ore, exit, spawn
        ctx.fillStyle='#0d0d24'; ctx.fillRect(cx,cy,CELL,CELL);
        ctx.strokeStyle='rgba(255,255,255,.04)'; ctx.strokeRect(cx+.5,cy+.5,CELL-1,CELL-1);
      }

      if (t===2) { // ore
        var oc=cell.c||'#888';
        ctx.shadowColor=oc; ctx.shadowBlur=8;
        ctx.fillStyle=oc+'99';
        var s=CELL*.42, os=(CELL-s)/2;
        ctx.fillRect(cx+os,cy+os,s,s);
        ctx.fillStyle=oc;
        var s2=CELL*.22, os2=(CELL-s2)/2;
        ctx.fillRect(cx+os2,cy+os2,s2,s2);
        ctx.shadowBlur=0;
      } else if (t===3) { // exit
        ctx.shadowColor='#3bcf63'; ctx.shadowBlur=12;
        ctx.fillStyle='#3bcf63'; ctx.font='bold '+(CELL-4)+'px monospace';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        ctx.fillText('↑',cx+CELL/2,cy+CELL/2+1);
        ctx.shadowBlur=0;
      } else if (t===4) { // spawn mark
        ctx.fillStyle='rgba(25,240,199,.08)'; ctx.fillRect(cx,cy,CELL,CELL);
      }

      // Player
      if (wx===px && wy===py) {
        var r=CELL*.28;
        ctx.shadowColor='#19f0c7'; ctx.shadowBlur=14;
        ctx.fillStyle='#ffffff';
        ctx.beginPath(); ctx.arc(cx+CELL/2,cy+CELL/2,r,0,Math.PI*2); ctx.fill();
        ctx.fillStyle='#19f0c7';
        ctx.beginPath(); ctx.arc(cx+CELL/2,cy+CELL/2,r*.55,0,Math.PI*2); ctx.fill();
        ctx.shadowBlur=0;
      }
    }
  }
}

// ── HUD update ───────────────────────────────────────────────────────────
function updateHUD() {
  if (!state) return;
  var d = document.getElementById('hud-depth');
  if (d) d.textContent = state.depth;
  var st = document.getElementById('hud-steps');
  if (st) st.textContent = state.steps;
  var total=0; for(var k in state.collected) total+=state.collected[k];
  var oe=document.getElementById('hud-ore'); if(oe) oe.textContent=total;

  var btn=document.getElementById('btn-exit');
  if(btn) btn.style.display = state.on_exit ? '' : 'none';

  // Collected ore
  var list=document.getElementById('mine-ore-list');
  if (list) {
    if (!total) { list.innerHTML='<span class="muted" style="font-size:12px">Nothing yet — find ore and mine it.</span>'; }
    else {
      var html='';
      for (var ot in state.collected) {
        var od=oreDefs[ot]||{name:ot,col:'#888',glyph:'◈'};
        html+='<div class="ore-chip" style="color:'+od.col+';border-color:'+od.col+'40"><span>'+od.glyph+'</span>'
             +'<span style="color:var(--text)">'+od.name+'</span>'
             +'<span style="font-family:Orbitron,sans-serif;font-size:11px">×'+state.collected[ot]+'</span></div>';
      }
      list.innerHTML=html;
    }
  }

  var depthName = (depthNames[state.depth]||{}).name||'Depth '+state.depth;
  var hd=document.getElementById('hud-depth'); if(hd) hd.textContent=state.depth+' — '+depthName;
}

function showMsg(txt, col) {
  var el=document.getElementById('mine-msg');
  if (!el) return;
  el.style.opacity='1'; el.style.color=col||'var(--accent)'; el.textContent=txt;
  if (msgTimer) clearTimeout(msgTimer);
  msgTimer=setTimeout(function(){el.style.opacity='0';},2800);
}

// ── AJAX ──────────────────────────────────────────────────────────────────
function minePost(data, cb) {
  if (busy) return;
  busy=true;
  data.mine_ajax=1;
  var fd=new FormData();
  for(var k in data) fd.append(k,data[k]);
  fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){busy=false;cb(d);})
    .catch(function(){busy=false;showMsg('Network error','var(--neon2)');});
}

// ── Actions ──────────────────────────────────────────────────────────────
window.enterMine = function(depth) {
  minePost({mine_action:'enter',depth:depth},function(d){
    if (!d.ok){showMsg(d.err||'Error','var(--neon2)');return;}
    state=d.state;
    document.getElementById('mine-lobby').style.display='none';
    document.getElementById('mine-active').style.display='';
    document.getElementById('mine-result').style.display='none';
    render(); updateHUD();
    showMsg('Dropped into '+((depthNames[state.depth]||{}).name||'the mine')+'. Find the exit shaft.','var(--accent)');
    canvas.focus();
  });
};

window.doMove = function(dir) {
  if (!state) return;
  minePost({mine_action:'move',dir:dir},function(d){
    if (!d.ok){showMsg(d.err||'Blocked','rgba(255,255,255,.4)');return;}
    state=d.state; render(); updateHUD();
    if (state.on_exit) showMsg('Exit shaft found! Press ⬆ Exit to surface.','#3bcf63');
  });
};

window.doHarvestAdjacent = function() {
  if (!state) return;
  var px=state.px, py=state.py, grid=state.grid, w=state.w, h=state.h;
  var dirs=[[0,-1],[0,1],[-1,0],[1,0],[1,1],[-1,1],[1,-1],[-1,-1]];
  for (var i=0;i<dirs.length;i++) {
    var nx=px+dirs[i][0], ny=py+dirs[i][1];
    if(nx>=0&&ny>=0&&nx<w&&ny<h&&grid[ny][nx].t===2){doHarvest(nx,ny);return;}
  }
  showMsg('No ore adjacent. Move closer.','rgba(255,255,255,.4)');
};

function doHarvest(tx, ty) {
  minePost({mine_action:'harvest',tx:tx,ty:ty},function(d){
    if (!d.ok){showMsg(d.err||'Error','var(--neon2)');return;}
    state=d.state; render(); updateHUD();
    if (d.msg) showMsg(d.msg,'#3bcf63');
    if (d.drive !== undefined) {
      var hd=document.getElementById('hud-drive'); if(hd) hd.textContent=d.drive;
    }
  });
}

window.doExit = function() {
  if (!state||!state.on_exit) return;
  minePost({mine_action:'exit_mine'},function(d){
    if (!d.ok){showMsg(d.err||'Error','var(--neon2)');return;}
    if (d.complete) showResult(d);
  });
};

window.leaveMine = function() {
  if (!confirm('Abandon this run? All extracted ore will be lost.')) return;
  minePost({mine_action:'leave'},function(d){
    state=null; showLobby();
  });
};

function showResult(d) {
  document.getElementById('mine-active').style.display='none';
  document.getElementById('mine-result').style.display='';
  var depthName=(depthNames[d.depth]||{}).name||'Depth '+d.depth;
  document.getElementById('result-depth').textContent='Surfaced from '+depthName+' after '+d.steps+' steps.';
  var oreDiv=document.getElementById('result-ore');
  var empty=document.getElementById('result-empty');
  var summary=d.summary||{};
  var keys=Object.keys(summary);
  if (!keys.length){oreDiv.innerHTML='';empty.style.display='';}
  else {
    empty.style.display='none';
    var html='';
    keys.forEach(function(k){
      var o=summary[k];
      html+='<div class="ore-chip" style="color:'+o.col+';border-color:'+o.col+'40;padding:6px 14px">'
           +'<span style="font-size:16px">'+o.glyph+'</span>'
           +'<div><div style="font-size:11px;color:var(--text)">'+o.name+'</div>'
           +'<div style="font-family:Orbitron,sans-serif;font-size:14px;font-weight:700">×'+o.qty+'</div></div></div>';
    });
    oreDiv.innerHTML=html;
  }
  state=null;
}

window.showLobby = function() {
  document.getElementById('mine-lobby').style.display='';
  document.getElementById('mine-active').style.display='none';
  document.getElementById('mine-result').style.display='none';
};

// ── Canvas click ──────────────────────────────────────────────────────────
canvas.addEventListener('click', function(e){
  if (!state) return;
  var rect=canvas.getBoundingClientRect();
  var sx=canvas.width/rect.width, sy=canvas.height/rect.height;
  var vx=Math.floor((e.clientX-rect.left)*sx/CELL);
  var vy=Math.floor((e.clientY-rect.top)*sy/CELL);
  var wx=(state.px-HALF)+vx, wy=(state.py-HALF)+vy;
  if(wx<0||wy<0||wx>=state.w||wy>=state.h) return;
  var dx=wx-state.px, dy=wy-state.py;
  if(dx===0&&dy===0) return;
  var cell=state.grid[wy][wx];
  if (cell.t===-1||cell.t===0) return; // fog/wall
  if (Math.abs(dx)<=1&&Math.abs(dy)<=1) {
    if (cell.t===2) { doHarvest(wx,wy); return; }
    // Move toward clicked tile (cardinal step)
    if (Math.abs(dx)>Math.abs(dy)) doMove(dx>0?'right':'left');
    else doMove(dy>0?'down':'up');
  } else {
    // Click on distant tile: step toward it
    if (Math.abs(dx)>Math.abs(dy)) doMove(dx>0?'right':'left');
    else doMove(dy>0?'down':'up');
  }
});

// ── Keyboard ─────────────────────────────────────────────────────────────
document.addEventListener('keydown',function(e){
  if (!state||document.activeElement&&document.activeElement.tagName==='INPUT') return;
  var k=e.key;
  if(k==='ArrowUp'||k==='w'||k==='W'){e.preventDefault();doMove('up');}
  else if(k==='ArrowDown'||k==='s'||k==='S'){e.preventDefault();doMove('down');}
  else if(k==='ArrowLeft'||k==='a'||k==='A'){e.preventDefault();doMove('left');}
  else if(k==='ArrowRight'||k==='d'||k==='D'){e.preventDefault();doMove('right');}
  else if(k==='e'||k==='E'){e.preventDefault();doHarvestAdjacent();}
  else if((k==='x'||k==='X')&&state.on_exit){e.preventDefault();doExit();}
});

// ── Init ─────────────────────────────────────────────────────────────────
if (state) {
  document.getElementById('mine-lobby').style.display='none';
  document.getElementById('mine-active').style.display='';
  render(); updateHUD();
}

})();
</script>
