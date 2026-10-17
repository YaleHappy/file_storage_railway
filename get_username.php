<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

// 確保用戶已登入
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "msg" => "請先登入"]);
    exit;
}

// 檢查是否提供了用戶ID
if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(["success" => false, "msg" => "缺少有效的用戶ID"]);
    exit;
}

$userId = (int) $_GET['user_id'];

try {
    // 從資料庫查詢用戶名稱
    $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $username = $stmt->fetchColumn();
    
    if ($username) {
        echo json_encode([
            "success" => true,
            "username" => $username
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "msg" => "找不到該用戶"
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "msg" => "數據庫錯誤：" . $e->getMessage()
    ]);
}
?>