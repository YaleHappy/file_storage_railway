(() => {
const notificationBadge = document.querySelector('.notification-badge');
const notificationCount = document.querySelector('.notification-count');
const notificationPage = document.querySelector('[data-notifications-page]');
const notificationsContainer = notificationPage?.querySelector('.notifications-container') || document.querySelector('.notifications-container');
const notificationDropdown = document.getElementById('notificationDropdown');
const clearAllNotifications = notificationPage?.querySelector('[data-mark-all]') || document.getElementById('clearAllNotifications');
let deletionPending = false;
let notificationVersion = 0;

function formatNotificationTime(value) {
  return String(value || '').replace('T', ' ').slice(0, 16);
}

function updateNotificationBadge(count) {
  notificationCount.textContent = count;
  notificationBadge.style.display = count > 0 ? 'block' : 'none';
}

function renderNotifications(notifications) {
  notificationsContainer.replaceChildren();
  if (!notifications.length) {
    const empty = document.createElement('div');
    empty.className = 'text-center p-3 text-muted';
    empty.textContent = '沒有通知';
    notificationsContainer.appendChild(empty);
  }

  notifications.forEach(notification => {
    const item = document.createElement('div');
    item.className = 'notification-item p-2 border-bottom';
    item.tabIndex = 0;
    item.setAttribute('role', 'button');

    const row = document.createElement('div');
    row.className = 'd-flex align-items-start';
    const icon = document.createElement('div');
    icon.className = 'notification-icon me-2';
    icon.textContent = notification.group_id ? '👥' : (notification.type === 'file' ? '📎' : (notification.type === 'system' ? '⚙️' : '💬'));
    const content = document.createElement('div');
    content.className = 'notification-content flex-grow-1';
    const message = document.createElement('div');
    message.className = 'notification-message';
    message.textContent = notification.message;
    const time = document.createElement('small');
    time.className = 'text-muted';
    time.textContent = formatNotificationTime(notification.created_at);
    content.append(message, time);
    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'btn btn-outline-light btn-sm delete-notification-btn ms-2 flex-shrink-0';
    deleteButton.title = '刪除通知';
    deleteButton.setAttribute('aria-label', '刪除通知');
    deleteButton.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
    deleteButton.addEventListener('click', async event => {
      event.preventDefault();
      event.stopPropagation();
      if (deletionPending) return;
      deletionPending = true;
      notificationVersion++;
      deleteButton.disabled = true;
      try {
        const body = new FormData();
        body.append('notification_id', notification.id);
        const response = await fetch('clear_notifications.php', { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.msg || '刪除通知失敗');
        item.remove();
      } catch (error) {
        storageNotice(error.message || '刪除通知失敗，請稍後再試');
        deleteButton.disabled = false;
      } finally {
        deletionPending = false;
        await loadNotifications();
      }
    });
    const readButton = document.createElement('button');
    readButton.type = 'button';
    readButton.className = 'btn btn-sm text-light flex-shrink-0';
    readButton.textContent = '✓';
    readButton.title = '標記為已讀';
    readButton.setAttribute('aria-label', '標記為已讀');
    readButton.addEventListener('click', async event => {
      event.preventDefault(); event.stopPropagation();
      readButton.disabled = true;
      try {
        const body = new FormData(); body.append('notification_id', notification.id);
        const response = await fetch('mark_notifications_read.php', { method: 'POST', body });
        const data = await response.json();
        if (!data.success) throw new Error(data.msg || '無法標記已讀');
        await loadNotifications();
      } catch (error) { storageNotice(error.message); readButton.disabled = false; }
    });
    row.append(icon, content, readButton, deleteButton);
    item.appendChild(row);

    const openNotification = () => {
      const target = notification.group_id ? `chat_history.php?group=${Number(notification.group_id)}` : notification.type === 'message'
        ? `chat_history.php?user=${Number(notification.sender_id)}`
        : (notification.type === 'file' ? 'received_files.php' : '');
      if (target) window.location.href = target;
    };
    item.addEventListener('click', openNotification);
    item.addEventListener('keydown', event => {
      if (event.target !== item) return;
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openNotification();
      }
    });
    notificationsContainer.appendChild(item);
  });

  const footer = document.createElement('div');
  footer.className = 'text-center p-3';
  const clearHistoryButton = document.createElement('button');
  clearHistoryButton.type = 'button';
  clearHistoryButton.className = 'btn btn-outline-danger btn-sm';
  clearHistoryButton.textContent = '清除所有通知歷史';
  clearHistoryButton.addEventListener('click', async event => {
    event.preventDefault();
    event.stopPropagation();
    if (deletionPending || !(await storageConfirm('確定要清除所有通知歷史嗎？此操作不可恢復。'))) return;
    deletionPending = true;
    notificationVersion++;
    clearHistoryButton.disabled = true;
    try {
      const body = new FormData();
      body.append('clear_history', '1');
      const response = await fetch('clear_notifications.php', { method: 'POST', body });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.msg || '清除通知歷史失敗');
      updateNotificationBadge(0);
      renderNotifications([]);
    } catch (error) {
      storageNotice(error.message || '清除通知歷史失敗，請稍後再試');
      clearHistoryButton.disabled = false;
    } finally {
      deletionPending = false;
      await loadNotifications();
    }
  });
  footer.appendChild(clearHistoryButton);
  notificationsContainer.appendChild(footer);
}

async function loadNotifications() {
  if (deletionPending) return;
  const version = notificationVersion;
  try {
    const response = await fetch(`check_notifications.php?_=${Date.now()}${notificationPage ? '&page_view=1' : ''}`, { cache: 'no-store' });
    const data = await response.json();
    if (data.success && !deletionPending && version === notificationVersion) {
      updateNotificationBadge(Number(data.total_unread));
      renderNotifications(data.notifications || []);
    }
  } catch (error) {
    console.error('載入通知失敗', error);
  }
}

async function markAllNotificationsAsRead() {
  const body = new FormData();
  body.append('clear_all', '1');
  try {
    const response = await fetch('mark_notifications_read.php', { method: 'POST', body });
    const data = await response.json();
    if (data.success) {
      updateNotificationBadge(0);
      await loadNotifications();
    }
  } catch (error) {
    console.error('標記通知失敗', error);
  }
}

clearAllNotifications.addEventListener('click', event => {
  event.preventDefault();
  event.stopPropagation();
  markAllNotificationsAsRead();
});

notificationDropdown.addEventListener('shown.bs.dropdown', () => {
  if (Number(notificationCount.textContent) > 0) markAllNotificationsAsRead();
});

loadNotifications();
window.refreshNotifications = loadNotifications;
window.addEventListener('focus', loadNotifications);
window.setInterval(loadNotifications, 10000);
})();
