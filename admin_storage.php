<?php
function adminFolderTrail(PDO $pdo,int $uid,int $folder): array {
 $trail=[];$seen=[];
 while($folder>0 && !isset($seen[$folder])){
  $seen[$folder]=true;
  $row=adminQuery($pdo,'SELECT folder_id,folder_name,parent_folder FROM folders WHERE folder_id=? AND user_id=?',[$folder,$uid])->fetch(PDO::FETCH_ASSOC);
  if(!$row)break;
  array_unshift($trail,$row);$folder=(int)$row['parent_folder'];
 }
 return $trail;
}
function adminStoragePath(PDO $pdo,int $uid,int $folder):string {
 return '我的雲端'.implode('',array_map(fn($row)=>' / '.$row['folder_name'],adminFolderTrail($pdo,$uid,$folder)));
}
function adminStorageListData(PDO $pdo,string $q,int $page,int $uid=0,?int $folder=null):array {
 $params=[];$conditions=[];
 if($uid){$conditions[]='s.user_id=?';$params[]=$uid;}
 if($folder!==null){if($folder===0)$conditions[]='(s.parent_id IS NULL OR s.parent_id=0)';else{$conditions[]='s.parent_id=?';$params[]=$folder;}}
 if($q!==''){$conditions[]="(s.item_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')";$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';$params[]=$pattern;$params[]=$pattern;}
 $source="(SELECT 'folders' AS kind,folder_id AS id,user_id,parent_folder AS parent_id,folder_name AS item_name,0 AS file_size,created_at FROM folders UNION ALL SELECT 'files',file_id,user_id,folder_id,original_filename,file_size,created_at FROM files) s LEFT JOIN users u ON u.user_id=s.user_id";
 $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
 $total=(int)adminQuery($pdo,"SELECT COUNT(*) FROM $source$where",$params)->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=max(1,min($page,$pages));$offset=($page-1)*20;
 $rows=adminQuery($pdo,"SELECT s.*,u.username FROM $source$where ORDER BY CASE WHEN s.kind='folders' THEN 0 ELSE 1 END,s.item_name,s.id LIMIT 20 OFFSET $offset",$params)->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as &$row){
  $row['full_path']=adminStoragePath($pdo,(int)$row['user_id'],(int)$row['parent_id']);
  $row['folder_id']=$row['parent_id'];$row['folder_name']=$row['item_name'];$row['original_filename']=$row['item_name'];
  if($row['kind']==='folders'){
   $row['child_count']=(int)adminQuery($pdo,'SELECT COUNT(*) FROM folders WHERE user_id=? AND parent_folder=?',[$row['user_id'],$row['id']])->fetchColumn();
   $row['file_count']=(int)adminQuery($pdo,'SELECT COUNT(*) FROM files WHERE user_id=? AND folder_id=?',[$row['user_id'],$row['id']])->fetchColumn();
  }
 }
 unset($row);return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
}
