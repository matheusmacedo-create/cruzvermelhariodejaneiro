<!-- Especificação produzida em 04/10/2026 por um fluxo de diagnóstico (página no navegador, funil e eventos, copy
e objeções), três propostas independentes, dois juízes e uma síntese. Implementada no gerador
scripts/gerar_matricula_presencial.py no mesmo dia. -->

> **O que foi implementado diferente desta especificação (04/10/2026)**
>
> - **Sem Fase 0 separada:** os eventos novos entram junto com o layout. A linha de base são os eventos que já
>   existiam no GA4 (`select_item`, `begin_checkout`, `generate_lead`, `purchase`) das semanas anteriores.
> - **Cartões compactos também no computador** (miniatura 88×66 e não foto 16:9). Com as fotos reais, a foto 16:9
>   empurrava a 1ª fileira de botões para 1.008 px em 1280×800; com a miniatura, 774 px. A foto grande fica em
>   "Ver detalhes".
> - **O comparador fecha o grupo "Emergência e vida"** (depois dos cartões, não antes): antes, ele empurrava o 1º
>   botão do celular para fora da meta de 600 px.
> - **No celular, o topo não repete "Certificado da Cruz Vermelha…"** (o H1 já diz), e o rótulo do topo e o da
>   ficha ficam curtos.
> - **O logo do cabeçalho não muda** (o CSS da home é compartilhado; reduzir só aqui é risco à toa).
> - **Sem a chave TURMAS_CONTATO:** o formulário de turmas mantém o texto atual (decisão D5 em aberto); a nota
>   nova da faixa não cita canal ("A secretaria responde em até 3 dias úteis").
> - **A frase sobre 1914** foi reescrita para dizer só o que /historia/ diz: "A Cruz Vermelha forma pessoas no Rio
>   desde 20 de outubro de 1914, quando começou o primeiro curso, de Enfermeiras Voluntárias."
> - **Comprimento no celular:** 7,5 telas com o rodapé herdado da home (6,5 sem ele); a meta era 7.
> - **Medidas finais (local, com o logo e as fotos do ar):** 1º botão de checkout em 571 px (celular, 390×844,
>   modo geral), 570 px (modo curso) e 774 px (computador, 1280×800).

# Especificação final do redesenho da página de matrícula em cursos presenciais

Esta especificação parte da proposta "rapidez", que os dois juízes escolheram, e incorpora os enxertos que eles aprovaram. Todos os vetos foram respeitados.

**Como os conflitos entre propostas foram resolvidos**
- **Modo curso:** usa fichas pré-geradas que aparecem só por CSS. O nó do `<article>` não é movido, e o H1 continua visível, só que menor.
- **Preço:** o rótulo é "Inscrição agora", em `--black`. O total fica atrás de uma constante desligada.
- **Barra fixa:** só aparece quando há um curso em foco.
- **Chat:** só some quando o botão dele ficaria por cima de um botão de matrícula.
- **ÚnicoPag:** a marca não entra na faixa de confiança.
- **WhatsApp e cláusula de reembolso "depois dos 7 dias":** ficam travados até o dono decidir.

**Conferido no repositório hoje**
- O `turmas.js?v=567003f8ad` no ar já contém o commit 1dd8b87. Ele foi mesclado no PR #54 e publicado em 04/10 às 18h37, segundo o README:430. Não republicar.
- O formulário de turmas já usa `SubmitApplication`/`turma_pedido`, e não `Lead`.
- A pergunta 9 do briefing (linha 599) continua aberta.

**O que não foi feito:** nenhum arquivo foi editado e não houve acesso ao GA4 nem ao banco. Ganhos e alturas são estimativas até a medição.

---

## 1. Fases e condições para publicar

| Fase | O que entra | Condição para ir ao ar |
|---|---|---|
| **0. Linha de base** (PR pequeno no gerador, sobre o layout atual) | Os eventos `saida_escola`/`SaidaEscola`, `cta_matricula_visto`, `view_item_list` e `secao_vista`. O `ViewContent`/`view_item` passa a disparar ao carregar com `?curso=`. UTM e `data-saida` em todos os links para a escola. | OK do Matheus, `conferir_pixel.js` atualizado e a data anotada em `docs/rastreamento.md`. Medir de 1 a 2 semanas antes da Fase 1. **Obrigatória.** |
| **1. Layout** (este documento) | Seções 3 a 5. | Fase 0 com pelo menos 7 dias de dados; os critérios de aceite da seção 7 cumpridos; os itens D1 a D5 da seção 8 respondidos ou com a constante no padrão seguro; o OK do Matheus. |
| **PRs separados** (fora desta rodada) | Aviso de cookies compacto para o site todo (`consentimento.js`, com aumento da REVISAO se o texto mudar). Respostas fixas do `chat.js` ("Como faço a matrícula?" ainda manda à escola e fala em "3 dias úteis"). Ajustes no checkout (`checkout_erro`, `pix_copiado`, `Lead` só depois da resposta do servidor, `purchase` enviado ao GA4 pelo servidor). `contato_aberto` com o curso. | Cada um com o seu próprio OK. |

Nesta rodada não se mexe em `chat.js`, `chat.css`, `consentimento.js`, `cursos.json`, no checkout nem no texto do aviso de cookies.

---

## 2. Constantes e dados novos em `scripts/gerar_matricula_presencial.py`

```python
ESCOLA_MATRICULA_AUTOMATICA = True   # False => textos da versão B ("a secretaria confirma turma e horário por e-mail em até 3 dias úteis")
MOSTRAR_TOTAL = False                # só True depois da resposta escrita à pergunta 9 do briefing (D1)
GARANTIA_APOS_7_DIAS = False         # só True depois da resposta sobre "turma confirmada" com matrícula automática (D3)
TURMAS_CONTATO = "atual"             # "atual" (texto de hoje) | "telefone" | "excecao_b2b" (D5)
ORDEM_EXIBICAO = ["primeiros-socorros-basico", "suporte-basico-de-vida", "primeiros-socorros-lei-lucas",
                  "puncao-venosa", "bombeiro-civil", "cuidador-de-idosos", "micropigmentacao-labial"]
NOME_CURTO = {"primeiros-socorros-lei-lucas": "Primeiros Socorros Lei Lucas",
              "cuidador-de-idosos": "Cuidador de Idosos"}          # demais: c["nome"]
BENEFICIO = {  # 1 linha no celular (até cerca de 48 caracteres), só com o que está no cursos.json
  "primeiros-socorros-basico": "RCP, desfibrilador, engasgo e hemorragias",
  "suporte-basico-de-vida": "Atendimento inicial de emergências",
  "primeiros-socorros-lei-lucas": "Emergências com crianças: escolas, creches, famílias",
  "puncao-venosa": "Acesso venoso periférico, para a área da saúde",
  "bombeiro-civil": "Prevenção e combate a incêndios",
  "cuidador-de-idosos": "Cuidados, segurança e bem-estar do idoso",
  "micropigmentacao-labial": "Técnica estética de micropigmentação dos lábios"}
UTM_ESCOLA = "utm_source=cruzvermelhariodejaneiro&utm_medium=matricula&utm_content={local}"
```

