<!-- Plano elaborado em 16/09/2026 a partir do levantamento do ecossistema e do código de puncaovenosa-fullautomatic. Status: aguardando as decisões da Fase 0 antes de iniciar a implementação. -->

> **Nota de 18/09/2026.** A definição do produto passou a ser `docs/briefing-matricula-rapida.md`.
> Duas decisões deste plano mudaram lá: (1) a página do aluno fica **dentro do domínio**, em
> `cruzvermelhariodejaneiro.org/matricula-rapida/`, sem subdomínio; o backend reaproveitado
> vira API com CORS; (2) o pós-pagamento ganha a API da escola (tela Parabéns com acesso), com
> a versão manual (planilha + painel + WhatsApp) como fallback de lançamento. O restante
> (catálogo de cursos, migration `0016`, preço por etapa, planilha, painel) continua válido.

# Plano: "Matrícula express" — fluxo paralelo de matrícula a partir do site principal

## Contexto

- **Problema.** O fluxo da Escola de Educação e Saúde CVB-RJ (`escola.cursoscruzvermelha.org`,
  app separado no Render) é engessado: curso → turma → criar conta (nome, e-mail, documento,
  senha) → login → pagar o curso inteiro (ex.: Bombeiro Civil R$ 950 + matrícula R$ 100 =
  R$ 1.050). Conferido em 16/09/2026: `GET /inscrever/<turma>` redireciona para `/login`.
  A escola quer continuar assim e **não vamos mexer nela**.
- **Ideia (Matheus, carta branca).** Um atalho para o lead decidido: na home de
  `cruzvermelhariodejaneiro.org`, um bloco "Pague sua matrícula agora"; o lead escolhe o
  curso, informa dados, paga **só a matrícula de R$ 99** (PIX ou cartão) e a **secretaria**
  fecha turma, dia e o restante depois.
- **Decisões já tomadas.** R$ 99 fixo; pagamento pela **ÚnicoPag**; secretaria acompanha
  por **planilha + painel**; v1 = bloco na home + página de matrícula com pagamento +
  aviso à secretaria.
- **Descoberta que muda tudo.** `matheusmacedo-create/puncaovenosa-fullautomatic` (Next.js 16
  na Vercel, Supabase, Resend, Meta Pixel + CAPI) **já é esse funil**, para um curso só:
  checkout PIX/cartão pela ÚnicoPag (`lib/unicopag.ts`), cobrança em duas etapas com a
  matrícula em **R$ 99** (`COBRANCAS` em `lib/enrollment.ts`, migration `0014`), webhook que
  confirma reconsultando a API, painel `/secretaria` com senha, espelho em planilha Google,
  webhook assinado para a secretaria, e-mails ao aluno, eventos do funil no Meta. O que ele
  tem de mono-curso é superficial e enumerável. O projeto Supabase dele
  (`lqpnbqislaxzhqkszijg`) está **INACTIVE** (pausado). Código clonado (só leitura) em
  `/home/user/matheusmacedo-create/puncaovenosa-fullautomatic`.
- **Resultado esperado.** Em poucos dias: `matricula.cruzvermelhariodejaneiro.org` recebendo
  matrículas de qualquer um dos 7 cursos, home e página de cursos apontando para lá, e a
  secretaria recebendo cada pagamento na planilha e no painel.

## Abordagem recomendada

**Generalizar o app da Punção Venosa para multi-curso, no mesmo repositório e no mesmo
projeto Vercel, com um único host canônico `matricula.cruzvermelhariodejaneiro.org`.**

- Por que não um serviço novo: reimplementaria o que já custou caro acertar (ÚnicoPag com
  reaproveitamento de PIX pendente e `maxDuration = 60`, postback não confiável tratado por
  reconsulta à API + polling, PCI com cartão em claro sem gravar/logar, sessão por cookie
  `httpOnly` sem login, RLS forçada, painel, planilha, e-mails, Pixel + CAPI deduplicados).
  O Supabase pausado não pesa: qualquer opção precisa de banco, e as 15 migrations recriam o
  schema em minutos.
