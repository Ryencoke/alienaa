<?php /* pages/boutique.php — Chrome Boutique: cosmetic wardrobe for your avatar.
   The base look (male/female body) is driven entirely by the player's
   Account gender setting, not a separate picker here. Placeholder rendering
   only (no real sprite art yet) — see lib.php's render_avatar_inner() for
   the single choke point that'll need updating once real art exists. */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$msgErr = false;

// Schema — equip state lives directly on players (see lib.php's
// render_avatar_inner()) so the sidebar/hero/profile avatars never need an
// extra query or join; only this page needs to self-heal the columns.
try { $pdo->exec("ALTER TABLE players ADD COLUMN equip_hat    VARCHAR(32) NULL DEFAULT NULL AFTER gender"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE players ADD COLUMN equip_jacket VARCHAR(32) NULL DEFAULT NULL AFTER equip_hat"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE players ADD COLUMN equip_pants  VARCHAR(32) NULL DEFAULT NULL AFTER equip_jacket"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE players ADD COLUMN equip_shoes  VARCHAR(32) NULL DEFAULT NULL AFTER equip_pants"); } catch (Throwable $e) {}
ensure_player_cosmetics_table($pdo);

$CATALOG = boutique_catalog();
$BC_BY_CODE = [];
foreach ($CATALOG as $c) $BC_BY_CODE[$c[0]] = $c;

// Slot -> players column. Validated against this whitelist before ever
// touching a column name in SQL — the value stays a bound parameter either way.
$SLOT_COLS   = ['hat' => 'equip_hat', 'jacket' => 'equip_jacket', 'pants' => 'equip_pants', 'shoes' => 'equip_shoes'];
$SLOT_LABELS = ['hat' => '&#129504; Hats', 'jacket' => '&#129513; Jackets', 'pants' => '&#128096; Pants', 'shoes' => '&#129399; Shoes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'buy') {
      $code = $_POST['item_code'] ?? '';
      $item = $BC_BY_CODE[$code] ?? null;
      if (!$item) throw new RuntimeException('Item not found.');
      $sexReq = $item[4];
      $mySexNow = $player['gender'] ?? '';
      // Server-side sex-restriction check — not just a UI filter.
      if ($sexReq !== 'U' && $sexReq !== $mySexNow) {
        throw new RuntimeException('Set your base look first — this item is restricted to ' . ($sexReq === 'M' ? 'male' : 'female') . ' avatars.');
      }
      $price = (int)$item[5];
      $pdo->beginTransaction();
      $dup = $pdo->prepare('SELECT 1 FROM player_cosmetics WHERE player_id=? AND item_code=?');
      $dup->execute([$pid, $code]);
      if ($dup->fetchColumn()) { $pdo->rollBack(); throw new RuntimeException('You already own that item.'); }
      $u = $pdo->prepare('UPDATE players SET creds_pocket = creds_pocket - ? WHERE id = ? AND creds_pocket >= ?');
      $u->execute([$price, $pid, $price]);
      if ($u->rowCount() !== 1) { $pdo->rollBack(); throw new RuntimeException('Not enough creds. Need ' . number_format($price) . ' cr.'); }
      $pdo->prepare('INSERT INTO player_cosmetics (player_id, item_code, slot) VALUES (?,?,?)')->execute([$pid, $code, $item[3]]);
      $pdo->commit();
      $player = current_player();
      $msg = 'Purchased: ' . $item[1] . '.';

    } elseif ($action === 'equip' || $action === 'unequip') {
      $slot = $_POST['slot'] ?? '';
      if (!isset($SLOT_COLS[$slot])) throw new RuntimeException('Invalid slot.');
      $col = $SLOT_COLS[$slot]; // pre-validated against the whitelist above
      if ($action === 'equip') {
        $code = $_POST['item_code'] ?? '';
        $item = $BC_BY_CODE[$code] ?? null;
        if (!$item || $item[3] !== $slot) throw new RuntimeException('Item not found for that slot.');
        $own = $pdo->prepare('SELECT 1 FROM player_cosmetics WHERE player_id=? AND item_code=?');
        $own->execute([$pid, $code]);
        if (!$own->fetchColumn()) throw new RuntimeException('You don\'t own that item yet.');
        $pdo->prepare("UPDATE players SET {$col} = ? WHERE id = ?")->execute([$code, $pid]);
        $msg = 'Equipped: ' . $item[1] . '.';
      } else {
        $pdo->prepare("UPDATE players SET {$col} = NULL WHERE id = ?")->execute([$pid]);
        $msg = 'Unequipped.';
      }
      $player = current_player();
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $ex->getMessage(); $msgErr = true;
  }
}

// Owned codes
$ownedQ = $pdo->prepare('SELECT item_code FROM player_cosmetics WHERE player_id = ?');
$ownedQ->execute([$pid]);
$owned = array_fill_keys($ownedQ->fetchAll(PDO::FETCH_COLUMN), true);

$tab = $_GET['tab'] ?? 'hat';
if (!in_array($tab, ['hat', 'jacket', 'pants', 'shoes'], true)) $tab = 'hat';

$items = array_values(array_filter($CATALOG, fn($c) => $c[3] === $tab));
$pocket = (int)($player['creds_pocket'] ?? 0);
$mySex = $player['gender'] ?? '';
$avatarActive = in_array($mySex, ['M', 'F'], true);

// Live "paper doll" summary preview — Boutique-only, deliberately separate
// from lib.php's render_avatar_inner() (that one is for the small 52-72px
// boxes elsewhere and can't legibly show jacket/pants/shoes).
function boutique_render_preview(array $player, array $BC_BY_CODE): string {
  $active = in_array($player['gender'] ?? '', ['M', 'F'], true);
  if (!$active) {
    return '<div style="text-align:center;padding:24px 10px;color:var(--muted);font-size:12px">Set your gender in <a href="index.php?p=account&sec=profile" style="color:var(--accent)">Account &rarr;</a> to unlock your avatar.</div>';
  }
  $bodyEmoji = $player['gender'] === 'F' ? '&#128105;' : '&#128104;';
  $hatItem = !empty($player['equip_hat']) ? ($BC_BY_CODE[$player['equip_hat']] ?? null) : null;
  $out = '<div style="text-align:center">';
  $out .= '<div style="position:relative;display:inline-block;width:110px;height:110px;border-radius:12px;background:radial-gradient(circle at 35% 35%,#1c2540,#0a0a12);border:2px solid var(--accent);box-shadow:0 0 20px rgba(25,240,199,.15)">';
  $out .= '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:64px">' . $bodyEmoji . '</span>';
  if ($hatItem) $out .= '<span style="position:absolute;top:-4px;right:-4px;font-size:34px;filter:drop-shadow(0 1px 3px rgba(0,0,0,.7))">' . $hatItem[2] . '</span>';
  $out .= '</div>';
  $out .= '<div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:12px;max-width:320px;margin-left:auto;margin-right:auto">';
  foreach (['hat' => 'Hat', 'jacket' => 'Jacket', 'pants' => 'Pants', 'shoes' => 'Shoes'] as $sk => $sl) {
    $code = $player['equip_' . $sk] ?? null;
    $it = $code ? ($BC_BY_CODE[$code] ?? null) : null;
    if ($it) {
      $out .= '<span style="background:' . $it[6] . '22;border:1px solid ' . $it[6] . '55;color:var(--text);padding:4px 10px;border-radius:12px;font-size:11px">' . $it[2] . ' ' . e($it[1]) . '</span>';
    } else {
      $out .= '<span style="padding:4px 10px;border-radius:12px;font-size:11px;color:var(--muted);border:1px dashed var(--line)">' . e($sl) . ': none</span>';
    }
  }
  $out .= '</div></div>';
  return $out;
}
?>
<style>
.bq-card{position:relative;overflow:hidden;cursor:pointer;transition:transform .12s,border-color .15s,box-shadow .15s}
.bq-card:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.4)}
.bq-card.selected{border-color:var(--tier-col)!important;box-shadow:0 0 14px var(--tier-glow)}
.bq-card.broke .sc-pr{color:var(--neon2)}
.bq-tier{position:absolute;top:6px;right:6px;font-size:8px;font-weight:700;letter-spacing:.08em;padding:1px 6px;border-radius:8px;border:1px solid var(--tier-col);color:var(--tier-col);background:rgba(0,0,0,.35)}
.bq-sex{position:absolute;top:6px;left:6px;font-size:8px;font-weight:700;padding:1px 6px;border-radius:8px;background:rgba(0,0,0,.35)}
#bq-detail{border:1px solid var(--tier-col,var(--line));box-shadow:0 0 18px var(--tier-glow,transparent);transition:border-color .2s,box-shadow .2s}
</style>