**Regras do gerador**
- **Curso sem entrada nos dicionários:** se o slug não estiver em `BENEFICIO` ou `ORDEM_EXIBICAO`, o gerador avisa no terminal, usa `descricao` cortada e põe o curso no fim. Não falha.
- **Trava contra marcadores e números sem dado:** o gerador aborta (`sys.exit(1)`) se `[INSERIR`, `[CONFIRMAR` ou `[DECIDIR` aparecer no HTML final. Também aborta se `MOSTRAR_TOTAL` estiver ligado sem a confirmação registrada.
- **Ordem de exibição × JSON-LD:** `ORDEM_EXIBICAO` vale só para a exibição. O ItemList do JSON-LD continua gerado como hoje.
- **Links para a escola no cabeçalho e no rodapé:** em `partes_da_home()`, o gerador acrescenta, por regex, `data-saida="cabecalho|rodape"` e a UTM a cada link para `escola.cursoscruzvermelha.org`. A home não muda.
- **FAQ da Lei Lucas:** `cursos.json` tem `"faq": []` para esse curso. O gerador puxa de `site/faq-home.json` a pergunta "O que é a Lei Lucas e quem precisa fazer o curso de primeiros socorros?".
- **Abertura duplicada:** o gerador deixa de imprimir a `descricao` como abertura quando ela for igual ao 1º parágrafo de `sobre`.

---

## 3. Ordem das seções

| # | Seção | Celular | Computador |
|---|---|---|---|
| 0 | Cabeçalho (herdado) + camadas fixas: chat, cookies e barra | cabeçalho de 64 px | igual à home |
| 1 | Topo: modo geral (sem `?curso=`) ou modo curso (ficha) | cerca de 0,36 tela no geral; ficha com o botão até 600 px | 2 colunas |
| 2 | Catálogo em cartões `#cursos`, com o comparador de primeiros socorros | cerca de 2 telas | grade de 3 colunas |
| 3 | Depois da inscrição + garantia `#depois-da-inscricao` | cerca de 1 tela | 3 colunas |
| 4 | Confiança | cerca de 0,45 tela | 4 colunas |
| 5 | Perguntas frequentes + botão final | cerca de 0,9 tela recolhida | coluna de 820 px |
| 6 | Turmas para grupos `#turmas-sob-demanda` (faixa compacta) | cerca de 0,6 tela fechada | 2 colunas |
| 7 | Rodapé (herdado) | igual a hoje | igual a hoje |

Meta: no máximo 7 telas no celular (hoje são 12,1).

---

## 4. Seção a seção

### 4.0 Camadas fixas (só CSS e JS desta página)

**Cabeçalho.** O HTML é o de `partes_da_home()`. No celular, CSS local reduz o logo para o cabeçalho grudado cair de 84 para 64 px. O botão "Plataforma" continua, com `data-saida="cabecalho"` e UTM.

**Chat (`#cv-chat`), sem tocar em `chat.js` nem em `chat.css`**
- **Celular (< 720 px):** `.cv-chat-abrir` vira um círculo de 56 px, branco, com ícone vermelho, borda `--line` e sombra. O `.cv-chat-abrir-rotulo` fica **visualmente oculto** com o padrão sr-only (clip). Ele não leva `display:none`, para o nome acessível continuar "Fale com a gente", que é o nome que `/reembolso/` cita.
- **Computador:** a pílula continua, mas branca, com borda e texto vermelhos.
- **Recolher só quando há sobreposição real.** O JS da página põe `html.mr-chat-recolher` enquanto algum `.mr-cta` cruza os 96 px de baixo da tela. Isso é feito com um IntersectionObserver de `rootMargin` no topo igual a `-(innerHeight-96)px`, recalculado no resize. Com a classe, o botão fica `opacity:0; pointer-events:none`, mas só com o painel fechado (`aria-expanded="false"`).
- **Com a barra fixa visível** (`html.mr-barra-on`), o elemento fixo do chat sobe para `bottom: calc(76px + env(safe-area-inset-bottom))`. Conferir em `chat.css` qual elemento tem `position:fixed`.
- **Dependência a registrar:** um comentário no gerador avisa que este CSS depende das classes de `chat.js`.

**Aviso de cookies (`.cvrj-ck`): só espaçamento, texto intacto, REVISAO intacta.** O `consentimento.js` injeta o próprio `<style>` depois do CSS da página, então as regras usam mais especificidade (`html body …`). Abaixo de 560 px: `.cvrj-ck{padding:14px}` e `.cvrj-ck-botoes{grid-template-columns:repeat(3,1fr)}`, com os botões em 0,85rem. Os botões já têm o mesmo peso visual (brancos, com borda preta) e assim continuam. Meta: sair de 475 px para cerca de 350 px, a medir. A versão curta do texto fica no PR separado.

### 4.1 Script inline no `<head>` (modo curso, antes da primeira pintura)

- **Entrada e validação:** o script lê `?curso=` e confere o valor contra `["slug",…]`, uma lista embutida pelo gerador.
- **Quando liga:** se o slug é válido e não há `?turma=1`, o script grava `document.documentElement.dataset.curso = slug`.
- **`#curso-<slug>` não liga o modo curso.** Mantém a rolagem nativa até o cartão. O JS da página abre o `<details>` desse cartão e dispara `view_item` com `origem=url`.
- **Sem JS ou com slug inválido:** a página fica no modo geral.
- **CSS gerado por slug:**
  - `.mr-ficha{display:none}`
  - `html[data-curso="<slug>"] .mr-ficha[data-ficha="<slug>"]{display:block}`
  - `html[data-curso] .mr-hero-apoio{display:none}`

### 4.2 Topo, modo geral (`section.mr-hero`)

```html
<section class="mr-hero">
  <div class="wrap mr-hero-grid">
    <div class="mr-hero-texto">
      <p class="eyebrow">Escola de Educação e Saúde · Cruz Vermelha Brasileira Rio de Janeiro</p>
      <h1>…</h1>
      <div class="mr-hero-apoio"><p class="mr-hero-sub">…</p><ul class="mr-confianca-linha">…</ul></div>
    </div>
    <aside class="mr-hero-passos mr-hero-apoio">…</aside>   <!-- só computador -->
  </div>
</section>
```

**Copy**
- **H1:** "Cursos presenciais com certificado da Cruz Vermelha, no Centro do Rio"
- **Subtítulo:** "Aulas na sede da Praça da Cruz Vermelha, 10. Para se matricular, você paga agora só a inscrição de R$ 99, por PIX ou cartão, sem criar conta. O valor do curso é pago depois."
- **Linha de confiança**, com ícone e texto, sem pílula:
  - [certificate] "Certificado da Cruz Vermelha Brasileira Rio de Janeiro"
  - [rotate-left] "7 dias para desistir, com o valor de volta"
