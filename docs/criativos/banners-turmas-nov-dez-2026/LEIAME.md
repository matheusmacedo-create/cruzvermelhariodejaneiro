# Banners das turmas abertas — novembro e dezembro de 2026

Um banner por turma aberta no `oferta.json` em 15/11/2026, no mesmo desenho do banner de 17/11:

| Arte | Curso | Turma |
|---|---|---|
| `curativos-*` | Curativos e Tratamento de Feridas | sábado, 28/11, 9h às 17h |
| `basico-*` | Primeiros Socorros Básico | quarta, 02/12, 9h às 18h |
| `puncao-*` | Punção Venosa | sábado, 05/12, 9h às 17h |
| `lei-lucas-*` | Primeiros Socorros Lei Lucas | quarta, 09/12, 9h às 17h |

- `banner.html` é a fonte (a lista `BANNERS` tem uma entrada por turma); `node render.js` (Playwright) regenera os
  PNG de computador (1920x640) e de celular (1080x1420).
- No site: `site/matricula-cursos-presenciais/img/banner-<turma>-{1920,960,celular-1080,celular-540}.webp` (WebP 80).
- Fotos: as que a escola publica em cada curso (`imagem_url` do `cursos.json`); marca: `img/logo.png`.
- Na home só aparece um banner por vez, o da turma mais próxima; cada um sai sozinho à meia-noite do dia da aula
  (quando as inscrições fecham) e o seguinte assume. Depois de 09/12 a home fica sem banner.
- Se uma turma lotar ou mudar de data antes disso, tire o `<a class="hp-banner-turma">` dela de `site/index.html`
  (ou refaça a arte) e publique a home: o banner não lê o `oferta.json`.
- Como no banner anterior, ficaram de fora "certificado internacional" e "vagas limitadas".
