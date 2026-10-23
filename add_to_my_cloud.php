<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_activity.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 驗證使用者是否已登入
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}

// 驗證 GET 參數 id 是否存在且為數字
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'msg' => '無效的檔案 ID']);
    exit;
}

$sharedId = (int)$_GET['id'];
$recipientId = $_SESSION['user_id'];
require_once __DIR__ . '/file_cleanup.php';
cleanupMissingSharedFiles($pdo, (int) $recipientId, $sharedId);

// Only the sender or recipient may save a personal-chat attachment.
$stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ? AND (recipient_id = ? OR sender_id = ?)");
$stmt->execute([$sharedId, $recipientId, $recipientId]);
$sharedFile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sharedFile) {
    echo json_encode(['success' => false, 'msg' => '檔案不存在或無權限']);
    exit;
}

// 來源檔案路徑（例如："uploads/sent/xxxx.ext"）
$sourcePath = $sharedFile['file_path'];
$sourceFullPath = __DIR__ . '/' . $sourcePath;

if (!file_exists($sourceFullPath)) {
    echo json_encode(['success' => false, 'msg' => '原始檔案不存在']);
    exit;
}

// 使用資料庫中儲存的原始檔名
$originalFilename = $sharedFile['original_filename'];

// 設定目的資料夾：使用者的根目錄，假設存放在 uploads/ 內
$destDir = __DIR__ . '/uploads/';
if (!is_dir($destDir)) {
    if (!mkdir($destDir, 0755, true)) {
        echo json_encode(['success' => false, 'msg' => '無法建立目的目錄']);
        exit;
    }
}

require_once __DIR__ . '/cloud_storage.php';
$destFullPath = null;
try {
    $pdo->beginTransaction();
    assertCloudNameAvailable($pdo, (int)$recipientId, $originalFilename);
    $newFilename = bin2hex(random_bytes(24)) . '.bin';
    $destFullPath = $destDir . $newFilename;
    if (!copy($sourceFullPath, $destFullPath)) throw new RuntimeException('無法複製檔案');
    $stmtInsert = $pdo->prepare("INSERT INTO files (user_id, folder_id, original_filename, stored_filename, file_size, created_at) VALUES (?, 0, ?, ?, ?, NOW())");
    $stmtInsert->execute([$recipientId, $originalFilename, $newFilename, filesize($destFullPath)]);
    $savedId=(int)$pdo->lastInsertId();
    $pdo->commit();
    recordUserActivity($pdo,'儲存到雲端',$savedId,'檔名：'.$originalFilename);
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($destFullPath && is_file($destFullPath)) unlink($destFullPath);
    http_response_code($error instanceof DomainException ? 409 : 500);
    echo json_encode(['success' => false, 'msg' => $error instanceof DomainException ? $error->getMessage() : '儲存失敗，請稍後再試。']);
}
