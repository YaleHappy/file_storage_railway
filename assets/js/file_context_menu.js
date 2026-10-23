(() => {
 const menu=document.createElement('div');menu.className='file-context-menu';menu.setAttribute('role','menu');menu.setAttribute('aria-label','檔案操作');menu.hidden=true;document.body.append(menu);
 let origin=null;
 function close(restore=false){const wasOpen=!menu.hidden;menu.hidden=true;if(restore && wasOpen && origin?.isConnected)origin.focus({preventScroll:true});}
 const item=(label,icon,run,danger=false,disabled=false)=>({label,icon,run,danger,disabled});
 const from=(card,selector,label,icon,danger=false)=>{const target=card.querySelector(selector);return target?item(label,icon,()=>target.click(),danger,target.disabled):null;};
 function actions(card){
  if(card.matches('.folder-card')){
   const id=Number(card.dataset.folder);
   return [from(card,'.folder-open','開啟','folder2-open'),from(card,'.rename-folder-btn','重新命名','pencil-square'),item('刪除資料夾','trash',()=>window.deleteFolder(id),true)];
  }
  if(card.matches('.file-card-inner'))return [from(card,'[title="預覽檔案"]','預覽','eye'),from(card,'a[href*="download="]','下載','download'),from(card,'[data-bs-target="#shareModal"]','分享','share'),from(card,'.move-btn','移動','arrow-right-square'),from(card,'[data-delete-file]','刪除檔案','trash',true)];
  if(card.matches('.received-row'))return [from(card,'.file-preview','預覽','eye'),from(card,'a[href*="download_shared_file.php"]','下載','download'),from(card,'.save-cloud','存到我的雲端','cloud-arrow-up'),item('移除收件紀錄','trash',()=>{
   // Use the existing confirmation and deletion flow, targeting this row only.
   document.querySelectorAll('input[name="delete[]"]').forEach(box=>box.checked=false);
   const box=card.querySelector('input[name="delete[]"]');box.checked=true;box.dispatchEvent(new Event('change',{bubbles:true}));document.getElementById('removeReceived').click();
  },true)];
  return [from(card,'.file-preview','預覽','eye'),from(card,'.btn-copy','複製連結','clipboard'),from(card,'a[title="開啟分享連結"]','開啟連結','box-arrow-up-right'),from(card,'.share-settings','分享設定','gear'),from(card,'.delete-btn','取消分享','link-45deg',true)];
 }
 function open(event,entries){
  entries=entries.filter(Boolean);if(!entries.length)return;
  event.preventDefault();close();
  origin=event.target.closest('button,a,input,[tabindex]') || event.target.closest('.folder-card,.file-card-inner,.file-row')?.querySelector('button,a') || document.querySelector('#searchInput,.file-search');
  menu.replaceChildren();
  for(const entry of entries){const button=document.createElement('button');button.type='button';button.setAttribute('role','menuitem');button.disabled=!!entry.disabled;if(entry.danger)button.className='is-danger';const icon=document.createElement('i');icon.className='bi bi-'+entry.icon;icon.setAttribute('aria-hidden','true');const text=document.createElement('span');text.textContent=entry.label;button.append(icon,text);button.addEventListener('click',()=>{close(true);entry.run();});menu.append(button);}
  menu.hidden=false;menu.style.left='0px';menu.style.top='0px';
  const rect=menu.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(event.clientX,innerWidth-rect.width-8))+'px';menu.style.top=Math.max(8,Math.min(event.clientY,innerHeight-rect.height-8))+'px';menu.querySelector('button:not(:disabled)')?.focus({preventScroll:true});
 }
 document.addEventListener('contextmenu',event=>{
  if(event.target.closest('input,textarea,select,button,a,[contenteditable="true"],.modal,dialog,nav,.navbar') || window.getSelection()?.toString()){close();return;}
  const card=event.target.closest('.folder-card,.file-card-inner,.file-row');
  if(card){open(event,actions(card));return;}
  const workspace=document.querySelector('#storageWorkspace,.file-workspace');
  if(!workspace || !(workspace.contains(event.target) || event.target===document.body)){close();return;}
  if(workspace.id==='storageWorkspace'){
   open(event,[item('新增資料夾','folder-plus',()=>window.openCreateFolderModal()),item('上傳檔案','upload',()=>window.openUploadModal())]);
  }else{
   const search=workspace.querySelector('.file-search'),filter=workspace.querySelector('.file-filter');
   open(event,[item('搜尋檔案','search',()=>{search.focus();search.select();}),item('清除篩選','x-circle',()=>{
    search.value='';if(filter)filter.value='';search.dispatchEvent(new Event('input',{bubbles:true}));search.focus();
   },false,!search.value && !filter?.value)]);
  }
 });
 document.addEventListener('pointerdown',event=>{if(!menu.contains(event.target))close();});
 document.addEventListener('keydown',event=>{
  if(menu.hidden)return;
  if(event.key==='Escape' || event.key==='Tab'){if(event.key==='Escape')event.preventDefault();close(true);return;}
  if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key))return;
  event.preventDefault();const buttons=[...menu.querySelectorAll('button:not(:disabled)')];const index=buttons.indexOf(document.activeElement);const next=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(event.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length;buttons[next]?.focus();
 });
 window.addEventListener('resize',()=>close());window.addEventListener('scroll',()=>close(),true);
})();
