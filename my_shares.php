<?php
session_start();
require 'config.php';

// 如果沒有登入 → 跳回 login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/file_cleanup.php';
cleanupMissingUserFiles($pdo, (int) $_SESSION['user_id']);

header('Content-Type: text/html; charset=utf-8');

// ✅ 依目前請求自動組 share.php 連結
function buildShareLink($token) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // 取得目前檔案所在資料夾路徑（例如 /file_storage）
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return "{$scheme}://{$host}{$dir}/share.php?token=" . urlencode($token);
}

// ==============================
//  (A) 刪除分享 (AJAX)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $stmt = $pdo->prepare("
        DELETE FROM file_shares
        WHERE share_id = :share_id
          AND file_id IN (
            SELECT file_id FROM files WHERE user_id = :user_id
          )
        LIMIT 1
    ");
    $stmt->execute([
        ':share_id' => $_POST['delete'],
        ':user_id'  => $_SESSION['user_id']
    ]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ==============================
//  (B) 更新到期時間 / 密碼 (AJAX)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $shareId = (int)($_POST['share_id'] ?? 0);

    // 更新到期時間（datetime-local 會是 2026-03-02T14:30）
    if (isset($_POST['expires_at'])) {
        $expiresRaw = $_POST['expires_at'];

        $expires = null;
        if ($expiresRaw !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $expiresRaw);
            if ($dt) {
                $expires = $dt->format('Y-m-d H:i:s');
            }
        }

        $sql = "
            UPDATE file_shares 
            SET expires_at = ?
            WHERE share_id = ?
              AND file_id IN (
                SELECT file_id FROM files WHERE user_id = ?
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $expires,               // 允許設為 NULL（清空到期）
            $shareId,
            $_SESSION['user_id']
        ]);
    }

    // 更新密碼（輸入空白 = 不更新；你也可以改成空白就清除密碼）
    if (isset($_POST['share_password'])) {
        $newPwd = trim($_POST['share_password']);

        if ($newPwd !== '') {
            $hashed = password_hash($newPwd, PASSWORD_DEFAULT);

            $sql = "
                UPDATE file_shares
                SET share_password = ?
                WHERE share_id = ?
                  AND file_id IN (
                    SELECT file_id FROM files WHERE user_id = ?
                  )
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $hashed,
                $shareId,
                $_SESSION['user_id']
            ]);
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ==============================
//  (C) 分頁設定
// ==============================
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ==============================
//  (D) 查詢該用戶所有分享的檔案
// ==============================
$sql = "
    SELECT
      f.original_filename,
      s.share_token,
      s.expires_at,
      s.share_password,
      s.share_id
    FROM file_shares s
    JOIN files f ON s.file_id = f.file_id
    WHERE f.user_id = :uid
    ORDER BY s.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':uid',   $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage,             PDO::PARAM_INT);
$stmt->bindValue(':offset',$offset,              PDO::PARAM_INT);
$stmt->execute();
$shares = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==============================
//  (E) 計算總數量(分頁用)
// ==============================
$sqlCount = "
    SELECT COUNT(*) 
    FROM file_shares s 
    JOIN files f ON s.file_id = f.file_id 
    WHERE f.user_id = ?
";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute([$_SESSION['user_id']]);
$totalShares = $stmtCount->fetchColumn();
$totalPages  = ceil($totalShares / $perPage);

