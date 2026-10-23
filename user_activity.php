<?php
// Frontend activity is separate from privileged administrator audit records.
function ensureUserActivitySchema(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $id=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_activity_log (id $id, actor_id INTEGER NOT NULL, actor_name VARCHAR(100) NOT NULL, actor_role VARCHAR(20) NOT NULL, action VARCHAR(40) NOT NULL, target_id BIGINT NOT NULL, detail VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
}
function recordUserActivity(PDO $pdo,string $action,int $target,string $detail): void
{
    // Only invoke after a successful mutation/commit. Logging failure must not undo an operation.
    try {
        if(empty($_SESSION['user_id']))return;
        ensureUserActivitySchema($pdo);
        $s=$pdo->prepare('SELECT username,is_admin FROM users WHERE user_id=?');
        $s->execute([(int)($_SESSION['user_id']??0)]);$user=$s->fetch(PDO::FETCH_ASSOC);
        if(!$user)return;
        $s=$pdo->prepare('INSERT INTO user_activity_log (actor_id,actor_name,actor_role,action,target_id,detail) VALUES (?,?,?,?,?,?)');
        $s->execute([(int)$_SESSION['user_id'],$user['username'],$user['is_admin']?'admin':'user',$action,$target,mb_substr($detail,0,500)]);
    } catch(Throwable $error){error_log('User activity log failed: '.$error->getMessage());}
}
