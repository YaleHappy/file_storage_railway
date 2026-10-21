<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('請先登入'); }
require 'config.php';
require_once __DIR__ . '/group_service.php';
try {
    ensureGroupSchema($pdo);
    $groupId = (int) ($_GET['group_id'] ?? 0);
    groupMember($pdo, $groupId, (int) $_SESSION['user_id']);
    $message = groupMessage($pdo, $groupId, (int) ($_GET['message_id'] ?? 0));
    if ($message['kind'] !== 'file') throw new OutOfBoundsException('找不到檔案');
    $path = groupPrivateFile($message['file_path']);
    $handle = @fopen($path, 'rb');
    if (!$handle) throw new OutOfBoundsException('檔案已遺失');
    session_write_close();
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Disposition: attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode($message['original_filename']));
    header('Content-Length: ' . fstat($handle)['size']);
    fpassthru($handle);
    fclose($handle);
} catch (OutOfBoundsException $error) {
    http_response_code(404); echo '檔案不存在、已收回，或你已不在此群組';
} catch (Throwable $error) {
    error_log('群組下載失敗：' . $error->getMessage());
    http_response_code(500); echo '無法下載檔案';
}
