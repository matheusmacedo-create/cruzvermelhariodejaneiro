// node render.js: regenera feed-curativos-1080x1350.png a partir do feed.html.
const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1080,height:1350}});
await p.goto('file://'+__dirname+'/feed.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(800);
await (await p.$('#feed')).screenshot({path:__dirname+'/feed-curativos-1080x1350.png'});
await b.close()})();
