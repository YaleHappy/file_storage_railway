const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
(async()=>{
 const status={innerHTML:'old'},active={textContent:''},online={textContent:''};
 const intervals=[],events={};let calls=0,cleared=false,code=200,label='閒置';
 const document={hidden:false,querySelectorAll:()=>[],querySelector:selector=>selector.startsWith('[data-admin-presence=')?status:selector.startsWith('[data-admin-active=')?active:selector==='.stat-online strong'?online:null,addEventListener:(name,fn)=>events[name]=fn};
 const context={document,matchMedia:()=>({matches:true}),window:{addEventListener:(name,fn)=>events[name]=fn},setInterval:(fn,ms)=>{intervals.push({fn,ms});return 1},clearInterval:()=>{cleared=true},fetch:async()=>{calls++;return {status:code,ok:code===200,json:async()=>({success:true,users:[{id:2,html:label,last_active:'now'}],online:2})}}};
 vm.runInNewContext(fs.readFileSync('assets/js/admin_workspace.js','utf8'),context);
 await new Promise(setImmediate);
 assert.equal(intervals.length,1);assert.equal(intervals[0].ms,5000);
 assert.equal(status.innerHTML,'閒置');assert.equal(active.textContent,'now');assert.equal(online.textContent,2);
 label='請勿打擾';await intervals[0].fn();assert.equal(status.innerHTML,'請勿打擾');
 document.hidden=true;const before=calls;await intervals[0].fn();assert.equal(calls,before);
 document.hidden=false;label='隱形';events.visibilitychange();await new Promise(setImmediate);assert.equal(status.innerHTML,'隱形');
 code=403;await intervals[0].fn();assert.equal(cleared,true);
 console.log('Admin presence UI: 9 checks passed (5-second polling, status-only updates, hidden tab, focus and denied access).');
})();
