<?php
require_once __DIR__ . '/../file_cleanup.php';

function checkCleanup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/file-cleanup-' . bin2hex(random_bytes(8));
mkdir($directory);
try {
    file_put_contents($directory . '/present.txt', 'content');
    file_put_contents($directory . '/empty.txt', '');
    checkCleanup(!uploadFileIsMissing($directory, 'present.txt'), 'Existing file must remain');
    checkCleanup(!uploadFileIsMissing($directory, 'empty.txt'), 'Empty file must remain');
    checkCleanup(uploadFileIsMissing($directory, 'missing.txt'), 'Missing file must be detected');
    checkCleanup(!uploadFileIsMissing($directory . '/unavailable', 'missing.txt'), 'Unavailable storage must be skipped');
    foreach (['', '.', '..', '../outside.txt', 'nested/file.txt', "bad\0name"] as $invalid) {
        checkCleanup(!uploadFileIsMissing($directory, $invalid), 'Invalid paths must be skipped');
    }
    echo "File absence checks passed\n";
} finally {
    unlink($directory . '/present.txt');
    unlink($directory . '/empty.txt');
    rmdir($directory);
}