- **Cartão lateral, só no computador** (`aside.mr-hero-passos`), título "Sua matrícula em 3 passos":
  1. "Escolha o curso e pague a inscrição de R$ 99"
  2. Com `ESCOLA_MATRICULA_AUTOMATICA` ligada: "Se o curso tem turma aberta, você entra nela e vê a data na confirmação". Desligada: "A secretaria confirma turma e horário por e-mail"
  3. "Você marca os dias e horários em que pode vir"
  - Selo: "7 dias para desistir · art. 49 do Código de Defesa do Consumidor"

**Visual**
- **Celular:** cerca de 300 px. O eyebrow cai para 0,8rem. O H1 usa `clamp(1.7rem,7vw,2.1rem)` em até 3 linhas. O subtítulo fica em 1rem, cor `#4a5568` (7:1 ou mais sobre `--soft`), no lugar de `--muted`.
- **Computador:** grade `1fr 360px` e altura de até 380 px.
- **O topo não tem:** botão, link de turmas, a palavra "hoje" nem as pílulas.

**Topo no modo curso:** o H1 continua visível, mas cai para 0,95rem/700 em `--muted-escuro` e funciona como chapéu acima da ficha. Nunca leva `display:none`.

### 4.3 Ficha do curso, modo curso (`#mr-fichas > div.mr-ficha[data-ficha=slug]`)

São 7 fichas pré-geradas, **sem `id`, fora do JSON-LD**, colocadas logo após o `.mr-hero`.

```html
<div class="mr-ficha" data-ficha="bombeiro-civil">
  <div class="mr-ficha-foto"><img src="img/bombeiro-civil-960.webp" …></div>      <!-- só computador -->
  <div class="mr-ficha-corpo">
    <p class="mr-ficha-chapeu">Curso presencial · Cruz Vermelha Brasileira Rio de Janeiro</p>
    <p class="mr-ficha-titulo">Curso de Bombeiro Civil na Cruz Vermelha, no Centro do Rio</p>
    <p class="mr-ficha-meta">[clock] 80 horas · [graduation-cap] Ensino Médio · [location-dot] Praça da Cruz Vermelha, 10</p>
    <div class="mr-preco">…</div>
    <a class="btn btn-red mr-cta" data-local="ficha" href="/matricula-cursos-presenciais/checkout/?curso=bombeiro-civil&via=ficha">Fazer matrícula · R$ 99</a>
    <p class="mr-micro">…</p>
    <a class="mr-chat-atalho" href="#chat" data-abrir-chat data-assunto="matricula" data-curso="bombeiro-civil" data-local="ficha">…</a>
    <ol class="mr-mini-passos">…</ol>
    <p class="mr-ficha-links"><a href="#curso-bombeiro-civil" data-abrir-detalhes>Detalhes, conteúdo e dúvidas deste curso</a> · <a href="#cursos">Ver os outros cursos</a></p>
  </div>
</div>
```

O JS repassa as UTMs, `fbclid` e `gclid` da URL atual a todo `href` de checkout, como hoje.

**Bloco de preço `.mr-preco`**, igual na ficha e nos detalhes
- `p.mr-preco-agora`: "Inscrição agora" (0,85rem) e **"R$ 99"** (1,8rem/800, `--black`), mais a linha "garante sua vaga".
- `p.mr-preco-depois` (0,95rem, `#4a5568`): "Valor do curso: R$ 950, pago depois na plataforma da escola, à vista ou parcelado com juros."
- **Observações** que vêm de `observacoes`, impressas logo abaixo do preço. Exemplo do Bombeiro Civil: "Homologação somente no final do curso, valor a consultar, a cargo do aluno."
- **Só com `MOSTRAR_TOTAL`:** "Total do curso com a inscrição: R$ 1.049". Os valores seriam 99 + `valor_curso`: R$ 279 no Básico; R$ 249 no SBV, na Lei Lucas e na Punção Venosa; R$ 499 na Micropigmentação; R$ 1.049 no Bombeiro Civil e no Cuidador de Idosos.
- O único elemento vermelho do bloco é o botão.

**Micro-copy `.mr-micro`** (0,85rem, `#4a5568`)
- 1ª linha: "PIX ou cartão, à vista · sem criar conta · confirmação no seu e-mail"
- 2ª linha: "7 dias para desistir, com o valor de volta"

**Atalho do chat:** "Dúvida antes de pagar? Pergunte no chat."

**Mini linha do tempo `.mr-mini-passos`**
- Com `ESCOLA_MATRICULA_AUTOMATICA`: "Comprovante no seu e-mail" · "Se há turma aberta, você entra nela e vê a data" · "Você marca os dias e horários em que pode vir".
- Sem: "Comprovante no seu e-mail" · "A secretaria confirma turma e horário por e-mail" · "Você marca os dias e horários em que pode vir".

**Visual**
- **Celular:** sem foto. Botão com 100% de largura, 56 px de altura e 1rem. **Aceite: a borda de baixo do botão fica em até 600 px em 390×844**, inclusive com o nome mais longo. A mini linha do tempo vem depois do botão.
- **Computador:** 2 colunas (foto 4:3 com 44% e corpo). O botão tem largura automática, com no mínimo 280 px, e fica dentro da dobra a partir de 1280×800.

**Medição:** ao carregar no modo curso, `view_item`/`ViewContent` dispara uma vez, com `origem=url` (seção 6).

### 4.4 Catálogo em cartões (`section#cursos.mr-catalogo`)

**Estrutura**
- O `h2` visualmente oculto "Cursos presenciais e valores".
- No modo curso, o rótulo visível é "Outros cursos da Cruz Vermelha Brasileira Rio de Janeiro".
- Os rótulos de grupo são `p.mr-grupo-rotulo` ("Emergência e vida", "Formação profissional", "Outros cursos"), com os cursos em `ORDEM_EXIBICAO`.

```html
<article class="mr-curso" id="curso-primeiros-socorros-basico" data-curso="…" data-nome="…" data-curto="…">
  <div class="mr-curso-topo"><img class="mr-curso-mini" src="img/<slug>-480.webp" loading="lazy|eager" alt="…">
    <div><h3 class="mr-curso-nome">Primeiros Socorros Básico</h3><p class="mr-curso-meta">8 horas · Ensino Fundamental</p></div></div>
  <p class="mr-curso-beneficio">RCP, desfibrilador, engasgo e hemorragias</p>
  <div class="mr-curso-acao">
    <p class="mr-preco-curto"><strong>Inscrição R$ 99</strong><span>+ R$ 180 do curso, depois</span></p>
    <a class="btn btn-red mr-cta" data-local="cartao" href="…?curso=<slug>&via=cartao">Fazer matrícula</a>
  </div>
  <details class="mr-curso-mais"><summary>Ver detalhes e dúvidas</summary> … (4.5) </details>
</article>
```

