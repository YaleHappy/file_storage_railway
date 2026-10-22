<?php
session_start();
require_once __DIR__ . '/config.php';

// 若未登入 → login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/file_cleanup.php';
cleanupMissingUserFiles($pdo, (int) $_SESSION['user_id']);

// (A) 取得目前所在資料夾 (0 代表根目錄)
$currentFolder = isset($_GET['folder']) ? (int)$_GET['folder'] : 0;

// (B) 分頁設定
$perPage = 12;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// (C) 查詢所有資料夾(供移動/下拉選單)
$stmtFolders = $pdo->prepare("
    SELECT folder_id, folder_name, parent_folder
    FROM folders
    WHERE user_id = ?
    ORDER BY folder_name
");
$stmtFolders->execute([$_SESSION['user_id']]);
$allFolders = $stmtFolders->fetchAll(PDO::FETCH_ASSOC);

// (D) 取得當前資料夾資訊 (若無效則回根目錄)
$folderName     = '';
$parentFolderId = 0;
if ($currentFolder !== 0) {
    $stmtF = $pdo->prepare("
        SELECT folder_name, parent_folder
        FROM folders
        WHERE folder_id = ? AND user_id = ?
    ");
    $stmtF->execute([$currentFolder, $_SESSION['user_id']]);
    $thisFld = $stmtF->fetch(PDO::FETCH_ASSOC);
    if ($thisFld) {
        $folderName     = $thisFld['folder_name'];
        $parentFolderId = $thisFld['parent_folder'];
    } else {
        $currentFolder = 0;
    }
}

// Build paths only from folders owned by this user; stop on malformed cycles.
$folderMap = [];
foreach ($allFolders as $folder) $folderMap[(int)$folder['folder_id']] = $folder;
function homeFolderTrail(array $map, int $id): array {
    $trail = []; $seen = [];
    while ($id && isset($map[$id]) && !isset($seen[$id])) {
        $seen[$id] = true; array_unshift($trail, $map[$id]); $id = (int)$map[$id]['parent_folder'];
    }
    return $trail;
}
$folderTrail = homeFolderTrail($folderMap, $currentFolder);

// (E) 計算檔案數 (分頁用)
$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM files
    WHERE user_id = ? AND folder_id = ?
");
$stmtCount->execute([$_SESSION['user_id'], $currentFolder]);
$totalFiles = $stmtCount->fetchColumn();
$totalPages = ceil($totalFiles / $perPage);

// (F) 撈取檔案列表
$stmtFiles = $pdo->prepare("
    SELECT *
    FROM files
    WHERE user_id = :uid
      AND folder_id = :fid
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmtFiles->bindValue(':uid',    $_SESSION['user_id'], PDO::PARAM_INT);
$stmtFiles->bindValue(':fid',    $currentFolder,       PDO::PARAM_INT);
$stmtFiles->bindValue(':limit',  $perPage,             PDO::PARAM_INT);
$stmtFiles->bindValue(':offset', $offset,              PDO::PARAM_INT);
$stmtFiles->execute();
$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-TW">

<script>
    // 將 PHP 的 user_id 傳到前端全域變數 currentUserId
    var currentUserId = <?= (int)$_SESSION['user_id'] ?>;
</script>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">

  <title>雲上聊頁來｜檔案管理與即時通訊</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
html, body {
  height: 100%;
  margin: 1;
  padding: 0;
  background: #1a1a2e;
  color: #fff;
}
.card-file {
  background: #25274d; 
  color: #fff;
  border-radius: 10px;
  transition: box-shadow .2s;
}
.card-file:hover {
  box-shadow: 0 4px 12px rgba(217, 240, 15, 0.1);
}
/* 資料夾卡片底色再調亮一點 */
.card-file.folder {
  background: rgb(166, 175, 38);
}
.fab {
  position: fixed;
  bottom: 30px;
  right: 30px;
  font-size: 2rem;
  z-index: 1050;
}
.pagination .page-link {
  background: #25274d;
  color: #fff;
  border: 1px solid #666;
}
.pagination .page-item.active .page-link {
  background: #007bff;
  border-color: #007bff;
}
#uploadProgress {
  display: none;
  margin-top: 1rem;
}
#uploadProgress .progress-bar {
  transition: width .3s ease;
}
#uploadAlert {
  display: none;
}
/* 資料夾被拖曳到時候的外框提示 */
.card-file.folder.drop-hover {
  outline: 2px dashed #0f0;
}
/* 搜尋輸入框 */
#searchInput {
  width: 100%;
  padding: 10px;
  font-size: 1rem;
  border-radius: 5px;
  border: 1px solid #ccc;
  margin-bottom: 20px;
}
/* 右鍵選單 */
.context-menu {
  position: absolute;
  display: none;
  background: #333;
  color: #fff;
  border-radius: 5px;
  z-index: 2000;
  box-shadow: 0px 0px 10px rgba(0,0,0,0.3);
  padding: 5px 0;
  width: 180px;
}
.context-menu ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.context-menu ul li {
  padding: 8px 15px;
  cursor: pointer;
}
.context-menu ul li:hover {
  background: #555;
}
/* 背景右鍵選單 */
.bg-context-menu {
  position: absolute;
  display: none;
  background: #333;
  color: #fff;
  border-radius: 5px;
  z-index: 3000;
  box-shadow: 0 2px 10px rgba(0,0,0,0.3);
  padding: 5px 0;
  width: 180px;
}
.bg-context-menu ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.bg-context-menu ul li {
  padding: 8px 15px;
  cursor: pointer;
}
.bg-context-menu ul li:hover {
  background: #555;
}
.modal {
  z-index: 1050;
}
.modal.show {
  z-index: 1060;
}
/* 白底視窗使用深色文字，避免繼承頁面的白色文字。 */
.modal-content {
  background-color: #fff;
  color: #212529;
  color-scheme: light;
}
/* 右側固定使用者狀態面板 (略) */
#userPopup {
  position: fixed;
  right: 20px;
  top: 50%;
  transform: translateY(-50%);
  width: 260px;
  background: linear-gradient(135deg, #25274d, #1a1a2e);
  color: #fff;
  padding: 15px;
  border-radius: 15px;
  box-shadow: 0px 5px 15px rgba(0, 0, 0, 0.3);
  display: none;
  z-index: 1100;
  max-height: 400px;
  overflow-y: auto;
}
#toggleUserPopup {
  position: fixed;
  right: 20px;
  top: 20%;
  transform: translateY(0);
  background: #007bff;
  color: #fff;
  border: none;
  border-radius: 20px;
  padding: 10px 15px;
  z-index: 1090;
}
#toggleUserPopup.popup-active {
  opacity: 0.7;
}
#userList {
  width: 100%;
  padding: 0;
  margin: 0;
}
#userList li {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 12px 14px;
  border-radius: 15px;
  background: rgba(255, 255, 255, 0.1);
  margin-bottom: 8px;
  transition: all 0.3s ease-in-out;
  width: 100%;
  box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
}
#userList li:hover {
  background: rgba(255, 255, 255, 0.15);
  transform: translateY(-2px);
}
#userList li .user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  justify-content: center;
  flex-wrap: wrap;
}
#userList li .user-id {
  max-width: 130px;
  word-break: break-word;
  white-space: normal;
  font-weight: bold;
  text-align: center;
}
#userList li .status-text {
  display: flex;
  align-items: center;
  gap: 5px;
}
#userList li .user-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: center;
  width: 100%;
  background: transparent;
  padding-top: 6px;
}
#userList li .btn {
  border-radius: 20px;
  font-size: 14px;
  padding: 8px 14px;
  transition: transform 0.2s ease;
  width: 100%;
  margin-top: 5px;
  text-align: center;
  box-shadow: 0px 2px 5px rgba(0, 0, 0, 0.2);
  border: none;
}
#userList li .btn:hover {
  transform: scale(1.08);
}
/* 通知鈴鐺 (略) */
.notification-badge {
  font-size: 0.7rem;
  top: 0 !important;
  right: -5px !important;
}
.notification-dropdown {
  background-color: #25274d;
  color: #fff;
  border: none;
  width: 320px;
  max-height: 450px;
  overflow-y: auto;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}
  </style>
  <link href="assets/css/responsive.css?v=20261022-2" rel="stylesheet">
  <link href="assets/css/home_announcements.css?v=20261022-6" rel="stylesheet">
