<?php
session_start();
require_once __DIR__ . '/config.php';

// 明確登出時立即顯示離線，但保留手動模式供下次登入使用。
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("UPDATE users SET status = 'offline', last_active = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
}

// 清除 session 並登出
session_destroy();
header("Location: login.php");
exit;
?>
