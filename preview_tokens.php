<?php

function ensurePreviewTokensTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS preview_tokens (
            token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            file_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token),
            KEY idx_preview_tokens_file (file_id),
            KEY idx_preview_tokens_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function createPreviewToken(PDO $pdo, int $fileId, int $userId, int $lifetimeSeconds = 3600): string
{
    ensurePreviewTokensTable($pdo);

    // Keep the table small without requiring a scheduled cleanup task.
    $pdo->exec("DELETE FROM preview_tokens WHERE expires_at < NOW()");

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeSeconds);
    $stmt = $pdo->prepare("
        INSERT INTO preview_tokens (token, file_id, user_id, expires_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$token, $fileId, $userId, $expiresAt]);

    return $token;
}