**Visual**
- **Celular:** 1 coluna, cartão de cerca de 210 px.
  - Linha 1: miniatura de 64×48 e, ao lado, o nome (1,05rem/800) e a meta (0,85rem).
  - Linha 2: o benefício (0,9rem, 1 linha, com reticências).
  - Linha 3: o preço à esquerda e o botão à direita (48 px de altura, cerca de 150 px de largura).
  - O `summary` é uma linha de toque de 44 px.
  - Só as 2 primeiras miniaturas são `eager`.
  - **Aceite, modo geral: a borda de baixo do 1º botão fica em até 600 px em 390×844.**
- **Computador:** grade de 3 colunas (2 colunas entre 920 e 1099 px), com os rótulos de grupo em `grid-column:1/-1`.
  - O cartão é vertical: foto 16:9 (480w), nome, meta, benefício, preço, botão largo e `summary`, com preço e botão presos ao pé (`margin-top:auto`).
  - Aberto, o `<details>` ocupa a linha toda (`grid-column:1/-1`).
  - A 1ª fileira de botões fica dentro da dobra de 1280×800.

**Comparador** (`details.mr-comparar`, fechado, antes dos cartões de Emergência e vida)
- Resumo: "Em dúvida entre os cursos de primeiros socorros? Compare".
- Só dados do `cursos.json` e da FAQ da home, sem `[CONFIRMAR]` e sem dizer o que o SBV cobre a menos:
  - **Primeiros Socorros Básico:** 8 horas · Ensino Fundamental · curso R$ 180 · "Inclui Lei Lucas." (texto literal de `observacoes`, sem afirmar que atende à Lei 13.722).
  - **Suporte Básico de Vida:** 4 horas · Ensino Fundamental · curso R$ 150 · "Atendimento inicial de emergências."
  - **Primeiros Socorros Lei Lucas, Ambientes com Crianças:** 8 horas · Ensino Fundamental · curso R$ 150 · "Para quem trabalha com crianças em escolas de educação básica e espaços de recreação infantil (Lei 13.722/2018)."
  - Cada linha tem o botão "Fazer matrícula" do curso (`data-local="comparar"`, `via=comparar`).
- **No celular:** blocos empilhados. **No computador:** tabela de 3 colunas.

**Linha depois do último cartão** (cinza, 0,9rem): "Empresas, escolas e grupos de 15 pessoas ou mais, ou cursos em inglês: [veja as turmas para grupos]" (`href="#turmas-sob-demanda" data-turma-abrir="catalogo"`).

### 4.5 Detalhes do curso (dentro de `details.mr-curso-mais`)

**Conteúdo, nesta ordem**
1. A foto 4:3 (lazy), só no celular.
2. "Sobre o curso": os parágrafos de `sobre`, com `nome_filial()`.
3. As observações.
4. "Dúvidas sobre {nome curto}": a FAQ do curso, cada pergunta num `<details>` (44 px). Na Lei Lucas, a pergunta puxada da `faq-home.json`.
5. O bloco `.mr-preco` + o botão `Fazer matrícula · R$ 99` (`data-local="detalhes"`, `via=detalhes`) + `.mr-micro`.
6. "Dúvidas sobre este curso? O chat responde na hora as perguntas mais comuns." (`data-abrir-chat data-assunto="matricula" data-curso`, `data-local="detalhes"`).
7. Uma linha cinza: "Tem um grupo de 15 a 30 pessoas ou quer este curso em inglês? [Peça uma turma]" (`data-turma-abrir="curso" data-curso`).

**Comportamento**
- **Celular:** abre no lugar. Se o `summary` saiu da tela, rola até o topo do cartão.
- **Computador:** "Sobre" à esquerda, a FAQ do curso à direita e o botão no fim, alinhado à direita.
- **Ao abrir:** `view_item` com `origem=detalhes` (uma vez por curso), e o curso vira o **curso em foco** (`window.mrCursoFoco`).
- **Sem JS:** os `<details>` funcionam nativamente.

### 4.6 Depois da inscrição + garantia (`section.mr-depois#depois-da-inscricao`)

Esta seção substitui `.mr-como` e fica logo depois do catálogo.

- **Eyebrow:** "Depois da inscrição"
- **H2:** "O que acontece depois que você paga"

**`ol.mr-passos`, com `ESCOLA_MATRICULA_AUTOMATICA = True`**
1. **Confirmação no seu e-mail**: "Assim que o pagamento é confirmado, o comprovante da inscrição chega no seu e-mail."
2. **Sua matrícula na escola**: "Se o curso já tem turma aberta, sua matrícula entra nela, e a data de início aparece na tela de confirmação e no e-mail. Se ainda não tem, a secretaria coloca você na próxima turma e avisa por e-mail."
3. **Você diz quando pode vir**: "Você marca os dias e os períodos em que pode vir. A secretaria usa suas respostas para encaixar você na turma."

**Com `False`:** os três passos atuais do gerador (linhas 743 a 745), sem alteração.

**Nota:** "O valor do curso é pago depois, na plataforma da escola, nas condições informadas lá."

**Restrições de copy:** não listar "noite" nem "sábado" e não prometer data.

**Bloco de garantia `div.mr-garantia#garantia`** (ícone rotate-left, borda esquerda `--red`, fundo branco)
- **Selo:** "7 dias para desistir · art. 49 do Código de Defesa do Consumidor"
- **Título:** "7 dias para desistir, com o valor de volta"
- **Sempre, literal de /reembolso/:** "Você pode desistir da inscrição em até 7 dias corridos, contados do pagamento, sem precisar dizer o motivo. O valor pago volta por inteiro, inclusive o custo de processamento, se você tiver escolhido cobri-lo."
- **Só com `GARANTIA_APOS_7_DIAS`:** "Passado esse prazo, a inscrição ainda é devolvida por inteiro se não houver turma com horário compatível para você, ou se você desistir antes de a secretaria confirmar a sua turma (data e horário da aula). Com a turma confirmada, a inscrição não é devolvida, salvo se a própria filial cancelar ou adiar a turma e você não puder participar em outra data."
- **Como pedir:** "Peça pelo chat “Fale com a gente”, no canto da página, no assunto “Pagamento ou PIX”. A confirmação de que o pedido chegou vai para o seu e-mail, com número de protocolo. No PIX, o valor volta para a conta de onde saiu o pagamento; no cartão, a cobrança é cancelada ou o estorno aparece em uma das faturas seguintes."
- **Link:** "Regras completas de cancelamento e reembolso" (`/reembolso/`)
- **Proibido:** "na hora", "automático", "sem risco" e "risco zero" sobre o estorno.

**Visual**
- **Celular:** os passos em coluna, com o número num círculo de 36 px ligado por uma linha vertical. A garantia mostra o título e o 1º parágrafo; o resto fica em `<details>` "Ler as regras".
- **Computador:** 3 colunas, com a garantia em largura total e sem recolher.