<link href="assets/css/share_theme.css?v=20261022-1" rel="stylesheet">
<link href="assets/css/preview_theme.css?v=20261022-2" rel="stylesheet">
<link href="assets/css/storage_workspace.css?v=20261022-3" rel="stylesheet">
<link href="assets/css/cloud_search.css?v=20261022-1" rel="stylesheet">
</head>
<body class="has-fixed-navbar home-page">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">☁️ 雲上聊頁來</a>
    <div class="home-nav-search" role="search"><i class="bi bi-search" aria-hidden="true"></i><label class="visually-hidden" for="searchInput">搜尋檔案與資料夾</label><input type="search" id="searchInput" placeholder="搜尋我的雲端..." autocomplete="off"></div>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="切換導覽選單">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNavbar">
    <ul class="navbar-nav ms-auto">
      <li class="nav-item">
        <a class="nav-link" href="my_shares.php">🔗 我的分享</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="received_files.php">📥 收到的檔案</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="chat_history.php"><i class="bi bi-clock-history me-1"></i>聊天紀錄</a>
      </li>
      <!-- 使用者名稱改成下拉選單 -->
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          👤 <?= htmlspecialchars($_SESSION['username']) ?> (<span id="currentStatus">
            <?php
              // 顯示狀態文字
              $statusTexts = [
                'online' => '🟢 在線',
                'idle' => '🌙 閒置',
                'away' => '🔴 請勿打擾',
                'hidden' => '⚪ 隱形',
                'offline' => '⚪ 離線'
              ];
              $currentStatus = isset($_SESSION['status']) ? $_SESSION['status'] : 'online';
              echo htmlspecialchars($statusTexts[$currentStatus]);
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
      <?php if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
        <li class="nav-item">
          <a class="nav-link" href="admin.php">🛠️ 管理後台</a>
        </li>
      <?php endif; ?>
      <li class="nav-item navbar-session-actions"><a class="nav-link" href="logout.php">登出</a>
        <?php require __DIR__ . '/navbar_notifications.php'; ?>
      </li>
    </ul>
    </div>
  </div>
</nav>

<div class="container py-5" id="storageWorkspace">
  <header class="storage-heading">
    <nav aria-label="資料夾路徑"><ol class="storage-breadcrumb"><li><a href="index.php"><i class="bi bi-cloud" aria-hidden="true"></i> 我的雲端</a></li><?php foreach($folderTrail as $crumb): ?><li><?php if((int)$crumb['folder_id'] === $currentFolder): ?><span aria-current="page"><?= htmlspecialchars($crumb['folder_name']) ?></span><?php else: ?><a href="?folder=<?= (int)$crumb['folder_id'] ?>"><?= htmlspecialchars($crumb['folder_name']) ?></a><?php endif; ?></li><?php endforeach; ?></ol></nav>
    <p>集中整理檔案與資料夾；點選資料夾名稱開啟，或雙擊檔案預覽。</p>
  </header>

  <?php require __DIR__ . "/partials/cloud_search_results.php"; ?>

  <!-- (4) 公告區域：撈取並顯示有效公告 (start_date、end_date、is_active=1) -->
  <?php
  $announcementStmt = $pdo->prepare("
    SELECT * FROM announcements 
    WHERE is_active = 1 
      AND (start_date IS NULL OR start_date <= NOW()) 
      AND (end_date IS NULL OR end_date >= NOW())
    ORDER BY created_at DESC
  ");
  $announcementStmt->execute();
  $announcements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <?php if (!empty($announcements)): ?>
    <details class="home-announcements announcement-compact mb-3">
      <summary class="announcement-summary">
        <i class="bi bi-megaphone" aria-hidden="true"></i><strong>系統公告</strong>
        <span class="announcement-latest"><?= htmlspecialchars($announcements[0]['title'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="announcement-count"><?= count($announcements) ?> 則</span>
        <span class="announcement-expand">展開</span><span class="announcement-collapse">收合</span><i class="bi bi-chevron-down announcement-chevron" aria-hidden="true"></i>
      </summary>
      <div class="announcement-list">
        <?php foreach ($announcements as $announcement): ?>
          <article class="announcement-item">
            <h3><?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="announcement-content"><?= nl2br(htmlspecialchars($announcement['content'], ENT_QUOTES, 'UTF-8')) ?></div>
            <?php if ($announcement['start_date'] || $announcement['end_date']): ?>
              <div class="announcement-dates"><i class="bi bi-clock" aria-hidden="true"></i>
                <?php if ($announcement['start_date']): ?><span>開始 <?= htmlspecialchars(substr($announcement['start_date'],0,16)) ?></span><?php endif; ?>
                <?php if ($announcement['end_date']): ?><span>截止 <?= htmlspecialchars(substr($announcement['end_date'],0,16)) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <!-- (6) 分頁 -->
  <?php if ($totalPages > 1): ?>
    <nav class="mb-4">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="?folder=<?= $currentFolder ?>&page=<?= ($page - 1) ?>">« 上一頁</a>
        </li>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
            <a class="page-link" href="?folder=<?= $currentFolder ?>&page=<?= $i ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
          <a class="page-link" href="?folder=<?= $currentFolder ?>&page=<?= ($page + 1) ?>">下一頁 »</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

  <div class="home-storage-toolbar">
    <div class="storage-primary-actions"><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload" aria-hidden="true"></i> 上傳檔案</button><button class="btn storage-secondary" type="button" data-bs-toggle="modal" data-bs-target="#createFolderModal"><i class="bi bi-folder-plus" aria-hidden="true"></i> 新增資料夾</button><?php if($currentFolder): ?><a class="btn storage-secondary" href="?folder=<?= (int)$parentFolderId ?>"><i class="bi bi-arrow-up" aria-hidden="true"></i> 上一層</a><?php endif; ?></div>
    <div class="storage-selection"><span id="selectedFileCount" role="status">未選取檔案</span><button id="clearFileSelection" type="button" class="btn btn-sm storage-secondary" hidden>取消選取</button><button id="bulkDeleteBtn" type="button" class="btn btn-sm btn-outline-danger" disabled>刪除選取檔案</button></div>
  </div>
  <h2 class="storage-section-title"><i class="bi bi-folder2" aria-hidden="true"></i> 資料夾</h2>
  <!-- (6) 子資料夾列表 (一行最多4個) -->
  <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4" id="folderContainer">
    <?php
    // 查詢該資料夾下的子資料夾
    $stmtSub = $pdo->prepare("
      SELECT folder_id, folder_name, parent_folder
      FROM folders
      WHERE parent_folder = ? AND user_id = ? ORDER BY folder_name
    ");
    $stmtSub->execute([$currentFolder, $_SESSION['user_id']]);
    $subFolders = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    if (!$subFolders) echo '<p class="storage-empty-note">這一層還沒有資料夾，可按「新增資料夾」開始整理。</p>';
    foreach ($subFolders as $fld):
    ?>
<div class="col">
  <div class="card card-file folder h-100 p-3 folder-card" 
       data-folder="<?= $fld['folder_id'] ?>"
       data-folder-name="<?= htmlspecialchars($fld['folder_name']) ?>"
       ondragover="folderDragOver(event)"
       ondragleave="folderDragLeave(event)"
       ondrop="folderDrop(event)"
       ondblclick="if (!event.target.closest('a,button,input')) goIntoFolder(<?= (int)$fld['folder_id'] ?>)"
       style="position: relative;">
    <a class="card-body text-center folder-open" href="?folder=<?= (int)$fld['folder_id'] ?>">
      <i class="bi bi-folder-fill fs-1" aria-hidden="true"></i>
      <div><h6 class="mt-2"><?= htmlspecialchars($fld['folder_name']) ?></h6><small><span class="folder-desktop-hint">點選名稱或雙擊卡片開啟</span><span class="folder-mobile-hint">點選開啟資料夾</span></small></div>
    </a>
    <div class="card-footer border-0 folder-actions">
      <!-- 進入資料夾 -->
      <a href="?folder=<?= $fld['folder_id'] ?>" class="btn btn-sm btn-light">
        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i> 開啟
      </a>
      <!-- 重命名資料夾 -->
      <button class="btn btn-sm btn-info rename-folder-btn"
              data-id="<?= $fld['folder_id'] ?>"
              data-name="<?= htmlspecialchars($fld['folder_name']) ?>">
        <i class="bi bi-pencil-square" aria-hidden="true"></i> 重新命名
      </button>
      <!-- 刪除資料夾 -->
      <button class="btn btn-sm btn-danger delete-folder-btn"
              data-id="<?= $fld['folder_id'] ?>">
        <i class="bi bi-folder-x" aria-hidden="true"></i> 刪除
      </button>
    </div>
  </div>
</div>
    <?php endforeach; ?>
  </div>

  <h2 class="storage-section-title"><i class="bi bi-files" aria-hidden="true"></i> 檔案 <small><?= (int)$totalFiles ?> 個</small></h2>
  <!-- (7) 檔案列表 -->
  <p id="storageSearchEmpty" class="storage-empty-note" role="status" hidden>找不到符合的檔案或資料夾，請換個關鍵字。</p>
  <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-4" id="fileContainer">
    <?php if (empty($files)): ?>
      <p class="text-center text-muted">目前沒有任何檔案</p>
    <?php else: ?>
      <?php foreach ($files as $f): ?>
        <div class="col file-card">
          <div class="card card-file h-100 p-3 file-card-inner" draggable="true"
               ondragstart="fileDragStart(event)"
               data-file="<?= $f['file_id'] ?>"
               style="position: relative;">
            <input type="checkbox" class="form-check-input select-item" 
                   style="position: absolute; top: 10px; left: 10px;" 
                   value="<?= $f['file_id'] ?>">
            <div class="card-body text-center">
              <i class="bi bi-file-earmark-fill fs-1"></i>
              <h6 class="mt-2 file-name"><?= htmlspecialchars($f['original_filename']) ?></h6>
              <small class="text-muted"><?= number_format($f['file_size'] / 1024, 2) ?> KB</small><br>
              <small class="text-muted"><?= substr($f['created_at'], 0, 16) ?></small>
            </div>
            <div class="card-footer border-0 home-file-actions">
              <a href="files.php?download=<?= (int)$f['file_id'] ?>" class="btn btn-success btn-sm" title="下載檔案"><i class="bi bi-download" aria-hidden="true"></i> 下載</a>
              <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#shareModal" data-id="<?= (int)$f['file_id'] ?>" title="產生分享連結"><i class="bi bi-share-fill" aria-hidden="true"></i> 分享</button>
              <button type="button" class="btn btn-info btn-sm move-btn" data-id="<?= (int)$f['file_id'] ?>" title="移動到資料夾"><i class="bi bi-arrow-right-square" aria-hidden="true"></i> 移動</button>
              <button type="button" class="btn btn-primary btn-sm" onclick="openPreviewModal(<?= (int)$f['file_id'] ?>)" title="預覽檔案"><i class="bi bi-eye" aria-hidden="true"></i> 預覽</button>
              <a href="files.php?delete=<?= (int)$f['file_id'] ?>" onclick="return confirm('確定刪除此檔案？');" class="btn btn-danger btn-sm" title="刪除檔案"><i class="bi bi-trash" aria-hidden="true"></i> 刪除</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>


<!-- 背景右鍵選單 -->
<div id="bgContextMenu" class="bg-context-menu">
  <ul>
    <li onclick="openCreateFolderModal()">📂 新建資料夾</li>
    <li onclick="openUploadModal()">📤 上傳檔案</li>
    <li onclick="location.reload()">🔄 刷新頁面</li>
  </ul>
</div>

<!-- 右鍵選單 (針對檔案/資料夾) -->
<div id="contextMenu" class="context-menu">
  <ul id="contextMenuList"></ul>
</div>

<!-- Modal 區塊 -->
<!-- 建立資料夾 Modal -->
<div class="modal fade" id="createFolderModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="createFolderForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">建立資料夾</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" class="form-control mb-3" name="folder_name" required placeholder="資料夾名稱">
        <input type="hidden" name="parent_folder" value="<?= $currentFolder ?>">
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">建立</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>

<!-- 上傳檔案 Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="uploadForm" class="modal-content" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title">上傳檔案</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="folder_id" value="<?= $currentFolder ?>">
        <input type="file" name="files[]" id="multiFileInput" class="form-control mb-3" required multiple>
        <div id="fileList" class="p-2 mb-3" 
             style="font-size:0.9rem; background:#25274d; color:#fff; border-radius:5px; min-height:40px;">
          <small class="text-muted">尚未選擇檔案</small>
        </div>
        <div id="uploadProgress" style="display:none;">
          <div class="progress">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
          </div>
        </div>
        <div id="uploadAlert" class="alert alert-danger mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">上傳</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>

<!-- 分享 Modal -->
<div class="modal fade" id="shareModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="shareForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">產生分享連結</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fileId" name="file_id">
        <div class="mb-3">
          <label>到期時間</label>
          <input type="datetime-local" class="form-control" name="expires_at" aria-describedby="shareExpiryHint">
          <p id="shareExpiryHint" class="small mt-2 mb-0" style="color:#aeb8d4">預設七天：未指定到期時間時，連結將於建立後七天到期。</p>
        </div>
        <div class="mb-3">
          <label>分享密碼</label>
          <input type="text" class="form-control" name="share_password">
        </div>
        <div id="shareResult" class="alert alert-success d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-warning">產生連結</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>

<!-- 移動檔案 Modal -->
<div class="modal fade" id="moveModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="moveForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">移動到資料夾</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="file_id" id="moveFileId">
        <div class="mb-3">
          <label>選擇資料夾</label>
          <select class="form-select" name="target_folder">
            <?php if ($currentFolder != 0): // 若不在根目錄，顯示根目錄選項 ?>
              <option value="0">我的雲端（根目錄）</option>
            <?php endif; ?>
            <?php foreach($allFolders as $fld): ?>
              <?php 
              if ($currentFolder != 0 && $fld['folder_id'] == $currentFolder) {
                continue;
              }
              ?>
              <option value="<?= $fld['folder_id'] ?>">
                <?= htmlspecialchars('我的雲端 / ' . implode(' / ', array_column(homeFolderTrail($folderMap, (int)$fld['folder_id']), 'folder_name'))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="moveResult" class="alert alert-success d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-info">移動</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>

<!-- 重命名資料夾 Modal -->
<div class="modal fade" id="renameFolderModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="renameFolderForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">重命名資料夾</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="folder_id" id="renameFolderId">
        <div class="mb-3">
          <label>新的資料夾名稱</label>
          <input type="text" class="form-control" name="new_name" id="renameFolderName" required>
        </div>
        <div id="renameResult" class="alert alert-success d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-info">更新</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>

<!-- 傳送檔案 Modal -->
<div class="modal fade" id="sendFileModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="sendFileForm" class="modal-content" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title">傳送檔案給 <span id="recipientName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="recipient_id" id="recipientId">
        <ul class="nav nav-tabs" id="fileTab" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload" type="button" role="tab" aria-controls="upload" aria-selected="true">上傳新檔案</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="select-tab" data-bs-toggle="tab" data-bs-target="#select" type="button" role="tab" aria-controls="select" aria-selected="false">從我的檔案選擇</button>
          </li>
        </ul>
        <div class="tab-content pt-3" id="fileTabContent">
          <div class="tab-pane fade show active" id="upload" role="tabpanel" aria-labelledby="upload-tab">
            <div class="mb-3">
              <label for="sendFileInput" class="form-label">選擇檔案</label>
              <input type="file" name="file" id="sendFileInput" class="form-control">
            </div>
          </div>
          <div class="tab-pane fade" id="select" role="tabpanel" aria-labelledby="select-tab">
            <div class="mb-3">
              <label for="existingFileSelect" class="form-label">選擇已上傳的檔案</label>
              <select name="existing_file_id" id="existingFileSelect" class="form-select">
                <option value="">-- 請選擇檔案 --</option>
                <?php
                  $stmtUserFiles = $pdo->prepare("SELECT file_id, original_filename FROM files WHERE user_id = ?");
                  $stmtUserFiles->execute([$_SESSION['user_id']]);
                  $userFiles = $stmtUserFiles->fetchAll(PDO::FETCH_ASSOC);
                  foreach($userFiles as $uf){
                    echo '<option value="'. $uf['file_id'] .'">'. htmlspecialchars($uf['original_filename']) .'</option>';
                  }
                ?>
              </select>
            </div>
          </div>
        </div>
        <input type="hidden" name="file_option" id="fileOption" value="upload">
        <div id="sendFileAlert" class="alert alert-danger mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">傳送</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>

<!-- ★★★ 新增「預覽用」Modal ★★★ -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content" style="background:#252842;color:#e8ecfb;border:1px solid #586181;">
      <div class="modal-header" style="background:#252842;border-color:#454c6b;">
        <h5 class="modal-title">檔案預覽</h5>
        <button type="button" class="btn-close btn-close-white" aria-label="關閉預覽" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="overflow:auto;background:#191c30;">
        <!-- 這裡用 iframe 來顯示 preview.php 的內容 -->
        <iframe id="previewFrame" style="width:100%; height:600px; border:none;"></iframe>
      </div>
    </div>
  </div>
</div>
<!-- 浮動 + 按鈕 (上傳) -->
<button class="btn btn-primary rounded-circle fab" type="button" data-bs-toggle="modal" data-bs-target="#uploadModal" aria-label="上傳檔案" title="上傳檔案">
  <i class="bi bi-plus-lg"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script>


// ========== 檔案拖曳 → 資料夾 ==========
function fileDragStart(e) {
  const fileId = e.currentTarget.dataset.file;
  e.dataTransfer.setData('text/plain', fileId);
}
function folderDragOver(e) {
  e.preventDefault();
  e.currentTarget.classList.add('drop-hover');
}
function folderDragLeave(e) {
  e.currentTarget.classList.remove('drop-hover');
}
function folderDrop(e) {
  e.preventDefault();
  const folderEl = e.currentTarget;
  folderEl.classList.remove('drop-hover');
  const folderId = folderEl.dataset.folder;
  const fileId   = e.dataTransfer.getData('text/plain');
  fetch('move_file.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `file_id=${fileId}&target_folder=${folderId}`
  })
  .then(r => r.json())
  .then(js => {
    if (js.success) {
      alert('檔案移動成功');
      location.reload();
    } else {
      alert(js.msg || '檔案移動失敗');
    }
  });
}
function goIntoFolder(folderId) {
  location.href = '?folder=' + folderId;
}

// === 右鍵選單功能 (針對檔案與資料夾) ===
const contextMenu = document.getElementById("contextMenu");
const contextMenuList = document.getElementById("contextMenuList");
let currentTarget = null;
function getContextMenuOptions(target) {
  if(target.classList.contains("folder-card")) {
    const folderId = target.dataset.folder;
    const folderName = target.dataset.folderName;
    return `
      <li onclick="window.location.href='?folder=${folderId}'">🔍 開啟資料夾</li>
      <li onclick="renameFolder('${folderId}', currentTarget.dataset.folderName)">✏️ 重新命名資料夾</li>
      <li onclick="deleteFolder('${folderId}')">❌ 刪除資料夾</li>
    `;
  } else if(target.classList.contains("file-card-inner")) {
    const fileId = target.dataset.file;
    return `
      <li onclick="window.location.href='files.php?download=${fileId}'">⬇️ 下載檔案</li>
      <li onclick="deleteFile('${fileId}')">❌ 刪除檔案</li>
      <li onclick="openMoveModal('${fileId}')">📂➡️ 移動檔案</li>
      <li onclick="openShareModal('${fileId}')">🔗 分享檔案</li>
    `;
  }
  return "";
}
document.addEventListener("contextmenu", function(e) {
  const folderElem = e.target.closest(".folder-card");
  const fileElem = e.target.closest(".file-card-inner");
  if(folderElem || fileElem) {
    e.preventDefault();
    currentTarget = folderElem || fileElem;
    contextMenuList.innerHTML = getContextMenuOptions(currentTarget);
    contextMenu.style.top = `${e.clientY}px`;
    contextMenu.style.left = `${e.clientX}px`;
    contextMenu.style.display = "block";
  } else {
    contextMenu.style.display = "none";
  }
});
document.addEventListener("click", function() {
  contextMenu.style.display = "none";
});

// === 背景右鍵選單功能 ===
const bgContextMenu = document.getElementById("bgContextMenu");
document.body.addEventListener("contextmenu", function(e) {
  // 如果點擊目標在任何 modal 內或在下拉選單內，不顯示背景選單
  if (e.target.closest('.modal') || e.target.closest('.dropdown-menu')) {
    return;
  }

  // 如果不是在檔案、資料夾或右鍵選單內，顯示背景選單
  if (
    !e.target.closest(".file-card") &&
    !e.target.closest(".folder-card") &&
    !e.target.closest(".context-menu")
  ) {
    e.preventDefault();
    bgContextMenu.style.top = `${e.clientY}px`;
    bgContextMenu.style.left = `${e.clientX}px`;
    bgContextMenu.style.display = "block";
  } else {
    bgContextMenu.style.display = "none";
  }
});




document.addEventListener("click", function() {
  bgContextMenu.style.display = "none";
});
function openUploadModal() {
  const uploadModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadModal'));
  uploadModal.show();
}
function openCreateFolderModal() {
  const createFolderModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('createFolderModal'));
  createFolderModal.show();
}

// === 資料夾操作函式 ===
function renameFolder(folderId, folderName) {
  document.getElementById('renameFolderId').value = folderId;
  document.getElementById('renameFolderName').value = folderName;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('renameFolderModal')).show();
}
function deleteFolder(folderId) {
  if(confirm("確定刪除此資料夾？僅能刪除空資料夾，內有檔案或子資料夾時會保留。")) {
    const fd = new FormData();
    fd.append('folder_id', folderId);
    fetch('folders.php?action=delete', { method:'POST', body: fd })
      .then(r => r.json())
      .then(js => {
        if(js.success) {
          alert("資料夾已刪除");
          location.reload();
        } else {
          alert(js.msg || "刪除失敗");
        }
      });
  }
}
function deleteFile(fileId) {
  if(confirm("確定要刪除此檔案嗎？")) {
    window.location.href = "files.php?delete=" + fileId;
  }
}
function openMoveModal(fileId) {
  document.getElementById('moveFileId').value = fileId;
  document.getElementById('moveResult').classList.add('d-none');
  const moveModalEl = bootstrap.Modal.getOrCreateInstance(document.getElementById('moveModal'));
  moveModalEl.show();
}
function openShareModal(fileId) {
  document.getElementById('fileId').value = fileId;
  document.querySelector('[name="expires_at"]').value = '';
  document.querySelector('[name="share_password"]').value = '';
  document.getElementById('shareResult').classList.add('d-none');
  const shareModalEl = bootstrap.Modal.getOrCreateInstance(document.getElementById('shareModal'));
  shareModalEl.show();
}

// === 其他原有功能 ===
// 建立資料夾
document.getElementById('createFolderForm').onsubmit = async e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const resp = await fetch('folders.php?action=create', { method: 'POST', body: formData });
  const js = await resp.json();
  if (js.success) {
    bootstrap.Modal.getInstance(document.getElementById('createFolderModal')).hide();
    location.reload();
  } else {
    alert(js.msg || '建立資料夾失敗');
  }
};
// 上傳檔案
document.getElementById('uploadForm').onsubmit = e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const progress = document.getElementById('uploadProgress');
  const bar      = progress.querySelector('.progress-bar');
  const alertBox = document.getElementById('uploadAlert');
  const selectedFiles=Array.from(e.target.querySelector('input[type="file"]').files);
  const maxBytes=100*1024*1024;
  if(selectedFiles.some(file=>file.size>maxBytes) || selectedFiles.reduce((sum,file)=>sum+file.size,0)>maxBytes){
    alertBox.textContent='單檔及每次上傳合計上限為 100MB，請減少檔案後再試。';
    alertBox.style.display='block';return;
  }
  progress.style.display = 'block';
  bar.style.width = '0%';
  bar.textContent = '0%';
  alertBox.style.display = 'none';
  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'upload_ajax.php');
  xhr.upload.onprogress = evt => {
    if (evt.lengthComputable) {
      const pct = Math.floor((evt.loaded / evt.total) * 100);
      bar.style.width = pct + '%';
      bar.textContent = pct + '%';
    }
  };
  xhr.onload = () => {
    try {
      const res = JSON.parse(xhr.responseText);
      if (res.success) {
        bootstrap.Modal.getInstance(document.getElementById('uploadModal')).hide();
        location.reload();
      } else {
        alertBox.textContent = res.error || '上傳失敗';
        alertBox.style.display = 'block';
      }
    } catch (err) {
      alertBox.textContent = xhr.status===413
        ? '伺服器拒絕接收：上傳容量超過限制，請減少檔案後再試。'
        : xhr.status===401 || xhr.status===403 || xhr.responseURL.includes('login.php')
          ? '登入已失效，請重新登入後再上傳。'
          : `伺服器未正常完成上傳（HTTP ${xhr.status}），請稍後再試。`;
      alertBox.style.display = 'block';
    }
  };
  xhr.onerror = () => {
    alertBox.textContent = '無法連線到伺服器，請稍後再試';
    alertBox.style.display = 'block';
  };
  xhr.send(formData);
};
// Modal reset
document.getElementById('createFolderModal').addEventListener('show.bs.modal', () => {
  document.getElementById('createFolderForm').reset();
});
document.getElementById('uploadModal').addEventListener('show.bs.modal', () => {
  document.getElementById('uploadForm').reset();
  document.getElementById('uploadProgress').style.display = 'none';
  document.getElementById('uploadAlert').style.display = 'none';
  document.getElementById('fileList').innerHTML = '<small class="text-muted">尚未選擇檔案</small>';

});
// Share Modal
document.getElementById('shareModal').addEventListener('show.bs.modal', e => {
    const triggerBtn = e.relatedTarget;
    const selectedFile = triggerBtn?.dataset.id || document.getElementById('fileId').value;
    document.getElementById('shareForm').reset();
    document.getElementById('fileId').value = selectedFile;
    if (triggerBtn && triggerBtn.dataset.id) {
        document.getElementById('fileId').value = triggerBtn.dataset.id;
    }
    // 清空舊結果
    document.getElementById('shareResult').classList.add('d-none');
    document.getElementById('shareResult').innerHTML = '';
});

