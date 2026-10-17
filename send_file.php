<?php
// 確保任何未預期的輸出都不會破壞 JSON 響應
ob_start();

// 嚴格的錯誤處理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 捕獲致命錯誤
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        // 清除之前的輸出
        ob_clean();
        
        // 輸出 JSON 格式的錯誤訊息
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'msg' => '系統發生嚴重錯誤：' . $error['message'],
            'error_details' => $error
        ]);
        exit;
    }
});

session_start();
require 'config.php';
require 'add_notification.php'; // 引入通知功能

// 開發階段詳細錯誤報告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 確保純 JSON 輸出
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// 增加更多除錯日誌
error_log("收到的 POST: " . json_encode($_POST));
error_log("收到的 FILES: " . json_encode($_FILES));

if (!isset($_SESSION['user_id'])) {
    error_log("傳送檔案失敗：未登入");
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}

if (!isset($_POST['recipient_id']) || empty($_POST['recipient_id'])) {
    error_log("傳送檔案失敗：缺少收件人資訊");
    echo json_encode(['success' => false, 'msg' => '缺少收件人資訊']);
    exit;
}




$recipient_id = (int) $_POST['recipient_id'];
$sender_id    = $_SESSION['user_id'];

$file_option = isset($_POST['file_option']) ? $_POST['file_option'] : 'upload';

// 設定共享資料夾 (用來存放傳送後的檔案副本)
$sharedDir = 'uploads/shared/';
if (!is_dir($sharedDir)) {
    mkdir($sharedDir, 0755, true);
}

$originalFilename = '';

