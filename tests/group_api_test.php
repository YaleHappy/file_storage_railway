<?php
// Exercise the real PHP endpoints without a network listener or production DB.
$fixtureDirectory = sys_get_temp_dir() . '/group-api-' . bin2hex(random_bytes(8));
mkdir($fixtureDirectory);
putenv('GROUP_TEST_DSN=sqlite:' . $fixtureDirectory . '/test.sqlite');
require __DIR__ . '/group_service_test.php';
putenv('GROUP_TEST_DSN');
$root = dirname(__DIR__);
$config = '<?php $pdo = new PDO(' . var_export('sqlite:' . $fixtureDirectory . '/test.sqlite', true)
    . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);';
file_put_contents($fixtureDirectory . '/config.php', $config);
$apiChecks = 0;
function requestGroupEndpoint(string $endpoint, int $user, string $method = 'GET', array $get = [], array $post = []): array
{
    global $fixtureDirectory, $root;
    $code = 'chdir(' . var_export($fixtureDirectory, true) . '); set_include_path(getcwd()); '
        . 'session_save_path(getcwd()); session_id("grouptest-' . $user . '"); session_start(); $_SESSION=[]; '
        . ($user > 0 ? '$_SESSION["user_id"]=' . $user . '; $_SESSION["chat_csrf"]="fixture-token";' : '')
        . ' session_write_close(); $_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
        . '$_GET=' . var_export($get, true) . '; $_POST=' . var_export($post, true) . ';'
        . 'register_shutdown_function(function(){fwrite(STDERR,"HTTP_STATUS=".(http_response_code()?:200));});'
        . 'require ' . var_export($root . '/' . $endpoint, true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to launch PHP endpoint test');
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($process);
    if (!preg_match('/HTTP_STATUS=(\d+)/', $error, $status)) throw new RuntimeException($error . $body);
    return ['status' => (int) $status[1], 'body' => $body, 'data' => json_decode($body, true)];
}
function checkGroupEndpoint(bool $value, string $label): void { global $apiChecks; if (!$value) throw new RuntimeException($label); $apiChecks++; }
try {
    $result = requestGroupEndpoint('group_api.php', 0);
    checkGroupEndpoint($result['status'] === 401, 'Anonymous requests must fail');
    $result = requestGroupEndpoint('group_api.php', 1, 'POST', [], ['action' => 'send', 'group_id' => 1, 'payload' => '{"content":"bad"}']);
    checkGroupEndpoint($result['status'] === 403, 'Missing CSRF token must fail');
    $result = requestGroupEndpoint('group_api.php', 1, 'PUT');
    checkGroupEndpoint($result['status'] === 405, 'Unsupported HTTP methods must fail');
    $result = requestGroupEndpoint('group_api.php', 1, 'GET', ['group_id' => 1]);
    checkGroupEndpoint($result['status'] === 200 && $result['data']['success'], 'Member can get real snapshot endpoint');
    $result = requestGroupEndpoint('group_api.php', 2, 'GET', ['group_id' => 1]);
    checkGroupEndpoint($result['status'] === 404, 'Removed member cannot read endpoint');
    $result = requestGroupEndpoint('group_api.php', 1, 'POST', [], ['csrf_token' => 'fixture-token', 'action' => 'send', 'group_id' => 1, 'payload' => json_encode(['content' => 'API send @mention-user'])]);
    checkGroupEndpoint($result['status'] === 200 && $result['data']['success'], 'Member can send via real endpoint');
    $id = $result['data']['message_id'];
    $result = requestGroupEndpoint('group_api.php', 3, 'POST', [], ['csrf_token' => 'fixture-token', 'action' => 'recall', 'group_id' => 1, 'payload' => json_encode(['message_id' => $id])]);
    checkGroupEndpoint($result['status'] === 403, 'Member cannot recall another sender via API');
    $result = requestGroupEndpoint('group_api.php', 1, 'POST', [], ['csrf_token' => 'fixture-token', 'action' => 'send', 'group_id' => 1, 'payload' => 'invalid-json']);
    checkGroupEndpoint($result['status'] === 400, 'Invalid JSON returns client error');
    $result = requestGroupEndpoint('group_api.php', 2, 'GET', ['group_id' => 1, 'view' => 'files']);
    checkGroupEndpoint($result['status'] === 404, 'Removed member cannot list group files');
    $result = requestGroupEndpoint('group_download.php', 2, 'GET', ['group_id' => 1, 'message_id' => $id]);
    checkGroupEndpoint($result['status'] === 404, 'Removed member cannot download files');
    $result = requestGroupEndpoint('group_api.php', 1, 'GET', ['view' => 'list']);
    checkGroupEndpoint($result['status'] === 200 && count($result['data']['groups']) === 2 && !isset($result['data']['users']), 'Lightweight list endpoint returns only own groups');
    $result = requestGroupEndpoint('check_notifications.php', 1);
    checkGroupEndpoint($result['status'] === 200 && $result['data']['success'], 'Unified notification endpoint still works');
    checkGroupEndpoint(count(array_filter($result['data']['notifications'], fn($n) => (int) ($n['group_id'] ?? 0) === 1)) > 0, 'Group notifications contain routing ID');
    $result = requestGroupEndpoint('check_notifications.php', 2);
    checkGroupEndpoint(count(array_filter($result['data']['notifications'], fn($n) => (int) ($n['group_id'] ?? 0) === 1)) === 0, 'Removed member cannot receive old group notifications');
    $result = requestGroupEndpoint('clear_notifications.php', 1, 'POST', [], ['clear_history' => '1']);
    checkGroupEndpoint($result['data']['success'], 'Clear all remains supported');
    $result = requestGroupEndpoint('check_notifications.php', 1);
    checkGroupEndpoint($result['data']['total_unread'] === 0 && count($result['data']['notifications']) === 0, 'Clear all removes own group and direct notifications');
    echo "Group HTTP endpoint simulation: $apiChecks checks passed\n";
} finally {
    $pdo = null;
    foreach (glob($fixtureDirectory . '/*') as $file) if (is_file($file)) unlink($file);
    rmdir($fixtureDirectory);
}
