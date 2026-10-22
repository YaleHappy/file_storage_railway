<?php
function storageGroupPaused(PDO $pdo,int $groupId): bool
{
    try { $s=$pdo->prepare('SELECT paused FROM admin_group_state WHERE group_id=?');$s->execute([$groupId]);return (bool)$s->fetchColumn(); }
    catch(PDOException $error){if($error->getCode()==='42S02'||str_contains($error->getMessage(),'no such table'))return false;throw $error;}
}
function storageAccountState(PDO $pdo, int $userId): array
{
    try {
        $stmt=$pdo->prepare('SELECT disabled, session_version FROM admin_account_state WHERE user_id=?'); $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['disabled'=>0,'session_version'=>0];
    } catch(PDOException $error) {
        if ($error->getCode()==='42S02' || str_contains($error->getMessage(),'no such table')) return ['disabled'=>0,'session_version'=>0];
        throw $error;
    }
}
function storageDisabledUserIds(PDO $pdo): array
{
    try{return array_map('intval',$pdo->query('SELECT user_id FROM admin_account_state WHERE disabled=1')->fetchAll(PDO::FETCH_COLUMN));}
    catch(PDOException $error){if($error->getCode()==='42S02'||str_contains($error->getMessage(),'no such table'))return [];throw $error;}
}
function enforceStorageAccount(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) return;
    $script=basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script,['login.php','register.php','logout.php'],true)) return;
    $state=storageAccountState($pdo,(int)$_SESSION['user_id']);
    $exists=$pdo->prepare('SELECT user_id,username,is_admin FROM users WHERE user_id=?'); $exists->execute([$_SESSION['user_id']]);
    $user=$exists->fetch(PDO::FETCH_ASSOC);
    if ($user && !(int)$state['disabled'] && (int)($_SESSION['account_version'] ?? 0)===(int)$state['session_version']) {
        $_SESSION['is_admin']=(int)$user['is_admin']; $_SESSION['role']=$user['is_admin']?'admin':'user'; $_SESSION['username']=$user['username']; return;
    }
    $_SESSION=[]; session_regenerate_id(true);
    if (in_array($script,['index.php','admin.php','my_shares.php','received_files.php','chat_history.php','notifications.php','group_chat.php'],true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET' && ($_GET['format']??'')!=='json' && ($_GET['presence']??'')!=='1') {
        header('Location: login.php?session=expired');
    } else {
        http_response_code(403); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'msg'=>'帳號已停用或登入已失效，請重新登入。','error'=>'帳號已停用或登入已失效，請重新登入。']);
    }
    exit;
}
