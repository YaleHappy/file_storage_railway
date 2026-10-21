<?php
session_start();
require 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$_SESSION['chat_csrf'] ??= bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>群組聊天｜雲上聊頁來</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/responsive.css?v=20261021-1" rel="stylesheet">
  <link href="assets/css/group_chat.css?v=20261021-1" rel="stylesheet">
</head>
<body class="has-fixed-navbar group-page">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top"><div class="container">
  <a class="navbar-brand fw-bold" href="index.php">☁️ 雲上聊頁來</a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#groupNavbar" aria-label="導覽選單" aria-expanded="false"><span class="navbar-toggler-icon"></span></button>
  <div class="collapse navbar-collapse" id="groupNavbar"><ul class="navbar-nav ms-auto">
    <li class="nav-item"><a class="nav-link" href="my_shares.php">🔗 我的分享</a></li>
    <li class="nav-item"><a class="nav-link" href="received_files.php">📥 收到的檔案</a></li>
    <li class="nav-item"><a class="nav-link active" href="chat_history.php">💬 聊天紀錄</a></li>
    <li class="nav-item"><span class="nav-link">👤 <?= htmlspecialchars($_SESSION['username'] ?? '') ?></span></li>
    <li class="nav-item navbar-session-actions"><a class="nav-link" href="logout.php">登出</a><?php require __DIR__ . '/navbar_notifications.php'; ?></li>
  </ul></div>
</div></nav>
<main class="container group-main">
  <div id="groupError" class="alert alert-danger" role="alert" hidden></div>
  <div class="group-heading"><div><h1>群組聊天</h1><a href="chat_history.php">← 私訊與最近對話</a></div><button type="button" class="btn btn-primary" id="createGroup">＋ 建立群組</button></div>
  <section class="group-shell" id="groupShell">
    <aside class="group-sidebar"><label for="groupSearch" class="visually-hidden">搜尋群組</label><input type="search" id="groupSearch" class="form-control" placeholder="搜尋群組"><div id="groupList" aria-label="群組列表"></div></aside>
    <section class="group-room" id="groupRoom" hidden>
      <header class="group-room-header"><a class="btn btn-outline-light group-back" href="group_chat.php" aria-label="返回群組列表">←</a><div class="flex-grow-1"><h2 id="groupTitle"></h2><small id="groupMemberCount"></small></div><button class="btn btn-outline-light" id="groupTools" type="button">群組管理</button><button class="btn btn-outline-light" id="openFiles" type="button">📎 檔案</button></header>
      <div id="groupAnnouncement" class="group-announcement" hidden></div>
      <div id="groupPins" class="group-pins" hidden></div>
      <div class="group-messages" id="groupMessages" aria-live="polite"><button id="olderMessages" type="button" class="btn btn-sm btn-outline-light">較早訊息</button><button id="latestMessages" type="button" class="btn btn-sm btn-outline-info ms-2" hidden>回到最新訊息</button><div id="groupTimeline"></div></div>
      <div id="replyPreview" class="group-reply-preview" hidden><span></span><button class="btn btn-sm btn-outline-light" type="button" id="cancelReply">取消回覆</button></div>
      <form id="groupComposer" class="group-composer">
        <details class="group-mentions"><summary>@ 提及</summary><div id="mentionMembers"></div></details>
        <div class="group-input-row"><button class="btn btn-outline-light" type="button" id="attachGroupFile" aria-label="傳送檔案">📎</button><label class="visually-hidden" for="groupInput">訊息</label><textarea class="form-control" id="groupInput" rows="1" maxlength="2000" placeholder="輸入訊息，支援 @提及" required></textarea><button class="btn btn-primary" type="submit" id="groupSend">傳送</button></div>
      </form>
    </section>
    <div id="groupEmpty" class="group-empty">選擇群組，或建立第一個群組。</div>
  </section>
</main>

<dialog id="createDialog" class="group-dialog"><form id="createGroupForm"><h2>建立群組</h2><label for="newGroupName">群組名稱</label><input id="newGroupName" class="form-control" maxlength="80" required><fieldset class="mt-3"><legend class="fs-6">加入成員</legend><div id="newGroupMembers" class="group-checklist"></div></fieldset><p class="text-secondary small">新成員可查看歷史紀錄；退出或移除後立即停止存取。</p><div class="group-dialog-actions"><button type="button" class="btn btn-secondary" data-close>取消</button><button type="submit" class="btn btn-primary">建立群組</button></div></form></dialog>

