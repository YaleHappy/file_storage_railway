<?php
require_once __DIR__ . '/../folder_access.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE folders (folder_id INTEGER PRIMARY KEY,user_id INTEGER)');
$pdo->exec('INSERT INTO folders VALUES (1,10),(2,20)');
foreach ([[10,0,true],[10,1,true],[10,2,false],[10,999,false],[10,-1,false],[0,0,false]] as [$user,$folder,$expected]) {
    if (ownsStorageFolder($pdo,$user,$folder) !== $expected) throw new RuntimeException('Folder ownership validation failed');
}
echo "Folder ownership: 6 checks passed\n";
$pdo->exec("ALTER TABLE folders ADD COLUMN parent_folder INTEGER;ALTER TABLE folders ADD COLUMN folder_name TEXT;
UPDATE folders SET folder_name='root-null' WHERE folder_id=1;
UPDATE folders SET folder_name='root-zero',parent_folder=0 WHERE folder_id=2;
INSERT INTO folders VALUES(3,10,0,'root-zero'),(4,10,1,'nested');");
foreach([[10,0,'root-null',0,true],[10,0,'root-zero',0,true],[10,1,'nested',0,true],[10,0,'nested',0,false],[20,0,'root-null',0,false],[10,0,'root-null',1,false]] as [$uid,$parent,$name,$exclude,$expected]){
 if(storageFolderNameExists($pdo,$uid,$parent,$name,$exclude)!==$expected)throw new RuntimeException('Folder name validation failed');
}
echo "Folder names: 6 root, nested and ownership checks passed\n";