- **Host único.** `NEXT_PUBLIC_SITE_URL` é um só e alimenta `postback_url`, os links dos
  e-mails ("voltar para o pagamento") e a URL de validação; dois hosts servindo o mesmo app
  fariam o aluno cair no outro host sem cookie de sessão. Então: `matricula.…` é o host;
  `/` continua sendo a landing da Punção (anúncios seguem funcionando);
  `puncaovenosav1.cruzvermelhariodejaneiro.org` passa a redirecionar (308, no painel de
  domínios da Vercel) para `matricula.…`.
- **Entrada do express.** `/matricula?curso=<slug>` redireciona para
  `/inscricao?curso=<slug>&etapa=dados` preservando UTMs/`fbclid`; `/matricula` sem curso
  lista os cursos. A etapa `dados` do funil existente ganha um `<select>` de curso (padrão
  `puncao-venosa`, o que mantém a landing intacta).
- **Site institucional só linka**, nunca embute em iframe (`X-Frame-Options: SAMEORIGIN` e
  cookie `SameSite=Lax`).

### Decisões de negócio a confirmar antes de codar (Fase 0)
- R$ 99 do express **substituem** a inscrição de R$ 100 da escola? (define a copy e a
  linha "Inscrição: R$ 100,00" em `site/cursos.html:668`).
- SLA prometido na tela e no e-mail: contato da secretaria pelo WhatsApp em **até 2 dias úteis**.
- Requisito por curso (texto do checkbox): Ensino Fundamental (Cuidador) × Ensino Médio.
- Carga horária de Primeiros Socorros Básico: `cursos.html` diz 04h, a home diz 8 horas.
- Incluir "Suporte Básico de Vida" como card 07 em `cursos.html` (existe na home e na escola).
- Quem concilia na escola e quem estorna (não há função de estorno em `lib/unicopag.ts`;
  estorno é manual no painel da ÚnicoPag).

## Mudanças no app (`puncaovenosa-fullautomatic`, branch `feat/matricula-express`)

### 1. Catálogo de cursos — novo `lib/cursos.ts`
Arquivo TS (não tabela: preço é constante de build por decisão do projeto; 7 itens que
mudam raramente). Campos: `slug`, `nome`, `nomeCurto`, `cargaHoraria`, `escolaId`,
`escolaUrl`, `ativo`, `cursoCentavos` (`15000` só para `puncao-venosa`; `null` nos demais:
o restante é fechado pela secretaria), `temTriagem` (só Punção), `requisito` (texto do
checkbox). Exporta `CURSO_PADRAO = 'puncao-venosa'`, `cursoPorSlug` (fallback com
`nome = slug`, para o painel nunca quebrar), `ehCursoAtivo`, `CURSOS_ATIVOS`. Não importa
`lib/enrollment.ts` (evita ciclo).

| slug | Curso | UUID na escola |
|---|---|---|
| `bombeiro-civil` | Bombeiro Civil (80h) | `5bd737ee-00a6-48dc-b5ab-08cde9b12897` |
| `cuidador-de-idosos` | Cuidador de Idosos, curso livre (160h) | `2bf8d91d-ad41-4232-903f-ca4895b611f3` |
| `micropigmentacao-labial` | Micropigmentação Labial (24h) | `b05365c2-c066-4c73-bb4a-f32475a338a2` |
| `primeiros-socorros-basico` | Primeiros Socorros Básico | `05f2c1fa-3aef-40c6-a5df-66ea30c32a4b` |
| `primeiros-socorros-lei-lucas` | Primeiros Socorros – Lei Lucas (8h) | `f8373fef-2523-42e4-ab3f-cc1059b10379` |
| `puncao-venosa` | Punção Venosa (8h) | `ab2035b1-2c11-476f-a275-d29a4111ecf0` |
| `suporte-basico-de-vida` | Suporte Básico de Vida (4h) | `4f84e97b-1908-4e1e-8ea7-97d4124caff4` |

