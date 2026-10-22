<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('未登入');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit('無效的檔案 ID');
}

$id = (int) $_GET['id'];
require_once __DIR__ . '/file_cleanup.php';
cleanupMissingSharedFiles($pdo, (int) $_SESSION['user_id'], $id);
// 查詢該檔案，確保是傳給目前使用者
$stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ? AND (recipient_id = ? OR sender_id = ?)");
$stmt->execute([$id, $_SESSION['user_id'], $_SESSION['user_id']]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file || (isset($_GET['preview']) && (int)$file['recipient_id'] !== (int)$_SESSION['user_id'])) {
    http_response_code(404);
    exit('檔案不存在或沒有權限');
}

// 檔案實際路徑
$filepath = __DIR__ . '/' . $file['file_path'];
if (!file_exists($filepath)) {
    cleanupMissingSharedFiles($pdo, (int) $_SESSION['user_id'], $id);
    http_response_code(404);
    exit('檔案不存在');
}

// 取得原始檔名（若無則用檔案路徑的 basename）
$downloadName = $file['original_filename'] ?: basename($filepath);

if (isset($_GET['preview'])) {
    $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf','txt'=>'text/plain; charset=utf-8','csv'=>'text/plain; charset=utf-8','mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg'];
    $extension = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    if (isset($types[$extension])) {
        header('Content-Type: ' . $types[$extension]);
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($downloadName));
        readfile($filepath);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<body style="background:#181a2f;color:#e9edff;font:16px sans-serif;padding:40px"><h2>此格式暫不支援預覽</h2><p>請下載後使用對應的應用程式開啟。</p><a style="color:#a9c5ff" href="download_shared_file.php?id=' . $id . '">下載檔案</a></body>';
    }
    exit;
}

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
