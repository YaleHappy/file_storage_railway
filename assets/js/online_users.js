(() => {
  const $ = id => document.getElementById(id);
  const panel = $('onlineUsersPanel'), toggle = $('onlineUsersToggle'), list = $('onlineUsersList');
  const dialog = $('onlineUserFileDialog'), form = $('onlineUserFileForm');
  let recipient = 0, busy = false, loading = false;
  const backdrop = $('onlineUsersBackdrop');
  const navbar = document.querySelector('nav.navbar .container') || document.querySelector('nav.navbar');
  let previousFocus = null;
  toggle.classList.add('online-users-nav');
  toggle.setAttribute('aria-label', '上線使用者');
  toggle.title = '上線使用者';
  toggle.innerHTML = '<i class="bi bi-people-fill" aria-hidden="true"></i><span class="online-users-nav-label">上線使用者</span>';
  const desktopNavigation = matchMedia('(min-width: 992px)');
  const shareItem = navbar?.querySelector('a[href="my_shares.php"]')?.closest('li');
  const onlineItem = document.createElement('li');
  onlineItem.className = 'nav-item online-users-nav-item';
  if (shareItem) shareItem.before(onlineItem);
  function placeNavigationToggle() {
    if (desktopNavigation.matches && shareItem) {
      onlineItem.hidden = false; onlineItem.append(toggle);
    } else if (navbar) {
      onlineItem.hidden = true;
      navbar.insertBefore(toggle, navbar.querySelector('.navbar-toggler') || navbar.querySelector('.navbar-collapse'));
    }
  }
  desktopNavigation.addEventListener('change', placeNavigationToggle);
  placeNavigationToggle();
  function closePanel(restoreFocus = true) {
    const wasOpen = !panel.hidden;
    panel.hidden = true; backdrop.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('online-users-open');
    if (wasOpen && restoreFocus) (previousFocus || toggle).focus();
  }
  function openPanel() {
    previousFocus = document.activeElement;
    panel.hidden = false; backdrop.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    document.body.classList.add('online-users-open');
    $('onlineUsersClose').focus(); refresh();
  }
  async function refresh() {
    if (loading || panel.hidden || document.hidden) return;
    loading = true;
    try {
      const response = await fetch('user_status.php', {cache:'no-store'}), data = await response.json();
      if(response.status===401 || response.status===403)throw new Error('登入已失效，請重新登入後再試。');
      if (!response.ok || !data.success) throw new Error(data.error || data.msg || '無法載入使用者');
      const users = data.users.filter(user => Number(user.user_id) !== Number(panel.dataset.currentUser) && ['online','idle','away'].includes(user.status));
      list.replaceChildren();
      for (const user of users) {
        const item = document.createElement('li'), info = document.createElement('div'), name = document.createElement('strong'), status = document.createElement('span'), actions = document.createElement('div');
        info.className = 'online-user-info'; name.textContent = user.username; status.className = 'online-user-status';
        status.textContent = {online:'🟢 在線',idle:'🌙 閒置',away:'🔴 請勿打擾'}[user.status]; info.append(name,status);
        actions.className = 'online-user-actions';
        const chat = document.createElement('a'); chat.className = 'btn btn-primary btn-sm'; chat.textContent = '訊息'; chat.href = 'chat_history.php?user=' + Number(user.user_id);
        const file = document.createElement('button'); file.type = 'button'; file.className = 'btn btn-secondary btn-sm'; file.textContent = '檔案';
        file.addEventListener('click', () => openFile(user)); actions.append(chat,file); item.append(info,actions); list.append(item);
      }
      if (!users.length) list.textContent = '目前沒有上線使用者';
    } catch (error) { list.textContent = error.message || '無法載入使用者'; }
    finally { loading = false; }
  }
  async function openFile(user) {
    if (busy) return;
    recipient = Number(user.user_id); form.reset(); $('onlineUserFileStatus').textContent = '';
    $('onlineUserFileRecipient').textContent = '傳送給：' + user.username;
    changeSource(); closePanel(); dialog.showModal();
    $('onlineUserCloud').replaceChildren();
    try {
      const response = await fetch('group_api.php', {cache:'no-store'}), data = await response.json();
      if (!data.success) throw new Error('雲端檔案載入失敗');
      for (const file of data.files) { const option = document.createElement('option'); option.value = file.file_id; option.textContent = file.original_filename; $('onlineUserCloud').append(option); }
    } catch (error) { $('onlineUserFileStatus').textContent = error.message + '，仍可上傳新檔案。'; }
  }
  function changeSource() {
    const cloud = $('onlineUserFileSource').value === 'select';
    $('onlineUserUpload').hidden = $('onlineUserUploadLabel').hidden = cloud;
    $('onlineUserCloud').hidden = $('onlineUserCloudLabel').hidden = !cloud;
  }
  toggle.addEventListener('click', () => panel.hidden ? openPanel() : closePanel());
  $('onlineUsersClose').addEventListener('click', () => closePanel());
  backdrop.addEventListener('click', () => closePanel());
  document.addEventListener('keydown', event => {
    if (panel.hidden || dialog.open) return;
    if (event.key === 'Escape') { event.preventDefault(); closePanel(); }
    if (event.key === 'Tab') {
      const controls = [...panel.querySelectorAll('button,a[href],input,select,[tabindex="0"]')].filter(node => !node.disabled && node.getClientRects().length);
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  $('onlineUserFileSource').addEventListener('change',changeSource);
  window.addEventListener('open-user-file', event => {
    const user = event.detail;
    if (Number(user?.user_id) > 0 && Number(user.user_id) !== Number(panel.dataset.currentUser)) openFile(user);
  });
  $('onlineUserFileCancel').addEventListener('click', () => { if (!busy) dialog.close(); });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return;
    const cloud = $('onlineUserFileSource').value === 'select', file = $('onlineUserUpload').files[0];
    if ((cloud && !$('onlineUserCloud').value) || (!cloud && !file)) { $('onlineUserFileStatus').textContent = '請選擇檔案'; return; }
    if (!cloud && file.size > 100*1024*1024) { $('onlineUserFileStatus').textContent = '檔案不可超過 100MB'; return; }
    const body = new FormData(); body.append('recipient_id',recipient); body.append('file_option',cloud?'select':'upload');
    if (cloud) body.append('existing_file_id',$('onlineUserCloud').value); else body.append('file',file);
    busy = true; form.querySelector('[type="submit"]').disabled = true; $('onlineUserFileStatus').textContent = '傳送中…';
    try {
      const response = await fetch('send_file.php',{method:'POST',body}), data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.msg || '傳送失敗');
      $('onlineUserFileStatus').textContent = '檔案已傳送'; form.reset(); changeSource();
      window.dispatchEvent(new CustomEvent('private-file-sent', {detail:{recipientId:recipient}}));
    } catch (error) { $('onlineUserFileStatus').textContent = error.message || '傳送失敗'; }
    finally { busy = false; form.querySelector('[type="submit"]').disabled = false; }
  });
  window.addEventListener('refresh-online-users',refresh);
  setInterval(refresh,10000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
