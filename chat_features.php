<?php
require_once __DIR__ . '/chat_message_service.php';

function ensureChatFeatures(PDO $pdo): void
{
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    foreach ([
        'chat_event_details (event_type VARCHAR(12) NOT NULL, event_id BIGINT NOT NULL, reply_type VARCHAR(12) DEFAULT NULL, reply_id BIGINT DEFAULT NULL, pinned INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(event_type,event_id))',
        'chat_event_reactions (event_type VARCHAR(12) NOT NULL, event_id BIGINT NOT NULL, user_id INTEGER NOT NULL, emoji VARCHAR(16) NOT NULL, PRIMARY KEY(event_type,event_id,user_id,emoji))'
    ] as $table) $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . $suffix);
}

function privateChatEvent(PDO $pdo, int $userId, string $type, int $id): array
{
    if (!in_array($type, ['message', 'file'], true) || $id <= 0) throw new InvalidArgumentException('無效的訊息');
    $table = $type === 'file' ? 'shared_files' : 'chat_messages';
    $lock = $pdo->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND (sender_id = ? OR recipient_id = ?)" . $lock);
    $stmt->execute([$id, $userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new OutOfBoundsException('訊息不存在或你沒有權限');
    return $row;
}

function setPrivateReply(PDO $pdo, int $userId, int $recipientId, int $messageId, string $replyType, int $replyId): void
{
    $target = privateChatEvent($pdo, $userId, $replyType, $replyId);
    if (!in_array($recipientId, [(int)$target['sender_id'], (int)$target['recipient_id']], true)) throw new OutOfBoundsException('無法回覆其他對話的訊息');
    $stmt = $pdo->prepare("INSERT INTO chat_event_details (event_type,event_id,reply_type,reply_id) VALUES ('message',?,?,?)");
    $stmt->execute([$messageId,$replyType,$replyId]);
}

function privateChatFeatureAction(PDO $pdo, int $userId, string $type, int $id, string $action, string $emoji = ''): void
{
    privateChatEvent($pdo, $userId, $type, $id);
    if ($action === 'react') {
        if (!in_array($emoji, ['👍','❤️','😂','😮','😢','🙏'], true)) throw new InvalidArgumentException('不支援的表情');
        $stmt = $pdo->prepare('SELECT emoji FROM chat_event_reactions WHERE event_type=? AND event_id=? AND user_id=? AND emoji=?');
        $stmt->execute([$type,$id,$userId,$emoji]);
        $sql = $stmt->fetchColumn() !== false ? 'DELETE FROM chat_event_reactions WHERE event_type=? AND event_id=? AND user_id=? AND emoji=?' : 'INSERT INTO chat_event_reactions (event_type,event_id,user_id,emoji) VALUES (?,?,?,?)';
        $pdo->prepare($sql)->execute([$type,$id,$userId,$emoji]);
    } elseif ($action === 'pin') {
        $stmt = $pdo->prepare('SELECT pinned FROM chat_event_details WHERE event_type=? AND event_id=?');
        $stmt->execute([$type,$id]); $value = $stmt->fetchColumn();
        if ($value === false) $pdo->prepare('INSERT INTO chat_event_details (event_type,event_id,pinned) VALUES (?,?,1)')->execute([$type,$id]);
        else $pdo->prepare('UPDATE chat_event_details SET pinned=? WHERE event_type=? AND event_id=?')->execute([(int)!$value,$type,$id]);
    } else throw new InvalidArgumentException('無效的操作');
}

function enrichPrivateChat(PDO $pdo, array $events): array
{
    foreach ($events as &$event) {
        $stmt = $pdo->prepare('SELECT * FROM chat_event_details WHERE event_type=? AND event_id=?');
        $stmt->execute([$event['event_type'],$event['event_id']]); $details = $stmt->fetch(PDO::FETCH_ASSOC);
        $event['pinned'] = (int)($details['pinned'] ?? 0); $event['reply'] = null;
        if (!empty($details['reply_id'])) {
            try {
                $reply = privateChatEvent($pdo, (int)$event['sender_id'], $details['reply_type'], (int)$details['reply_id']);
                if (in_array((int)$event['recipient_id'], [(int)$reply['sender_id'],(int)$reply['recipient_id']], true)) {
                    $event['reply'] = ['event_type'=>$details['reply_type'],'event_id'=>(int)$details['reply_id'],'content'=>$reply['message'] ?? $reply['original_filename'] ?? '檔案'];
                }
            } catch (OutOfBoundsException $error) { /* Recalled target has no quote. */ }
        }
        $stmt = $pdo->prepare('SELECT r.user_id,r.emoji,u.username FROM chat_event_reactions r JOIN users u ON u.user_id=r.user_id WHERE r.event_type=? AND r.event_id=?');
        $stmt->execute([$event['event_type'],$event['event_id']]); $event['reactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $events;
}
