const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1080,height:1350}});
await p.goto('file://'+__dirname+'/cartao.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(800);
const fontes=await p.evaluate(()=>[...document.fonts].filter(f=>f.status==='loaded').map(f=>f.family+' '+f.weight));
console.log('fontes:',[...new Set(fontes)].join(', '));
const m=await p.evaluate(()=>{const r=e=>document.querySelector(e).getBoundingClientRect();return {aviso:r('.aviso').bottom,pe:r('.pe').top,valor:r('.valor').right}});
console.log(JSON.stringify(m));
await (await p.$('#cartao')).screenshot({path:__dirname+'/10_cartao_final_1080x1350.png'});await b.close()})();
