<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(json_encode(['success'=>false,'msg'=>'請先登入'])); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/group_service.php';
try {
    $user = (int)$_SESSION['user_id'];
    $query = trim((string)($_GET['q'] ?? ''));
    if ($query === '' || mb_strlen($query,'UTF-8') > 200) throw new InvalidArgumentException('請輸入 1 到 200 字的搜尋文字');
    $term = '%' . str_replace(['!','%','_'],['!!','!%','!_'],$query) . '%';
    $before = max(0,(int)($_GET['before'] ?? 0));
    $offset = max(0,min(100000,(int)($_GET['offset'] ?? 0)));
    $group = (int)($_GET['group_id'] ?? 0);
    $peer = (int)($_GET['user_id'] ?? 0);
    if ($group) {
        ensureGroupSchema($pdo); groupMember($pdo,$group,$user); cleanupMissingGroupFiles($pdo,$group,$user);
        $beforeClause = $before > 0 ? ' AND m.id < ' . $before : '';
        $stmt = $pdo->prepare("SELECT m.id, m.kind AS event_type, m.sender_id, m.content, m.original_filename, m.created_at AS event_at,u.username
            FROM chat_group_messages m JOIN users u ON u.user_id=m.sender_id
            WHERE m.group_id=? AND m.recalled_at IS NULL $beforeClause
            AND (m.content LIKE ? ESCAPE '!' OR m.original_filename LIKE ? ESCAPE '!') ORDER BY m.id DESC LIMIT 51");
        $stmt->execute([$group,$term,$term]);
    } else {
        if ($peer <= 0 || $peer === $user) throw new InvalidArgumentException('請先選擇聊天對象');
        cleanupMissingSharedFiles($pdo,$user);
        $stmt = $pdo->prepare("SELECT t.*,u.username FROM (
            SELECT id,'message' AS event_type,sender_id,recipient_id,message AS content,NULL AS original_filename,sent_at AS event_at FROM chat_messages
            UNION ALL SELECT id,'file',sender_id,recipient_id,NULL,original_filename,sent_at FROM shared_files
            ) t JOIN users u ON u.user_id=t.sender_id
            WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))
            AND (content LIKE ? ESCAPE '!' OR original_filename LIKE ? ESCAPE '!') ORDER BY event_at DESC,id DESC,event_type DESC LIMIT 51 OFFSET $offset");
        $stmt->execute([$user,$peer,$peer,$user,$term,$term]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); $more = count($rows)>50;
    if ($more) array_pop($rows);
    echo json_encode(['success'=>true,'results'=>$rows,'has_more'=>$more,'next_offset'=>$offset+count($rows),'next_before'=>$rows ? (int)end($rows)['id'] : 0],JSON_UNESCAPED_UNICODE);
} catch (OutOfBoundsException|DomainException $error) {
    http_response_code(403); echo json_encode(['success'=>false,'msg'=>'無法存取此群組']);
} catch (InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['success'=>false,'msg'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('聊天搜尋失敗：'.$error->getMessage()); http_response_code(500); echo json_encode(['success'=>false,'msg'=>'搜尋失敗，請稍後重試']);
}
