<?php
require_once __DIR__.'/file_cleanup.php';
require_once __DIR__.'/account_access.php';
require_once __DIR__.'/group_service.php';
function adminQuery(PDO $pdo,string $sql,array $params=[]): PDOStatement { $s=$pdo->prepare($sql);$s->execute($params);return $s; }
function ensureAdminSchema(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_account_state (user_id INTEGER PRIMARY KEY, disabled INTEGER NOT NULL DEFAULT 0, session_version INTEGER NOT NULL DEFAULT 0)'.$suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_group_state (group_id BIGINT PRIMARY KEY, paused INTEGER NOT NULL DEFAULT 0)'.$suffix);
    $id=$mysql?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (id $id, actor_id INTEGER NOT NULL, actor_name VARCHAR(100) NOT NULL, action VARCHAR(40) NOT NULL, target_id BIGINT NOT NULL, detail VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)".$suffix);
    ensureGroupSchema($pdo);
}
function requireStorageAdmin(PDO $pdo,int $actor): array
{
    $user=adminQuery($pdo,'SELECT user_id,username,is_admin FROM users WHERE user_id=?',[$actor])->fetch(PDO::FETCH_ASSOC);
    if (!$user || !(int)$user['is_admin'] || (int)storageAccountState($pdo,$actor)['disabled']) throw new DomainException('沒有管理權限，請重新登入。');
    return $user;
}
function adminText(string $value,int $max,string $label): string { $value=trim($value);if($value===''||mb_strlen($value)>$max)throw new InvalidArgumentException($label.'不可空白或超過 '.$max.' 字');return $value; }
function adminAction(PDO $pdo,int $actor,string $action,array $input): array
{
    $admin=requireStorageAdmin($pdo,$actor);$id=(int)($input['id']??0);$unlink=[];$result=[];$detail='';
    $pdo->beginTransaction();
    try {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        // Keep role and disable changes serialized, including last-administrator checks.
        $admins=adminQuery($pdo,'SELECT user_id FROM users WHERE is_admin=1 ORDER BY user_id'.$lock)->fetchAll(PDO::FETCH_COLUMN);
        requireStorageAdmin($pdo,$actor);
        switch($action) {
        case 'addUser':
            $name=adminText((string)($input['username']??''),80,'名稱');$password=adminText((string)($input['password']??''),200,'密碼');
            $email=trim((string)($input['email']??''));if($email==='')$email='account.'.bin2hex(random_bytes(8)).'@local.register';
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Email 格式錯誤');
            if(adminQuery($pdo,'SELECT user_id FROM users WHERE username=?',[$name])->fetchColumn())throw new InvalidArgumentException('使用者名稱已存在');
            $role=($input['role']??'user')==='admin'?'admin':'user';
            adminQuery($pdo,'INSERT INTO users (username,password,email,role,is_admin) VALUES (?,?,?,?,?)',[$name,$password,$email,$role,$role==='admin'?1:0]);
            $id=(int)$pdo->lastInsertId();$detail='新增帳號 '.$name;$result['id']=$id;break;
        case 'editUser': case 'disableUser': case 'restoreUser': case 'deleteUser':
            $target=adminQuery($pdo,'SELECT * FROM users WHERE user_id=?'.$lock,[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$target)throw new OutOfBoundsException('帳號不存在');
            if($id===$actor)throw new DomainException('不可在後臺修改、停用或刪除自己的帳號');
            $detail=$target['username'];
            if($action==='editUser') {
                $name=adminText((string)($input['username']??''),80,'名稱');$email=trim((string)($input['email']??''));$role=($input['role']??'user')==='admin'?'admin':'user';
                if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Email 格式錯誤');
                if(adminQuery($pdo,'SELECT user_id FROM users WHERE username=? AND user_id<>?',[$name,$id])->fetchColumn())throw new InvalidArgumentException('使用者名稱已存在');
                if((int)$target['is_admin'] && $role!=='admin' && count($admins)<2)throw new DomainException('至少保留一位管理員');
                adminQuery($pdo,'UPDATE users SET username=?,email=?,role=?,is_admin=? WHERE user_id=?',[$name,$email,$role,$role==='admin'?1:0,$id]);
                $detail.=' → '.$name.'（'.$role.'）';
            } elseif($action==='disableUser'||$action==='restoreUser') {
                if((int)$target['is_admin'] && $action==='disableUser' && count($admins)<2)throw new DomainException('至少保留一位管理員');
                $state=storageAccountState($pdo,$id);$disabled=$action==='disableUser'?1:0;
                if((int)$state['disabled']===$disabled)throw new InvalidArgumentException('帳號已是指定狀態');
                adminQuery($pdo,'DELETE FROM admin_account_state WHERE user_id=?',[$id]);
                adminQuery($pdo,'INSERT INTO admin_account_state (user_id,disabled,session_version) VALUES (?,?,?)',[$id,$disabled,(int)$state['session_version']+1]);
                if($disabled)adminQuery($pdo,"UPDATE users SET status='offline' WHERE user_id=?",[$id]);
            } else {
                // Preserve data by refusing destructive account cascades. Disable is the normal offboarding action.
                foreach(['files'=>'user_id','folders'=>'user_id','chat_group_members'=>'user_id','chat_groups'=>'owner_id','chat_group_messages'=>'sender_id'] as $table=>$column)if(adminQuery($pdo,"SELECT COUNT(*) FROM $table WHERE $column=?",[$id])->fetchColumn())throw new DomainException('帳號仍有檔案、資料夾或群組，請先移轉／清理，或改用停用');
                foreach(['chat_messages','shared_files'] as $table)if(adminQuery($pdo,"SELECT COUNT(*) FROM $table WHERE sender_id=? OR recipient_id=?",[$id,$id])->fetchColumn())throw new DomainException('帳號仍有對話或收件紀錄，請改用停用以保留資料');
                adminQuery($pdo,'DELETE FROM chat_group_notification_links WHERE notification_id IN (SELECT id FROM notifications WHERE sender_id=? OR recipient_id=?)',[$id,$id]);
                adminQuery($pdo,'DELETE FROM notifications WHERE sender_id=? OR recipient_id=?',[$id,$id]);
                foreach(['preview_tokens','received_preview_tokens','chat_event_reactions'] as $table)if(cleanupTableExists($pdo,$table))adminQuery($pdo,"DELETE FROM $table WHERE ".($table==='received_preview_tokens'?'recipient_id':'user_id').'=?',[$id]);
                adminQuery($pdo,'DELETE FROM admin_account_state WHERE user_id=?',[$id]);adminQuery($pdo,'DELETE FROM users WHERE user_id=?',[$id]);
            }break;
        case 'deleteFolder':
            $folder=adminQuery($pdo,'SELECT * FROM folders WHERE folder_id=?'.$lock,[$id])->fetch(PDO::FETCH_ASSOC);if(!$folder)throw new OutOfBoundsException('資料夾不存在');
            if(adminQuery($pdo,'SELECT COUNT(*) FROM folders WHERE parent_folder=?',[$id])->fetchColumn()||adminQuery($pdo,'SELECT COUNT(*) FROM files WHERE folder_id=?',[$id])->fetchColumn())throw new DomainException('只能刪除空資料夾；請先移出檔案與子資料夾');
            adminQuery($pdo,'DELETE FROM folders WHERE folder_id=?',[$id]);$detail=$folder['folder_name'];break;
        case 'deleteFile':
            $file=adminQuery($pdo,'SELECT * FROM files WHERE file_id=?'.$lock,[$id])->fetch(PDO::FETCH_ASSOC);if(!$file)throw new OutOfBoundsException('檔案不存在');
            if(basename($file['stored_filename'])!==$file['stored_filename'])throw new DomainException('檔案路徑無效');
            foreach(['file_shares','preview_tokens'] as $table)if(cleanupTableExists($pdo,$table))adminQuery($pdo,"DELETE FROM $table WHERE file_id=?",[$id]);
            adminQuery($pdo,'DELETE FROM files WHERE file_id=?',[$id]);
            if(!adminQuery($pdo,'SELECT COUNT(*) FROM files WHERE stored_filename=?',[$file['stored_filename']])->fetchColumn() && !adminQuery($pdo,'SELECT COUNT(*) FROM shared_files WHERE file_path=?',['uploads/'.$file['stored_filename']])->fetchColumn())$unlink[]=__DIR__.'/uploads/'.$file['stored_filename'];
            $detail=$file['original_filename'];break;
        case 'deleteNotification':
            if(!adminQuery($pdo,'SELECT id FROM notifications WHERE id=?',[$id])->fetchColumn())throw new OutOfBoundsException('通知不存在');
            adminQuery($pdo,'DELETE FROM chat_group_notification_links WHERE notification_id=?',[$id]);adminQuery($pdo,'DELETE FROM notifications WHERE id=?',[$id]);$detail='通知';break;
        case 'deleteMessage':
            if(!adminQuery($pdo,'SELECT id FROM chat_messages WHERE id=?',[$id])->fetchColumn())throw new OutOfBoundsException('訊息不存在');
            if(cleanupTableExists($pdo,'chat_event_details')) {adminQuery($pdo,"UPDATE chat_event_details SET reply_type=NULL,reply_id=NULL WHERE reply_type='message' AND reply_id=?",[$id]);adminQuery($pdo,"DELETE FROM chat_event_details WHERE event_type='message' AND event_id=?",[$id]);}
            if(cleanupTableExists($pdo,'chat_event_reactions'))adminQuery($pdo,"DELETE FROM chat_event_reactions WHERE event_type='message' AND event_id=?",[$id]);
            adminQuery($pdo,'DELETE FROM chat_messages WHERE id=?',[$id]);$detail='私訊';break;
        case 'pauseGroup': case 'resumeGroup': case 'deleteGroup':
            $group=adminQuery($pdo,'SELECT * FROM chat_groups WHERE id=?'.$lock,[$id])->fetch(PDO::FETCH_ASSOC);if(!$group)throw new OutOfBoundsException('群組不存在');$detail=$group['name'];
            if($action==='deleteGroup') {
                $paths=adminQuery($pdo,"SELECT file_path FROM chat_group_messages WHERE group_id=? AND kind='file'",[$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach($paths as $path) {try{$unlink[]=groupPrivateFile($path);}catch(Throwable $e){throw new DomainException('群組檔案路徑無效');}}
                foreach(['chat_group_mentions','chat_group_reactions'] as $table)adminQuery($pdo,"DELETE FROM $table WHERE message_id IN (SELECT id FROM chat_group_messages WHERE group_id=?)",[$id]);
                adminQuery($pdo,'DELETE FROM notifications WHERE id IN (SELECT notification_id FROM chat_group_notification_links WHERE group_id=?)',[$id]);
                adminQuery($pdo,'DELETE FROM chat_group_notification_links WHERE group_id=?',[$id]);adminQuery($pdo,'DELETE FROM chat_group_messages WHERE group_id=?',[$id]);adminQuery($pdo,'DELETE FROM chat_group_members WHERE group_id=?',[$id]);adminQuery($pdo,'DELETE FROM chat_groups WHERE id=?',[$id]);
                adminQuery($pdo,'DELETE FROM admin_group_state WHERE group_id=?',[$id]);
            } else {adminQuery($pdo,'DELETE FROM admin_group_state WHERE group_id=?',[$id]);adminQuery($pdo,'INSERT INTO admin_group_state (group_id,paused) VALUES (?,?)',[$id,$action==='pauseGroup'?1:0]);}
            break;
        case 'createAnnouncement': case 'editAnnouncement':
            $title=adminText((string)($input['title']??''),50,'公告標題');$content=adminText((string)($input['content']??''),2000,'公告內容');$dates=[];
            foreach(['start_date','end_date'] as $field){$raw=trim((string)($input[$field]??''));$date=$raw===''?false:DateTime::createFromFormat('!Y-m-d\TH:i',$raw);if($raw!==''&&(!$date||$date->format('Y-m-d\TH:i')!==$raw))throw new InvalidArgumentException('公告時間格式錯誤');$dates[]=$date?$date->format('Y-m-d H:i:s'):null;}
            if($dates[0]&&$dates[1]&&$dates[1]<=$dates[0])throw new InvalidArgumentException('結束時間必須晚於開始時間');
            $params=[$title,$content,$dates[0],$dates[1],isset($input['is_active'])?1:0];
            if($action==='createAnnouncement'){adminQuery($pdo,'INSERT INTO announcements (title,content,start_date,end_date,is_active,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)',$params);$id=(int)$pdo->lastInsertId();$result['id']=$id;}
            else {if(!adminQuery($pdo,'SELECT id FROM announcements WHERE id=?',[$id])->fetchColumn())throw new OutOfBoundsException('公告不存在');$params[]=$id;adminQuery($pdo,'UPDATE announcements SET title=?,content=?,start_date=?,end_date=?,is_active=? WHERE id=?',$params);}
            $detail=$title;break;
        case 'deleteAnnouncement':
            $title=adminQuery($pdo,'SELECT title FROM announcements WHERE id=?',[$id])->fetchColumn();if($title===false)throw new OutOfBoundsException('公告不存在');adminQuery($pdo,'DELETE FROM announcements WHERE id=?',[$id]);$detail=$title;break;
        default: throw new InvalidArgumentException('不支援的管理操作');
        }
        adminQuery($pdo,'INSERT INTO admin_audit_log (actor_id,actor_name,action,target_id,detail) VALUES (?,?,?,?,?)',[$actor,$admin['username'],$action,$id,mb_substr($detail,0,500)]);
        $pdo->commit();
    } catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    foreach(array_unique($unlink) as $path)if(is_file($path)&&!@unlink($path))error_log('管理刪除：實體檔案待清理 '.$path);
    return ['success'=>true,'msg'=>'操作完成']+$result;
}
