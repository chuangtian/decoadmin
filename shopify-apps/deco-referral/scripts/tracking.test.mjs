import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const code=readFileSync(new URL('../extensions/referral-tracking/assets/referral-tracking.js',import.meta.url),'utf8');
const token=(id,exp=Math.floor(Date.now()/1000)+3600)=>Buffer.from(JSON.stringify({v:1,exp,click:id})).toString('base64url')+'.signature';
const settle=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(incoming,consent=false,initial={}) {
 const events={},requests=[],storage=new Map(Object.entries(initial));
 let permit=consent;
 const location={href:'https://example.myshopify.com/products/test?deco_aff='+incoming,origin:'https://example.myshopify.com'};
 const window={location,Shopify:{routes:{root:'/'},customerPrivacy:{marketingAllowed:()=>permit}},history:{state:null,replaceState:()=>{}},addEventListener:(name,cb)=>events[name]=cb};
 const context={window,location,document:{readyState:'complete',addEventListener:(name,cb)=>events[name]=cb},URL,atob:s=>Buffer.from(s,'base64').toString(),AbortSignal,
 localStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},fetch:async (url,options)=>{requests.push(JSON.parse(options.body).attributes);return {ok:true};}};
 vm.runInNewContext(code,context);
 return {requests,storage,events,consent:value=>{permit=value;events.visitorConsentCollected();}};
}
test('denied consent never persists referral token; opt-in captures and revocation clears',async()=>{
 const f=fixture(token('a'));
 await settle();
 assert.equal(f.storage.size,0);
 assert.equal(f.requests.at(-1).deco_aff__,'');
 f.consent(true);await settle();
 assert.ok(f.storage.has('deco_referral_v1'));
 assert.ok(f.requests.at(-1).deco_aff__);
 f.consent(false);await settle();
 assert.equal(f.storage.size,0);
 assert.equal(f.requests.at(-1).deco_aff__,'');
});
test('first referral survives later visit and last referral updates',async()=>{
 const first=token('first'),last=token('last');
 const f=fixture(last,true,{deco_referral_v1:JSON.stringify({first,last:first})});
 await settle();
 assert.equal(f.requests.at(-1).deco_aff_first__,first);
 assert.equal(f.requests.at(-1).deco_aff_last__,last);
});
test('expired and malformed tokens are ignored',async()=>{
 for(const value of [token('expired',1),'not-a-token']) {
  const f=fixture(value,true);await settle();assert.equal(f.requests.length,0);assert.equal(f.storage.size,0);
 }
});
