const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 const nodes=[];let focusCount=0;
 class Node{
  constructor(tag){this.tag=tag;this.children=[];this.events={};this.isConnected=true;nodes.push(this);}
  append(...items){this.children.push(...items);}setAttribute(){}replaceChildren(...items){this.children=items;}
  addEventListener(name,fn){this.events[name]=fn;}showModal(){this.open=true;}focus(){focusCount++;}
  remove(){this.isConnected=false;this.open=false;}close(value){this.returnValue=value;this.events.close();}
 }
 const body=new Node('body');const previous=new Node('button');
 const document={body,activeElement:previous,createElement:tag=>new Node(tag),createTextNode:text=>text,addEventListener(){},getElementById:id=>nodes.find(n=>n.id===id),querySelector:()=>nodes.find(n=>n.tag==='dialog'&&n.open)};
 const window={};vm.runInNewContext(fs.readFileSync('assets/js/ui_feedback.js','utf8'),{window,document,setTimeout:()=>1,clearTimeout(){}});
 const first=window.storageConfirm('first'),second=window.storageConfirm('second');await new Promise(setImmediate);
 assert.equal(nodes.filter(n=>n.open).length,1);
 nodes.find(n=>n.open).close('cancel');assert.equal(await first,false);await new Promise(setImmediate);
 assert.equal(nodes.filter(n=>n.open).length,1);nodes.find(n=>n.open).close('confirm');assert.equal(await second,true);
 assert.equal(nodes.filter(n=>n.open).length,0);assert.equal(focusCount,4);
 window.storageNotice('<b>plain text</b>');const notice=nodes.find(n=>n.id==='storageNotice');assert.equal(notice.children[0],'<b>plain text</b>');notice.children[1].onclick();assert.equal(notice.hidden,true);
 console.log('UI feedback: queued confirmations, cancel/accept, cleanup, focus and text-only dismissible notice passed.');
})();
