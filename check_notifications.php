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
    require_once __DIR__ . '/group_schema.php';
    ensureGroupSchema($pdo);
    $userId = $_SESSION['user_id'];
    
    // 使用 MySQL/MariaDB 相容語法，避免 PostgreSQL 的 FILTER 導致原生 prepare 失敗。
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN n.type = 'message' AND n.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_messages,
            COALESCE(SUM(CASE WHEN n.type = 'file' AND n.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_files,
            COALESCE(SUM(CASE WHEN n.is_read = 0 THEN 1 ELSE 0 END), 0) AS total_unread
        FROM notifications n
        LEFT JOIN chat_group_notification_links gl ON gl.notification_id = n.id
        LEFT JOIN chat_group_members gm ON gm.group_id = gl.group_id AND gm.user_id = n.recipient_id
        WHERE n.recipient_id = ? AND (gl.notification_id IS NULL OR gm.user_id IS NOT NULL)
    ");
    
    $stmt->execute([$userId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 獲取最近10條通知
    $notifStmt = $pdo->prepare("
        SELECT n.id, n.sender_id, n.type, n.message, n.created_at, n.is_read, u.username as sender_name, gl.group_id
        FROM notifications n
        LEFT JOIN users u ON n.sender_id = u.user_id
        LEFT JOIN chat_group_notification_links gl ON gl.notification_id = n.id
        LEFT JOIN chat_group_members gm ON gm.group_id = gl.group_id AND gm.user_id = n.recipient_id
        WHERE n.recipient_id = ? AND (gl.notification_id IS NULL OR gm.user_id IS NOT NULL)
        ORDER BY n.created_at DESC, n.id DESC
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
        "msg" => "暫時無法載入通知，請稍後重試"
    ]);
}
exit;
?>
