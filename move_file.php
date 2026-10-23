<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_activity.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false, 'msg'=>'未登入']);
    exit;
}

$file_id    = (int)($_POST['file_id'] ?? 0);
$targetFld  = (int)($_POST['target_folder'] ?? 0);
require_once __DIR__ . '/folder_access.php';
if (!ownsStorageFolder($pdo, (int)$_SESSION['user_id'], $targetFld)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'msg'=>'目標資料夾不存在或沒有權限']); exit;
}
$exists = $pdo->prepare('SELECT file_id FROM files WHERE file_id=? AND user_id=?');
$exists->execute([$file_id,$_SESSION['user_id']]);
if (!$exists->fetchColumn()) { http_response_code(404); echo json_encode(['success'=>false,'msg'=>'找不到檔案']); exit; }

// 更新資料庫 → 移動檔案至新資料夾
$stmt = $pdo->prepare("
    UPDATE files
    SET folder_id = ?
    WHERE file_id = ?
      AND user_id = ?
");
$ok = $stmt->execute([$targetFld, $file_id, $_SESSION['user_id']]);

if ($ok) {
    recordUserActivity($pdo,'移動檔案',$file_id,'目標資料夾編號：'.$targetFld);
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'msg'=>'移動失敗']);
}
