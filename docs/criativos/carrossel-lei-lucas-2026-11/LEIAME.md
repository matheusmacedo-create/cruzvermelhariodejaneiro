# Carrossel "resgates + depoimentos" do Primeiros Socorros Lei Lucas (24/11/2026)

Dez cartões em 1080 x 1350 (4:5), H.264 + AAC, 30 quadros por segundo: seis vídeos de resgate com narração e
legenda embutida, três depoimentos de alunas com legenda embutida e o cartão final de preço.
Os vídeos não ficam no repositório (são de terceiros e pesam 120 MB); aqui ficam o roteiro, a origem de cada
imagem e as ferramentas que montam tudo de novo.

## Ordem dos cartões

| Nº | Arquivo | O que é | Duração |
| --- | --- | --- | --- |
| 1 | 01_D_viatura_DF | PMs salvam bebê de 1 ano ao lado da viatura (DF) | 29 s |
| 2 | 02_G_depoimento_Luciana | Depoimento de aluna | 23 s |
| 3 | 03_K_quartel_MG | PM salva bebê de 1 mês no quartel (MG) | 46 s |
| 4 | 04_F_policial_rua | PM salva bebê na rua (câmera de 2022) | 45 s |
| 5 | 05_H_depoimento_Ana_Claudia | Depoimento de aluna, odontopediatra | 20 s |
| 6 | 06_J_bombeiros_SC | Bombeiro salva menina de 7 anos no quartel (SC) | 33 s |
| 7 | 07_E_batalhao_Chapeco | PM salva bebê no batalhão (Chapecó, SC) | 38 s |
| 8 | 08_I_depoimento_Luciene | Depoimento de aluna | 19 s |
| 9 | 09_A_mae_em_casa | Mãe desengasga o filho em casa | 28 s |
| 10 | 10_cartao_final (PNG, ou MP4 com voz) | Preço e chamada para a inscrição | 19 s com voz |

Reserva: reserva_L_batalhao_DF (PMs salvam bebê no batalhão, DF, 22 s).

## Regras que valeram aqui

- Só vídeo limpo, de matéria jornalística ou da própria filial. Nada com marca de outra escola ou curso.
- A narração só diz o que a matéria de origem diz. Nome de criança não entra.
- Imagem de terceiro pede autorização do dono antes de publicar ou anunciar (tabela abaixo).
- O cartão de preço diz o código do cupom: sem digitar PRO12 no checkout, a página cobra R$ 249.

## Origem das imagens e a quem pedir autorização

| Cartão | Origem | Crédito na faixa | A quem pedir |
| --- | --- | --- | --- |
| 01_D | https://www.instagram.com/p/DgD8ycCx7Io/ (@metropoles, 22/11/2026) | Imagens: PMDF, via Metrópoles | Assessoria da PMDF |
| 03_K | https://www.instagram.com/p/Df4RWk8NxuF/ (@g1.suldeminas, 18/11/2026) | Imagens: Polícia Militar, via g1 Sul de Minas | g1 Sul de Minas (Globo) e PMMG |
| 04_F | https://www.instagram.com/p/CwKuRSFhahg/ (@policiadopovo, imagem de 16/06/2022) | Imagens: câmera de segurança, 2022 | A corporação dona da imagem (a página não identifica) |
| 06_J | https://www.instagram.com/p/DeKdBwUKSJD/ (@jornalrazao, 06/10/2026) | Imagens: Corpo de Bombeiros de SC, via Jornal Razão | Jornal Razão e Bombeiros de SC |
| 07_E | https://www.instagram.com/p/Dfa6tb0qlgU/ (@jornalrazao, 07/11/2026) | Imagens: câmera do batalhão, via Jornal Razão | Jornal Razão e PM de SC |
| 09_A | https://www.instagram.com/p/DVtTh8RjloO/ (@juliannarvalentim, 10/03/2026) | Imagens: arquivo da família | A mãe, dona do perfil |
| reserva_L | https://www.instagram.com/p/DgBl3wQgNoN/ (@correio.braziliense, 22/11/2026) | Imagens: PMDF, via Correio Braziliense | Correio Braziliense e PMDF |
| 02_G, 05_H, 08_I | Arquivos enviados pela filial (WhatsApp, 576 x 1024) | Nome e "aluna do curso" | Autorização de imagem das entrevistadas |

## Narração e legenda

- Voz: ElevenLabs, voz brasileira nativa "Ana Dias" (biblioteca de vozes; `VOZ_ID` troca) e o modelo de
  narração do projeto (`eleven_v4`), pedida com o tempo de cada letra: a legenda sai desse tempo, no ponto da
  fala. A primeira versão usou a "Sarah" da filial, que é americana e lê português com sotaque: não ficou
  natural (retorno do Matheus, 24/11/2026). Para anúncio, só voz nativa.
- O cartão final com voz sai de `cartao_voz.py`, com a mesma voz.
- O texto de cada fala está em `montar.py` (`NARRADOS`). A barra `|` marca onde a legenda troca de bloco e
  não vai para a voz. O número antes da fala é o segundo em que ela entra, para casar com a cena.
- Depoimentos: a fala é a original, cortada para começar na aluna, com redução de ruído e volume nivelado; a
  legenda veio da transcrição da ElevenLabs e foi conferida bloco a bloco (`DEPOIMENTOS`).
- Volume: todos em torno de -16 LUFS. O som original só ficou, baixo, no vídeo da mãe (09_A).
- Conferência feita: cada vídeo narrado foi transcrito de volta e bateu com o roteiro palavra por palavra.

## Como montar de novo

```bash
# 1. converter cada vídeo bruto para 1080 x 1350
scripts/video_para_feed.sh entrada.mp4 PASTA/videos/prontos/NOME_1080x1350.mp4
# 2. montar (sem letras, monta todos); a chave da ElevenLabs vem do ambiente, nunca do código
PASTA=/caminho/da/pasta python3 docs/criativos/carrossel-lei-lucas-2026-11/montar.py D K F
# 3. cartão final
node docs/criativos/carrossel-lei-lucas-2026-11/render.js
```

Precisa de ffmpeg com libass, da fonte Inter instalada e, para o cartão, do Playwright.

## Texto da publicação

```
Em todos esses vídeos, alguém por perto sabia o que fazer.

Um engasgo não espera a ambulância chegar. Nos primeiros minutos, quem ajuda é quem está ao lado: a mãe, o pai, a professora, a babá.

No curso de Primeiros Socorros Lei Lucas da Cruz Vermelha do Rio você aprende, na prática, a agir em engasgos, quedas e outras emergências com crianças. São 8 horas, presencial, no Centro do Rio, com certificado.

Inscreva-se na próxima turma: de R$ 249 por R$ 140 com tudo incluso, usando o cupom PRO12. É preço promocional e não sabemos até quando o desconto vai ficar.

Toque em Saiba mais.

Imagens: PMDF, Polícia Militar de MG e Corpo de Bombeiros de SC, via Metrópoles, g1 Sul de Minas e Jornal Razão; arquivo de família.
```

## Pendências antes de anunciar

- Autorizações da tabela acima.
- O cupom PRO12 hoje tem limite de 14 usos (restam 2) e vale até 08/12/2026
  (`site/matricula-cursos-presenciais/api/lib/cupons.php`). Para o anúncio prometer R$ 140 sem data, o limite e a
  validade precisam acompanhar.
