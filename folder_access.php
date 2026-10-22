<?php
function ownsStorageFolder(PDO $pdo, int $userId, int $folderId): bool
{
    if ($folderId === 0) return $userId > 0;
    if ($folderId < 0 || $userId <= 0) return false;
    $stmt = $pdo->prepare('SELECT folder_id FROM folders WHERE folder_id = ? AND user_id = ?');
    $stmt->execute([$folderId,$userId]);
    return $stmt->fetchColumn() !== false;
}

function storageFolderNameExists(PDO $pdo,int $userId,int $parent,string $name,int $excludeId=0): bool {
 $params=[$userId,$name,$excludeId];
 $where=$parent===0?'(parent_folder IS NULL OR parent_folder=0)':'parent_folder=?';
 if($parent!==0)$params[]=$parent;
 $query=$pdo->prepare('SELECT folder_id FROM folders WHERE user_id=? AND folder_name=? AND folder_id<>? AND '.$where.' LIMIT 1');
 $query->execute($params);
 return $query->fetchColumn()!==false;
}
