<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "msg" => "請先登入"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shared_files WHERE recipient_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $newFiles = $stmt->fetchColumn();
    
    echo json_encode(["success" => true, "new_count" => $newFiles]);
} catch (Exception $e) {
    error_log("checkNewFiles.php SQL 錯誤: " . $e->getMessage());
    
    // 在前端顯示 SQL 錯誤 (開發測試用，正式環境請移除)
    echo json_encode([
        "success" => false,
        "msg" => "SQL 錯誤：" . $e->getMessage()
    ]);
}
exit;
