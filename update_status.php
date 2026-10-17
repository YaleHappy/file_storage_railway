<?php
session_start();
require 'config.php';
require_once 'presence_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => '請先登入']);
    exit;
}

$statusToMode = [
    'online' => 'auto',
    'idle' => 'idle',
    'away' => 'dnd',
    'hidden' => 'invisible',
];
$requestedStatus = $_POST['status'] ?? '';
if (!isset($statusToMode[$requestedStatus])) {
    echo json_encode(['success' => false, 'error' => '無效的狀態']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$mode = $statusToMode[$requestedStatus];
$effectiveStatus = match ($mode) {
    'idle' => 'idle',
    'dnd' => 'away',
    'invisible' => 'hidden',
    default => 'online',
};

try {
    $stmt = $pdo->prepare("
        UPDATE users
        SET status = ?, status_mode = ?, last_active = NOW(), last_interaction = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$effectiveStatus, $mode, $userId]);
    $_SESSION['status'] = $effectiveStatus;

    echo json_encode(array_merge(
        ['success' => true, 'message' => '狀態更新成功'],
        presencePayload($effectiveStatus, $mode)
    ));
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}
