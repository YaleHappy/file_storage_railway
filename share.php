<?php
require 'config.php';
date_default_timezone_set('Asia/Taipei');

// 1) 取得 token、download 參數
$token    = $_GET['token']    ?? null;
$download = isset($_GET['download']);
$error    = '';
$row      = null;

// 2) 檢查 token
if (!$token) {
    $error = "無效的分享連結。";
} else {
    // 查詢分享紀錄 & 檔案資訊
    $stmt = $pdo->prepare("
        SELECT f.original_filename, f.stored_filename,
               s.expires_at, s.share_password
        FROM files f
        JOIN file_shares s USING(file_id)
        WHERE s.share_token = ?
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $error = "分享連結不存在或已失效。";
    } elseif ($row['expires_at'] && new DateTime($row['expires_at']) < new DateTime()) {
        $error = "分享連結已過期。";
    }
}

// 3) 如果連結有效，且 GET 帶了 ?download=1
//    → 代表使用者想下載檔案
if (!$error && $download) {
    // 如果「沒有設密碼 (share_password 為空)」，直接下載
    if (empty($row['share_password'])) {
        doDownload($row);
        exit; // 完成下載後結束程式
    }

    // 如果「有密碼」，則要在這裡判斷
    // a) 如果 method=POST → 檢查密碼是否正確
    // b) 如果 method=GET → 顯示輸入密碼的表單
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 使用 password_verify() 與資料庫裡的雜湊比對
        if (password_verify($_POST['password'], $row['share_password'])) {
            // 密碼正確 → 直接執行下載
            doDownload($row);
            exit;
        } else {
            $error = "密碼錯誤，請再試一次。";
        }
    } 
    // 如果是 GET，或 密碼錯誤 → 後面 HTML 會顯示一個表單讓使用者輸入密碼
}

// ============ 自定義下載函式 ============
function doDownload($fileRow) {
    $path = __DIR__ . '/uploads/' . $fileRow['stored_filename'];
    if (!file_exists($path)) {
        echo "<div class='alert alert-danger text-center'>檔案不存在或已刪除。</div>";
        return;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($fileRow['original_filename']) . '"');
    readfile($path);
}

// ============ 以下為 HTML ============ 
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="image/icon.png">

    <title>雲上聊頁來｜檔案分享</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }
        .card {
            max-width: 500px;
            margin: auto;
            margin-top: 50px;
        }
        .btn-lg {
            width: 100%;
        }
    </style>
  <link href="assets/css/responsive.css?v=20260928-7" rel="stylesheet">
</head>
<body>
<div class="container py-5">
<?php
// 如果有錯誤，就顯示錯誤訊息
if ($error):
?>
    <div class="alert alert-danger text-center">
        <?= htmlspecialchars($error) ?>
    </div>
<?php
// 如果沒有錯誤
elseif (!$download):
    // [情境] 使用者只是打開 share.php?token=xxx (沒有 download=1)
    //       可能想先看看檔案資訊
?>
    <div class="card text-center">
        <div class="card-body">
            <h5 class="card-title">
                <?= htmlspecialchars($row['original_filename']) ?>
            </h5>
            <?php if ($row['expires_at']): ?>
            <p class="text-muted">
                有效期限：
                <?= (new DateTime($row['expires_at']))->format('Y-m-d H:i:s') ?>
            </p>
            <?php endif; ?>
            <!-- 提供一個「下載」按鈕，會帶 download=1 再次進入此頁面 -->
            <a href="?token=<?= urlencode($token) ?>&download=1"
               class="btn btn-success btn-lg">
               下載檔案
            </a>
            <a href="index.php" class="btn btn-secondary btn-lg mt-2">回首頁</a>

        </div>
    </div>
<?php
// 走到這邊，代表 ?download=1，但 method=GET or POST
// 需要輸入密碼 → 顯示密碼表單
else:
?>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title text-center">輸入分享密碼</h5>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <!-- 
                 注意 form action 仍是 ?token=xxx&download=1 
                 method=POST 用來傳遞 password
            -->
            <form method="post"
                  action="?token=<?= urlencode($token) ?>&download=1">
                <input type="password" name="password"
                       class="form-control mb-3" placeholder="分享密碼" required>
                <button class="btn btn-primary btn-lg">確認</button>
            </form>
        </div>
    </div>
<?php
endif;
?>
</div>
</body>
</html>