### 2. Banco — `supabase/migrations/0016_inscricoes_curso.sql` (+ `0017`)
- `inscricoes.curso_slug text not null default 'puncao-venosa'` + check `^[a-z0-9-]+$`.
- Chave natural passa a ser **`(cpf, curso_slug)`**: `drop constraint inscricoes_cpf_key`,
  `add constraint inscricoes_cpf_curso_key unique (cpf, curso_slug)`; índice
  `(curso_slug, criado_em desc)`. `visitas_landing.curso_slug text` (visita por curso).
- Nova `upsert_inscricao(... 9 args ..., p_curso_slug text)` com `on conflict (cpf,
  curso_slug)`, no padrão das migrations `0006`/`0013`; **a versão de 9 args vira wrapper**
  que chama a nova com `'puncao-venosa'`, senão o código antigo quebra entre a migration e
  o deploy ("no unique constraint matching ON CONFLICT"). `drop` + recriar
  `validar_credencial` devolvendo `curso_slug` (mudança de tipo de retorno exige drop).
- `0017_upsert_inscricao_drop_9_args.sql`: aplicar **só depois** do deploy.
- Número de inscrição continua `CVB-YYYY-NNNN` (sequência única). Triagem de 8 passos fica
  só para `temTriagem`; nos cursos express, `paga` é o estado final do app.

### 3. Preço por etapa — `lib/enrollment.ts`
`EnrollmentData.curso`; `fieldError('curso')`; `composicaoDoCurso(slug)` = matrícula 9900
(+ item `curso` só se `cursoCentavos`); `escalar(composicao, total)` para o preço de teste;
`cobrancasDoCurso(slug)` e `cobraCursoAParte(slug)`. `COMPOSICAO_PRECO`, `COBRANCAS`,
`COBRA_CURSO_A_PARTE` e `PRECO_*` continuam existindo, derivados de `CURSO_PADRAO`, para a
landing não mudar. Regra do repo: mexeu aqui, conferir `/inscricao` e `/triagem/[step]`.

### 4. API
- `app/api/inscricoes/route.ts`: aceita e valida `curso` (`ehCursoAtivo`), passa `p_curso_slug`.
- `app/api/inscricoes/consulta/route.ts`: **obrigatório no mesmo deploy**: filtrar por
  `curso_slug`; hoje faz `.maybeSingle()` por CPF e quebraria com duas inscrições do mesmo CPF.
- `app/api/inscricoes/atual/route.ts`: devolve `curso {slug, nome, cargaHoraria, escolaUrl}`,
  `temTriagem`, `cobraCursoAParte`.
- `app/api/pagamentos/route.ts`: `select` inclui `curso_slug`; `aCobrar =
  cobrancasDoCurso(slug)[etapa]`; recusa `etapa === 'curso'` quando o curso não cobra à
  parte; `cart[].title = "${curso.nome} — ${item.rotulo}"`; `metadata.curso`,
  `metadata.curso_slug`; `origin: "matricula-${slug}"`.
- `app/api/pagamentos/atual/route.ts`: valor esperado por curso (hoje compara com
  `COBRANCAS[pedida]`); ler `curso_slug` via embed `inscricoes!inner(curso_slug)`.
- `app/api/visitas/route.ts`: grava `curso_slug`. `app/api/diagnostico/route.ts`: lista o
  catálogo e alerta se `NEXT_PUBLIC_SITE_URL` não é o host canônico.
- `app/api/webhooks/unicopag/route.ts`: **sem mudança** (agnóstico ao curso).

### 5. Funil (cliente) e páginas
- Novo `app/matricula/page.tsx` (Server Component, `robots: { index: false }`): com
  `?curso` válido → `redirect` para `/inscricao?...&etapa=dados` preservando a query; sem
  curso → lista de cursos ativos com links que carregam a query. Inclui `<RegistroDeVisita />`.
