<?php
session_start();
require 'config.php';

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

        if ($user && $password === $user['password']) {
            // 登入成功，寫入 Session
            $_SESSION['user_id'] = $user['user_id'];
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
            $error = $user ? "密碼錯誤。" : "找不到該使用者。";
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
  <style>
    body {
      background: linear-gradient(135deg, #2c3e50, #4e5f70);
      color: #fff;
      height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .login-container {
      max-width: 420px;
      width: 100%;
    }
    .login-card {
      background: rgba(255,255,255,0.12);
      backdrop-filter: blur(10px);
      border-radius: .75rem;
      box-shadow: 0 8px 24px rgba(0,0,0,0.3);
      padding: 2rem;
    }
    .brand-logo {
      display: block;
      width: 80px;
      height: 80px;
      margin: 0 auto 1rem;
    }
    .form-floating label {
      color: #eee;
    }
    .form-control {
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.3);
      color: #fff;
    }
    .form-control:focus {
      background: rgba(255,255,255,0.3);
      color: #fff;
    }
    .btn-primary {
      background-color: #0d6efd;
      border: none;
    }
    .btn-primary:hover {
      background-color: #0056b3;
    }
    .btn-link {
      color: #ccc;
      text-decoration: none;
    }
    .btn-link:hover {
      color: #fff;
      text-decoration: underline;
    }
    .website-title {
      text-align: center;
      font-size: 1.6rem;
      font-weight: bold;
      margin-bottom: 0.5rem;
    }
    .website-description {
      text-align: center;
      font-size: 0.95rem;
      color: #ddd;
      margin-bottom: 1.5rem;
    }
  </style>
  <link href="responsive.css?v=20260928-7" rel="stylesheet">
</head>
<body class="auth-page">
  <div class="login-container">
    <div class="website-title">☁️ 雲上聊頁來</div>
    <div class="website-description">檔案儲存、即時聊天與安全分享，一頁完成</div>
    
    <div class="card login-card">
      <h3 class="text-center mb-4">登入</h3>
      <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" action="login.php">
        <div class="form-floating mb-3">
          <input type="text" class="form-control" id="username" name="username" placeholder="使用者名稱" required value="<?= htmlspecialchars($username ?? '') ?>">
          <label for="username">使用者名稱</label>
        </div>
        <div class="form-floating mb-3">
          <input type="password" class="form-control" id="password" name="password" placeholder="密碼" required>
          <label for="password">密碼</label>
        </div>
        <div class="form-floating mb-4">
          <input type="text" class="form-control" id="authcode" name="authcode" placeholder="授權碼" required>
          <label for="authcode">授權碼 (stust+日期，例如：stust0403)</label>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3" name="login">登入</button>
        <div class="text-center">
          <a href="register.php" class="btn btn-link">還沒有帳號？立即註冊</a>
        </div>
      </form>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