**`TEXTO_ESTORNO`** não muda, porque é usado nos e-mails. Ele só deixa de ser impresso nesta página.

### 4.7 Confiança (`section.mr-confianca`)

- **H2:** "Quem dá o curso"
- **Texto:** "A Escola de Educação e Saúde é a escola da Cruz Vermelha Brasileira Rio de Janeiro. Foi no Rio, em 20 de outubro de 1914, que começou o primeiro curso da Cruz Vermelha Brasileira, de Enfermeiras Voluntárias. As aulas são na sede da filial, o Palácio da Cruz Vermelha, no Centro, tombado como patrimônio cultural federal."
- **Itens** (ícone e texto), só fatos de `site/historia` e `faq-home.json`:
  1. [certificate] "Certificado emitido pela Cruz Vermelha Brasileira Rio de Janeiro, com a carga horária do curso"
  2. [location-dot] "Aulas no Palácio da Cruz Vermelha, Praça da Cruz Vermelha, 10, Centro"
  3. [landmark] "Palácio tombado como patrimônio cultural federal"
  4. [scale-balanced] "Utilidade pública municipal (Lei 5.153/2010) e estadual (Lei 9.984/2023)"
- **Blocos opcionais** (`.mr-prova-numero`, `.mr-depoimentos`, `.mr-foto-turma`, `.mr-nota-google`): só são impressos quando o dado existir num dicionário `PROVA` no gerador. A trava da seção 2 impede publicar marcador.
- **Sem marca de terceiros:** nada de ÚnicoPag nem selo de pagamento.
- **Visual:**
  - **Celular:** 4 linhas, cerca de 0,45 tela. Depoimentos, quando existirem, em carrossel com scroll-snap.
  - **Computador:** 4 colunas, com a foto real à direita quando houver.

### 4.8 Perguntas frequentes + botão final (`section.mr-faq-pagina`)

`FAQ_PAGINA` é reescrita nesta ordem. O FAQPage passa a ser gerado de `FAQ_PAGINA + FAQ_TURMAS`.

1. **Os R$ 99 são o valor do curso?** "Não. Os R$ 99 são a inscrição: garantem sua vaga no curso escolhido, na Escola de Educação e Saúde, a escola da Cruz Vermelha Brasileira Rio de Janeiro. O valor de cada curso, de R$ 150 a R$ 950, aparece no cartão dele e é pago depois, na [plataforma da escola], à vista ou parcelado com juros." O link é o **único link para a escola no corpo da página**: `data-saida="faq"`, UTM e `target="_blank"`, em texto comum, sem negrito nem vermelho.
2. **Quando começa minha aula?** Com `ESCOLA_MATRICULA_AUTOMATICA`, o texto dos passos 2 e 3. Sem ela, "A secretaria confirma turma e horário por e-mail em até 3 dias úteis depois do pagamento." Com `GARANTIA_APOS_7_DIAS`, acrescenta "Se não houver turma com horário compatível para você, a inscrição é devolvida."
3. **Posso desistir depois de pagar?** O texto do bloco de garantia (4.6), com as mesmas travas, e o link para /reembolso/.
4. **Posso parcelar?** "A inscrição de R$ 99 é paga à vista, por PIX ou cartão. O valor do curso é pago na plataforma da escola, à vista ou parcelado com juros, nas condições informadas lá."
5. **Qual curso de primeiros socorros eu faço?** O resumo do comparador (só carga, escolaridade, valor e público), com o link "Compare os três" que abre `.mr-comparar`.
6. **O certificado vale? É reconhecido pelo MEC?** "O certificado é emitido pela Cruz Vermelha Brasileira Rio de Janeiro a quem conclui o curso, com carga horária e conteúdo. Nenhum curso livre é reconhecido pelo MEC, aqui ou em qualquer instituição: o MEC regula a educação formal, como o ensino técnico, a graduação e a pós. No Bombeiro Civil, a homologação profissional é feita ao final do curso, à parte."
7. **Os cursos são gratuitos?** "Não. Os sete cursos presenciais são pagos: a inscrição de R$ 99 garante a vaga, e o valor do curso, de R$ 150 a R$ 950, é pago depois. Quem quer aprender e servir pode ser voluntário, num caminho separado dos cursos."
8. **Onde são as aulas?** "Na sede da filial, o Palácio da Cruz Vermelha, na Praça da Cruz Vermelha, 10, Centro do Rio de Janeiro. Todos os cursos são presenciais."
9. **Preciso criar conta ou escolher turma agora?** "Não. Você informa nome, CPF, e-mail e telefone e paga a inscrição. A conta na escola é criada depois do pagamento, com um link para você criar a senha."

**Abaixo da lista:** "Não achou sua dúvida? O chat no canto da página responde na hora as perguntas mais comuns. O que ficar de fora, a equipe responde por e-mail em até 3 dias úteis." Com `data-abrir-chat` e `data-local="faq"`.

**Botão final** (`.mr-cta`, `data-local="faq_final"`)
- Com curso em foco: "Fazer matrícula em {nome curto} · R$ 99", para o checkout desse curso, com `via=faq_final`.
- Sem curso em foco: um link de texto "Ver os cursos e valores" (`#cursos`), **sem estilo de botão**. Isso evita repetir o botão-âncora removido em 18/09.
- O texto do botão é atualizado pelo JS quando o foco muda. O HTML inicial vem com a versão sem foco.
- Embaixo, o `.mr-micro`.

**Visual:** no celular, `<details>` de 48 px, uma aberta por vez. No computador, coluna de 820 px.

**Saem da FAQ:** "Posso ver as turmas abertas antes de pagar?", a comparação com os R$ 100 da escola, "Como tiro dúvidas" (vira a linha) e as 3 perguntas de turmas (vão para a 4.9).

### 4.9 Turmas para grupos (`section.mr-demanda#turmas-sob-demanda`)

Fica depois da FAQ e antes do rodapé. Os IDs atuais continuam: `#turma-form-bloco`, `#turma-form`, `#tf-curso`, `#tf-pessoas`, `#tf-situacao`, `#tf-matricula`, `#tf-dados`, `#tf-enviar`, `#turma-ok`.

**Copy**
- **Eyebrow:** "Turmas para grupos"
- **H2:** "Tem um grupo de 15 pessoas ou mais? Fechamos uma turma só para vocês"
- **Abertura:** "Os cursos do catálogo, em português, têm matrícula individual: é só usar o botão do curso. Esta parte é para quem quer uma turma só do seu grupo, um curso em inglês ou primeiros socorros para jovens de 12 a 14 anos."
- **Itens:**
  - "Empresas, escolas, igrejas e condomínios: turma só do grupo, de 15 a 30 alunos, com o mesmo valor por pessoa, na sede (em outro local, com aprovação)."
  - "Qualquer curso em inglês, com professor ou tradutor · Courses in English. A turma abre com 15 alunos."
  - "Primeiros socorros para jovens de 12 a 14 anos, numa turma só dessa idade, que abre com 15 alunos."