<div class="panel" style="padding:16px 20px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">&#128084; Chrome Boutique</h2>
      <p class="muted" style="margin:2px 0 0;font-size:11px">Second skins for a city that never sees your first one.</p>
    </div>
    <div style="text-align:right">
      <div class="muted" style="font-size:10px">POCKET</div>
      <div style="font-size:19px;font-weight:700;font-family:'Orbitron',sans-serif;color:var(--accent)"><?= number_format($pocket) ?> <span style="font-size:11px;font-weight:400">cr</span></div>
    </div>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= $msgErr ? 'flash-err' : 'flash-ok' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div class="panel">
  <h3 style="margin-top:0;font-size:13px">Preview</h3>
  <?php if (!$avatarActive): ?>
  <p class="muted" style="font-size:12px;margin-top:-4px">Your base look follows your Account gender setting.</p>
  <?php endif; ?>
  <?= boutique_render_preview($player, $BC_BY_CODE) ?>
</div>

<div class="tabs" style="margin:14px 0 16px">
  <?php foreach ($SLOT_LABELS as $sk => $sl): ?>
  <a class="tab <?= $tab === $sk ? 'is-active' : '' ?>" href="index.php?p=boutique&tab=<?= $sk ?>"><?= $sl ?></a>
  <?php endforeach; ?>
