<?php /* pages/home.php — Hideout: player dashboard */
$pdo  = db();
$pid  = (int)$player['id'];
$role = $player['role'] ?? 'member';
$col  = chat_color($role, '');
$isSub = is_subscribed($player);

// ── Equipped gear ──
$ew = $ea = null; $gearAtk = $gearDef = 0;
try {
  $geq = $pdo->prepare('SELECT v FROM settings WHERE k=?');
  $geq->execute(['equipped_weapon:'.$pid]); $wid = (int)($geq->fetchColumn() ?: 0);
  if ($wid > 0) {
    $gq = $pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def,loan_id FROM player_gear WHERE id=? AND player_id=?');
    $gq->execute([$wid,$pid]); $ew = $gq->fetch() ?: null;
    if (!$ew) { $gq2 = $pdo->prepare('SELECT i.id,i.name,i.atk,0 AS def,0 AS loan_id FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0'); $gq2->execute([$pid,$wid]); $ew = $gq2->fetch() ?: null; }
    $gearAtk = $ew ? (int)$ew['atk'] : 0;
  }
  $geq->execute(['equipped_armor:'.$pid]); $aid = (int)($geq->fetchColumn() ?: 0);
  if ($aid > 0) {
    $gq = $pdo->prepare('SELECT id,name,atk_bonus AS atk,def_bonus AS def,loan_id FROM player_gear WHERE id=? AND player_id=?');
    $gq->execute([$aid,$pid]); $ea = $gq->fetch() ?: null;
    if (!$ea) { $gq2 = $pdo->prepare('SELECT i.id,i.name,0 AS atk,i.def,0 AS loan_id FROM items i JOIN player_items pi ON pi.item_id=i.id AND pi.player_id=? WHERE i.id=? AND pi.qty>0'); $gq2->execute([$pid,$aid]); $ea = $gq2->fetch() ?: null; }
    $gearDef = $ea ? (int)$ea['def'] : 0;
  }
} catch (Throwable $e) {}

// ── Combat stats ──
$cStats = ['str_pts'=>5,'spd_pts'=>5,'end_pts'=>5,'unspent'=>0];
try {
  $csq = $pdo->prepare('SELECT str_pts,spd_pts,end_pts,unspent FROM player_stats WHERE pid=?');
  $csq->execute([$pid]); $cs = $csq->fetch(); if ($cs) $cStats = array_merge($cStats,$cs);
} catch (Throwable $e) {}

