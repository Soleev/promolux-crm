import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

function setup(fetch) {
 const content={innerHTML:'old',replaceChildren(){this.innerHTML='';}};
 const status={textContent:''}; const events={}; const timers=[];
 const nodes={'#clock':{dataset:{epoch:'1795258800000'}},'#connection':status,'#sfp-content':content,'#fullscreen':{addEventListener(){}}};
 const context={document:{querySelector:s=>nodes[s]},performance:{now:()=>0},Intl,Date,URL,AbortController,location:{href:'https://local.test/crm/sfp?view=queue&page=2'},window:{addEventListener:(e,f)=>events[e]=f},setInterval:(callback,delay)=>timers.push({callback,delay}),setTimeout:()=>1,clearTimeout(){},fetch};
 vm.runInNewContext(readFileSync('resources/js/sfp.js','utf8'),context);
 return {content,status,events,timers};
}
test('SFP retains stale data after network failure then recovers on reconnect', async()=>{
 let fail=true;
 const s=setup(async url=>{assert.equal(url.searchParams.get('page'),'2');assert.equal(url.searchParams.get('fragment'),'1');if(fail)throw Error();return {ok:true,status:200,text:async()=>'<p>fresh</p>'};});
 assert.equal(s.timers[1].delay, 30_000);
 await s.timers[1].callback(); assert.equal(s.content.innerHTML,'old');assert.match(s.status.textContent,/Нет связи/);
 fail=false; await s.events.online();assert.equal(s.content.innerHTML,'<p>fresh</p>');assert.match(s.status.textContent,/обновлены/);
});
test('SFP clears data on revoked access or login redirect and never renders error HTML', async()=>{
 for(const response of [{status:403},{status:404},{status:200,redirected:true}]){
  const s=setup(async()=>response);await s.timers[1].callback();assert.equal(s.content.innerHTML,'');assert.match(s.status.textContent,/недоступен/);
 }
 const s=setup(async()=>({status:500,ok:false,text:async()=>'<pre>SQL secret</pre>'}));await s.timers[1].callback();assert.equal(s.content.innerHTML,'old');
});
