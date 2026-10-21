(() => {
  const idleAfterMs = 10 * 60 * 1000;
  const heartbeatEveryMs = 15 * 1000;
  const activitySendThrottleMs = 5 * 1000;
  const statusLabels = {
    online: '🟢 在線',
    idle: '🌙 閒置',
    away: '🔴 請勿打擾',
    hidden: '⚪ 隱形',
    offline: '⚪ 離線'
  };

  let lastInteractionAt = Date.now();
  let lastActivitySentAt = 0;
  let currentStatus = document.documentElement.dataset.presenceStatus || 'online';
  let currentMode = document.documentElement.dataset.presenceMode || 'auto';

  function clientState() {
    if (document.hidden) return 'background';
    return Date.now() - lastInteractionAt >= idleAfterMs ? 'idle' : 'active';
  }

  function applyPresence(data) {
    if (!data || !data.success) return;
    currentStatus = data.status;
    currentMode = data.mode;
    const currentStatusElement = document.getElementById('currentStatus');
    if (currentStatusElement) {
      currentStatusElement.textContent = data.label || statusLabels[currentStatus] || statusLabels.offline;
    }
    document.documentElement.dataset.presenceStatus = currentStatus;
    document.documentElement.dataset.presenceMode = currentMode;
    window.dispatchEvent(new CustomEvent('presence:updated', { detail: data }));
  }

  async function sendPresence({ activity = false, backgroundStart = false } = {}) {
    const state = clientState();
    if (state === 'background' && !backgroundStart) return;

    const body = new URLSearchParams({
      state,
      activity: activity ? '1' : '0',
      background_start: backgroundStart ? '1' : '0'
    });

    try {
      const response = await fetch('update_last_active.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true
      });
      applyPresence(await response.json());
    } catch (error) {
      console.error('狀態同步失敗', error);
    }
  }

  function recordActivity() {
    if (document.hidden) return;
    const wasIdle = Date.now() - lastInteractionAt >= idleAfterMs;
    lastInteractionAt = Date.now();
    if (wasIdle || Date.now() - lastActivitySentAt >= activitySendThrottleMs) {
      lastActivitySentAt = Date.now();
      sendPresence({ activity: true });
    }
  }

  async function setManualMode(status) {
    const body = new URLSearchParams({ status });
    try {
      const response = await fetch('update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.error || '狀態更新失敗');
      lastInteractionAt = Date.now();
      applyPresence(data);
    } catch (error) {
      window.alert(error.message || '無法更新狀態');
    }
  }

  async function refreshVisibleStatuses() {
    if (document.hidden) return;
    try {
      const response = await fetch(`user_status.php?_=${Date.now()}`, {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!data.success) return;
      applyPresence(data);

      const labels = {
        online: '在線',
        idle: '閒置',
        away: '請勿打擾',
        hidden: '離線',
        offline: '離線'
      };
      (data.users || []).forEach(user => {
        document.querySelectorAll(`[data-presence-user="${Number(user.user_id)}"]`).forEach(element => {
          element.classList.remove('online', 'idle', 'away', 'hidden', 'offline');
          element.classList.add(user.status);
          element.title = labels[user.status] || '離線';
          element.setAttribute('aria-label', labels[user.status] || '離線');
        });
      });
      window.dispatchEvent(new CustomEvent('presence:users', { detail: data.users || [] }));
    } catch (error) {
      console.error('使用者狀態更新失敗', error);
    }
  }

  document.addEventListener('click', event => {
    const option = event.target.closest('.status-option');
    if (!option) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    setManualMode(option.dataset.status);
  }, true);

  ['pointermove', 'pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(eventName => {
    window.addEventListener(eventName, recordActivity, { passive: true });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      sendPresence({ backgroundStart: true });
    } else {
      lastInteractionAt = Date.now();
      lastActivitySentAt = Date.now();
      sendPresence({ activity: true });
    }
  });

  window.addEventListener('pagehide', () => {
    const body = new URLSearchParams({
      state: 'background',
      activity: '0',
      background_start: '1'
    });
    navigator.sendBeacon('update_last_active.php', body);
  });

  window.presenceIsDnd = () => currentStatus === 'away';
  window.presenceRefresh = () => sendPresence();

  lastActivitySentAt = Date.now();
  (async () => {
    await sendPresence({ activity: true });
    await refreshVisibleStatuses();
  })();
  window.setInterval(() => sendPresence(), heartbeatEveryMs);
  window.setInterval(refreshVisibleStatuses, 5000);
})();
