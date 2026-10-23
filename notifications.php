<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>雲上聊頁來｜通知</title>
  <link rel="icon" href="image/icon.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/css/responsive.css?v=20261022-2">
  <link rel="stylesheet" href="assets/css/notifications_page.css?v=20261022-1">
<link rel="stylesheet" href="assets/css/ui_feedback.css?v=20261024-1"><script src="assets/js/ui_feedback.js?v=20261024-1"></script></head>
<body>
  <nav class="navbar navbar-dark bg-dark px-3"><a class="navbar-brand" href="index.php">☁️ 雲上聊頁來</a><div class="d-flex align-items-center"><a class="nav-link text-light" href="logout.php">登出</a><?php require __DIR__ . '/navbar_notifications.php'; ?></div></nav>
  <main class="container notifications-page" data-notifications-page>
    <header><h1>通知</h1><button type="button" class="btn btn-link text-info" data-mark-all>全部標為已讀</button></header>
    <p class="notifications-page-hint">顯示最近 100 則通知</p>
    <div class="notifications-container" aria-live="polite"><p>載入中…</p></div>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/presence.js?v=20261024-3"></script>
  <script src="assets/js/message_notifications.js?v=20261021-5"></script>
  <script src="assets/js/navbar_notifications.js?v=20261024-3"></script>
<?php require __DIR__ . '/partials/online_users.php'; ?>
</body>
</html>