document.getElementById('shareForm').onsubmit = async e => {
  e.preventDefault();
  const fd  = new FormData(e.target);
  const resp = await fetch('share_ajax.php', { method:'POST', body:fd });
  const js  = await resp.json();
  const el  = document.getElementById('shareResult');
  if (js.success) {
    el.innerHTML = `<strong>分享連結：</strong><a href="${js.link}" target="_blank">${js.link}</a>`;
    el.classList.remove('d-none');
  } else {
    alert(js.msg || "產生分享連結失敗");
  }
};
// 移動檔案 Modal
const moveModalEl = bootstrap.Modal.getOrCreateInstance(document.getElementById('moveModal'));
document.querySelectorAll('.move-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('moveFileId').value = btn.dataset.id;
    document.getElementById('moveResult').classList.add('d-none');
    moveModalEl.show();
  });
});
document.getElementById('moveForm').onsubmit = async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const r  = await fetch('move_file.php', { method:'POST', body:fd });
  const js = await r.json();
  const res = document.getElementById('moveResult');
  if (js.success) {
    res.textContent = '移動成功';
    res.classList.remove('d-none');
    setTimeout(() => {
      moveModalEl.hide();
      location.reload();
    }, 1000);
  } else {
    res.textContent = js.msg || '移動失敗';
    res.classList.remove('d-none');
  }
};
// 重命名資料夾 Modal
const renameModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('renameFolderModal'));
document.querySelectorAll('.rename-folder-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const id   = btn.dataset.id;
    const name = btn.dataset.name;
    document.getElementById('renameFolderId').value   = id;
    document.getElementById('renameFolderName').value = name;
    document.getElementById('renameResult').classList.add('d-none');
    renameModal.show();
  });
});
document.getElementById('renameFolderForm').onsubmit = async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const r  = await fetch('folders.php?action=rename', { method:'POST', body: fd });
  const js = await r.json();
  const renameRes = document.getElementById('renameResult');
  if (js.success) {
    renameRes.textContent = '更新成功';
    renameRes.classList.remove('d-none');
    setTimeout(() => {
      renameModal.hide();
      location.reload();
    }, 1000);
  } else {
    renameRes.textContent = js.msg || '更新失敗';
    renameRes.classList.remove('d-none');
  }
};
// 刪除資料夾 (按鈕與右鍵皆適用)
document.querySelectorAll('.delete-folder-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('確定刪除此空資料夾？內有檔案或子資料夾時不會刪除。')) return;
    const folderId = btn.dataset.id;
    const fd = new FormData();
    fd.append('folder_id', folderId);
    const r  = await fetch('folders.php?action=delete', { method:'POST', body: fd });
    const js = await r.json();
    if (js.success) {
      alert('資料夾已刪除');
      location.reload();
    } else {
      alert(js.msg || '刪除失敗');
    }
  });
});
  const multiFileInput = document.getElementById('multiFileInput');
  const fileListDiv = document.getElementById('fileList');

  multiFileInput.addEventListener('change', function() {
    // 清空舊的顯示
    fileListDiv.innerHTML = '';

    // 如果沒有選檔案
    if (!this.files || this.files.length === 0) {
      fileListDiv.textContent = '尚未選擇檔案';
      return;
    }

    // 逐一顯示檔名
    const files = this.files; // FileList
    const list = document.createElement('ul'); list.style.paddingLeft = '1.2rem';
    for (let i = 0; i < files.length; i++) {
      const item = document.createElement('li'); item.textContent = files[i].name; list.append(item);
    }
    fileListDiv.replaceChildren(list);
  });

