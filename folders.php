<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_activity.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'msg'=>'未登入']);
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['success'=>false,'msg'=>'請透過表單操作']);exit;}
$action = $_GET['action'] ?? '';

if ($action === 'create') {
    $name = trim($_POST['folder_name'] ?? '');
    $parent = (int)($_POST['parent_folder'] ?? 0);
    require_once __DIR__ . '/folder_access.php';
    if (!ownsStorageFolder($pdo, (int)$_SESSION['user_id'], $parent)) {
        http_response_code(403); echo json_encode(['success'=>false,'msg'=>'上層資料夾不存在或沒有權限']); exit;
    }
    
    if ($name === '') {
        echo json_encode(['success' => false, 'msg' => '資料夾名稱不可空']);
        exit;
    }

    // **檢查同一個 parent_folder 是否已經有相同名稱的資料夾**
    $exists=storageFolderNameExists($pdo,(int)$_SESSION['user_id'],$parent,$name);

    if ($exists > 0) {
        echo json_encode(['success' => false, 'msg' => '同層已存在相同名稱的資料夾']);
        exit;
    }

    // **如果沒有重名，則新增資料夾**
    $stmt = $pdo->prepare("
        INSERT INTO folders(user_id, parent_folder, folder_name)
        VALUES(?, ?, ?)
    ");
    $ok = $stmt->execute([$_SESSION['user_id'], $parent, $name]);
    if($ok) recordUserActivity($pdo,$action==='create'?'新增資料夾':'重新命名資料夾',$action==='create'?(int)$pdo->lastInsertId():$fid,'名稱：'.$name.'；上層資料夾編號：'.$parent);
    echo json_encode(['success' => $ok]);
}

elseif ($action === 'rename') {
    $fid = (int)($_POST['folder_id'] ?? 0);
    $name = trim($_POST['new_name'] ?? '');
    
    if ($fid <= 0 || $name === '') {
        echo json_encode(['success' => false, 'msg' => '參數錯誤']);
        exit;
    }

    // **取得原始資料夾的 parent_folder**
    $stmtGetParent = $pdo->prepare("
        SELECT parent_folder FROM folders WHERE folder_id = ? AND user_id = ?
    ");
    $stmtGetParent->execute([$fid, $_SESSION['user_id']]);
    $parentData = $stmtGetParent->fetch(PDO::FETCH_ASSOC);
    
    if (!$parentData) {
        echo json_encode(['success' => false, 'msg' => '找不到該資料夾']);
        exit;
    }
    $parent = (int)$parentData['parent_folder'];

    // **檢查新的名稱在同層資料夾是否已存在**
    require_once __DIR__.'/folder_access.php';
    $exists=storageFolderNameExists($pdo,(int)$_SESSION['user_id'],$parent,$name,$fid);

    if ($exists > 0) {
        echo json_encode(['success' => false, 'msg' => '同層已存在相同名稱的資料夾']);
        exit;
    }

    // **如果沒有重名，則更新資料夾名稱**
    $stmt = $pdo->prepare("
        UPDATE folders
        SET folder_name = ?
        WHERE folder_id = ? AND user_id = ?
    ");
    $ok = $stmt->execute([$name, $fid, $_SESSION['user_id']]);
    if($ok) recordUserActivity($pdo,$action==='create'?'新增資料夾':'重新命名資料夾',$action==='create'?(int)$pdo->lastInsertId():$fid,'名稱：'.$name.'；上層資料夾編號：'.$parent);
    echo json_encode(['success' => $ok]);
}

elseif ($action === 'delete') {
    $fid = (int)($_POST['folder_id'] ?? 0);
    if ($fid <= 0) {
        echo json_encode(['success' => false, 'msg' => '參數錯誤']);
        exit;
    }

    require_once __DIR__.'/folder_access.php';
    if(!ownsStorageFolder($pdo,(int)$_SESSION['user_id'],$fid)){
        http_response_code(404);echo json_encode(['success'=>false,'msg'=>'資料夾不存在或沒有權限']);exit;
    }
    // **檢查該資料夾內是否還有子資料夾**
    $stmtCheckSubFolders = $pdo->prepare("
        SELECT COUNT(*) FROM folders WHERE parent_folder = ? AND user_id = ?
    ");
    $stmtCheckSubFolders->execute([$fid, $_SESSION['user_id']]);
    $hasSubFolders = $stmtCheckSubFolders->fetchColumn();

    if ($hasSubFolders > 0) {
        echo json_encode(['success' => false, 'msg' => '無法刪除，資料夾內仍有子資料夾']);
        exit;
    }

    // **檢查該資料夾內是否還有檔案**
    $stmtCheckFiles = $pdo->prepare("
        SELECT COUNT(*) FROM files WHERE folder_id = ? AND user_id = ?
    ");
    $stmtCheckFiles->execute([$fid, $_SESSION['user_id']]);
    $hasFiles = $stmtCheckFiles->fetchColumn();

    if ($hasFiles > 0) {
        echo json_encode(['success' => false, 'msg' => '無法刪除，資料夾內仍有檔案']);
        exit;
    }

    // **如果資料夾為空，才允許刪除**
    $stmt = $pdo->prepare("
        DELETE FROM folders
        WHERE folder_id = ? AND user_id = ?
        LIMIT 1
    ");
    $ok = $stmt->execute([$fid, $_SESSION['user_id']]);
    if($ok && $stmt->rowCount()>0) recordUserActivity($pdo,'刪除資料夾',$fid,'刪除空資料夾');
    echo json_encode(['success' => $ok && $stmt->rowCount()>0]);
}

else {
    echo json_encode(['success' => false, 'msg' => '未知動作']);
}
