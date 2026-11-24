// node render_info.js: gera os PNG dos cartões de informação (info.html), 1080 x 1350.
const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1080,height:1400}});
await p.goto('file://'+__dirname+'/info.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(800);
for(const [id,nome] of [['aprende','cartao_o_que_voce_aprende'],['lei','cartao_lei_lucas']]){
  const m=await p.evaluate(i=>{const c=document.getElementById(i),r=c.getBoundingClientRect();const t=c.querySelector('.txt').lastElementChild.getBoundingClientRect(),pe=c.querySelector('.pe').getBoundingClientRect();return {fimDoTexto:Math.round(t.bottom-r.top),rodape:Math.round(pe.top-r.top)}},id);
  console.log(id,JSON.stringify(m));
  await (await p.$('#'+id)).screenshot({path:`${__dirname}/${nome}_1080x1350.png`});}
await b.close()})();
