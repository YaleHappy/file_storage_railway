<?php

function ensureGroupSchema(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) return;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $count = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
            AND table_name IN ('chat_groups','chat_group_members','chat_group_messages','chat_group_mentions','chat_group_reactions','chat_group_notification_links')")->fetchColumn();
        if ((int) $count === 6) { $done[$key] = true; return; }
    }
    $id = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        "chat_groups (id $id, name VARCHAR(80) NOT NULL, owner_id INTEGER NOT NULL,
            announcement TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "chat_group_members (group_id BIGINT NOT NULL, user_id INTEGER NOT NULL,
            role VARCHAR(12) NOT NULL DEFAULT 'member', notification_mode VARCHAR(12) NOT NULL DEFAULT 'all',
            last_read_id BIGINT NOT NULL DEFAULT 0, joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id))",
        "chat_group_messages (id $id, group_id BIGINT NOT NULL, sender_id INTEGER NOT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'text', content TEXT NOT NULL,
            file_path VARCHAR(200) DEFAULT NULL, original_filename VARCHAR(255) DEFAULT NULL,
            file_size BIGINT NOT NULL DEFAULT 0, reply_to_id BIGINT DEFAULT NULL,
            edited_at DATETIME DEFAULT NULL, recalled_at DATETIME DEFAULT NULL,
            pinned INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "chat_group_mentions (message_id BIGINT NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY(message_id, user_id))",
        "chat_group_reactions (message_id BIGINT NOT NULL, user_id INTEGER NOT NULL,
            emoji VARCHAR(16) NOT NULL, PRIMARY KEY(message_id, user_id, emoji))",
        "chat_group_notification_links (notification_id BIGINT NOT NULL PRIMARY KEY,
            group_id BIGINT NOT NULL, message_id BIGINT NOT NULL)",
    ];
    foreach ($tables as $table) $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . $suffix);
    // Index creation is separate to keep the schema usable in SQLite tests too.
    $indexes = [
        'idx_group_messages_timeline' => ['chat_group_messages', 'group_id, id'],
        'idx_group_members_user' => ['chat_group_members', 'user_id, group_id'],
        'idx_group_notification_message' => ['chat_group_notification_links', 'message_id'],
    ];
    foreach ($indexes as $name => [$table, $columns]) {
        if ($mysql) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $stmt->execute([$table, $name]);
            if ($stmt->fetchColumn()) continue;
        }
        try {
            $pdo->exec('CREATE INDEX ' . ($mysql ? '' : 'IF NOT EXISTS ') . "$name ON $table ($columns)");
        } catch (PDOException $error) {
            // Concurrent first requests may both try to create the same index.
            if (!$mysql || (int) ($error->errorInfo[1] ?? 0) !== 1061) throw $error;
        }
    }
    $done[$key] = true;
}
