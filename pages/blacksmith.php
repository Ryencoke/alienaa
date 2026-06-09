<?php /* pages/blacksmith.php — The Forge: weapons and armor shop */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Ensure purchase table exists
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS blacksmith_owned (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    player_id  INT NOT NULL,
    item_code  VARCHAR(64) NOT NULL,
    bought_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_player_item (player_id, item_code),
    KEY idx_player (player_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {}

// Catalog  [code, name, icon, type, sub, atk, def, spd, price, desc]
$CATALOG = [
  ['mono_blade',    'Mono-Edge Blade',     '&#9876;',  'weapon', 'Melee',    3,  0, 1, 800,   'A monomolecular-edged short blade. Whisper-quiet, lethal up close.'],
  ['plasma_pistol', 'Plasma Pistol',       '&#128299;','weapon', 'Ranged',   5,  0, 0, 2200,  'Single-shot plasma rounds. Standard Sprawl enforcer loadout.'],
  ['shock_baton',   'Shock Baton',         '&#9889;',  'weapon', 'Melee',    4,  2, 0, 3500,  'Electromagnetic stunner. Effective crowd control with a nasty bite.'],
  ['arc_rifle',     'Arc Rifle',           '&#127775;','weapon', 'Ranged',   9,  0,-1, 7500,  'High-voltage pulse weapon. Expensive, loud, absolutely devastating.'],
  ['neuro_injector','Neurotoxin Injector', '&#128300;','weapon', 'Stealth',  6,  0, 3, 5000,  'Fast-acting synthetic toxin. Hits before they realize what happened.'],
  ['combat_vest',   'Combat Vest',         '&#129413;','armor',  'Light',    0,  3, 1, 600,   'Lightweight ballistic weave. Lets you move fast and take a hit.'],
  ['riot_shell',    'Riot Shell',          '&#128737;','armor',  'Medium',   0,  7, 0, 2800,  'Hardened polymer shell. Standard issue for perimeter security.'],
  ['exo_frame',     'Exo-Frame',           '&#129302;','armor',  'Heavy',    1, 12,-1, 6000,  'Powered exoskeletal rig. Near-impenetrable but slows your footwork.'],
  ['camo_wraps',    'Camo Wraps',          '&#129399;','armor',  'Stealth',  0,  4, 4, 4200,  'Adaptive camouflage fabric. Hard to spot, harder to hit.'],
  ['neural_weave',  'Neural Weave',        '&#129504;','armor',  'Tech',     0,  9, 2, 9500,  'Bio-integrated smart armor. Predicts incoming strikes before they land.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
  $code = $_POST['item_code'] ?? '';
  $item = null;
  foreach ($CATALOG as $c) { if ($c[0] === $code) { $item = $c; break; } }
  try {
    if (!$item) throw new RuntimeException('Item not found.');
    $price = $item[8];
    if ((int)$player['creds_pocket'] < $price) throw new RuntimeException('Not enough creds. You need ' . number_format($price) . ' &#9670;');
    $pdo->prepare('INSERT IGNORE INTO blacksmith_owned (player_id, item_code) VALUES (?,?)')->execute([$pid, $code]);
    if ($pdo->rowCount() < 1) throw new RuntimeException('You already own that item.');
    $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ?')->execute([$price, $pid]);
    $player = current_player();
    $msg = 'Purchased: ' . e($item[1]) . '. Check your Stash.';
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

// Load owned items
$ownedQ = $pdo->prepare('SELECT item_code FROM blacksmith_owned WHERE player_id = ?');
$ownedQ->execute([$pid]);
$owned = array_flip($ownedQ->fetchAll(PDO::FETCH_COLUMN));

$tab = $_GET['tab'] ?? 'weapons';
if (!in_array($tab, ['weapons','armor'], true)) $tab = 'weapons';
?>
<div class="panel">
  <h2>&#9874; The Forge &mdash; Blacksmith</h2>
  <p class="muted" style="text-align:center;margin-top:-8px">Forged in the underbelly. Tested in the Sprawl. No returns.</p>
  <?php if ($msg): ?><div class="flash flash-ok"><?= $msg ?></div><?php endif; ?>
  <div style="text-align:center;margin:8px 0">
    <span class="muted" style="font-size:12px">Creds:&nbsp;</span>
    <span id="bs-creds" style="font-family:'Orbitron',sans-serif;font-weight:bold;color:var(--accent);font-size:1.3rem"><?= number_format($player['creds_pocket']) ?></span>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:6px;justify-content:center;margin-bottom:14px">
  <a href="index.php?p=blacksmith&tab=weapons"
     class="daemon-tab<?= $tab==='weapons'?' active':'' ?>">&#9876; Weapons</a>
  <a href="index.php?p=blacksmith&tab=armor"
     class="daemon-tab<?= $tab==='armor'?' active':'' ?>">&#129413; Armor</a>
</div>

<!-- Detail panel (JS-driven) -->
<div class="shop-detail" id="bs-detail" style="display:none">
  <div class="sd-head">
    <div class="sd-ic" id="bs-d-ic"></div>
    <div class="sd-info">
      <div class="sd-name" id="bs-d-name"></div>
      <div class="sd-type" id="bs-d-type"></div>
      <div class="sd-desc" id="bs-d-desc"></div>
    </div>
  </div>
  <div class="sd-stats" id="bs-d-stats"></div>
  <div class="sd-footer">
    <div class="sd-price" id="bs-d-price"></div>
    <form method="post" id="bs-buy-form" style="margin:0">
      <input type="hidden" name="action" value="buy">
      <input type="hidden" name="item_code" id="bs-buy-code">
      <button type="submit" id="bs-buy-btn">Buy</button>
    </form>
  </div>
</div>

<!-- Item grid -->
<div class="panel">
  <div class="shop-grid" id="bs-grid">
    <?php foreach ($CATALOG as $c):
      if ($tab === 'weapons' && $c[3] !== 'weapon') continue;
      if ($tab === 'armor'   && $c[3] !== 'armor')  continue;
      $isOwned = isset($owned[$c[0]]);
    ?>
    <div class="shop-card<?= $isOwned ? ' owned-card' : '' ?>"
         data-code="<?= e($c[0]) ?>"
         data-ic="<?= $c[2] ?>"
         data-name="<?= e($c[1]) ?>"
         data-type="<?= e(ucfirst($c[3]).' &mdash; '.$c[4]) ?>"
         data-desc="<?= e($c[9]) ?>"
         data-atk="<?= (int)$c[5] ?>"
         data-def="<?= (int)$c[6] ?>"
         data-spd="<?= (int)$c[7] ?>"
         data-price="<?= (int)$c[8] ?>"
         data-owned="<?= $isOwned ? '1' : '0' ?>">
      <div class="sc-ic"><?= $c[2] ?></div>
      <div class="sc-nm"><?= e($c[1]) ?></div>
      <div class="sc-pr"><?= number_format($c[8]) ?> creds</div>
      <?php if ($isOwned): ?><div class="sc-owned">&#10003; Owned</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<p class="muted" style="text-align:center"><a href="index.php?p=city">&larr; Back to The Sprawl</a></p>

<script>
(function(){
  var detail=document.getElementById('bs-detail');
  var grid=document.getElementById('bs-grid');
  if(!grid||!detail) return;
  var sel=null;
  function stat(label, val){
    if(val===0) return '';
    var cls=val>0?'sv-pos':'sv-neg';
    return '<div class="sd-stat"><span class="sl">'+label+':</span> <span class="'+cls+'">'+(val>0?'+':'')+val+'</span></div>';
  }
  grid.addEventListener('click',function(e){
    var card=e.target.closest('.shop-card'); if(!card) return;
    if(sel) sel.classList.remove('selected');
    card.classList.add('selected'); sel=card;
    document.getElementById('bs-d-ic').innerHTML=card.dataset.ic;
    document.getElementById('bs-d-name').textContent=card.dataset.name;
    document.getElementById('bs-d-type').innerHTML=card.dataset.type;
    document.getElementById('bs-d-desc').textContent=card.dataset.desc;
    var atk=parseInt(card.dataset.atk), def=parseInt(card.dataset.def), spd=parseInt(card.dataset.spd);
    document.getElementById('bs-d-stats').innerHTML=stat('ATK',atk)+stat('DEF',def)+stat('SPD',spd);
    var price=parseInt(card.dataset.price);
    document.getElementById('bs-d-price').textContent=price.toLocaleString()+' creds';
    document.getElementById('bs-buy-code').value=card.dataset.code;
    var btn=document.getElementById('bs-buy-btn');
    if(card.dataset.owned==='1'){
      btn.textContent='&#10003; Already Owned'; btn.disabled=true; btn.style.opacity='0.5';
    } else {
      btn.textContent='Buy'; btn.disabled=false; btn.style.opacity='1';
    }
    detail.style.display='block';
    detail.scrollIntoView({behavior:'smooth',block:'nearest'});
  });
})();
</script>
