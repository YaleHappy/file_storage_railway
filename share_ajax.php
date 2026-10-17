<?php
session_start();
require 'config.php';
header('Content-Type: application/json');

// 開發模式除錯設定
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 寫入除錯日誌的函式
function writeDebugLog($message) {
    file_put_contents(__DIR__ . '/debug_log.txt', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

/**
 * ✅ 依目前請求自動組分享連結
 * - 支援 http/https
 * - 支援 localhost / 127.0.0.1 / 正式網域
 * - 支援放在子資料夾，例如 http://localhost/myapp/share_ajax.php
 */
function buildShareLink($token) {
    // 有些環境會透過反向代理，HTTPS 會放在這些 header
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $isHttps ? 'https' : 'http';

    // Host（含 port，如果有）
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // 取得目前檔案所在資料夾路徑（例如 /myapp）
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    // share.php 通常跟 share_ajax.php 同層，所以用 $dir
    return "{$scheme}://{$host}{$dir}/share.php?token=" . urlencode($token);
}

// 1️⃣ 檢查使用者是否登入
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}

// 2️⃣ 取得 POST 參數
$file_id     = intval($_POST['file_id'] ?? 0);
$expires_raw = $_POST['expires_at'] ?? null;
$pwd         = trim($_POST['share_password'] ?? '');

// 寫入除錯日誌，檢查傳入參數
writeDebugLog("POST Data: " . print_r($_POST, true));

// 檢查 file_id 是否有效
if ($file_id === 0) {
    echo json_encode(['success' => false, 'msg' => '未提供正確的檔案 ID']);
    exit;
}

// 3️⃣ 檢查檔案是否在 files 資料表中
$stmt = $pdo->prepare("SELECT file_id, stored_filename FROM files WHERE file_id = ? AND user_id = ?");
$stmt->execute([$file_id, $_SESSION['user_id']]);
$fileRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileRow) {
    echo json_encode([
        'success' => false,
        'msg' => '檔案不存在或無權限',
        'debug' => ['file_id' => $file_id, 'user_id' => $_SESSION['user_id']]
    ]);
    exit;
}

// 4️⃣ 檢查實際檔案是否存在於 uploads/
$filePath = __DIR__ . '/uploads/' . $fileRow['stored_filename'];
if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'msg' => '伺服器找不到檔案 (可能已刪除)']);
    exit;
}

// 5️⃣ 處理過期時間 expires_at
$expires = null;
if (!empty($expires_raw)) {
    // 你前端是 datetime-local，常見格式：2026-03-02T14:30
    $dateObj = DateTime::createFromFormat('Y-m-d\TH:i', $expires_raw);
    if ($dateObj) {
        $expires = $dateObj->format('Y-m-d H:i:s');
    } else {
        echo json_encode(['success' => false, 'msg' => '無效的過期時間格式']);
        exit;
    }
}

// 若沒有指定過期時間且無密碼，預設 7 天後過期
if (!$expires && empty($pwd)) {
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
}

// 6️⃣ 刪除舊的分享紀錄，保證只有最新的一個分享連結有效
$delStmt = $pdo->prepare("DELETE FROM file_shares WHERE file_id = ?");
$delStmt->execute([$file_id]);

// 7️⃣ 產生新的 share_token
$token = bin2hex(random_bytes(16));
$hashed_pwd = !empty($pwd) ? password_hash($pwd, PASSWORD_DEFAULT) : null;

// 寫入除錯日誌
writeDebugLog("file_id: {$file_id}, token: {$token}, expires: {$expires}, pwd_given: " . (!empty($pwd) ? 'yes' : 'no'));

// 8️⃣ 插入新的分享紀錄
try {
    $insert = $pdo->prepare("
        INSERT INTO file_shares (file_id, share_token, expires_at, share_password)
        VALUES (?, ?, ?, ?)
    ");
    $ok = $insert->execute([$file_id, $token, $expires, $hashed_pwd]);

    if (!$ok) {
        writeDebugLog("INSERT Error: " . print_r($insert->errorInfo(), true));
        echo json_encode(['success' => false, 'msg' => '無法產生分享紀錄 (INSERT 失敗)']);
        exit;
    }

    // 9️⃣ 生成分享連結（✅自動依環境）
    $link = buildShareLink($token);
    writeDebugLog("Share link generated: " . $link);

    echo json_encode([
        'success' => true,
        'link' => $link,
        'debug' => [
            'file_id' => $file_id,
            'expires' => $expires,
            'pwd_given' => !empty($pwd),
            'token' => $token
        ]
    ]);

} catch (PDOException $e) {
    writeDebugLog("PDOException: " . $e->getMessage());
    echo json_encode(['success' => false, 'msg' => 'SQL 錯誤: ' . $e->getMessage()]);
}
exit;