- **Botão em contorno:** "Pedir turma para grupo" (`button.btn.btn-outline`, `data-turma-abrir="faixa"`, `aria-expanded`, `aria-controls="turma-form-bloco"`).
- **Nota:** "Nada é cobrado agora. A secretaria responde por e-mail em até 3 dias úteis." Com `TURMAS_CONTATO="atual"`, fica o texto de hoje até a decisão D5.
- **`FAQ_TURMAS`:** as 3 perguntas atuais em `<details>`. A da empresa troca "pela seção Turmas sob demanda desta página" por "pelo botão Pedir turma para grupo".

**Formulário**
- `#turma-form-bloco` fica `hidden` por padrão.
- Um `<noscript><style>#turma-form-bloco{display:block!important}</style></noscript>` mostra o formulário sem JS.
- Abre sozinho com `?turma=1` ou com `#turmas-sob-demanda` / `#turma-form-bloco`.

**Mudanças em `static/turmas.js`** (hash novo no `?v=`)
1. `abrirBloco()` tira o `hidden` e atualiza `aria-expanded` **antes** do `scrollIntoView`. Vale no clique em `[data-turma-abrir]` e no `?turma=1&turma_curso=&idioma=&alunos=`, que continua preenchendo os campos.
2. Duas etapas: `#tf-dados` só aparece quando a situação é "pode" (hoje aparece também quando está "vazio").
3. `#tf-matricula` ganha `via=turmas` no href.
4. **`#tf-enviar`:** 1rem e 56 px. Desabilitado, fundo `#e2e8f0` e texto `#4a5568`, com a dica "Escolha o curso e o número de alunos para continuar".
5. Os eventos continuam `turma_abrir` e `turma_pedido`/`SubmitApplication`, **nunca `Lead`/`generate_lead`**.

**Visual:** no celular, cerca de 0,6 tela fechada e formulário em 1 coluna quando aberto. No computador, 2 colunas (texto e itens | perguntas), com o formulário abaixo, em largura total, na grade `mr-tf-grade`.

### 4.10 Barra fixa de matrícula (`div#mr-barra.mr-barra`, só abaixo de 720 px)

**Aparece só quando as quatro condições valem**
1. Existe curso em foco: o de `?curso=`, o do último "Ver detalhes" aberto, ou o do `#curso-<slug>` de chegada.
2. Nenhum `.mr-cta` está visível, e a pessoa está abaixo do primeiro `.mr-cta`.
3. `#turmas-sob-demanda` e o rodapé estão fora da tela.
4. O aviso `.cvrj-ck` não está aberto.

Sem curso em foco, a barra não existe.

**Conteúdo**
- À esquerda: `data-curto` (0,95rem/700, com reticências) e, embaixo, "Inscrição R$ 99 · 7 dias para desistir".
- À direita: o botão `.mr-cta` "Fazer matrícula" (48 px, `data-local="barra"`, `via=barra`).

**Visual e acessibilidade**
- Altura de 64 px + `env(safe-area-inset-bottom)`, fundo branco, borda superior `--line`, sombra e `z-index:1050` (abaixo do chat, que tem 1100).
- Escondida, leva `hidden`. A entrada é por `transform` e respeita `prefers-reduced-motion`.
- O `body` ganha `padding-bottom` enquanto a barra está ativa, e o `<html>` recebe `mr-barra-on`.

**Teste:** em 320×568, o botão não pode quebrar linha.

### 4.11 Rodapé

É o herdado da home, sem telefone. Muda só isto: os 2 links para a escola ganham `data-saida="rodape"` e a UTM.

---

## 5. O que sai ou muda de lugar

1. **Topo:** saem as 3 pílulas, o "Garanta sua vaga hoje", "a vaga é sua", o link vermelho "feche sua turma" e a cor `--muted` no subtítulo.
2. **Catálogo:** saem a lista lateral `.mr-lista` e o detalhe único (`.js .mr-detalhe:not(.ativo){display:none}`). Também saem a foto de 560 px acima do botão e a abertura repetida.
3. **Links para a escola:** saem o link de cada curso (`.mr-link-escola`) e o botão "Ver turmas na plataforma da escola" do fim da FAQ. **Ficam:** "Plataforma" no cabeçalho, 1 link de texto na FAQ 1 e 2 no rodapé, todos medidos e com UTM.
4. **"Monte uma turma":** sai do corpo do curso e vira uma linha cinza dentro dos detalhes.
5. **Seção de turmas:** sai do meio da página (3,55 telas) e vira a faixa da 4.9.
   - Saem os 3 cartões com 3 botões, o vermelho cheio "Montar minha turma" e a frase "Alguns cursos só abrem quando juntamos 15 alunos".
   - O formulário deixa de ficar sempre aberto.
6. **"Como funciona"** vira "Depois da inscrição", sobe para depois do catálogo e troca o passo 3 da versão B.
7. **Reembolso:** deixa de ser um parágrafo legal no fim da seção e vira o bloco de garantia, mais a linha curta sob cada botão.
8. **Botão do fim da FAQ:** deixa de apontar para `#cursos` e passa a ir ao checkout do curso em foco.
9. **Chat e cookies:** a pílula vermelha do chat sai no celular (4.0). O aviso de cookies muda só o espaçamento (4.0).
10. **Não mudam:** `TITULO`, `DESCRICAO`, canonical, `og:*`, BreadcrumbList, ItemList de Course, as âncoras `#curso-<slug>`, o checkout, `chat.js`/`chat.css`, `consentimento.js` e `cursos.json`.

---

## 6. Medição

### 6.1 Eventos

