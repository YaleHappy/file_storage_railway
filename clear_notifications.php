<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "msg" => "請先登入"]);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // 檢查是否請求清除所有通知歷史
    if (isset($_POST['clear_history']) && $_POST['clear_history'] == 1) {
        // 刪除所有通知（包括已讀和未讀）
        $stmt = $pdo->prepare("
            DELETE FROM notifications 
            WHERE recipient_id = ?
        ");
        $stmt->execute([$userId]);
        
        $count = $stmt->rowCount();
        
        echo json_encode([
            "success" => true,
            "message" => "已清除 {$count} 條通知記錄",
            "deleted_count" => $count
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "msg" => "無效的操作請求"
        ]);
    }
} catch (Exception $e) {
    error_log("clear_notifications.php SQL 錯誤: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "msg" => "數據庫錯誤：" . $e->getMessage()
    ]);
}
exit;
?>