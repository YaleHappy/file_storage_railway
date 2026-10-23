<?php
require __DIR__.'/../user_activity.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (user_id INTEGER,username TEXT,is_admin INTEGER);INSERT INTO users VALUES (1,'member',0),(2,'manager',1)");
$_SESSION=['user_id'=>1];recordUserActivity($pdo,'上傳檔案',9,'檔名：<test>.txt');
$_SESSION=['user_id'=>2];recordUserActivity($pdo,'移動檔案',9,'資料夾：12');
$_SESSION=['user_id'=>999];recordUserActivity($pdo,'刪除檔案',9,'不存在的帳號');
$rows=$pdo->query('SELECT * FROM user_activity_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if(count($rows)!==2 || $rows[0]['actor_role']!=='user' || $rows[1]['actor_role']!=='admin' || $rows[0]['detail']!=='檔名：<test>.txt')throw new RuntimeException('Actor attribution failed');
$pdo->exec('DELETE FROM users');
if($pdo->query('SELECT COUNT(*) FROM user_activity_log')->fetchColumn()!=2)throw new RuntimeException('History must survive account deletion');
echo "User activity: role attribution, target details, missing actor and history retention passed.\n";
