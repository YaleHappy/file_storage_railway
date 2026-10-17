window.installChatMessageActions = function({ container, csrfToken, onChange }) {
  const menu = document.createElement('div');
  menu.className = 'message-action-menu';
  menu.hidden = true;
  menu.setAttribute('role', 'menu');
  menu.setAttribute('aria-label', '訊息操作');
  menu.innerHTML = '<button type="button" role="menuitem" data-action="edit">編輯訊息</button><button type="button" role="menuitem" data-action="recall">收回訊息</button>';
  document.body.appendChild(menu);

  const editor = document.createElement('dialog');
  editor.className = 'chat-edit-dialog';
  editor.setAttribute('aria-labelledby', 'chatEditTitle');
  editor.innerHTML = '<form><h2 id="chatEditTitle" class="fs-5">編輯訊息</h2><label class="visually-hidden" for="chatEditText">訊息內容</label><textarea id="chatEditText" class="form-control" maxlength="2000" required></textarea><div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-secondary" data-cancel>取消</button><button type="submit" class="btn btn-primary">儲存</button></div></form>';
  document.body.appendChild(editor);
  const textarea = editor.querySelector('textarea');
  const saveButton = editor.querySelector('[type="submit"]');
  let selected = null;
  let pending = false;
  let revision = 0;
  let press = null;
  let suppressClickUntil = 0;
  let restoreFocus = null;

  function closeMenu() {
    menu.hidden = true;
  }
  function showMenu(bubble, x, y) {
    if (pending || editor.open) return;
    selected = { id: bubble.dataset.messageId, text: bubble.querySelector('.message-text').textContent };
    restoreFocus = bubble;
    menu.hidden = false;
    menu.style.left = `${Math.max(8, Math.min(x, window.innerWidth - menu.offsetWidth - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - menu.offsetHeight - 8))}px`;
    menu.querySelector('button').focus({ preventScroll: true });
  }
  function cancelPress() {
    if (press) clearTimeout(press.timer);
    press = null;
  }

  container.addEventListener('contextmenu', event => {
    const bubble = event.target.closest('[data-message-id]');
    if (!bubble) return;
    event.preventDefault();
    cancelPress();
    showMenu(bubble, event.clientX, event.clientY);
  });
  container.addEventListener('pointerdown', event => {
    cancelPress();
    if (event.pointerType === 'mouse' || !event.isPrimary) return;
    const bubble = event.target.closest('[data-message-id]');
    if (!bubble) return;
    const { clientX: x, clientY: y } = event;
    press = { x, y, timer: setTimeout(() => {
      press = null;
      suppressClickUntil = Date.now() + 800;
      showMenu(bubble, x, y);
    }, 550) };
  });
  container.addEventListener('pointermove', event => {
    if (press && Math.hypot(event.clientX - press.x, event.clientY - press.y) > 10) cancelPress();
  });
  document.addEventListener('pointerup', cancelPress);
  document.addEventListener('pointercancel', cancelPress);
  document.addEventListener('scroll', () => { cancelPress(); closeMenu(); }, true);
  window.addEventListener('resize', closeMenu);
  document.addEventListener('pointerdown', event => {
    if (!menu.contains(event.target)) closeMenu();
  });
  container.addEventListener('click', event => {
    if (Date.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);
  container.addEventListener('keydown', event => {
    const bubble = event.target.closest('[data-message-id]');
    if (bubble && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))) {
      event.preventDefault();
      const rect = bubble.getBoundingClientRect();
      showMenu(bubble, rect.left, rect.bottom);
    }
  });
  menu.addEventListener('keydown', event => {
    if (event.key === 'Escape' || event.key === 'Tab') {
      closeMenu();
      if (event.key === 'Escape') restoreFocus?.focus({ preventScroll: true });
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const buttons = [...menu.querySelectorAll('button')];
      buttons[(buttons.indexOf(document.activeElement) + 1) % buttons.length].focus();
    }
  });

  async function mutate(action, message = '') {
    if (!selected || pending) return false;
    pending = true;
    revision++;
    saveButton.disabled = true;
    try {
      const body = new URLSearchParams({ action, message, message_id: selected.id, csrf_token: csrfToken });
      const response = await fetch('chat_message_action.php', { method: 'POST', body });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.msg || '訊息操作失敗');
      if (editor.open) editor.close();
      return true;
    } catch (error) {
      window.alert(error.message || '操作失敗，請稍後再試');
      return false;
    } finally {
      pending = false;
      saveButton.disabled = false;
      await onChange();
    }
  }
  menu.addEventListener('click', async event => {
    const button = event.target.closest('[data-action]');
    if (!button || !selected) return;
    closeMenu();
    if (button.dataset.action === 'edit') {
      textarea.value = selected.text;
      editor.showModal();
      textarea.focus();
    } else if (window.confirm('確定收回這則訊息？雙方都將看不到此訊息。')) {
      await mutate('recall');
    }
  });
  editor.querySelector('[data-cancel]').addEventListener('click', () => {
    if (!pending) editor.close();
  });
  editor.addEventListener('cancel', event => { if (pending) event.preventDefault(); });
  editor.querySelector('form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!textarea.value.trim()) {
      textarea.setCustomValidity('請輸入訊息');
      textarea.reportValidity();
      return;
    }
    await mutate('edit', textarea.value.trim());
  });
  textarea.addEventListener('input', () => textarea.setCustomValidity(''));
  return {
    isBusy: () => pending || editor.open || !menu.hidden || press !== null,
    version: () => revision
  };
};
