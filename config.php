<?php
// config.php（Railway MySQL 版本）

// Railway MySQL 固定連線設定
$db_host = 'mysql.railway.internal';
$db_port = '3306';
$db_name = 'file_storage_db';
$db_user = 'root';
$db_pass = 'jnktoligkZdqECAOosuAIBetLUZkobIB';

// PHP 時區設定為台北
date_default_timezone_set('Asia/Taipei');

try {
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";

    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // MySQL 連線時區設定為 UTC+8
    $pdo->exec("SET time_zone = '+08:00'");

} catch (PDOException $e) {
    error_log("資料庫連線失敗：" . $e->getMessage());
    die("資料庫連線失敗，請檢查設定");
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/account_access.php';
enforceStorageAccount($pdo);
