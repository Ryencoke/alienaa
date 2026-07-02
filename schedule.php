<?php
$today = date('Y-m-d');

$games = [
    // Completed
    ['date' => '2026-05-07', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Calgary Cowboys', 'diamond' => 5,  'score' => 'W 8-6'],
    ['date' => '2026-05-07', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Calgary Cowboys', 'diamond' => 5,  'score' => 'W 5-2'],
    ['date' => '2026-05-14', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Foul Tips',       'diamond' => 1,  'score' => 'L 10-17'],
    ['date' => '2026-05-14', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Foul Tips',       'diamond' => 1,  'score' => 'W 14-17'],
    ['date' => '2026-05-21', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Fatboys',         'diamond' => 10, 'score' => 'L 16-24'],
    ['date' => '2026-05-21', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Fatboys',         'diamond' => 10, 'score' => 'W 14-15'],
    ['date' => '2026-05-28', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Fight Club',      'diamond' => 10, 'score' => 'L 9-15'],
    ['date' => '2026-05-28', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Fight Club',      'diamond' => 10, 'score' => 'W 7-16'],
    ['date' => '2026-06-11', 'time' => '6:00 PM',  'ha' => 'Home', 'opponent' => 'Dirtbags',        'diamond' => 2,  'score' => 'W 1-16'],
    ['date' => '2026-06-11', 'time' => '7:20 PM',  'ha' => 'Away', 'opponent' => 'Dirtbags',        'diamond' => 2,  'score' => 'W 8-6'],
    // Upcoming
    ['date' => '2026-06-18', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Fight Club',      'diamond' => 7,  'score' => null],
    ['date' => '2026-06-18', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Fight Club',      'diamond' => 7,  'score' => null],
    ['date' => '2026-06-20', 'time' => '12:00 PM', 'ha' => 'Home', 'opponent' => 'Calgary Cowboys', 'diamond' => 2,  'score' => null],
    ['date' => '2026-06-20', 'time' => '1:20 PM',  'ha' => 'Away', 'opponent' => 'Calgary Cowboys', 'diamond' => 2,  'score' => null],
    ['date' => '2026-06-25', 'time' => '6:00 PM',  'ha' => 'Home', 'opponent' => 'Blue Ballers',    'diamond' => 9,  'score' => null],
    ['date' => '2026-06-25', 'time' => '7:20 PM',  'ha' => 'Away', 'opponent' => 'Blue Ballers',    'diamond' => 9,  'score' => null],
    ['date' => '2026-07-02', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Fatboys',         'diamond' => 3,  'score' => null],
    ['date' => '2026-07-02', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Fatboys',         'diamond' => 3,  'score' => null],
    ['date' => '2026-07-09', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Bat Asses',       'diamond' => 5,  'score' => null],
    ['date' => '2026-07-09', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Bat Asses',       'diamond' => 5,  'score' => null],
    ['date' => '2026-07-16', 'time' => '6:00 PM',  'ha' => 'Home', 'opponent' => 'Placers',         'diamond' => 6,  'score' => null],
    ['date' => '2026-07-16', 'time' => '7:20 PM',  'ha' => 'Away', 'opponent' => 'Placers',         'diamond' => 6,  'score' => null],
    ['date' => '2026-07-23', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Bat Asses',       'diamond' => 8,  'score' => null],
    ['date' => '2026-07-23', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Bat Asses',       'diamond' => 8,  'score' => null],
    ['date' => '2026-07-30', 'time' => '6:00 PM',  'ha' => 'Away', 'opponent' => 'Blue Ballers',    'diamond' => 4,  'score' => null],
    ['date' => '2026-07-30', 'time' => '7:20 PM',  'ha' => 'Home', 'opponent' => 'Blue Ballers',    'diamond' => 4,  'score' => null],
    ['date' => '2026-08-06', 'time' => '6:00 PM',  'ha' => 'Home', 'opponent' => 'Free Agents',     'diamond' => 2,  'score' => null],
    ['date' => '2026-08-06', 'time' => '7:20 PM',  'ha' => 'Away', 'opponent' => 'Free Agents',     'diamond' => 2,  'score' => null],
];

// Find today's diamond(s)
$todayDiamonds = [];
foreach ($games as $g) {
    if ($g['date'] === $today) {
        $todayDiamonds[] = $g['diamond'];
    }
}
$todayDiamonds = array_unique($todayDiamonds);

// Group games by date
$grouped = [];
foreach ($games as $g) {
    $grouped[$g['date']][] = $g;
}

$wins = 7; $losses = 3;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RamRod Baseball Schedule 2026</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Open+Sans:wght@400;600&display=swap');

  :root {
    --green:   #1a472a;
    --green2:  #2d6a4f;
    --dirt:    #c8a96e;
    --dirt2:   #a07840;
    --white:   #f5f0e8;
    --yellow:  #f5c518;
    --red:     #c0392b;
    --blue:    #2471a3;
    --today:   #f39c12;
    --card-bg: #0f2418;
    --row-alt: rgba(255,255,255,0.03);
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: #0a1a0f;
    color: var(--white);
    font-family: 'Open Sans', sans-serif;
    min-height: 100vh;
  }

  /* ── Header ── */
  header {
    background: linear-gradient(135deg, #0a1a0f 0%, var(--green) 60%, #0a1a0f 100%);
    border-bottom: 3px solid var(--dirt);
    padding: 28px 20px 22px;
    text-align: center;
    position: relative;
    overflow: hidden;
  }
  header::before {
    content: '⚾';
    position: absolute;
    font-size: 180px;
    opacity: 0.06;
    top: -30px; left: -30px;
    transform: rotate(-20deg);
  }
  header::after {
    content: '⚾';
    position: absolute;
    font-size: 180px;
    opacity: 0.06;
    bottom: -40px; right: -20px;
    transform: rotate(15deg);
  }
  .team-name {
    font-family: 'Oswald', sans-serif;
    font-size: clamp(2rem, 6vw, 3.6rem);
    font-weight: 700;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--dirt);
    text-shadow: 0 2px 12px rgba(0,0,0,0.6);
  }
  .league-name {
    font-size: 0.85rem;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #aaa;
    margin-top: 4px;
  }
  .record-badge {
    display: inline-block;
    margin-top: 14px;
    background: var(--dirt2);
    color: #fff;
    font-family: 'Oswald', sans-serif;
    font-size: 1.1rem;
    letter-spacing: 2px;
    padding: 6px 22px;
    border-radius: 30px;
    border: 2px solid var(--dirt);
  }

  /* ── Today's callout ── */
  .today-banner {
    background: linear-gradient(90deg, #7a4f00, var(--today), #7a4f00);
    color: #000;
    text-align: center;
    padding: 12px 16px;
    font-family: 'Oswald', sans-serif;
    font-size: 1.1rem;
    letter-spacing: 1px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
  }

  /* ── Diamond SVG ── */
  .diamond-wrap {
    background: var(--card-bg);
    border: 1px solid #2a4a2a;
    border-radius: 12px;
    padding: 24px;
    max-width: 320px;
    margin: 24px auto 0;
    text-align: center;
  }
  .diamond-wrap h3 {
    font-family: 'Oswald', sans-serif;
    letter-spacing: 2px;
    color: var(--dirt);
    margin-bottom: 14px;
    font-size: 1rem;
    text-transform: uppercase;
  }
  .diamond-svg-container {
    position: relative;
    width: 200px;
    height: 200px;
    margin: 0 auto;
  }
  .diamond-svg-container svg {
    width: 100%;
    height: 100%;
  }

  /* ── Main layout ── */
  main {
    max-width: 860px;
    margin: 0 auto;
    padding: 32px 16px 60px;
  }

  .section-title {
    font-family: 'Oswald', sans-serif;
    font-size: 1.3rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--dirt);
    border-bottom: 2px solid var(--green2);
    padding-bottom: 8px;
    margin: 32px 0 16px;
  }

  /* ── Game Date Card ── */
  .date-card {
    background: var(--card-bg);
    border: 1px solid #1e3a1e;
    border-radius: 10px;
    margin-bottom: 16px;
    overflow: hidden;
    transition: transform 0.15s;
  }
  .date-card:hover {
    transform: translateY(-2px);
  }
  .date-card.is-today {
    border-color: var(--today);
    box-shadow: 0 0 0 2px rgba(243,156,18,0.4), 0 4px 24px rgba(243,156,18,0.15);
  }

  .date-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    background: rgba(255,255,255,0.04);
    border-bottom: 1px solid #1e3a1e;
  }
  .date-card.is-today .date-header {
    background: rgba(243,156,18,0.12);
    border-bottom-color: rgba(243,156,18,0.3);
  }

  .date-label {
    font-family: 'Oswald', sans-serif;
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 1px;
    flex: 1;
  }
  .today-pill {
    background: var(--today);
    color: #000;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 2px 10px;
    border-radius: 20px;
    font-family: 'Oswald', sans-serif;
  }
  .diamond-pill {
    background: var(--green2);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 3px 12px;
    border-radius: 20px;
    letter-spacing: 1px;
  }
  .date-card.is-today .diamond-pill {
    background: var(--today);
    color: #000;
    font-weight: 700;
  }

  /* ── Game Row ── */
  .game-row {
    display: grid;
    grid-template-columns: 80px 1fr auto auto;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 0.9rem;
  }
  .game-row:last-child { border-bottom: none; }
  .game-row:nth-child(even) { background: var(--row-alt); }

  .game-time {
    color: #aaa;
    font-size: 0.82rem;
    white-space: nowrap;
  }
  .game-matchup {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .ha-badge {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 2px 8px;
    border-radius: 4px;
    text-transform: uppercase;
  }
  .ha-home { background: var(--green);  color: #9ee09e; }
  .ha-away { background: #1a1a2e;      color: #9ab3d5; }

  .opponent { font-weight: 600; }

  .score-badge {
    font-family: 'Oswald', sans-serif;
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 3px 12px;
    border-radius: 6px;
    white-space: nowrap;
  }
  .score-w { background: rgba(39,174,96,0.2);  color: #2ecc71; border: 1px solid #27ae60; }
  .score-l { background: rgba(192,57,43,0.2);  color: #e74c3c; border: 1px solid #c0392b; }
  .score-tbp {
    color: #666;
    font-size: 0.8rem;
    letter-spacing: 1px;
    font-style: italic;
  }

  /* ── Field diagram ── */
  .field-label {
    font-size: 0.75rem;
    color: #777;
    text-align: center;
    margin-top: 8px;
    letter-spacing: 1px;
  }

  footer {
    text-align: center;
    color: #444;
    font-size: 0.75rem;
    padding-bottom: 24px;
    letter-spacing: 1px;
  }

  @media (max-width: 500px) {
    .game-row { grid-template-columns: 70px 1fr auto; }
    .score-badge { font-size: 0.8rem; padding: 2px 8px; }
  }
</style>
</head>
<body>

<div style="background:#8b0000;color:#fff;text-align:center;padding:14px 20px;font-family:'Oswald',sans-serif;font-size:1.15rem;font-weight:700;letter-spacing:1px;border-bottom:3px solid #ff0000;">
  You fucking idiots are 2 players short on Saturday, June 20th
</div>

<header>
  <div class="team-name">⚾ RamRod ⚾</div>
  <div class="record-badge"><?= $wins ?>W &ndash; <?= $losses ?>L</div>
</header>

<?php if (!empty($todayDiamonds)): ?>
<div class="today-banner">
  🔴 GAME DAY — Playing on Diamond <?= implode(' &amp; ', $todayDiamonds) ?> tonight!
</div>

<!-- Diamond highlight graphic -->
<div class="diamond-wrap">
  <h3>Tonight's Diamond<?= count($todayDiamonds) > 1 ? 's' : '' ?></h3>
  <div class="diamond-svg-container">
    <svg viewBox="0 0 220 220" xmlns="http://www.w3.org/2000/svg">
      <!-- Dark field background -->
      <rect width="220" height="220" fill="#0d2010" rx="6"/>
      <!-- Outfield wedge (pie from home plate, foul line to foul line) -->
      <path d="M110,192 L230,72 A172,172 0 0,0 -10,72 Z" fill="#1e5a1e"/>
      <!-- Infield dirt (rotated square) -->
      <polygon points="110,192 176,126 110,60 44,126" fill="#9b6b3a"/>
      <!-- Infield grass circle -->
      <circle cx="110" cy="126" r="44" fill="#267326"/>
      <!-- Foul lines -->
      <line x1="110" y1="192" x2="230" y2="72" stroke="rgba(255,255,255,0.35)" stroke-width="1"/>
      <line x1="110" y1="192" x2="-10" y2="72" stroke="rgba(255,255,255,0.35)" stroke-width="1"/>
      <!-- Outfield wall arc -->
      <path d="M230,72 A172,172 0 0,0 -10,72" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1.5"/>
      <!-- Second base (top) - rotated square -->
      <rect x="103" y="53" width="14" height="14" rx="2" fill="white" transform="rotate(45,110,60)"/>
      <!-- First base (right) -->
      <rect x="169" y="119" width="14" height="14" rx="2" fill="white" transform="rotate(45,176,126)"/>
      <!-- Third base (left) -->
      <rect x="37" y="119" width="14" height="14" rx="2" fill="white" transform="rotate(45,44,126)"/>
      <!-- Home plate (pentagon) -->
      <polygon points="110,184 121,192 118,202 102,202 99,192" fill="white"/>
      <!-- Pitcher's mound -->
      <circle cx="110" cy="130" r="8" fill="#b07840"/>
      <!-- Diamond number badge -->
      <circle cx="110" cy="126" r="27" fill="rgba(243,156,18,0.92)" stroke="rgba(0,0,0,0.15)" stroke-width="1"/>
      <text x="110" y="120" text-anchor="middle" font-family="Oswald,sans-serif" font-size="8.5" fill="#000" font-weight="700" letter-spacing="1.5">DIAMOND</text>
      <text x="110" y="140" text-anchor="middle" font-family="Oswald,sans-serif" font-size="22" fill="#000" font-weight="700"><?= implode('/', $todayDiamonds) ?></text>
    </svg>
  </div>
</div>
<?php endif; ?>

<main>

<?php
$pastGames    = [];
$todayGames   = [];
$upcomingGames = [];

foreach ($grouped as $date => $dayGames) {
    if ($date < $today)      $pastGames[$date]    = $dayGames;
    elseif ($date === $today) $todayGames[$date]  = $dayGames;
    else                      $upcomingGames[$date] = $dayGames;
}

function renderDateCard(string $date, array $dayGames, bool $isToday): void {
    $ts      = strtotime($date);
    $dayStr  = date('l, F j, Y', $ts);
    $diamond = $dayGames[0]['diamond'];
    $card    = $isToday ? 'date-card is-today' : 'date-card';
    echo "<div class=\"$card\">";
    echo "<div class=\"date-header\">";
    echo "  <div class=\"date-label\">$dayStr</div>";
    if ($isToday) echo "  <span class=\"today-pill\">TODAY</span>";
    echo "  <span class=\"diamond-pill\">&#9830; Diamond $diamond</span>";
    echo "</div>";

    foreach ($dayGames as $g) {
        $haBadge = $g['ha'] === 'Home'
            ? '<span class="ha-badge ha-home">Home</span>'
            : '<span class="ha-badge ha-away">Away</span>';

        if ($g['score']) {
            $cls  = $g['score'][0] === 'W' ? 'score-w' : 'score-l';
            $scoreHtml = "<span class=\"score-badge $cls\">{$g['score']}</span>";
        } else {
            $scoreHtml = '<span class="score-tbp">TBP</span>';
        }

        echo "<div class=\"game-row\">";
        echo "  <div class=\"game-time\">{$g['time']}</div>";
        echo "  <div class=\"game-matchup\">$haBadge <span class=\"opponent\">vs. {$g['opponent']}</span></div>";
        echo "  $scoreHtml";
        echo "</div>";
    }
    echo "</div>";
}

if (!empty($todayGames)): ?>
  <div class="section-title">🔴 Today's Games</div>
  <?php foreach ($todayGames as $date => $dayGames) renderDateCard($date, $dayGames, true); ?>
<?php endif; ?>

<?php if (!empty($upcomingGames)): ?>
  <div class="section-title">Upcoming Games</div>
  <?php foreach ($upcomingGames as $date => $dayGames) renderDateCard($date, $dayGames, false); ?>
<?php endif; ?>

<?php if (!empty($pastGames)): ?>
  <div class="section-title">Results</div>
  <?php foreach (array_reverse($pastGames, true) as $date => $dayGames) renderDateCard($date, $dayGames, false); ?>
<?php endif; ?>

</main>


</body>
</html>