</div>

<!-- Detail panel (JS-driven) -->
<div class="shop-detail" id="bq-detail" style="display:none">
  <div class="sd-head">
    <div class="sd-ic" id="bq-d-ic"></div>
    <div class="sd-info">
      <div class="sd-name" id="bq-d-name"></div>
      <div class="sd-type" id="bq-d-type"></div>
      <div class="sd-desc" id="bq-d-desc"></div>
    </div>
  </div>
  <div class="sd-footer">
    <div class="sd-price" id="bq-d-price"></div>
    <div style="display:flex;gap:6px">
      <form method="post" id="bq-buy-form" style="margin:0">
        <input type="hidden" name="action" value="buy">
        <input type="hidden" name="item_code" id="bq-buy-code">
        <button type="submit" id="bq-buy-btn" style="background:rgba(25,240,199,.08);border-color:rgba(25,240,199,.35);color:var(--accent)">Buy</button>
      </form>
      <form method="post" id="bq-equip-form" style="margin:0;display:none">
        <input type="hidden" name="action" id="bq-equip-action" value="equip">
        <input type="hidden" name="item_code" id="bq-equip-code">
        <input type="hidden" name="slot" id="bq-equip-slot">
        <button type="submit" id="bq-equip-btn">Equip</button>
      </form>
    </div>
  </div>
</div>

<div class="panel">
  <div class="shop-grid" id="bq-grid">
    <?php foreach ($items as $c):
      $isOwned = isset($owned[$c[0]]);
      $isEquipped = ($player['equip_' . $tab] ?? null) === $c[0];
      [$tier, $tcol] = boutique_tier((int)$c[5]);
      $sexOk = $c[4] === 'U' || $c[4] === $mySex;
      $broke = !$isOwned && $pocket < (int)$c[5];
      $sexLabel = ['M' => 'MALE', 'F' => 'FEMALE', 'U' => 'UNISEX'][$c[4]] ?? 'UNISEX';
    ?>
    <div class="shop-card bq-card<?= $isOwned ? ' owned-card' : '' ?><?= $broke ? ' broke' : '' ?>"
         style="--tier-col:<?= $tcol ?>;--tier-glow:<?= $tcol ?>33"
         data-code="<?= e($c[0]) ?>"
         data-ic="<?= $c[2] ?>"
         data-name="<?= e($c[1]) ?>"
         data-sex="<?= e($sexLabel) ?>"
         data-desc="<?= e($c[7]) ?>"
         data-price="<?= (int)$c[5] ?>"
         data-tier="<?= $tier ?>"
         data-tcol="<?= $tcol ?>"
         data-slot="<?= e($c[3]) ?>"
         data-owned="<?= $isOwned ? '1' : '0' ?>"
         data-equipped="<?= $isEquipped ? '1' : '0' ?>"
         data-sexok="<?= $sexOk ? '1' : '0' ?>">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,<?= $tcol ?>,transparent)"></div>
      <span class="bq-tier"><?= $tier ?></span>
      <?php if ($c[4] !== 'U'): ?><span class="bq-sex" style="color:<?= $c[4] === 'F' ? '#ff75b5' : '#5fa8e8' ?>"><?= $sexLabel ?></span><?php endif; ?>
      <div class="sc-ic"><?= $c[2] ?></div>
      <div class="sc-nm"><?= e($c[1]) ?></div>
      <div class="sc-pr"><?= number_format($c[5]) ?> cr</div>
      <?php if ($isEquipped): ?><div class="sc-owned">&#10003; Equipped</div>
      <?php elseif ($isOwned): ?><div class="sc-owned">&#10003; Owned</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