- `components/enrollment-flow.tsx`: `curso` inicial = `?curso` válido → rascunho do
  `localStorage` → `CURSO_PADRAO`; `<select>` de curso como primeiro campo da etapa `dados`
  (`.field select` já existe em `app/globals.css`); `close()` volta para `/matricula` quando
  veio de lá; `ConfirmationStage` express: "Matrícula confirmada. A secretaria da Escola
  entra em contato pelo WhatsApp em até 2 dias úteis para fechar turma, data e o restante do
  curso. Não é preciso se inscrever de novo na plataforma." + link para o curso na escola.
  Trocar o curso com o formulário válido abre outra cadeia e outra inscrição `(cpf, curso)`:
  correto, por isso o seletor vem antes dos campos de texto.
- `lib/api-cliente.ts` (`consultarCpf(cpf, curso)`, `registrarVisita` com `cursoSlug`),
  `lib/checkout.ts` (`atribuicaoAtual()` inclui `cursoSlug`), `lib/rastreio.ts`
  (`content_name`/`content_ids` do catálogo; nunca `fbq` direto nas telas),
  `components/price-breakdown.tsx`, `components/clinical-header.tsx`,
  `components/student-pass.tsx` (curso da inscrição; triagem e "falta o curso" só quando
  se aplicam), `app/validar/[token]/page.tsx`, `app/layout.tsx` (título genérico; o da
  Punção vai para `app/page.tsx`).

### 6. Secretaria, planilha, e-mails, webhook, docs
- `lib/planilha.ts` + `docs/planilha-secretaria.gs`: colunas **no fim** (o Apps Script
  escreve por posição): `Curso`, `Etapa da cobrança`, `Origem (utm_source)`,
  `Campanha (utm_campaign)`, `Conciliado na escola` (manual). **Planilha nova**
  ("Matrículas express"), restrita.
- `lib/email.ts`: nome do curso nos 4 e-mails; `matricula_paga` sem triagem/saldo nos cursos
  express, com a frase do SLA e o link da escola. `lib/webhook-secretaria.ts`: payload com
  `inscricao.curso {slug, nome}` e `pagamento.etapa`. `lib/meta-capi.ts`: `content_name`
  do catálogo.
- `app/secretaria/page.tsx` + `components/secretaria-inscricoes.tsx`: coluna e recorte por
  curso, `completa = triagem_concluida || (paga && !temTriagem)`, bloco "Por curso"; nada
  que se mova sozinho (regra do repo). Atualizar `EXEMPLO_PAYLOAD`.
- `.env.example`, `README.md`, `CLAUDE.md` (armadilhas: chave `(cpf, curso_slug)`,
  catálogo, triagem por curso), `app/politica-de-privacidade` e `app/politica-de-reembolso`
  (generalizar "Punção Venosa" → "cursos da Escola").

## Mudanças no site institucional (este repo, `site/`)

### Home — `site/index.html`
Nova `<section id="matricula">` **depois do fechamento de `#cursos` e do `<script>` do
slider (linha ~1253), antes de `.alert` (~1255)**: o lead acaba de ver os cursos e "já
decidiu?" é a pergunta natural; âncora própria para bio/anúncios
(`cruzvermelhariodejaneiro.org/#matricula`); não mexe nos `.mini-course-card` (são `<a>`,
botão aninhado seria inválido); fundo branco contrasta com `.courses` (`--soft`) e com a
faixa preta `.alert`. Acrescentar `<a href="#matricula">Matrícula</a>` em `.nav-links`
(linha ~992). Formulário **GET** funciona sem JavaScript e cai em `/matricula?curso=…`.

