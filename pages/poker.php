<?php /* pages/poker.php — retired: folded into the Lucky Daemon casino (daemon.php).
   This standalone Video Poker engine kept its own session hand/bet state
   ($_SESSION['vp']) and logged 'poker' (vs daemon's 'video_poker'), so its
   plays never showed in the casino history. Kept as a redirect for old links. */
echo '<div class="panel"><h2>&#127183; Video Poker</h2>'
   . '<p class="muted">Video Poker now lives in the Lucky Daemon casino.</p>'
   . '<p><a class="btn btn-primary" href="index.php?p=daemon">Enter the Lucky Daemon &rarr;</a></p></div>';
echo '<script>location.replace("index.php?p=daemon");</script>';
return;
