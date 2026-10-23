(() => {
  let queue=Promise.resolve();
  window.storageNotice=text=>{
    let box=document.getElementById('storageNotice');
    if(!box){box=document.createElement('div');box.id='storageNotice';box.className='storage-notice';box.setAttribute('role','status');document.body.append(box);}
    (document.querySelector('dialog[open]') || document.body).append(box);
    box.replaceChildren(document.createTextNode(String(text)));
    const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','關閉提示');close.onclick=()=>box.hidden=true;box.append(close);box.hidden=false;
    clearTimeout(box.dismissTimer);box.dismissTimer=setTimeout(()=>box.hidden=true,10000);
  };
  document.addEventListener('click',async event=>{
    const link=event.target.closest('a[data-storage-confirm]');
    if(!link)return;event.preventDefault();
    if(await storageConfirm(link.dataset.storageConfirm))location.href=link.href;
  });
  window.storageConfirm=text=>{
    const task=queue.then(()=>new Promise(resolve=>{
      const previous=document.activeElement;
      const dialog=document.createElement('dialog');dialog.className='storage-confirm';
      const title=document.createElement('h2');title.textContent='確認操作';title.id='storageConfirmTitle';
      dialog.setAttribute('aria-labelledby',title.id);
      const message=document.createElement('p');message.textContent=String(text);
      const form=document.createElement('form');form.method='dialog';
      const cancel=document.createElement('button');cancel.type='submit';cancel.value='cancel';cancel.className='btn btn-secondary';cancel.textContent='取消';
      const accept=document.createElement('button');accept.type='submit';accept.value='confirm';accept.className='btn btn-danger';accept.textContent='確定';
      form.append(cancel,accept);dialog.append(title,message,form);document.body.append(dialog);
      dialog.addEventListener('close',()=>{const yes=dialog.returnValue==='confirm';dialog.remove();if(previous?.isConnected)previous.focus({preventScroll:true});resolve(yes);},{once:true});
      dialog.showModal();cancel.focus();
    }));
    queue=task.catch(()=>{});return task;
  };
})();
