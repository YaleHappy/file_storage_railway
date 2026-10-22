<?php
// Only age a disposable account created by Test-AdminPresence.ps1.
require 'C:/xampp/htdocs/file_storage/config.php';
$id=(int)($argv[1]??0);
$stmt=$pdo->prepare("UPDATE users SET last_active=DATE_SUB(NOW(),INTERVAL 121 SECOND),status='online' WHERE user_id=? AND username LIKE 'presenceaudit%'");
$stmt->execute([$id]);
if($stmt->rowCount()!==1)throw new RuntimeException('Disposable account not found');
