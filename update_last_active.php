<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/presence_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => '請先登入']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$clientState = $_POST['state'] ?? '';
$isActivity = ($_POST['activity'] ?? '0') === '1';
$isBackgroundStart = ($_POST['background_start'] ?? '0') === '1';
$allowedStates = ['active', 'idle', 'background'];

try {
    $stmt = $pdo->prepare("SELECT status, status_mode, last_interaction FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => '找不到使用者']);
        exit;
    }

    // 舊版頁面留下的無參數心跳不再影響在線時間。
    if (!in_array($clientState, $allowedStates, true)) {
        echo json_encode(array_merge(['success' => true], presencePayload($user['status'], $user['status_mode'])));
        exit;
    }

    if ($clientState === 'background') {
        if ($isBackgroundStart) {
            $status = $user['status_mode'] === 'auto'
                ? 'idle'
                : effectivePresenceStatus($user['status_mode'], 'idle', $user['last_interaction']);
            $update = $pdo->prepare("UPDATE users SET status = ?, last_active = NOW() WHERE user_id = ?");
            $update->execute([$status, $userId]);
        } else {
            $status = $user['status'];
        }
    } else {
        if ($isActivity) {
            $update = $pdo->prepare("UPDATE users SET last_active = NOW(), last_interaction = NOW() WHERE user_id = ?");
            $update->execute([$userId]);
            $user['last_interaction'] = date('Y-m-d H:i:s');
        } else {
            $update = $pdo->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
            $update->execute([$userId]);
        }

        $status = effectivePresenceStatus($user['status_mode'], $clientState, $user['last_interaction']);
        $updateStatus = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $updateStatus->execute([$status, $userId]);
    }

    $_SESSION['status'] = $status;
    echo json_encode(array_merge(['success' => true], presencePayload($status, $user['status_mode'])));
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}
