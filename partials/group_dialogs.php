<dialog id="createDialog" class="group-dialog"><form id="createGroupForm"><h2>建立群組</h2><label for="newGroupName">群組名稱</label><input id="newGroupName" class="form-control" maxlength="80" required><fieldset class="mt-3"><legend class="fs-6">加入成員</legend><div id="newGroupMembers" class="group-checklist"></div></fieldset><p class="text-secondary small">新成員可查看歷史紀錄；退出或移除後立即停止存取。</p><div class="group-dialog-actions"><button type="button" class="btn btn-secondary" data-close>取消</button><button type="submit" class="btn btn-primary">建立群組</button></div></form></dialog>

<dialog id="toolsDialog" class="group-dialog">
<h2>群組設定</h2>
<label for="groupNotifyMode">此群組通知</label><select class="form-select mb-3" id="groupNotifyMode"><option value="all">全部訊息</option><option value="mentions">只接收 @提及</option><option value="mute">靜音（不產生新通知）</option></select>
<div id="adminSettings" hidden><form id="groupSettingsForm"><label for="editGroupName">群組名稱</label><input id="editGroupName" class="form-control" maxlength="80" required><label for="editAnnouncement" class="mt-2">群組公告</label><textarea id="editAnnouncement" class="form-control" maxlength="2000" rows="3"></textarea></form>
<label for="addGroupMember" class="mt-3">新增成員</label><div class="d-flex gap-2 mb-3"><select id="addGroupMember" class="form-select"></select><button type="button" class="btn btn-primary" id="addMemberButton">加入</button></div></div>
<button id="openFiles" type="button" class="btn btn-outline-light mb-3">📎 群組檔案</button>
<h3 class="fs-6">群組成員</h3><div id="groupMembersList"></div>
<div id="ownerSettings" hidden class="mt-3"><label for="transferOwnerMember">轉移群主（轉移後可退出）</label><div class="d-flex gap-2"><select id="transferOwnerMember" class="form-select"></select><button id="transferGroupOwner" type="button" class="btn btn-outline-warning">轉移</button></div><button id="dissolveGroup" type="button" class="btn btn-outline-danger mt-3">解散群組</button></div>
<p id="groupSettingsStatus" class="small mt-2" role="status"></p>
<div class="group-dialog-actions"><button class="btn btn-outline-danger" type="button" id="leaveGroup">退出群組</button><button class="btn btn-primary" type="button" id="saveGroupSettings">儲存</button><button type="button" class="btn btn-secondary" data-close>關閉</button></div>
</dialog>

<dialog id="fileDialog" class="group-dialog"><form id="groupFileForm"><h2>傳送檔案</h2><label for="fileSource">檔案來源</label><select class="form-select mb-2" id="fileSource"><option value="upload">上傳新檔案</option><option value="cloud">從我的雲端選擇</option></select><label for="groupUpload" id="uploadLabel">選擇檔案（最大 100MB）</label><input type="file" class="form-control" id="groupUpload"><label for="groupCloudFile" id="cloudLabel" hidden>我的雲端檔案</label><select id="groupCloudFile" class="form-select" hidden></select><div id="groupUploadStatus" class="small my-2" role="status"></div><div class="group-dialog-actions"><button type="button" class="btn btn-secondary" data-close>取消</button><button type="submit" class="btn btn-primary">傳送</button></div></form></dialog>

<dialog id="filesDialog" class="group-dialog"><h2>群組檔案</h2><label for="groupFileSearch" class="visually-hidden">搜尋檔案</label><input id="groupFileSearch" type="search" class="form-control" placeholder="搜尋檔案名稱"><div id="groupFilesList"></div><div class="group-dialog-actions"><button class="btn btn-secondary" type="button" data-close>關閉</button></div></dialog>

<div id="messageDialog" role="dialog" aria-label="訊息操作" aria-modal="false" hidden><div id="messageActionButtons" class="group-action-buttons"></div></div>
<dialog id="editDialog" class="group-dialog"><form id="editMessageForm"><h2>編輯訊息</h2><label for="editMessageText" class="visually-hidden">訊息內容</label><textarea id="editMessageText" class="form-control" rows="4" maxlength="2000" required></textarea><div class="group-dialog-actions"><button class="btn btn-secondary" type="button" data-close>取消</button><button class="btn btn-primary" type="submit">儲存</button></div></form></dialog>
<dialog id="readDialog" class="group-dialog"><h2>已讀成員</h2><div id="readMembers"></div><div class="group-dialog-actions"><button type="button" class="btn btn-secondary" data-close>關閉</button></div></dialog>

