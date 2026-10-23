(() => {
  const message=document.createElement('p');
  message.className='storage-delete-feedback';message.hidden=true;message.setAttribute('role','status');
  document.querySelector('.home-storage-toolbar').after(message);
  const showMessage=(text,error=false)=>{message.textContent=text;message.hidden=false;message.classList.toggle('is-error',error);};
  try{const saved=sessionStorage.getItem('storage-delete-result');if(saved){sessionStorage.removeItem('storage-delete-result');showMessage(saved);}}catch{}
  const dialog=document.createElement('dialog');dialog.className='storage-delete-dialog';
  dialog.innerHTML='<form method="dialog"><h2 id="storageDeleteTitle">確認刪除</h2><p id="storageDeleteText"></p><div><button value="cancel" class="btn btn-secondary">取消</button><button value="delete" class="btn btn-danger">刪除</button></div></form>';
  dialog.setAttribute('aria-labelledby','storageDeleteTitle');document.body.append(dialog);
  let busy=false;
  window.storageDelete=async(kind,ids)=>{
    if(busy)return;
    if(!ids.length){showMessage('請先選取要刪除的檔案。',true);return;}
    busy=true;
    const previous=document.activeElement;
    dialog.querySelector('p').textContent=kind==='folder'?'確定刪除此資料夾？只能刪除空資料夾，裡面有檔案或子資料夾時會保留。':`確定刪除 ${ids.length} 個檔案？刪除後無法從此頁還原。`;
    dialog.returnValue='';
    const answer=new Promise(resolve=>dialog.addEventListener('close',()=>resolve(dialog.returnValue),{once:true}));
    dialog.showModal();dialog.querySelector('[value="cancel"]').focus();
    const confirmed=(await answer)==='delete';
    if(previous?.isConnected)previous.focus({preventScroll:true});
    if(!confirmed){busy=false;return;}
    showMessage('正在刪除…');
    try{
      let response;
      if(kind==='folder'){
        response=await fetch('folders.php?action=delete',{method:'POST',body:new URLSearchParams({folder_id:ids[0]})});
      }else{
        response=await fetch('bulk_delete.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids})});
      }
      const data=await response.json();
      if(!response.ok || !data.success)throw new Error(data.msg || data.error || '刪除失敗，請稍後再試。');
      const text=kind==='folder'?'資料夾已刪除。':(data.msg || '檔案已刪除。');
      try{sessionStorage.setItem('storage-delete-result',text);}catch{}
      location.reload();
    }catch(error){showMessage(error.message || '刪除失敗，請稍後再試。',true);busy=false;}
  };
  document.addEventListener('click',event=>{
    const folder=event.target.closest('.delete-folder-btn');
    const file=event.target.closest('[data-delete-file]');
    const bulk=event.target.closest('#bulkDeleteBtn');
    if(!folder && !file && !bulk)return;
    event.preventDefault();event.stopPropagation();
    const ids=folder?[folder.dataset.id]:file?[file.dataset.deleteFile]:Array.from(document.querySelectorAll('.file-card-inner .select-item:checked'),box=>box.value);
    window.storageDelete(folder?'folder':'file',ids);
  });
})();
