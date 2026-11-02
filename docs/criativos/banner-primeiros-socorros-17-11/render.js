const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1920,height:2100}});
await p.goto('file://'+__dirname+'/banner.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(500);
await (await p.$('#d')).screenshot({path:__dirname+'/banner-desktop-1920x640.png'});
await (await p.$('#m')).screenshot({path:__dirname+'/banner-celular-1080x1420.png'});
await b.close()})();
