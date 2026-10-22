<?php

function ensureReceivedPreviewTokensTable(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS received_preview_tokens (
        token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        delivery_id INT NOT NULL, recipient_id INT NOT NULL, expires_at DATETIME NOT NULL,
        KEY idx_received_preview_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function createReceivedPreviewToken(PDO $pdo, int $deliveryId, int $recipientId): string
{
    ensureReceivedPreviewTokensTable($pdo);
    $pdo->exec('DELETE FROM received_preview_tokens WHERE expires_at < NOW()');
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO received_preview_tokens (token, delivery_id, recipient_id, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$token, $deliveryId, $recipientId, date('Y-m-d H:i:s', time() + 3600)]);
    return $token;
}

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

