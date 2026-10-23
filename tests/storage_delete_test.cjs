const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
function fixture(){
 let document,dialog,reloads=0,calls=[];let outcome=true;
 class Element extends EventTarget{
  constructor(tag){super();this.tag=tag;this.style={};this.classList={toggle(){}};this.isConnected=true;this.returnValue='';this.open=false;this.child={};}
  setAttribute(){} after(){} append(){} focus(){document.activeElement=this;}
  querySelector(key){return this.child[key]??=new Element('child');}
  showModal(){this.open=true;}
  close(value){this.returnValue=value;this.open=false;this.dispatchEvent(new Event('close'));}
 }
 const oldFocus=new Element('button'),message=new Element('p'),toolbar=new Element('toolbar');
 document={activeElement:oldFocus,createElement:tag=>tag==='dialog'?(dialog=new Element(tag)):message,querySelector:()=>toolbar,querySelectorAll:()=>[],body:{append(){}},addEventListener(){}};
 const storage=new Map();const context={document,window:{},URLSearchParams,sessionStorage:{getItem:key=>storage.get(key),setItem:(key,v)=>storage.set(key,v),removeItem:key=>storage.delete(key)},location:{reload(){reloads++}},fetch:async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>({success:outcome,msg:outcome?'成功':'資料夾不是空的'})}}};
 vm.runInNewContext(fs.readFileSync('assets/js/storage_delete.js','utf8'),context);
 return {context,document,oldFocus,message,get dialog(){return dialog},get calls(){return calls},get reloads(){return reloads},fail(){outcome=false}};
}
(async()=>{
 const a=fixture();let pending=a.context.window.storageDelete('folder',[1]);assert(a.dialog.open);a.dialog.close('cancel');await pending;assert.equal(a.calls.length,0);assert.equal(a.document.activeElement,a.oldFocus);
 a.fail();pending=a.context.window.storageDelete('folder',[1]);a.dialog.close('delete');await pending;assert.equal(a.reloads,0);assert.equal(a.message.textContent,'資料夾不是空的');
 pending=a.context.window.storageDelete('file',[2]);assert(a.dialog.open);a.dialog.close('cancel');await pending;
 const b=fixture();pending=b.context.window.storageDelete('file',[2,3]);await b.context.window.storageDelete('file',[4]);b.dialog.close('delete');await pending;assert.equal(b.calls.length,1);assert.equal(b.calls[0].url,'bulk_delete.php');assert.deepEqual(JSON.parse(b.calls[0].options.body).ids,[2,3]);assert.equal(b.reloads,1);
 const c=fixture();pending=c.context.window.storageDelete('folder',[8]);c.dialog.close('delete');await pending;assert.equal(c.calls[0].url,'folders.php?action=delete');assert.equal(c.reloads,1);
 console.log('Delete interaction: 12 checks passed (cancel, focus, failure retry, duplicate lock and success routes).');
})();
