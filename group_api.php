<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => '請先登入']);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/group_service.php';
try {
    ensureGroupSchema($pdo);
    $userId = (int) $_SESSION['user_id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['group_id'] ?? 0);
        if (!$id && ($_GET['view'] ?? '') === 'list') {
            $data = ['groups' => groupList($pdo, $userId)];
        } elseif ($id && ($_GET['view'] ?? '') === 'files') {
            groupMember($pdo, $id, $userId);
            cleanupMissingGroupFiles($pdo, $id, $userId);
            $search = '%' . mb_substr((string) ($_GET['search'] ?? ''), 0, 255) . '%';
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $files = groupQuery($pdo, "SELECT m.id, m.original_filename, m.file_size, m.created_at, u.username
                FROM chat_group_messages m JOIN users u ON u.user_id = m.sender_id
                WHERE m.group_id = ? AND m.kind = 'file' AND m.recalled_at IS NULL AND m.original_filename LIKE ?
                ORDER BY m.id DESC LIMIT 100 OFFSET $offset", [$id, $search])->fetchAll(PDO::FETCH_ASSOC);
            $data = ['files' => $files, 'has_more' => count($files) === 100];
        } elseif ($id) {
            $data = groupSnapshot($pdo, $id, $userId, (int) ($_GET['before'] ?? 0));
        } else {
            cleanupMissingUserFiles($pdo, $userId);
            $data = [
                'groups' => groupList($pdo, $userId),
                'users' => groupQuery($pdo, 'SELECT user_id, username FROM users WHERE user_id <> ? ORDER BY username', [$userId])->fetchAll(PDO::FETCH_ASSOC),
                'files' => groupQuery($pdo, 'SELECT file_id, original_filename FROM files WHERE user_id = ? ORDER BY created_at DESC LIMIT 500', [$userId])->fetchAll(PDO::FETCH_ASSOC),
            ];
        }
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit(json_encode(['success' => false, 'msg' => '不支援的請求方式']));
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['chat_csrf']) || !hash_equals($_SESSION['chat_csrf'], $token)) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'msg' => '頁面已過期，請重新整理']));
    }
    $input = json_decode($_POST['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('無效的請求');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $data = ['group_id' => createChatGroup($pdo, $userId, (string) ($input['name'] ?? ''), (array) ($input['members'] ?? []))];
    } else {
        $data = groupAction($pdo, (int) ($_POST['group_id'] ?? 0), $userId, $action, $input, $_FILES['file'] ?? null);
    }
    echo json_encode(['success' => true] + $data);
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (DomainException $error) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (InvalidArgumentException | JsonException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('群組操作失敗：' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'msg' => '群組操作失敗，請稍後重試或檢查伺服器日誌']);
}
