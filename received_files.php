<?php
session_start();
require 'config.php';

// (1) 確認是否已登入
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// (2) 若尚未設定狀態，從資料庫載入或預設 online
if (!isset($_SESSION['status'])) {
    $stmt = $pdo->prepare("SELECT status FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['status'] = $stmt->fetchColumn() ?: 'online';
}

// (3) 處理刪除請求（批次刪除）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
  // 收集勾選的 shared_files.id
  $ids = $_POST['delete'] ?? [];
  if (!empty($ids)) {
      // 事先準備好 SELECT 與 DELETE
      $stmtSelect = $pdo->prepare("SELECT file_path FROM shared_files WHERE id = ? AND recipient_id = ?");
      $stmtDel    = $pdo->prepare("DELETE FROM shared_files WHERE id = ? AND recipient_id = ?");

      foreach ($ids as $id) {
          // 先查詢檔案路徑
          $stmtSelect->execute([$id, $_SESSION['user_id']]);
          $fileInfo = $stmtSelect->fetch(PDO::FETCH_ASSOC);
          if ($fileInfo) {
              // 組合實體檔案的完整路徑
              // 假設 file_path 內已含 "uploads/sent/檔名" 或 "uploads/shared/檔名" 
              // 如果只存「檔名」，則需自行補上目錄路徑。
              $fileFullPath = __DIR__ . '/' . $fileInfo['file_path'];

              // 確認檔案存在後再刪除
              if (file_exists($fileFullPath)) {
                  unlink($fileFullPath);
              }
          }
          // 刪除資料庫紀錄
          $stmtDel->execute([$id, $_SESSION['user_id']]);
      }
  }
  // 重新導向避免重複提交
  header("Location: received_files.php");
  exit;
}

// (4) 使用者進入「收到的檔案」頁面時，將尚未讀取的檔案標記為已讀
$stmtMarkRead = $pdo->prepare("UPDATE shared_files SET is_read = 1 WHERE recipient_id = ? AND is_read = 0");
$stmtMarkRead->execute([$_SESSION['user_id']]);

// (4) 撈取目前使用者收到的檔案
$stmt = $pdo->prepare("
    SELECT sf.*, u.username AS sender_name
    FROM shared_files sf
    JOIN users u ON sf.sender_id = u.user_id
    WHERE sf.recipient_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">

  <title>雲上聊頁來｜收到的檔案</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {
      background: #1a1a2e;
      color: #fff;
      padding-top: 70px; /* 考量固定導覽列 */
    }
    .navbar {
      background: #343a40;
    }
    .card-file {
      background: #25274d;
      border-radius: 10px;
      transition: box-shadow .2s;
    }
    .card-file:hover {
      box-shadow: 0 4px 12px rgba(217, 240, 15, 0.1);
    }
    .file-card {
      position: relative;
    }
  </style>
  <link href="responsive.css?v=20261017-9" rel="stylesheet">
</head>
<body class="has-fixed-navbar">

<!-- 導覽列 (可與 index.php 相同) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">☁️ 雲上聊頁來</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#receivedNavbar" aria-controls="receivedNavbar" aria-expanded="false" aria-label="切換導覽選單">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="receivedNavbar">
    <ul class="navbar-nav ms-auto">
      <li class="nav-item">
        <a class="nav-link" href="my_shares.php">🔗 我的分享</a>
      </li>
      <li class="nav-item">
        <a class="nav-link active" href="received_files.php">📥 收到的檔案</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="chat_history.php"><i class="bi bi-clock-history me-1"></i>聊天紀錄</a>
      </li>
      <!-- 狀態下拉 (與 index.php 一致) -->
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          👤 <?= htmlspecialchars($_SESSION['username']) ?> (<span id="currentStatus">
            <?php
              $statusTexts = [
                'online' => '🟢 在線',
                'idle'   => '🌙 閒置',
                'away'   => '🔴 請勿打擾',
                'hidden' => '⚪ 隱形',
                'offline'=> '⚪ 離線'
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

<!-- 主內容區 -->
<div class="container py-5">
  <h1 class="mb-4 text-center">收到的檔案</h1>

  <?php if (empty($files)): ?>
    <div class="alert alert-info text-center">目前沒有收到任何檔案。</div>
  <?php else: ?>
    <!-- 批次刪除表單 -->
    <form method="post" id="deleteForm">
      <div class="mb-3 d-flex align-items-center bulk-actions">
        <input type="checkbox" id="selectAll" class="me-2">
        <label class="btn btn-primary me-2" for="selectAll">全選</label>
        <button type="submit" name="delete_files" class="btn btn-danger" onclick="return confirm('確定刪除選取的檔案？');">
          刪除選取檔案
        </button>
      </div>

      <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-4">
        <?php foreach ($files as $file): ?>
          <div class="col">
            <div class="card card-file file-card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <span>來自 <?= htmlspecialchars($file['sender_name']) ?></span>
                <!-- 單筆勾選 -->
                <input type="checkbox" name="delete[]" value="<?= $file['id'] ?>">
              </div>
              <div class="card-body text-center">
                <i class="bi bi-file-earmark-fill fs-1"></i>
                <h5 class="card-title mt-2"><?= htmlspecialchars($file['original_filename']) ?></h5>
                <p class="card-text">傳送時間：<?= htmlspecialchars($file['sent_at']) ?></p>
              </div>
              <div class="card-footer bg-dark border-0 text-center">
                <!-- 下載連結 -->
                <a href="download_shared_file.php?id=<?= $file['id'] ?>" class="btn btn-success btn-sm">
                  <i class="bi bi-download"></i> 下載
                </a>
                <!-- 新增到自己雲端 -->
                <button type="button" class="btn btn-warning btn-sm" onclick="addToMyCloud(<?= $file['id'] ?>)">
                  <i class="bi bi-cloud-arrow-up"></i> 新增到我的雲端
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </form>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ========== 切換使用者狀態 ==========
document.querySelectorAll('.status-option').forEach(item => {
  item.addEventListener('click', function(e) {
    e.preventDefault();
    const newStatus = this.getAttribute('data-status');
    fetch('update_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `status=${newStatus}`
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const statusTextMap = {
          online: '🟢 在線',
          idle: '🌙 閒置',
          away: '🔴 請勿打擾',
          hidden: '⚪ 隱形'
        };
        document.getElementById('currentStatus').textContent = statusTextMap[newStatus] || '⚪ 未知';
        sessionStorage.setItem('user_status', newStatus);
      } else {
        alert(data.error || '更新狀態失敗');
      }
    })
    .catch(err => {
      console.error('更新狀態發生錯誤:', err);
      alert('無法更新狀態，請檢查網路或伺服器');
    });
  });
});

// ========== 全選/取消全選 ==========
document.getElementById('selectAll').addEventListener('change', function() {
  const checkboxes = document.querySelectorAll('input[name="delete[]"]');
  checkboxes.forEach(cb => cb.checked = this.checked);
});

// ========== 新增到自己雲端 ==========
function addToMyCloud(id) {
  if (confirm("確定要將此檔案新增到您的雲端（根目錄）嗎？")) {
    fetch('add_to_my_cloud.php?id=' + id)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert("檔案已成功新增到您的雲端！");
        } else {
          alert(data.msg || "新增失敗");
        }
      })
      .catch(err => {
        console.error('新增到雲端時發生錯誤:', err);
        alert("新增過程發生錯誤");
      });
  }
}
</script>
<script src="presence.js?v=20260928-2"></script>
<script src="message_notifications.js?v=20260928-2"></script>
<script src="navbar_notifications.js?v=20261017-1"></script>
</body>
</html>
