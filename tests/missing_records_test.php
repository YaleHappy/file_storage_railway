<?php
require_once __DIR__ . '/../file_cleanup.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE files (file_id INTEGER PRIMARY KEY, user_id INTEGER, stored_filename TEXT)');
$pdo->exec('CREATE TABLE file_shares (file_id INTEGER)');
$pdo->exec('CREATE TABLE preview_tokens (file_id INTEGER)');
$pdo->exec('CREATE TABLE shared_files (id INTEGER PRIMARY KEY, sender_id INTEGER, recipient_id INTEGER, file_path TEXT)');
$pdo->exec('CREATE TABLE chat_group_members (group_id INTEGER, user_id INTEGER)');
$pdo->exec('CREATE TABLE chat_group_messages (id INTEGER PRIMARY KEY, group_id INTEGER, kind TEXT, recalled_at TEXT, file_path TEXT)');
$pdo->exec('CREATE TABLE chat_group_mentions (message_id INTEGER)');
$pdo->exec('CREATE TABLE chat_group_reactions (message_id INTEGER)');
$pdo->exec('CREATE TABLE chat_group_notification_links (message_id INTEGER, notification_id INTEGER)');
$pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY)');
$root = sys_get_temp_dir() . '/missing-records-' . bin2hex(random_bytes(8));
mkdir($root); mkdir($root . '/shared'); mkdir($root . '/.group-files');
file_put_contents($root . '/empty.txt', '');
file_put_contents($root . '/shared/valid.txt', 'valid');
$privateName = str_repeat('a', 48) . '.bin';
$missingName = str_repeat('b', 48) . '.bin';
file_put_contents($root . '/.group-files/' . $privateName, 'group');
$checks = 0;
function missingCheck(bool $pass, string $message): void { global $checks; if (!$pass) throw new RuntimeException($message); $checks++; }
try {
    $pdo->exec("INSERT INTO files VALUES (1,1,'missing.txt'),(2,1,'empty.txt'),(3,2,'other.txt'),(4,1,'../outside')");
    $pdo->exec('INSERT INTO file_shares VALUES (1),(2); INSERT INTO preview_tokens VALUES (1),(2)');
    missingCheck(cleanupMissingUserFiles($pdo, 1, null, $root) === 1, 'Only own missing personal file is deleted');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn() === 3, 'Other files remain');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM file_shares WHERE file_id=1')->fetchColumn() === 0, 'Dead sharing links are deleted');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM preview_tokens WHERE file_id=1')->fetchColumn() === 0, 'Dead preview tokens are deleted');
    $pdo->exec("INSERT INTO shared_files VALUES (1,2,1,'uploads/shared/missing.txt'),(2,2,1,'uploads/shared/valid.txt'),(3,3,4,'uploads/shared/other.txt'),(4,1,2,'../config.php'),(5,1,2,'uploads/sent/missing.txt')");
    missingCheck(cleanupMissingSharedFiles($pdo, 1, null, $root) === 2, 'Missing received and sent records are cleaned');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM shared_files WHERE id IN (2,3,4)')->fetchColumn() === 3, 'Existing, unrelated and unsafe shared paths are preserved');
    missingCheck(cleanupMissingSharedFiles($pdo, 4, null, $root . '/unmounted') === 0, 'Unavailable upload root never deletes records');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM shared_files WHERE id=3')->fetchColumn() === 1, 'Unavailable storage retains database row');
    $pdo->exec('INSERT INTO chat_group_members VALUES (10,1),(10,2),(20,3)');
    $stmt = $pdo->prepare("INSERT INTO chat_group_messages VALUES (?,?,'file',NULL,?)");
    $stmt->execute([10,10,$missingName]); $stmt->execute([11,10,$privateName]); $stmt->execute([12,20,$missingName]);
    $pdo->exec('INSERT INTO chat_group_mentions VALUES(10); INSERT INTO chat_group_reactions VALUES(10); INSERT INTO notifications VALUES(100); INSERT INTO chat_group_notification_links VALUES(10,100)');
    missingCheck(cleanupMissingGroupFiles($pdo, 10, 3, $root) === 0, 'Nonmember cannot clean group records');
    missingCheck(cleanupMissingGroupFiles($pdo, 10, 1, $root) === 1, 'Member cleans missing group file');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM chat_group_messages')->fetchColumn() === 2, 'Valid and unrelated group files remain');
    foreach (['chat_group_mentions','chat_group_reactions','chat_group_notification_links','notifications'] as $table) {
        missingCheck((int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0, 'Dependent group rows cleaned: ' . $table);
    }
    $pdo->exec("INSERT INTO files VALUES (9,1,'rollback.txt'); INSERT INTO file_shares VALUES(9)");
    $pdo->exec("CREATE TRIGGER prevent_delete BEFORE DELETE ON files WHEN OLD.file_id=9 BEGIN SELECT RAISE(ABORT,'test rollback'); END");
    missingCheck(cleanupMissingUserFiles($pdo, 1, 9, $root) === 0, 'Failed deletion does not count as success');
    missingCheck((int) $pdo->query('SELECT COUNT(*) FROM file_shares WHERE file_id=9')->fetchColumn() === 1, 'Transaction restores dependent rows on failure');
    missingCheck(uploadRelativeFileIsMissing($root, 'shared/absent.txt'), 'Nested file absence is detected');
    foreach (['/absolute','../escape','shared/../../escape','shared\\escape',"bad\0path", 'C:/absolute'] as $bad) {
        missingCheck(!uploadRelativeFileIsMissing($root, $bad), 'Unsafe path is skipped');
    }
    echo "Missing record cleanup: $checks checks passed\n";
} finally {
    unlink($root . '/empty.txt'); unlink($root . '/shared/valid.txt'); unlink($root . '/.group-files/' . $privateName);
    rmdir($root . '/shared'); rmdir($root . '/.group-files'); rmdir($root);
}
