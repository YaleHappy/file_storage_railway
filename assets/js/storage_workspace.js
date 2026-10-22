(() => {
  const boxes = [...document.querySelectorAll('.file-card-inner .select-item')];
  const count = document.getElementById('selectedFileCount');
  const clear = document.getElementById('clearFileSelection');
  const remove = document.getElementById('bulkDeleteBtn');
  const update = () => {
    const selected = boxes.filter(box => box.checked).length;
    count.textContent = selected ? `已選取 ${selected} 個檔案` : '未選取檔案';
    clear.hidden = selected === 0;
    remove.disabled = selected === 0;
  };
  document.addEventListener('storage-selection-reset', update);
  const preview = document.getElementById('previewModal');
  preview?.addEventListener('hidden.bs.modal', () => document.getElementById('previewFrame').removeAttribute('src'));
  boxes.forEach(box => box.addEventListener('change', update));
  clear.addEventListener('click', () => { boxes.forEach(box => { box.checked = false; }); update(); });
  document.querySelectorAll('.folder-actions .btn').forEach(btn => { btn.title = btn.textContent.trim(); });
  document.querySelectorAll('.home-page .modal').forEach(modal => modal.addEventListener('shown.bs.modal', () => {
    modal.querySelector('input:not([type=hidden]),select,textarea')?.focus();
  }));
  update();
})();
