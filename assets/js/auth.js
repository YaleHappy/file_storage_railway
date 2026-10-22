document.querySelectorAll('.auth-password-toggle').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.target);
    const show = input.type === 'password'; input.type = show ? 'text' : 'password';
    button.textContent = show ? '隱藏' : '顯示'; button.setAttribute('aria-pressed',String(show));
    button.setAttribute('aria-label',(show ? '隱藏' : '顯示')+button.dataset.label);
  });
});
document.querySelector('.auth-alert[role="alert"]')?.focus();
