<dialog id="chatSearchDialog" aria-labelledby="chatSearchTitle" class="chat-edit-dialog">
  <h2 id="chatSearchTitle" class="fs-5">搜尋聊天紀錄</h2>
  <form id="chatSearchForm" class="d-flex gap-2 my-3"><label class="visually-hidden" for="chatSearchInput">搜尋文字或檔名</label><input id="chatSearchInput" type="search" maxlength="200" class="form-control" placeholder="搜尋文字或檔名" required><button type="submit" class="btn btn-primary">搜尋</button></form>
  <p id="chatSearchStatus" role="status"></p><div id="chatSearchResults"></div><button type="button" id="chatSearchMore" class="btn btn-outline-light mt-2" hidden>較早的搜尋結果</button>
  <div class="d-flex justify-content-end mt-3"><button type="button" id="chatSearchClose" class="btn btn-secondary">關閉</button></div>
</dialog>
<script>window.chatSearchScope = <?= json_encode(['group_id'=>$groupMode ? $selectedGroupId : 0,'user_id'=>$groupMode ? 0 : $selectedUserId]) ?>;</script>
<script src="assets/js/chat_search.js?v=20261022-1"></script>
