import QRCode from 'qrcode';
const form=document.querySelector('#deep-link-form');
const anchor=document.querySelector('#referral-link');
const canvas=document.querySelector('#referral-qr');
if(form && anchor && canvas) {
 const base=anchor.href;
 const render=async()=>{
  const path=form.elements.path.value.trim()||'/';
  const error=document.querySelector('#deep-link-error');
  if(!path.startsWith('/') || path.startsWith('//') || /[\\\x00-\x1f]/.test(path) || path.length>1000){error.textContent='请输入以 / 开头的店内路径。';return;}
  const url=new URL(base);url.searchParams.set('to',path);
  const source=form.elements.source.value.trim();
  if(source)url.searchParams.set('utm_source',source);
  anchor.href=url.href;anchor.textContent=url.href;
  try {await QRCode.toCanvas(canvas,url.href,{width:256,margin:4,errorCorrectionLevel:'M'});error.textContent='';}
  catch{error.textContent='链接过长，无法生成二维码，请缩短路径。';}
 };
 form.addEventListener('submit',event=>{event.preventDefault();render();});
 document.querySelector('#download-qr')?.addEventListener('click',()=>{const link=document.createElement('a');link.href=canvas.toDataURL('image/png');link.download='referral-qr.png';link.click();});
 render();
}
