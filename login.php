<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/account_access.php';

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $authcode = trim($_POST['authcode'] ?? '');
    
    // 檢查授權碼 (stust + 當天日期，格式為 mmdd)
    $today = date('md'); // 取得當前月日，例如 0403
    $expectedAuthcode = 'stust' . $today;
    
    if ($username === '' || $password === '' || $authcode === '') {
        $error = "請輸入帳號、密碼與授權碼。";
    } elseif ($authcode !== $expectedAuthcode) {
        $error = "授權碼錯誤。";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $password === $user['password'] && !(int)storageAccountState($pdo,(int)$user['user_id'])['disabled']) {
            // Rotate the session after authentication.
            session_regenerate_id(true);
            // 登入成功，寫入 Session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['account_version'] = (int)storageAccountState($pdo,(int)$user['user_id'])['session_version'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['role'] = ($user['is_admin'] == 1) ? 'admin' : 'user'; // ← 加這行



            // 保留手動模式；自動模式登入後恢復在線。
            $statusMode = $user['status_mode'] ?? 'auto';
            $newStatus = match ($statusMode) {
                'idle' => 'idle',
                'dnd' => 'away',
                'invisible' => 'hidden',
                default => 'online',
            };
            $_SESSION['status'] = $newStatus;

            $stmtUpdate = $pdo->prepare("
                UPDATE users
                SET status = ?, last_active = NOW(), last_interaction = NOW()
                WHERE user_id = ?
            ");
            $stmtUpdate->execute([$newStatus, $_SESSION['user_id']]);

            header("Location: index.php");
            exit;
        } else {
            $error = $user && (int)storageAccountState($pdo,(int)$user['user_id'])['disabled'] ? "此帳號已停用，請聯絡管理員。" : ($user ? "密碼錯誤。" : "找不到該使用者。");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">
  <title>雲上聊頁來｜登入</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/auth.css?v=20261022-1" rel="stylesheet">
</head>
<body class="auth-page">
<main class="auth-shell">
  <?php require __DIR__ . '/partials/auth_intro.php'; ?>
  <section class="auth-card" aria-labelledby="authTitle">
    <h1 id="authTitle">歡迎回來</h1>
    <p class="auth-subtitle">登入後，繼續整理檔案與對話。</p>
    <?php if (isset($error)): ?>
      <div class="auth-alert" role="alert" tabindex="-1"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (($_GET['session'] ?? '') === 'expired'): ?>
      <div class="auth-alert" role="alert" tabindex="-1">登入已失效或帳號已停用，請重新登入；如有問題請聯絡管理員。</div>
    <?php elseif (($_GET['verify'] ?? '') === 'success'): ?>
      <div class="auth-alert success" role="status">帳號建立成功，請使用新帳號登入。</div>
    <?php endif; ?>
    <form method="post" action="login.php">
      <div class="auth-field"><label for="username">使用者名稱</label><input type="text" class="form-control" id="username" name="username" placeholder="輸入使用者名稱" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= htmlspecialchars($username ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
      <div class="auth-field"><label for="password">密碼</label><div class="auth-password"><input type="password" class="form-control" id="password" name="password" placeholder="輸入密碼" autocomplete="current-password" required><button type="button" class="auth-password-toggle" data-target="password" data-label="密碼" aria-controls="password" aria-label="顯示密碼" aria-pressed="false">顯示</button></div></div>
      <div class="auth-field"><label for="authcode">授權碼</label><input type="text" class="form-control" id="authcode" name="authcode" placeholder="輸入當日授權碼" autocapitalize="none" spellcheck="false" aria-describedby="authcodeHint" required><p id="authcodeHint" class="auth-hint">格式為 stust＋當日月日，例如 stust0403。</p></div>
      <button type="submit" class="btn auth-submit" name="login" value="1">登入</button>
    </form>
    <p class="auth-switch">還沒有帳號？<a href="register.php">建立帳號</a></p>
  </section>
</main>
<script src="assets/js/auth.js?v=20261022-1"></script>
</body>
</html>
