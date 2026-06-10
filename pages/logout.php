<?php /* pages/logout.php */
session_destroy();
echo '<script>location.replace("index.php");</script>';
exit;
