(() => {
  const shell = document.querySelector('.history-shell');
  if (!shell) return;
  let scheduled = false;
  function fit() {
    scheduled = false;
    const scroller = document.getElementById('chatMessages') || document.getElementById('groupMessages');
    const wasAtBottom = scroller && scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 90;
    const nav = document.querySelector('nav[aria-label="主要功能"]');
    const navHeight = nav && getComputedStyle(nav).position === 'fixed' && getComputedStyle(nav).display !== 'none' ? nav.getBoundingClientRect().height : 0;
    const viewHeight = window.visualViewport?.height || innerHeight;
    const top = shell.getBoundingClientRect().top + window.scrollY;
    shell.style.setProperty('--chat-available-height', Math.max(180, viewHeight - top - navHeight - 12) + 'px');
    if (wasAtBottom) scroller.scrollTop = scroller.scrollHeight;
  }
  function schedule() { if (!scheduled) { scheduled = true; requestAnimationFrame(fit); } }
  window.addEventListener('resize', schedule);
  window.addEventListener('load', schedule);
  window.visualViewport?.addEventListener('resize', schedule);
  fit();
})();
