<?php
session_start();
require 'config.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// 從 shared_files 表中取得檔案記錄，確認該檔案是傳送給目前使用者的
$stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ? AND recipient_id = ?");
$stmt->execute([$sharedId, $recipientId]);
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

// 使用原始檔名作為新檔名，並處理同名檔案問題
$newFilename = $originalFilename;
$destFullPath = $destDir . $newFilename;
if (file_exists($destFullPath)) {
    $filenameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
    $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $counter = 1;
    do {
        $newFilename = $filenameWithoutExt . '_' . $counter . ($ext ? '.' . $ext : '');
        $destFullPath = $destDir . $newFilename;
        $counter++;
    } while (file_exists($destFullPath));
}

// 複製檔案到使用者的根目錄
if (!copy($sourceFullPath, $destFullPath)) {
    echo json_encode(['success' => false, 'msg' => '無法複製檔案']);
    exit;
}

// 取得新複製檔案的大小
$fileSize = filesize($destFullPath);

// 新增記錄到 files 表，設定 folder_id 為 0（根目錄）
$stmtInsert = $pdo->prepare("INSERT INTO files (user_id, folder_id, original_filename, stored_filename, file_size, created_at) VALUES (?, 0, ?, ?, ?, NOW())");
$result = $stmtInsert->execute([$recipientId, $originalFilename, $newFilename, $fileSize]);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'msg' => '資料庫錯誤，無法新增至我的雲端']);
}
exit;
?>
