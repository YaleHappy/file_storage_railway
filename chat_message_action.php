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
require 'config.php';
require_once __DIR__ . '/chat_message_service.php';
try {
    $id = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $message = $_POST['message'] ?? '';
    if (!$id || !is_string($action) || !is_string($message)) {
        throw new InvalidArgumentException('無效的訊息操作');
    }
    changeOwnChatMessage($pdo, (int) $_SESSION['user_id'], $id, $action, $message);
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('訊息操作失敗：' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'msg' => '操作失敗，請稍後再試']);
}
