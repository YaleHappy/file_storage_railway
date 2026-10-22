<?php
session_start();
require_once __DIR__ . '/config.php';
require __DIR__ . '/add_notification.php'; // 引入通知功能

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}

if (!isset($_POST['recipient_id']) || !isset($_POST['message'])) {
    echo json_encode(['success' => false, 'msg' => '缺少參數']);
    exit;
}

$recipient_id = (int) $_POST['recipient_id'];
$message = trim($_POST['message']);
if ($message === '' || !mb_check_encoding($message, 'UTF-8') || mb_strlen($message, 'UTF-8') > 2000) {
    echo json_encode(['success' => false, 'msg' => '訊息必須為 1 到 2000 個字']);
    exit;
}

if ($recipient_id <= 0 || $recipient_id === (int) $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'msg' => '無效的聊天對象']);
    exit;
}

$recipientStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id = ?");
$recipientStmt->execute([$recipient_id]);
if ((int) $recipientStmt->fetchColumn() === 0) {
    echo json_encode(['success' => false, 'msg' => '找不到聊天對象']);
    exit;
}

require_once __DIR__ . '/chat_features.php';
ensureChatFeatures($pdo);
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, recipient_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $recipient_id, $message]);
    $messageId = (int)$pdo->lastInsertId();
    if (!empty($_POST['reply_id'])) {
        setPrivateReply($pdo, (int)$_SESSION['user_id'], $recipient_id, $messageId, (string)($_POST['reply_type'] ?? 'message'), (int)$_POST['reply_id']);
    }
    addNotification($_SESSION['user_id'], $recipient_id, 'message', $_SESSION['username'] . " 傳送了一則訊息給你");
    $pdo->commit();
    echo json_encode(['success' => true, 'message_id' => $messageId]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($error instanceof OutOfBoundsException ? 404 : 400);
    echo json_encode(['success' => false, 'msg' => '傳送失敗，請確認回覆的訊息仍存在']);
}
