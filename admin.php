<?php
session_start();
require 'config.php';

// 【1】權限檢查：只有 is_admin = 1 才能進入
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: login.php");
    exit;
}

// 【2】讀取查詢參數 (用於切換顯示區塊)
$section = isset($_GET['section']) ? $_GET['section'] : 'users';

// =======================================
// (A) 新增使用者
// =======================================
if (isset($_POST['action']) && $_POST['action'] === 'addUser') {
    $newUsername = $_POST['username'];
    $newPassword = $_POST['password'];
    $newEmail    = $_POST['email'];
    $newRole     = $_POST['role'];
    $isAdmin     = ($newRole === 'admin') ? 1 : 0;

    // 檢查使用者名稱是否已存在
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $checkStmt->execute([$newUsername]);
    $exists = $checkStmt->fetchColumn();

    if ($exists > 0) {
        $_SESSION['admin_error'] = "使用者名稱 '{$newUsername}' 已存在";
        header("Location: admin.php?section=users");
        exit;
    }

    // 使用者名稱不存在，可以新增
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, is_admin) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$newUsername, $newPassword, $newEmail, $newRole, $isAdmin]);
    header("Location: admin.php?section=users");
    exit;
}

// (B) 刪除使用者
if (isset($_GET['delUser'])) {
    $delUid = (int) $_GET['delUser'];
    // 不允許刪除自己(避免把 admin 刪除掉)
    if ($delUid !== $_SESSION['user_id']) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$delUid]);
    }
    header("Location: admin.php?section=users");
    exit;
}

// (C) 刪除資料夾
if (isset($_GET['delFolder'])) {
    $delFolderId = (int)$_GET['delFolder'];
    $stmt = $pdo->prepare("DELETE FROM folders WHERE folder_id = ?");
    $stmt->execute([$delFolderId]);
    header("Location: admin.php?section=folders");
    exit;
}

// (D) 刪除檔案
if (isset($_GET['delFile'])) {
  $delFileId = (int) $_GET['delFile'];
  
  // 先查詢檔案資訊，取得 stored_filename
  $stmtFile = $pdo->prepare("SELECT stored_filename FROM files WHERE file_id = ?");
  $stmtFile->execute([$delFileId]);
  $fileInfo = $stmtFile->fetch(PDO::FETCH_ASSOC);
  
  if ($fileInfo) {
      // 組合檔案的完整路徑
      $filePath = __DIR__ . '/uploads/' . $fileInfo['stored_filename'];
      
      // 檢查檔案是否存在並刪除
      if (file_exists($filePath)) {
          unlink($filePath);
      }
  }
  
  // 刪除資料庫記錄
  $stmt = $pdo->prepare("DELETE FROM files WHERE file_id = ?");
  $stmt->execute([$delFileId]);
  
  header("Location: admin.php?section=files");
  exit;
}

// (E) 刪除通知
if (isset($_GET['delNotification'])) {
    $delNid = (int) $_GET['delNotification'];
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
    $stmt->execute([$delNid]);
    $returnUid = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
    $returnPage = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $returnUrl = "admin.php?section=notifications";
    if ($returnUid > 0) {
        $returnUrl .= "&uid={$returnUid}&page={$returnPage}";
    }
    header("Location: {$returnUrl}");
    exit;
}

// (F) 刪除聊天訊息
if (isset($_GET['delMessage'])) {
    $delMid = (int) $_GET['delMessage'];
    $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE id = ?");
    $stmt->execute([$delMid]);
    $returnUid = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
    $returnPage = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $returnUrl = "admin.php?section=chats";
    if ($returnUid > 0) {
        $returnUrl .= "&uid={$returnUid}&page={$returnPage}";
    }
    header("Location: {$returnUrl}");
    exit;
}

