<?php

function changeOwnChatMessage(PDO $pdo, int $userId, int $messageId, string $action, string $message = ''): void
{
    if ($userId <= 0 || $messageId <= 0 || !in_array($action, ['edit', 'recall'], true)) {
        throw new InvalidArgumentException('無效的訊息操作');
    }
    $message = trim($message);
    if ($action === 'edit' && ($message === '' || mb_strlen($message, 'UTF-8') > 2000)) {
        throw new InvalidArgumentException('訊息必須為 1 到 2000 個字');
    }
    // Both mutations enforce ownership in SQL, regardless of client input.
    if ($action === 'recall') {
        $stmt = $pdo->prepare('DELETE FROM chat_messages WHERE id = ? AND sender_id = ?');
        $stmt->execute([$messageId, $userId]);
    } else {
        $stmt = $pdo->prepare('UPDATE chat_messages SET message = ? WHERE id = ? AND sender_id = ?');
        $stmt->execute([$message, $messageId, $userId]);
    }
    if ($stmt->rowCount() === 0) {
        // MySQL reports zero affected rows when an edit has identical content.
        $exists = $pdo->prepare('SELECT id FROM chat_messages WHERE id = ? AND sender_id = ?');
        $exists->execute([$messageId, $userId]);
        if ($action === 'recall' || !$exists->fetchColumn()) {
            throw new OutOfBoundsException('訊息不存在或你沒有操作權限');
        }
    }
}
