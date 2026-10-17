<?php
// 確保只在 session 未啟動時才呼叫 session_start()
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * 新增通知的函數
 * 
 * @param int $senderId 發送者ID
 * @param int $recipientId 接收者ID
 * @param string $type 通知類型 (message/file/system)
 * @param string $message 通知訊息
 * @return bool 是否成功新增
 */
function addNotification($senderId, $recipientId, $type, $message) {
    global $pdo;
    
    // 防止自己給自己發通知
    if ($senderId == $recipientId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (sender_id, recipient_id, type, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$senderId, $recipientId, $type, $message]);
    } catch (Exception $e) {
        error_log("新增通知失敗: " . $e->getMessage());
        return false;
    }
}
?>