| Evento | Destino | Quando dispara | Parâmetros | Fase |
|---|---|---|---|---|
| `page_view` / `PageView` | GA4 e Pixel (+ API de Conversões) | Como hoje | Sem mudança | — |
| `view_item` / `ViewContent` | GA4 e Pixel (`eventID` `vc.<id>` + `cvrjMedicao.servidor` com o mesmo id) | (a) ao carregar com `?curso=` válido sem `?turma=1`, ou com `#curso-<slug>`, uma vez; (b) ao abrir "Ver detalhes", uma vez por curso por página. **Nunca por rolagem nem por impressão de cartão.** | Os de hoje + `origem` = `url` \| `detalhes` (só no GA4; o payload da Meta não muda) | (a) Fase 0; (b) Fase 1 |
| `view_item_list` | GA4 | `#cursos` com pelo menos 30% visível, uma vez por página | `item_list_id:"matricula"`, `item_list_name:"Matrícula cursos presenciais"`, `items` = cursos na ordem exibida (`item_id` = slug, `price` 99) | 0 |
| `cta_matricula_visto` | GA4 | O primeiro `.mr-cta` com pelo menos 50% visível por 1 s, uma vez por página | `curso`, `local` (`ficha` \| `cartao` \| `detalhes` \| `comparar` \| `barra` \| `faq_final`), `modo` (`curso` \| `geral`) | 0 (na Fase 0, `local` = `detalhe_atual`) |
| `select_item` (rótulo interno `SelecionouCurso`) | Só GA4; a Meta não recebe nada no clique | Clique em qualquer `.mr-cta` | Os de hoje + `local` (mesmos valores); o `href` leva `via=<local>` (sem `utm_`, então não reinicia a sessão) | 1 |
| `barra_fixa_vista` | GA4 | Primeira exibição da barra, uma vez por página | `curso` | 1 |
| `saida_escola` + `SaidaEscola` | GA4; Pixel com `fbq('trackCustom','SaidaEscola',{content_ids:[curso],content_category:local})` só com consentimento de marketing | Ouvinte delegado em `document` para `a[href*="escola.cursoscruzvermelha.org"]` | `local` (de `data-saida`: `cabecalho` \| `faq` \| `rodape`; na Fase 0 também `curso` \| `faq_botao`), `curso` (o em foco ou `""`), `destino` (`curso` \| `catalogo` \| `home`, pelo caminho do href), `tela:"matricula"`, `transport_type:"beacon"` | 0 |
| `faq_aberta` | GA4 | `toggle` que abre, uma vez por pergunta por página | `bloco` (`pagina` \| `curso` \| `turmas` \| `comparar`), `pergunta` (até 100 caracteres), `curso` | 1 |
| `secao_vista` | GA4 | Seção com pelo menos 40% visível, uma vez por seção | `secao` (Fase 1: `topo` \| `ficha` \| `catalogo` \| `depois` \| `confianca` \| `faq` \| `turmas` \| `rodape`; Fase 0: `hero` \| `catalogo` \| `turmas` \| `como_funciona` \| `faq` \| `rodape`) | 0 |
| `chat_atalho` | GA4 | Clique num `[data-abrir-chat]` da página, antes de o chat abrir | `local` (`ficha` \| `detalhes` \| `faq`), `curso` | 1 |
| `turma_abrir` | GA4 | Como hoje | `origem` = `faixa` \| `curso` \| `catalogo` \| `anuncio` | 1 |
| `turma_pedido` / `SubmitApplication` | GA4; Pixel + API de Conversões com o mesmo id | Como hoje, no ar desde 04/10 às 18h37. **Proibido `Lead`/`generate_lead` no formulário de turmas.** | Sem mudança | — |
| `begin_checkout`, `generate_lead`/`Lead`, `add_payment_info`, `purchase` | Checkout | Sem mudança nesta rodada. `via` aparece no `page_location` do `begin_checkout`. | — | — |
| `contato_aberto`, `contato_enviado`/`Contact`, `chat_duvida_*` | Chat | Sem mudança, porque o `chat.js` não é tocado | — | — |

**Regras gerais**
- Todos os eventos passam pela função `rastrear()` da página, que já respeita o consentimento: sem escolha ou com "Rejeitar", nada sai para a Meta, e o repasse ao servidor fica pendente.
- Os eventos só do GA4 também exigem consentimento de estatística.

### 6.2 Testes e documentação no mesmo PR

- **`scripts/conferir_pixel.js`:**
  - O seletor `.mr-lista a[data-curso]` vira `.mr-curso-mais > summary`.
  - Cenários novos: "`?curso=bombeiro-civil`, quem já tinha aceitado", que espera `PageView` + `ViewContent` com o mesmo id no repasse; e "`?curso=`, sem escolher" e "`?curso=`, rejeitar", que esperam nada para a Meta.
  - O cenário "matrícula geral, aceitou" continua esperando só `PageView`.
- **Testes manuais:** repetir os de 04/10 no formulário de turmas (turma fechada, matrícula na hora, lista em inglês, link do curso e link de anúncio `?turma=1&turma_curso=&idioma=en&alunos=15`) no celular e no computador.
- **`php scripts/testar_checkout.php`:** confere o hash do `turmas.js` e as `<option>`.
- **Documentação:** atualizar a tabela "Funil da matrícula" e anotar as datas das Fases 0 e 1 em `docs/rastreamento.md`, documentar os `chat_duvida_*` e atualizar a seção da página no README.

### 6.3 Configuração fora do código (feita pelo responsável)

- **GA4, dimensões de evento:** `curso`, `origem`, `local`, `modo`, `secao`, `pergunta`, `bloco`, `destino`, `tela`. O registro não vale para trás, então precisa ser feito antes da Fase 0.
- **GA4, eventos-chave:** `purchase`, `generate_lead` e `turma_pedido`.
- **Meta:** criar o público "SaidaEscola 30 dias" e anotar a data da mudança de significado do `ViewContent`. Ele vai subir com o tráfego de anúncio, então qualquer campanha que otimize por `ViewContent` precisa saber.

### 6.4 Leitura

- **Antes e depois:** 2 semanas da Fase 0 contra 2 semanas da Fase 1, por dispositivo, `modo` e `utm_source`. Ler **taxas**, não volumes, porque o GA4 só vê quem aceitou os cookies.
- **Funil:** `page_view` → `cta_matricula_visto` → `select_item` → `begin_checkout` → `generate_lead` → `purchase`.
- **Indicadores de apoio:**
  - `saida_escola`/`page_view`, por `local`;
  - `turma_abrir` e `turma_pedido`, que devem ficar estáveis;
  - `contato_aberto`;
  - taxa de estorno por curso, no portal (briefing §14).
- **Mudanças nos anúncios no mesmo período confundem a leitura:** anotar todas.

---

## 7. Critérios de aceite

Playwright em 390×844, 320×568, 412×915, 1280×800 e 1366×768, com os scripts do scratchpad (`auditar.js`) contra o preview.

1. **Celular:** a borda de baixo do primeiro botão de checkout fica em até 600 px, no modo geral e no modo curso (os 7 slugs).
2. **Computador:** o primeiro botão fica dentro da dobra a partir de 1280×800.
3. **Comprimento:** no máximo 7 telas no celular, com tudo recolhido.
4. **Contraste:** todo texto com pelo menos 4,5:1; o subtítulo e o preço "depois" com pelo menos 7:1. Os botões de matrícula continuam com 56 px na ficha e nos detalhes.
5. **Sobreposição:** nem a barra nem o chat cobrem um botão de matrícula ou um campo do formulário (testar também com o teclado aberto e com o painel do chat aberto).
6. **Modo curso:** `?curso=` dispara `ViewContent`/`view_item` uma única vez, com o mesmo id no repasse. Sem consentimento, não dispara nada.
7. **`conferir_pixel.js`:** passa com os cenários novos.
8. **`?turma=1`:** abre e preenche o formulário. Sem JS, o formulário aparece aberto.
9. **Sem JS:** o modo geral funciona, os `<details>` abrem e os botões levam ao checkout.
10. **SEO e travas:** o HTML não contém `[INSERIR`/`[CONFIRMAR`/`[DECIDIR`; há um único `h1`; as fichas não têm `id`; o JSON-LD é igual ao de hoje, exceto o FAQPage; as âncoras `#curso-<slug>` existem; o CLS fica em até 0,01.
11. **Teclado e leitor de tela:** a ordem de foco segue a visual (as fichas ocultas por `display:none` saem da ordem). Testar com TalkBack ou VoiceOver.