function loadUserList() { window.dispatchEvent(new Event('refresh-online-users')); }

  // 當切換 Tab 時，更新隱藏欄位的值
  var uploadTab = document.getElementById('upload-tab');
  var selectTab = document.getElementById('select-tab');
  uploadTab.addEventListener('shown.bs.tab', function(){
    document.getElementById('fileOption').value = 'upload';
  });
  selectTab.addEventListener('shown.bs.tab', function(){
    document.getElementById('fileOption').value = 'select';
});



document.getElementById('sendFileForm').addEventListener('submit', function(e){
  e.preventDefault();
  var formData = new FormData(this);
  fetch('send_file.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if(data.success) {
      alert('檔案傳送成功');
      bootstrap.Modal.getInstance(document.getElementById('sendFileModal')).hide();
    } else {
      document.getElementById('sendFileAlert').textContent = data.msg || '傳送失敗';
      document.getElementById('sendFileAlert').style.display = 'block';
    }
  })
  .catch(err => {
    document.getElementById('sendFileAlert').textContent = '傳送過程發生錯誤';
    document.getElementById('sendFileAlert').style.display = 'block';
    console.error(err);
  });
});


document.querySelectorAll('.status-option').forEach(item => {
  item.addEventListener('click', function(e) {
    e.preventDefault();
    const newStatus = this.getAttribute('data-status');

    fetch('update_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `status=${newStatus}`
    })
    .then(response => response.json())  // 直接轉換為 JSON
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

        // 延遲 800 毫秒後重新載入使用者列表，確保後端更新完成
        setTimeout(loadUserList, 800);
      } else {
        console.error('更新狀態失敗:', data.error);
        alert(data.error || '更新狀態失敗');
      }
    })
    .catch(err => {
      console.error('AJAX 錯誤:', err);
      alert('無法更新狀態，請檢查網路或伺服器錯誤');
    });
  });
});




