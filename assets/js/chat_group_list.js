(() => {
  const list = document.getElementById('historyGroupList');
  if (!list) return;
  let previous = '';
  async function refresh() {
    if (document.hidden) return;
    try {
      const response = await fetch('group_api.php?view=list', { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.success || JSON.stringify(data.groups) === previous) return;
      previous = JSON.stringify(data.groups);
      const keyword = document.getElementById('conversationSearch').value.trim().toLocaleLowerCase('zh-TW');
      const links = data.groups.map(group => {
        const link = document.createElement('a');
        link.className = 'conversation-item';
        link.href = 'chat_history.php?group=' + Number(group.id);
        link.dataset.username = group.name.toLocaleLowerCase('zh-TW');
        link.hidden = !link.dataset.username.includes(keyword);
        const row = document.createElement('div');
        row.className = 'conversation-name-row';
        const name = document.createElement('span');
        name.className = 'conversation-name'; name.textContent = '👥 ' + group.name; row.append(name);
        if (Number(group.unread_count)) { const badge = document.createElement('span'); badge.className = 'badge bg-danger'; badge.textContent = group.unread_count; row.append(badge); }
        const preview = document.createElement('div'); preview.className = 'conversation-preview'; preview.textContent = group.last_content || '開始群組對話';
        const count = document.createElement('small'); count.textContent = group.member_count + ' 位成員' + (group.notification_mode === 'mute' ? ' · 已靜音' : '');
        link.append(row, preview, count); return link;
      });
      list.replaceChildren(...links);
    } catch (error) { console.error('無法更新群組列表', error); }
  }
  refresh();
  setInterval(refresh, 10000);
})();
