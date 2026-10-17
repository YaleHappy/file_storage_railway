<?php
session_start();
require 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "msg" => "請先登入"]);
    exit;
}

try {
    $userId = $_SESSION['user_id'];
    
    // 使用 MySQL/MariaDB 相容語法，避免 PostgreSQL 的 FILTER 導致原生 prepare 失敗。
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'message' AND is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_messages,
            COALESCE(SUM(CASE WHEN type = 'file' AND is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_files,
            COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS total_unread
        FROM notifications 
        WHERE recipient_id = ?
    ");
    
    $stmt->execute([$userId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 獲取最近10條通知
    $notifStmt = $pdo->prepare("
        SELECT n.id, n.sender_id, n.type, n.message, n.created_at, u.username as sender_name
        FROM notifications n
        LEFT JOIN users u ON n.sender_id = u.user_id
        WHERE n.recipient_id = ?
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $notifStmt->execute([$userId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        "success" => true, 
        "user_id" => (int)$userId,
        "unread_messages" => (int)$counts['unread_messages'],
        "unread_files" => (int)$counts['unread_files'],
        "total_unread" => (int)$counts['total_unread'],
        "notifications" => $notifications
    ]);
} catch (Exception $e) {
    error_log("check_notifications.php SQL 錯誤: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "msg" => "數據庫錯誤：" . $e->getMessage()
    ]);
}
exit;
?>
