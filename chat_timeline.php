<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
require_once __DIR__ . '/file_cleanup.php';
cleanupMissingSharedFiles($pdo, $currentUserId);
$recipientId = isset($_GET['recipient_id']) ? (int) $_GET['recipient_id'] : 0;
if ($recipientId <= 0 || $recipientId === $currentUserId) {
    echo json_encode(['success' => false, 'msg' => '無效的聊天對象']);
    exit;
}

$stmt = $pdo->prepare("
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
$stmt->execute([
    $currentUserId, $recipientId, $recipientId, $currentUserId,
    $currentUserId, $recipientId, $recipientId, $currentUserId,
]);

require_once __DIR__ . '/chat_features.php';
ensureChatFeatures($pdo);
echo json_encode([
    'success' => true,
    'events' => enrichPrivateChat($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC)),
]);
