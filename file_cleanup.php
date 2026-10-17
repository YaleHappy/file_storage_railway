<?php

// Only a successful directory listing can confirm absence. Permission errors
// and unavailable storage must not trigger database deletion.
function uploadFileIsMissing(string $directory, string $filename): bool
{
    if ($filename === '' || $filename === '.' || $filename === '..'
        || strpbrk($filename, "/\\\0") !== false) {
        return false;
    }
    clearstatcache();
    if (!is_dir($directory) || !is_readable($directory)) {
        return false;
    }
    if (file_exists($directory . '/' . $filename)) {
        return false;
    }
    $entries = @scandir($directory);
    if ($entries === false || in_array($filename, $entries, true)) {
        return false;
    }
    // Recheck immediately before treating the file as missing.
    return !file_exists($directory . '/' . $filename);
}

function cleanupMissingUserFiles(PDO $pdo, int $userId): int
{
    $directory = __DIR__ . '/uploads';
    if ($userId <= 0 || !is_dir($directory) || !is_readable($directory)) {
        return 0;
    }
    $deleted = 0;
    try {
        $stmt = $pdo->prepare('SELECT file_id, stored_filename FROM files WHERE user_id = ?');
        $stmt->execute([$userId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasPreviewTable = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'preview_tokens'")->fetchColumn() > 0;

        foreach ($files as $file) {
            if (!uploadFileIsMissing($directory, $file['stored_filename'])) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT stored_filename FROM files WHERE file_id = ? AND user_id = ? FOR UPDATE');
                $lock->execute([$file['file_id'], $userId]);
                $current = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$current || !uploadFileIsMissing($directory, $current['stored_filename'])) {
                    $pdo->rollBack();
                    continue;
                }
                $pdo->prepare('DELETE FROM file_shares WHERE file_id = ?')->execute([$file['file_id']]);
                if ($hasPreviewTable) {
                    $pdo->prepare('DELETE FROM preview_tokens WHERE file_id = ?')->execute([$file['file_id']]);
                }
                $delete = $pdo->prepare('DELETE FROM files WHERE file_id = ? AND user_id = ?');
                $delete->execute([$file['file_id'], $userId]);
                $count = $delete->rowCount();
                $pdo->commit();
                $deleted += $count;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
    } catch (Throwable $error) {
        error_log('清理遺失檔案紀錄失敗：' . $error->getMessage());
    }
    return $deleted;
}
