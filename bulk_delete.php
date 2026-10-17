<?php
session_start();
require 'config.php';

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
$stmt_select = $pdo->prepare("SELECT stored_filename FROM files WHERE file_id = ? AND user_id = ?");
// 準備刪除資料庫中的資料列
$stmt_delete = $pdo->prepare("DELETE FROM files WHERE file_id = ? AND user_id = ?");

foreach ($ids as $id) {
    if (is_numeric($id)) {
        // 取得檔案資訊
        $stmt_select->execute([$id, $user_id]);
        $file = $stmt_select->fetch(PDO::FETCH_ASSOC);
        if ($file) {
            // 組合實體檔案路徑（假設檔案存放在 uploads 資料夾下）
            $filePath = __DIR__ . '/uploads/' . $file['stored_filename'];
            // 如果檔案存在，則刪除
            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    // 若無法刪除實體檔案，記錄錯誤並跳過此筆
                    error_log("無法刪除檔案：$filePath");
                    continue;
                }
            }
            // 刪除資料庫中的資料列
            $stmt_delete->execute([$id, $user_id]);
            if ($stmt_delete->rowCount() > 0) {
                $deletedCount++;
            }
        }
    }
}

if ($deletedCount > 0) {
    echo json_encode(['success' => true, 'msg' => "成功刪除 {$deletedCount} 項目"]);
} else {
    echo json_encode(['success' => false, 'msg' => '刪除失敗或沒有符合條件的檔案']);
}
?>
