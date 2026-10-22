(() => {
  const previewModal = document.getElementById('previewModal');
  if (previewModal) {
    const frame = document.getElementById('previewFrame');
    document.querySelectorAll('.file-preview').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      document.getElementById('filePreviewTitle').textContent = '檔案預覽';
      frame.src = link.href;
      bootstrap.Modal.getOrCreateInstance(previewModal).show();
    }));
    document.querySelectorAll('.file-row').forEach(card => card.addEventListener('dblclick', event => {
      if (event.target.closest('a, button, input, label, select, textarea, .file-actions')) return;
      card.querySelector('.file-preview')?.click();
    }));
    previewModal.addEventListener('hidden.bs.modal', () => frame.removeAttribute('src'));
  }
  document.querySelectorAll('.file-actions .btn').forEach(btn => { if (!btn.title) btn.title = btn.textContent.trim(); });
  const search = document.querySelector('.file-search');
  const filter = document.querySelector('.file-filter');
  const rows = [...document.querySelectorAll('.file-row')];
  const apply = () => {
    const query = search.value.trim().toLocaleLowerCase();
    let visible = 0;
    rows.forEach(row => {
      row.hidden = !row.dataset.name.toLocaleLowerCase().includes(query) || (filter.value !== '' && row.dataset.state !== filter.value);
      if (!row.hidden) visible++;
    });
    document.getElementById('fileEmpty').hidden = visible > 0;
    document.dispatchEvent(new Event('file-filtered'));
  };
  search?.addEventListener('input', apply);
  filter?.addEventListener('change', apply);
})();
