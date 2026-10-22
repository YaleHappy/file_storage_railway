<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/preview_tokens.php';
$id = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
if ($id <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) { http_response_code(403); exit('無效的預覽連結'); }
ensureReceivedPreviewTokensTable($pdo);
$stmt = $pdo->prepare('SELECT sf.* FROM received_preview_tokens pt JOIN shared_files sf ON sf.id = pt.delivery_id AND sf.recipient_id = pt.recipient_id WHERE pt.token = ? AND pt.delivery_id = ? AND pt.expires_at > NOW() LIMIT 1');
$stmt->execute([$token, $id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) { http_response_code(403); exit('預覽連結無效或已過期'); }
$types = ['doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation'];
$extension = strtolower(pathinfo($file['original_filename'], PATHINFO_EXTENSION));
$path = __DIR__ . '/' . $file['file_path'];
if (!isset($types[$extension]) || !is_file($path)) { http_response_code(404); exit('無法預覽此檔案'); }
header('Content-Type: ' . $types[$extension]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($file['original_filename']));
header('Content-Length: ' . filesize($path));
readfile($path);