try {
    if ($file_option === 'select') {
        // 使用者選擇已上傳的檔案
        if (!isset($_POST['existing_file_id']) || trim($_POST['existing_file_id']) === '') {
            error_log("傳送檔案失敗：未選擇已上傳檔案");
            echo json_encode(['success' => false, 'msg' => '請選擇已上傳的檔案']);
            exit;
        }
        $existingFileId = (int) trim($_POST['existing_file_id']);
        
        // 取出檔案資料：存放檔名與原始檔名
        $stmtFile = $pdo->prepare("SELECT stored_filename, folder_id, original_filename FROM files WHERE file_id = ? AND user_id = ?");
        $stmtFile->execute([$existingFileId, $sender_id]);
        $fileData = $stmtFile->fetch(PDO::FETCH_ASSOC);
        
        if (!$fileData) {
            error_log("傳送檔案失敗：找不到檔案或無權限");
            echo json_encode(['success' => false, 'msg' => '檔案不存在或權限不足']);
            exit;
        }
        
        // 假設上傳的檔案存在於 uploads/ 目錄中
        $sourceFile = 'uploads/' . $fileData['stored_filename'];
        $originalFilename = $fileData['original_filename'];

        // 確保檔案存在
        if (!file_exists($sourceFile)) {
            error_log("傳送檔案失敗：來源檔案不存在 {$sourceFile}");
            echo json_encode(['success' => false, 'msg' => '來源檔案不存在']);
            exit;
        }

        // 複製檔案到共享資料夾，處理同名問題
        $sharedFilename = $originalFilename;
        $targetFile = $sharedDir . $sharedFilename;
        
        if (file_exists($targetFile)) {
            $filenameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
            $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $counter = 1;
            do {
                $sharedFilename = $filenameWithoutExt . '_' . $counter . ($ext ? '.' . $ext : '');
                $targetFile = $sharedDir . $sharedFilename;
                $counter++;
            } while (file_exists($targetFile));
        }
        
        if (!copy($sourceFile, $targetFile)) {
            error_log("傳送檔案失敗：無法複製檔案到共享資料夾");
            echo json_encode(['success' => false, 'msg' => '無法複製檔案到共享資料夾']);
            exit;
        }
    } else {
        // 使用上傳新檔案的方式傳送
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? 'UNKNOWN';
            error_log("檔案上傳失敗，錯誤碼: {$errorCode}");
            
            // 更詳細的上傳錯誤訊息
            $uploadErrorMessages = [
                UPLOAD_ERR_INI_SIZE => '上傳檔案超過 PHP 設定的大小限制',
                UPLOAD_ERR_FORM_SIZE => '上傳檔案超過 HTML 表單指定的大小限制',
                UPLOAD_ERR_PARTIAL => '檔案只有部分被上傳',
                UPLOAD_ERR_NO_FILE => '沒有檔案被上傳',
                UPLOAD_ERR_NO_TMP_DIR => '找不到暫存資料夾',
                UPLOAD_ERR_CANT_WRITE => '無法將檔案寫入磁碟',
                UPLOAD_ERR_EXTENSION => '檔案上傳被 PHP 擴充功能阻止'
            ];
            
            $errorMessage = $uploadErrorMessages[$errorCode] ?? '未知的上傳錯誤';
            echo json_encode(['success' => false, 'msg' => $errorMessage]);
            exit;
        }
        
        $uploadDir = 'uploads/sent/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            error_log("傳送檔案失敗：無法建立上傳目錄");
            echo json_encode(['success' => false, 'msg' => '無法建立上傳目錄']);
            exit;
        }
        
        if (!is_writable($uploadDir)) {
            error_log("傳送檔案失敗：上傳目錄不可寫入");
            echo json_encode(['success' => false, 'msg' => '上傳目錄不可寫入']);
            exit;
        }
        
        // 使用原始檔名
        $filename = basename($_FILES['file']['name']);
        
        // 組合目標路徑，處理同名問題 (加上序號)
        $targetFileSent = $uploadDir . $filename;
        $storedFilename = $filename;
        
        if (file_exists($targetFileSent)) {
            $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $counter = 1;
            do {
                $storedFilename = $filenameWithoutExt . '_' . $counter . ($ext ? '.' . $ext : '');
                $targetFileSent = $uploadDir . $storedFilename;
                $counter++;
            } while (file_exists($targetFileSent));
        }
        
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetFileSent)) {
            error_log("傳送檔案失敗：無法儲存上傳檔案");
            echo json_encode(['success' => false, 'msg' => '無法儲存上傳檔案']);
            exit;
        }
        
        $originalFilename = $filename;
        
        // 複製檔案到共享資料夾，處理同名問題
        $sharedFilename = $originalFilename;
        $targetFile = $sharedDir . $sharedFilename;
        
        if (file_exists($targetFile)) {
            $filenameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);
            $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $counter = 1;
            do {
                $sharedFilename = $filenameWithoutExt . '_' . $counter . ($ext ? '.' . $ext : '');
                $targetFile = $sharedDir . $sharedFilename;
                $counter++;
            } while (file_exists($targetFile));
        }
        
        if (!copy($targetFileSent, $targetFile)) {
            error_log("傳送檔案失敗：無法複製檔案到共享資料夾");
            echo json_encode(['success' => false, 'msg' => '無法複製檔案到共享資料夾']);
            exit;
        }
    }

    // 儲存傳送紀錄，同時保存原始檔名及共享目錄中的檔案路徑
    try {
        // 假設 shared_files 資料表中有 original_filename 欄位
        $stmt = $pdo->prepare("INSERT INTO shared_files (sender_id, recipient_id, file_path, original_filename, sent_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$sender_id, $recipient_id, $targetFile, $originalFilename]);
        
        // 新增檔案傳送通知
        $stmtUser = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmtUser->execute([$sender_id]);
        $senderName = $stmtUser->fetchColumn();
        
        $notificationMessage = $senderName . " 傳送了一個檔案給你: " . $originalFilename;
        addNotification($sender_id, $recipient_id, 'file', $notificationMessage);
        
        error_log("檔案傳送成功：{$originalFilename} 傳送給使用者 {$recipient_id}");
        
        echo json_encode(['success' => true]);
        
    } catch (PDOException $e) {
        error_log("資料庫錯誤: " . $e->getMessage());
        echo json_encode(['success' => false, 'msg' => '資料庫錯誤：' . $e->getMessage()]);
        exit;
    }
} catch (Exception $e) {
    error_log("檔案傳送發生未預期錯誤: " . $e->getMessage());
    echo json_encode(['success' => false, 'msg' => '檔案傳送發生未預期錯誤：' . $e->getMessage()]);
}
exit;
?>


