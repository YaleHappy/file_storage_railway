(() => {
  const storageKeyPrefix = 'cloudchat:last-notification-id';
  const pollEveryMs = 3000;
  let audioContext = null;
  let initialized = false;
  let pendingSound = false;

  async function unlockAudio() {
    if (!audioContext) {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) return;
      audioContext = new AudioContextClass();
    }
    if (audioContext.state === 'suspended') {
      try {
        await audioContext.resume();
      } catch (_) {
        return;
      }
    }
    if (pendingSound && audioContext.state === 'running') {
      pendingSound = false;
      playMessageSound();
    }
  }

  function playMessageSound() {
    if (!audioContext || audioContext.state !== 'running') return;
    const start = audioContext.currentTime;
    [0, 0.12].forEach((offset, index) => {
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = index === 0 ? 660 : 880;
      gain.gain.setValueAtTime(0.0001, start + offset);
      gain.gain.exponentialRampToValueAtTime(0.16, start + offset + 0.015);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.1);
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start(start + offset);
      oscillator.stop(start + offset + 0.11);
    });
  }

  async function checkNewMessages() {
    if (document.hidden && !initialized) return;
    try {
      const response = await fetch(`check_notifications.php?_=${Date.now()}`, {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!data.success) return;

      const notifications = data.notifications || [];
      const latestId = notifications.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0);
      const storageKey = `${storageKeyPrefix}:${Number(data.user_id) || 'unknown'}`;
      const storedId = Number(localStorage.getItem(storageKey) || 0);

      if (!initialized && storedId === 0) {
        localStorage.setItem(storageKey, String(latestId));
        initialized = true;
        return;
      }

      initialized = true;
      const newMessageExists = notifications.some(item =>
        item.type === 'message' && Number(item.id) > storedId
      );

      if (latestId > storedId) {
        localStorage.setItem(storageKey, String(latestId));
      }

      const isDnd = document.documentElement.dataset.presenceStatus === 'away'
        || (window.presenceIsDnd && window.presenceIsDnd());
      if (newMessageExists && !isDnd) {
        if (audioContext && audioContext.state === 'running') {
          playMessageSound();
        } else {
          pendingSound = true;
        }
      }
    } catch (error) {
      console.error('新訊息提示檢查失敗', error);
    }
  }

  window.addEventListener('pointerdown', unlockAudio, { once: true, passive: true });
  window.addEventListener('keydown', unlockAudio, { once: true });
  window.addEventListener('touchstart', unlockAudio, { once: true, passive: true });

  checkNewMessages();
  window.setInterval(checkNewMessages, pollEveryMs);
})();
