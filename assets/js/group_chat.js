(() => {
  const boot = window.groupBoot;
  const $ = id => document.getElementById(id);
  const state = { group: null, members: [], messages: [], groups: [], users: [], files: [], busy: false, reply: null, selected: null, signature: '', memberSignature: '', historyBefore: 0, lastRead: 0, readPending: false, version: 0 };
  let press = null;
  let stopClickUntil = 0;
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function button(text, onClick, className = 'btn btn-sm btn-outline-light') {
    const node = element('button', className, text);
    node.type = 'button';
    node.addEventListener('click', onClick);
    return node;
  }
  function showError(error) { $('groupError').textContent = error.message || String(error); $('groupError').hidden = false; }
  function clearError() { $('groupError').hidden = true; }
  function modalOpen() { return !!document.querySelector('dialog[open]') || !$('messageDialog').hidden; }
  function closeMessageMenu() { $('messageDialog').hidden = true; }
  async function apiGet(query = {}) {
    const response = await fetch('group_api.php?' + new URLSearchParams(query), { cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.success) {
      if (response.status === 404 && boot.groupId) { $('groupRoom').hidden = true; $('groupShell').classList.remove('conversation-open'); $('groupEmpty').hidden = false; $('groupEmpty').textContent = '你已無法存取此群組，請返回對話列表。'; }
      throw new Error(data.msg || '無法載入群組');
    }
    return data;
  }
  async function action(actionName, payload = {}, file = null) {
    if (state.busy) throw new Error('操作處理中，請稍候');
    state.busy = true;
    state.version++;
    const body = new FormData();
    body.append('csrf_token', boot.csrf);
    body.append('group_id', boot.groupId);
    body.append('action', actionName);
    body.append('payload', JSON.stringify(payload));
    if (file) body.append('file', file);
    try {
      const response = await fetch('group_api.php', { method: 'POST', body });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.msg || '操作失敗');
      clearError();
      return data;
    } finally { state.busy = false; }
  }
  async function run(operation) {
    try { await operation(); } catch (error) { showError(error); storageNotice(error.message || '操作失敗'); }
  }
  function renderGroups() {
    const list = $('groupList') || $('historyGroupList');
    list.replaceChildren();
    const keyword = ($('groupSearch') || $('conversationSearch')).value.toLocaleLowerCase();
    for (const group of state.groups.filter(g => g.name.toLocaleLowerCase().includes(keyword))) {
      const link = element('a', 'conversation-item' + (Number(group.id) === boot.groupId ? ' active' : ''));
      link.href = 'chat_history.php?group=' + Number(group.id);
      link.dataset.username = group.name.toLocaleLowerCase('zh-TW');
      const title = element('div', 'd-flex justify-content-between gap-2');
      title.append(element('strong', '', '👥 ' + group.name));
      if (Number(group.unread_count)) title.append(element('span', 'badge bg-danger', group.unread_count));
      link.append(title, element('div', 'conversation-preview', group.last_content || '開始群組對話'), element('small', '', `${group.member_count} 位成員${group.notification_mode === 'mute' ? ' · 已靜音' : ''}`));
      list.appendChild(link);
    }
    if (!list.childElementCount) list.append(element('p', 'mt-3', '沒有符合的群組'));
  }
  function memberCheckbox(person, name) {
    const label = element('label');
    const input = element('input');
    input.type = 'checkbox'; input.value = person.user_id; input.name = name;
    label.append(input, document.createTextNode(' ' + person.username));
    return label;
  }
  async function loadDirectory() {
    const data = await apiGet();
    state.groups = data.groups; state.users = data.users; state.files = data.files;
    renderGroups();
    $('newGroupMembers').replaceChildren(...state.users.map(u => memberCheckbox(u, 'new_member')));
    $('groupCloudFile').replaceChildren(...state.files.map(file => {
      const option = element('option', '', file.original_filename); option.value = file.file_id; return option;
    }));
  }
  function chosenMentions() {
    return {};
  }
  function clearReply() { state.reply = null; $('replyPreview').hidden = true; }
  function setReply(message) {
    state.reply = Number(message.id);
    $('replyPreview').hidden = false;
    $('replyPreview').querySelector('span').textContent = '回覆 ' + message.username + '：' + (message.original_filename || message.content).slice(0, 100);
    $('groupInput').focus();
  }
  function downloadLink(message) {
    const link = element('a', 'btn btn-sm btn-light', '下載');
    link.title = '下載檔案';
    link.href = `group_download.php?group_id=${boot.groupId}&message_id=${Number(message.id)}`;
    return link;
  }
  function fileActions(message) {
    const row = element('div', 'group-file-actions');
    row.append(downloadLink(message), button('存到雲端', () => run(async () => {
      await action('save_file', { message_id: Number(message.id) });
      storageNotice('已另存到我的雲端');
    })));
    row.querySelectorAll('button').forEach(btn => { btn.title = '儲存到我的雲端'; });
    return row;
  }
  function showRead(message) {
    if (Number(message.sender_id) !== boot.userId) return;
    $('readMembers').replaceChildren(...(message.read_by.length ? message.read_by.map(m => element('p', '', m.username)) : [element('p', '', '尚無其他成員已讀')]));
    $('readDialog').showModal();
  }
  function showMessageActions(message, x = 0, y = 0, reactionsOnly = false) {
    if (state.busy || modalOpen() || message.recalled_at) return;
    state.selected = message;
    const buttons = [button('↩ 回覆訊息', () => { closeMessageMenu(); setReply(message); })];
    if (state.group.role === 'admin') buttons.push(button(Number(message.pinned) ? '📌 取消置頂' : '📌 置頂訊息', () => run(async () => {
      await action('pin', { message_id: Number(message.id) }); closeMessageMenu(); await refreshRoom();
    })));
    let recallButton = null;
    if (Number(message.sender_id) === boot.userId) {
      if (message.kind === 'text') buttons.push(button('✎ 編輯訊息', () => {
        closeMessageMenu(); $('editMessageText').value = message.content; $('editDialog').showModal();
      }));
      recallButton = button(message.kind === 'file' ? '收回檔案' : '收回訊息', () => run(async () => {
        if (!(await storageConfirm('確定收回？其他成員將無法再查看或下載，已另存的副本會保留。'))) return;
        await action('recall', { message_id: Number(message.id) });
        closeMessageMenu(); await refreshRoom(true);
      }), 'btn btn-outline-danger group-recall-button');
    }
    if (Number(message.sender_id) === boot.userId) buttons.push(button(`✓ 查看已讀（${message.read_by.length}）`, () => { closeMessageMenu(); showRead(message); }));
    const actions = element('div', 'group-action-grid');
    actions.setAttribute('role', 'group'); actions.setAttribute('aria-label', '訊息功能');
    actions.append(...buttons);
    const reactions = element('div', 'group-reaction-grid');
    reactions.setAttribute('role', 'group'); reactions.setAttribute('aria-label', '表情反應');
    for (const emoji of ['👍', '❤️', '😂', '😮', '😢', '🙏']) reactions.append(button(emoji, () => run(async () => {
      await action('react', { message_id: Number(message.id), emoji }); closeMessageMenu(); await refreshRoom(true);
    }), 'group-reaction-option'));
    $('messageActionButtons').replaceChildren(reactionsOnly ? reactions : actions);
    if (recallButton && !reactionsOnly) $('messageActionButtons').append(recallButton);
    const menu = $('messageDialog');
    menu.hidden = false;
    const rect = document.getElementById('group-message-' + Number(message.id)).getBoundingClientRect();
    menu.style.left = Math.max(12, Math.min(x || rect.left, innerWidth - menu.offsetWidth - 12)) + 'px';
    menu.style.top = Math.max(12, Math.min(y || rect.bottom, innerHeight - menu.offsetHeight - 12)) + 'px';
    menu.querySelector('button').focus({ preventScroll: true });
  }
  function renderMessage(message) {
    const sent = Number(message.sender_id) === boot.userId;
    const row = element('article', 'group-message' + (sent ? ' sent' : ''));
    row.id = 'group-message-' + Number(message.id);
    const bubble = element('div', 'group-bubble');
    bubble.dataset.messageId = message.id;
    bubble.tabIndex = 0;
    bubble.setAttribute('aria-label', message.username + ' 的訊息，右鍵或長按可操作');
    const header = element('div', 'group-message-heading');
    const time = element('time', 'group-message-meta', message.created_at.slice(5, 16) + (message.edited_at ? ' · 已編輯' : ''));
    time.dateTime = message.created_at.replace(' ', 'T');
    header.append(element('span', 'group-message-author', message.username), time);
    bubble.append(header);
    if (message.reply) bubble.append(element('div', 'group-quote', `回覆 ${message.reply.username}：${message.reply.original_filename || message.reply.content}`));
    bubble.append(element('div', 'group-message-content' + (message.kind === 'file' ? ' group-file-title' : ''), message.recalled_at ? '訊息已收回' : (message.kind === 'file' ? '📎 ' + message.original_filename : message.content)));
    row.append(bubble);
    if (!message.recalled_at) {
      if (message.kind === 'file') bubble.append(fileActions(message));
      const reactions = element('div', 'group-reactions');
      const counts = new Map();
      for (const reaction of message.reactions) {
        const data = counts.get(reaction.emoji) || { count: 0, mine: false, users: [] };
        data.count++; data.mine ||= Number(reaction.user_id) === boot.userId; data.users.push(reaction.username); counts.set(reaction.emoji, data);
      }
      for (const [emoji, data] of counts) {
        const react = button(`${emoji} ${data.count}`, () => run(async () => { await action('react', { message_id: Number(message.id), emoji }); await refreshRoom(true); }), data.mine ? 'mine' : '');
        react.title = data.users.join('、');
        react.setAttribute('aria-pressed', String(data.mine)); reactions.append(react);
      }
      const addReaction = button('☺', event => showMessageActions(message, event.clientX, event.clientY, true), 'group-add-reaction');
      addReaction.setAttribute('aria-label', '加入表情反應');
      addReaction.title = '加入表情反應'; reactions.append(addReaction);
      row.append(reactions);
    }
    if (sent && !message.recalled_at) row.append(button(`已讀 ${message.read_by.length}`, () => showRead(message), 'group-message-read'));
    return row;
  }
  function renderRoom(data, forceBottom = false) {
    state.group = data.group; state.members = data.members;
    const memberSignature = JSON.stringify(data.members.map(m => [m.user_id, m.username]));
    if (memberSignature !== state.memberSignature) { renderMentions(); state.memberSignature = memberSignature; }
    $('groupRoom').hidden = false; $('groupEmpty').hidden = true; $('groupShell').classList.add('group-open');
    $('groupTitle').textContent = data.group.name;
    $('groupMemberCount').textContent = data.members.length + ' 位成員';
    const signature = JSON.stringify(data);
    if (signature === state.signature && !forceBottom) return;
    state.signature = signature;
    const scroller = $('groupMessages');
    const previousTop = scroller.scrollTop;
    const oldHeight = scroller.scrollHeight;
    const nearBottom = oldHeight - previousTop - scroller.clientHeight < 90;
    state.messages = data.messages;
    $('groupTimeline').replaceChildren(...state.messages.map(renderMessage));
    $('olderMessages').hidden = !data.has_older;
    $('latestMessages').hidden = !state.historyBefore;
    $('groupStatusNotice').hidden = !data.group.paused;
    $('groupAnnouncement').hidden = !data.group.announcement;
    $('groupInput').disabled = $('groupSend').disabled = $('attachGroupFile').disabled = !!data.group.paused;
    $('groupAnnouncement').textContent = '📢 '+data.group.announcement;
    $('groupPins').hidden = !data.pins.length;
    $('groupPins').replaceChildren(...data.pins.map(pin => {
      const link = element('a', 'd-block text-light', '📌 ' + (pin.original_filename || pin.content).slice(0, 100));
      link.href = '#group-message-' + Number(pin.id);
      link.addEventListener('click', event => {
        event.preventDefault();
        run(async () => {
          if (!document.getElementById('group-message-' + Number(pin.id))) {
            state.historyBefore = Number(pin.id) + 1; state.signature = ''; await refreshRoom();
          }
          document.getElementById('group-message-' + Number(pin.id))?.scrollIntoView({ block: 'center' });
        });
      });
      return link;
    }));
    if (forceBottom || nearBottom) scroller.scrollTop = scroller.scrollHeight;
    else scroller.scrollTop = previousTop;
  }
  async function refreshRoom(forceBottom = false) {
    if (!boot.groupId || state.busy || modalOpen() || press) return;
    const version = state.version;
    if (forceBottom) state.historyBefore = 0;
    const before = state.historyBefore;
    const data = await apiGet({ group_id: boot.groupId, before });
    if (state.busy || modalOpen() || version !== state.version) return;
    if (before !== state.historyBefore) return;
    renderRoom(data, forceBottom);
    await markVisibleRead();
  }
  async function markVisibleRead() {
    const scroller = $('groupMessages');
    if (document.hidden || modalOpen() || state.busy || state.readPending || !state.messages.length || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight > 90) return;
    const lastId = Number(state.messages[state.messages.length - 1].id);
    if (lastId <= state.lastRead) return;
    state.readPending = true;
    try { await action('read', { last_id: lastId }); state.lastRead = lastId; } catch (error) { showError(error); }
    finally { state.readPending = false; }
  }
  function renderTools() {
    const admin = state.group.role === 'admin';
    $('adminSettings').hidden = !admin;
    $('editGroupName').value = state.group.name;
    $('editAnnouncement').value = state.group.announcement;
    $('groupNotifyMode').value = state.group.notification_mode;
    $('leaveGroup').hidden = Number(state.group.owner_id) === boot.userId;
    const owner = Number(state.group.owner_id) === boot.userId;
    $('ownerSettings').hidden = !owner;
    $('groupSettingsStatus').textContent = '';
    const others = state.members.filter(m => Number(m.user_id) !== boot.userId);
    $('transferOwnerMember').replaceChildren(...others.map(m => { const option = element('option','',m.username); option.value=m.user_id; return option; }));
    $('transferGroupOwner').disabled = !others.length;
    $('groupMembersList').replaceChildren(...state.members.map(member => {
      const row = element('div', 'group-member-row');
      row.append(element('span', '', member.username + (member.role === 'admin' ? ' · 管理員' : '')));
      if (admin && Number(member.user_id) !== Number(state.group.owner_id)) row.append(button('移除', () => run(async () => {
        if (!(await storageConfirm('確定移除此成員？對方將立即失去群組存取權。'))) return;
        await action('remove_member', { user_id: Number(member.user_id) }); $('toolsDialog').close(); await refreshRoom();
      }), 'btn btn-sm btn-outline-danger'));
      return row;
    }));
    const memberIds = new Set(state.members.map(m => Number(m.user_id)));
    $('addGroupMember').replaceChildren(...state.users.filter(u => !memberIds.has(Number(u.user_id))).map(u => { const option = element('option', '', u.username); option.value = u.user_id; return option; }));
    $('addMemberButton').disabled = !$('addGroupMember').options.length || state.members.length >= 100;
  }
  function renderMentions() {
    const input = $('groupInput');
    const before = input.value.slice(0, input.selectionStart);
    const match = before.match(/(?:^|[\s，。！？,.!?：:；;])@([^\s@]*)$/u);
    const list = $('mentionMembers');
    mentionOptions = match ? ['everyone', ...state.members.filter(m => Number(m.user_id) !== boot.userId).map(m => m.username)].filter(name => name.toLocaleLowerCase().startsWith(match[1].toLocaleLowerCase())) : [];
    mentionStart = match ? before.lastIndexOf('@') : -1;
    mentionIndex = 0;
    list.replaceChildren(...mentionOptions.map((name, index) => {
      const option = button('@' + name, () => selectMention(index), 'mention-option');
      option.id = 'mention-option-' + index; option.setAttribute('role', 'option');
      option.addEventListener('pointerdown', event => event.preventDefault());
      return option;
    }));
    list.hidden = !mentionOptions.length;
    updateMentionSelection();
  }
  let mentionOptions = [], mentionIndex = 0, mentionStart = -1;
  function updateMentionSelection() {
    [...$('mentionMembers').children].forEach((node, index) => node.setAttribute('aria-selected', String(index === mentionIndex)));
    $('groupInput').setAttribute('aria-expanded', String(!$('mentionMembers').hidden));
    if (!$('mentionMembers').hidden) $('groupInput').setAttribute('aria-activedescendant', 'mention-option-' + mentionIndex);
    else $('groupInput').removeAttribute('aria-activedescendant');
  }
  function selectMention(index) {
    const input = $('groupInput');
    const text = '@' + mentionOptions[index] + ' ';
    input.setRangeText(text, mentionStart, input.selectionStart, 'end');
    $('mentionMembers').hidden = true; updateMentionSelection(); input.focus();
  }
  $('groupInput').setAttribute('aria-controls', 'mentionMembers');
  $('groupInput').setAttribute('aria-autocomplete', 'list');
  $('groupInput').addEventListener('input', renderMentions);
  $('groupInput').addEventListener('click', renderMentions);
  $('groupInput').addEventListener('blur', () => { $('mentionMembers').hidden = true; updateMentionSelection(); });
  $('groupInput').addEventListener('keydown', event => {
    if (event.isComposing || event.keyCode === 229) return;
    if (!$('mentionMembers').hidden) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault(); mentionIndex = (mentionIndex + (event.key === 'ArrowDown' ? 1 : -1) + mentionOptions.length) % mentionOptions.length; updateMentionSelection(); return;
      }
      if ((event.key === 'Enter' && !event.shiftKey) || event.key === 'Tab') { event.preventDefault(); selectMention(mentionIndex); return; }
      if (event.key === 'Escape') { event.preventDefault(); $('mentionMembers').hidden = true; updateMentionSelection(); return; }
    }
    if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); if (!state.busy) $('groupComposer').requestSubmit(); }
  });
  async function loadFiles(append = false) {
    const offset = append ? $('groupFilesList').querySelectorAll('.group-file-row').length : 0;
    const data = await apiGet({ group_id: boot.groupId, view: 'files', search: $('groupFileSearch').value, offset });
    if (!append) $('groupFilesList').replaceChildren();
    $('groupFilesList').querySelector('[data-more]')?.remove();
    for (const file of data.files) {
      const row = element('div', 'group-file-row');
      row.append(element('strong', '', file.original_filename), element('small', '', `${file.username} · ${file.created_at} · ${Math.ceil(Number(file.file_size) / 1024)} KB`), fileActions(file));
      $('groupFilesList').append(row);
    }
    if (data.has_more) { const more = button('載入更多檔案', () => run(() => loadFiles(true))); more.dataset.more = '1'; $('groupFilesList').append(more); }
    if (!$('groupFilesList').childElementCount) $('groupFilesList').append(element('p', 'mt-3', '沒有符合的檔案'));
  }
  document.querySelectorAll('[data-close]').forEach(node => node.addEventListener('click', () => { if (!state.busy) node.closest('dialog').close(); }));
  document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('cancel', event => { if (state.busy) event.preventDefault(); }));
  $('createGroup').addEventListener('click', event => { event.preventDefault(); $('createDialog').showModal(); });
  ($('groupSearch') || $('conversationSearch')).addEventListener('input', renderGroups);
  $('createGroupForm').addEventListener('submit', event => { event.preventDefault(); run(async () => {
    const members = [...$('newGroupMembers').querySelectorAll('input:checked')].map(n => Number(n.value));
    const data = await action('create', { name: $('newGroupName').value, members });
    location.href = 'chat_history.php?group=' + data.group_id;
  }); });
  $('groupComposer').addEventListener('submit', event => { event.preventDefault(); run(async () => {
    const content = $('groupInput').value.trim(); if (!content) return;
    $('groupSend').disabled = true;
    try {
      await action('send', { content, reply_to_id: state.reply, ...chosenMentions() });
      $('groupInput').value = ''; clearReply();
      $('mentionMembers').hidden = true; updateMentionSelection();
      await refreshRoom(true);
    } finally { $('groupSend').disabled = !!state.group?.paused; }
  }); });
  $('cancelReply').addEventListener('click', clearReply);
  $('attachGroupFile').addEventListener('click', () => { $('groupUploadStatus').textContent = ''; $('fileDialog').showModal(); });
  $('fileSource').addEventListener('change', () => {
    const cloud = $('fileSource').value === 'cloud';
    $('groupUpload').hidden = $('uploadLabel').hidden = cloud;
    $('groupCloudFile').hidden = $('cloudLabel').hidden = !cloud;
  });
  $('groupFileForm').addEventListener('submit', event => { event.preventDefault(); run(async () => {
    const cloud = $('fileSource').value === 'cloud'; const file = $('groupUpload').files[0];
    if ((!cloud && !file) || (cloud && !$('groupCloudFile').value)) throw new Error('請選擇檔案');
    if (file && !cloud && file.size > 100 * 1024 * 1024) throw new Error('檔案不可超過 100MB');
    const submit = $('groupFileForm').querySelector('[type="submit"]'); submit.disabled = true;
    $('groupUploadStatus').textContent = '正在傳送，請稍候…';
    try {
      await action('file', { content: $('groupInput').value.trim(), reply_to_id: state.reply, cloud_file_id: cloud ? Number($('groupCloudFile').value) : 0, ...chosenMentions() }, cloud ? null : file);
      $('groupUpload').value = ''; $('groupInput').value = ''; renderMentions(); $('fileDialog').close(); clearReply(); await refreshRoom(true);
    } finally { submit.disabled = false; $('groupUploadStatus').textContent = ''; }
  }); });
  $('groupTools').addEventListener('click', () => { renderTools(); $('toolsDialog').showModal(); });
  async function saveSettings() { await run(async () => {
    if (state.group.role === 'admin' && !$('groupSettingsForm').reportValidity()) return;
    const save = $('saveGroupSettings'); save.disabled = true;
    window.dispatchEvent(new Event('notification-preferences-changed'));
    try { await action('save_settings', { mode: $('groupNotifyMode').value, ...(state.group.role === 'admin' ? {name:$('editGroupName').value,announcement:$('editAnnouncement').value} : {}) });
    localStorage.setItem('cloudchat:notification-preferences', String(Date.now()));
    window.dispatchEvent(new Event('notification-preferences-changed'));
    $('toolsDialog').close(); await refreshRoom();
    } finally { save.disabled = false; }
  }); }
  $('saveGroupSettings').addEventListener('click',saveSettings);
  $('groupSettingsForm').addEventListener('submit',event=>{event.preventDefault();saveSettings();});
  $('transferGroupOwner').addEventListener('click',()=>run(async()=>{
    const id=Number($('transferOwnerMember').value); if (!id || !(await storageConfirm('確定轉移群主？你將改為一般成員，之後可退出群組。'))) return;
    await action('transfer_owner',{user_id:id}); $('toolsDialog').close(); await refreshRoom();
  }));
  $('dissolveGroup').addEventListener('click',()=>run(async()=>{
    if (!(await storageConfirm('確定解散「'+state.group.name+'」？所有成員將失去群組，訊息與群組附件會刪除，已另存的副本保留。此操作無法復原。'))) return;
    await action('dissolve'); location.href='chat_history.php';
  }));
  $('addMemberButton').addEventListener('click', () => run(async () => { await action('add_member', { user_id: Number($('addGroupMember').value) }); $('toolsDialog').close(); await refreshRoom(); renderMentions(); }));
  $('leaveGroup').addEventListener('click', () => run(async () => { if (!(await storageConfirm('確定退出群組？退出後將無法再查看紀錄與檔案。'))) return; await action('leave'); location.href = 'chat_history.php'; }));
  $('editMessageForm').addEventListener('submit', event => { event.preventDefault(); run(async () => { await action('edit', { message_id: Number(state.selected.id), content: $('editMessageText').value }); $('editDialog').close(); await refreshRoom(); }); });
  $('openFiles').addEventListener('click', () => run(async () => { await loadFiles(); $('toolsDialog').close(); $('filesDialog').showModal(); }));
  window.addEventListener('chat-search-jump',event=>{
    if (Number(event.detail.group_id)!==boot.groupId) return;
    run(async()=>{
      const id=Number(event.detail.id); state.historyBefore=id+1; state.signature=''; await refreshRoom();
      const node=document.getElementById('group-message-'+id);
      if (!node) { storageNotice('訊息已被收回或刪除，請重新搜尋。'); return; }
      node.scrollIntoView({block:'center'}); node.classList.add('chat-search-highlight'); setTimeout(()=>node.classList.remove('chat-search-highlight'),2500);
    });
  });
  let fileSearchTimer;
  $('groupFileSearch').addEventListener('input', () => { clearTimeout(fileSearchTimer); fileSearchTimer = setTimeout(() => run(() => loadFiles()), 250); });
  $('olderMessages').addEventListener('click', () => run(async () => {
    if (!state.messages.length) return;
    state.historyBefore = Number(state.messages[0].id);
    state.signature = '';
    await refreshRoom();
    $('groupMessages').scrollTop = 0;
  }));
  $('latestMessages').addEventListener('click', () => run(() => refreshRoom(true)));
  function messageFromTarget(target) { const bubble = target.closest('.group-bubble'); return bubble ? state.messages.find(m => Number(m.id) === Number(bubble.dataset.messageId)) : null; }
  function cancelPress() { if (press) clearTimeout(press.timer); press = null; }
  $('groupTimeline').addEventListener('contextmenu', event => { const message = messageFromTarget(event.target); if (message && !message.recalled_at) { event.preventDefault(); cancelPress(); showMessageActions(message, event.clientX, event.clientY); } });
  $('groupTimeline').addEventListener('pointerdown', event => {
    cancelPress(); if (event.pointerType === 'mouse' || !event.isPrimary || event.target.closest('button,a')) return;
    const message = messageFromTarget(event.target); if (!message || message.recalled_at) return;
    const x = event.clientX, y = event.clientY;
    press = { x, y, timer: setTimeout(() => { press = null; stopClickUntil = Date.now() + 800; showMessageActions(message, x, y); }, 550) };
  });
  $('groupTimeline').addEventListener('pointermove', event => { if (press && Math.hypot(event.clientX - press.x, event.clientY - press.y) > 10) cancelPress(); });
  document.addEventListener('pointerup', cancelPress); document.addEventListener('pointercancel', cancelPress);
  $('groupMessages').addEventListener('scroll', () => { cancelPress(); closeMessageMenu(); markVisibleRead(); }, { passive: true });
  document.addEventListener('pointerdown', event => { if (!$('messageDialog').contains(event.target) && !event.target.closest('.group-message-more')) closeMessageMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMessageMenu(); });
  window.addEventListener('resize', closeMessageMenu);
  $('groupTimeline').addEventListener('click', event => { if (Date.now() < stopClickUntil) { event.preventDefault(); event.stopPropagation(); } }, true);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) run(() => refreshRoom()); });
  (async () => {
    try {
      await loadDirectory();
      if (boot.groupId) { await refreshRoom(true); renderMentions(); }
      if (boot.create) $('createDialog').showModal();
    } catch (error) { showError(error); }
  })();
  setInterval(async () => {
    if (document.hidden || state.busy || modalOpen()) return;
    try { if (boot.groupId) await refreshRoom(); const data = await apiGet(); state.groups = data.groups; renderGroups(); }
    catch (error) { showError(error); }
  }, 5000);
})();
