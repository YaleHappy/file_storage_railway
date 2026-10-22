<?php
require_once __DIR__ . '/group_schema.php';
require_once __DIR__ . '/file_cleanup.php';
require_once __DIR__ . '/cloud_storage.php';
require_once __DIR__ . '/account_access.php';

function groupQuery(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function groupMember(PDO $pdo, int $groupId, int $userId): array
{
    $row = groupQuery($pdo, 'SELECT g.*, m.role, m.notification_mode, m.last_read_id
        FROM chat_groups g JOIN chat_group_members m ON m.group_id = g.id
        WHERE g.id = ? AND m.user_id = ?', [$groupId, $userId])->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new OutOfBoundsException('群組不存在，或你已不在此群組');
    return $row;
}

function groupAdmin(array $member): void
{
    if ($member['role'] !== 'admin') throw new DomainException('只有群組管理員能操作');
}

function groupText(string $text, int $limit, string $label, bool $allowEmpty = false): string
{
    $text = trim($text);
    if ((!$allowEmpty && $text === '') || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > $limit) {
        throw new InvalidArgumentException("$label 必須為" . ($allowEmpty ? ' 0' : ' 1') . " 到 $limit 個字");
    }
    return $text;
}

function groupList(PDO $pdo, int $userId): array
{
    $ids = groupQuery($pdo, 'SELECT group_id FROM chat_group_members WHERE user_id = ?', [$userId])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) cleanupMissingGroupFiles($pdo, (int) $id, $userId);
    return groupQuery($pdo, "SELECT g.id, g.name, g.created_at, m.notification_mode,
        (SELECT COUNT(*) FROM chat_group_members mm WHERE mm.group_id = g.id) AS member_count,
        (SELECT COUNT(*) FROM chat_group_messages msg WHERE msg.group_id = g.id AND msg.id > m.last_read_id
            AND msg.sender_id <> m.user_id AND msg.recalled_at IS NULL) AS unread_count,
        (SELECT CASE WHEN msg.recalled_at IS NOT NULL THEN '已收回訊息' WHEN msg.kind = 'file'
            THEN msg.original_filename ELSE msg.content END FROM chat_group_messages msg
            WHERE msg.group_id = g.id AND msg.recalled_at IS NULL ORDER BY msg.id DESC LIMIT 1) AS last_content,
        (SELECT MAX(id) FROM chat_group_messages WHERE group_id = g.id AND recalled_at IS NULL) AS last_message_id,
        COALESCE((SELECT MAX(created_at) FROM chat_group_messages WHERE group_id = g.id AND recalled_at IS NULL), g.created_at) AS last_event_at
        FROM chat_groups g JOIN chat_group_members m ON m.group_id = g.id WHERE m.user_id = ?
        ORDER BY last_event_at DESC, g.id DESC", [$userId])->fetchAll(PDO::FETCH_ASSOC);
}

function createChatGroup(PDO $pdo, int $userId, string $name, array $members): int
{
    $name = groupText($name, 80, '群組名稱');
    $ids = array_values(array_unique(array_merge([$userId], array_map('intval', $members))));
    if (count($ids) < 2 || count($ids) > 100 || min($ids) <= 0) throw new InvalidArgumentException('請選擇 1 到 99 位其他成員');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $count = groupQuery($pdo, "SELECT COUNT(*) FROM users WHERE user_id IN ($placeholders)", $ids)->fetchColumn();
    if ((int) $count !== count($ids)) throw new InvalidArgumentException('部分使用者不存在');
    $pdo->beginTransaction();
    try {
        groupQuery($pdo, "INSERT INTO chat_groups (name, owner_id, announcement) VALUES (?, ?, '')", [$name, $userId]);
        $groupId = (int) $pdo->lastInsertId();
        foreach ($ids as $id) {
            groupQuery($pdo, 'INSERT INTO chat_group_members (group_id, user_id, role) VALUES (?, ?, ?)',
                [$groupId, $id, $id === $userId ? 'admin' : 'member']);
            if ($id !== $userId) groupInviteNotification($pdo, $groupId, $userId, $id, $name);
        }
        $pdo->commit();
        return $groupId;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function groupInviteNotification(PDO $pdo, int $groupId, int $senderId, int $recipientId, string $name): void
{
    if ($senderId === $recipientId) return;
    groupQuery($pdo, "INSERT INTO notifications (sender_id, recipient_id, type, message) VALUES (?, ?, 'system', ?)",
        [$senderId, $recipientId, '你已加入群組「' . $name . '」']);
    groupQuery($pdo, 'INSERT INTO chat_group_notification_links (notification_id, group_id, message_id) VALUES (?, ?, 0)', [(int) $pdo->lastInsertId(), $groupId]);
}

function groupMessage(PDO $pdo, int $groupId, int $messageId): array
{
    $message = groupQuery($pdo, 'SELECT * FROM chat_group_messages WHERE group_id = ? AND id = ?', [$groupId, $messageId])->fetch(PDO::FETCH_ASSOC);
    if (!$message || $message['recalled_at']) throw new OutOfBoundsException('訊息不存在或已收回');
    return $message;
}

function groupResolveMentions(PDO $pdo, int $groupId, string $content, array $input = []): array
{
    $people = groupQuery($pdo, 'SELECT u.user_id, u.username FROM users u JOIN chat_group_members m ON m.user_id = u.user_id WHERE m.group_id = ?', [$groupId])->fetchAll(PDO::FETCH_ASSOC);
    $all = array_map('intval', array_column($people, 'user_id'));
    $ids = array_map('intval', (array) ($input['mentions'] ?? []));
    foreach ($people as $person) {
        if (preg_match('/(?<![\p{L}\p{N}_@])@' . preg_quote($person['username'], '/') . '(?=$|[\s，。！？,.!?：:；;])/u', $content)) $ids[] = (int) $person['user_id'];
    }
    if (!empty($input['mention_all']) || preg_match('/(?<![\p{L}\p{N}_@])@(所有人|all|everyone)(?=$|[\s，。！？,.!?：:；;])/iu', $content)) $ids = $all;
    if (array_diff($ids, $all)) throw new InvalidArgumentException('只能提及目前群組成員');
    return array_values(array_unique($ids));
}

function groupNotify(PDO $pdo, int $groupId, int $messageId, int $senderId, array $mentions, ?array $onlyRecipients = null): void
{
    $members = groupQuery($pdo, 'SELECT user_id, notification_mode FROM chat_group_members WHERE group_id = ?', [$groupId])->fetchAll(PDO::FETCH_ASSOC);
    $name = groupQuery($pdo, 'SELECT name FROM chat_groups WHERE id = ?', [$groupId])->fetchColumn();
    $sender = groupQuery($pdo, 'SELECT username FROM users WHERE user_id = ?', [$senderId])->fetchColumn();
    foreach ($members as $member) {
        $id = (int) $member['user_id'];
        if ($onlyRecipients !== null && !in_array($id, $onlyRecipients, true)) continue;
        $mentioned = in_array($id, $mentions, true);
        if ($id === $senderId || $member['notification_mode'] === 'mute'
            || ($member['notification_mode'] === 'mentions' && !$mentioned)) continue;
        $label = '群組「' . $name . '」：' . $sender . ($mentioned ? ' 提及了你' : ' 傳送了新訊息');
        groupQuery($pdo, "INSERT INTO notifications (sender_id, recipient_id, type, message) VALUES (?, ?, 'message', ?)", [$senderId, $id, $label]);
        $notificationId = (int) $pdo->lastInsertId();
        groupQuery($pdo, 'INSERT INTO chat_group_notification_links (notification_id, group_id, message_id) VALUES (?, ?, ?)', [$notificationId, $groupId, $messageId]);
    }
}

// Store one private file per group message; downloads always require membership.
function groupStorageDirectory(): string { return __DIR__ . '/uploads/.group-files'; }

function groupPrivateFile(string $filename): string
{
    if (!preg_match('/^[a-f0-9]{48}\.bin$/D', $filename)) throw new RuntimeException('無效的檔案路徑');
    return groupStorageDirectory() . '/' . $filename;
}

function groupStoreFile(array $upload, ?string $cloudSource = null): array
{
    $name = groupText(basename(str_replace('\\', '/', (string) ($upload['name'] ?? ''))), 255, '檔案名稱');
    $size = $cloudSource !== null ? filesize($cloudSource) : (int) ($upload['size'] ?? 0);
    if ($cloudSource === null && (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('檔案上傳失敗，請檢查大小限制並重試');
    }
    if ($size === false || $size < 0 || $size > 100 * 1024 * 1024) throw new InvalidArgumentException('檔案不可超過 100MB');
    $directory = groupStorageDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('無法建立儲存目錄');
    // XAMPP/local Apache needs the same direct-access protection as Docker.
    if (!is_file($directory . '/.htaccess') && file_put_contents($directory . '/.htaccess', "Require all denied\n", LOCK_EX) === false) {
        throw new RuntimeException('無法設定群組檔案存取保護');
    }
    $filename = bin2hex(random_bytes(24)) . '.bin';
    $target = groupPrivateFile($filename);
    $ok = $cloudSource !== null ? copy($cloudSource, $target) : move_uploaded_file($upload['tmp_name'], $target);
    if (!$ok) throw new RuntimeException('無法儲存檔案，請檢查容量與權限');
    return ['name' => $name, 'size' => $size, 'path' => $filename];
}

function groupRemoveMessageNotifications(PDO $pdo, int $messageId): void
{
    groupQuery($pdo, 'DELETE FROM notifications WHERE id IN (SELECT notification_id FROM chat_group_notification_links WHERE message_id = ?)', [$messageId]);
    groupQuery($pdo, 'DELETE FROM chat_group_notification_links WHERE message_id = ?', [$messageId]);
}

function groupAction(PDO $pdo, int $groupId, int $userId, string $action, array $input, ?array $upload = null): array
{
    cleanupMissingGroupFiles($pdo, $groupId, $userId);
    $storedFile = null;
    $cloudCopy = null;
    $recalledFile = null;
    $dissolvedFiles = [];
    $pdo->beginTransaction();
    try {
        // Serialize membership changes, sends and downloads against the group.
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        groupQuery($pdo, 'SELECT id FROM chat_groups WHERE id = ?' . $lock, [$groupId])->fetchColumn();
        $member = groupMember($pdo, $groupId, $userId);
        if(in_array($action,['send','file'],true) && storageGroupPaused($pdo,$groupId)) throw new DomainException('此群組已由管理員暫停傳送訊息與檔案');
        $messageId = (int) ($input['message_id'] ?? 0);
        switch ($action) {
            case 'send':
            case 'file':
                $content = groupText((string) ($input['content'] ?? ''), 2000, '訊息', $action === 'file');
                $replyId = (int) ($input['reply_to_id'] ?? 0);
                if ($replyId) groupMessage($pdo, $groupId, $replyId);
                $mentions = groupResolveMentions($pdo, $groupId, $content, $input);
                if ($action === 'file') {
                    $cloudId = (int) ($input['cloud_file_id'] ?? 0);
                    if ($cloudId) {
                        $cloud = groupQuery($pdo, 'SELECT original_filename, stored_filename FROM files WHERE file_id = ? AND user_id = ?', [$cloudId, $userId])->fetch(PDO::FETCH_ASSOC);
                        if (!$cloud || basename($cloud['stored_filename']) !== $cloud['stored_filename']) throw new OutOfBoundsException('找不到你的雲端檔案');
                        $source = __DIR__ . '/uploads/' . $cloud['stored_filename'];
                        if (!is_file($source)) throw new OutOfBoundsException('雲端檔案已遺失');
                        $storedFile = groupStoreFile(['name' => $cloud['original_filename']], $source);
                    } else {
                        $storedFile = groupStoreFile($upload ?? []);
                    }
                }
                groupQuery($pdo, 'INSERT INTO chat_group_messages (group_id, sender_id, kind, content, reply_to_id, file_path, original_filename, file_size)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$groupId, $userId, $action === 'file' ? 'file' : 'text', $content, $replyId ?: null,
                    $storedFile['path'] ?? null, $storedFile['name'] ?? null, $storedFile['size'] ?? 0]);
                $messageId = (int) $pdo->lastInsertId();
                foreach ($mentions as $id) groupQuery($pdo, 'INSERT INTO chat_group_mentions (message_id, user_id) VALUES (?, ?)', [$messageId, $id]);
                groupNotify($pdo, $groupId, $messageId, $userId, $mentions);
                break;
            case 'edit':
            case 'recall':
                $message = groupMessage($pdo, $groupId, $messageId);
                if ((int) $message['sender_id'] !== $userId) throw new DomainException('只能編輯或收回自己的訊息');
                if ($action === 'edit') {
                    if ($message['kind'] !== 'text') throw new InvalidArgumentException('檔案無法編輯');
                    $content = groupText((string) ($input['content'] ?? ''), 2000, '訊息');
                    $oldMentions = array_map('intval', groupQuery($pdo, 'SELECT user_id FROM chat_group_mentions WHERE message_id = ?', [$messageId])->fetchAll(PDO::FETCH_COLUMN));
                    $mentions = groupResolveMentions($pdo, $groupId, $content);
                    groupQuery($pdo, 'UPDATE chat_group_messages SET content = ?, edited_at = CURRENT_TIMESTAMP WHERE id = ?', [$content, $messageId]);
                    groupQuery($pdo, 'DELETE FROM chat_group_mentions WHERE message_id = ?', [$messageId]);
                    foreach ($mentions as $id) groupQuery($pdo, 'INSERT INTO chat_group_mentions (message_id, user_id) VALUES (?, ?)', [$messageId, $id]);
                    groupNotify($pdo, $groupId, $messageId, $userId, $mentions, array_values(array_diff($mentions, $oldMentions)));
                } else {
                    $recalledFile = $message['kind'] === 'file' ? $message['file_path'] : null;
                    groupQuery($pdo, 'UPDATE chat_group_messages SET reply_to_id = NULL WHERE group_id = ? AND reply_to_id = ?', [$groupId, $messageId]);
                    groupQuery($pdo, 'DELETE FROM chat_group_reactions WHERE message_id = ?', [$messageId]);
                    groupQuery($pdo, 'DELETE FROM chat_group_mentions WHERE message_id = ?', [$messageId]);
                    groupRemoveMessageNotifications($pdo, $messageId);
                    groupQuery($pdo, 'DELETE FROM chat_group_messages WHERE id = ? AND group_id = ?', [$messageId, $groupId]);
                }
                break;
            case 'react':
                groupMessage($pdo, $groupId, $messageId);
                $emoji = (string) ($input['emoji'] ?? '');
                if (!in_array($emoji, ['👍', '❤️', '😂', '😮', '😢', '🙏'], true)) throw new InvalidArgumentException('不支援的表情');
                $existing = groupQuery($pdo, 'SELECT emoji FROM chat_group_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?', [$messageId, $userId, $emoji])->fetchColumn();
                if ($existing !== false) groupQuery($pdo, 'DELETE FROM chat_group_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?', [$messageId, $userId, $emoji]);
                else groupQuery($pdo, 'INSERT INTO chat_group_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)', [$messageId, $userId, $emoji]);
                break;
            case 'save_file':
                $message = groupMessage($pdo, $groupId, $messageId);
                if ($message['kind'] !== 'file') throw new InvalidArgumentException('不是檔案訊息');
                assertCloudNameAvailable($pdo, $userId, $message['original_filename']);
                $source = groupPrivateFile($message['file_path']);
                if (!is_file($source)) throw new OutOfBoundsException('原始檔案已遺失');
                $copyName = bin2hex(random_bytes(24)) . '.bin';
                $copyPath = __DIR__ . '/uploads/' . $copyName;
                if (!copy($source, $copyPath)) throw new RuntimeException('無法另存檔案');
                $cloudCopy = $copyPath;
                try {
                    groupQuery($pdo, 'INSERT INTO files (user_id, folder_id, original_filename, stored_filename, file_size) VALUES (?, 0, ?, ?, ?)',
                        [$userId, $message['original_filename'], $copyName, $message['file_size']]);
                } catch (Throwable $error) {
                    unlink($copyPath);
                    $cloudCopy = null;
                    throw $error;
                }
                break;
            case 'pin':
                groupAdmin($member);
                $message = groupMessage($pdo, $groupId, $messageId);
                groupQuery($pdo, 'UPDATE chat_group_messages SET pinned = ? WHERE id = ?', [$message['pinned'] ? 0 : 1, $messageId]);
                break;
            case 'settings':
            case 'save_settings':
                if ($action === 'settings' || $member['role'] === 'admin') groupAdmin($member);
                $name = groupText((string) ($input['name'] ?? $member['name']), 80, '群組名稱');
                $announcement = groupText((string) ($input['announcement'] ?? ''), 2000, '公告', true);
                if ($action === 'settings' || $member['role'] === 'admin') groupQuery($pdo, 'UPDATE chat_groups SET name = ?, announcement = ? WHERE id = ?', [$name, $announcement, $groupId]);
                if ($action === 'save_settings') {
                    $mode = (string)($input['mode'] ?? '');
                    if (!in_array($mode, ['all','mentions','mute'], true)) throw new InvalidArgumentException('無效的通知設定');
                    groupQuery($pdo, 'UPDATE chat_group_members SET notification_mode = ? WHERE group_id = ? AND user_id = ?', [$mode,$groupId,$userId]);
                }
                break;
            case 'transfer_owner':
                if ((int)$member['owner_id'] !== $userId) throw new DomainException('只有群主可以轉移群主');
                $target = (int)($input['user_id'] ?? 0);
                if ($target === $userId || !groupQuery($pdo, 'SELECT user_id FROM chat_group_members WHERE group_id=? AND user_id=?', [$groupId,$target])->fetchColumn()) throw new InvalidArgumentException('請選擇其他群組成員');
                groupQuery($pdo, "UPDATE chat_group_members SET role='member' WHERE group_id=? AND user_id=?", [$groupId,$userId]);
                groupQuery($pdo, "UPDATE chat_group_members SET role='admin' WHERE group_id=? AND user_id=?", [$groupId,$target]);
                groupQuery($pdo, 'UPDATE chat_groups SET owner_id=? WHERE id=?', [$target,$groupId]);
                break;
            case 'dissolve':
                if ((int)$member['owner_id'] !== $userId) throw new DomainException('只有群主可以解散群組');
                $dissolvedFiles = groupQuery($pdo, "SELECT file_path FROM chat_group_messages WHERE group_id=? AND kind='file'", [$groupId])->fetchAll(PDO::FETCH_COLUMN);
                foreach (['chat_group_mentions','chat_group_reactions'] as $table) groupQuery($pdo, "DELETE FROM $table WHERE message_id IN (SELECT id FROM chat_group_messages WHERE group_id=?)", [$groupId]);
                groupQuery($pdo, 'DELETE FROM notifications WHERE id IN (SELECT notification_id FROM chat_group_notification_links WHERE group_id=?)', [$groupId]);
                groupQuery($pdo, 'DELETE FROM chat_group_notification_links WHERE group_id=?', [$groupId]);
                groupQuery($pdo, 'DELETE FROM chat_group_messages WHERE group_id=?', [$groupId]);
                groupQuery($pdo, 'DELETE FROM chat_group_members WHERE group_id=?', [$groupId]);
                groupQuery($pdo, 'DELETE FROM chat_groups WHERE id=?', [$groupId]);
                if(cleanupTableExists($pdo,'admin_group_state')) groupQuery($pdo,'DELETE FROM admin_group_state WHERE group_id=?',[$groupId]);
                break;
            case 'preference':
                $mode = (string) ($input['mode'] ?? '');
                if (!in_array($mode, ['all', 'mentions', 'mute'], true)) throw new InvalidArgumentException('無效的通知設定');
                groupQuery($pdo, 'UPDATE chat_group_members SET notification_mode = ? WHERE group_id = ? AND user_id = ?', [$mode, $groupId, $userId]);
                break;
            case 'add_member':
                groupAdmin($member);
                $id = (int) ($input['user_id'] ?? 0);
                if (!groupQuery($pdo, 'SELECT user_id FROM users WHERE user_id = ?', [$id])->fetchColumn()) throw new InvalidArgumentException('使用者不存在');
                if ((int) groupQuery($pdo, 'SELECT COUNT(*) FROM chat_group_members WHERE group_id = ?', [$groupId])->fetchColumn() >= 100) throw new InvalidArgumentException('群組最多 100 人');
                if (!groupQuery($pdo, 'SELECT user_id FROM chat_group_members WHERE group_id = ? AND user_id = ?', [$groupId, $id])->fetchColumn()) {
                    groupQuery($pdo, 'INSERT INTO chat_group_members (group_id, user_id) VALUES (?, ?)', [$groupId, $id]);
                    groupInviteNotification($pdo, $groupId, $userId, $id, $member['name']);
                }
                break;
            case 'remove_member':
            case 'leave':
                if ($action === 'remove_member') groupAdmin($member);
                $id = $action === 'leave' ? $userId : (int) ($input['user_id'] ?? 0);
                if ((int) $member['owner_id'] === $id) throw new DomainException('請先轉移群主再退出，或選擇解散群組');
                if (!groupQuery($pdo, 'SELECT user_id FROM chat_group_members WHERE group_id=? AND user_id=?', [$groupId,$id])->fetchColumn()) throw new OutOfBoundsException('成員已不在群組');
                groupQuery($pdo, 'DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?', [$groupId, $id]);
                groupQuery($pdo, 'DELETE FROM notifications WHERE recipient_id = ? AND id IN (SELECT notification_id FROM chat_group_notification_links WHERE group_id = ?)', [$id, $groupId]);
                break;
            case 'read':
                $max = (int) groupQuery($pdo, 'SELECT COALESCE(MAX(id), 0) FROM chat_group_messages WHERE group_id = ?', [$groupId])->fetchColumn();
                $seen = max(0, min($max, (int) ($input['last_id'] ?? 0)));
                groupQuery($pdo, 'UPDATE chat_group_members SET last_read_id = CASE WHEN last_read_id < ? THEN ? ELSE last_read_id END WHERE group_id = ? AND user_id = ?', [$seen, $seen, $groupId, $userId]);
                groupQuery($pdo, 'UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND id IN
                    (SELECT notification_id FROM chat_group_notification_links WHERE group_id = ? AND message_id <= ?)', [$userId, $groupId, $seen]);
                break;
            default: throw new InvalidArgumentException('無效的群組操作');
        }
        $pdo->commit();
        foreach ($dissolvedFiles as $file) {
            if (!is_string($file) || !preg_match('/^[a-f0-9]{48}\.bin$/D', $file)) continue;
            $path = groupPrivateFile($file);
            if (is_file($path) && !@unlink($path)) error_log('群組已解散，但附件副本無法刪除');
        }
        if ($recalledFile) {
            $path = groupPrivateFile($recalledFile);
            if (is_file($path) && !@unlink($path)) error_log('群組檔案已收回，但實體副本無法刪除');
        }
        return ['message_id' => $messageId];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($storedFile && is_file(groupPrivateFile($storedFile['path']))) unlink(groupPrivateFile($storedFile['path']));
        if ($cloudCopy && is_file($cloudCopy)) unlink($cloudCopy);
        throw $error;
    }
}

function groupSnapshot(PDO $pdo, int $groupId, int $userId, int $before = 0): array
{
    $group = groupMember($pdo, $groupId, $userId);
    $group['paused'] = storageGroupPaused($pdo,$groupId);
    cleanupMissingGroupFiles($pdo, $groupId, $userId);
    $members = groupQuery($pdo, 'SELECT m.user_id, m.role, m.last_read_id, u.username FROM chat_group_members m
        JOIN users u ON u.user_id = m.user_id WHERE m.group_id = ? ORDER BY m.role, u.username', [$groupId])->fetchAll(PDO::FETCH_ASSOC);
    $sql = 'SELECT m.*, u.username FROM chat_group_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.group_id = ? AND m.recalled_at IS NULL';
    $params = [$groupId];
    if ($before > 0) { $sql .= ' AND m.id < ?'; $params[] = $before; }
    $messages = array_reverse(groupQuery($pdo, $sql . ' ORDER BY m.id DESC LIMIT 80', $params)->fetchAll(PDO::FETCH_ASSOC));
    $ids = array_map('intval', array_column($messages, 'id'));
    $reactions = [];
    $mentions = [];
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = groupQuery($pdo, "SELECT r.*, u.username FROM chat_group_reactions r JOIN users u ON u.user_id = r.user_id WHERE r.message_id IN ($marks)", $ids)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $reactions[$row['message_id']][] = $row;
        $rows = groupQuery($pdo, "SELECT r.message_id, r.user_id, u.username FROM chat_group_mentions r JOIN users u ON u.user_id = r.user_id WHERE r.message_id IN ($marks)", $ids)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $mentions[$row['message_id']][] = $row;
    }
    foreach ($messages as &$message) {
        unset($message['file_path']);
        if ($message['recalled_at']) { $message['content'] = ''; $message['original_filename'] = null; }
        $message['reactions'] = $message['recalled_at'] ? [] : ($reactions[$message['id']] ?? []);
        $message['mentions'] = $message['recalled_at'] ? [] : ($mentions[$message['id']] ?? []);
        $message['read_by'] = (int)$message['sender_id'] === $userId
            ? array_values(array_filter($members, fn($m) => (int) $m['user_id'] !== (int) $message['sender_id'] && (int) $m['last_read_id'] >= (int) $message['id']))
            : [];
        $message['reply'] = null;
        if ($message['reply_to_id']) {
            $reply = groupQuery($pdo, 'SELECT m.id, m.content, m.original_filename, m.recalled_at, u.username FROM chat_group_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.id = ? AND m.group_id = ?', [$message['reply_to_id'], $groupId])->fetch(PDO::FETCH_ASSOC);
            if ($reply && $reply['recalled_at']) $reply = null;
            $message['reply'] = $reply ?: null;
        }
    }
    unset($message);
    $pins = groupQuery($pdo, 'SELECT id, content, original_filename FROM chat_group_messages WHERE group_id = ? AND pinned = 1 AND recalled_at IS NULL ORDER BY id DESC', [$groupId])->fetchAll(PDO::FETCH_ASSOC);
    $oldest = $messages ? (int) $messages[0]['id'] : 0;
    $hasOlder = $oldest > 0 && (bool) groupQuery($pdo, 'SELECT id FROM chat_group_messages WHERE group_id = ? AND recalled_at IS NULL AND id < ? LIMIT 1', [$groupId, $oldest])->fetchColumn();
    foreach ($members as &$member) unset($member['last_read_id']);
    unset($member);
    foreach ($messages as &$message) {
        foreach ($message['read_by'] as &$reader) unset($reader['last_read_id']);
        unset($reader);
    }
    unset($message);
    return ['group' => $group, 'members' => $members, 'messages' => $messages, 'pins' => $pins, 'has_older' => $hasOlder];
}