const API_BASE = "update_last_active.php";

// 修改這兩行代碼
setInterval(sendHeartbeat, 10000);
setInterval(() => { fetch('update_last_active.php', { method: "POST" }); }, 10000);

// 發送心跳的函數
function sendHeartbeat() {
    fetch('update_last_active.php', {
        method: "POST",
        credentials: "include"
    })
    .then(response => response.json())
    .catch(err => console.log("心跳更新失敗: " + (err.message || "錯誤")));
}
setInterval(sendHeartbeat, 10000);

// 上線使用者的訊息按鈕統一前往聊天紀錄頁。
document.addEventListener('click', function(e) {
  const chatBtn = e.target.closest('.chatBtn');
  if (chatBtn) {
    window.location.href = `chat_history.php?user=${encodeURIComponent(chatBtn.dataset.user)}`;
  }
});

// 傳送檔案按鈕：打開傳送檔案視窗
document.addEventListener('click', function(e) {
  const sendFileBtn = e.target.closest('.sendFileBtn');
  if (sendFileBtn) {
    const { user, username } = sendFileBtn.dataset; // 讀取 data-user 與 data-username
    // 將資料設定到傳送檔案 Modal 的隱藏欄位及標題中
    document.getElementById('recipientId').value = user;
    document.getElementById('recipientName').textContent = username;
    let sendFileModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('sendFileModal'));
    sendFileModal.show();
  }
});



