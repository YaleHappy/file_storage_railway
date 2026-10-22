<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'msg' => '請使用 POST']);
    exit;
}
$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || empty($_SESSION['chat_csrf']) || !hash_equals($_SESSION['chat_csrf'], $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => '頁面已過期，請重新整理']);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/chat_message_service.php';
require_once __DIR__ . '/chat_features.php';
ensureChatFeatures($pdo);
try {
    $id = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $message = $_POST['message'] ?? '';
    $eventType = $_POST['event_type'] ?? 'message';
    if (!$id || !is_string($action) || !is_string($message) || !in_array($eventType, ['message', 'file'], true)) {
        throw new InvalidArgumentException('無效的訊息操作');
    }
    $pdo->beginTransaction();
    if (in_array($action, ['react', 'pin'], true)) {
        privateChatFeatureAction($pdo, (int)$_SESSION['user_id'], $eventType, $id, $action, (string)($_POST['emoji'] ?? ''));
    } elseif ($eventType === 'file') {
        if ($action !== 'recall') {
            throw new InvalidArgumentException('檔案只支援收回，無法編輯');
        }
        recallOwnChatFile($pdo, (int) $_SESSION['user_id'], $id);
    } else {
        changeOwnChatMessage($pdo, (int) $_SESSION['user_id'], $id, $action, $message);
    }
    if ($action === 'recall') {
        $pdo->prepare('DELETE FROM chat_event_details WHERE event_type=? AND event_id=?')->execute([$eventType,$id]);
        $pdo->prepare('DELETE FROM chat_event_reactions WHERE event_type=? AND event_id=?')->execute([$eventType,$id]);
        $pdo->prepare('UPDATE chat_event_details SET reply_type=NULL,reply_id=NULL WHERE reply_type=? AND reply_id=?')->execute([$eventType,$id]);
    }
    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (OutOfBoundsException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('訊息操作失敗：' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'msg' => '操作失敗，請稍後再試']);
}