---

## 8. Decisões do dono, com recomendação

| # | Decisão | Recomendação | Enquanto não decidir |
|---|---|---|---|
| D1 | **Pergunta 9 do briefing:** a filial confirma por escrito que os R$ 99 quitam a inscrição de R$ 100 da escola? | Confirmar e ligar `MOSTRAR_TOTAL`. Mostrar o total antes do pagamento tende a reduzir estorno (hipótese a medir pela taxa de estorno). | `MOSTRAR_TOTAL=False`: só "inscrição agora" e "valor do curso depois". |
| D2 | **Links para a escola** | Ficam "Plataforma" no cabeçalho, 1 link de texto na FAQ 1 e 2 no rodapé, todos com UTM e `saida_escola`. Saem o link de cada curso, o botão da FAQ e a pergunta "Posso ver as turmas…?". Isso cumpre o §12 ("não esconder a escola"). Reavaliar com 4 semanas de `saida_escola`. | — (é o desenho padrão) |
| D3 | **/reembolso/:** com a matrícula automática numa turma com data, a turma já conta como "confirmada pela secretaria"? | Responder por escrito e, se preciso, ajustar /reembolso/ antes. Depois, ligar `GARANTIA_APOS_7_DIAS`. | Fica no ar só a cláusula dos 7 dias, com o link para as regras completas. |
| D4 | **A integração `config-escola.php` (28/09) continua ligada no servidor?** | Confirmar antes de publicar. | Se houver dúvida, `ESCOLA_MATRICULA_AUTOMATICA=False` (textos da versão B). |
| D5 | **WhatsApp no formulário de turmas** (rótulo "WhatsApp com DDD", consentimento e nota). Contraria o adendo de 19/09. | Registrar no briefing a exceção B2B: o campo pede o WhatsApp **da pessoa** (o portal de turmas já usa esse número), e a página nunca mostra número da filial. A nota passa a "A secretaria responde por e-mail". Alternativa: `TURMAS_CONTATO="telefone"` ("Telefone (celular, com DDD)" e consentimento "por e-mail e telefone"), sabendo que muda o que a pessoa autoriza. | Nenhuma troca unilateral. A Fase 1 não vai ao ar sem esta decisão registrada. |
| D6 | **Condições de parcelamento do curso** (nº de vezes e juros) | Se a escola informar, acrescentar ao bloco de preço. | "à vista ou parcelado com juros, nas condições informadas lá". |
| D7 | **Pedir à escola:** um texto do SBV diferente do Básico; o significado de "Inclui Lei Lucas"; a ressalva do MEC na FAQ do Bombeiro Civil | Pedir. Quando vier, o comparador ganha a linha do foco. | O comparador mostra só carga, escolaridade, valor e público. |
| D8 | **Aviso de cookies compacto para o site todo** (PR separado) | Sim, com Rejeitar e Aceitar lado a lado e o mesmo peso. Se o texto mudar, subir juntas `REVISAO`, `MCP_META_REVISAO` e a REVISAO do bloco de medição. | Só o espaçamento por CSS local nesta página. |
| D9 | **Respostas fixas do `chat.js`** (PR separado) | Alinhar com a página: tirar "veja as turmas na escola", atualizar o passo dos "3 dias úteis" e acrescentar `curso` em `contato_aberto`. Regerar as páginas que carregam o chat. | O chat diverge da página até lá. |
| D10 | **Fase 0 antes do layout** | Aprovar e publicar já. | Sem linha de base, o antes e depois fica só com `select_item`/`page_view`. |
| D11 | **Fotos do catálogo** (parecem ilustrativas: hipótese) | Trocar por fotos reais de aula no Palácio, com autorização de imagem. | Mantém as atuais. |
| D12 | **O que levar no primeiro dia e se o 1 kg de alimento ainda vale** | Confirmar e acrescentar uma pergunta à FAQ. | A pergunta não entra. |

---

## 9. Prova social que o dono precisa coletar

Nada disso é publicado sem o dado real. Cada item tem um bloco opcional no dicionário `PROVA` do gerador.

1. `[INSERIR nº de alunos certificados pela Escola de Educação e Saúde em 2025–2026, com a plataforma da escola como fonte]`. Entra numa linha da seção de confiança.
2. `[INSERIR 2 ou 3 depoimentos reais: nome, curso e o que mudou, com autorização por escrito (LGPD)]`. Começar por alunos de Primeiros Socorros Básico e de Punção Venosa. Coleta sugerida: uma pergunta opcional no comprovante de comparecimento ou no fim do curso, com a autorização explícita para publicar.
3. `[INSERIR foto real de uma turma no Palácio da Cruz Vermelha, com autorização de imagem]`.
4. `[INSERIR nota e nº de avaliações do Perfil da Empresa no Google, quando ele existir]`.
5. `[INSERIR escolas e empresas treinadas em Lei Lucas ou NR, com autorização para citar]`.

Fica vetado em qualquer caso: número estimado, "últimas vagas", contagem regressiva e depoimento sem autorização.

---

## 10. Hipóteses e riscos que continuam

- **Ganhos não medidos:** todo ganho de conversão é hipótese. Não há GA4, nem banco, nem A/B no site estático. Testes de H1 ou de texto de botão ficam para depois, em sequência, com 2 a 4 semanas cada. Candidatos: o H1 "Na hora da emergência, seja quem sabe o que fazer" (só no modo curso de Emergência e vida) e o botão "Garantir minha vaga · R$ 99".
- **Vendas de 03/10:** ligá-las aos links da escola continua sendo hipótese. O `saida_escola` vai mostrar.
- **Histórico de 18/09:** um botão "Escolher meu curso" no topo foi testado e removido. Este desenho não tem botão-âncora no topo: o primeiro botão sempre vai ao checkout. Registrar no README como outra hipótese.
- **Turmas para grupos:** podem cair ao descer para o fim. O link de anúncio `?turma=1` continua direto. Se `turma_abrir` cair muito, subir a faixa para logo depois de "Depois da inscrição".
- **Aviso de cookies na primeira visita:** mesmo compacto, ele cobre parte da dobra até a pessoa escolher. Medir `cta_matricula_visto` por dispositivo.
- **SEO:** risco baixo, porque o título, a descrição e o JSON-LD continuam e o conteúdo dentro de `<details>` é indexado. Acompanhar no Search Console, por 4 semanas, as consultas "cruz vermelha cursos rj" e "curso de primeiros socorros cruz vermelha rj".