<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/folder_access.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'msg'=>'請先登入']); exit; }
$userId = (int)$_SESSION['user_id'];
$query = trim((string)($_GET['q'] ?? ''));
$scope = ($_GET['scope'] ?? '') === 'folder' ? 'folder' : 'cloud';
$folderId = max(0, (int)($_GET['folder'] ?? 0));
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
if ($query === '' || mb_strlen($query) > 200) { http_response_code(400); echo json_encode(['success'=>false,'msg'=>'請輸入 1–200 字的檔案或資料夾名稱']); exit; }
if ($scope === 'folder' && !ownsStorageFolder($pdo, $userId, $folderId)) { http_response_code(403); echo json_encode(['success'=>false,'msg'=>'資料夾不存在或沒有權限']); exit; }
try {
    $pattern = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $query) . '%';
    $fileScope = $scope === 'folder' ? ' AND folder_id = ?' : '';
    $folderScope = $scope === 'folder' ? ' AND parent_folder = ?' : '';
    $sql = "SELECT file_id AS id, original_filename AS name, 'file' AS kind, COALESCE(folder_id,0) AS parent_id, file_size AS bytes, created_at AS updated_at FROM files WHERE user_id = ? AND original_filename LIKE ? ESCAPE '!'$fileScope
        UNION ALL SELECT folder_id AS id, folder_name AS name, 'folder' AS kind, COALESCE(parent_folder,0) AS parent_id, 0 AS bytes, NULL AS updated_at FROM folders WHERE user_id = ? AND folder_name LIKE ? ESCAPE '!'$folderScope";
    $params = [$userId, $pattern]; if ($scope === 'folder') $params[] = $folderId;
    $params[] = $userId; $params[] = $pattern; if ($scope === 'folder') $params[] = $folderId;
    $count = $pdo->prepare("SELECT COUNT(*) FROM ($sql) matches"); $count->execute($params); $total = (int)$count->fetchColumn();
    $page = min($page, max(1,(int)ceil($total / 30)));
    $offset = ($page - 1) * 30;
    $stmt = $pdo->prepare("SELECT * FROM ($sql) matches ORDER BY kind DESC, name, id LIMIT 30 OFFSET $offset");
    $stmt->execute($params); $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $folders = $pdo->prepare('SELECT folder_id, folder_name, parent_folder FROM folders WHERE user_id = ?'); $folders->execute([$userId]);
    $map = []; foreach($folders->fetchAll(PDO::FETCH_ASSOC) as $folder) $map[(int)$folder['folder_id']] = $folder;
    foreach($results as &$result) {
        $trail = []; $seen = []; $id = (int)$result['parent_id'];
        while($id && isset($map[$id]) && !isset($seen[$id])) { $seen[$id] = true; array_unshift($trail,$map[$id]['folder_name']); $id = (int)$map[$id]['parent_folder']; }
        $result['path'] = '我的雲端' . ($trail ? ' / ' . implode(' / ',$trail) : '');
        $result['id'] = (int)$result['id']; $result['parent_id'] = (int)$result['parent_id']; $result['bytes'] = (int)$result['bytes'];
    }
    unset($result);
    echo json_encode(['success'=>true,'results'=>$results,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/30)], JSON_UNESCAPED_UNICODE);
} catch(Throwable $error) { error_log('Cloud search failed: '.$error->getMessage()); http_response_code(500); echo json_encode(['success'=>false,'msg'=>'搜尋暫時無法使用，請稍後重試']); }
