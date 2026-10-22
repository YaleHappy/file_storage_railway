<?php
session_start();
require_once __DIR__ . '/config.php';

$error = '';

// 處理「完成註冊」的動作（不再使用 Email 驗證碼 / SMTP）
if (isset($_POST['register'])) {
    $username         = trim($_POST['username'] ?? '');
    $password         = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if ($username === '' || $password === '' || $confirm_password === '') {
        $error = '請確實填寫所有欄位。';
    } elseif ($password !== $confirm_password) {
        $error = '兩次密碼輸入不一致。';
    } else {
        // 檢查 username 是否重複
        $stmtUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmtUser->execute([$username]);
        if ($stmtUser->fetchColumn() > 0) {
            $error = '使用者名稱已存在。';
        } else {
            // 不再由註冊頁輸入 email，使用系統產生的保留 email
            $systemEmail = $username . '@local.register';
            $insert = $pdo->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
            $insert->execute([$username, $password, $systemEmail]);
            header('Location: login.php?verify=success');
            exit;
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
  <title>雲上聊頁來｜註冊</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/auth.css?v=20261022-1" rel="stylesheet">
</head>
<body class="auth-page">
<main class="auth-shell">
  <?php require __DIR__ . '/partials/auth_intro.php'; ?>
  <section class="auth-card" aria-labelledby="authTitle">
    <h1 id="authTitle">建立帳號</h1>
    <p class="auth-subtitle">準備好自己的雲端空間，開始分享與交流。</p>
    <?php if ($error): ?><div class="auth-alert" role="alert" tabindex="-1"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" action="register.php">
      <div class="auth-field"><label for="username">使用者名稱</label><input type="text" class="form-control" id="username" name="username" placeholder="設定使用者名稱" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></div>
      <div class="auth-field"><label for="password">密碼</label><div class="auth-password"><input type="password" class="form-control" id="password" name="password" placeholder="設定密碼" autocomplete="new-password" required><button type="button" class="auth-password-toggle" data-target="password" data-label="密碼" aria-controls="password" aria-label="顯示密碼" aria-pressed="false">顯示</button></div></div>
      <div class="auth-field"><label for="confirm_password">確認密碼</label><div class="auth-password"><input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="再次輸入密碼" autocomplete="new-password" required><button type="button" class="auth-password-toggle" data-target="confirm_password" data-label="確認密碼" aria-controls="confirm_password" aria-label="顯示確認密碼" aria-pressed="false">顯示</button></div><p class="auth-hint">請再次輸入相同密碼。</p></div>
      <button type="submit" class="btn auth-submit" name="register" value="1">建立帳號</button>
    </form>
    <p class="auth-switch">已經有帳號？<a href="login.php">前往登入</a></p>
  </section>
</main>
<script src="assets/js/auth.js?v=20261022-1"></script>
</body>
</html>
