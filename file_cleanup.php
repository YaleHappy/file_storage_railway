<?php

// Successful listing proves absence; is_readable() is unreliable on Windows ACLs.
function uploadRelativeFileIsMissing(string $root, string $relative): bool
{
    if ($relative === '' || strpos($relative, "\0") !== false || strpos($relative, '\\') !== false) return false;
    $parts = explode('/', $relative);
    foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..' || strpos($part, ':') !== false) return false;
    if (!is_dir($root)) return false;
    $directory = rtrim($root, '/\\');
    foreach ($parts as $index => $part) {
        clearstatcache();
        $path = $directory . '/' . $part;
        if (is_link($path)) return false;
        if (file_exists($path)) {
            if ($index === count($parts) - 1) return false;
            if (!is_dir($path)) return false;
            $directory = $path;
            continue;
        }
        $entries = @scandir($directory);
        if ($entries === false || in_array($part, $entries, true)) return false;
        clearstatcache();
        return !file_exists($path) && !is_link($path);
    }
    return false;
}

function uploadFileIsMissing(string $directory, string $filename): bool
{
    if (strpbrk($filename, '/\\') !== false) return false;
    return uploadRelativeFileIsMissing($directory, $filename);
}

function cleanupTableExists(PDO $pdo, string $table): bool
{
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        : 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    $stmt = $pdo->prepare($sql); $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

// Identifiers and scopes are internal constants, never request parameters.
function cleanupMissingRows(PDO $pdo, string $table, string $idColumn, string $pathColumn,
    string $scope, array $params, callable $relativePath, callable $beforeDelete, ?string $uploadRoot = null): int
{
    $root = $uploadRoot ?? __DIR__ . '/uploads';
    if ($pdo->inTransaction() || !is_dir($root) || @scandir($root) === false) return 0;
    $deleted = 0;
    try {
        $stmt = $pdo->prepare("SELECT $idColumn, $pathColumn FROM $table WHERE $scope");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relative = $relativePath((string) $row[$pathColumn]);
            if ($relative === null || !uploadRelativeFileIsMissing($root, $relative)) continue;
            $pdo->beginTransaction();
            try {
                $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $current = $pdo->prepare("SELECT $idColumn, $pathColumn FROM $table WHERE $idColumn = ? AND ($scope)$lock");
                $current->execute(array_merge([$row[$idColumn]], $params));
                $record = $current->fetch(PDO::FETCH_ASSOC);
                $relative = $record ? $relativePath((string) $record[$pathColumn]) : null;
                if ($relative === null || !uploadRelativeFileIsMissing($root, $relative)) { $pdo->rollBack(); continue; }
                $beforeDelete((int) $row[$idColumn]);
                $delete = $pdo->prepare("DELETE FROM $table WHERE $idColumn = ? AND ($scope)");
                $delete->execute(array_merge([$row[$idColumn]], $params));
                $count = $delete->rowCount();
                $pdo->commit();
                $deleted += $count;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        }
    } catch (Throwable $error) { error_log('清理遺失檔案紀錄失敗：' . $error->getMessage()); }
    return $deleted;
}

function cleanupMissingUserFiles(PDO $pdo, int $userId, ?int $fileId = null, ?string $uploadRoot = null): int
{
    if ($userId <= 0) return 0;
    $scope = 'user_id = ?'; $params = [$userId];
    if ($fileId !== null) { $scope .= ' AND file_id = ?'; $params[] = $fileId; }
    return cleanupMissingRows($pdo, 'files', 'file_id', 'stored_filename', $scope, $params,
        fn($path) => strpos($path, '/') === false ? $path : null,
        function ($id) use ($pdo) {
            foreach (['file_shares', 'preview_tokens'] as $table) {
                if (cleanupTableExists($pdo, $table)) $pdo->prepare("DELETE FROM $table WHERE file_id = ?")->execute([$id]);
            }
        }, $uploadRoot);
}

function cleanupMissingSharedFiles(PDO $pdo, int $userId, ?int $fileId = null, ?string $uploadRoot = null): int
{
    if ($userId <= 0) return 0;
    $scope = '(sender_id = ? OR recipient_id = ?)'; $params = [$userId, $userId];
    if ($fileId !== null) { $scope .= ' AND id = ?'; $params[] = $fileId; }
    return cleanupMissingRows($pdo, 'shared_files', 'id', 'file_path', $scope, $params,
        fn($path) => preg_match('~^uploads/(shared|sent)/[^/]+$~D', $path) ? substr($path, 8) : null,
        function ($id) {}, $uploadRoot);
}

function cleanupMissingGroupFiles(PDO $pdo, int $groupId, int $userId, ?string $uploadRoot = null): int
{
    if ($groupId <= 0 || $userId <= 0) return 0;
    $scope = "group_id = ? AND kind = 'file' AND recalled_at IS NULL
        AND EXISTS (SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ?)";
    return cleanupMissingRows($pdo, 'chat_group_messages', 'id', 'file_path', $scope, [$groupId, $groupId, $userId],
        fn($path) => preg_match('/^[a-f0-9]{48}\.bin$/D', $path) ? '.group-files/' . $path : null,
        function ($id) use ($pdo) {
            $pdo->prepare('DELETE FROM notifications WHERE id IN (SELECT notification_id FROM chat_group_notification_links WHERE message_id = ?)')->execute([$id]);
            foreach (['chat_group_mentions', 'chat_group_reactions', 'chat_group_notification_links'] as $table) {
                $pdo->prepare("DELETE FROM $table WHERE message_id = ?")->execute([$id]);
            }
        }, $uploadRoot);
}
