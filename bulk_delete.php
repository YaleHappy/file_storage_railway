<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_activity.php';

// 設定回應為 JSON 格式
header('Content-Type: application/json');

// 檢查是否已登入
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}

// 讀取原始輸入並解碼 JSON
$input = file_get_contents("php://input");
$data = json_decode($input, true);
if (!$data || !isset($data['ids'])) {
    echo json_encode(['success' => false, 'msg' => '無效的輸入']);
    exit;
}

$ids = $data['ids'];
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'msg' => '沒有選擇要刪除的項目']);
    exit;
}

$user_id = $_SESSION['user_id'];
$deletedCount = 0;

// 準備查詢該筆檔案的資訊，使用資料表中的 stored_filename 欄位
$stmt_select = $pdo->prepare("SELECT stored_filename, original_filename FROM files WHERE file_id = ? AND user_id = ?");
// 準備刪除資料庫中的資料列
$stmt_delete = $pdo->prepare("DELETE FROM files WHERE file_id = ? AND user_id = ?");

require_once __DIR__ . '/file_cleanup.php';
foreach ($ids as $id) {
    if (!ctype_digit((string)$id) || (int)$id <= 0) continue;
    $stmt_select->execute([$id, $user_id]);
    $file = $stmt_select->fetch(PDO::FETCH_ASSOC);
    if (!$file || basename($file['stored_filename']) !== $file['stored_filename']) continue;
    $filePath = __DIR__ . '/uploads/' . $file['stored_filename'];
    try {
        $pdo->beginTransaction();
        foreach (['file_shares','preview_tokens'] as $table) {
            if (cleanupTableExists($pdo,$table)) $pdo->prepare("DELETE FROM $table WHERE file_id = ?")->execute([$id]);
        }
        $stmt_delete->execute([$id,$user_id]);
        $count = $stmt_delete->rowCount();
        $pdo->commit();
        $deletedCount += $count;
        if($count) recordUserActivity($pdo,'刪除檔案',(int)$id,'檔名：'.$file['original_filename']);
        // A database failure must never leave a listed record pointing at a deleted file.
        if ($count && is_file($filePath) && !@unlink($filePath)) error_log('已移除檔案紀錄，但實體副本未能清除');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('刪除檔案失敗：' . $error->getMessage());
    }
}

if ($deletedCount > 0) {
    echo json_encode(['success' => true, 'msg' => "成功刪除 {$deletedCount} 項目"]);
} else {
    echo json_encode(['success' => false, 'msg' => '刪除失敗或沒有符合條件的檔案']);
}
?>
