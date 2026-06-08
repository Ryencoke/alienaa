<?php /* pages/logout.php */
session_destroy(); header('Location: index.php?p=login'); exit;