```html
<section class="matricula-express" id="matricula" aria-labelledby="matricula-title">
  <style>
    .matricula-express{background:#fff;border-top:1px solid var(--line)}
    .matricula-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:40px;align-items:center}
    .matricula-form{background:var(--soft);border:1px solid var(--line);border-radius:var(--radius);padding:28px;display:grid;gap:12px}
    .matricula-form label{font-weight:800;font-size:.9rem}
    .matricula-form select{min-height:46px;border:1px solid var(--line);border-radius:12px;padding:0 14px;font:inherit;background:#fff}
    .matricula-confianca{color:var(--muted);font-size:.9rem;margin:14px 0 0}
    @media (max-width:920px){.matricula-grid{grid-template-columns:1fr}}
  </style>
  <div class="wrap matricula-grid">
    <div>
      <p class="eyebrow">Matrícula express</p>
      <h2 id="matricula-title">Já escolheu seu curso? Garanta sua vaga agora.</h2>
      <p class="lead">Pague só a matrícula de R$ 99, por PIX ou cartão. A secretaria da Escola de Educação e Saúde entra em contato pelo WhatsApp para fechar turma, data e o restante do curso.</p>
      <p class="matricula-confianca">Pagamento em ambiente da Cruz Vermelha Brasileira RJ · Comprovante por e-mail · 7 dias para desistir com devolução integral.</p>
    </div>
    <form class="matricula-form" id="matricula-form" method="get" action="https://matricula.cruzvermelhariodejaneiro.org/matricula">
      <label for="matricula-curso">Qual curso você quer fazer?</label>
      <select id="matricula-curso" name="curso" required>
        <option value="" disabled selected>Escolha o curso</option>
        <option value="bombeiro-civil">Bombeiro Civil</option>
        <option value="cuidador-de-idosos">Cuidador de Idosos (curso livre)</option>
        <option value="micropigmentacao-labial">Micropigmentação Labial</option>
        <option value="primeiros-socorros-basico">Primeiros Socorros Básico</option>
        <option value="primeiros-socorros-lei-lucas">Primeiros Socorros – Lei Lucas (ambientes com crianças)</option>
        <option value="puncao-venosa">Punção Venosa</option>
        <option value="suporte-basico-de-vida">Suporte Básico de Vida</option>
      </select>
      <input type="hidden" name="utm_source" value="site">
      <input type="hidden" name="utm_medium" value="home">
      <input type="hidden" name="utm_campaign" value="matricula-express">
      <input type="hidden" name="utm_content" value="bloco-home">
      <div class="cta-row">
        <button type="submit" class="btn btn-red">Pagar matrícula de R$ 99</button>
        <a class="btn btn-outline" href="https://escola.cursoscruzvermelha.org/cursos" target="_blank" rel="noopener">Ver turmas e valores na Escola</a>
      </div>
    </form>
  </div>
  <script>
  (function(){
    var f=document.getElementById('matricula-form');if(!f)return;
    var q=new URLSearchParams(location.search);['fbclid','gclid'].forEach(function(k){if(q.get(k)){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=q.get(k);f.appendChild(i)}});
    f.addEventListener('submit',function(){
      var s=f.querySelector('select[name=curso]'),nome=s.options[s.selectedIndex].text;
      if(!window.fbq)return;
      fbq('track','InitiateCheckout',{content_name:nome,content_ids:[s.value],content_category:'matricula-express',value:99,currency:'BRL'});
      fbq('trackCustom','funil_2_cta',{content_name:nome,cta_position:'home-matricula'});
    });
  })();
  </script>
</section>
```

Pixel: quem entra por `/matricula` pula a landing do app e não dispara o `funil_2_cta` de lá,
então a home dispara o mesmo par (`InitiateCheckout` + `funil_2_cta`) com `value: 99`. O
app precisa usar o **mesmo** Pixel `2224500131617302` da home (conferir na Vercel).

### Cursos — `site/cursos.html`
Em cada `.course-card`: o botão vermelho vira "Pagar matrícula de R$ 99" →
`https://matricula.cruzvermelhariodejaneiro.org/matricula?curso=<slug>&utm_source=site&utm_medium=cursos&utm_campaign=matricula-express&utm_content=card-<slug>`
(com os mesmos eventos no clique); "Ver turmas e inscrever-se" (outline, já existe) fica;
WhatsApp continua no hero, na `.call-box` e no rodapé. Corrigir "Inscrição: R$ 100,00"
(linha ~668) conforme a decisão de negócio; decidir o card 07 (Suporte Básico de Vida).
Publicar com `scripts/publicar_hostinger.sh site/index.html site/cursos.html` + limpar
cache (exige aprovação explícita, como antes); atualizar `README.md`.

