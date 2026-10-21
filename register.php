<?php
session_start();
require 'config.php';

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
  <title>雲上聊頁來｜註冊</title>
  <link rel="icon" type="image/png" href="image/icon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #34495e, #5d6d7e);
      color: #fff;
      height: 100vh;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: Arial, sans-serif;
    }
    .register-container {
      max-width: 500px;
      width: 100%;
      padding: 2rem;
    }
    .register-card {
      background: rgba(255, 255, 255, 0.18);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 0.8rem;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
      padding: 2.5rem;
    }
    .website-title {
      text-align: center;
      font-size: 1.8rem;
      font-weight: bold;
      margin-bottom: 0.5rem;
      text-shadow: 1px 1px 4px rgba(0, 0, 0, 0.3);
    }
    .website-description {
      text-align: center;
      font-size: 1rem;
      color: #ddd;
      margin-bottom: 1.8rem;
    }
    .form-floating label {
      color: #ddd;
    }
    .form-control {
      background: rgba(255, 255, 255, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: #fff;
      font-size: 1rem;
    }
    .form-control:focus {
      background: rgba(255, 255, 255, 0.3);
      color: #fff;
    }
    .btn-success {
      border: none;
      font-size: 1.1rem;
      padding: 0.6rem;
    }
    .btn-link {
      color: #ccc;
      text-decoration: none;
      font-size: 0.95rem;
    }
    .btn-link:hover {
      color: #fff;
      text-decoration: underline;
    }

    .form-control {
      color: #fff !important;
    }

    .form-control::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .alert-custom-error {
      background-color: rgba(255, 255, 255, 0.15);
      border: 1px solid #00ccff;
      color: #ffaa00;
      padding: 0.75rem 1.25rem;
      border-radius: 0.5rem;
      font-weight: bold;
      margin-bottom: 1rem;
    }
  </style>
  <link href="assets/css/responsive.css?v=20260928-7" rel="stylesheet">
</head>
<body class="auth-page">
<div class="register-container">
  <div class="website-title">☁️ 雲上聊頁來</div>
  <div class="website-description">建立帳號，開始管理檔案與即時交流</div>

  <div class="card register-card">
    <h3 class="text-center mb-4">使用者註冊</h3>

    <?php if ($error): ?>
      <div class="alert-custom-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="form-floating mb-3">
        <input type="text" class="form-control" id="username" name="username" placeholder="使用者名稱" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        <label for="username">使用者名稱</label>
      </div>

      <div class="form-floating mb-3">
        <input type="password" class="form-control" id="password" name="password" placeholder="密碼" required>
        <label for="password">密碼</label>
      </div>

      <div class="form-floating mb-4">
        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="確認密碼" required>
        <label for="confirm_password">確認密碼</label>
      </div>

      <button type="submit" class="btn btn-success w-100 mb-3" name="register">完成註冊</button>
    </form>

    <div class="text-center">
      <a href="login.php" class="btn btn-link">已經有帳號？前往登入</a>
    </div>
  </div>
</div>
</body>
</html>
