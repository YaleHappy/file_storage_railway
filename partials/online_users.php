<link rel="stylesheet" href="assets/css/online_users.css?v=20261022-4">
<button type="button" id="onlineUsersToggle" aria-controls="onlineUsersPanel" aria-expanded="false">上線使用者</button>
<div id="onlineUsersBackdrop" hidden aria-hidden="true"></div>
<aside id="onlineUsersPanel" role="dialog" aria-modal="true" aria-label="上線使用者" data-current-user="<?= (int)$_SESSION['user_id'] ?>" hidden>
  <header><strong>上線使用者</strong><button type="button" id="onlineUsersClose" aria-label="關閉使用者列表">×</button></header>
  <ul id="onlineUsersList"><li>載入中…</li></ul>
</aside>
<dialog id="onlineUserFileDialog" aria-labelledby="onlineUserFileTitle">
  <form id="onlineUserFileForm">
    <h2 id="onlineUserFileTitle">傳送檔案</h2>
    <p id="onlineUserFileRecipient"></p>
    <label for="onlineUserFileSource">檔案來源</label>
    <select id="onlineUserFileSource" class="form-select"><option value="upload">上傳新檔案</option><option value="select">我的雲端</option></select>
    <label for="onlineUserUpload" id="onlineUserUploadLabel">選擇檔案（最多 100MB）</label>
    <input type="file" id="onlineUserUpload" class="form-control">
    <label for="onlineUserCloud" id="onlineUserCloudLabel" hidden>選擇雲端檔案</label>
    <select id="onlineUserCloud" class="form-select" hidden></select>
    <p id="onlineUserFileStatus" role="status"></p>
    <div class="online-user-file-actions"><button type="button" id="onlineUserFileCancel" class="btn btn-secondary">取消</button><button type="submit" class="btn btn-primary">傳送</button></div>
  </form>
</dialog>
<script src="assets/js/online_users.js?v=20261022-5"></script>