## Infra e configuração (com Matheus)

- **Supabase**: tentar **restaurar** `lqpnbqislaxzhqkszijg` (mantém histórico e a
  integração da Vercel); se não der, projeto novo em `sa-east-1` aplicando `0001`…`0016`
  e atualizando `NEXT_PUBLIC_SUPABASE_URL`/`SUPABASE_SECRET_KEY`. `0016` **antes** do
  deploy, `0017` **depois**. Evitar nova pausa: keep-alive diário (o cron de
  `vercel.json` já roda 1×/dia; garantir que toque o banco) ou plano pago.
- **Vercel**: no projeto existente, adicionar `matricula.cruzvermelhariodejaneiro.org`;
  `puncaovenosav1.…` → "Redirect to" 308 para o novo host; **Deployment Protection
  desligada** em produção (o postback chega sem cookie); manter `regions: ["gru1"]`.
- **DNS (Hostinger, zona nesta conta)**: `CNAME matricula → alvo informado pela Vercel`
  (padrão de `doar`, `redacao`, `puncaovenosav1`) e o TXT `_vercel` que ela pedir.
- **Variáveis (nomes)**: `NEXT_PUBLIC_SUPABASE_URL` + `SUPABASE_SECRET_KEY`;
  `UNICO_API_KEY` (produção), `UNICO_BASE_URL`,
  `NEXT_PUBLIC_SITE_URL=https://matricula.cruzvermelhariodejaneiro.org` (define o
  `postback_url`); `SECRETARIA_SENHA` (≥16); `PLANILHA_URL`/`PLANILHA_TOKEN`;
  `RESEND_API_KEY`/`EMAIL_REMETENTE` (domínio já verificado na Resend);
  `NEXT_PUBLIC_META_PIXEL_ID=2224500131617302`, `META_CAPI_TOKEN`, `CRON_SECRET`.
  Só durante testes: `SIMULAR_PAGAMENTO` (Preview), `PERMITIR_CONFIRMACAO_MANUAL`,
  `NEXT_PUBLIC_PRECO_TESTE_CENTAVOS`, `META_CAPI_TEST_EVENT_CODE`; **remover antes de vender**.
- **ÚnicoPag**: nada no painel; `postback_url` vai em cada `POST /public/v1/payments`.
  Conferir a chave em `/api/diagnostico` (`provedor.chaveValida`).
- **Planilha**: nova, restrita; colar o `.gs` atualizado; App da Web ("Executar como: eu",
  "Qualquer pessoa"); URL `/exec` → `PLANILHA_URL`; token → `PLANILHA_TOKEN`; redeploy.

## Riscos e mitigação

| Risco | Mitigação |
|---|---|
| LGPD / CPF (obrigatório na ÚnicoPag) | Banco só pelo servidor, RLS forçada, painel por senha longa, planilha restrita; generalizar a política de privacidade; `LegalNote` antes de pagar. |
| Pagamento duplicado | Reaproveitamento de PIX pendente, `409` para etapa já paga, `unique (cpf, curso_slug)`; resíduo (PIX antigo pago tarde) é estornado pela secretaria. |
| Aluno paga e a escola não reconhece | Processo: linha na planilha/painel com curso e `CVB-2026-NNNN`; SLA de 2 dias úteis; secretaria cria a matrícula na escola e aplica os R$ 99; coluna manual "Conciliado na escola"; e-mail diz "não se inscreva de novo". v1.1: e-mail à secretaria por pagamento confirmado. |
| Reembolso | `/politica-de-reembolso` (7 dias) já existe; estorno manual no painel da ÚnicoPag; definir quem estorna. |
| Consistência de preço | R$ 99 tem fonte única no app (`COBRANCAS.matricula`); o site só repete o texto e é republicado se mudar; sob preço de teste a tela avisa. |
| Supabase pausado | Restaurar/recriar; keep-alive; conferir `/api/diagnostico.banco`. |
| Postback sem assinatura | Já mitigado (reconsulta + polling); rota fora da Deployment Protection. |
| Curso sem turma prevista | Copy diz que a secretaria confirma a data; 7 dias de arrependimento; `ativo` no catálogo tira o curso do ar com um deploy. |

