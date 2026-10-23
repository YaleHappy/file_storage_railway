<?php
session_start();
require_once __DIR__ . '/config.php';

// (1) 確認是否已登入
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/file_cleanup.php';
cleanupMissingSharedFiles($pdo, (int) $_SESSION['user_id']);

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
      // Removing a received delivery must not unlink a file used by other deliveries.
      $stmtDel = $pdo->prepare("DELETE FROM shared_files WHERE id = ? AND recipient_id = ?");
      foreach ((array)$ids as $id) $stmtDel->execute([(int)$id, $_SESSION['user_id']]);
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
    ORDER BY sf.sent_at DESC, sf.id DESC
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
  <link href="assets/css/responsive.css?v=20261022-2" rel="stylesheet">
<link href="assets/css/file_lists.css?v=20261024-2" rel="stylesheet">
<link href="assets/css/preview_theme.css?v=20261022-2" rel="stylesheet">
<link href="assets/css/file_context_menu.css?v=20261024-1" rel="stylesheet">
<link rel="stylesheet" href="assets/css/ui_feedback.css?v=20261024-1"><script src="assets/js/ui_feedback.js?v=20261024-1"></script></head>
<body class="has-fixed-navbar file-page">

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

<main class="container file-workspace">
<header class="file-heading"><div><h1><i class="bi bi-inbox"></i> 收到的檔案</h1><p class="file-muted">共 <?= count($files) ?> 個檔案 · 下載或儲存一份到自己的雲端</p></div></header>
<section class="file-panel" aria-label="收到的檔案列表">
<div class="file-toolbar"><input type="search" class="file-search" placeholder="搜尋檔名或傳送者…" aria-label="搜尋收到的檔案"><select class="file-filter" aria-label="傳送者"><option value="">所有傳送者</option><?php $senders=[]; foreach($files as $f) $senders[$f['sender_id']]=$f['sender_name']; foreach($senders as $id=>$name): ?><option value="<?= (int)$id ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
<form method="post" id="deleteForm">
<?php if($files): ?><div class="file-toolbar"><label class="d-flex align-items-center gap-2"><input type="checkbox" id="selectAll"> 全選顯示項目</label><button type="submit" name="delete_files" class="btn btn-outline-danger btn-sm" id="removeReceived" disabled>移除選取紀錄</button><span class="file-muted">保留傳送者的原始檔案</span></div><?php endif; ?>
<?php foreach($files as $file): $path=__DIR__.'/'.$file['file_path']; $bytes=is_file($path)?filesize($path):0; ?>
<article class="file-row received-row" data-name="<?= htmlspecialchars($file['original_filename'].' '.$file['sender_name'],ENT_QUOTES) ?>" data-state="<?= (int)$file['sender_id'] ?>">
<div class="file-name"><input type="checkbox" name="delete[]" value="<?= (int)$file['id'] ?>" aria-label="選取 <?= htmlspecialchars($file['original_filename'],ENT_QUOTES) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><div><h2 class="file-title" title="<?= htmlspecialchars($file['original_filename'],ENT_QUOTES) ?>"><?= htmlspecialchars($file['original_filename']) ?></h2><p class="file-muted">來自 <?= htmlspecialchars($file['sender_name']) ?> · <?= $bytes>=1048576 ? number_format($bytes/1048576,2).' MB' : number_format($bytes/1024,1).' KB' ?></p></div></div>
<div><p class="file-muted">收到時間</p><span><?= date('Y/m/d H:i',strtotime($file['sent_at'])) ?></span></div>
<div class="file-actions"><a class="btn btn-soft btn-sm file-preview" title="預覽檔案" href="preview.php?received_id=<?= (int)$file['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> 預覽</a><a href="download_shared_file.php?id=<?= (int)$file['id'] ?>" class="btn btn-soft btn-sm"><i class="bi bi-download"></i> 下載</a><button type="button" class="btn btn-primary btn-sm save-cloud" data-id="<?= (int)$file['id'] ?>"><i class="bi bi-cloud-arrow-up"></i> 儲存到雲端</button></div>
</article>
<?php endforeach; ?>
</form>
<div class="file-empty" id="fileEmpty" <?= $files ? 'hidden' : '' ?>><i class="bi bi-inbox" aria-hidden="true"></i><h2><?= $files ? '找不到符合的檔案' : '還沒有收到檔案' ?></h2><p><?= $files ? '試試其他檔名或傳送者。' : '朋友透過個人聊天傳送的檔案，會整理在這裡。' ?></p></div>
</section><p class="file-feedback" id="receivedFeedback" role="status"></p>
</main>
<?php require __DIR__ . "/partials/file_preview_dialog.php"; ?>

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
        storageNotice(data.error || '更新狀態失敗');
      }
    })
    .catch(err => {
      console.error('更新狀態發生錯誤:', err);
      storageNotice('無法更新狀態，請檢查網路或伺服器');
    });
  });
});

const selectionChanged=()=>{
 const boxes=[...document.querySelectorAll('input[name="delete[]"]')].filter(cb=>!cb.closest('.file-row').hidden);
 const selected=boxes.filter(cb=>cb.checked).length;
 const all=document.getElementById('selectAll'),remove=document.getElementById('removeReceived');
 if(all){all.checked=boxes.length>0&&selected===boxes.length;all.indeterminate=selected>0&&selected<boxes.length;}
 if(remove)remove.disabled=!selected;
};
document.getElementById('selectAll')?.addEventListener('change',function(){document.querySelectorAll('.file-row:not([hidden]) input[name="delete[]"]').forEach(cb=>cb.checked=this.checked);selectionChanged();});
document.querySelectorAll('input[name="delete[]"]').forEach(cb=>cb.addEventListener('change',selectionChanged));
let removePending=false;
document.getElementById('deleteForm').addEventListener('submit',async e=>{e.preventDefault();if(removePending)return;removePending=true;try{if(await storageConfirm('移除選取的收件紀錄？已儲存的雲端副本與傳送者的檔案會保留。')){document.getElementById('removeReceived').disabled=true;e.target.submit();}}finally{removePending=false;}});
document.addEventListener('file-filtered',()=>{document.querySelectorAll('.file-row[hidden] input[type=checkbox]').forEach(cb=>cb.checked=false);selectionChanged();});
document.querySelectorAll('.save-cloud').forEach(btn=>btn.addEventListener('click',async()=>{
 btn.disabled=true;btn.textContent='儲存中…';
 try {const r=await fetch('add_to_my_cloud.php?id='+encodeURIComponent(btn.dataset.id));const d=await r.json();if(!r.ok||!d.success)throw Error(d.msg||'儲存失敗');btn.textContent='已儲存到雲端';document.getElementById('receivedFeedback').textContent='檔案已儲存到首頁的雲端根目錄。';}
 catch(err){btn.disabled=false;btn.textContent='儲存到雲端';document.getElementById('receivedFeedback').textContent=err.message;}
}));
</script>
<script src="assets/js/file_lists.js?v=20261022-4"></script>
<script src="assets/js/presence.js?v=20261024-3"></script>
<script src="assets/js/message_notifications.js?v=20261021-5"></script>
<script src="assets/js/navbar_notifications.js?v=20261024-3"></script>
<?php require __DIR__ . '/partials/online_users.php'; ?>
<script src="assets/js/file_context_menu.js?v=20261024-2"></script>
</body>
</html>
