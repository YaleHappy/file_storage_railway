<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false, 'msg'=>'未登入']);
    exit;
}

$file_id    = (int)($_POST['file_id'] ?? 0);
$targetFld  = (int)($_POST['target_folder'] ?? 0);

// 更新資料庫 → 移動檔案至新資料夾
$stmt = $pdo->prepare("
    UPDATE files
    SET folder_id = ?
    WHERE file_id = ?
      AND user_id = ?
");
$ok = $stmt->execute([$targetFld, $file_id, $_SESSION['user_id']]);

if ($ok) {
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'msg'=>'移動失敗']);
}