function checkNewFiles() {
  fetch('check_new_files.php')
    .then(r => r.json())
    .then(data => {
      const newCount = Number(data.new_count || 0);

      // 只有在未讀檔案數量「增加」時才提示，避免每次輪詢都重複跳 alert
      if (data.success && newCount > 0 && newCount > lastNewFileCount && document.documentElement.dataset.presenceStatus !== 'away') {
        // 例如用 alert 提示，或是加個 badge
        alert(`你有 ${newCount} 個新檔案尚未讀取！`);
      }

      // 記錄最新計數，當使用者已讀/刪除後回落為 0，之後有新檔案仍可再次提示
      lastNewFileCount = newCount;
    })
    .catch(err => console.error('checkNewFiles 錯誤：', err));
}

let lastNewFileCount = 0;

// 每 10 秒檢查一次
setInterval(checkNewFiles, 10000);



// 隱藏 Modal 後移除所有遺留的 backdrop 元素
document.addEventListener('hidden.bs.modal', function (e) {
  document.querySelectorAll('.modal-backdrop').forEach(function(el) {
    el.parentNode.removeChild(el);
  });
});

document.getElementById('sendFileModal').addEventListener('hidden.bs.modal', function () {
  // 重置傳送檔案表單
  document.getElementById('sendFileForm').reset();
  // 如有需要，也可以清空提示訊息或其他元素
  document.getElementById('sendFileAlert').style.display = 'none';
});

