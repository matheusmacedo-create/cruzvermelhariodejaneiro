// node render.js: regenera a imagem de compartilhamento da página /escola-presencial/lei-lucas/ (og.html, 1200x630).
// Grava o JPG direto na pasta da página, em site/. Playwright precisa estar instalado (como nos banners das turmas).
const {chromium}=require('playwright');const path=require('path');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1200,height:630}});
await p.goto('file://'+__dirname+'/og.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(800);
const saida=path.join(__dirname,'../../../site/escola-presencial/lei-lucas/og-lei-lucas.jpg');
await (await p.$('#og')).screenshot({path:saida,type:'jpeg',quality:86});console.log('gravado',saida);
await b.close()})();
