<?php /* pages/registry.php — Grid Authority ID Registry */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';

// Schema: track last handle change time
try { $pdo->exec("ALTER TABLE players ADD COLUMN prev_username VARCHAR(32) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE players ADD COLUMN handle_changed_at DATETIME NULL"); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act === 'change_handle') {
      $newHandle = trim($_POST['new_username'] ?? '');
      if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newHandle))
        throw new RuntimeException('Handle must be 3–20 characters: letters, numbers, underscores only.');
      if (strtolower($newHandle) === strtolower($player['username']))
        throw new RuntimeException('That is already your handle.');
      // 30-day cooldown
      if (!empty($player['handle_changed_at'])) {
        $lastChange = strtotime($player['handle_changed_at']);
        if (time() - $lastChange < 30 * 86400)
          throw new RuntimeException('Handle change on cooldown. Next change available ' . date('M j, Y', $lastChange + 30 * 86400) . '.');
      }
      $cost = 50;
      if ((int)$player['shards'] < $cost) throw new RuntimeException('Not enough shards. Need ' . $cost . ' &#9670;.');
      // Check uniqueness. This SELECT + the UPDATE below aren't atomic together —
      // two concurrent requests for the same handle could both pass this check
      // before either commits. The schema's UNIQUE KEY on players.username is
      // what actually closes that race; the catch below turns the resulting
      // duplicate-key error into a friendly message instead of a raw exception.
      $chk = $pdo->prepare('SELECT id FROM players WHERE LOWER(username) = LOWER(?) AND id != ?');
      $chk->execute([$newHandle, $pid]); if ($chk->fetchColumn()) throw new RuntimeException('That handle is already taken.');
      // Block handles matching/too similar to staff names (same letters after stripping digits/symbols)
      $staffQ = $pdo->query("SELECT username FROM players WHERE role IN ('admin','manager','moderator','chatmod')");
      $newLetters = preg_replace('/[^a-z]/', '', strtolower($newHandle));
      foreach ($staffQ->fetchAll(PDO::FETCH_COLUMN) as $sn) {
        if (strtolower($newHandle) === strtolower($sn)) throw new RuntimeException('That handle matches a staff member\'s name.');
        $snLetters = preg_replace('/[^a-z]/', '', strtolower($sn));
        if ($newLetters !== '' && $snLetters !== '' && $newLetters === $snLetters)
          throw new RuntimeException('That handle is too similar to a staff member\'s name.');
      }
      try {
        $u = $pdo->prepare('UPDATE players SET prev_username=username, username=?, shards=shards-?, handle_changed_at=NOW() WHERE id=? AND shards >= ?');
        $u->execute([$newHandle, $cost, $pid, $cost]);
      } catch (PDOException $dupEx) {
        if ($dupEx->getCode() === '23000') throw new RuntimeException('That handle is already taken.');
        throw $dupEx;
      }
      if ($u->rowCount() !== 1) throw new RuntimeException('Not enough shards. Need ' . $cost . ' &#9670;.');
      $player = current_player();
      $msg = 'Handle updated to ' . $newHandle . '. ' . $cost . ' shards deducted.';
    }
  } catch (Throwable $ex) { $msg = $ex->getMessage(); }
}

// Clearance tier by level
function clearance_tier($level) {
  $level = (int)$level;
  if ($level >= 100) return ['Phantom',     '#ff4444', '&#128312;'];
  if ($level >= 50)  return ['Syndicate',   '#ff8800', '&#128313;'];
  if ($level >= 30)  return ['Fixer',       '#e8d44d', '&#128314;'];
  if ($level >= 20)  return ['Ghost',       '#3bcf63', '&#128994;'];
  if ($level >= 10)  return ['Operator',    'var(--accent)', '&#128309;'];
  return                    ['Civilian',    'var(--muted)', '&#9899;'];
}
[$tierName, $tierColor, $tierIcon] = clearance_tier($player['level']);

$cooldownMsg = '';
if (!empty($player['handle_changed_at'])) {
  $lastChange = strtotime($player['handle_changed_at']);
  if (time() - $lastChange < 30 * 86400)
    $cooldownMsg = 'Next change available ' . date('M j, Y', $lastChange + 30 * 86400) . '.';
}

// Registered date from created_at
$regDate = '';
try { $rq=$pdo->prepare('SELECT created_at FROM players WHERE id=?'); $rq->execute([$pid]); $regDate=(string)$rq->fetchColumn(); } catch(Throwable $e){}
?>
<!-- Header -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),#e8d44d,transparent)"></div>
  <div style="padding:14px 20px">
    <h2 style="margin:0 0 2px">&#128279; ID Registry</h2>
    <p class="muted" style="margin:0;font-size:12px">The Grid Authority. Where ghosts become citizens — for a price. Or stay a ghost.</p>
  </div>
</div>

