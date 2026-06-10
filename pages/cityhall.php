<?php /* pages/cityhall.php — Admin Spire (City Hall) hub */
$pdo = db();
$sec = $_GET['sec'] ?? '';

/* ============================ PRESIDENTIAL OFFICES (staff roster) ============================ */
if ($sec === 'offices') {
  $staff = $pdo->query("SELECT id, username, role, chat_color FROM players
                        WHERE role <> 'member'
                        ORDER BY FIELD(role,'manager','admin','moderator','chatmod'), username")->fetchAll();
  $groups = ['manager'=>[], 'admin'=>[], 'moderator'=>[], 'chatmod'=>[]];
  foreach ($staff as $s) if (isset($groups[$s['role']])) $groups[$s['role']][] = $s;
  $blurbs = [
    'manager'   => ['Managers',  'The benevolent dictators. They run the Sprawl.'],
    'admin'     => ['Admins',    'Handle day-to-day player issues and account problems.'],
    'moderator' => ['Moderators','Keep the message boards civil and help new ghosts.'],
    'chatmod'   => ['Chat Mods', 'Watch the Public Channel for spam and abuse.'],
  ];
  ?>
  <div class="panel"><h2>Presidential Offices</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a> &middot; Each staff member has a specific role &mdash; contact the right one.</p>
  </div>
  <?php foreach ($blurbs as $rk => $b): ?>
  <div class="panel">
    <h3><?= e($b[0]) ?></h3>
    <p class="muted" style="margin-top:-4px"><?= e($b[1]) ?></p>
    <?php if ($groups[$rk]): foreach ($groups[$rk] as $s): $c = chat_color($s['role'], $s['chat_color']); ?>
      <div style="padding:2px 0"><a href="index.php?p=profile&id=<?= (int)$s['id'] ?>" style="color:<?= e($c) ?>;font-weight:bold"><?= e($s['username']) ?></a></div>
    <?php endforeach; else: ?>
      <p class="muted">None appointed yet.</p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php return;
}

/* ============================ RECORDS HALL (stats) ============================ */
if ($sec === 'records') {
  $total  = (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();
  $online = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetchColumn();
  $week   = 0; $male = null; $female = null;
  try { $week = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}
  try {
    $male   = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE avatar = 1')->fetchColumn();
    $female = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE avatar = 2')->fetchColumn();
  } catch (Throwable $e) {}
  $subs = null;
  try { $subs = (int)$pdo->query("SELECT COUNT(*) FROM players WHERE sub_until >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
  ?>
  <div class="panel"><h2>Records Hall</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a> &middot; Live census of the Sprawl.</p>
    <ul>
      <li>The Sprawl is home to <b><?= number_format($total) ?></b> ghosts.</li>
      <li><b><?= number_format($week) ?></b> jacked in for the first time this week.</li>
      <li><b><?= number_format($online) ?></b> are online right now.</li>
      <?php if ($male !== null): ?>
        <li><b><?= number_format($male) ?></b> are Drifters, <b><?= number_format($female) ?></b> are Netghosts.</li>
      <?php endif; ?>
      <?php if ($subs !== null): ?><li><b><?= number_format($subs) ?></b> are subscribed.</li><?php endif; ?>
    </ul>
    <p class="muted" style="font-size:11px">Karma alignment and subscriber counts come online with those systems later.</p>
  </div>
  <?php return;
}

/* ============================ CRYOGENIC STORAGE ============================ */
if ($sec === 'cryo') {
  $pid = $_SESSION['pid']; $today = date('Y-m-d'); $msg = '';
  $sub = is_subscribed($player);
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'freeze') {
    if (!$sub) { $msg = 'Cryo is for subscribers only.'; }
    else {
      $days = max(3, (int)($_POST['days'] ?? 3));
      $until = date('Y-m-d', strtotime("+{$days} days"));
      $pdo->prepare('UPDATE players SET cryo_until = ? WHERE id = ?')->execute([$until, $pid]);
      $msg = "Frozen until {$until}."; $player = current_player();
    }
  }
  $frozen = !empty($player['cryo_until']) && $player['cryo_until'] >= $today;
  ?>
  <div class="panel"><h2>Cryogenic Storage</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a></p>
    <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
    <p>Freeze your ghost while you're away. Minimum 3 days. Once PvP and subscription-day loss are in, freezing protects you from both.</p>
    <?php if ($frozen): ?>
      <p>Status: <b>frozen until <?= e($player['cryo_until']) ?></b>.</p>
    <?php elseif ($sub): ?>
      <form method="post"><input type="hidden" name="action" value="freeze">
        <label>Days to freeze (min 3)</label>
        <p><input type="number" name="days" min="3" value="3" style="max-width:120px"></p>
        <p><button type="submit">Freeze</button></p>
      </form>
    <?php else: ?>
      <p class="flash">Subscribers only. Grab a subscription at <a href="index.php?p=exchange">The Exchange</a>.</p>
    <?php endif; ?>
  </div>
  <?php return;
}

/* ============================ GAME LAWS ============================ */
if ($sec === 'laws') {
  ?>
  <div class="panel"><h2>Game Laws</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a></p>
    <p>This page is the single source of truth for rules in Sprawl-9. If a rule isn't written here, it isn't a rule. Anything posted by players claiming otherwise is null and void.</p>
    <h3>Conduct</h3>
    <ul>
      <li>No harassment, hate speech, or threats toward other ghosts.</li>
      <li>No spamming the Public Channel, boards, or private messages.</li>
      <li>No impersonating staff or other players.</li>
      <li>One account per person unless staff says otherwise.</li>
    </ul>
    <h3>Fair Play</h3>
    <ul>
      <li>No bots, scripts, or automation against the game.</li>
      <li>No exploiting bugs &mdash; report them on the Bug Reports board instead.</li>
      <li>No real-money trading of in-game creds, items, or accounts.</li>
    </ul>
    <h3>Enforcement</h3>
    <p>Breaking these can mean a communication ban, stat reset, account freeze, or a permanent ban &mdash; at staff discretion, scaled to severity. A reasonable request from staff is an order; challenge it afterward via the boards, but follow it at the time.</p>
    <p class="muted" style="font-size:11px">Staff reserve the right to update these laws. Check back after major updates.</p>
  </div>
  <?php return;
}

/* ============================ GAME HELP ============================ */
if ($sec === 'help') {
  ?>
  <div class="panel"><h2>Game Help</h2>
    <p class="muted"><a href="index.php?p=cityhall">&laquo; City Hall</a> &middot; New to the Sprawl? Start here.</p>
    <h3>The Basics</h3>
    <p>You're a drifter who jacks in with nothing. Build <b>Creds</b>, raise your <b>Level</b> (via XP), and climb the rankings. Your left-side card shows your money and meters: <b>Health</b>, <b>XP</b>, <b>Signal</b>, and <b>Drive</b> (spent on skills).</p>
    <h3>The Core Loop</h3>
    <ul>
      <li><b>Datacore</b> (in The Sprawl) &mdash; spend Drive to learn skills that unlock deeper activities.</li>
      <li><b>Foundry Sector</b> &mdash; scavenge raw materials, then craft items (gated by skills).</li>
      <li><b>Transit Hub</b> &mdash; run cargo for creds and mine ore (gated by Drone Piloting).</li>
      <li><b>Bazaar</b> &mdash; sell what you craft/mine to other players, or buy gear.</li>
      <li><b>Bank</b> &mdash; stash creds, transfer to other players, or take a loan.</li>
    </ul>
    <h3>Action</h3>
    <ul>
      <li><b>Combat Sim</b> (The Firewall) &mdash; fight drones for creds, XP, and loot. Heal with Field Patch Kits crafted at the Foundry.</li>
      <li><b>The Lucky Daemon</b> (The Undervolt) &mdash; Dice, Neon Reels, and Blackjack. The house has an edge; bet what you can lose.</li>
    </ul>
    <h3>Social</h3>
    <ul>
      <li><b>Message Boards</b> &mdash; threaded discussion; vote on posts.</li>
      <li><b>Public Channel</b> &mdash; live chat. Use <code>[b]bold[/b]</code> / <code>[i]italics[/i]</code>; set your name color in Account.</li>
      <li><b>Messages</b> &mdash; private one-on-one conversations with other ghosts.</li>
      <li><b>Profiles</b> &mdash; click any player's name to see their stats and message them.</li>
    </ul>
  </div>
  <?php return;
}

/* ============================ HUB MENU ============================ */
?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="height:3px;background:linear-gradient(90deg,var(--accent),var(--neon2),transparent)"></div>
  <div style="padding:20px 20px 16px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
      <span style="font-size:28px;line-height:1">&#127963;</span>
      <div>
        <h2 style="margin:0;font-family:'Orbitron',sans-serif;letter-spacing:2px">CITY HALL</h2>
        <p class="muted" style="margin:2px 0 0;font-size:12px">The Grid Authority runs the Sprawl on paper. In practice, it runs on bribes and downtime.</p>
      </div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">

  <a href="index.php?p=cityhall&sec=offices" style="text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
    <span style="font-size:22px">&#128737;</span>
    <div style="font-weight:700;font-size:13px;color:var(--text)">Presidential Offices</div>
    <div style="font-size:12px;color:var(--muted)">Meet the staff &mdash; admins, mods, and who runs what.</div>
  </a>

  <a href="index.php?p=cityhall&sec=records" style="text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
    <span style="font-size:22px">&#128202;</span>
    <div style="font-weight:700;font-size:13px;color:var(--text)">Records Hall</div>
    <div style="font-size:12px;color:var(--muted)">Live census of the Sprawl &mdash; population and activity stats.</div>
  </a>

  <a href="index.php?p=cityhall&sec=cryo" style="text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--line)'">
    <span style="font-size:22px">&#10052;</span>
    <div style="font-weight:700;font-size:13px;color:var(--text)">Cryogenic Storage</div>
    <div style="font-size:12px;color:var(--muted)">Freeze your ghost while you're away. Subscribers only.</div>
  </a>

  <a href="index.php?p=updates" style="text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--neon2)'" onmouseout="this.style.borderColor='var(--line)'">
    <span style="font-size:22px">&#128221;</span>
    <div style="font-weight:700;font-size:13px;color:var(--text)">City Planning</div>
    <div style="font-size:12px;color:var(--muted)">Game updates, patch notes, and upcoming changes.</div>
  </a>

  <a href="index.php?p=cityhall&sec=laws" style="text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--neon2)'" onmouseout="this.style.borderColor='var(--line)'">
    <span style="font-size:22px">&#9878;</span>
    <div style="font-weight:700;font-size:13px;color:var(--text)">Game Laws</div>
    <div style="font-size:12px;color:var(--muted)">The rules. If it's not written here, it's not a rule.</div>
  </a>

  <a href="index.php?p=cityhall&sec=help" style="text-decoration:none;display:flex;flex-direction:column;gap:6px;padding:16px 18px;background:var(--panel);border:1px solid var(--line);border-radius:8px;transition:border-color .15s" onmouseover="this.style.borderColor='var(--neon2)'" onmouseout="this.style.borderColor='var(--line)'">
    <span style="font-size:22px">&#128218;</span>
    <div style="font-weight:700;font-size:13px;color:var(--text)">Game Help</div>
    <div style="font-size:12px;color:var(--muted)">New to the Sprawl? Start here for a full breakdown.</div>
  </a>

</div>
