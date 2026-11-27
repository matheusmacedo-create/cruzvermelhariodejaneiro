# Imagem de compartilhamento da página do curso Lei Lucas

`og.html` é a fonte da imagem que aparece quando o link `/escola-presencial/lei-lucas/` é colado no WhatsApp, no
Instagram ou no Facebook (1200x630), no mesmo desenho dos banners das turmas
(`../banners-turmas-nov-dez-2026/`, de onde vêm a foto e a marca).

- `node render.js` (Playwright) grava `site/escola-presencial/lei-lucas/og-lei-lucas.jpg`.
- Sem data e sem preço, de propósito: o WhatsApp e a Meta guardam a imagem por semanas, e a turma e o cupom mudam.
- Como nos banners, ficaram de fora "certificado internacional" e "vagas limitadas".

## Depoimentos em vídeo da página (24/11/2026)

Os três vídeos da seção "Alunas contam como foi" (`site/escola-presencial/lei-lucas/depoimento-*.mp4`) são os cortes
das alunas Luciana, Ana Cláudia e Luciene já montados para o carrossel do Instagram
(`docs/criativos/carrossel-lei-lucas-2026-11/`, no branch do carrossel): gravados pela filial na sede, com a legenda e o
nome embutidos. Vieram da conta de anúncios da Meta (vídeos `02_depoimento_Luciana`, `06_depoimento_Ana_Claudia` e
`09_depoimento_Luciene`, 720 x 720 com faixa escura nas laterais).

- Recodificar (tira as faixas laterais: 576 x 720, 4:5; cerca de 1,5 MB cada):
  `ffmpeg -i entrada.mp4 -vf "crop=576:720:72:0,format=yuv420p" -c:v libx264 -preset slow -crf 29 -maxrate 560k
  -bufsize 1120k -profile:v high -level 3.1 -g 60 -c:a aac -b:a 64k -ac 1 -movflags +faststart depoimento-NOME.mp4`
- Capa (`.webp`): um quadro do próprio vídeo, com a frase mais forte na legenda.
- Legenda em texto (`.vtt`): a transcrição do carrossel (`DEPOIMENTOS` em `montar.py`), com os tempos contados do
  início do corte. Fica desligada (a legenda já está na imagem) e serve a quem usa leitor de tela.
- O crédito é o que está no vídeo: elas dizem "curso de primeiros socorros", sem dizer qual deles.
- **Autorização de imagem das três entrevistadas: com a filial** (a mesma pendência do carrossel).
