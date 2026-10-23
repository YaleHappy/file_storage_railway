<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
require_once __DIR__ . '/file_cleanup.php';
cleanupMissingSharedFiles($pdo, $currentUserId);
$_SESSION['chat_csrf'] ??= bin2hex(random_bytes(32));
$groupMode = isset($_GET['group']) || isset($_GET['create']) || isset($_GET['groups']);
$selectedGroupId = max(0, (int) ($_GET['group'] ?? 0));
require_once __DIR__ . '/group_service.php';
ensureGroupSchema($pdo);
$chatGroups = groupList($pdo, $currentUserId);
$conversationStmt = $pdo->prepare("
    SELECT
        u.user_id,
        u.username,
        u.status,
        SUM(activity.event_type = 'message') AS message_count,
        SUM(activity.event_type = 'file') AS file_count,
        MAX(activity.event_at) AS last_event_at
    FROM (
        SELECT sender_id, recipient_id, sent_at AS event_at, 'message' AS event_type
        FROM chat_messages
        UNION ALL
        SELECT sender_id, recipient_id, sent_at AS event_at, 'file' AS event_type
        FROM shared_files
    ) activity
    INNER JOIN users u
        ON u.user_id = CASE
            WHEN activity.sender_id = ? THEN activity.recipient_id
            ELSE activity.sender_id
        END
    WHERE activity.sender_id = ? OR activity.recipient_id = ?
    GROUP BY u.user_id, u.username, u.status
    ORDER BY last_event_at DESC
");
$conversationStmt->execute([$currentUserId, $currentUserId, $currentUserId]);
$conversations = $conversationStmt->fetchAll(PDO::FETCH_ASSOC);

$lastEventStmt = $pdo->prepare("
    SELECT event_type, content
    FROM (
        SELECT 'message' AS event_type, message AS content, sent_at AS event_at, id AS event_id
        FROM chat_messages
        WHERE (sender_id = ? AND recipient_id = ?)
           OR (sender_id = ? AND recipient_id = ?)
        UNION ALL
        SELECT 'file' AS event_type, original_filename AS content, sent_at AS event_at, id AS event_id
        FROM shared_files
        WHERE (sender_id = ? AND recipient_id = ?)
           OR (sender_id = ? AND recipient_id = ?)
    ) latest_activity
    ORDER BY event_at DESC, event_id DESC
    LIMIT 1
");
foreach ($conversations as &$conversation) {
    $partnerId = (int) $conversation['user_id'];
    $lastEventStmt->execute([
        $currentUserId, $partnerId, $partnerId, $currentUserId,
        $currentUserId, $partnerId, $partnerId, $currentUserId,
    ]);
    $lastEvent = $lastEventStmt->fetch(PDO::FETCH_ASSOC);
    $conversation['last_event'] = $lastEvent && $lastEvent['event_type'] === 'file'
        ? '📎 檔案：' . $lastEvent['content']
        : (string) ($lastEvent['content'] ?? '');
}
unset($conversation);

$conversationIds = array_map('intval', array_column($conversations, 'user_id'));
$requestedUserId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
$selectedUserId = $requestedUserId ?: ($conversationIds[0] ?? 0);
$isNewConversation = false;

// 從上線使用者清單開啟尚未聊過的對象時，也要建立可輸入訊息的新對話畫面。
if ($selectedUserId > 0 && $selectedUserId !== $currentUserId && !in_array($selectedUserId, $conversationIds, true)) {
    $newConversationStmt = $pdo->prepare("
        SELECT user_id, username, status
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $newConversationStmt->execute([$selectedUserId]);
    $newConversation = $newConversationStmt->fetch(PDO::FETCH_ASSOC);

    if ($newConversation) {
        $newConversation['message_count'] = 0;
        $newConversation['file_count'] = 0;
        $newConversation['last_event_at'] = null;
        $newConversation['last_event'] = '開始新對話';
        array_unshift($conversations, $newConversation);
        $conversationIds[] = $selectedUserId;
        $isNewConversation = true;
    } else {
        $selectedUserId = $conversationIds[0] ?? 0;
    }
} elseif ($selectedUserId === $currentUserId) {
    $selectedUserId = $conversationIds[0] ?? 0;
}

$selectedConversation = null;
foreach ($conversations as $conversation) {
    if ((int) $conversation['user_id'] === $selectedUserId) {
        $selectedConversation = $conversation;
        break;
    }
}

$timelineEvents = [];
if ($selectedConversation) {
    $timelineStmt = $pdo->prepare("
        SELECT event_type, event_id, sender_id, recipient_id, content,
               original_filename, file_path, event_at
        FROM (
            SELECT 'message' AS event_type, id AS event_id, sender_id, recipient_id,
                   message AS content, NULL AS original_filename, NULL AS file_path,
                   sent_at AS event_at
            FROM chat_messages
            WHERE (sender_id = ? AND recipient_id = ?)
               OR (sender_id = ? AND recipient_id = ?)
            UNION ALL
            SELECT 'file' AS event_type, id AS event_id, sender_id, recipient_id,
                   NULL AS content, original_filename, file_path, sent_at AS event_at
            FROM shared_files
            WHERE (sender_id = ? AND recipient_id = ?)
               OR (sender_id = ? AND recipient_id = ?)
        ) timeline
        ORDER BY event_at ASC, event_id ASC
    ");
    $timelineStmt->execute([
        $currentUserId, $selectedUserId, $selectedUserId, $currentUserId,
        $currentUserId, $selectedUserId, $selectedUserId, $currentUserId,
    ]);
    $timelineEvents = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);
}

$statusTexts = [
    'online' => '🟢 在線',
    'idle' => '🌙 閒置',
    'away' => '🔴 請勿打擾',
    'hidden' => '⚪ 隱形',
    'offline' => '⚪ 離線',
];
$currentStatus = $_SESSION['status'] ?? 'online';

function formatChatTime(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return $dateTime;
    }

    return date('Y/m/d H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">
  <title>雲上聊頁來｜聊天紀錄</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    html, body {
      min-height: 100%;
      background: #1a1a2e;
      color: #fff;
    }
    .history-page {
      padding-top: 5rem;
      padding-bottom: 1.5rem;
    }
    .history-heading {
      margin-bottom: 1rem;
    }
    .history-heading h1 {
      font-size: clamp(1.5rem, 2.2vw, 2rem);
      margin-bottom: .25rem;
    }
    .history-heading p {
      color: #adb5bd;
      margin: 0;
    }
    .history-shell {
      display: grid;
      grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
      min-height: calc(100vh - 9rem);
      border: 2px solid #50557f;
      border-radius: 8px;
      overflow: hidden;
      background: #20223f;
      box-shadow: 0 12px 32px rgba(0, 0, 0, .22);
    }
    .conversation-panel {
      min-width: 0;
      border-right: 1px solid #3f4266;
      background: #25274d;
    }
    .conversation-toolbar {
      padding: 1rem;
      border-bottom: 2px solid #3f4266;
    }
    .conversation-toolbar h2 {
      font-size: 1rem;
      margin-bottom: .75rem;
    }
    .conversation-list {
      max-height: calc(100vh - 15.5rem);
      overflow-y: auto;
      padding: .65rem;
    }
    .conversation-item {
      display: block;
      padding: .9rem 1rem;
      margin-bottom: .6rem;
      color: #fff;
      text-decoration: none;
      border: 2px solid #454a76;
      border-radius: 6px;
      background: #292c52;
      transition: background-color .15s ease, border-color .15s ease, transform .15s ease;
    }
    .conversation-item:last-child {
      margin-bottom: 0;
    }
    .conversation-item:hover,
    .conversation-item:focus-visible {
      color: #fff;
      background: #343768;
      border-color: #747bb4;
    }
    .conversation-item.active {
      color: #fff;
      background: #343768;
      border-color: #4dabf7;
      box-shadow: inset 4px 0 0 #4dabf7;
    }
    .conversation-name-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      margin-bottom: .3rem;
    }
    .conversation-name {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-weight: 600;
    }
    .conversation-time {
      flex: 0 0 auto;
      color: #adb5bd;
      font-size: .75rem;
    }
    .conversation-preview {
      color: #c4c7d9;
      font-size: .875rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .status-dot {
      display: inline-block;
      width: .55rem;
      height: .55rem;
      margin-right: .35rem;
      border-radius: 50%;
      background: #6c757d;
    }
    .status-dot.online { background: #34c759; }
    .status-dot.idle { background: #ffc107; }
    .status-dot.away { background: #fd7e14; }
    .chat-panel {
      min-width: 0;
      display: flex;
      flex-direction: column;
      background: #17182d;
    }
    .chat-header {
      min-height: 72px;
      padding: 1rem 1.25rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      border-bottom: 2px solid #3f4266;
      background: #25274d;
    }
    .chat-header h2 {
      font-size: 1.1rem;
      margin: 0;
    }
    .chat-header small {
      color: #adb5bd;
    }
    .chat-messages {
      flex: 1;
      min-height: 360px;
      max-height: calc(100vh - 17.5rem);
      overflow-y: auto;
      padding: 1.25rem;
    }
    .new-conversation-hint {
      min-height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: .45rem;
      padding: 1.5rem;
      color: #adb5bd;
      text-align: center;
    }
    .new-conversation-hint i {
      color: #69c0ff;
      font-size: 2rem;
    }
    .new-conversation-hint strong {
      color: #fff;
      font-size: 1rem;
    }
    .new-conversation-hint span {
      font-size: .88rem;
    }
    .message-row {
      display: flex;
      margin-bottom: .85rem;
    }
    .message-row.sent { justify-content: flex-end; }
    .message-bubble {
      max-width: min(72%, 640px);
      padding: .65rem .8rem .45rem;
      border-radius: 8px;
      background: #343768;
      overflow-wrap: anywhere;
    }
    .message-row.sent .message-bubble {
      background: #0d6efd;
    }
    .file-bubble {
      min-width: min(280px, 100%);
      background: #3a3d67;
      border: 1px solid rgba(255, 255, 255, .14);
    }
    .message-row.sent .file-bubble {
      background: #1858b8;
    }
    .file-event-title {
      display: flex;
      align-items: center;
      gap: .65rem;
      font-weight: 600;
    }
    .file-event-title i {
      flex: 0 0 auto;
      font-size: 1.35rem;
    }
    .file-event-name {
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .file-event-note {
      margin-top: .35rem;
      color: rgba(255, 255, 255, .76);
      font-size: .8rem;
    }
    .file-event-actions {
      margin-top: .55rem;
    }
    .message-text {
      white-space: pre-wrap;
    }
    .message-time {
      display: block;
      margin-top: .25rem;
      color: rgba(255, 255, 255, .72);
      font-size: .72rem;
      text-align: right;
    }
    .chat-composer {
      display: flex;
      gap: .75rem;
      padding: 1rem 1.25rem;
      border-top: 2px solid #3f4266;
      background: #25274d;
    }
    .chat-composer .form-control,
    .conversation-toolbar .form-control {
      border-color: #565a87;
      background: #17182d;
      color: #fff;
    }
    .chat-composer .form-control::placeholder,
    .conversation-toolbar .form-control::placeholder {
      color: #9296ae;
    }
    .empty-state {
      min-height: 360px;
      display: grid;
      place-items: center;
      padding: 2rem;
      color: #adb5bd;
      text-align: center;
    }
    .empty-state i {
      display: block;
      margin-bottom: .75rem;
      font-size: 2rem;
    }
    .conversation-empty {
      padding: 2rem 1rem;
      color: #adb5bd;
      text-align: center;
    }
    .notification-badge {
      top: 0 !important;
      right: -5px !important;
      font-size: .7rem;
    }
    .notification-dropdown {
      width: 320px;
      max-height: 450px;
      overflow-y: auto;
      border: 0;
      background: #25274d;
      color: #fff;
      box-shadow: 0 5px 15px rgba(0, 0, 0, .3);
    }
    .notification-item {
      cursor: pointer;
    }
    .notification-item:hover {
      background: rgba(255, 255, 255, .08);
    }
    @media (min-width: 768px) and (max-width: 991.98px) {
      .history-page {
        padding-top: 1rem;
      }
    }
    @media (max-width: 767.98px) {
      .history-page {
        padding-top: .75rem;
        padding-right: .75rem;
        padding-left: .75rem;
      }
      .history-heading {
        margin-bottom: .75rem;
      }
      .history-heading p {
        font-size: .9rem;
      }
      .history-shell {
        grid-template-columns: 1fr;
        gap: .75rem;
        min-height: auto;
        border: 0;
        border-radius: 0;
        overflow: visible;
        background: transparent;
        box-shadow: none;
      }
      .conversation-panel {
        border: 2px solid #50557f;
        border-radius: 8px;
        overflow: hidden;
      }
      .conversation-list {
        display: flex;
        gap: .65rem;
        max-height: none;
        padding: .65rem;
        overflow-x: auto;
        overflow-y: hidden;
        scroll-snap-type: x proximity;
        scrollbar-width: none;
      }
      .conversation-list::-webkit-scrollbar {
        display: none;
      }
      .conversation-item {
        flex: 0 0 min(78vw, 300px);
        margin: 0;
        scroll-snap-align: start;
      }
      .conversation-item.active {
        box-shadow: inset 0 4px 0 #4dabf7;
      }
      .chat-panel {
        border: 2px solid #50557f;
        border-radius: 8px;
        overflow: hidden;
      }
      .chat-header {
        min-height: 64px;
        padding: .8rem 1rem;
      }
      .chat-messages {
        min-height: 48vh;
        max-height: 58vh;
        padding: 1rem;
      }
      .message-bubble {
        max-width: 88%;
      }
      .chat-composer {
        padding: .75rem;
      }
    }
  </style>
  <link href="assets/css/responsive.css?v=20261022-2" rel="stylesheet">
  <link href="assets/css/chat_interactions.css?v=20261022-4" rel="stylesheet">
  <?php if ($groupMode): ?><link href="assets/css/group_chat.css?v=20261022-6" rel="stylesheet"><?php endif; ?>
  <link href="assets/css/chat_typography.css?v=20261022-2" rel="stylesheet">
<link rel="stylesheet" href="assets/css/ui_feedback.css?v=20261024-1"><script src="assets/js/ui_feedback.js?v=20261024-1"></script></head>
<body class="has-fixed-navbar<?= $groupMode ? ' group-page' : '' ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">☁️ 雲上聊頁來</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#historyNavbar" aria-controls="historyNavbar" aria-expanded="false" aria-label="切換導覽選單">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="historyNavbar">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="my_shares.php">🔗 我的分享</a></li>
        <li class="nav-item"><a class="nav-link" href="received_files.php">📥 收到的檔案</a></li>
        <li class="nav-item"><a class="nav-link active" href="chat_history.php" aria-current="page"><i class="bi bi-clock-history me-1"></i>聊天紀錄</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            👤 <?= htmlspecialchars($_SESSION['username']) ?> (<span id="currentStatus"><?= htmlspecialchars($statusTexts[$currentStatus] ?? '🟢 在線') ?></span>)
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li><a class="dropdown-item status-option" data-status="online" href="#">🟢 在線（自動）</a></li>
            <li><a class="dropdown-item status-option" data-status="idle" href="#">🌙 閒置</a></li>
            <li><a class="dropdown-item status-option" data-status="away" href="#">🔴 請勿打擾</a></li>
            <li><a class="dropdown-item status-option" data-status="hidden" href="#">⚪ 隱形</a></li>
          </ul>
        </li>
        <?php if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
          <li class="nav-item"><a class="nav-link" href="admin.php">🛠️ 管理後台</a></li>
        <?php endif; ?>
        <li class="nav-item navbar-session-actions"><a class="nav-link" href="logout.php">登出</a>
        <?php require __DIR__ . '/navbar_notifications.php'; ?>
      </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container history-page">
  <?php if ($groupMode): ?><div id="groupError" class="alert alert-danger" role="alert" hidden></div><?php endif; ?>
  <header class="history-heading">
    <h1><i class="bi bi-chat-left-text me-2"></i>聊天紀錄</h1>
    <p>查看過往對話，或接著傳送訊息。</p>
    <div class="d-flex gap-2 mt-2"><a id="createGroup" class="btn btn-primary btn-sm" href="chat_history.php?create=1">＋ 建立群組</a></div>
  </header>

  <section id="groupShell" class="history-shell<?= ($groupMode ? $selectedGroupId > 0 : $requestedUserId > 0 && $selectedConversation) ? ' conversation-open' : '' ?>" aria-label="聊天紀錄">
    <aside class="conversation-panel">
      <div class="conversation-toolbar">
        <h2>最近對話</h2>
        <label class="visually-hidden" for="conversationSearch">搜尋對話</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-dark text-light border-secondary"><i class="bi bi-search"></i></span>
          <input id="conversationSearch" class="form-control" type="search" placeholder="搜尋使用者" autocomplete="off">
        </div>
      </div>
      <div class="conversation-list" id="conversationList">
        <div id="historyGroupList">
        <?php foreach ($chatGroups as $chatGroup): ?>
          <a class="conversation-item<?= (int) $chatGroup['id'] === $selectedGroupId ? ' active' : '' ?>" href="chat_history.php?group=<?= (int) $chatGroup['id'] ?>" data-username="<?= htmlspecialchars(mb_strtolower($chatGroup['name'], 'UTF-8')) ?>">
            <div class="conversation-name-row"><span class="conversation-name">👥 <?= htmlspecialchars($chatGroup['name']) ?></span>
              <?php if ($chatGroup['unread_count']): ?><span class="badge bg-danger"><?= (int) $chatGroup['unread_count'] ?></span><?php endif; ?>
            </div>
            <div class="conversation-preview"><?= htmlspecialchars($chatGroup['last_content'] ?: '開始群組對話') ?></div>
            <small><?= (int) $chatGroup['member_count'] ?> 位成員<?= $chatGroup['notification_mode'] === 'mute' ? ' · 已靜音' : '' ?></small>
          </a>
        <?php endforeach; ?>
        </div>
        <?php if (!$conversations && !$chatGroups): ?>
          <div class="conversation-empty">
            <i class="bi bi-chat-square-dots d-block fs-3 mb-2"></i>
            尚無聊天紀錄
          </div>
        <?php else: ?>
          <?php foreach ($conversations as $conversation): ?>
            <?php $isActive = !$groupMode && (int) $conversation['user_id'] === $selectedUserId; ?>
            <a class="conversation-item<?= $isActive ? ' active' : '' ?>" href="chat_history.php?user=<?= (int) $conversation['user_id'] ?>" data-username="<?= htmlspecialchars(mb_strtolower($conversation['username'], 'UTF-8')) ?>">
              <div class="conversation-name-row">
                <span class="conversation-name"><span class="status-dot <?= htmlspecialchars($conversation['status']) ?>" data-presence-user="<?= (int) $conversation['user_id'] ?>" aria-label="使用者狀態"></span><?= htmlspecialchars($conversation['username']) ?></span>
                <time class="conversation-time"><?= htmlspecialchars(formatChatTime($conversation['last_event_at'])) ?></time>
              </div>
              <div class="conversation-preview"><?= htmlspecialchars($conversation['last_event']) ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>

    <?php if ($groupMode): ?>
      <?php require __DIR__ . '/partials/group_room.php'; ?>
      <div id="groupEmpty" class="group-empty">選擇群組，或按「建立群組」開始。</div>
    <?php else: ?>
    <div class="chat-panel">
      <?php if ($selectedConversation): ?>
        <div class="chat-header">
          <a class="chat-back btn btn-sm btn-outline-light" href="chat_history.php" aria-label="返回對話列表"><i class="bi bi-arrow-left"></i></a>
          <div>
            <h2><?= htmlspecialchars($selectedConversation['username']) ?></h2>
            <small><?= (int) $selectedConversation['message_count'] ?> 則訊息 · <?= (int) $selectedConversation['file_count'] ?> 個檔案</small>
          </div>
          <button class="chat-search-trigger" type="button" data-chat-search aria-label="搜尋聊天紀錄"><span>搜尋聊天紀錄</span><i class="bi bi-search" aria-hidden="true"></i></button>
        </div>
        <div id="privatePins" class="private-pins" hidden></div>
        <div class="chat-messages" id="chatMessages" aria-live="polite">
          <?php if (!$timelineEvents): ?>
            <div class="new-conversation-hint" id="newConversationHint">
              <i class="bi bi-chat-heart"></i>
              <strong>開始和 <?= htmlspecialchars($selectedConversation['username']) ?> 聊天</strong>
              <span>輸入第一則訊息，對話紀錄就會保存在這裡。</span>
            </div>
          <?php endif; ?>
          <?php foreach ($timelineEvents as $event): ?>
            <?php $isSent = (int) $event['sender_id'] === $currentUserId; ?>
            <div class="message-row <?= $isSent ? 'sent' : 'received' ?>">
              <div class="message-bubble<?= $event['event_type'] === 'file' ? ' file-bubble' : '' ?>"<?php if ($isSent): ?> data-message-id="<?= (int) $event['event_id'] ?>" data-event-type="<?= htmlspecialchars($event['event_type']) ?>" tabindex="0" aria-label="<?= $event['event_type'] === 'file' ? '你傳送的檔案，右鍵或長按可收回' : '你傳送的訊息，右鍵或長按可編輯或收回' ?>"<?php endif; ?>>
                <?php if ($event['event_type'] === 'file'): ?>
                  <div class="file-event-title">
                    <i class="bi bi-file-earmark-arrow-down"></i>
                    <span class="file-event-name"><?= htmlspecialchars($event['original_filename'] ?: '未命名檔案') ?></span>
                  </div>
                  <div class="file-event-note"><?= $isSent ? '你傳送了這個檔案' : '對方傳送了這個檔案給你' ?></div>
                    <div class="file-event-actions">
                      <a class="btn btn-sm btn-light" href="download_shared_file.php?id=<?= (int) $event['event_id'] ?>"><i class="bi bi-download me-1"></i>下載</a>
                      <button class="btn btn-sm btn-outline-light" type="button" data-save-cloud="<?= (int)$event['event_id'] ?>"><i class="bi bi-cloud-plus me-1" aria-hidden="true"></i>存到雲端</button>
                    </div>
                <?php else: ?>
                  <div class="message-text"><?= htmlspecialchars($event['content']) ?></div>
                <?php endif; ?>
                <time class="message-time"><?= htmlspecialchars(formatChatTime($event['event_at'])) ?></time>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div id="privateReplyPreview" class="private-reply-preview" hidden><span id="privateReplyText"></span><button type="button" class="btn btn-sm btn-outline-light" id="cancelPrivateReply">取消回覆</button></div>
        <form class="chat-composer" id="chatForm">
          <button class="btn btn-outline-light flex-shrink-0" type="button" id="attachPrivateFile" aria-label="傳送檔案">📎</button>
          <label class="visually-hidden" for="chatInput">輸入訊息</label>
          <input class="form-control" id="chatInput" type="text" maxlength="2000" placeholder="輸入訊息..." autocomplete="off" required>
          <button class="btn btn-primary flex-shrink-0" type="submit" id="sendButton"><i class="bi bi-send me-1"></i>傳送</button>
        </form>
      <?php else: ?>
        <div class="empty-state">
          <div>
            <i class="bi bi-chat-left-dots"></i>
            <div>尚無聊天紀錄</div>
            <small>從首頁的上線使用者清單開始聊天後，紀錄會顯示在這裡。</small>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
</main>
<?php if ($groupMode): ?>
<?php require __DIR__ . '/partials/group_dialogs.php'; ?>
<script>window.groupBoot = <?= json_encode(['userId' => $currentUserId, 'groupId' => $selectedGroupId, 'csrf' => $_SESSION['chat_csrf'], 'create' => isset($_GET['create'])], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/chat_message_actions.js?v=20261024-3"></script>
<script>
const currentUserId = <?= $currentUserId ?>;
const selectedUserId = <?= $selectedUserId ?>;
const isNewConversation = <?= $isNewConversation ? 'true' : 'false' ?>;
const chatMessages = document.getElementById('chatMessages');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');
const sendButton = document.getElementById('sendButton');
const messageActions = chatMessages ? window.installChatMessageActions({
  container: chatMessages,
  csrfToken: <?= json_encode($_SESSION['chat_csrf']) ?>,
  onChange: () => refreshTimeline(true),
  onReply: target => { privateReply = target; document.getElementById('privateReplyPreview').hidden = false; document.getElementById('privateReplyText').textContent = '回覆：' + target.text; chatInput.focus(); }
}) : null;
const privateCloudSaved = new Set(), privateCloudPending = new Set();
chatMessages?.addEventListener('click', async event => {
  const button = event.target.closest('[data-save-cloud]'); if (!button) return;
  event.preventDefault(); event.stopPropagation();
  const id = Number(button.dataset.saveCloud);
  if (privateCloudPending.has(id) || privateCloudSaved.has(id)) return;
  privateCloudPending.add(id); button.disabled = true;
  try {
    const response = await fetch('add_to_my_cloud.php?id=' + id, {cache:'no-store'}), data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.msg || '儲存失敗');
    privateCloudSaved.add(id); button.textContent = '已存到雲端';
    chatMessages.querySelectorAll('[data-save-cloud="'+id+'"]').forEach(node=>{node.disabled=true;node.textContent='已存到雲端';});
  } catch (error) { button.disabled = false; storageNotice(error.message || '儲存失敗'); }
  finally { privateCloudPending.delete(id); }
});
let timelineSignature = null;
let privateReply = null;
document.getElementById('cancelPrivateReply')?.addEventListener('click', () => { privateReply = null; document.getElementById('privateReplyPreview').hidden = true; });

function formatMessageTime(value) {
  if (!value) return '';
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/);
  return match ? `${match[1]}/${match[2]}/${match[3]} ${match[4]}:${match[5]}` : value;
}

function renderTimeline(events, keepScrollPosition = false) {
  if (!chatMessages) return;
  const signature = JSON.stringify(events);
  if (signature === timelineSignature) return;
  timelineSignature = signature;
  const previousScrollTop = chatMessages.scrollTop;
  const wasNearBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 80;
  chatMessages.replaceChildren();

  if (events.length === 0 && isNewConversation) {
    const hint = document.createElement('div');
    hint.className = 'new-conversation-hint';
    hint.innerHTML = '<i class="bi bi-chat-heart"></i><strong>開始新的聊天</strong><span>輸入第一則訊息，對話紀錄就會保存在這裡。</span>';
    chatMessages.appendChild(hint);
  }

  events.forEach(event => {
    const isSent = Number(event.sender_id) === currentUserId;
    const row = document.createElement('div');
    row.className = `message-row ${isSent ? 'sent' : 'received'}`;

    const bubble = document.createElement('div');
    bubble.className = `message-bubble${event.event_type === 'file' ? ' file-bubble' : ''}`;
    {
      bubble.dataset.sent = String(isSent);
      bubble.dataset.pinned = String(event.pinned || 0);
      bubble.dataset.messageId = Number(event.event_id);
      bubble.dataset.eventType = event.event_type;
      bubble.tabIndex = 0;
      bubble.setAttribute('aria-label', (isSent ? '你傳送的' : '收到的') + (event.event_type === 'file' ? '檔案' : '訊息') + '，右鍵或長按可操作');
    }

    if (event.reply) {
      const quote = document.createElement('div'); quote.className = 'private-quote'; quote.textContent = '回覆：' + event.reply.content; bubble.append(quote);
    }
    if (event.event_type === 'file') {
      const title = document.createElement('div');
      title.className = 'file-event-title';
      const icon = document.createElement('i');
      icon.className = 'bi bi-file-earmark-arrow-down';
      const filename = document.createElement('span');
      filename.className = 'file-event-name';
      filename.textContent = event.original_filename || '未命名檔案';
      title.append(icon, filename);

      const note = document.createElement('div');
      note.className = 'file-event-note';
      note.textContent = isSent ? '你傳送了這個檔案' : '對方傳送了這個檔案給你';
      bubble.append(title, note);

      {
        const actions = document.createElement('div');
        actions.className = 'file-event-actions';
        const download = document.createElement('a');
        download.className = 'btn btn-sm btn-light';
        download.href = `download_shared_file.php?id=${Number(event.event_id)}`;
        download.innerHTML = '<i class="bi bi-download me-1"></i>下載';
        const save = document.createElement('button'); save.type = 'button'; save.className = 'btn btn-sm btn-outline-light';
        save.dataset.saveCloud = Number(event.event_id);
        save.textContent = privateCloudSaved.has(Number(event.event_id)) ? '已存到雲端' : '存到雲端';
        save.disabled = privateCloudSaved.has(Number(event.event_id)) || privateCloudPending.has(Number(event.event_id));
        actions.append(download, save);
        bubble.appendChild(actions);
      }
    } else {
      const text = document.createElement('div');
      text.className = 'message-text';
      text.textContent = event.content;
      bubble.appendChild(text);
    }

    const time = document.createElement('time');
    time.className = 'message-time';
    time.textContent = formatMessageTime(event.event_at);

    bubble.appendChild(time);
    row.appendChild(bubble);
    messageActions?.decorate(row, bubble, event, currentUserId);
    chatMessages.appendChild(row);
  });

  const pins = document.getElementById('privatePins');
  if (pins) {
    pins.replaceChildren(...events.filter(item => Number(item.pinned)).map(item => {
      const link = document.createElement('button'); link.type = 'button'; link.textContent = '📌 ' + (item.content || item.original_filename);
      link.addEventListener('click', () => { const target = [...chatMessages.querySelectorAll('[data-message-id]')].find(node => Number(node.dataset.messageId) === Number(item.event_id) && node.dataset.eventType === item.event_type); target?.scrollIntoView({block:'center'}); });
      return link;
    }));
    pins.hidden = !pins.childElementCount;
  }
  if (!keepScrollPosition || wasNearBottom) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else {
    chatMessages.scrollTop = previousScrollTop;
  }
}

async function refreshTimeline(keepScrollPosition = true) {
  if (!selectedUserId || !chatMessages || messageActions?.isBusy()) return;
  const version = messageActions?.version();
  try {
    const response = await fetch(`chat_timeline.php?recipient_id=${selectedUserId}`, { cache: 'no-store' });
    const data = await response.json();
    if (data.success && !messageActions?.isBusy() && version === messageActions?.version()) {
      renderTimeline(data.events, keepScrollPosition);
      const preview = document.querySelector('.conversation-item.active .conversation-preview');
      const latest = data.events[data.events.length - 1];
      if (preview) preview.textContent = latest
        ? (latest.event_type === 'file' ? '📎 檔案：' + latest.original_filename : latest.content)
        : '尚無訊息';
    }
  } catch (error) {
    console.error('無法更新聊天紀錄', error);
  }
}

if (chatMessages) {
  chatMessages.scrollTop = chatMessages.scrollHeight;
  refreshTimeline(true);
  window.setInterval(() => refreshTimeline(true), 5000);
}

document.getElementById('attachPrivateFile')?.addEventListener('click', () => {
  window.dispatchEvent(new CustomEvent('open-user-file', {detail:{user_id:selectedUserId,username:<?= json_encode($selectedConversation['username'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>}}));
});
window.addEventListener('private-file-sent', event => {
  if (Number(event.detail.recipientId) !== selectedUserId || !chatMessages) return;
  if (isNewConversation) { location.reload(); return; }
  refreshTimeline(false);
});

if (chatForm) {
  chatForm.addEventListener('submit', async event => {
    event.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;

    sendButton.disabled = true;
    const body = new URLSearchParams({ recipient_id: selectedUserId, message });
    if (privateReply) { body.set('reply_id', privateReply.id); body.set('reply_type', privateReply.type); }
    try {
      const response = await fetch('chat_send.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.msg || '訊息傳送失敗');
      chatInput.value = ''; privateReply = null; document.getElementById('privateReplyPreview').hidden = true;
      if (isNewConversation) {
        window.location.reload();
        return;
      }
      await refreshTimeline(false);
      chatInput.focus();
    } catch (error) {
      storageNotice(error.message || '訊息傳送失敗，請稍後再試。');
    } finally {
      sendButton.disabled = false;
    }
  });
}

document.getElementById('conversationSearch')?.addEventListener('input', event => {
  const keyword = event.target.value.trim().toLocaleLowerCase('zh-TW');
  document.querySelectorAll('.conversation-item').forEach(item => {
    item.hidden = !item.dataset.username.includes(keyword);
  });
});

document.querySelectorAll('.status-option').forEach(item => {
  item.addEventListener('click', async event => {
    event.preventDefault();
    const status = item.dataset.status;
    const body = new URLSearchParams({ status });
    try {
      const response = await fetch('update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body
      });
      const data = await response.json();
      if (data.success) {
        const statusTextMap = {
          online: '🟢 在線',
          idle: '🌙 閒置',
          away: '🔴 請勿打擾',
          hidden: '⚪ 隱形'
        };
        document.getElementById('currentStatus').textContent = statusTextMap[status] || '⚪ 未知';
        sessionStorage.setItem('user_status', status);
      }
    } catch (error) {
      console.error('無法更新狀態', error);
    }
  });
});


</script>
<script src="assets/js/navbar_notifications.js?v=20261024-3"></script>
<?php if ($groupMode): ?>
<script src="assets/js/group_chat.js?v=20261024-3"></script>
<?php else: ?>
<script src="assets/js/chat_group_list.js?v=20261021-2"></script>
<?php endif; ?>
<script src="assets/js/presence.js?v=20261024-3"></script>
<script src="assets/js/message_notifications.js?v=20261021-5"></script>
<?php require __DIR__ . '/partials/online_users.php'; ?>
<?php if (($groupMode && $selectedGroupId > 0) || (!$groupMode && $selectedConversation)): ?><?php require __DIR__ . '/partials/chat_search.php'; ?><?php endif; ?>
<script src="assets/js/chat_viewport.js?v=20261021-1"></script>
</body>
</html>