<?php if ($msg): ?>
<div class="flash <?= str_contains($msg,'Not enough')||str_contains($msg,'taken')||str_contains($msg,'cooldown')||str_contains($msg,'must be') ? 'flash-err' : 'flash-ok' ?>"><?= $msg ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- IDENTITY CARD -->
<div>
  <div class="panel" style="padding:16px">
    <h3 style="margin:0 0 14px">&#128196; Your Identity</h3>
    <div style="background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:8px;padding:16px">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
        <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--neon2),var(--accent));display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#0a0a12;flex:none"><?= strtoupper(mb_substr($player['username'],0,1)) ?></div>
        <div>
          <div style="font-size:18px;font-weight:700"><?= e($player['username']) ?></div>
          <div style="font-size:11px;color:var(--muted)">ID #<?= (int)$player['id'] ?></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px">
        <div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px">
          <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.5px">Level</div>
          <div style="font-family:'Orbitron',sans-serif;font-size:16px;font-weight:700;color:var(--accent)"><?= (int)$player['level'] ?></div>
        </div>
        <div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px">
          <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.5px">Clearance</div>
          <div style="font-size:14px;font-weight:700;color:<?= $tierColor ?>"><?= $tierIcon ?> <?= $tierName ?></div>
        </div>
        <div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px">
          <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.5px">Registered</div>
          <div style="font-size:12px;font-weight:600"><?= $regDate ? e(date('M j, Y', strtotime($regDate))) : 'Unknown' ?></div>
        </div>
        <div style="background:rgba(255,255,255,.04);border-radius:5px;padding:8px 10px">
          <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.5px">Shards</div>
          <div style="font-size:14px;font-weight:700;color:#e8d44d">&#9670; <?= number_format($player['shards']) ?></div>
        </div>
      </div>
      <?php if (!empty($player['prev_username'])): ?>
      <div style="margin-top:10px;font-size:11px;color:var(--muted)">Previously known as: <i><?= e($player['prev_username']) ?></i></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Clearance Tiers Info -->
  <div class="panel" style="padding:16px;margin-top:0">
    <h3 style="margin:0 0 10px">&#127959; Clearance Tiers</h3>
    <?php foreach ([
      ['Civilian','Level 1–9','&#9899;','var(--muted)'],
      ['Operator','Level 10–19','&#128309;','var(--accent)'],
      ['Ghost','Level 20–29','&#128994;','#3bcf63'],
      ['Fixer','Level 30–49','&#128314;','#e8d44d'],
      ['Syndicate','Level 50–99','&#128313;','#ff8800'],
      ['Phantom','Level 100+','&#128312;','#ff4444'],
    ] as [$tn,$tl,$ti,$tc]):
      $isCurrent = $tn === $tierName;
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);<?= $isCurrent ? 'background:rgba(255,255,255,.03);border-radius:4px;padding-left:6px;padding-right:6px;' : '' ?>">
      <span style="font-size:16px;flex:none"><?= $ti ?></span>
      <div style="flex:1">
        <span style="font-weight:700;color:<?= $tc ?>"><?= $tn ?></span>
        <span style="font-size:11px;color:var(--muted);margin-left:6px"><?= $tl ?></span>
      </div>
      <?php if ($isCurrent): ?><span style="font-size:10px;color:var(--accent);font-weight:700">&#10003; CURRENT</span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- HANDLE CHANGE -->
<div>
  <div class="panel" style="padding:16px">
    <h3 style="margin:0 0 4px">&#128393; Change Handle</h3>
    <p class="muted" style="font-size:12px;margin:0 0 14px">Change your username. Costs <b style="color:#e8d44d">50 &#9670;</b>. 30-day cooldown between changes.</p>

    <?php if ($cooldownMsg): ?>
    <div style="background:rgba(255,45,149,.06);border:1px solid rgba(255,45,149,.2);border-radius:6px;padding:9px 12px;font-size:12px;color:var(--neon2);margin-bottom:12px">&#9888; <?= e($cooldownMsg) ?></div>
    <?php endif; ?>

    <form method="post" <?= $cooldownMsg ? 'style="opacity:.5;pointer-events:none"' : '' ?>>
      <input type="hidden" name="action" value="change_handle">
      <div class="field">
        <span>New Handle</span>
        <input type="text" name="new_username" maxlength="20" pattern="[a-zA-Z0-9_]{3,20}"
               placeholder="3–20 chars, letters/numbers/underscores"
               value="<?= e($player['username']) ?>">
        <small class="muted">Current: <b><?= e($player['username']) ?></b> &middot; Shards: <b style="color:#e8d44d">&#9670; <?= number_format($player['shards']) ?></b></small>
      </div>
      <button type="submit" onclick="return confirm('Change handle to the new value? This costs 50 shards.')" style="margin-top:8px">Confirm Change — 50 &#9670;</button>
    </form>
  </div>

  <div class="panel" style="padding:16px;margin-top:0">
    <h3 style="margin:0 0 8px">&#128172; What Clearance Unlocks</h3>
    <div style="font-size:12px;color:var(--muted);line-height:1.8">
      <div>&#9899; <b style="color:var(--text)">Civilian</b> — Access to public markets, basic faction contracts</div>
      <div>&#128309; <b style="color:var(--accent)">Operator</b> — Unlocks advanced trade routes and mid-tier auction access</div>
      <div>&#128994; <b style="color:#3bcf63">Ghost</b> — Syndicate eligibility, encrypted message channels</div>
      <div>&#128314; <b style="color:#e8d44d">Fixer</b> — Priority bazaar listings, guild leadership eligibility</div>
      <div>&#128313; <b style="color:#ff8800">Syndicate</b> — High-tier bounties, cross-sector transit priority</div>
      <div>&#128312; <b style="color:#ff4444">Phantom</b> — Full network authority. The Sprawl owes you nothing and pays everything.</div>
    </div>
  </div>
</div>

</div>
