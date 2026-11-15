// node render.js: regenera os PNG (computador 1920x640 e celular 1080x1420) de cada turma do banner.html.
const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1920,height:2100}});
await p.goto('file://'+__dirname+'/banner.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(800);
for(const id of await p.$$eval('.b',l=>l.map(e=>e.id))){const [nome,v]=[id.slice(0,-2),id.slice(-1)];
  await (await p.$('#'+id)).screenshot({path:`${__dirname}/${nome}-${v==='d'?'computador-1920x640':'celular-1080x1420'}.png`});}
await b.close()})();
