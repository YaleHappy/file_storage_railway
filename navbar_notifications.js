(() => {
const notificationBadge = document.querySelector('.notification-badge');
const notificationCount = document.querySelector('.notification-count');
const notificationsContainer = document.querySelector('.notifications-container');
const notificationDropdown = document.getElementById('notificationDropdown');
const clearAllNotifications = document.getElementById('clearAllNotifications');
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
    return;
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
    icon.textContent = notification.type === 'file' ? '📎' : (notification.type === 'system' ? '⚙️' : '💬');
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
        window.alert(error.message || '刪除通知失敗，請稍後再試');
        deleteButton.disabled = false;
      } finally {
        deletionPending = false;
        await loadNotifications();
      }
    });
    row.append(icon, content, deleteButton);
    item.appendChild(row);

    const openNotification = () => {
      const target = notification.type === 'message'
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
}

async function loadNotifications() {
  if (deletionPending) return;
  const version = notificationVersion;
  try {
    const response = await fetch(`check_notifications.php?_=${Date.now()}`, { cache: 'no-store' });
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
window.setInterval(loadNotifications, 10000);
})();
