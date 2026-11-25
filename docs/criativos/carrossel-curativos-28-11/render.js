// node render.js: regenera slide-1.png a slide-7.png (1080x1350) e a folha de conferência a partir do carrossel.html.
const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1080,height:1350}});
await p.goto('file://'+__dirname+'/carrossel.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(1200);
for(const id of await p.$$eval('.s',l=>l.map(e=>e.id)))
  await (await p.$('#'+id)).screenshot({path:`${__dirname}/slide-${id.slice(1)}.png`});
await b.close()})();
