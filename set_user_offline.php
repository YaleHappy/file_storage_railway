<?php
session_start();
require 'config.php';

if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("UPDATE users SET status = 'offline', last_active = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
}
echo json_encode(['success' => true]);
?>