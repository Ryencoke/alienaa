<?php /* pages/blackjack.php — retired: folded into the Lucky Daemon casino (daemon.php).
   This standalone engine kept its own session hand/bet state ($_SESSION['bj']),
   separate from daemon's, which let a player run two blackjack engines at once.
   Kept as a redirect so any old bookmarks still land somewhere sensible. */
echo '<div class="panel"><h2>&#127183; Blackjack</h2>'
   . '<p class="muted">Blackjack now lives in the Lucky Daemon casino.</p>'
   . '<p><a class="btn btn-primary" href="index.php?p=daemon">Enter the Lucky Daemon &rarr;</a></p></div>';
echo '<script>location.replace("index.php?p=daemon");</script>';
return;