## Fases

0. **Decisões e acessos (dia 0)**: lista da Fase 0 acima; restaurar/criar Supabase; planilha
   e Apps Script; CNAME `matricula`; domínio na Vercel; remetente Resend; `UNICO_API_KEY` e
   ID do Pixel.
1. **App (dias 1–2)**: `0016`; `lib/cursos.ts`; `lib/enrollment.ts`; rotas `inscricoes`,
   `inscricoes/consulta`, `inscricoes/atual`, `pagamentos`, `pagamentos/atual`, `visitas`;
   `enrollment-flow` (select + confirmação express); `/matricula`; `price-breakdown`,
   `clinical-header`, `student-pass`, `validar`; `email`, `planilha` (+ `.gs`),
   `webhook-secretaria`, `meta-capi`, `rastreio`; painel e recortes; metadata; docs.
   `pnpm install --frozen-lockfile && pnpm typecheck && pnpm build`; PR contra `main`
   (`CONTRIBUTING.md`). Deploy de Preview com simulação.
2. **Site (dia 2–3)**: bloco `#matricula` + link no menu; CTAs em `cursos.html`; publicar;
   `README.md`.
3. **Verificação e go-live (dia 3)**: ver abaixo; aplicar `0017`; remover variáveis de teste.
4. **v1.1**: e-mail à secretaria em `pagamento_confirmado`; `funil_por_origem` por curso
   (`0018`); mini-triagem "período preferido"; status "conciliada" acionável no painel;
   mover a landing da Punção para `/puncao-venosa` e `/` → `/matricula`; corrigir
   `alternates.canonical` global; sincronizar o catálogo a partir da página pública da
   escola (o script `scripts/gerar_sitemap_escola.py` já a lê).

## Verificação

1. **Preview, simulação** (sem `UNICO_API_KEY`): para os 7 cursos, `/matricula?curso=slug`
   → etapa `dados` com o curso pré-selecionado e UTMs preservadas; PIX simulado (copiar
   aprova) e cartão simulado; mesmo CPF em dois cursos cria duas inscrições; mesmo CPF no
   mesmo curso reaproveita; `/minha-inscricao` mostra o curso; painel filtra por curso;
   planilha recebe `Curso`/`Etapa`; `email_entregas` registra `cobranca_aberta` e
   `matricula_paga` com o curso; Punção mantém a triagem, os demais não. CPFs fictícios
   com dígito válido, apagados depois.
2. **Produção, cobrança real de teste**: `NEXT_PUBLIC_PRECO_TESTE_CENTAVOS=10` e
   `META_CAPI_TEST_EVENT_CODE`; `/api/diagnostico.prontoParaTesteOperacional === true` e
   `postback.alcancavel === true`; pagar R$ 0,10 por PIX num curso express e R$ 0,10 no
   cartão em outro; conferir logs `[funil] pagamentos` e `[funil] webhook` na Vercel,
   `confirmar_pagamento` gerando `CVB-2026-NNNN`, linha da planilha como `confirmado`,
   e-mail com o curso certo, `Purchase` deduplicado no Gerenciador de Eventos, `funil_2_cta`
   vindo da home.
3. **Fechar**: remover as variáveis de teste; redeploy; `/api/diagnostico.prontoParaVender
   === true`; aplicar `0017`; excluir inscrições de teste; um pagamento real de R$ 99 com a
   secretaria acompanhando a planilha; apontar bio/anúncios para
   `cruzvermelhariodejaneiro.org/#matricula`.
