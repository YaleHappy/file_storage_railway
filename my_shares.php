<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user_activity.php';

// 如果沒有登入 → 跳回 login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/file_cleanup.php';
cleanupMissingUserFiles($pdo, (int) $_SESSION['user_id']);

header('Content-Type: text/html; charset=utf-8');

// ✅ 依目前請求自動組 share.php 連結
function buildShareLink($token) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // 取得目前檔案所在資料夾路徑（例如 /file_storage）
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return "{$scheme}://{$host}{$dir}/share.php?token=" . urlencode($token);
}

// ==============================
//  (A) 刪除分享 (AJAX)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $stmt = $pdo->prepare("
        DELETE FROM file_shares
        WHERE share_id = :share_id
          AND file_id IN (
            SELECT file_id FROM files WHERE user_id = :user_id
          )
        LIMIT 1
    ");
    $stmt->execute([
        ':share_id' => $_POST['delete'],
        ':user_id'  => $_SESSION['user_id']
    ]);
    if($stmt->rowCount()) recordUserActivity($pdo,'取消分享',(int)$_POST['delete'],'分享編號：'.(int)$_POST['delete']);
    header('Content-Type: application/json');
    if(!$stmt->rowCount()){http_response_code(404);echo json_encode(['success'=>false,'error'=>'分享連結不存在或已移除']);}else echo json_encode(['success'=>true]);
    exit;
}

// ==============================
//  (B) 更新到期時間 / 密碼 (AJAX)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__.'/share_expiry.php';
    $shareId=(int)($_POST['share_id']??0);
    try {
        $expires=isset($_POST['expires_at'])?parseShareExpiry((string)$_POST['expires_at']):null;
        $pdo->beginTransaction();
        $owned=$pdo->prepare('SELECT s.share_id FROM file_shares s JOIN files f ON f.file_id=s.file_id WHERE s.share_id=? AND f.user_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $owned->execute([$shareId,$_SESSION['user_id']]);
        if(!$owned->fetchColumn())throw new OutOfBoundsException('找不到分享連結，或你沒有權限。');
        if(isset($_POST['expires_at']))$pdo->prepare('UPDATE file_shares SET expires_at=? WHERE share_id=?')->execute([$expires,$shareId]);
        $password=trim((string)($_POST['share_password']??''));
        if($password!=='')$pdo->prepare('UPDATE file_shares SET share_password=? WHERE share_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$shareId]);
        $pdo->commit();
        recordUserActivity($pdo,'修改分享設定',$shareId,'分享編號：'.$shareId);
        echo json_encode(['success'=>true]);
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        $expected=$error instanceof InvalidArgumentException || $error instanceof OutOfBoundsException;
        http_response_code($error instanceof InvalidArgumentException?400:($expected?404:500));
        echo json_encode(['success'=>false,'error'=>$expected?$error->getMessage():'儲存失敗，原有設定已保留，請稍後再試。']);
    }
    exit;
}
// ==============================
//  (C) 分頁設定
// ==============================
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ==============================
//  (D) 查詢該用戶所有分享的檔案
// ==============================
$sql = "
    SELECT
      f.file_id,
      f.original_filename,
      s.share_token,
      s.expires_at,
      s.share_password,
      s.share_id
    FROM file_shares s
    JOIN files f ON s.file_id = f.file_id
    WHERE f.user_id = :uid
    ORDER BY s.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':uid',   $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage,             PDO::PARAM_INT);
$stmt->bindValue(':offset',$offset,              PDO::PARAM_INT);
$stmt->execute();
$shares = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==============================
//  (E) 計算總數量(分頁用)
// ==============================
$sqlCount = "
    SELECT COUNT(*) 
    FROM file_shares s 
    JOIN files f ON s.file_id = f.file_id 
    WHERE f.user_id = ?
";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute([$_SESSION['user_id']]);
$totalShares = $stmtCount->fetchColumn();
$totalPages  = ceil($totalShares / $perPage);

