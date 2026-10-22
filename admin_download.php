<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__.'/admin_service.php';
header('Cache-Control: no-store');
try { requireStorageAdmin($pdo,(int)($_SESSION['user_id']??0)); }
catch(DomainException $error){http_response_code(403);exit('沒有管理權限');}
$file=adminQuery($pdo,'SELECT original_filename,stored_filename FROM files WHERE file_id=?',[(int)($_GET['file_id']??0)])->fetch(PDO::FETCH_ASSOC);
$root=realpath(__DIR__.'/uploads');
$path=$file?realpath(__DIR__.'/uploads/'.$file['stored_filename']):false;
if(!$path || !$root || !str_starts_with(str_replace(chr(92),'/',$path),str_replace(chr(92),'/',$root).'/') || !is_file($path)){http_response_code(404);exit('找不到檔案');}
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: attachment; filename=\"download\"; filename*=UTF-8''".rawurlencode($file['original_filename']));
header('Content-Length: '.filesize($path));
readfile($path);