'use strict';
var detail = document.getElementById('bq-detail');
var grid = document.getElementById('bq-grid');
if (!grid || !detail) return;
var sel = null;

grid.addEventListener('click', function (e) {
  var card = e.target.closest('.bq-card'); if (!card) return;
  if (sel) sel.classList.remove('selected');
  card.classList.add('selected'); sel = card;

  var tcol = card.dataset.tcol || '#19f0c7';
  detail.style.setProperty('--tier-col', tcol);
  detail.style.setProperty('--tier-glow', tcol + '33');
  document.getElementById('bq-d-ic').innerHTML = card.dataset.ic;
  document.getElementById('bq-d-ic').style.textShadow = '0 0 14px ' + tcol;
  var nm = document.getElementById('bq-d-name');
  nm.textContent = card.dataset.name; nm.style.color = tcol;
  document.getElementById('bq-d-type').innerHTML = card.dataset.sex + ' &middot; <span style="color:' + tcol + ';letter-spacing:.06em;font-size:10px">' + card.dataset.tier + '</span>';
  document.getElementById('bq-d-desc').textContent = card.dataset.desc;
  var price = parseInt(card.dataset.price, 10);
  document.getElementById('bq-d-price').textContent = price.toLocaleString() + ' cr';

  var owned = card.dataset.owned === '1';
  var equipped = card.dataset.equipped === '1';
  var sexOk = card.dataset.sexok === '1';
  var buyForm = document.getElementById('bq-buy-form'), buyBtn = document.getElementById('bq-buy-btn');
  var equipForm = document.getElementById('bq-equip-form'), equipBtn = document.getElementById('bq-equip-btn');
  document.getElementById('bq-buy-code').value = card.dataset.code;
  document.getElementById('bq-equip-code').value = card.dataset.code;
  document.getElementById('bq-equip-slot').value = card.dataset.slot;

  if (!owned) {
    buyForm.style.display = ''; equipForm.style.display = 'none';
    if (!sexOk) { buyBtn.disabled = true; buyBtn.textContent = 'Restricted'; buyBtn.style.opacity = '0.5'; }
    else {
      var afford = <?= (int)$pocket ?> >= price;
      buyBtn.disabled = !afford; buyBtn.style.opacity = afford ? '1' : '0.5';
      buyBtn.textContent = afford ? ('Buy — ' + price.toLocaleString() + ' cr') : ('Need ' + price.toLocaleString() + ' cr');
    }
  } else {
    buyForm.style.display = 'none'; equipForm.style.display = '';
    document.getElementById('bq-equip-action').value = equipped ? 'unequip' : 'equip';
    equipBtn.textContent = equipped ? 'Unequip' : 'Equip';
    equipBtn.style.background = equipped ? 'transparent' : 'rgba(25,240,199,.08)';
    equipBtn.style.borderColor = equipped ? 'rgba(255,45,149,.3)' : 'rgba(25,240,199,.35)';
    equipBtn.style.color = equipped ? 'var(--neon2)' : 'var(--accent)';
  }

  detail.style.display = 'block';
  detail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
});
})();
</script>
