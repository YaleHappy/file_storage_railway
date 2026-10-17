<?php
require_once __DIR__ . '/../chat_message_service.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY, sender_id INTEGER, recipient_id INTEGER, message TEXT, sent_at TEXT)');
$pdo->exec("INSERT INTO chat_messages VALUES (1, 10, 20, 'hello', '2026-10-17 12:00:00'), (2, 20, 10, 'reply', '2026-10-17 12:01:00')");
function checkChat(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
function rejectsChat(callable $operation, string $exception): void {
    try { $operation(); } catch (Throwable $error) {
        if ($error instanceof $exception) return;
        throw $error;
    }
    throw new RuntimeException('Expected rejection: ' . $exception);
}
changeOwnChatMessage($pdo, 10, 1, 'edit', '  修改訊息  ');
$row = $pdo->query('SELECT * FROM chat_messages WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
checkChat($row['message'] === '修改訊息', 'Edit must update content');
checkChat($row['sent_at'] === '2026-10-17 12:00:00', 'Edit must preserve timeline order');
changeOwnChatMessage($pdo, 10, 1, 'edit', '修改訊息');
rejectsChat(fn() => changeOwnChatMessage($pdo, 10, 2, 'edit', 'unauthorized'), OutOfBoundsException::class);
rejectsChat(fn() => changeOwnChatMessage($pdo, 10, 2, 'recall'), OutOfBoundsException::class);
rejectsChat(fn() => changeOwnChatMessage($pdo, 10, 1, 'edit', '  '), InvalidArgumentException::class);
rejectsChat(fn() => changeOwnChatMessage($pdo, 10, 1, 'edit', str_repeat('字', 2001)), InvalidArgumentException::class);
rejectsChat(fn() => changeOwnChatMessage($pdo, 10, 1, 'delete-all'), InvalidArgumentException::class);
changeOwnChatMessage($pdo, 10, 1, 'recall');
checkChat((int) $pdo->query('SELECT COUNT(*) FROM chat_messages WHERE id = 1')->fetchColumn() === 0, 'Recalled message must disappear for both participants');
checkChat($pdo->query('SELECT message FROM chat_messages WHERE id = 2')->fetchColumn() === 'reply', 'Other messages must remain unchanged');
echo "Chat edit, recall, validation and ownership checks passed\n";
