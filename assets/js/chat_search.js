(() => {
  const $ = id => document.getElementById(id), dialog = $('chatSearchDialog');
  let requestVersion = 0, before = 0, offset = 0, keyword = '';
  document.querySelectorAll('[data-chat-search]').forEach(button => button.addEventListener('click',() => { dialog.showModal(); $('chatSearchInput').focus(); }));
  $('chatSearchClose').addEventListener('click',()=>dialog.close());
  async function search(append = false) {
    const q = $('chatSearchInput').value.trim(); if (!q) return;
    const version = ++requestVersion;
    if (!append || q !== keyword) { before=0; offset=0; append=false; $('chatSearchResults').replaceChildren(); }
    keyword=q; $('chatSearchStatus').textContent='搜尋中…'; $('chatSearchMore').hidden=true;
    try {
      const response=await fetch('chat_search.php?'+new URLSearchParams({...window.chatSearchScope,q,before,offset}),{cache:'no-store'}), data=await response.json();
      if (version !== requestVersion) return;
      if (!response.ok || !data.success) throw new Error(data.msg || '搜尋失敗');
      for (const result of data.results) {
        const button=document.createElement('button'); button.type='button'; button.className='chat-search-result';
        const meta=document.createElement('small'); meta.textContent=result.username+' · '+result.event_at;
        const text=document.createElement('span'); text.textContent=result.original_filename ? '📎 '+result.original_filename : result.content;
        button.append(meta,text); button.addEventListener('click',()=>{
          dialog.close(); window.dispatchEvent(new CustomEvent('chat-search-jump',{detail:{...result,...window.chatSearchScope}}));
        }); $('chatSearchResults').append(button);
      }
      before=data.next_before; offset=data.next_offset; $('chatSearchMore').hidden=!data.has_more;
      $('chatSearchStatus').textContent=$('chatSearchResults').childElementCount ? '找到 '+$('chatSearchResults').childElementCount+' 則結果（由新到舊）' : '找不到符合的訊息';
    } catch (error) { if (version===requestVersion) $('chatSearchStatus').textContent=error.message; }
  }
  $('chatSearchForm').addEventListener('submit',event=>{event.preventDefault();search();});
  $('chatSearchMore').addEventListener('click',()=>search(true));
  window.addEventListener('chat-search-jump',event=>{
    const result=event.detail; if (Number(result.group_id)) return;
    const target=document.getElementById('private-'+result.event_type+'-'+result.id);
    if (target) { target.scrollIntoView({block:'center'}); target.classList.add('chat-search-highlight'); setTimeout(()=>target.classList.remove('chat-search-highlight'),2500); }
    else storageNotice('這則訊息已不在目前紀錄，請重新搜尋。');
  });
})();
