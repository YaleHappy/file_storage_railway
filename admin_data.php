<?php
require_once __DIR__.'/admin_storage.php';
function adminOverview(PDO $pdo): array
{
    return [
        'users'=>(int)adminQuery($pdo,'SELECT COUNT(*) FROM users')->fetchColumn(),
        'online'=>(int)adminQuery($pdo,"SELECT COUNT(*) FROM users u LEFT JOIN admin_account_state a ON a.user_id=u.user_id WHERE COALESCE(a.disabled,0)=0 AND u.status IN ('online','idle','away') AND u.last_active>=?",[date('Y-m-d H:i:s',time()-120)])->fetchColumn(),
        'files'=>(int)adminQuery($pdo,'SELECT COUNT(*) FROM files')->fetchColumn(),
        'bytes'=>(int)adminQuery($pdo,'SELECT COALESCE(SUM(file_size),0) FROM files')->fetchColumn(),
        'groups'=>(int)adminQuery($pdo,'SELECT COUNT(*) FROM chat_groups')->fetchColumn(),
        'announcements'=>(int)adminQuery($pdo,'SELECT COUNT(*) FROM announcements WHERE is_active=1 AND (start_date IS NULL OR start_date<=CURRENT_TIMESTAMP) AND (end_date IS NULL OR end_date>=CURRENT_TIMESTAMP)')->fetchColumn(),
    ];
}
function adminListData(PDO $pdo,string $section,string $q,int $page,int $uid=0,?int $folder=null): array
{
    $defs=[
        'users'=>['u.user_id AS id,u.username,u.email,u.role,u.is_admin,u.status,u.last_active,COALESCE(a.disabled,0) AS disabled,(SELECT COUNT(*) FROM files f WHERE f.user_id=u.user_id) AS file_count','users u LEFT JOIN admin_account_state a ON a.user_id=u.user_id',['u.username','u.email'],'u.user_id'],
        'files'=>["fi.file_id AS id,fi.user_id,fi.folder_id,fi.original_filename,u.username,COALESCE(fo.folder_name,'我的雲端') AS folder_name,fi.file_size,fi.created_at",'files fi LEFT JOIN users u ON u.user_id=fi.user_id LEFT JOIN folders fo ON fo.folder_id=fi.folder_id',['fi.original_filename','u.username'],'fi.file_id'],
        'folders'=>["f.folder_id AS id,f.user_id,f.parent_folder,f.folder_name,u.username,COALESCE(p.folder_name,'我的雲端') AS parent_name,f.created_at",'folders f LEFT JOIN users u ON u.user_id=f.user_id LEFT JOIN folders p ON p.folder_id=f.parent_folder AND p.user_id=f.user_id',['f.folder_name','u.username','p.folder_name'],'f.folder_id'],
        'groups'=>["g.id,g.name,g.owner_id,u.username,COALESCE(a.paused,0) AS paused,(SELECT COUNT(*) FROM chat_group_members m WHERE m.group_id=g.id) AS member_count,(SELECT COUNT(*) FROM chat_group_messages m WHERE m.group_id=g.id AND m.kind='file') AS file_count,(SELECT COALESCE(SUM(m.file_size),0) FROM chat_group_messages m WHERE m.group_id=g.id AND m.kind='file') AS file_bytes,g.created_at",'chat_groups g LEFT JOIN users u ON u.user_id=g.owner_id LEFT JOIN admin_group_state a ON a.group_id=g.id',['g.name','u.username'],'g.id'],
        'notifications'=>['n.id,n.message,n.type,n.is_read,n.created_at,s.username AS sender_name,r.username AS recipient_name','notifications n LEFT JOIN users s ON s.user_id=n.sender_id LEFT JOIN users r ON r.user_id=n.recipient_id',['n.message','s.username','r.username'],'n.id'],
        'chats'=>['c.id,c.message,c.sent_at,s.username AS sender_name,r.username AS recipient_name','chat_messages c LEFT JOIN users s ON s.user_id=c.sender_id LEFT JOIN users r ON r.user_id=c.recipient_id',['c.message','s.username','r.username'],'c.id'],
        'announcements'=>['a.id,a.title,a.content,a.start_date,a.end_date,a.is_active,a.created_at','announcements a',['a.title','a.content'],'a.id'],
        'logs'=>['a.*','admin_audit_log a',['a.actor_name','a.action','a.detail'],'a.id'],
    ];
    if($section==='user_files')$section='files';
    [$columns,$from,$search,$order]=$defs[$section];$where=[];$params=[];
    if($q!==''){$parts=[];$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';foreach($search as $field){$parts[]="$field LIKE ? ESCAPE '!'";$params[]=$pattern;}$where[]='('.implode(' OR ',$parts).')';}
    if($uid>0){if($section==='files'){$where[]='fi.user_id=?';$params[]=$uid;}elseif(in_array($section,['chats','notifications'],true)){$alias=$section==='chats'?'c':'n';$where[]="($alias.sender_id=? OR $alias.recipient_id=?)";$params[]=$uid;$params[]=$uid;}}
    if($uid>0 && $section==='folders'){$where[]='f.user_id=?';$params[]=$uid;}
    if($folder!==null && in_array($section,['files','folders'],true)){$field=$section==='files'?'fi.folder_id':'f.parent_folder';if($folder===0){$where[]='('.$field.' IS NULL OR '.$field.'=0)';}else{$where[]=$field.'=?';$params[]=$folder;}}
    $filter=$where?' WHERE '.implode(' AND ',$where):'';
    $total=(int)adminQuery($pdo,"SELECT COUNT(*) FROM $from$filter",$params)->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=max(1,min($page,$pages));$offset=($page-1)*20;
    $rows=adminQuery($pdo,"SELECT $columns FROM $from$filter ORDER BY $order DESC LIMIT 20 OFFSET $offset",$params)->fetchAll(PDO::FETCH_ASSOC);
    if(in_array($section,['files','folders'],true))foreach($rows as &$row){
      $row['full_path']=adminStoragePath($pdo,(int)$row['user_id'],(int)($section==='files'?$row['folder_id']:$row['parent_folder']));
      if($section==='folders'){
        $row['child_count']=(int)adminQuery($pdo,'SELECT COUNT(*) FROM folders WHERE user_id=? AND parent_folder=?',[$row['user_id'],$row['id']])->fetchColumn();
        $row['file_count']=(int)adminQuery($pdo,'SELECT COUNT(*) FROM files WHERE user_id=? AND folder_id=?',[$row['user_id'],$row['id']])->fetchColumn();
      }
    }
    unset($row);
    return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
}
