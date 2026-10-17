<?php
session_start();
require 'config.php';
require_once 'presence_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    refreshPresenceStatuses($pdo);
    echo json_encode([
        'success' => true,
        'idle_after_seconds' => PRESENCE_IDLE_SECONDS,
        'offline_after_seconds' => PRESENCE_OFFLINE_SECONDS,
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}
