(() => {
  const notifications = document.querySelector('.navbar-notifications');
  if (!notifications) return;
  const trigger = notifications.querySelector('#notificationDropdown');
  const originalPosition = document.createComment('desktop notifications');
  notifications.before(originalPosition);
  const nav = document.createElement('nav');
  nav.className = 'mobile-bottom-nav';
  nav.setAttribute('aria-label', '主要功能');
  const currentPage = location.pathname.split('/').pop() || 'index.php';
  const entries = [
    ['index.php', '首頁', 'bi-house-door'],
    ['my_shares.php', '分享', 'bi-link-45deg'],
    ['received_files.php', '收件', 'bi-inbox'],
    ['chat_history.php', '聊天', 'bi-chat-dots']
  ];
  for (const [href, label, icon] of entries) {
    const link = document.createElement('a');
    link.href = href;
    link.className = 'mobile-nav-item';
    if (href === currentPage || (currentPage === 'group_chat.php' && href === 'chat_history.php')) link.setAttribute('aria-current', 'page');
    const symbol = document.createElement('i');
    symbol.className = `bi ${icon}`;
    symbol.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = label;
    link.append(symbol, text);
    nav.appendChild(link);
  }
  document.body.appendChild(nav);
  const mobile = matchMedia('(max-width: 991.98px)');
  function updateLayout() {
    window.bootstrap?.Dropdown.getInstance(trigger)?.hide();
    document.body.classList.toggle('has-mobile-navigation', mobile.matches);
    if (mobile.matches) {
      nav.appendChild(notifications);
    } else {
      originalPosition.after(notifications);
    }
    // A Bootstrap dropdown caches its positioning parent. Recreate after moving.
    window.bootstrap?.Dropdown.getInstance(trigger)?.dispose();
  }
  mobile.addEventListener('change', updateLayout);
  updateLayout();
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') window.bootstrap?.Dropdown.getInstance(trigger)?.hide();
  });
})();
