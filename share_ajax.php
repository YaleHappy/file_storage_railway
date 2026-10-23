<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_activity.php';
header('Content-Type: application/json');

ini_set('display_errors', '0');
require_once __DIR__.'/share_expiry.php';

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

// 檢查 file_id 是否有效
if ($file_id === 0) {
    echo json_encode(['success' => false, 'msg' => '未提供正確的檔案 ID']);
    exit;
}

// 3️⃣ 檢查檔案是否在 files 資料表中
require_once __DIR__ . '/file_cleanup.php';
cleanupMissingUserFiles($pdo, (int) $_SESSION['user_id'], $file_id);
$stmt = $pdo->prepare("SELECT file_id, stored_filename FROM files WHERE file_id = ? AND user_id = ?");
$stmt->execute([$file_id, $_SESSION['user_id']]);
$fileRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileRow) {
    echo json_encode([
        'success' => false,
        'msg' => '檔案不存在或無權限',
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
try { $expires=parseShareExpiry((string)($expires_raw??'')); }
catch(InvalidArgumentException $error){http_response_code(400);echo json_encode(['success'=>false,'msg'=>$error->getMessage()]);exit;}
// Password protection does not change the default lifetime.
if ($expires===null) $expires=date('Y-m-d H:i:s',strtotime('+7 days'));
// 6️⃣ 刪除舊的分享紀錄，保證只有最新的一個分享連結有效

// 7️⃣ 產生新的 share_token
$token = bin2hex(random_bytes(16));
$hashed_pwd = !empty($pwd) ? password_hash($pwd, PASSWORD_DEFAULT) : null;

// 寫入除錯日誌

// 8️⃣ 插入新的分享紀錄
try {
    $pdo->beginTransaction();
    $delStmt = $pdo->prepare('DELETE FROM file_shares WHERE file_id = ?');
    $delStmt->execute([$file_id]);
    $insert = $pdo->prepare("
        INSERT INTO file_shares (file_id, share_token, expires_at, share_password)
        VALUES (?, ?, ?, ?)
    ");
    $ok = $insert->execute([$file_id, $token, $expires, $hashed_pwd]);

    if (!$ok) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'msg' => '無法產生分享紀錄 (INSERT 失敗)']);
        exit;
    }

    $pdo->commit();
    recordUserActivity($pdo,'建立分享連結',$file_id,'檔案編號：'.$file_id.'；到期：'.$expires);
    // 9️⃣ 生成分享連結（✅自動依環境）
    $link = buildShareLink($token);

    echo json_encode([
        'success' => true,
        'link' => $link
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'msg' => '分享連結建立失敗，原有設定已保留，請稍後再試。']);
}
exit;