<dialog id="toolsDialog" class="group-dialog"><h2>群組管理</h2><label for="groupNotifyMode">此群組通知</label><select class="form-select mb-3" id="groupNotifyMode"><option value="all">全部訊息</option><option value="mentions">只接收 @提及</option><option value="mute">靜音（不產生新通知）</option></select><button type="button" class="btn btn-outline-light mb-3" id="saveNotifyMode">儲存通知設定</button><div id="adminSettings" hidden><form id="groupSettingsForm"><label for="editGroupName">群組名稱</label><input id="editGroupName" class="form-control" maxlength="80" required><label for="editAnnouncement" class="mt-2">群組公告</label><textarea id="editAnnouncement" class="form-control" maxlength="2000" rows="3"></textarea><button class="btn btn-primary my-2" type="submit">儲存名稱與公告</button></form><label for="addGroupMember">新增成員</label><div class="d-flex gap-2 mb-3"><select id="addGroupMember" class="form-select"></select><button type="button" class="btn btn-primary" id="addMemberButton">加入</button></div></div><h3 class="fs-6">群組成員</h3><div id="groupMembersList"></div><div class="group-dialog-actions"><button class="btn btn-outline-danger" type="button" id="leaveGroup">退出群組</button><button type="button" class="btn btn-secondary" data-close>關閉</button></div></dialog>

<dialog id="fileDialog" class="group-dialog"><form id="groupFileForm"><h2>傳送檔案</h2><label for="fileSource">檔案來源</label><select class="form-select mb-2" id="fileSource"><option value="upload">上傳新檔案</option><option value="cloud">從我的雲端選擇</option></select><label for="groupUpload" id="uploadLabel">選擇檔案（最大 100MB）</label><input type="file" class="form-control" id="groupUpload"><label for="groupCloudFile" id="cloudLabel" hidden>我的雲端檔案</label><select id="groupCloudFile" class="form-select" hidden></select><div id="groupUploadStatus" class="small my-2" role="status"></div><div class="group-dialog-actions"><button type="button" class="btn btn-secondary" data-close>取消</button><button type="submit" class="btn btn-primary">傳送</button></div></form></dialog>

<dialog id="filesDialog" class="group-dialog"><h2>群組檔案</h2><label for="groupFileSearch" class="visually-hidden">搜尋檔案</label><input id="groupFileSearch" type="search" class="form-control" placeholder="搜尋檔案名稱"><div id="groupFilesList"></div><div class="group-dialog-actions"><button class="btn btn-secondary" type="button" data-close>關閉</button></div></dialog>

<dialog id="messageDialog" class="group-dialog"><h2>訊息操作</h2><p id="messageSummary" class="group-message-summary"></p><div id="messageActionButtons" class="group-action-buttons"></div><div class="group-dialog-actions"><button class="btn btn-secondary" type="button" data-close>關閉</button></div></dialog>
<dialog id="editDialog" class="group-dialog"><form id="editMessageForm"><h2>編輯訊息</h2><label for="editMessageText" class="visually-hidden">訊息內容</label><textarea id="editMessageText" class="form-control" rows="4" maxlength="2000" required></textarea><div class="group-dialog-actions"><button class="btn btn-secondary" type="button" data-close>取消</button><button class="btn btn-primary" type="submit">儲存</button></div></form></dialog>
<dialog id="readDialog" class="group-dialog"><h2>已讀成員</h2><div id="readMembers"></div><div class="group-dialog-actions"><button type="button" class="btn btn-secondary" data-close>關閉</button></div></dialog>

<script>window.groupBoot = <?= json_encode(['userId' => (int) $_SESSION['user_id'], 'groupId' => max(0, (int) ($_GET['group'] ?? 0)), 'csrf' => $_SESSION['chat_csrf'], 'create' => isset($_GET['create'])], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/navbar_notifications.js?v=20261021-1"></script>
<script src="assets/js/group_chat.js?v=20261021-1"></script>
<script src="assets/js/presence.js?v=20260928-2"></script>
<script src="assets/js/message_notifications.js?v=20261021-1"></script>
</body>
</html>
