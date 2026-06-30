<?php /* pages/register.php — retired: folded into the landing-page signup flow.
   This standalone form inserted straight into `players` on POST with no email
   verification, bypassing the pending_signups (6-digit email code, 15-min
   expiry) flow landing.php uses for every other signup. Kept as a redirect
   so old bookmarks/links still land somewhere sensible. */
echo '<div class="panel"><h2>&#128100; Create a Ghost</h2>'
   . '<p class="muted">Registration now happens from the front page.</p>'
   . '<p><a class="btn btn-primary" href="index.php?p=landing">Go to the Sprawl &rarr;</a></p></div>';
echo '<script>location.replace("index.php?p=landing");</script>';
return;
