<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    exit('未登入');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit('無效的檔案 ID');
}

$id = (int) $_GET['id'];
// 查詢該檔案，確保是傳給目前使用者
$stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ? AND recipient_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    exit('檔案不存在或沒有權限');
}

// 檔案實際路徑
$filepath = __DIR__ . '/' . $file['file_path'];
if (!file_exists($filepath)) {
    exit('檔案不存在');
}

// 取得原始檔名（若無則用檔案路徑的 basename）
$downloadName = $file['original_filename'] ?: basename($filepath);

// 設定下載標頭
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
// 這裡將下載時的檔名指定為 $downloadName
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));

// 輸出檔案內容
readfile($filepath);
exit;
?>
