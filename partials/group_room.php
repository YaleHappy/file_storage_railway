    <section class="chat-panel group-room" id="groupRoom" hidden>
      <header class="group-room-header"><a class="btn btn-outline-light group-back" href="chat_history.php" aria-label="返回群組列表">←</a><div class="flex-grow-1"><h2 id="groupTitle"></h2><small id="groupMemberCount"></small></div><button class="btn btn-outline-light" id="groupTools" type="button" aria-label="群組設定" title="群組設定"><span aria-hidden="true">⚙</span><span class="group-control-label"> 設定</span></button><button class="chat-search-trigger" type="button" data-chat-search aria-label="搜尋聊天紀錄"><span>搜尋聊天紀錄</span><i class="bi bi-search" aria-hidden="true"></i></button></header>
      <div id="groupStatusNotice" class="group-status-notice" role="status" hidden>此群組已由管理員暫停傳送，仍可查看歷史訊息。</div>
      <div id="groupAnnouncement" class="group-announcement" hidden></div>
      <div id="groupPins" class="group-pins" hidden></div>
      <div class="group-messages" id="groupMessages" aria-live="polite"><button id="olderMessages" type="button" class="btn btn-sm btn-outline-light">較早訊息</button><button id="latestMessages" type="button" class="btn btn-sm btn-outline-info ms-2" hidden>回到最新訊息</button><div id="groupTimeline"></div></div>
      <div id="replyPreview" class="group-reply-preview" hidden><span></span><button class="btn btn-sm btn-outline-light" type="button" id="cancelReply">取消回覆</button></div>
      <form id="groupComposer" class="group-composer">
        <div id="mentionMembers" class="mention-suggestions" role="listbox" aria-label="提及成員" hidden></div>
        <div class="group-input-row"><button class="btn btn-outline-light" type="button" id="attachGroupFile" aria-label="傳送檔案">📎</button><label class="visually-hidden" for="groupInput">訊息</label><textarea class="form-control" id="groupInput" rows="1" maxlength="2000" placeholder="輸入訊息，支援 @提及" required></textarea><button class="btn btn-primary" type="submit" id="groupSend"><i class="bi bi-send me-1" aria-hidden="true"></i>傳送</button></div>
      </form>
    </section>
