# Anúncios na Meta (Instagram): regras da casa

O que vale para toda campanha da escola criada ou alterada na conta de anúncios "3"
(`2438819769904784`, portfólio NND Negocios Locais). O porquê de cada regra vem de erro já pago.

## Regras do Matheus (valem sempre)

- **Advantage+ desligado em tudo que criarmos** (pedido de 24/11/2026): público Advantage+ desligado no
  conjunto (`targeting_automation.advantage_audience = 0`), posicionamentos escolhidos à mão e melhorias de
  criativo Advantage+ desligadas. Não religar sem ele pedir.
- **Posicionamento: só feed do Instagram** (`publisher_platforms: instagram`, `instagram_positions: stream`),
  salvo pedido em contrário.
- Otimização para clique no link, R$ 6 por dia por conjunto, público por local (raios), idade 18 a 65: é o
  padrão dos conjuntos que rodam desde novembro de 2026.

## Carrossel em vídeo: o que a Meta aceita

- **Formato quadrado, 1080 x 1080.** Carrossel em 4:5 no feed não vira anúncio: o post é recusado com
  mensagens que não dizem o motivo ("publicação indisponível", "sem permissão para promover", "erro
  desconhecido #2446289"). Perdemos uma tarde nisso em 24/11/2026.
- **No máximo 10 cartões.**
- **Carrossel editado depois de publicado não pode ser impulsionado** ("Cannot reboost edited carousel"). Se
  precisar mudar, é post novo.
- Para não depender do post do perfil: subir os vídeos direto na conta e criar o carrossel como anúncio. Os
  vídeos quadrados saem de `docs/criativos/carrossel-lei-lucas-2026-11/` (o quadro 4:5 inteiro, com faixa
  escura nas laterais, para não cortar a legenda embutida).

## Prova social (curtidas e comentários)

- Curtidas e comentários ficam no **criativo**. Para não perder ao mudar de público ou de campanha, o
  anúncio novo aponta para o **mesmo criativo**, em vez de subir os vídeos de novo.
- Mudar a legenda cria outro criativo e zera a contagem: decidir o texto antes de ligar.
- A Meta não otimiza só para comentário; o objetivo mais próximo é engajamento com a publicação.

## Medição

- A conta "3" só enxerga o conjunto de dados `1580762603752102` (o segundo pixel do site, ver
  `docs/rastreamento.md`). Página de destino sem ele deixa as visitas do anúncio invisíveis para a conta.