// (G) 公告管理：新增 / 刪除 / 編輯
if ($section === 'announcements') {
  // 1) 新增公告
  if (isset($_POST['action']) && $_POST['action'] === 'create_announcement') {
      $title    = trim($_POST['title']);
      $content  = trim($_POST['content']);
      $start    = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
      $end      = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;
      $isActive = isset($_POST['is_active']) ? 1 : 0;

      $stmtAnn = $pdo->prepare("
          INSERT INTO announcements (title, content, start_date, end_date, is_active, created_at)
          VALUES (?, ?, ?, ?, ?, NOW())
      ");
      $stmtAnn->execute([$title, $content, $start, $end, $isActive]);

      header("Location: admin.php?section=announcements");
      exit;
  }

  // 2) 刪除公告
  if (isset($_GET['delAnnouncement'])) {
      $delAid = (int) $_GET['delAnnouncement'];
      $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
      $stmt->execute([$delAid]);
      header("Location: admin.php?section=announcements");
      exit;
  }

 // 3) 編輯公告 (使用 AJAX 回傳 JSON 給前端)
if (isset($_POST['action']) && $_POST['action'] === 'edit_announcement') {
  // 設定頭，確保返回 JSON
  header('Content-Type: application/json');
  
  $id = (int)$_POST['id'];
  $title = trim($_POST['title']);
  $content = trim($_POST['content']);
  
  // 簡易限制標題 & 內容長度
  if (mb_strlen($title) > 50) {
      echo json_encode(['success' => false, 'msg' => "公告標題不可超過 50 字"]);
      exit;
  }
  if (mb_strlen($content) > 500) {
      echo json_encode(['success' => false, 'msg' => "公告內容不可超過 500 字"]);
      exit;
  }

  // 處理日期 - 確保正確格式化
  $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
  $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
  
  // 格式化日期，確保符合MySQL日期時間格式
  if ($startDate) {
      try {
          $startDateTime = new DateTime($startDate);
          $startDate = $startDateTime->format('Y-m-d H:i:s');
      } catch (Exception $e) {
          $startDate = null;
      }
  }
  
  if ($endDate) {
      try {
          $endDateTime = new DateTime($endDate);
          $endDate = $endDateTime->format('Y-m-d H:i:s');
      } catch (Exception $e) {
          $endDate = null;
      }
  }

  // 計算是否過期
  $now = new DateTime();
  $endDateTime = $endDate ? new DateTime($endDate) : null;
  $isExpired = ($endDateTime && $now > $endDateTime);

  // 使用者勾選
  $isManuallyActive = isset($_POST['is_active']) && $_POST['is_active'] ? 1 : 0;
  // 如果已過期則強制停用，否則依使用者勾選
  $finalActiveStatus = $isExpired ? 0 : $isManuallyActive;

  try {
      $stmt = $pdo->prepare("
          UPDATE announcements
          SET title = ?, content = ?, start_date = ?, end_date = ?, is_active = ?
          WHERE id = ?
      ");
      $result = $stmt->execute([$title, $content, $startDate, $endDate, $finalActiveStatus, $id]);
      
      if (!$result) {
          echo json_encode([
              'success' => false,
              'msg' => "資料庫更新失敗"
          ]);
          exit;
      }
      
      // 回傳 JSON 給前端，前端再更新畫面
      echo json_encode([
          'success' => true,
          'is_active' => $finalActiveStatus,
          'is_expired' => $isExpired
      ]);
  } catch (Exception $e) {
      echo json_encode([
          'success' => false,
          'msg' => "處理更新時發生異常: " . $e->getMessage()
      ]);
  }
  exit;
}
}

// 【3】 撈取資料 (依照 $section 取得對應資料)
$users = [];
$folders = [];
$files = [];
$notifs = [];
$chats = [];
$userFiles = [];  
$userInfo = [];   
$announcements = [];
$recordSummary = [];

// (A) 使用者列表
if ($section === 'users') {
    $stmtUsers = $pdo->query("SELECT * FROM users ORDER BY user_id ASC");
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
}

// (B) 資料夾列表
elseif ($section === 'folders') {
    $stmtFolders = $pdo->query("
      SELECT f.*, u.username
      FROM folders f
      LEFT JOIN users u ON f.user_id = u.user_id
      ORDER BY f.parent_folder ASC, f.folder_id ASC
    ");
    $folders = $stmtFolders->fetchAll(PDO::FETCH_ASSOC);
}

// (C) 檔案列表
elseif ($section === 'files') {
    $stmtFiles = $pdo->query("
      SELECT fi.*, u.username, fo.folder_name
      FROM files fi
      LEFT JOIN users u ON fi.user_id = u.user_id
      LEFT JOIN folders fo ON fi.folder_id = fo.folder_id
      ORDER BY fi.folder_id ASC, fi.file_id DESC
    ");
    $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);
}

// (D) 通知列表 (改成「與聊天紀錄一樣」顯示使用者 -> 再進入通知)
elseif ($section === 'notifications') {
    // 若只想查看「某位使用者」收/發的所有通知
    if (isset($_GET['uid'])) {
        $uid = (int)$_GET['uid'];
        
        // 檢查該使用者是否存在
        $stmtCheck = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmtCheck->execute([$uid]);
        $userInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($userInfo) {
            // 簡易分頁
            $limit = 10;
            $page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($page - 1) * $limit;

            // 查詢總筆數(該 user 收到 或 該 user 發送的)
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN recipient_id = :unread_uid AND is_read = 0 THEN 1 ELSE 0 END) AS unread,
                       SUM(CASE WHEN type = 'message' THEN 1 ELSE 0 END) AS message_count,
                       SUM(CASE WHEN type = 'file' THEN 1 ELSE 0 END) AS file_count
                FROM notifications
                WHERE sender_id = :sender_uid OR recipient_id = :recipient_uid
            ");
            $countStmt->execute([
                ':unread_uid' => $uid,
                ':sender_uid' => $uid,
                ':recipient_uid' => $uid,
            ]);
            $recordSummary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalNotifs = (int)($recordSummary['total'] ?? 0);
            $totalPages = ceil($totalNotifs / $limit);

            // 撈資料
            $stmtN = $pdo->prepare("
                SELECT n.*, s.username AS sender_name, r.username AS recipient_name
                FROM notifications n
                LEFT JOIN users s ON n.sender_id = s.user_id
                LEFT JOIN users r ON n.recipient_id = r.user_id
                WHERE n.sender_id = :sender_uid OR n.recipient_id = :recipient_uid
                ORDER BY n.id DESC
                LIMIT :offset, :lim
            ");
            $stmtN->bindValue(':sender_uid', $uid, PDO::PARAM_INT);
            $stmtN->bindValue(':recipient_uid', $uid, PDO::PARAM_INT);
            $stmtN->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtN->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmtN->execute();
            $notifs = $stmtN->fetchAll(PDO::FETCH_ASSOC);

            // 分頁用資料
            $pagination = [
              'current' => $page,
              'total'   => $totalPages
            ];
        }
    } else {
        // 若未指定 uid，則顯示所有使用者列表（與「聊天紀錄」作法相同）
        $stmtUsers = $pdo->query("
          SELECT u.user_id, u.username, u.email,
                 (SELECT COUNT(*) FROM notifications n
                  WHERE n.sender_id = u.user_id OR n.recipient_id = u.user_id) AS activity_count,
                 (SELECT COUNT(*) FROM notifications n
                  WHERE n.recipient_id = u.user_id AND n.is_read = 0) AS unread_count
          FROM users u
          ORDER BY u.user_id ASC
        ");
        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    }
}

// (E) 聊天紀錄
elseif ($section === 'chats') {
    // 若只想查看「某位使用者」與所有人的對話
    if (isset($_GET['uid'])) {
        $uid = (int)$_GET['uid'];

        // 先檢查該使用者是否存在
        $stmtCheck = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmtCheck->execute([$uid]);
        $userInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($userInfo) {
            // 簡易分頁
            $limit = 15; 
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $offset = ($page - 1) * $limit;

            // 撈此使用者「發送或接收」的所有訊息
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) AS total,
                       COUNT(DISTINCT CASE
                           WHEN sender_id = :partner_sender_uid THEN recipient_id
                           ELSE sender_id
                       END) AS partner_count
                FROM chat_messages
                WHERE sender_id = :sender_uid OR recipient_id = :recipient_uid
            ");
            $countStmt->execute([
                ':partner_sender_uid' => $uid,
                ':sender_uid' => $uid,
                ':recipient_uid' => $uid,
            ]);
            $recordSummary = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalChats = (int)($recordSummary['total'] ?? 0);
            $totalPages = ceil($totalChats / $limit);

            $stmtC = $pdo->prepare("
              SELECT c.*, s.username AS sender_name, r.username AS recipient_name
              FROM chat_messages c
              LEFT JOIN users s ON c.sender_id = s.user_id
              LEFT JOIN users r ON c.recipient_id = r.user_id
              WHERE c.sender_id = :sender_uid OR c.recipient_id = :recipient_uid
              ORDER BY c.id DESC
              LIMIT :offset, :limit
            ");
            $stmtC->bindValue(':sender_uid', $uid, PDO::PARAM_INT);
            $stmtC->bindValue(':recipient_uid', $uid, PDO::PARAM_INT);
            $stmtC->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtC->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmtC->execute();
            $chats = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            // 分頁資訊
            $pagination = [
              'current' => $page,
              'total'   => $totalPages
            ];
        }
    } 
    else {
        // 若未指定 uid，則顯示所有使用者列表
        $stmtUsers = $pdo->query("
          SELECT u.user_id, u.username, u.email,
                 (SELECT COUNT(*) FROM chat_messages c
                  WHERE c.sender_id = u.user_id OR c.recipient_id = u.user_id) AS activity_count,
                 (SELECT MAX(c.sent_at) FROM chat_messages c
                  WHERE c.sender_id = u.user_id OR c.recipient_id = u.user_id) AS last_activity
          FROM users u
          ORDER BY u.user_id ASC
        ");
        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    }
}

// (F) 使用者檔案列表 (顯示特定用戶的檔案)
elseif ($section === 'user_files' && isset($_GET['uid'])) {
    $uid = (int)$_GET['uid'];
    $stmt = $pdo->prepare("
      SELECT fi.*, fo.folder_name
      FROM files fi
      LEFT JOIN folders fo ON fi.folder_id = fo.folder_id
      WHERE fi.user_id = ?
      ORDER BY fi.file_id DESC
    ");
    $stmt->execute([$uid]);
    $userFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 取得使用者名稱（可選）
    $stmtName = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmtName->execute([$uid]);
    $userInfo = $stmtName->fetch(PDO::FETCH_ASSOC);
}

// (G) 公告管理 (全部公告)
elseif ($section === 'announcements') {
    $stmtA = $pdo->query("SELECT * FROM announcements ORDER BY id DESC");
    $announcements = $stmtA->fetchAll(PDO::FETCH_ASSOC);
}

// (H) 編輯使用者
if (isset($_POST['action']) && $_POST['action'] === 'editUser') {
  $userId = (int)$_POST['user_id'];
  $username = trim($_POST['username']);
  $email = trim($_POST['email']);
  $role = $_POST['role'];
  $isAdmin = ($role === 'admin') ? 1 : 0;

  // 檢查是否修改了自己的帳號
  if ($userId === $_SESSION['user_id']) {
      $_SESSION['admin_error'] = "不可修改自己的帳號";
      header("Location: admin.php?section=users");
      exit;
  }

  // 驗證使用者名稱是否重複（排除當前使用者）
  $checkStmt = $pdo->prepare("
      SELECT COUNT(*) FROM users 
      WHERE username = ? AND user_id != ?
  ");
  $checkStmt->execute([$username, $userId]);
  $exists = $checkStmt->fetchColumn();

  if ($exists > 0) {
      $_SESSION['admin_error'] = "使用者名稱 '{$username}' 已存在";
      header("Location: admin.php?section=users");
      exit;
  }

  // 更新使用者資訊
  $updateStmt = $pdo->prepare("
      UPDATE users 
      SET username = ?, email = ?, role = ?, is_admin = ? 
      WHERE user_id = ?
  ");
  $result = $updateStmt->execute([
      $username, $email, $role, $isAdmin, $userId
  ]);

  if ($result) {
      header("Location: admin.php?section=users");
      exit;
  } else {
      $_SESSION['admin_error'] = "更新使用者資訊失敗";
      header("Location: admin.php?section=users");
      exit;
  }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">

  <title>雲上聊頁來｜後台管理</title>
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    html, body {
      height: 100%;
      margin: 0;
      background: #1a1a2e;
      color: #fff;
    }
    .navbar {
      margin-bottom: 1.25rem;
      border-bottom: 1px solid #343853;
      box-shadow: 0 4px 18px rgba(0, 0, 0, .2);
    }
    .card {
      background: #222541;
      color: #fff;
      border-radius: 8px;
      border: 1px solid #3f4468;
      box-shadow: 0 12px 32px rgba(0, 0, 0, .2);
    }
    .card-body {
      padding: 1.25rem;
    }
    .btn-light, .btn-secondary, .btn-info, .btn-danger, .btn-warning, .btn-primary, .btn-success {
      border-radius: 6px;
    }
    .table thead th {
      padding: .8rem .75rem;
      background: #191c32;
      color: #dfe3f2;
      border-color: #414663;
      font-size: .8rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .table tbody tr {
      background: #292d4b;
      color: #f4f5fa;
      border-color: #414663;
      transition: background-color .15s ease;
    }
    .table tbody tr:nth-child(even) {
      background: #252943;
    }
    .table tbody tr:hover {
      --bs-table-accent-bg: transparent;
      color: #fff;
      background: #343957;
    }
    .table tbody tr td {
      padding: .75rem;
      border-color: #414663;
      vertical-align: middle;
    }
    .table a {
      color: #fff;
      text-decoration: none;
    }
    .table a:hover {
      color: #ccc;
      text-decoration: underline;
    }
    .card-header {
      background: #19193b;
    }
    .admin-page .navbar .nav-link {
      color: #ddd !important;
    }
    .admin-section-nav {
      gap: .4rem;
      margin-bottom: 1rem !important;
      padding: .45rem;
      border: 1px solid #3f4468;
      border-radius: 8px;
      background: #20233d;
      scrollbar-width: none;
    }
    .admin-section-nav::-webkit-scrollbar {
      display: none;
    }
    .admin-section-nav .nav-link {
      padding: .55rem .8rem;
      color: #c8ccda !important;
      border: 1px solid transparent;
      border-radius: 6px;
      white-space: nowrap;
    }
    .admin-section-nav .nav-link:hover {
      color: #fff !important;
      border-color: #505676;
      background: #2c304d;
    }
    .admin-section-nav .nav-link.active {
      background-color: #0d6efd !important;
      color: #fff !important;
      border-color: #4dabf7;
    }
    .form-control {
      border-radius: 6px;
      border: 1px solid #555c80;
      background: #191c32;
      color: #fff;
    }
    .form-control:focus {
      border-color: #4dabf7;
      background: #191c32;
      color: #fff;
      box-shadow: 0 0 0 .2rem rgba(77, 171, 247, .18);
    }
    .form-control::placeholder {
      color: #9298ad;
    }
    .form-select {
      border-color: #555c80;
      border-radius: 6px !important;
      background-color: #191c32;
      color: #fff;
    }
    .search-box {
      max-width: 300px;
      margin-bottom: 1rem;
    }
    .admin-record-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1rem;
      padding-bottom: .9rem;
      border-bottom: 1px solid #414663;
    }
    .admin-record-header .btn {
      flex: 0 0 auto;
    }
    .record-summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: .75rem;
      margin-bottom: 1rem;
    }
    .summary-item {
      padding: .85rem 1rem;
      background: #191c32;
      border: 1px solid #3f4468;
      border-left: 3px solid #4dabf7;
      border-radius: 6px;
    }
    .summary-item .summary-label {
      display: block;
      color: #adb5bd;
      font-size: .8rem;
      margin-bottom: .15rem;
    }
    .summary-item strong {
      font-size: 1.2rem;
    }
    .record-table tbody tr {
      background: #292d4b;
    }
    .record-table {
      --bs-table-color: #f8f9fa;
      --bs-table-hover-color: #fff;
      color: #f8f9fa;
    }
    .record-table tbody tr:hover {
      background: #343957;
    }
    .record-table td {
      border-color: rgba(255, 255, 255, .08);
    }
    .message-cell {
      min-width: 220px;
      max-width: 440px;
      white-space: normal;
      overflow-wrap: anywhere;
    }
    .direction-label,
    .record-type,
    .read-state {
      display: inline-flex;
      align-items: center;
      gap: .25rem;
      padding: .22rem .5rem;
      border-radius: 4px;
      font-size: .78rem;
      font-weight: 600;
      white-space: nowrap;
    }
    .direction-out { background: #0d6efd; color: #fff; }
    .direction-in { background: #198754; color: #fff; }
    .record-message { background: #3d3270; color: #e7ddff; }
    .record-file { background: #164e63; color: #cffafe; }
    .record-system { background: #495057; color: #fff; }
    .read-state.is-read { background: #343a40; color: #ced4da; }
    .read-state.is-unread { background: #842029; color: #fff; }
    .empty-records {
      padding: 2.5rem 1rem;
      text-align: center;
      color: #adb5bd;
    }
    .admin-page .table-responsive {
      border: 1px solid #414663;
      border-radius: 7px;
      background: #191c32;
    }
    .admin-page .table {
      --bs-table-color: #f4f5fa;
      --bs-table-striped-color: #f4f5fa;
      --bs-table-hover-color: #fff;
      margin-bottom: 0;
    }
    .admin-page .table:not(.record-table) {
      min-width: 800px;
    }
    .admin-page .table:not(.record-table) td {
      white-space: nowrap;
    }
    .admin-page .pagination {
      margin-top: 1rem;
      margin-bottom: 0;
    }
    .admin-page .page-link {
      border-color: #444a6c;
      background: #20233d;
      color: #dfe3f2;
    }
    .admin-page .page-item.active .page-link {
      border-color: #4dabf7;
      background: #0d6efd;
    }
    .admin-page .modal-content {
      border: 1px solid #4a5074;
      background: #222541;
      color: #fff;
    }
    .admin-page .modal-header,
    .admin-page .modal-footer {
      border-color: #414663;
    }
    .admin-page .btn-close {
      filter: invert(1);
    }
    @media (max-width: 767.98px) {
      .card-body {
        padding: .9rem;
      }
      .admin-section-nav {
        margin-right: -.75rem;
        margin-left: -.75rem;
        border-right: 0;
        border-left: 0;
        border-radius: 0;
      }
      .admin-record-header {
        align-items: flex-start;
        flex-direction: column;
      }
      .admin-record-header .btn {
        width: 100%;
      }
      .record-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .record-table {
        min-width: 0 !important;
      }
      .record-table thead {
        display: none;
      }
      .record-table tbody,
      .record-table tr,
      .record-table td {
        display: block;
        width: 100%;
      }
      .record-table tr {
        margin-bottom: .75rem;
        padding: .35rem .75rem;
        border: 2px solid #454b70;
        border-radius: 6px;
      }
      .record-table td {
        display: grid;
        grid-template-columns: 6rem minmax(0, 1fr);
        gap: .75rem;
        padding: .55rem 0;
        border-top: 1px solid rgba(255, 255, 255, .08);
      }
      .record-table td:first-child {
        border-top: 0;
      }
      .record-table td::before {
        content: attr(data-label);
        color: #adb5bd;
        font-size: .8rem;
        font-weight: 600;
      }
      .record-table .message-cell {
        min-width: 0;
        max-width: none;
      }
      .admin-page .table-responsive:not(:has(.record-table)) {
        border-radius: 6px;
      }
    }
    /* 編輯公告 Modal 的樣式 */
    #editAnnouncementModal .modal-content {
      background-color: #25274d;
      color: #fff;
    }
    #editAnnouncementModal .form-control {
      background-color: #2f2f5a;
      color: #fff;
      border-color: #666;
    }
    #editAnnouncementModal .form-control:focus {
      background-color: #2f2f5a;
      color: #fff;
      border-color: #007bff;
      box-shadow: 0 0 5px rgba(217, 240, 15, 0.3);
    }
    #editAnnouncementModal .form-label {
      color: #ddd;
    }
    #editAnnouncementModal .btn-close {
      filter: invert(1);
    }
    #editAnnouncementModal .form-check-label {
      color: #fff;
    }
    .text-danger { color: #dc3545 !important; }
    .text-primary { color: #0d6efd !important; }
  </style>
  <link href="assets/css/responsive.css?v=20260928-7" rel="stylesheet">
</head>
<body class="admin-page">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="admin.php">雲上聊頁來｜後台管理</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="切換導覽選單">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="adminNavbar">
    <ul class="navbar-nav ms-auto">
      <li class="nav-item">
        <!-- 回到前台(例如 index.php) -->
        <a class="nav-link" href="index.php"><i class="bi bi-house-door me-1"></i>回到前台</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>登出</a>
      </li>
    </ul>
    </div>
  </div>
</nav>

<div class="container mb-5">
  <!-- 切換區域連結(可用 nav-pills 或 nav-tabs) -->
  <ul class="nav nav-pills justify-content-center mb-3 admin-section-nav">
    <li class="nav-item">
      <a class="nav-link <?= ($section === 'users') ? 'active' : '' ?>" href="?section=users"><i class="bi bi-people me-1"></i>使用者</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($section === 'folders') ? 'active' : '' ?>" href="?section=folders"><i class="bi bi-folder me-1"></i>資料夾</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($section === 'files') ? 'active' : '' ?>" href="?section=files"><i class="bi bi-file-earmark me-1"></i>檔案</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($section === 'notifications') ? 'active' : '' ?>" href="?section=notifications"><i class="bi bi-bell me-1"></i>通知</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($section === 'chats') ? 'active' : '' ?>" href="?section=chats"><i class="bi bi-chat-dots me-1"></i>聊天紀錄</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= ($section === 'announcements') ? 'active' : '' ?>" href="?section=announcements"><i class="bi bi-megaphone me-1"></i>公告管理</a>
    </li>
  </ul>

  <div class="card">
    <div class="card-body">
      
<?php if ($section === 'users'): ?>

  <!-- 使用者管理 -->
  <h4 class="card-title mb-3">使用者管理</h4>

  <!-- 錯誤訊息顯示 -->
  <?php if (isset($_SESSION['admin_error'])): ?>
    <div class="alert alert-danger">
      <?php echo $_SESSION['admin_error']; unset($_SESSION['admin_error']); ?>
    </div>
  <?php endif; ?>

  <!-- 新增使用者表單 -->
  <div class="mb-4">
    <form class="row row-cols-lg-auto g-3 align-items-center" method="post" action="admin.php?section=users">
      <div class="col-12">
        <input type="text" class="form-control" name="username" placeholder="使用者名稱" required>
      </div>
      <div class="col-12">
        <input type="text" class="form-control" name="password" placeholder="密碼(示範未做hash)" required>
      </div>
      <div class="col-12">
        <input type="email" class="form-control" name="email" placeholder="Email" required>
      </div>
      <div class="col-12">
        <select class="form-select" name="role" style="border-radius: 10px;">
          <option value="user">user</option>
          <option value="admin">admin</option>
        </select>
      </div>
      <input type="hidden" name="action" value="addUser">
      <div class="col-12">
        <button type="submit" class="btn btn-success">新增使用者</button>
      </div>
    </form>
  </div>

  <!-- 動態搜尋輸入框 -->
  <div class="search-box">
    <input type="text" class="form-control" id="searchUsers" placeholder="搜尋使用者...">
  </div>

  <!-- 使用者列表 -->
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="tableUsers">
      <thead>
        <tr>
          <th>UID</th>
          <th>名稱</th>
          <th>Email</th>
          <th>角色</th>
          <th>狀態</th>
          <th>最後活動</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= $u['user_id'] ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><?= $u['role'] ?></td>
          <td><?= $u['status'] ?></td>
          <td><?= $u['last_active'] ?></td>
          <td>
  <a href="admin.php?section=user_files&uid=<?= $u['user_id'] ?>" class="btn btn-info btn-sm">
    <i class="bi bi-folder2-open"></i> 檔案
  </a>
  <button class="btn btn-warning btn-sm edit-user-btn"
          data-id="<?= $u['user_id'] ?>"
          data-username="<?= htmlspecialchars($u['username']) ?>"
          data-email="<?= htmlspecialchars($u['email']) ?>"
          data-role="<?= $u['role'] ?>">
    <i class="bi bi-pencil"></i>
  </button>
  <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
    <a href="?section=users&delUser=<?= $u['user_id'] ?>"
       onclick="return confirm('確定刪除使用者 <?= htmlspecialchars($u['username']) ?> ?')"
       class="btn btn-danger btn-sm">
      <i class="bi bi-trash"></i>
    </a>
  <?php else: ?>
    <span class="text-muted">無法刪除自己</span>
  <?php endif; ?>
</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($section === 'folders'): ?>

  <!-- 資料夾列表 -->
  <h4 class="card-title mb-3">資料夾列表</h4>
  <div class="search-box">
    <input type="text" class="form-control" id="searchFolders" placeholder="搜尋資料夾...">
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="tableFolders">
      <thead>
        <tr>
          <th>Folder ID</th>
          <th>Owner(使用者)</th>
          <th>資料夾名稱</th>
          <th>Parent Folder</th>
          <th>建立時間</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f): ?>
        <tr>
          <td><?= $f['folder_id'] ?></td>
          <td><?= htmlspecialchars($f['username'] ?? '-') ?></td>
          <td><?= htmlspecialchars($f['folder_name']) ?></td>
          <td><?= $f['parent_folder'] ?></td>
          <td><?= $f['created_at'] ?></td>
          <td>
            <a href="?section=folders&delFolder=<?= $f['folder_id'] ?>"
               onclick="return confirm('確定刪除資料夾: <?= htmlspecialchars($f['folder_name']) ?>?')"
               class="btn btn-danger btn-sm">
              <i class="bi bi-folder-x"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($section === 'files'): ?>

  <!-- 檔案列表 -->
  <h4 class="card-title mb-3">檔案列表</h4>
  <div class="search-box">
    <input type="text" class="form-control" id="searchFiles" placeholder="搜尋檔案...">
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="tableFiles">
      <thead>
        <tr>
          <th>File ID</th>
          <th>Owner(使用者)</th>
          <th>檔名</th>
          <th>所在資料夾</th>
          <th>檔案大小(KB)</th>
          <th>建立時間</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($files as $f): ?>
        <tr>
          <td><?= $f['file_id'] ?></td>
          <td><?= htmlspecialchars($f['username'] ?? '-') ?></td>
          <td><?= htmlspecialchars($f['original_filename']) ?></td>
          <td><?= htmlspecialchars($f['folder_name'] ?? '根目錄') ?></td>
          <td><?= number_format($f['file_size']/1024,2) ?></td>
          <td><?= $f['created_at'] ?></td>
          <td>
            <a href="?section=files&delFile=<?= $f['file_id'] ?>"
               onclick="return confirm('確定刪除檔案: <?= htmlspecialchars($f['original_filename']) ?>?')"
               class="btn btn-danger btn-sm">
              <i class="bi bi-trash"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($section === 'notifications'): ?>

  <?php if (empty($_GET['uid'])): ?>
    <!-- 若未指定 uid，顯示所有使用者列表 (模仿聊天紀錄的作法) -->
    <div class="admin-record-header">
      <div>
        <h4 class="card-title mb-1"><i class="bi bi-bell me-2"></i>通知紀錄</h4>
        <div class="text-white-50">選擇使用者查看收發通知與未讀狀態</div>
      </div>
    </div>
    <div class="search-box">
      <input type="search" class="form-control" id="searchUsersChat" placeholder="搜尋名稱、Email 或 UID">
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="tableUsersChat">
        <thead>
          <tr>
            <th>UID</th>
            <th>名稱</th>
            <th>Email</th>
            <th>紀錄數</th>
            <th>未讀</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= $u['user_id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= (int)($u['activity_count'] ?? 0) ?></td>
            <td>
              <?php if ((int)($u['unread_count'] ?? 0) > 0): ?>
                <span class="badge bg-danger"><?= (int)$u['unread_count'] ?></span>
              <?php else: ?>
                <span class="text-white-50">0</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="?section=notifications&uid=<?= $u['user_id'] ?>" class="btn btn-info btn-sm text-dark">
                <i class="bi bi-bell"></i> 查看通知
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <!-- 已選定某位使用者 -> 顯示該使用者的通知紀錄 -->
    <?php if (!$userInfo): ?>
      <div class="alert alert-warning">無此使用者或使用者不存在。</div>
    <?php else: ?>
      <div class="admin-record-header">
        <div>
          <h4 class="card-title mb-1"><i class="bi bi-bell me-2"></i><?= htmlspecialchars($userInfo['username'] ?? '未知') ?> 的通知紀錄</h4>
          <div class="text-white-50">包含此使用者發送與接收的通知</div>
        </div>
        <a href="admin.php?section=notifications" class="btn btn-light">
          <i class="bi bi-arrow-left me-1"></i>返回使用者列表
        </a>
      </div>

      <div class="record-summary">
        <div class="summary-item"><span class="summary-label">全部紀錄</span><strong><?= (int)($recordSummary['total'] ?? 0) ?></strong></div>
        <div class="summary-item"><span class="summary-label">收到且未讀</span><strong><?= (int)($recordSummary['unread'] ?? 0) ?></strong></div>
        <div class="summary-item"><span class="summary-label">訊息通知</span><strong><?= (int)($recordSummary['message_count'] ?? 0) ?></strong></div>
        <div class="summary-item"><span class="summary-label">檔案通知</span><strong><?= (int)($recordSummary['file_count'] ?? 0) ?></strong></div>
      </div>

      <div class="search-box">
        <input type="search" class="form-control" id="searchNotifs" placeholder="搜尋發送者、接收者或內容">
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle record-table" id="tableNotifs">
          <thead>
            <tr>
              <th>ID</th>
              <th>方向</th>
              <th>發送者</th>
              <th>接收者</th>
              <th>類型</th>
              <th>訊息</th>
              <th>已讀?</th>
              <th>建立時間</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($notifs)): ?>
            <tr><td colspan="9" class="empty-records">目前沒有通知紀錄</td></tr>
          <?php endif; ?>
          <?php foreach ($notifs as $n): ?>
            <tr>
              <td data-label="ID">#<?= (int)$n['id'] ?></td>
              <td data-label="方向">
                <?php if ((int)$n['sender_id'] === $uid): ?>
                  <span class="direction-label direction-out"><i class="bi bi-arrow-up-right"></i>發出</span>
                <?php else: ?>
                  <span class="direction-label direction-in"><i class="bi bi-arrow-down-left"></i>收到</span>
                <?php endif; ?>
              </td>
              <td data-label="發送者"><?= htmlspecialchars($n['sender_name'] ?? '系統') ?></td>
              <td data-label="接收者"><?= htmlspecialchars($n['recipient_name'] ?? '-') ?></td>
              <td data-label="類型"><span class="record-type record-<?= htmlspecialchars($n['type']) ?>"><?= $n['type'] === 'message' ? '訊息' : ($n['type'] === 'file' ? '檔案' : '系統') ?></span></td>
              <td data-label="訊息" class="message-cell"><?= htmlspecialchars($n['message']) ?></td>
              <td data-label="狀態"><span class="read-state <?= $n['is_read'] ? 'is-read' : 'is-unread' ?>"><?= $n['is_read'] ? '已讀' : '未讀' ?></span></td>
              <td data-label="建立時間"><?= htmlspecialchars($n['created_at']) ?></td>
              <td data-label="操作">
                <a href="?section=notifications&uid=<?= $uid ?>&page=<?= (int)($pagination['current'] ?? 1) ?>&delNotification=<?= $n['id'] ?>"
                   onclick="return confirm('確定刪除此通知?')"
                   class="btn btn-outline-danger btn-sm" title="刪除通知" aria-label="刪除通知">
                  <i class="bi bi-trash"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!empty($pagination)): ?>
        <nav>
          <ul class="pagination">
            <?php for ($p=1; $p<=$pagination['total']; $p++): ?>
              <li class="page-item <?= ($p == $pagination['current']) ? 'active' : '' ?>">
                <a class="page-link" href="?section=notifications&uid=<?= $uid ?>&page=<?= $p ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($section === 'chats'): ?>

  <?php if (empty($_GET['uid'])): ?>
    <!-- 若未指定 uid，顯示所有使用者列表 -->
    <div class="admin-record-header">
      <div>
        <h4 class="card-title mb-1"><i class="bi bi-chat-dots me-2"></i>聊天紀錄</h4>
        <div class="text-white-50">選擇使用者查看完整收發訊息</div>
      </div>
    </div>
    <div class="search-box">
      <input type="search" class="form-control" id="searchUsersChat" placeholder="搜尋名稱、Email 或 UID">
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="tableUsersChat">
        <thead>
          <tr>
            <th>UID</th>
            <th>名稱</th>
            <th>Email</th>
            <th>訊息數</th>
            <th>最後訊息</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= $u['user_id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= (int)($u['activity_count'] ?? 0) ?></td>
            <td><?= !empty($u['last_activity']) ? htmlspecialchars($u['last_activity']) : '<span class="text-white-50">尚無紀錄</span>' ?></td>
            <td>
              <a href="?section=chats&uid=<?= $u['user_id'] ?>" class="btn btn-info btn-sm text-dark">
                <i class="bi bi-chat-dots"></i> 查看聊天
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <!-- 指定某位使用者聊天紀錄 -->
    <?php if (!$userInfo): ?>
      <div class="alert alert-warning">無此使用者或使用者不存在。</div>
    <?php else: ?>
      <div class="admin-record-header">
        <div>
          <h4 class="card-title mb-1"><i class="bi bi-chat-dots me-2"></i><?= htmlspecialchars($userInfo['username'] ?? '未知') ?> 的聊天紀錄</h4>
          <div class="text-white-50">依最新訊息排序，清楚標示收發方向</div>
        </div>
        <a href="admin.php?section=chats" class="btn btn-light">
          <i class="bi bi-arrow-left me-1"></i>返回使用者列表
        </a>
      </div>

      <div class="record-summary">
        <div class="summary-item"><span class="summary-label">訊息總數</span><strong><?= (int)($recordSummary['total'] ?? 0) ?></strong></div>
        <div class="summary-item"><span class="summary-label">對話人數</span><strong><?= (int)($recordSummary['partner_count'] ?? 0) ?></strong></div>
        <div class="summary-item"><span class="summary-label">目前頁數</span><strong><?= (int)($pagination['current'] ?? 1) ?> / <?= max(1, (int)($pagination['total'] ?? 1)) ?></strong></div>
      </div>

      <div class="search-box">
        <input type="search" class="form-control" id="searchChats" placeholder="搜尋對象或訊息內容">
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle record-table" id="tableChats">
          <thead>
            <tr>
              <th>ID</th>
              <th>方向</th>
              <th>發送者</th>
              <th>接收者</th>
              <th>訊息內容</th>
              <th>時間</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($chats)): ?>
            <tr><td colspan="7" class="empty-records">目前沒有聊天紀錄</td></tr>
          <?php endif; ?>
          <?php foreach ($chats as $c): ?>
            <tr>
              <td data-label="ID">#<?= (int)$c['id'] ?></td>
              <td data-label="方向">
                <?php if ((int)$c['sender_id'] === $uid): ?>
                  <span class="direction-label direction-out"><i class="bi bi-arrow-up-right"></i>發出</span>
                <?php else: ?>
                  <span class="direction-label direction-in"><i class="bi bi-arrow-down-left"></i>收到</span>
                <?php endif; ?>
              </td>
              <td data-label="發送者"><?= htmlspecialchars($c['sender_name'] ?? '-') ?></td>
              <td data-label="接收者"><?= htmlspecialchars($c['recipient_name'] ?? '-') ?></td>
              <td data-label="訊息內容" class="message-cell"><?= nl2br(htmlspecialchars($c['message'])) ?></td>
              <td data-label="時間"><?= htmlspecialchars($c['sent_at']) ?></td>
              <td data-label="操作">
                <a href="?section=chats&uid=<?= $uid ?>&page=<?= (int)($pagination['current'] ?? 1) ?>&delMessage=<?= $c['id'] ?>"
                   onclick="return confirm('確定刪除此聊天訊息?')"
                   class="btn btn-outline-danger btn-sm" title="刪除訊息" aria-label="刪除訊息">
                  <i class="bi bi-trash"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- 若有分頁資訊 -->
      <?php if (!empty($pagination)): ?>
        <nav>
          <ul class="pagination">
            <?php for ($p=1; $p<=$pagination['total']; $p++): ?>
            <li class="page-item <?= ($p == $pagination['current']) ? 'active' : '' ?>">
              <a class="page-link" href="?section=chats&uid=<?= (int)$_GET['uid'] ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($section === 'user_files'): ?>

  <?php if (empty($userFiles)): ?>
    <!-- 若此使用者沒有任何檔案也顯示返回按鈕 -->
    <h4 class="card-title mb-3"><?= htmlspecialchars($userInfo['username'] ?? '未知') ?> 的檔案 (目前無檔案)</h4>
    <a href="admin.php?section=users" class="btn btn-light mb-3">
      <i class="bi bi-arrow-left"></i> 返回使用者管理
    </a>
  <?php else: ?>
    <!-- 使用者檔案列表 -->
    <h4 class="card-title mb-3"><?= htmlspecialchars($userInfo['username'] ?? '未知') ?> 的檔案</h4>
    <a href="admin.php?section=users" class="btn btn-light mb-3"><i class="bi bi-arrow-left"></i> 返回使用者管理</a>
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="tableUserFiles">
        <thead>
          <tr>
            <th>File ID</th>
            <th>檔名</th>
            <th>所在資料夾</th>
            <th>檔案大小(KB)</th>
            <th>建立時間</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($userFiles as $f): ?>
          <tr>
            <td><?= $f['file_id'] ?></td>
            <td><?= htmlspecialchars($f['original_filename']) ?></td>
            <td><?= htmlspecialchars($f['folder_name'] ?? '根目錄') ?></td>
            <td><?= number_format($f['file_size']/1024,2) ?></td>
            <td><?= $f['created_at'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php elseif ($section === 'announcements'): ?>

  <!-- 公告管理 -->
  <h4 class="card-title mb-3">公告管理</h4>
  <!-- 新增公告表單 -->
  <div class="mb-4">
    <form class="row g-3" method="post" action="admin.php?section=announcements">
      <div class="col-md-6">
        <input type="text" class="form-control" name="title" placeholder="公告標題" required>
      </div>
      <div class="col-md-6">
        <div class="input-group">
          <input type="datetime-local" class="form-control" name="start_date" placeholder="開始日期 (選填)">
          <input type="datetime-local" class="form-control" name="end_date" placeholder="結束日期 (選填)">
        </div>
      </div>
      <div class="col-12">
        <textarea class="form-control" name="content" rows="3" placeholder="公告內容" required></textarea>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
          <label class="form-check-label" for="is_active">立即啟用</label>
        </div>
      </div>
      <input type="hidden" name="action" value="create_announcement">
      <div class="col-12">
        <button type="submit" class="btn btn-primary">新增公告</button>
      </div>
    </form>
  </div>

  <!-- 公告列表 -->
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>標題</th>
          <th>內容</th>
          <th>開始日期</th>
          <th>結束日期</th>
          <th>狀態</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($announcements as $a): ?>
        <?php 
          $now = new DateTime();
          $endDate = $a['end_date'] ? new DateTime($a['end_date']) : null;
          $isExpired = ($endDate && $now > $endDate);
        ?>
        <tr data-announcement-id="<?= $a['id'] ?>">
          <td><?= $a['id'] ?></td>
          <td class="announcement-title"><?= htmlspecialchars($a['title']) ?></td>
          <td class="announcement-content">
            <?= mb_substr($a['content'], 0, 50) . (mb_strlen($a['content']) > 50 ? '...' : '') ?>
          </td>
          <td class="announcement-start-date"><?= $a['start_date'] ?: '無' ?></td>
          <td class="announcement-end-date"><?= $a['end_date'] ?: '無' ?></td>
          <td class="announcement-status 
            <?php 
              if ($a['is_active'] && $isExpired) {
                echo 'text-danger'; 
              } else if ($a['is_active'] && !$isExpired) {
                echo 'text-primary';
              }
            ?>">
            <?php 
            if (!$a['is_active']) {
              echo '已停用';
            } else {
              echo $isExpired ? '已過期' : '已啟用';
            }
            ?>
          </td>
          <td>
            <button class="btn btn-sm btn-info edit-announcement" 
              data-id="<?= $a['id'] ?>"
              data-title="<?= htmlspecialchars($a['title']) ?>"
              data-content="<?= htmlspecialchars($a['content']) ?>"
              data-start="<?= $a['start_date'] ?>"
              data-end="<?= $a['end_date'] ?>"
              data-active="<?= $a['is_active'] ? 1 : 0 ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <a href="?section=announcements&delAnnouncement=<?= $a['id'] ?>"
               onclick="return confirm('確定刪除此公告？')"
               class="btn btn-danger btn-sm">
              <i class="bi bi-trash"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

    </div> <!-- card-body -->
  </div> <!-- card -->
</div> <!-- container -->

<!-- 編輯使用者 Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="admin.php?section=users">
      <div class="modal-header">
        <h5 class="modal-title">編輯使用者</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="editUser">
        <input type="hidden" name="user_id" id="editUserId">
        
        <div class="mb-3">
          <label class="form-label">使用者名稱</label>
          <input type="text" class="form-control" name="username" id="editUsername" required>
        </div>
        
        <div class="mb-3">
          <label class="form-label">電子郵件</label>
          <input type="email" class="form-control" name="email" id="editUserEmail" required>
        </div>
        
        <div class="mb-3">
          <label class="form-label">角色</label>
          <select class="form-select" name="role" id="editUserRole">
            <option value="user">使用者</option>
            <option value="admin">管理員</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">更新</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </form>
  </div>
</div>
<!-- ======================== 編輯公告 Modal (AJAX) ======================== -->
<div class="modal fade" id="editAnnouncementModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">編輯公告</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editAction" value="edit_announcement">
        <input type="hidden" id="editAnnouncementId">

        <div class="mb-3">
          <label class="form-label">公告標題</label>
          <input type="text" class="form-control" id="editAnnouncementTitle" required>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <label class="form-label">開始日期（選填）</label>
            <input type="datetime-local" class="form-control" id="editAnnouncementStartDate">
          </div>
          <div class="col-md-6">
            <label class="form-label">結束日期（選填）</label>
            <input type="datetime-local" class="form-control" id="editAnnouncementEndDate">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">公告內容</label>
          <textarea class="form-control" id="editAnnouncementContent" rows="3" required></textarea>
        </div>

        <div class="mb-3 form-check">
          <input type="checkbox" class="form-check-input" id="editAnnouncementIsActive">
          <label class="form-check-label" for="editAnnouncementIsActive">立即啟用</label>
        </div>

        <div id="editAnnouncementError" class="text-danger" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" id="editAnnouncementSaveBtn" class="btn btn-primary">更新公告</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
      </div>
    </div>
  </div>
</div>
<!-- ============================================================ -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ------------------ 前端動態搜尋 ------------------
function setupTableSearch(inputId, tableId) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;

  input.addEventListener('keyup', function() {
    const filter = this.value.toLowerCase();
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) {
      const rowText = rows[i].textContent.toLowerCase();
      rows[i].style.display = (rowText.indexOf(filter) > -1) ? '' : 'none';
    }
  });
}

// 啟用搜尋(若該 ID 不存在會自動略過)
setupTableSearch('searchUsers', 'tableUsers');
setupTableSearch('searchFolders', 'tableFolders');
setupTableSearch('searchFiles', 'tableFiles');
setupTableSearch('searchNotifs', 'tableNotifs');
setupTableSearch('searchChats', 'tableChats');
setupTableSearch('searchUsersChat', 'tableUsersChat');

// 編輯公告 - 綁定按鈕
document.querySelectorAll('.edit-announcement').forEach(btn => {
  btn.addEventListener('click', function() {
    const id        = this.dataset.id;
    const title     = this.dataset.title;
    const content   = this.dataset.content;
    const start     = this.dataset.start;
    const end       = this.dataset.end;
    const isActive  = (this.dataset.active === '1');

    // 填入 Modal 欄位
    document.getElementById('editAnnouncementId').value = id;
    document.getElementById('editAnnouncementTitle').value = title;
    document.getElementById('editAnnouncementContent').value = content;

    // 轉成可放入 <input type="datetime-local"> 的字串 (去掉秒數)
    const fixDateTime = (str) => {
      if (!str) return '';
      let d = new Date(str);
      if (isNaN(d.getTime())) return '';
      // toISOString() -> "2025-04-05T08:30:00.000Z"
      // slice(0,16) -> "2025-04-05T08:30"
      return d.toISOString().slice(0, 16);
    };
    document.getElementById('editAnnouncementStartDate').value = fixDateTime(start);
    document.getElementById('editAnnouncementEndDate').value   = fixDateTime(end);

    // 狀態
    document.getElementById('editAnnouncementIsActive').checked = isActive;

    // 顯示 Modal
    const errorEl = document.getElementById('editAnnouncementError');
    errorEl.style.display = 'none';
    errorEl.textContent   = '';

    let modalEl = new bootstrap.Modal(document.getElementById('editAnnouncementModal'));
    modalEl.show();
  });
});

// 編輯公告 - 儲存按鈕
document.getElementById('editAnnouncementSaveBtn').addEventListener('click', function(e){
  e.preventDefault();

  const id        = document.getElementById('editAnnouncementId').value;
  const title     = document.getElementById('editAnnouncementTitle').value.trim();
  const content   = document.getElementById('editAnnouncementContent').value.trim();
  const startDate = document.getElementById('editAnnouncementStartDate').value;
  const endDate   = document.getElementById('editAnnouncementEndDate').value;
  const isActive  = document.getElementById('editAnnouncementIsActive').checked ? 1 : 0;

  // 前端再次限制標題50字,內容500字
  if (title.length > 50) {
    showEditError("公告標題不可超過50字");
    return;
  }
  if (content.length > 500) {
    showEditError("公告內容不可超過500字");
    return;
  }

  const fd = new FormData();
  fd.append('action', 'edit_announcement');
  fd.append('id', id);
  fd.append('title', title);
  fd.append('content', content);
  fd.append('start_date', startDate);
  fd.append('end_date', endDate);
  fd.append('is_active', isActive);

  fetch('admin.php?section=announcements', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      showEditError(data.msg || "更新失敗");
    } else {
      // 後端成功，更新前端表格
      const row = document.querySelector(`tr[data-announcement-id="${id}"]`);
      if (row) {
        // 更新標題與內容
        row.querySelector('.announcement-title').textContent = title;
        let shortContent = content.length > 50 ? content.slice(0,50) + '...' : content;
        row.querySelector('.announcement-content').textContent = shortContent;

        // 更新開始/結束日期
        row.querySelector('.announcement-start-date').textContent = startDate || '無';
        row.querySelector('.announcement-end-date').textContent   = endDate   || '無';

        // 更新 dataset 以供下次編輯仍可帶入
        const editBtn = row.querySelector('.edit-announcement');
        editBtn.dataset.title   = title;
        editBtn.dataset.content = content;
        editBtn.dataset.start   = startDate;
        editBtn.dataset.end     = endDate;
        editBtn.dataset.active  = data.is_active ? '1' : '0';

        // 更新狀態顯示
        const statusCell = row.querySelector('.announcement-status');
        statusCell.classList.remove('text-danger', 'text-primary');
        
        if (data.is_expired) {
          statusCell.textContent = '已過期';
          statusCell.classList.add('text-danger');
        } else if (!data.is_active) {
          statusCell.textContent = '已停用';
        } else {
          statusCell.textContent = '已啟用';
          statusCell.classList.add('text-primary');
        }
      }
      // 關閉 Modal
      bootstrap.Modal.getInstance(document.getElementById('editAnnouncementModal')).hide();
    }
  })
  .catch(err => {
    showEditError("發生錯誤，請稍後再試");
    console.error(err);
  });
});

function showEditError(msg) {
  const errorEl = document.getElementById('editAnnouncementError');
  errorEl.textContent   = msg;
  errorEl.style.display = 'block';
}

// 編輯使用者按鈕事件
document.querySelectorAll('.edit-user-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const userId = this.dataset.id;
    const username = this.dataset.username;
    const email = this.dataset.email;
    const role = this.dataset.role;

    document.getElementById('editUserId').value = userId;
    document.getElementById('editUsername').value = username;
    document.getElementById('editUserEmail').value = email;
    document.getElementById('editUserRole').value = role;

    let modalEl = new bootstrap.Modal(document.getElementById('editUserModal'));
    modalEl.show();
  });
});
</script>
<script src="assets/js/presence.js?v=20260928-2"></script>
<script src="assets/js/message_notifications.js?v=20260928-2"></script>
</body>
</html>
