(() => {
  const input = document.getElementById('searchInput'), section = document.getElementById('cloudSearchResults');
  const workspace = document.getElementById('storageWorkspace'), scope = document.getElementById('cloudSearchScope');
  const list = document.getElementById('cloudSearchList'), status = document.getElementById('cloudSearchStatus');
  const previous = document.getElementById('cloudSearchPrevious'), next = document.getElementById('cloudSearchNext');
  let timer, controller, version = 0, page = 1;
  const node = (tag, cls, text) => { const element = document.createElement(tag); element.className = cls; if (text) element.textContent = text; return element; };
  function clear() {
    clearTimeout(timer); controller?.abort(); version++; input.value = ''; section.hidden = true;
    workspace.classList.remove('cloud-search-active'); list.replaceChildren(); list.setAttribute('aria-busy','false');
    const url = new URL(location.href); url.searchParams.delete('q'); url.searchParams.delete('scope'); history.replaceState(null,'',url); input.focus();
  }
  function render(result) {
    const row = node('article','cloud-search-row');
    row.append(node('i',result.kind === 'folder' ? 'bi bi-folder-fill cloud-search-icon folder' : 'bi bi-file-earmark-text cloud-search-icon'));
    const info = node('div','cloud-search-info'), title = node('button','cloud-search-name',result.name); title.type = 'button'; title.title = result.name;
    const open = () => result.kind === 'folder' ? location.assign('index.php?folder='+result.id) : openPreviewModal(result.id);
    title.addEventListener('click',open); info.append(title);
    const path = node('a','cloud-search-path',result.path); path.href = 'index.php?folder='+result.parent_id; path.title = '開啟所在資料夾：'+result.path; info.append(path);
    const bytes = result.bytes >= 1048576 ? (result.bytes/1048576).toFixed(1)+' MB' : (result.bytes/1024).toFixed(1)+' KB';
    info.append(node('small','cloud-search-meta',result.kind === 'folder' ? '資料夾' : bytes+(result.updated_at ? ' · '+result.updated_at.slice(0,16) : ''))); row.append(info);
    const action = node('button','btn storage-secondary cloud-search-action',result.kind === 'folder' ? '開啟' : '預覽'); action.type='button'; action.addEventListener('click',open); row.append(action);
    row.addEventListener('dblclick',event=>{ if (!event.target.closest('a,button,input')) open(); }); return row;
  }
  async function search(targetPage = 1) {
    clearTimeout(timer); const q = input.value.trim(); if (!q) { clear(); return; }
    controller?.abort(); controller = new AbortController(); const current = ++version;
    page = targetPage; section.hidden = false; workspace.classList.add('cloud-search-active');
    document.querySelectorAll('.file-card-inner .select-item').forEach(box=>{box.checked=false;}); document.dispatchEvent(new Event('storage-selection-reset'));
    list.replaceChildren(); list.setAttribute('aria-busy','true'); status.textContent='搜尋中…'; previous.hidden=next.hidden=true; document.getElementById('cloudSearchPage').textContent='';
    const url = new URL(location.href); url.searchParams.set('q',q); url.searchParams.set('scope',scope.value); history.replaceState(null,'',url);
    try {
      const response = await fetch('cloud_search.php?'+new URLSearchParams({q,scope:scope.value,folder:section.dataset.folder,page}),{signal:controller.signal,cache:'no-store'});
      const data = await response.json(); if (current !== version) return; if (!response.ok || !data.success) throw new Error(data.msg || '搜尋失敗');
      page = data.page; status.textContent = '「'+q+'」找到 '+data.total+' 個項目'; list.replaceChildren(...data.results.map(render));
      if (!data.total) list.append(node('p','cloud-search-empty','找不到符合的檔案或資料夾，請換個關鍵字或搜尋範圍。'));
      previous.hidden = page <= 1; next.hidden = page >= data.pages;
      document.getElementById('cloudSearchPage').textContent = data.pages > 1 ? page+' / '+data.pages+' 頁' : '';
    } catch(error) { if (current !== version || error.name === 'AbortError') return; status.textContent=error.message; const retry=node('button','btn storage-secondary','重新搜尋'); retry.type='button'; retry.addEventListener('click',()=>search(page)); list.append(retry); }
    finally { if (current === version) list.setAttribute('aria-busy','false'); }
  }
  input.maxLength=200;
  input.addEventListener('input',()=>{ clearTimeout(timer); controller?.abort(); version++; if (!input.value.trim()) clear(); else timer=setTimeout(()=>search(),300); });
  input.addEventListener('keydown',event=>{ if (event.key==='Enter' && !event.isComposing) {event.preventDefault();search();} if(event.key==='Escape') clear(); });
  scope.addEventListener('change',()=>search()); document.getElementById('cloudSearchClear').addEventListener('click',clear);
  previous.addEventListener('click',()=>search(page-1)); next.addEventListener('click',()=>search(page+1));
  const initial = new URLSearchParams(location.search); if (initial.get('q')) {input.value=initial.get('q'); scope.value=initial.get('scope')==='folder'?'folder':'cloud';search();}
})();