// ✅ 確保 session status 存在
if (!isset($_SESSION['status']) && isset($_SESSION['user_id'])) {
    $stmtS = $pdo->prepare("SELECT status FROM users WHERE user_id = ?");
    $stmtS->execute([$_SESSION['user_id']]);
    $_SESSION['status'] = $stmtS->fetchColumn() ?: 'online';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">
  <title>雲上聊頁來｜我的分享連結</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body { background:#181a2f; color:#fff; padding-top:5rem; font-family:Arial,sans-serif; }
    .container { max-width:1200px; }
    .table-container { background:#222; border-radius:12px; padding:20px; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .table-dark th, .table-dark td { vertical-align:middle; text-align:center; }
    .form-control { background:#2c2f4b !important; border:1px solid #444; color:#fff; }
    .form-control:focus { border-color:#3498db; background:#2c2f4b; color:#fff; }
    .link-container { word-break:break-all; white-space:normal; }
    .link-container input { width:100%; background:transparent; border:none; color:#fff; text-align:center; cursor:text; }
    .link-container input:focus { outline:none; }
    .btn { border-radius:6px; transition:0.3s ease-in-out; }
    .btn-copy { background:#3498db; border:none; color:white; padding:6px 12px; }
    .btn-copy:hover { background:#2980b9; }
    .btn-danger { background-color:#e74c3c; border:none; padding:6px 12px; }
    .btn-danger:hover { background-color:#c0392b; }
  </style>
  <link href="responsive.css?v=20261017-10" rel="stylesheet">
</head>
<body class="has-fixed-navbar">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">☁️ 雲上聊頁來</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sharesNavbar" aria-controls="sharesNavbar" aria-expanded="false" aria-label="切換導覽選單">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="sharesNavbar">
    <ul class="navbar-nav ms-auto">
      <li class="nav-item"><a class="nav-link" href="my_shares.php">🔗 我的分享</a></li>
      <li class="nav-item"><a class="nav-link" href="received_files.php">📥 收到的檔案</a></li>
      <li class="nav-item"><a class="nav-link" href="chat_history.php"><i class="bi bi-clock-history me-1"></i>聊天紀錄</a></li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          👤 <?= htmlspecialchars($_SESSION['username']) ?> (<span id="currentStatus">
            <?php
              $statusTexts = [
                'online' => '🟢 在線',
                'idle' => '🌙 閒置',
                'away' => '🔴 請勿打擾',
                'hidden' => '⚪ 隱形',
                'offline' => '⚪ 離線'
              ];
              echo htmlspecialchars($statusTexts[$_SESSION['status']] ?? '🟢 在線');
            ?>
          </span>)
        </a>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
          <li><a class="dropdown-item status-option" data-status="online" href="#">🟢 在線（自動）</a></li>
          <li><a class="dropdown-item status-option" data-status="idle" href="#">🌙 閒置</a></li>
          <li><a class="dropdown-item status-option" data-status="away" href="#">🔴 請勿打擾</a></li>
          <li><a class="dropdown-item status-option" data-status="hidden" href="#">⚪ 隱形</a></li>
        </ul>
      </li>
      <li class="nav-item navbar-session-actions"><a class="nav-link" href="logout.php">登出</a>
        <?php require __DIR__ . '/navbar_notifications.php'; ?>
      </li>
    </ul>
    </div>
  </div>
</nav>

<div class="container">
  <div class="table-container mt-4">
    <h2 class="mb-4 text-center text-info">
      <i class="bi bi-link-45deg"></i> 我的分享連結
    </h2>

    <?php if (empty($shares)): ?>
      <p class="text-center text-muted">目前沒有分享連結。</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-dark table-hover align-middle text-center">
        <thead>
          <tr>
            <th>檔案名稱</th>
            <th>分享連結</th>
            <th>到期日</th>
            <th>密碼</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($shares as $s):
          // ✅ 改成自動產生（本機/線上通用）
          $link = buildShareLink($s['share_token']);

          // datetime-local 需要 "YYYY-MM-DDTHH:MM"
          $expiresLocal = '';
          if (!empty($s['expires_at'])) {
            $expiresLocal = date('Y-m-d\TH:i', strtotime($s['expires_at']));
          }
        ?>
          <tr>
            <td><?= htmlspecialchars($s['original_filename']) ?></td>

            <td class="link-container">
              <input type="text" value="<?= htmlspecialchars($link) ?>" readonly>
              <button class="btn btn-sm btn-copy ms-2" data-link="<?= htmlspecialchars($link) ?>">
                <i class="bi bi-clipboard"></i> 複製
              </button>
            </td>

            <td>
              <input type="datetime-local"
                     class="form-control expires"
                     data-id="<?= (int)$s['share_id'] ?>"
                     value="<?= htmlspecialchars($expiresLocal) ?>">
            </td>

            <td>
              <?php if (!empty($s['share_password'])): ?>
                <input type="text"
                       class="form-control password"
                       data-id="<?= (int)$s['share_id'] ?>"
                       placeholder="(已設定密碼)"
                       value="">
              <?php else: ?>
                <input type="text"
                       class="form-control password"
                       data-id="<?= (int)$s['share_id'] ?>"
                       placeholder="(未設定)"
                       value="">
              <?php endif; ?>
            </td>

            <td>
              <button class="btn btn-danger btn-sm delete-btn" data-id="<?= (int)$s['share_id'] ?>">
                <i class="bi bi-trash"></i> 刪除
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav>
      <ul class="pagination justify-content-center">
        <?php for ($i=1;$i<=$totalPages;$i++): ?>
          <li class="page-item <?= ($i==$page) ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// 更新狀態
document.querySelectorAll('.status-option').forEach(item => {
  item.addEventListener('click', function(e) {
    e.preventDefault();
    const newStatus = this.getAttribute('data-status');

    fetch('update_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `status=${newStatus}`
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const map = { online:'🟢 在線', idle:'🌙 閒置', away:'🔴 請勿打擾', hidden:'⚪ 隱形' };
        document.getElementById('currentStatus').textContent = map[newStatus] || '⚪ 未知';
      } else {
        alert(data.error || '更新狀態失敗');
      }
    })
    .catch(() => alert('無法更新狀態，請檢查網路或伺服器錯誤'));
  });
});

// 複製連結
document.querySelectorAll('.btn-copy').forEach(btn => {
  btn.addEventListener('click', () => {
    const link = btn.dataset.link;
    navigator.clipboard.writeText(link).then(() => alert("分享連結已複製到剪貼簿！"));
  });
});

// AJAX 刪除
document.querySelectorAll('.delete-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    if (confirm("確定要刪除此分享連結嗎？")) {
      fetch("my_shares.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `delete=${btn.dataset.id}`
      })
      .then(r => r.json())
      .then(() => location.reload());
    }
  });
});

// AJAX 更新到期時間
document.querySelectorAll('.expires').forEach(input => {
  input.addEventListener('change', () => {
    const shareId = input.dataset.id;
    const val = encodeURIComponent(input.value);
    fetch("my_shares.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `update=1&share_id=${shareId}&expires_at=${val}`
    });
  });
});

// AJAX 更新密碼
document.querySelectorAll('.password').forEach(input => {
  input.addEventListener('change', () => {
    const shareId = input.dataset.id;
    const newPwd  = encodeURIComponent(input.value);
    fetch("my_shares.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `update=1&share_id=${shareId}&share_password=${newPwd}`
    });
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="presence.js?v=20260928-2"></script>
<script src="message_notifications.js?v=20260928-2"></script>
<script src="navbar_notifications.js?v=20261017-2"></script>
</body>
</html>
