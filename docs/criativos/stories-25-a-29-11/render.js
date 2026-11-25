// node render.js: regenera os PNG 1080x1920 de cada cartão do stories.html (em png/) e uma folha de conferência por dia.
// Avisa se algum texto passar da área útil (os 340 px de baixo são da interface do Instagram).
const {chromium}=require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1200,height:2000}});
await p.goto('file://'+__dirname+'/stories.html');await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(1200);
const estouro=await p.$$eval('.s',l=>l.map(e=>{const t=e.querySelector('.txt');return t.scrollHeight>t.clientHeight+2?e.id+' (+'+(t.scrollHeight-t.clientHeight)+'px)':null}).filter(Boolean));
console.log(estouro.length?'PASSOU DA ÁREA ÚTIL: '+estouro.join(', '):'Nenhum texto passou da área útil.');
const ids=await p.$$eval('.s',l=>l.map(e=>e.id));
for(const id of ids)await (await p.$('[id="'+id+'"]')).screenshot({path:`${__dirname}/png/${id}.png`});
// Folhas de conferência: os cartões de cada dia lado a lado, reduzidos, quatro por folha.
const fs=require('fs');
for(const d of [...new Set(ids.map(i=>i[0]))]){
  const doDia=ids.filter(i=>i[0]===d);
  for(let n=0;n*4<doDia.length;n++){
    const tmp=`${__dirname}/png/_folha.html`;
    fs.writeFileSync(tmp,'<body style="margin:0;background:#333;display:flex;gap:12px;padding:12px">'+doDia.slice(n*4,n*4+4).map(i=>`<img src="${i}.png" style="height:860px">`).join('')+'</body>');
    const q=await b.newPage({viewport:{width:1996,height:884}});
    await q.goto('file://'+tmp);await q.waitForTimeout(500);await q.screenshot({path:`${__dirname}/png/_folha-${d}-${n+1}.png`});await q.close();fs.unlinkSync(tmp);}}
await b.close()})();
