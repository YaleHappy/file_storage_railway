<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "msg" => "請先登入"]);
    exit;
}

// 可以接收單個通知ID或全部清除
$notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : null;
$clearAll = isset($_POST['clear_all']) && $_POST['clear_all'] == 1;

try {
    $userId = $_SESSION['user_id'];
    
    if ($clearAll) {
        // 將所有未讀通知標記為已讀
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        $affectedRows = $stmt->rowCount();
        
        echo json_encode([
            "success" => true,
            "message" => "所有通知已標記為已讀",
            "affected_rows" => $affectedRows,
            "total_unread" => 0  // 明確返回0表示已全部清除
        ]);
    } elseif ($notificationId) {
        // 將特定通知標記為已讀
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$notificationId, $userId]);
        
        // 計算剩餘未讀通知數量
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM notifications 
            WHERE recipient_id = ? AND is_read = 0
        ");
        $countStmt->execute([$userId]);
        $remainingUnread = $countStmt->fetchColumn();
        
        echo json_encode([
            "success" => true,
            "message" => "通知已標記為已讀",
            "total_unread" => $remainingUnread
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "缺少必要參數"
        ]);
    }
} catch (Exception $e) {
    error_log("mark_notification_read.php SQL 錯誤: " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "msg" => "SQL 錯誤：" . $e->getMessage()
    ]);
}
exit;
?>