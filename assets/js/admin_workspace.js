const adminMenu=document.querySelector('.admin-menu');
const adminDesktop=matchMedia('(min-width:992px)');
if(adminMenu){
  const updateMenu=()=>{adminMenu.open=adminDesktop.matches;};
  updateMenu();adminDesktop.addEventListener('change',updateMenu);
}
// Keep the server-rendered forms usable when JavaScript is unavailable.
document.querySelectorAll('details.admin-editor, details.admin-edit-details').forEach((editor,index)=>{
  const summary=editor.querySelector('summary');
  const form=editor.querySelector('form');
  if(!summary || !form || typeof HTMLDialogElement==='undefined') return;
  const trigger=document.createElement('button');
  trigger.type='button';
  trigger.className='btn '+(editor.classList.contains('admin-editor')?'btn-primary':'btn-sm btn-soft');
  trigger.textContent=summary.textContent;
  const dialog=document.createElement('dialog');
  dialog.className='admin-dialog';
  const heading=document.createElement('h2');
  heading.id='adminDialogTitle'+index;
  const name=form.querySelector('[name="username"]')?.value;
  heading.textContent=name?'編輯使用者：'+name:summary.textContent.replace('＋','').trim();
  dialog.setAttribute('aria-labelledby',heading.id);
  const header=document.createElement('header');
  header.className='admin-dialog-header';
  header.append(heading);
  const close=document.createElement('button');
  close.type='button';close.className='admin-dialog-close';close.textContent='×';
  close.setAttribute('aria-label','關閉編輯視窗');
  header.append(close);
  const submit=form.querySelector('button:not([type]),button[type="submit"]');
  const fields=document.createElement('div');fields.className='admin-dialog-fields';
  while(form.firstChild) fields.append(form.firstChild);
  const footer=document.createElement('footer');footer.className='admin-dialog-footer';
  const cancel=document.createElement('button');
  cancel.type='button';cancel.className='btn btn-soft';cancel.textContent='取消';
  footer.append(cancel,submit);
  form.append(fields,footer);
  dialog.append(header,form);
  editor.replaceWith(trigger);
  document.body.append(dialog);
  trigger.addEventListener('click',()=>{dialog.showModal();document.body.classList.add('admin-dialog-open');});
  close.addEventListener('click',()=>dialog.close());
  cancel.addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{document.body.classList.remove('admin-dialog-open');trigger.focus();});
});
document.querySelectorAll('.admin-actions').forEach(actions=>{
  const edit=Array.from(actions.children).find(el=>el.tagName==='BUTTON');
  if(edit) actions.prepend(edit);
  actions.querySelectorAll('form').forEach(form=>{
    if(form.querySelector('[name="action"]')?.value.startsWith('delete')) actions.append(form);
  });
});
document.querySelectorAll('form[method="post"]').forEach(form=>{
  form.addEventListener('submit',event=>{
    if(form.dataset.pending==='1'){event.preventDefault();return;}
    if(form.dataset.confirm && !confirm(form.dataset.confirm)){event.preventDefault();return;}
    form.dataset.pending='1';
    form.querySelectorAll('button[type="submit"],button:not([type])').forEach(button=>{button.disabled=true;});
  });
});

// Refresh only status fields: keep open forms and list position intact.
(() => {
 let pending=false;
 let timer;
 async function refreshAdminPresence(){
  if(document.hidden || pending)return;
  pending=true;
  try{
   const response=await fetch('admin.php?presence=1',{credentials:'same-origin',cache:'no-store'});
   if(response.status===401 || response.status===403){clearInterval(timer);return;}
   if(!response.ok)return;
   const data=await response.json();
   if(!data.success)return;
   for(const user of data.users){
    const status=document.querySelector(`[data-admin-presence="${Number(user.id)}"]`);
    if(status && status.innerHTML!==user.html)status.innerHTML=user.html;
    const active=document.querySelector(`[data-admin-active="${Number(user.id)}"]`);
    if(active)active.textContent=user.last_active;
   }
   const online=document.querySelector('.stat-online strong');
   if(online)online.textContent=data.online;
  }catch{/* Keep the last successful result during temporary connection failures. */}
  finally{pending=false;}
 }
 timer=setInterval(refreshAdminPresence,5000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshAdminPresence();});
 window.addEventListener('presence:updated',refreshAdminPresence);
 refreshAdminPresence();
})();