// ── Online players ──
$recentOnline = [];
try { $recentOnline = $pdo->query("SELECT id,username,chat_color,role,level FROM players WHERE last_seen>=(NOW()-INTERVAL 5 MINUTE) ORDER BY last_seen DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) {}
$online = count($recentOnline);

// ── Today's combat ──
$todayWins = $todayLoss = $todayXp = $todayCredits = 0;
try {
  $tc = $pdo->prepare("SELECT attacker_id, defender_id, winner_id, atk_xp, def_xp, credits_looted FROM pvp_log WHERE (attacker_id=? OR defender_id=?) AND DATE(fought_at)=CURDATE()");
  $tc->execute([$pid, $pid]);
  foreach ($tc as $r) {
    $won = ((int)$r['winner_id'] === (int)$pid);
    $myXp = ((int)$r['attacker_id'] === (int)$pid) ? (int)$r['atk_xp'] : (int)$r['def_xp'];
    if ($won) { $todayWins++; $todayXp += $myXp; $todayCredits += (int)$r['credits_looted']; }
    else { $todayLoss++; }
  }
} catch (Throwable $e) {}

// ── Vital pcts ──
$xpPct  = $player['xp_next']>0         ? min(100,round($player['xp']/$player['xp_next']*100))                   : 0;
$hpPct  = $player['integrity_max']>0   ? min(100,round($player['integrity']/$player['integrity_max']*100))       : 0;
$sigPct = $player['signal_max']>0      ? min(100,round($player['signal']/$player['signal_max']*100))             : 0;
$drvPct = $player['cycles_max']>0      ? min(100,round($player['cycles']/$player['cycles_max']*100))             : 0;

// ── Syndicate info ──
$myHomeGuild = null;
try {
  $sgq = $pdo->prepare("SELECT s.name, s.tag, sm.rank, sm.joined_at FROM syndicate_members sm JOIN syndicates s ON s.id=sm.syndicate_id WHERE sm.player_id=?");
  $sgq->execute([$pid]); $myHomeGuild = $sgq->fetch();
} catch (Throwable $e) {}

// ── Player Details (account age, alignment, activity counts) ──
$acctAgeDays = !empty($player['created_at']) ? max(0, (int)floor((time() - strtotime($player['created_at'])) / 86400)) : 0;
$acctAgeLabel = $acctAgeDays >= 365 ? number_format($acctAgeDays / 365, 1) . ' yrs' : $acctAgeDays . ' day' . ($acctAgeDays !== 1 ? 's' : '');
$homePostCount = 0;
try { $ppc = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id = ?'); $ppc->execute([$pid]); $homePostCount = (int)$ppc->fetchColumn(); } catch (Throwable $e) {}
$homeChatCount = 0;
try { $pcc = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE player_id = ?'); $pcc->execute([$pid]); $homeChatCount = (int)$pcc->fetchColumn(); } catch (Throwable $e) {}
$homeFriendCount = 0;
try { $pfc = $pdo->prepare('SELECT COUNT(*) FROM friends WHERE player_id = ?'); $pfc->execute([$pid]); $homeFriendCount = (int)$pfc->fetchColumn(); } catch (Throwable $e) {}
$homeMsgCount = 0;
try { $pmc = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE from_id = ?'); $pmc->execute([$pid]); $homeMsgCount = (int)$pmc->fetchColumn(); } catch (Throwable $e) {}

// ── Notifications ── (schema + seed + game-time formatting shared with
// notifications_api.php, which is what actually keeps this feed live and
// handles dismissal — see that file. This is just the first paint.)
ensure_player_notifications_table($pdo);
seed_player_notifications($pdo, $pid);

// ── Fetch all unread notifications ──
$newsFeed = [];
try {
  $nq = $pdo->prepare("SELECT id, type, body AS text, created_at AS ts FROM player_notifications WHERE player_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 20");
  $nq->execute([$pid]); $newsFeed = $nq->fetchAll();
} catch(Throwable $e) {}
// One NOW() fetch for the whole batch — fmt_game_time() needs it to compute
// each item's true Denver-local time, and fetching it once avoids a query
// per notification.
try { $__mysqlNow = (string)$pdo->query('SELECT NOW()')->fetchColumn(); } catch (Throwable $e) { $__mysqlNow = null; }

// Visiting the Hideout marks notifications as seen (clears the sidebar "new"
// badge/bold from the next page load on) without dismissing them — they stay
// listed here until the player explicitly clears them.
try { $pdo->prepare("UPDATE player_notifications SET is_seen=1 WHERE player_id=? AND is_seen=0")->execute([$pid]); } catch(Throwable $e) {}

// ── Dynamic: unspent attr points — read from player_stats.unspent ──
$attrPoints = (int)($cStats['unspent'] ?? 0);
if ($attrPoints > 0) array_unshift($newsFeed, ['id'=>null,'type'=>'levelup','text'=>"You have <b>{$attrPoints} unspent attribute point".($attrPoints!==1?'s':'')."</b> from leveling up! <a href='index.php?p=pvp&tab=stats'>Spend Stats &rarr;</a>",'ts'=>date('Y-m-d H:i:s')]);
?>

<style>
#hm-canvas{display:block;width:100%;height:148px;border-radius:9px}
.hm-card{position:relative;overflow:hidden;margin-bottom:0;transition:transform .12s,border-color .15s,box-shadow .15s}
.hm-card:hover{transform:translateY(-2px);border-color:var(--hm-col,var(--accent));box-shadow:0 4px 12px rgba(0,0,0,.3),0 0 10px var(--hm-glow,rgba(25,240,199,.08))}
.hm-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--hm-col,var(--accent)),transparent)}
.hm-notif{animation:hmIn .25s ease-out backwards}
@keyframes hmIn{0%{opacity:0;transform:translateY(5px)}100%{opacity:1;transform:none}}
@keyframes hmBell{0%,100%{transform:rotate(0)}20%{transform:rotate(13deg)}40%{transform:rotate(-10deg)}60%{transform:rotate(6deg)}80%{transform:rotate(-3deg)}}
.hm-bell{display:inline-block;animation:hmBell 2.4s ease-in-out infinite;transform-origin:top center}
.hm-x{transition:opacity .12s,transform .08s}
.hm-x:hover{opacity:1!important}.hm-x:active{transform:scale(1.3)}
.hh-name,.hh-badges{text-shadow:0 1px 5px rgba(0,0,0,.8)}
</style>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="panel" style="padding:0;overflow:hidden">
  <div style="position:relative">
    <canvas id="hm-canvas"></canvas>
    <div style="position:absolute;inset:0;padding:16px 20px;display:flex;align-items:center">
    <div class="hh-wrap" style="width:100%">
      <div class="hh-av"><?= render_avatar_inner($player, 64) ?></div>
      <div class="hh-info">
        <?php $myMortality = (int)($player['mortality'] ?? 0); ?>
        <div class="hh-name" style="color:<?= e($col) ?>">
          <?= e($player['username']) ?>
          <?= flag_img($player['country']??'') ?>
          <?= gender_icon($player['gender'] ?? '') ?>
          <?php if ($isSub): ?><span title="Subscriber" style="color:#e8d44d;font-size:15px">&#9733;</span><?php endif; ?>
          <?= mortality_icon($myMortality) ?>
          <?php if ($myMortality !== 0): ?>
          <span style="font-size:11px;color:<?= $myMortality > 0 ? '#e8d44d' : '#ff2d95' ?>;font-weight:700"><?= ($myMortality > 0 ? '+' : '') . $myMortality ?></span>
          <?php endif; ?>
          <?php if ($role!=='member'): ?><?= role_tag($role,'font-size:10px;font-family:\'Orbitron\',sans-serif;border:1px solid rgba(255,255,255,.15);border-radius:4px;padding:2px 8px') ?><?php endif; ?>
        </div>
        <div class="hh-badges">
          <span class="hh-badge">Lv <?= (int)$player['level'] ?></span>
          <span class="muted" style="font-size:11px">Ghost #<?= $pid ?></span>
          <?php if (!empty($player['created_at'])): ?><span class="muted" style="font-size:11px">&#128197; <?= e(date('M Y',strtotime($player['created_at']))) ?></span><?php endif; ?>
        </div>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:3px">
            <span>XP &mdash; Level <?= (int)$player['level'] ?> &rarr; <?= (int)$player['level']+1 ?></span>
            <span><?= number_format((int)$player['xp']) ?> / <?= number_format((int)$player['xp_next']) ?> <span style="color:var(--accent)">(<?= $xpPct ?>%)</span></span>
          </div>
          <div style="height:7px;background:#080812;border-radius:5px;overflow:hidden">
            <div style="width:<?= $xpPct ?>%;height:100%;background:linear-gradient(90deg,var(--accent),var(--neon2));border-radius:5px;transition:width .4s"></div>
          </div>
        </div>
      </div>
    </div>
    </div>
  </div>
</div>

<!-- ══ NOTIFICATIONS ════════════════════════════════════════ -->
<div class="panel" id="home-news" style="border:1px solid rgba(25,240,199,.2);background:rgba(25,240,199,.03)">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
    <h3 style="margin:0;font-size:13px;text-transform:uppercase;letter-spacing:.5px"><span id="home-notif-bell" class="<?= !empty($newsFeed) ? 'hm-bell' : '' ?>">&#128276;</span> Notifications<span id="home-notif-count-wrap"><?php if (!empty($newsFeed)): ?> <span id="home-notif-count" style="background:var(--accent);color:#0b0c1a;border-radius:10px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:6px"><?= count($newsFeed) ?></span><?php endif; ?></span></h3>
    <div id="home-notif-clearall-wrap"<?= empty($newsFeed) ? ' style="display:none"' : '' ?>>
      <button type="button" id="home-notif-clearall" style="font-size:10px;padding:2px 8px;background:transparent;border:1px solid var(--line);color:var(--muted);cursor:pointer;border-radius:4px">Clear All</button>
    </div>
  </div>
  <div id="home-notif-list">
  <?php if (empty($newsFeed)): ?>
  <div id="home-notif-empty" style="text-align:center;padding:12px 0;color:var(--muted)">
    <div style="font-size:22px;opacity:.25;margin-bottom:5px">&#128276;</div>
    <div style="font-size:12px">No new notifications</div>
  </div>
  <?php else: foreach ($newsFeed as $item):
    $nicon = ['message'=>'&#9993;','transfer'=>'&#128178;','news'=>'&#128203;','levelup'=>'&#11088;','friend_add'=>'&#128101;','pvp'=>'&#9876;','guild_loan'=>'&#9874;'][$item['type']] ?? '&#8226;';
    $ncol  = ['message'=>'var(--accent)','transfer'=>'#3bcf63','news'=>'#e8d44d','levelup'=>'#e8d44d','friend_add'=>'var(--accent)','pvp'=>'var(--neon2)','guild_loan'=>'#e8a33d'][$item['type']] ?? 'var(--muted)';
    $rawHtml = $item['type'] === 'levelup';
    $canDismiss = $item['id'] !== null;
  ?>
  <div class="hm-notif" style="animation-delay:<?= min(10, $nfI = ($nfI ?? 0) + 1) * 45 ?>ms;display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)">
    <span style="font-size:13px;flex:none;color:<?= $ncol ?>;margin-top:2px"><?= $nicon ?></span>
    <div style="flex:1;min-width:0;overflow-wrap:break-word;word-break:break-word">
      <div style="font-size:12px;line-height:1.4"><?= $rawHtml ? $item['text'] : e($item['text']) ?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:1px"><?= !empty($item['ts']) ? e(fmt_game_time($item['ts'], 'M j, g:ia', $__mysqlNow)) : '' ?></div>
    </div>
    <?php if ($canDismiss): ?>
    <button type="button" class="hm-x" data-id="<?= (int)$item['id'] ?>" style="background:transparent;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:0 4px;line-height:1;opacity:.6;flex:none;align-self:center" title="Dismiss">&times;</button>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
  </div>
</div>

<!-- ══ STATS GRID ══════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px">

  <!-- Vitals -->
  <div class="panel hm-card" style="--hm-col:#3bcf63;--hm-glow:rgba(59,207,99,.08)">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128293; Vitals</h3>
    <?php foreach ([
      ['Health', $player['integrity'],$player['integrity_max'],$hpPct, '#3bcf63'],
      ['Signal', $player['signal'],   $player['signal_max'],   $sigPct,'var(--neon2)'],
      ['Drive',  $player['cycles'],   $player['cycles_max'],   $drvPct,'#e8a33d'],
    ] as [$vl,$vv,$vm,$vp,$vc]): ?>
    <div style="margin-bottom:11px">
      <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
        <span style="color:var(--muted)"><?= $vl ?></span>
        <span style="color:<?= $vc ?>;font-weight:600"><?= number_format((int)$vv) ?><span style="color:var(--muted);font-weight:400"> / <?= number_format((int)$vm) ?></span></span>
      </div>
      <div style="height:5px;background:rgba(0,0,0,.4);border-radius:3px;overflow:hidden">
        <div style="width:<?= $vp ?>%;height:100%;background:<?= $vc ?>;border-radius:3px;transition:width .4s"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Combat Stats -->
  <div class="panel hm-card" style="--hm-col:#ff2d95;--hm-glow:rgba(255,45,149,.08)">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#9876; Combat Stats</h3>
    <?php
    foreach ([
      ['Strength',  'str_pts','var(--neon2)','&#9889;','ATK'],
      ['Speed',     'spd_pts','#e8a33d',    '&#128168;','SPD'],
      ['Endurance', 'end_pts','#3bcf63',    '&#128158;','HP/DEF'],
    ] as [$sl,$sk,$sc,$si,$sef]):
      $sv = (int)($cStats[$sk] ?? 5);
      $barMax = max($sv, 50);
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="font-size:18px;width:24px;text-align:center;flex:none"><?= $si ?></span>
      <div style="flex:1;min-width:0">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px">
          <span style="color:var(--muted)"><?= $sl ?></span>
          <span style="color:<?= $sc ?>;font-weight:700"><?= $sv ?></span>
        </div>
        <div style="height:4px;background:rgba(0,0,0,.4);border-radius:2px;overflow:hidden">
          <div style="width:<?= min(100,round($sv/$barMax*100)) ?>%;height:100%;background:<?= $sc ?>;border-radius:2px"></div>
        </div>
      </div>
      <span style="font-size:10px;color:var(--muted);flex:none;width:46px;text-align:right"><?= $sef ?></span>
    </div>
    <?php endforeach; ?>
    <?php if ((int)$cStats['unspent'] > 0): ?>
    <div style="margin-top:10px;padding:8px 12px;background:rgba(232,212,77,.06);border:1px solid rgba(232,212,77,.3);border-radius:6px;font-size:12px;display:flex;align-items:center;justify-content:space-between">
      <span>&#11088; <b style="color:#e8d44d"><?= (int)$cStats['unspent'] ?></b> unspent point<?= $cStats['unspent']!=1?'s':'' ?></span>
      <a href="index.php?p=pvp&tab=stats" style="color:#e8d44d;font-size:11px">Spend &rarr;</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Loadout -->
  <div class="panel hm-card" style="--hm-col:#19f0c7;--hm-glow:rgba(25,240,199,.08)">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128737; Loadout
      <?php if ($gearAtk||$gearDef): ?>
        <span style="float:right;font-size:11px;font-weight:400">
          <?php if ($gearAtk): ?><span style="color:var(--neon2)">+<?= $gearAtk ?> ATK</span><?php endif; ?>
          <?php if ($gearAtk&&$gearDef): ?> <?php endif; ?>
          <?php if ($gearDef): ?><span style="color:var(--accent)">+<?= $gearDef ?> DEF</span><?php endif; ?>
        </span>
      <?php endif; ?>
    </h3>
    <?php foreach ([
      ['weapon','&#9876;','Weapon',$ew,'rgba(255,45,149,.08)','rgba(255,45,149,.25)'],
      ['armor','&#128737;','Armor',$ea,'rgba(25,240,199,.06)','rgba(25,240,199,.2)'],
    ] as [$slot,$icon,$label,$item,$ibg,$ibord]): ?>
    <div style="background:<?= $item?$ibg:'var(--panel2)' ?>;border:1px solid <?= $item?$ibord:'var(--line)' ?>;border-radius:7px;padding:10px 12px;margin-bottom:8px;display:flex;align-items:center;gap:8px">
      <span style="font-size:20px;opacity:<?= $item?'1':'.2' ?>;flex:none"><?= $icon ?></span>
      <div style="flex:1;min-width:0">
        <?php if ($item): ?>
          <div style="font-weight:700;font-size:13px"><?= e($item['name']) ?><?php if ((int)($item['loan_id'] ?? 0) > 0): ?> <span style="font-size:9px;font-weight:700;color:#e8a33d;text-transform:uppercase;letter-spacing:.4px;border:1px solid rgba(232,163,61,.4);border-radius:4px;padding:1px 5px;vertical-align:middle">&#9874; Guild Loan</span><?php endif; ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= $slot==='weapon'?'+'.((int)$item['atk']).' ATK':'+'.((int)$item['def']).' DEF' ?></div>
        <?php else: ?>
          <div style="color:var(--muted);font-size:12px;font-style:italic">No <?= $label ?> equipped</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Guild Info -->
  <div class="panel hm-card" style="--hm-col:#e8d44d;--hm-glow:rgba(232,212,77,.08)">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128101; Syndicate</h3>
    <?php if ($myHomeGuild): ?>
    <?php
      $SYN_RANK_LABELS_HOME = ['leader'=>'Leader','coleader'=>'Co-Leader','treasurer'=>'Treasurer','armourer'=>'Armourer','librarian'=>'Librarian','advisor'=>'Advisor','member'=>'Member'];
      $SYN_RANK_COLORS_HOME = ['leader'=>'#e8d44d','coleader'=>'var(--neon2)','treasurer'=>'#3bcf63','armourer'=>'var(--accent)','librarian'=>'#9b8cff','advisor'=>'#e8a33d','member'=>'var(--muted)'];
      $gRank = $myHomeGuild['rank'] ?? 'member';
      $gRankLabel = $SYN_RANK_LABELS_HOME[$gRank] ?? ucfirst($gRank);
      $gRankColor = $SYN_RANK_COLORS_HOME[$gRank] ?? 'var(--muted)';
    ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:7px;padding:10px 12px;margin-bottom:8px">
      <div style="font-weight:700;font-size:14px;color:var(--accent)">[<?= e($myHomeGuild['tag']) ?>] <?= e($myHomeGuild['name']) ?></div>
      <div style="font-size:11px;color:<?= $gRankColor ?>;margin-top:3px;font-weight:700"><?= $gRankLabel ?></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11px">
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Joined</div>
        <div style="font-weight:700;color:var(--text);margin-top:2px"><?= $myHomeGuild['joined_at'] ? e(date('M j, Y', strtotime($myHomeGuild['joined_at']))) : '—' ?></div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Rank</div>
        <div style="font-weight:700;color:<?= $gRankColor ?>;margin-top:2px"><?= $gRankLabel ?></div>
      </div>
    </div>
    <div style="margin-top:8px;text-align:center"><a href="index.php?p=guilds" style="font-size:11px;color:var(--muted);text-decoration:none;border:1px solid var(--line);border-radius:5px;padding:5px 14px;display:inline-block">View Syndicate &rarr;</a></div>
    <?php else: ?>
    <div style="text-align:center;padding:16px 0;color:var(--muted)">
      <div style="font-size:28px;opacity:.2;margin-bottom:6px">&#128101;</div>
      <div style="font-size:12px">Not in a syndicate</div>
      <a href="index.php?p=guilds" style="display:inline-block;margin-top:8px;font-size:11px;color:var(--accent);padding:5px 14px;border:1px solid rgba(25,240,199,.2);border-radius:5px;text-decoration:none">Browse &rarr;</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Player Details -->
  <div class="panel hm-card" style="--hm-col:#a66de8;--hm-glow:rgba(166,109,232,.08)">
    <h3 style="margin-top:0;margin-bottom:14px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128100; Player Details</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11px">
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Account Age</div>
        <div style="font-weight:700;color:var(--text);margin-top:2px"><?= e($acctAgeLabel) ?></div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Alignment</div>
        <div style="font-weight:700;margin-top:2px;color:<?= $myMortality>0 ? '#e8d44d' : ($myMortality<0 ? 'var(--neon2)' : 'var(--muted)') ?>">
          <?= mortality_icon($myMortality) ?> <?= $myMortality>0 ? 'Good' : ($myMortality<0 ? 'Evil' : 'Neutral') ?><?= $myMortality!==0 ? ' ('.($myMortality>0?'+':'').$myMortality.')' : '' ?>
        </div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Board Posts</div>
        <div style="font-weight:700;color:var(--text);margin-top:2px"><?= number_format($homePostCount) ?></div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Chat Messages</div>
        <div style="font-weight:700;color:var(--text);margin-top:2px"><?= number_format($homeChatCount) ?></div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">Friends</div>
        <div style="font-weight:700;color:var(--text);margin-top:2px"><?= number_format($homeFriendCount) ?></div>
      </div>
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:5px;padding:7px 10px">
        <div style="color:var(--muted)">PMs Sent</div>
        <div style="font-weight:700;color:var(--text);margin-top:2px"><?= number_format($homeMsgCount) ?></div>
      </div>
    </div>
  </div>

</div>

<!-- ══ ACTIVITY ══════════════════════════════════════════════ -->
<div class="panel">
  <h3 style="margin-top:0;margin-bottom:10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px">&#128202; Today's Combat</h3>
  <?php if ($todayWins+$todayLoss > 0): ?>
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:12px">
    <?php foreach ([
      ['Fights', $todayWins+$todayLoss,'var(--text)'],
      ['Wins',   $todayWins,           'var(--accent)'],
      ['Losses', $todayLoss,           'var(--neon2)'],
      ['XP',     '+'.number_format($todayXp), '#e8d44d'],
      ['Creds',  '+'.number_format($todayCredits),'#3bcf63'],
    ] as [$lbl,$v,$c]): ?>
    <div style="background:var(--panel2);border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center">
      <div style="font-family:'Orbitron',sans-serif;font-size:14px;font-weight:700;color:<?= $c ?>"><?= $v ?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:18px 0;color:var(--muted)">
    <div style="font-size:30px;margin-bottom:6px;opacity:.25">&#9876;</div>
    <div style="font-size:13px">No combat logged today</div>
  </div>
  <?php endif; ?>
</div>

<script>
(function(){
'use strict';
/* ── Hideout window: the Sprawl at night through rain-streaked glass ── */
var hv=document.getElementById('hm-canvas');
if(!hv) return;
var c=hv.getContext('2d');
var W=720, H=148;
var dpr=Math.min(2,window.devicePixelRatio||1);
hv.width=W*dpr; hv.height=H*dpr;
c.scale(dpr,dpr);
function cssVar(n,fb){
  try{ var v=getComputedStyle(document.documentElement).getPropertyValue(n).trim(); return v||fb; }catch(e){ return fb; }
}
var ACCENT=cssVar('--accent','#19f0c7'), NEON2=cssVar('--neon2','#ff2d95');

// skyline through the window (deterministic)
var s=21; function rnd(){ s=(s*16807)%2147483647; return s/2147483647; }
var towers=[]; var x=0;
while(x<W){ var bw=26+rnd()*50, bh=30+rnd()*72; towers.push({x:x,w:bw,h:bh,seed:rnd()}); x+=bw+4+rnd()*10; }
// rain drops running down the glass
var drips=[];
for(var i=0;i<26;i++) drips.push({x:Math.random()*W,y:Math.random()*H,v:.4+Math.random()*1.4,len:6+Math.random()*14,wob:Math.random()*9});

function winLit(b,wx,wy,t){
  var h2=Math.sin(b.seed*9999+wx*37+wy*91)*43758.55; var r=h2-Math.floor(h2);
  return ((t/1600+r*6)%7)<(2.6+r*2.4);
}

function loop(t){
  if(!document.body.contains(hv)) return;
  requestAnimationFrame(loop);
  c.clearRect(0,0,W,H);
  var bg=c.createLinearGradient(0,0,0,H);
  bg.addColorStop(0,'#07070f'); bg.addColorStop(1,'#0b0a14');
  c.fillStyle=bg; c.fillRect(0,0,W,H);

  // neon sign wash from somewhere outside (pulsing pink, right side)
  var wash=.05+.03*Math.sin(t/900);
  var ng=c.createRadialGradient(W-60,30,10,W-60,30,260);
  ng.addColorStop(0,'rgba(255,45,149,'+wash*2+')'); ng.addColorStop(1,'rgba(255,45,149,0)');
  c.fillStyle=ng; c.fillRect(0,0,W,H);

  // skyline
  towers.forEach(function(b){
    c.fillStyle='#0d0d1a';
    c.fillRect(b.x,H-b.h,b.w,b.h);
    for(var wy=H-b.h+5;wy<H-6;wy+=10){
      for(var wx=b.x+4;wx<b.x+b.w-4;wx+=8){
        if(winLit(b,wx,wy,t)){
          c.fillStyle=((wx+wy)%5===0)?'rgba(255,45,149,.30)':'rgba(25,240,199,.24)';
          c.fillRect(wx,wy,2.6,3.4);
        }
      }
    }
  });

  // distant aircar
  var carX=((t/14)%(W+160))-80;
  c.strokeStyle='rgba(25,240,199,.5)';
  c.beginPath(); c.moveTo(carX-10,34); c.lineTo(carX,34); c.stroke();
  c.fillStyle='#fff'; c.fillRect(carX-1,33,2,2);

  // rain on the glass
  c.strokeStyle='rgba(170,190,230,.16)'; c.lineWidth=1;
  drips.forEach(function(d){
    d.y+=d.v;
    if(d.y>H+d.len){ d.y=-d.len; d.x=Math.random()*W; }
    var wx2=Math.sin(t/700+d.wob)*1.2;
    c.beginPath(); c.moveTo(d.x+wx2,d.y-d.len); c.lineTo(d.x,d.y); c.stroke();
    // bead at the tip
    c.fillStyle='rgba(190,210,245,.20)';
    c.beginPath(); c.arc(d.x,d.y,1,0,Math.PI*2); c.fill();
  });

  // window frame crossbars
  c.fillStyle='rgba(0,0,0,.45)';
  c.fillRect(0,0,W,3); c.fillRect(0,H-3,W,3);
  c.fillRect(W*0.62-2,0,4,H);
  // interior vignette (left side darker where the player card sits)
  var iv=c.createLinearGradient(0,0,W*.55,0);
  iv.addColorStop(0,'rgba(4,4,10,.78)'); iv.addColorStop(1,'rgba(4,4,10,.05)');
  c.fillStyle=iv; c.fillRect(0,0,W*.55,H);
}
requestAnimationFrame(loop);
})();
</script>

<script>
(function(){
'use strict';
/* ── Live notification feed: polls notifications_api.php so new items show
   up without a page reload, and dismisses go through direct, individually-
   targeted requests instead of the sitewide AJAX-page-swap form handler
   (whose full-page re-render was the suspect behind dismiss only ever
   seeming to work on the newest entry). Buttons here are plain <button
   type=button>, not <form> submits, so the generic handler never sees them. */
var list = document.getElementById('home-notif-list');
if (!list) return;
var bell = document.getElementById('home-notif-bell');
var countWrap = document.getElementById('home-notif-count-wrap');
var clearAllWrap = document.getElementById('home-notif-clearall-wrap');
var clearAllBtn = document.getElementById('home-notif-clearall');

var ICONS = {message:'&#9993;',transfer:'&#128178;',news:'&#128203;',levelup:'&#11088;',friend_add:'&#128101;',pvp:'&#9876;',guild_loan:'&#9874;'};
var COLORS = {message:'var(--accent)',transfer:'#3bcf63',news:'#e8d44d',levelup:'#e8d44d',friend_add:'var(--accent)',pvp:'var(--neon2)',guild_loan:'#e8a33d'};

function render(items) {
  if (!items.length) {
    list.innerHTML = '<div id="home-notif-empty" style="text-align:center;padding:12px 0;color:var(--muted)">'
      + '<div style="font-size:22px;opacity:.25;margin-bottom:5px">&#128276;</div>'
      + '<div style="font-size:12px">No new notifications</div></div>';
    if (bell) bell.classList.remove('hm-bell');
    if (countWrap) countWrap.innerHTML = '';
    if (clearAllWrap) clearAllWrap.style.display = 'none';
    return;
  }
  if (bell) bell.classList.add('hm-bell');
  if (countWrap) countWrap.innerHTML = ' <span id="home-notif-count" style="background:var(--accent);color:#0b0c1a;border-radius:10px;font-size:10px;padding:1px 7px;font-weight:700;margin-left:6px">' + items.length + '</span>';
  if (clearAllWrap) clearAllWrap.style.display = '';

  list.innerHTML = '';
  items.forEach(function (item, i) {
    var row = document.createElement('div');
    row.className = 'hm-notif';
    row.style.cssText = 'animation-delay:' + (Math.min(10, i + 1) * 45) + 'ms;display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)';

    var icon = document.createElement('span');
    icon.style.cssText = 'font-size:13px;flex:none;color:' + (COLORS[item.type] || 'var(--muted)') + ';margin-top:2px';
    icon.innerHTML = ICONS[item.type] || '&#8226;';
    row.appendChild(icon);

    var body = document.createElement('div');
    body.style.cssText = 'flex:1;min-width:0;overflow-wrap:break-word;word-break:break-word';
    var textEl = document.createElement('div');
    textEl.style.cssText = 'font-size:12px;line-height:1.4';
    if (item.raw) textEl.innerHTML = item.text; else textEl.textContent = item.text; // server-controlled HTML only for the synthetic levelup entry
    var tsEl = document.createElement('div');
    tsEl.style.cssText = 'font-size:10px;color:var(--muted);margin-top:1px';
    tsEl.textContent = item.ts || '';
    body.appendChild(textEl); body.appendChild(tsEl);
    row.appendChild(body);

    if (item.id !== null) {
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'hm-x'; btn.dataset.id = item.id;
      btn.style.cssText = 'background:transparent;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:0 4px;line-height:1;opacity:.6;flex:none;align-self:center';
      btn.title = 'Dismiss'; btn.innerHTML = '&times;';
      row.appendChild(btn);
    }
    list.appendChild(row);
  });
}

function load() {
  if (!document.body.contains(list)) { if (window.__homeNotifInterval) { clearInterval(window.__homeNotifInterval); window.__homeNotifInterval = null; } return; }
  fetch('notifications_api.php?action=list', {credentials: 'same-origin'})
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.ok) render(d.items || []); })
    .catch(function () {});
}

list.addEventListener('click', function (e) {
  var btn = e.target.closest('.hm-x'); if (!btn) return;
  var row = btn.closest('.hm-notif'); if (row) row.remove(); // optimistic removal
  var fd = new FormData(); fd.append('action', 'dismiss'); fd.append('id', btn.dataset.id);
  fetch('notifications_api.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(load).catch(load);
});

if (clearAllBtn) clearAllBtn.addEventListener('click', function () {
  var fd = new FormData(); fd.append('action', 'dismiss_all');
  fetch('notifications_api.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(load).catch(load);
});

load();
if (window.__homeNotifInterval) clearInterval(window.__homeNotifInterval);
window.__homeNotifInterval = setInterval(load, 12000);
})();
</script>
