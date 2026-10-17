<?php
session_start();
require 'config.php';

// 確保沒有其他輸出
ob_start();
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);
mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");


// ✅ 加上這兩行來避免 413 問題
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');

// 防止大檔案上傳超時
ini_set('max_execution_time', 300);

$maxTotalSize = 100 * 1024 * 1024;
$maxFileSize = 100 * 1024 * 1024;

// 驗證 post_max_size
$maxPostSize = (int)ini_get('post_max_size') * 1024 * 1024;
if ($_SERVER['CONTENT_LENGTH'] > $maxPostSize) {
    echo json_encode(["success" => false, "error" => "總檔案大小超過 post_max_size 限制"]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "error" => "未登入"]);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
    echo json_encode(["success" => false, "error" => "沒有選擇檔案"]);
    exit;
}

$errors = [];
$successCount = 0;
$totalFiles = count($_FILES['files']['name']);
$totalUploadSize = 0;

// 計算總檔案大小
foreach ($_FILES['files']['size'] as $size) {
    $totalUploadSize += $size;
}
if ($totalUploadSize > $maxTotalSize) {
    echo json_encode(["success" => false, "error" => "所有檔案加總超過 100MB 限制"]);
    exit;
}

for ($i = 0; $i < $totalFiles; $i++) {
    $originalName = $_FILES['files']['name'][$i];
    $tempPath = $_FILES['files']['tmp_name'][$i];
    $fileSize = $_FILES['files']['size'][$i];
    $errorCode = $_FILES['files']['error'][$i];

    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = "檔案 $originalName 上傳錯誤 (錯誤碼: $errorCode)";
        continue;
    }

    if ($fileSize > $maxFileSize) {
        $errors[] = "檔案 $originalName 過大 (最大 100MB)";
        continue;
    }

    // 處理空格與特殊符號（基本清理）
    $safeName = preg_replace('/[^\w\-. ]+/u', '_', $originalName);

    $targetPath = $uploadDir . $safeName;
    $storedFilename = $safeName;

    if (file_exists($targetPath)) {
        $filenameWithoutExt = pathinfo($safeName, PATHINFO_FILENAME);
        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        $counter = 1;
        do {
            $storedFilename = $filenameWithoutExt . '_' . $counter . ($ext ? '.' . $ext : '');
            $targetPath = $uploadDir . $storedFilename;
            $counter++;
        } while (file_exists($targetPath));
    }

    if (!move_uploaded_file($tempPath, $targetPath)) {
        $errors[] = "檔案 $originalName 無法移動";
        continue;
    }

    $stmt = $pdo->prepare("
        INSERT INTO files (user_id, folder_id, original_filename, stored_filename, file_size, created_at)
        VALUES (:uid, :folder, :orig, :stored, :fsize, NOW())
    ");
    $result = $stmt->execute([
        ':uid'    => $_SESSION['user_id'],
        ':folder' => $_POST['folder_id'] ?? 0,
        ':orig'   => $originalName,
        ':stored' => $storedFilename,
        ':fsize'  => $fileSize
    ]);

    if ($result) {
        $successCount++;
    } else {
        $errors[] = "檔案 $originalName 無法寫入資料庫";
    }
}

// 清掉前面所有可能非預期輸出
ob_clean();

if ($successCount > 0) {
    echo json_encode(["success" => true, "uploaded" => $successCount, "errors" => $errors]);
} else {
    echo json_encode(["success" => false, "error" => implode("; ", $errors)]);
}
exit;
?>
