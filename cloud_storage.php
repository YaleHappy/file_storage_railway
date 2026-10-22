<?php
// Call inside a transaction. Both cloud-save entry points lock the same user.
function assertCloudNameAvailable(PDO $pdo, int $userId, string $name): void
{
    $lock = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $lock->execute([$userId]);
    $query = $pdo->prepare('SELECT file_id FROM files WHERE user_id = ? AND LOWER(original_filename) = LOWER(?) LIMIT 1');
    $query->execute([$userId, $name]);
    if ($query->fetchColumn() !== false) throw new DomainException('你的雲端已有相同名稱的檔案，無法重複儲存。請先重新命名或移除原檔案。');
}
