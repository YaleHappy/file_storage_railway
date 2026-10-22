<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$params = [];
if (isset($_GET['group'])) $params['group'] = max(0, (int) $_GET['group']);
elseif (isset($_GET['create'])) $params['create'] = 1;
else $params['groups'] = 1;
header('Location: chat_history.php?' . http_build_query($params));
exit;