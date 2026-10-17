<?php
session_start();
require 'config.php';
require_once 'presence_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => '請先登入']);
    exit;
}

try {
    refreshPresenceStatuses($pdo);
    $currentUserId = (int) $_SESSION['user_id'];

    $stmt = $pdo->query("
        SELECT user_id, username,
               CASE WHEN status = 'hidden' THEN 'offline' ELSE status END AS status,
               last_active
        FROM users
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusStmt = $pdo->prepare("SELECT status, status_mode FROM users WHERE user_id = ?");
    $statusStmt->execute([$currentUserId]);
    $current = $statusStmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['status'] = $current['status'];

    echo json_encode(array_merge([
        'success' => true,
        'users' => $users,
        'current_status' => $current['status'],
    ], presencePayload($current['status'], $current['status_mode'])));
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}
