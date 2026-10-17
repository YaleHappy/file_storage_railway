<?php

function recallOwnChatFile(PDO $pdo, int $userId, int $fileId): void
{
    if ($userId <= 0 || $fileId <= 0) {
        throw new InvalidArgumentException('無效的檔案操作');
    }
    // Downloads and "add to cloud" require this record and recipient ownership.
    // Remove only this delivery, preserving original files and saved copies.
    $stmt = $pdo->prepare('DELETE FROM shared_files WHERE id = ? AND sender_id = ?');
    $stmt->execute([$fileId, $userId]);
    if ($stmt->rowCount() === 0) {
        throw new OutOfBoundsException('檔案傳送紀錄不存在或你沒有收回權限');
    }
}

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