// 檢查用戶狀態的函數
function checkAndUpdateUserStatus() {
    fetch('detect_inactive.php')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 如果有用戶被設為離線，重新載入使用者列表
            loadUserList();
        }
    })
    .catch(err => {
        console.error('檢查使用者狀態時出錯：', err);
    });
}
// 在頁面載入時立即執行
document.addEventListener('DOMContentLoaded', () => {
    // 立即發送一次心跳
    sendHeartbeat();
    
    // 每 3 秒發送一次心跳
    setInterval(sendHeartbeat, 3000);
    
    // 每 5 秒檢查一次狀態
    setInterval(checkAndUpdateUserStatus, 5000);
    
    // 每 5 秒重新載入使用者列表

});

setInterval(() => {
  fetch('update_last_active.php', { method: "POST" });
}, 10000); // 每 10 秒更新一次 last_active


// 定期檢查非活動用戶和設置為離線
setInterval(checkInactiveUsers, 60000); // 每60秒檢查一次

// 檢查非活動用戶的函數
function checkInactiveUsers() {
  fetch('detect_inactive.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.updated_users > 0) {
        // 有用戶被設為離線，更新用戶列表
        loadUserList();
      }
    })
    .catch(err => console.error('檢查非活動用戶時出錯：', err));
}




document.getElementById('bulkDeleteBtn').addEventListener('click', function() {
  console.log("Bulk delete clicked");
  // 收集所有已選取的勾選框
  let selectedItems = document.querySelectorAll('.file-card-inner .select-item:checked');
  let ids = [];
  selectedItems.forEach(item => {
     ids.push(item.value);
  });
  console.log("Selected IDs:", ids);
  
  if (ids.length === 0) {
     alert('請先選取要刪除的項目');
     return;
  }
  
  if (confirm('確定要刪除所選項目嗎？')) {
     fetch('bulk_delete.php', {
       method: 'POST',
       headers: { 'Content-Type': 'application/json' },
       body: JSON.stringify({ ids: ids })
     })
     .then(res => {
       console.log("Response received", res);
       return res.json();
     })
     .then(data => {
       console.log("Response data:", data);
       if(data.success) {
          alert('刪除成功');
          location.reload();
       } else {
          alert(data.msg || '刪除失敗');
       }
     })
     .catch(err => {
       console.error("Fetch error:", err);
     });
  }
});



</script>


<script>

// Double-click the file itself, excluding interactive controls and folder cards.
document.querySelectorAll('.file-card-inner').forEach(card => {
  card.addEventListener('dblclick', event => {
    if (event.target.closest('a, button, input, label, select, textarea, .home-file-actions')) return;
    openPreviewModal(Number(card.dataset.file));
  });
});

// === 打開預覽視窗 ===
function openPreviewModal(fileId) {
    // 將 preview.php 的連結指定到 iframe
    document.getElementById('previewFrame').src = 'preview.php?file_id=' + fileId;
    // 顯示 Bootstrap Modal
    var myModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
    myModal.show();
  }

</script>
<script src="assets/js/navbar_notifications.js?v=20261022-1"></script>
<script src="assets/js/presence.js?v=20260928-2"></script>
<script src="assets/js/message_notifications.js?v=20261021-5"></script>
<?php require __DIR__ . '/partials/online_users.php'; ?>
<script src="assets/js/storage_workspace.js?v=20261022-4"></script>
<script src="assets/js/cloud_search.js?v=20261022-1"></script>
</body>
</html>