// ✅ 確保 session status 存在
if (!isset($_SESSION['status']) && isset($_SESSION['user_id'])) {
    $stmtS = $pdo->prepare("SELECT status FROM users WHERE user_id = ?");
    $stmtS->execute([$_SESSION['user_id']]);
    $_SESSION['status'] = $stmtS->fetchColumn() ?: 'online';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="image/icon.png">
  <title>雲上聊頁來｜我的分享連結</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body { background:#181a2f; color:#fff; padding-top:5rem; font-family:Arial,sans-serif; }
    .container { max-width:1200px; }
    .table-container { background:#222; border-radius:12px; padding:20px; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .table-dark th, .table-dark td { vertical-align:middle; text-align:center; }
    .form-control { background:#2c2f4b !important; border:1px solid #444; color:#fff; }
    .form-control:focus { border-color:#3498db; background:#2c2f4b; color:#fff; }
    .link-container { word-break:break-all; white-space:normal; }
    .link-container input { width:100%; background:transparent; border:none; color:#fff; text-align:center; cursor:text; }
    .link-container input:focus { outline:none; }
    .btn { border-radius:6px; transition:0.3s ease-in-out; }
    .btn-copy { background:#3498db; border:none; color:white; padding:6px 12px; }
    .btn-copy:hover { background:#2980b9; }
    .btn-danger { background-color:#e74c3c; border:none; padding:6px 12px; }
    .btn-danger:hover { background-color:#c0392b; }
  </style>
  <link href="assets/css/responsive.css?v=20261022-2" rel="stylesheet">
<link href="assets/css/file_lists.css?v=20261024-1" rel="stylesheet">
<link href="assets/css/preview_theme.css?v=20261022-2" rel="stylesheet">
<link href="assets/css/file_context_menu.css?v=20261024-1" rel="stylesheet">
<link rel="stylesheet" href="assets/css/ui_feedback.css?v=20261024-1"><script src="assets/js/ui_feedback.js?v=20261024-1"></script></head>
<body class="has-fixed-navbar file-page">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">☁️ 雲上聊頁來</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sharesNavbar" aria-controls="sharesNavbar" aria-expanded="false" aria-label="切換導覽選單">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="sharesNavbar">
    <ul class="navbar-nav ms-auto">
      <li class="nav-item"><a class="nav-link active" aria-current="page" href="my_shares.php">🔗 我的分享</a></li>
      <li class="nav-item"><a class="nav-link" href="received_files.php">📥 收到的檔案</a></li>
      <li class="nav-item"><a class="nav-link" href="chat_history.php"><i class="bi bi-clock-history me-1"></i>聊天紀錄</a></li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          👤 <?= htmlspecialchars($_SESSION['username']) ?> (<span id="currentStatus">
            <?php
              $statusTexts = [
                'online' => '🟢 在線',
                'idle' => '🌙 閒置',
                'away' => '🔴 請勿打擾',
                'hidden' => '⚪ 隱形',
                'offline' => '⚪ 離線'
              ];
              echo htmlspecialchars($statusTexts[$_SESSION['status']] ?? '🟢 在線');
            ?>
          </span>)
        </a>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
          <li><a class="dropdown-item status-option" data-status="online" href="#">🟢 在線（自動）</a></li>
          <li><a class="dropdown-item status-option" data-status="idle" href="#">🌙 閒置</a></li>
          <li><a class="dropdown-item status-option" data-status="away" href="#">🔴 請勿打擾</a></li>
          <li><a class="dropdown-item status-option" data-status="hidden" href="#">⚪ 隱形</a></li>
        </ul>
      </li>
      <li class="nav-item navbar-session-actions"><a class="nav-link" href="logout.php">登出</a>
        <?php require __DIR__ . '/navbar_notifications.php'; ?>
      </li>
    </ul>
    </div>
  </div>
</nav>

<main class="container file-workspace">
  <header class="file-heading"><div><h1><i class="bi bi-link-45deg"></i> 我的分享</h1><p class="file-muted">共 <?= (int)$totalShares ?> 個分享連結 · 新建立的分享連結預設七天到期，可在設定中調整</p></div></header>
  <section class="file-panel" aria-label="分享連結列表">
  <div class="file-toolbar"><input class="file-search" type="search" placeholder="搜尋本頁分享的檔案…" aria-label="搜尋分享檔案"><select class="file-filter" aria-label="分享狀態"><option value="">所有狀態</option><option value="active">分享中</option><option value="soon">即將到期</option><option value="expired">已過期</option></select></div>
  <?php foreach ($shares as $s):
    $link = buildShareLink($s['share_token']);
    $expiry = empty($s['expires_at']) ? null : strtotime($s['expires_at']);
    $state = $expiry && $expiry <= time() ? 'expired' : ($expiry && $expiry <= time()+259200 ? 'soon' : 'active');
    $stateText = ['active'=>'分享中','soon'=>'即將到期','expired'=>'已過期'][$state];
  ?>
  <article class="file-row" data-name="<?= htmlspecialchars($s['original_filename'], ENT_QUOTES) ?>" data-state="<?= $state ?>">
    <div class="file-name"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><div><h2 class="file-title" title="<?= htmlspecialchars($s['original_filename'], ENT_QUOTES) ?>"><?= htmlspecialchars($s['original_filename']) ?></h2><span class="file-state <?= $state === 'soon' ? 'warning' : ($state === 'expired' ? 'expired' : '') ?>"><?= $stateText ?></span></div></div>
    <div><p class="file-muted">到期時間</p><span><?= $expiry ? date('Y/m/d H:i',$expiry) : '無期限' ?></span></div>
    <div><p class="file-muted">連結密碼</p><span><?= empty($s['share_password']) ? '未設密碼' : '已設密碼' ?></span></div>
    <div class="file-actions">
      <a class="btn btn-soft btn-sm file-preview" title="預覽檔案" href="preview.php?file_id=<?= (int)$s['file_id'] ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> 預覽</a>
      <button class="btn btn-primary btn-sm btn-copy" data-link="<?= htmlspecialchars($link,ENT_QUOTES) ?>"><i class="bi bi-clipboard"></i> 複製連結</button>
      <a class="btn btn-soft btn-sm" href="<?= htmlspecialchars($link,ENT_QUOTES) ?>" target="_blank" rel="noopener" title="開啟分享連結">開啟連結</a>
      <button class="btn btn-soft btn-sm share-settings" data-id="<?= (int)$s['share_id'] ?>" data-expiry="<?= $expiry ? date('Y-m-d\TH:i',$expiry) : '' ?>" data-name="<?= htmlspecialchars($s['original_filename'],ENT_QUOTES) ?>">設定</button>
      <button class="btn btn-outline-danger btn-sm delete-btn" data-id="<?= (int)$s['share_id'] ?>">取消分享</button>
    </div>
  </article>
  <?php endforeach; ?>
  <div class="file-empty" id="fileEmpty" <?= $shares ? 'hidden' : '' ?>><i class="bi bi-link-45deg" aria-hidden="true"></i><h2><?= $shares ? '找不到符合的分享' : '還沒有分享連結' ?></h2><p><?= $shares ? '試試其他檔名或狀態。' : '在首頁選擇檔案並建立分享連結，就會顯示在這裡。' ?></p></div>
  </section>
  <p class="file-feedback" role="status" id="shareFeedback"></p>
  <?php if ($totalPages > 1): ?><nav aria-label="分享分頁"><ul class="pagination"><?php for($i=1;$i<=$totalPages;$i++): ?><li class="page-item <?= $i==$page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
</main>
<?php require __DIR__ . "/partials/file_preview_dialog.php"; ?>
<dialog id="shareSettings"><form id="shareSettingsForm"><h2>分享設定</h2><p id="settingsFilename" class="file-muted mb-3"></p><input type="hidden" name="share_id"><label for="shareExpiry" class="form-label">到期時間</label><input id="shareExpiry" type="datetime-local" name="expires_at" class="form-control mb-2"><p class="file-muted mb-3">新建立的分享連結預設七天到期。此處可調整日期，清空後儲存則改為無期限。</p><label for="sharePassword" class="form-label">變更連結密碼</label><input id="sharePassword" type="password" name="share_password" class="form-control" autocomplete="new-password"><p class="file-muted mt-2">留空保留目前密碼。</p><p id="settingsFeedback" class="file-feedback" role="status"></p><div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-secondary" id="closeShareSettings">取消</button><button type="submit" class="btn btn-primary">儲存設定</button></div></form></dialog>

<script>
// 更新狀態
document.querySelectorAll('.status-option').forEach(item => {
  item.addEventListener('click', function(e) {
    e.preventDefault();
    const newStatus = this.getAttribute('data-status');

    fetch('update_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `status=${newStatus}`
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const map = { online:'🟢 在線', idle:'🌙 閒置', away:'🔴 請勿打擾', hidden:'⚪ 隱形' };
        document.getElementById('currentStatus').textContent = map[newStatus] || '⚪ 未知';
      } else {
        storageNotice(data.error || '更新狀態失敗');
      }
    })
    .catch(() => storageNotice('無法更新狀態，請檢查網路或伺服器錯誤'));
  });
});

const shareFeedback = document.getElementById('shareFeedback');
document.querySelectorAll('.btn-copy').forEach(btn => btn.addEventListener('click',async()=>{
 try { await navigator.clipboard.writeText(btn.dataset.link); shareFeedback.textContent='分享連結已複製'; } catch { shareFeedback.textContent='無法自動複製，請開啟連結後複製網址。'; }
}));
document.querySelectorAll('.delete-btn').forEach(btn=>btn.addEventListener('click',async()=>{
 if(btn.dataset.confirming)return;btn.dataset.confirming='1';
 const accepted=await storageConfirm('確定取消分享？連結將失效，原始檔案仍會保留。');delete btn.dataset.confirming;
 if(!accepted)return;
 btn.disabled=true;
 try { const r=await fetch('my_shares.php',{method:'POST',body:new URLSearchParams({delete:btn.dataset.id})}); const d=await r.json(); if(!r.ok||!d.success) throw Error(); location.reload(); } catch { btn.disabled=false; shareFeedback.textContent='取消分享失敗，請重試。'; }
}));
const settingsDialog=document.getElementById('shareSettings'),settingsForm=document.getElementById('shareSettingsForm');
document.querySelectorAll('.share-settings').forEach(btn=>btn.addEventListener('click',()=>{
 settingsForm.elements.share_id.value=btn.dataset.id; settingsForm.elements.expires_at.value=btn.dataset.expiry; settingsForm.elements.share_password.value=''; document.getElementById('settingsFilename').textContent=btn.dataset.name; document.getElementById('settingsFeedback').textContent=''; settingsDialog.showModal();
}));
document.getElementById('closeShareSettings').addEventListener('click',()=>settingsDialog.close());
settingsForm.addEventListener('submit',async e=>{
 e.preventDefault(); const btn=settingsForm.querySelector('[type=submit]'); btn.disabled=true;
 try {const body=new URLSearchParams(new FormData(settingsForm)); body.set('update','1'); const r=await fetch('my_shares.php',{method:'POST',body}); const d=await r.json(); if(!r.ok||!d.success) throw Error(d.error||'儲存失敗'); location.reload();} catch(err){document.getElementById('settingsFeedback').textContent=err.message;btn.disabled=false;}
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/file_lists.js?v=20261022-4"></script>
<script src="assets/js/presence.js?v=20261024-3"></script>
<script src="assets/js/message_notifications.js?v=20261021-5"></script>
<script src="assets/js/navbar_notifications.js?v=20261024-3"></script>
<?php require __DIR__ . '/partials/online_users.php'; ?>
<script src="assets/js/file_context_menu.js?v=20261024-2"></script>
</body>
</html>
