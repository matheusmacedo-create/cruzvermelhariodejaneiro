# BRIEFING DE PROJETO
# Matrícula cursos presenciais — Cruz Vermelha Brasileira · Rio de Janeiro

**Uso deste arquivo:** documento-fonte para discutir, especificar ou implementar o projeto com outra IA ou com o time.
**Idioma:** português (Brasil).
**Status:** desenho alinhado; protótipo visual iniciado; pagamento e API da escola ainda não integrados.
**Versão:** 1.1 — 18/09/2026 (revisão do briefing de 18/09).

### O que mudou nesta versão

1. **Preço da inscrição: R$ 99** em todos os cursos (decisão de 16/09). A escola mostra R$ 100; o
   atalho cobra R$ 99 e a escola precisa aceitar esse valor como quitação da inscrição.
2. **URL definitiva: `https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais`**, dentro do domínio
   institucional, sem subdomínio. Motivo: rastreamento em primeira parte (GA4 `G-HDYZZ5JZHF` e Meta
   Pixel `2224500131617302` já instalados no site) sem ligação entre domínios.
3. **Padrão visual = o do site da filial, com os tokens e arquivos reais** (seção 10), não uma
   aproximação de cor. O protótipo atual está fora desse padrão e precisa ser refeito em cima do
   cabeçalho e rodapé do site.
4. Nova seção 23: como o circuito se encaixa no que já existe (backend validado da Punção Venosa,
   hospedagem estática na Hostinger) e o que muda no plano de 16/09.
5. Perguntas abertas atualizadas: as já respondidas saíram da lista, entraram as novas.

> **Nota de 18/09/2026 (ajustes depois da primeira publicação).** (1) O topo e o bloco de cada
> curso focam em "Faça sua matrícula agora e garanta sua vaga"; a regra "a secretaria confirma
> horário depois" sai do topo e fica só em "Como funciona" e no FAQ. (2) A página não mostra
> telefone nem WhatsApp da secretaria, nem o botão flutuante da home: desviavam da matrícula.
> (3) Botão único por curso, "Fazer matrícula agora"; o link para o curso na plataforma da escola
> fica discreto, no fim do detalhe. (4) As fotos vêm do catálogo da escola, recortadas em 4:3.
> (5) `cursos.html` saiu do ar no mesmo dia e responde 301 para esta página, que virou a vitrine
> de cursos do domínio. (6) O valor do curso é pago na plataforma da escola; onde o briefing diz
> "na escola / 1º dia", vale "na plataforma da escola". Onde as seções 6.1, 9, 10, 12 e 18
> disserem diferente, vale esta nota.

---

## 0. Prompt para colar em outra IA

Copie o bloco abaixo + o restante deste arquivo.

```
Você vai trabalhar na matrícula em cursos presenciais da Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro.

Leia o briefing completo abaixo como fonte da verdade. Não invente turmas com data. Não peça cadastro na escola antes do pagamento. Não construa o ambiente de horários/secretaria neste projeto — isso vive na plataforma da escola.

Este projeto (página em cruzvermelhariodejaneiro.org/matricula-cursos-presenciais, dentro do domínio institucional) só faz:
1) mostrar os cursos
2) cobrar a INSCRIÇÃO de R$ 99 via Unicopag (PIX ou cartão, à vista)
3) esperar o pagamento
4) se pago: chamar a API da escola para criar a matrícula, receber o acesso, mostrar na tela Parabéns e disparar e-mail
5) redirecionar o aluno para o ambiente da escola/secretaria

Se não pago: ficar na tela de aguardando. Sem conta. Sem login.

Visual: cabeçalho, rodapé, cores, logo e tipografia idênticos ao site da filial (seção 10). Nada de cara de infoproduto.

Reembolso automático Unicopag existe, mas o botão de estorno NÃO fica neste site — fica no ambiente da escola, enquanto a aula ainda não foi marcada.

Siga as regras, estados, páginas e o que está FORA DE ESCOPO. Se algo não estiver decidido, liste como pergunta; não chute curso extra ou prazo de estorno.
```

---

## 1. O que é este projeto (uma frase)

Uma página permanente no site da filial que funciona como **caixa de inscrição**: o lead escolhe o curso, paga só a inscrição (R$ 99), e só então a escola cria a matrícula e devolve o acesso.

Não é landing de campanha. Não substitui o site da escola. É um **circuito paralelo, mais curto, para leads que já decidiram**.

---

## 2. Contexto da organização

- **Instituição:** Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro (CVB-RJ / CVBRJ).
- **Escola:** Escola de Educação e Saúde CVB-RJ.
- **Cursos:** presenciais, na sede, com certificado da filial. Professores disponíveis **todos os dias**. A operação quer volume: a engrenagem deve girar mesmo assumindo risco de reclamação, desde que o caminho de reembolso seja absurdamente simples **no outro ambiente**.
- **Endereço:** Praça da Cruz Vermelha, 10 — Centro — Rio de Janeiro/RJ — CEP 20230-130.
- **WhatsApp da secretaria (atual):** (21) 99992-2864.
- **E-mail institucional visível:** contato@cruzvermelhariodejaneiro.org.
- **CNPJ (rodapé da vitrine):** 08.560.973/0001-97.

### Dois sites (não misturar)

| Papel | URL | O que faz hoje |
|---|---|---|
| Casa institucional / vitrine | https://cruzvermelhariodejaneiro.org | Marca, notícias, cursos, WhatsApp. HTML estático na Hostinger. Página atual: https://cruzvermelhariodejaneiro.org/cursos.html |
| Plataforma da escola (LMS / matrícula oficial) | https://escola.cursoscruzvermelha.org | Conta, turma, inscrição, pagamento do curso + matrícula, secretaria. App Node no Render, domínio separado de propósito (resiliência) |

**A matrícula em cursos presenciais vive no domínio institucional, como caminho, não como subdomínio:**

`https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais`

Atalho de anúncio (mesmo template, curso já aberto):

`https://cruzvermelhariodejaneiro.org/matricula-cursos-presenciais?curso=puncao-venosa`

Já existe `cruzvermelhariodejaneiro.org/escola` redirecionando para a plataforma da escola; a
matrícula em cursos presenciais é outra coisa e não redireciona para lugar nenhum antes do pagamento.

A plataforma da escola **não é copiada**. Ela só entra **depois do pagamento aprovado**, via API.

---

## 3. Problema que estamos resolvendo

O caminho oficial da escola tem fricção demais para lead que já quer pagar:

1. Entra no site da escola
2. Abre o curso
3. **Cria conta** (CPF/CNPJ/passaporte; senha inicial = últimos 4 dígitos do documento)
4. **Escolhe turma/data**
5. Só então vê o checkout, com **curso + matrícula separados** (a matrícula é R$ 100 à parte na escola)
6. Datas na vitrine ficam desatualizadas e geram suporte

Isso mata conversão de "lead inteligente" (anúncio, bio, WhatsApp, indicação). O comercial hoje ainda manda WhatsApp (`cursos.html` empurra "Fazer matrícula pelo WhatsApp").

**Objetivo da matrícula em cursos presenciais:** reduzir a decisão a uma só: *pagar a inscrição deste curso*.

---

## 4. Princípios (não negociáveis)

1. **Sem data de turma neste site.** Datas desatualizam. Secretaria/escola encaixa depois.
2. **Sem criar conta na escola antes de pagar.** Conta nasce só no webhook `paid`, via API.
3. **Este site não é a secretaria.** Não tem grade de horários, não tem botão de estorno, não tem material de aula.
4. **Cobra a INSCRIÇÃO de R$ 99, não o curso.** Valor do curso aparece como informação; o botão cobra só a inscrição. Os R$ 99 quitam a inscrição que a escola cobra como R$ 100 (alinhado com a filial; a secretaria não cobra diferença).
5. **Dois finais só:** aguardando pagamento **ou** parabéns + acesso devolvido pela API + e-mail.
6. **Cara institucional da filial**, não cara de infoproduto, não cara da plataforma da escola. Mesmo cabeçalho, rodapé, cores, logo e fonte do site (seção 10).
7. **Um template, N cursos.** Não criar 9 landings diferentes.
8. **Aceitar reclamação como custo de volume**, desde que o estorno no *outro* ambiente seja 1 clique enquanto a aula não foi marcada.
9. **Unicopag isolada** deste circuito (não misturar com o caixa interno da escola), para estorno via API sem financeiro manual.
10. **Prometer só o que o sistema cumpre.** Nunca escrever "estorno instantâneo". PIX vs cartão têm prazos diferentes. Enquanto a API da escola não existir, a tela Parabéns não promete login na hora (ver 6.4).
11. **Tudo que o aluno vê acontece em `cruzvermelhariodejaneiro.org`.** Chamadas de API podem ir a outro host, mas página, pixel e analytics ficam no domínio.

---

## 5. Circuito do aluno (fonte da verdade)

```
[Anúncio / WhatsApp / bio / cursos.html / home]
        ↓
[/matricula-cursos-presenciais]  escolhe curso (texto curto + vídeo/imagem + botão)
        ↓
[Checkout Unicopag, R$ 99]  nome, CPF, e-mail, WhatsApp
        ↓
        ├── NÃO PAGO  → tela "Aguardando pagamento"  (PIX pendente, expirado, cartão recusado)
        │                 sem conta, sem login, sem API da escola
        │                 pode tentar de novo
        │
        └── PAGO (webhook Unicopag status=paid, confirmado por reconsulta à API da Unicopag)
                  ↓
              API da ESCOLA: criar matrícula com os dados do aluno + curso
                  ↓
              Escola devolve: usuario, acesso (senha temporária ou link de acesso único), url_ambiente
                  ↓
              Tela PARABÉNS (mostra os dados) + e-mail com os mesmos dados
                  ↓
              Aluno clica e vai para o AMBIENTE DA ESCOLA / SECRETARIA
                  ↓
              Lá (FORA DESTE PROJETO): escolhe horário/turma
                  ou pede estorno em 1 clique se ainda não encaixou
```

### Estados da inscrição neste circuito

Só estes. Qualquer outro vira caso especial e quebra autonomia.

| Estado | Onde vive | O que o aluno vê neste site |
|---|---|---|
| `checkout_iniciado` | matrícula em cursos presenciais | formulário / Unicopag |
| `aguardando_pagamento` | matrícula em cursos presenciais | tela pendente |
| `pago` | matrícula em cursos presenciais + API escola | tela Parabéns + e-mail |
| `reembolsado` | Unicopag + escola | **não é tela deste site** |
| `encaixado` / cursando | escola | **não é tela deste site** |

Conta do aluno **não existe** em `aguardando_pagamento`.

---

## 6. Páginas deste projeto (somente estas)

### 6.1 `/matricula-cursos-presenciais` — catálogo + detalhe

Layout alinhado com o pedido original:

- **Esquerda (ou lista no mobile):** todos os cursos, agrupados (não 9 abas cruas).
- **Direita:** ao selecionar um curso, abre um bloco com:
  - título
  - 1 parágrafo curto
  - imagem **ou** vídeo curto (60–90s de apresentação, não aula)
  - chips: carga horária, escolaridade, "Presencial · Centro do Rio"
  - preço da **inscrição agora: R$ 99**
  - valor do curso como informação secundária
  - botão único: **Fazer matrícula**
- **Topo:** uma regra só, igual para todos os cursos:

> Pague a inscrição de R$ 99 agora. A secretaria confirma horário depois.
> Enquanto a aula não for marcada, o estorno é simples no ambiente da escola.

**Não mostrar:** calendário, "próxima turma 24/09", countdown, escolha de horário, criar conta.

**Query string:** `?curso=slug` já deixa aquele curso selecionado (para anúncio e comercial). UTMs, `fbclid` e `gclid` seguem para o checkout.

### 6.2 Checkout da inscrição

Mesmo domínio (`/matricula-cursos-presenciais/checkout`). Unicopag (PIX e cartão, **à vista**).

Campos mínimos:

- nome completo
- CPF
- e-mail (a Unicopag exige `customer.email`)
- WhatsApp

Metadata da transação Unicopag (obrigatório):

- `curso_id` / slug
- `cpf`
- `email`
- `nome`
- `whatsapp`
- origem (`utm_source`, `utm_campaign`)

O botão cobra **somente a inscrição: R$ 99 (9900 centavos)**. Título do item no carrinho da Unicopag: `<Nome do curso> — Inscrição`, para identificar a cobrança no painel e no comprovante do PIX.

### 6.3 Tela Aguardando pagamento

`/matricula-cursos-presenciais/pendente`. Quando o checkout foi gerado mas ainda não há `paid`.

Copy essencial:

- "Pagamento ainda não confirmado"
- Curso e valor (R$ 99)
- "Esta tela não cria login. Quando o pagamento for aprovado, a matrícula é aberta na escola e os dados de acesso aparecem aqui e no seu e-mail."
- Ações: copiar/abrir PIX, gerar novamente se expirou, tentar cartão de novo

**Se o aluno fechar a aba:** o webhook `paid` ainda cria a matrícula e o e-mail chega. A tela Parabéns não pode depender de a aba ficar aberta.

### 6.4 Tela Parabéns (só depois de `paid` + resposta da API da escola)

`/matricula-cursos-presenciais/parabens`. Três blocos, sem teatro:

1. Inscrição paga: [nome do curso] · R$ 99
2. Acesso devolvido pela escola: usuário + acesso + botão **Acessar ambiente da secretaria**
3. "Mandamos o mesmo acesso para [e-mail]"

Regras:

- `/parabens` **não** pode carregar senha na query string. A tela é aberta por token de sessão do pagamento.
- Preferir **link de acesso único** (expira, uso único) a senha temporária. Se a escola só devolver senha, ela aparece na tela na hora e no e-mail e o primeiro login obriga a trocar. Depois, só "esqueci senha" na escola.
- Se a API da escola falhar depois do pagamento, a tela diz "pagamento confirmado, acesso em instantes no e-mail", nunca silêncio. Precisa de retry da API com registro de cada tentativa.
- **Enquanto a API da escola não existir** (fase de lançamento), a tela Parabéns tem a versão B: "Inscrição paga. A secretaria da Escola entra em contato pelo WhatsApp em até 2 dias úteis para fechar turma e data. Você não precisa se inscrever de novo na plataforma." O e-mail espelha o mesmo texto. Trocar para a versão A (acesso) quando a API entrar.

### 6.5 E-mail (espelho da tela Parabéns)

Assunto: `Acesso à secretaria — [nome do curso]` (versão A) ou `Inscrição confirmada — [nome do curso]` (versão B).

Corpo: parabéns, curso, valor pago, usuário/acesso (A) ou aviso do contato da secretaria (B), link do ambiente da escola, uma linha: horário se resolve lá; estorno simples enquanto a aula não for marcada. Remetente em domínio verificado: `matricula@cruzvermelhariodejaneiro.org` (Resend, já usado no ecossistema).

---

## 7. O que NÃO entra neste site (ambiente da escola)

Depois do redirect, 100% responsabilidade da plataforma `escola.cursoscruzvermelha.org` (ou o ambiente que a API devolver):

- login com os dados que a API devolveu
- escolha de dias/horários (slots reais que a operação abre)
- professores/agenda do dia
- botão **Quero o dinheiro de volta** (1 clique, 1 confirmação) **somente enquanto status ≠ encaixado**
- após `encaixado`, some o estorno em 1 clique; vira regra da escola (remarcar, falta, no-show)
- certificado, material, histórico

### Política de estorno (para a outra IA da escola / Unicopag)

Estorno automático Unicopag, **fora da estrutura financeira principal da escola**, nos casos fechados:

- aluno pediu cancelamento **antes** de confirmar turma
- ficou X dias em `pago` sem escolher horário (X ainda não definido — ver perguntas abertas)
- slot clicado acabou no mesmo segundo
- nenhum horário compatível

**Não** estornar automático depois de `encaixado`.

Texto visível (matrícula em cursos presenciais + e-mail + escola), sempre igual:

> A inscrição reserva sua vaga. Se não houver horário compatível ou você desistir antes da confirmação da aula, o valor é estornado automaticamente. O prazo para aparecer na conta depende de PIX ou cartão.

Não escrever "na hora" / "instantâneo".

**Não** colocar o botão de estorno na página `/matricula-cursos-presenciais`. Senão o lead nem entra no pool.

Atenção: o cliente Unicopag já escrito (`lib/unicopag.ts` do projeto da Punção Venosa) **não tem função de estorno** e a documentação pública não mostra o endpoint. Confirmar com a Unicopag se existe estorno por API antes de prometer "automático".

---

## 8. Integrações

### 8.1 Unicopag (pagamento desta página)

- Gateway: Unicopag (`https://api.cloud.unicopag.com.br`, autenticação `api_token` em query string, valores em centavos). Criação: `POST /public/v1/payments`; consulta: `GET /public/v1/transactions/{hash}`.
- Métodos: PIX e cartão, à vista.
- Webhook `postback_url` em toda transação. **O corpo do postback não é fonte da verdade** (não há assinatura documentada): usar o `hash` para reconsultar a transação e decidir pela resposta da API.
- Status relevantes: `pending`, `processing`, `paid`, `refused`, `failed`, `cancelled`, `refunded`, `chargeback`.
- **Este projeto reage principalmente a `paid`.** Estorno é disparado pelo ambiente da escola, não por esta página.
- Checkout Unicopag isolado do caixa da escola, para o estorno não virar lançamento manual.
- PCI: a API recebe o número do cartão em claro. O dado passa pelo servidor e nunca é gravado, logado ou devolvido; guarda-se só bandeira e últimos 4 dígitos.

Contrato mínimo ao criar a cobrança:

- amount = 9900
- customer = nome, e-mail, CPF, telefone
- cart = 1 item `"<Curso> — Inscrição"`, 9900
- metadata = curso + identificadores + origem
- postback_url = endpoint do backend desta página

### 8.2 API da escola (criar matrícula e devolver acesso)

Disparada **somente** quando a Unicopag confirmar `paid`.

Pedido (proposta — a escola ainda precisa fechar o contrato real):

```json
POST /api/matriculas/rapida
{
  "curso_id": "puncao-venosa",
  "transacao_unicopag": "hash...",
  "valor_inscricao_centavos": 9900,
  "aluno": {
    "nome": "Maria Silva",
    "cpf": "00000000000",
    "email": "maria@email.com",
    "whatsapp": "21999999999"
  }
}
```

Resposta esperada:

```json
{
  "ok": true,
  "matricula_id": "...",
  "usuario": "maria@email.com",
  "acesso": { "tipo": "link_unico", "url": "https://escola.cursoscruzvermelha.org/acesso/…", "expira_em": "..." },
  "url_ambiente": "https://escola.cursoscruzvermelha.org/..."
}
```

Regras:

- Idempotência: se o mesmo `transacao_unicopag` chegar duas vezes, devolver o mesmo acesso, não criar duas matrículas.
- Não criar usuário na escola em pagamento pendente.
- Senha **não** deve ser os 4 últimos dígitos do CPF (padrão ruim do cadastro atual da escola). Preferir link de acesso único.
- Autenticação servidor a servidor (token secreto em cabeçalho), nunca a partir do navegador.

---

## 9. Cursos

A filial comunicou **9 cursos**. Fontes públicas hoje não batem 9.

**Na plataforma da escola (set/2026), 7 cursos visíveis:**

| Curso | Slug | Carga (site escola) | Escolaridade | Inscrição (matrícula em cursos presenciais) | Inscrição (escola) | Curso à vista (escola) |
|---|---|---|---|---|---|---|
| Bombeiro Civil | `bombeiro-civil` | 80h | Ensino Médio | **R$ 99** | R$ 100 | R$ 950 |
| Cuidador de Idosos (curso livre) | `cuidador-de-idosos` | 160h | Ens. Fundamental | **R$ 99** | R$ 100 | R$ 950 |
| Micropigmentação Labial | `micropigmentacao-labial` | 24h | Ensino Médio | **R$ 99** | R$ 100 | R$ 400 |
| Primeiros Socorros Básico | `primeiros-socorros-basico` | 8h (vitrine diz 4h — divergência) | Ens. Fundamental | **R$ 99** | R$ 100 | R$ 180 na escola / R$ 150 na vitrine |
| Primeiros Socorros Lei Lucas | `primeiros-socorros-lei-lucas` | 8h | Ens. Fundamental | **R$ 99** | R$ 100 | R$ 150 |
| Punção Venosa | `puncao-venosa` | 8h | Ensino Médio | **R$ 99** | R$ 100 | R$ 150 |
| Suporte Básico de Vida | `suporte-basico-de-vida` | 4h | Ens. Fundamental | **R$ 99** | R$ 100 | R$ 150 |

IDs dos cursos na escola (para `escolaUrl` e para o `curso_id` da API): ver `escola/sitemap.xml` neste repositório.

**Na vitrine institucional `cursos.html` (lista promocional):** 6 cursos (não lista SBV). Diz "inscrição R$ 100 à parte" e 1 kg de alimento não perecível entre os requisitos. A linha de R$ 100 precisa ser corrigida para R$ 99 quando a matrícula em cursos presenciais entrar no ar.

**Placeholders usados no protótipo para chegar a 9** (NÃO tratar como oficiais até a filial cravar):

- Atualização para Técnicos de Enfermagem
- Gastrostomia e Traqueostomia

Esses dois apareceram em comunicação antiga de redes da CVB-RJ. Entram no catálogo com `ativo = false` até a escola publicá-los.

### Agrupamento recomendado na UI (não 9 abas)

1. **Emergência e vida** — Primeiros Socorros, Lei Lucas, SBV, Punção Venosa
2. **Formação profissional** — Bombeiro Civil, Cuidador de Idosos, (+ técnicos / gastro se confirmados)
3. **Outros** — Micropigmentação Labial

Um curso aberto por padrão (o da campanha, via `?curso=`).

### Preço na UI

Sempre duas linhas, mesma lógica em todos:

- **Inscrição agora:** R$ 99 (paga neste checkout)
- **Valor do curso:** R$ X (pago depois, na escola / 1º dia — a filial já opera inscrição à parte)

O valor do curso é informação de referência e vem do catálogo; a fonte é a página do curso na escola. Não misturar modelo "às vezes cobra total". A matrícula em cursos presenciais é da inscrição.

---

## 10. Visual e marca (padrão real da filial, não aproximação)

A referência é o **site institucional em produção**, arquivo `site/index.html` deste repositório (home). A matrícula em cursos presenciais deve parecer uma página da home, não um app.

### Tokens (copiar do `:root` de `site/index.html`)

```css
:root {
  --red: #cc0000;        /* botões, destaques */
  --red-dark: #a30000;   /* hover */
  --black: #0f1318;      /* títulos, faixa preta */
  --text: #1a202c;
  --muted: #718096;
  --line: #e2e8f0;
  --soft: #f7f8fa;       /* fundos de seção */
  --paper: #ffffff;
  --max: 1100px;
  --radius: 16px;
  --shadow: 0 12px 28px rgba(16, 24, 40, .10);
}
```

Observação: `cursos.html` usa uma paleta mais antiga (`--red #ed1b2e`, `--radius 8px`, `--max 1180px`). A nova página segue a **home**; a unificação da vitrine fica para depois. O vermelho do emblema (favicon) é `#ed1b2e` e não deve ser alterado no logo.

### Tipografia e ícones

- **Inter** (Google Fonts, pesos 400–900), como em toda a home. Nada de fonte de infoproduto.
- Ícones **Font Awesome 6.4.0** (cdnjs), os mesmos da home.

### Logo e emblema

- Arquivos oficiais já publicados: `assets/logo-cvb-rj.png` (horizontal, 1730×520, cabeçalho e rodapé), `assets/logo-rj.png` (quadrado, 750×750, marca compacta), `assets/favicon.svg` e `assets/favicon.png`.
- Usar os arquivos como estão: sem recolorir, sem gradiente, sem inclinar, sem sombra, com área de respiro. O emblema da cruz vermelha tem uso regulado pelas Convenções de Genebra e pela legislação brasileira; só a filial pode aplicá-lo, e só na forma oficial.
- Não desenhar uma "cruz" própria em CSS no lugar do logo.

### Cabeçalho (copiar de `site/index.html`, `header.main-header`)

- Logo horizontal à esquerda; menu: Início, Notícias, Cursos, Campanhas, Parceiros, FAQ, Equipe, Contato; botão "Plataforma" (escola) à direita; menu sanfona no celular.
- Na matrícula em cursos presenciais, o item ativo é **Cursos**; acrescentar "Matrícula cursos presenciais" só se a home também ganhar esse item.
- A faixa preta superior "CRUZ VERMELHA BRASILEIRA - FILIAL DO ESTADO DO RIO DE JANEIRO", com e-mail e redes, existe em `cursos.html` (`.topbar`). Pode ser usada, desde que a home também a adote; não inventar uma terceira variação.

### Rodapé (copiar de `site/index.html`, `footer`)

- Logo horizontal + a linha dos princípios: "Humanidade, imparcialidade, neutralidade, independência, voluntariado, unidade e universalidade."
- Colunas: Sobre (Cruz Vermelha Brasileira / Filial Rio de Janeiro); Contato (Praça Cruz Vermelha, 10; (21) 99992-2864; contato@cruzvermelhariodejaneiro.org); Siga-nos (Facebook, Instagram; LinkedIn e TikTok só quando houver perfil real).
- Barra inferior: © 2026 Cruz Vermelha Brasileira do Rio de Janeiro | Notícias | Escola de Educação e Saúde | Política de Privacidade | Termos de Uso; CNPJ 08.560.973/0001-97.
- Botão flutuante de WhatsApp (`.wpp-float`), como na home.

### Componentes

- Botões `.btn .btn-red` (primário), `.btn-outline` (secundário), `.btn-white` (sobre fundo escuro); `.cta-row`; `.eyebrow`; `.lead`; cards brancos com `--radius` e `--shadow`; fundo `--soft` nas seções alternadas.
- Sem countdown, sem selo "última vaga", sem depoimento inventado, sem gradiente.

### Referências

- https://cruzvermelhariodejaneiro.org (fonte do padrão)
- https://cruzvermelhariodejaneiro.org/cursos.html (vitrine, paleta antiga)
- https://escola.cursoscruzvermelha.org (só para não copiar o fluxo; pode inspirar cards de curso)

---

## 11. Protótipo já existente

Foi iniciado um HTML navegável (catálogo + detalhe + checkout mock + aguardando + parabéns):

- Arquivo: `artifacts/matricula-cvb/index.html` (fora deste repositório).
- É **protótipo**: botão "Simular pagamento aprovado" substitui o webhook Unicopag.
- Login/senha na tela Parabéns são **fake**, no lugar da resposta da API da escola.
- Vídeo é placeholder (imagem Unsplash + play).
- Dois cursos extras são placeholder.
- **Está fora do padrão visual da filial** (logo, cores, cabeçalho). Refazer em cima de `site/index.html`, na pasta `site/matricula-cursos-presenciais/` deste repositório, antes de qualquer validação com a equipe.

Qualquer implementação nova deve partir deste circuito, não do funil da escola.

Já existe um experimento anterior de um curso só (Punção Venosa) em estilo "paga matrícula, agenda depois": `https://puncaovenosav1.cruzvermelhariodejaneiro.org/` (código em `matheusmacedo-create/puncaovenosa-fullautomatic`). Validou o modelo pagar-primeiro e já tem a integração Unicopag, o webhook, a planilha da secretaria e os e-mails prontos. A matrícula em cursos presenciais é a versão **catálogo** disso, no domínio oficial (ver seção 23).

---

## 12. Relação com as páginas atuais

| Página atual | Destino depois da matrícula em cursos presenciais |
|---|---|
| Home | bloco "Já escolheu seu curso?" com seletor de curso apontando para `/matricula-cursos-presenciais?curso=…` |
| `/cursos.html` (vitrine + WhatsApp) | pode continuar; CTA principal de lead quente aponta para `/matricula-cursos-presenciais?curso=…`; corrigir "Inscrição: R$ 100,00" para R$ 99 |
| `escola.cursoscruzvermelha.org` | continua para quem quer comparar turma/FAQ longo, e para **depois** do pagamento |
| WhatsApp da secretaria | vira escape (dúvida), não o caixa principal |

Não esconder a escola. Só não forçar o lead quente a atravessar cadastro + turma.

---

## 13. Fora de escopo (V1 desta página)

- Escolha de horário
- Painel de professor
- Chat
- Troca de curso no mesmo PIX (regra: estorna e paga o outro)
- Parcelamento da inscrição (estorno de parcela quebra o "quase autônomo")
- Criar conta na escola antes do `paid`
- 9 checkouts diferentes
- Certificado, aula, material
- Countdown / data de turma
- Cadastro CNPJ/estrangeiro (V1 = pessoa física + CPF; a Unicopag exige o documento). Se precisar PJ, é V2.
- Mostrar botão de estorno neste domínio
- Subdomínio próprio para a página (decisão: caminho dentro do domínio)

---

## 14. Métricas que a operação quer acompanhar

Porque o modelo aceita reclamação em troca de volume:

- taxa de estorno / inscritos pagos
- tempo médio `pago` → `encaixado` (isso é da escola; a matrícula em cursos presenciais precisa receber esse evento da escola)
- abandono em `aguardando_pagamento`
- estorno por curso (se um curso estorna 40%, o anúncio ou o texto está vendendo errado)
- PIX aprovado vs cartão recusado
- origem (UTM) → pago, por curso

Por estar no mesmo domínio, GA4 (`G-HDYZZ5JZHF`) e Meta Pixel (`2224500131617302`) da home rastreiam a página sem configuração de domínios cruzados. Eventos padrão: `ViewContent` (curso aberto), `InitiateCheckout` (botão), `Lead` (dados), `AddPaymentInfo` (PIX gerado), `Purchase` (pago, R$ 99), com deduplicação servidor/navegador por `event_id`.

Não fechar o botão de estorno se a taxa subir: ajustar a promessa ou os slots que a operação abre.

---

## 15. Requisitos operacionais que a página deve respeitar (sem burocratizar)

A vitrine atual pede, no ato da matrícula tradicional:

- Ensino Fundamental completo (varia por curso)
- RG, CPF, comprovante de residência
- 1 kg de alimento não perecível

**Decisão de produto (protótipo atual):** a matrícula em cursos presenciais **não** coleta documento nem comprovante. Coleta nome/CPF/e-mail/WhatsApp e um checkbox de requisito por curso ("Li os requisitos do curso e confirmo que os atendo", com link). O resto a secretaria pede no outro ambiente (ou no primeiro dia). O 1 kg de alimento fica como aviso, não como barreira, salvo decisão contrária da filial.

Homologação do Bombeiro Civil, se existir, **continua à parte**. Não misturar no valor da inscrição.

---

## 16. Mapa de URLs

Domínio: `cruzvermelhariodejaneiro.org` (páginas na Hostinger, pasta `public_html/matricula-cursos-presenciais/`).

| URL | Tela |
|---|---|
| `/matricula-cursos-presenciais/` | catálogo + detalhe |
| `/matricula-cursos-presenciais/?curso=slug` | mesmo, curso pré-selecionado |
| `/matricula-cursos-presenciais/checkout/` | dados do aluno + pagamento Unicopag (PIX ou cartão) |
| `/matricula-cursos-presenciais/pendente/` | não pago |
| `/matricula-cursos-presenciais/parabens/` | pago + acesso (aberta por token de sessão do pagamento; nunca senha na URL) |

Redirect final: `url_ambiente` devolvida pela API da escola.

A pasta `matricula-cursos-presenciais/` não é tocada pela Redação (que só escreve em `noticias/`, `termos/`, `privacidade/`, `sitemap.xml`, `robots.txt` e `index.html`). Deve entrar no sitemap do site.

---

## 17. Stack (decisão)

O site institucional é HTML estático em hospedagem compartilhada Hostinger (LiteSpeed; PHP e MySQL disponíveis, sem Node). O que importa:

1. **Páginas públicas rápidas no mesmo domínio** (confiança no PIX, rastreamento em primeira parte): HTML/CSS/JS estáticos em `site/matricula-cursos-presenciais/`, publicados como o resto do site (`scripts/publicar_hostinger.sh`).
2. **Backend mínimo**: criar cobrança Unicopag, receber webhook, chamar API da escola, mandar e-mail, registrar tudo. Fica na API já existente do projeto da Punção Venosa (Vercel), generalizada para vários cursos, exposta em `api.cruzvermelhariodejaneiro.org` com CORS restrito a `https://cruzvermelhariodejaneiro.org`. O aluno nunca navega para esse host; ele só recebe chamadas `fetch` das páginas. Sessão do pagamento por cookie `httpOnly` com `Domain=.cruzvermelhariodejaneiro.org` (mesmo site) ou por token opaco devolvido ao criar a cobrança.
3. Nenhuma lógica de turma neste backend.

Alternativa se a equipe preferir uma stack só na Hostinger: reescrever o backend em PHP + MySQL (a mesma base do "CVB Links" que já roda lá). Custa refazer a integração Unicopag, o webhook idempotente, os retries e os e-mails que hoje já existem e estão testados; só vale se a dependência da Vercel for inaceitável.

---

## 18. Copy canônica (usar igual em todo lugar)

**Hero:** Escolha o curso e pague a inscrição.

**Regra:** Sem criar conta antes. Sem escolher turma aqui. Depois do pagamento você recebe o acesso da secretaria no e-mail e nesta tela.

**Botão do curso:** Fazer matrícula

**Botão do checkout:** Pagar inscrição · R$ 99

**Pendente:** Pagamento ainda não confirmado.

**Parabéns (A, com API):** Parabéns, sua inscrição está paga. Aqui está seu acesso à secretaria.

**Parabéns (B, sem API):** Parabéns, sua inscrição está paga. A secretaria entra em contato pelo WhatsApp em até 2 dias úteis.

**Botão pós-pago:** Acessar ambiente da secretaria

**Estorno (só na escola):** Quero o dinheiro de volta

---

## 19. Perguntas ainda abertas (não chutar)

Já respondidas e retiradas da lista: valor da inscrição (R$ 99 em todos); provedor de e-mail (Resend, domínio verificado); onde a página vive (`/matricula-cursos-presenciais` no domínio, Hostinger); V1 só CPF (a Unicopag exige documento).

1. Lista fechada dos **9 nomes oficiais** e slugs (hoje 7 publicados na escola).
2. O valor do curso é cobrado na confirmação da turma, no primeiro dia, ou ainda na escola por outro checkout?
3. Contrato real da API da escola (path, auth, campos, idempotência) e **quem a constrói e quando**. Enquanto não existir, lançamos com a tela Parabéns versão B.
4. Prazo em dias para estorno automático se o aluno não escolher horário.
5. A Unicopag tem endpoint de estorno por API? (o cliente atual não tem; confirmar internamente na Unicopag).
6. O 1 kg de alimento entra neste fluxo? Só aviso ou barreira?
7. Vídeos reais: URL de cada curso ou a V1 sai com imagem?
8. Requisito por curso para o checkbox (Fundamental × Médio) e carga horária de Primeiros Socorros Básico (4h ou 8h).
9. A filial confirma por escrito que os R$ 99 quitam a inscrição de R$ 100 da escola?

---

## 20. Definição de pronto (V1)

A V1 da matrícula em cursos presenciais está pronta quando:

- [ ] `/matricula-cursos-presenciais/` no domínio da filial lista os cursos oficiais, com o cabeçalho e o rodapé da home
- [ ] selecionar curso mostra texto + mídia + botão de inscrição
- [ ] checkout Unicopag cobra só a inscrição (R$ 99)
- [ ] pendente não cria usuário
- [ ] `paid` (confirmado por reconsulta) chama a API da escola, recebe o acesso, mostra Parabéns, envia e-mail; sem API, mostra a versão B e avisa a secretaria (planilha + painel)
- [ ] botão leva ao `url_ambiente`
- [ ] zero data de turma na página
- [ ] falha da API da escola depois do PIX tem retry + mensagem humana
- [ ] webhook idempotente
- [ ] GA4 e Pixel da home registram a página e o `Purchase`

A V1 da **escola** (outro projeto) está pronta quando:

- [ ] aluno entra com o acesso devolvido
- [ ] vê slots ou "estamos encaixando"
- [ ] estorno 1 clique antes de `encaixado`
- [ ] Unicopag recebe o refund
- [ ] escola não cria aluno duplicado

---

## 21. O que a outra IA NÃO deve fazer

- Recriar o cadastro + escolha de turma da escola neste domínio.
- Colocar 9 abas horizontais no mobile como navegação principal.
- Prometer data de início.
- Cobrar curso + inscrição no mesmo botão "de surpresa".
- Usar senha = 4 dígitos do CPF.
- Tratar o HTML de protótipo como produção (não tem gateway real).
- Inventar os 2 cursos que faltam como se fossem oficiais.
- Colocar estorno neste site.
- Falar em nome da Cruz Vermelha com copy de infoproduto ("última vaga relâmpago", countdown falso, depoimento fake).
- Mover a página para um subdomínio ou outro domínio.
- Trocar cores, logo ou fonte da filial por "algo parecido".

---

## 22. Resumo para humano (30 segundos)

O site grande da escola é burocrático. Vamos ter uma página em cruzvermelhariodejaneiro.org/matricula-cursos-presenciais: escolhe curso, paga inscrição de R$ 99 na Unicopag, espera. Se pagou, a API da escola cria a matrícula e manda o acesso (enquanto a API não existe, a secretaria fecha pelo WhatsApp em até 2 dias úteis). Horário e estorno são lá. Sem data nesta página. Mesmo domínio, mesmo visual e mesmo rastreamento do site da filial. Professores estão todos os dias; o gargalo é a entrada, não a turma.

---

## 23. Alinhamento com a implementação (18/09)

Este briefing é a **definição do produto**. O plano de implementação de 16/09
(`docs/plano-matricula-express.md`) continua válido para o backend, com estas mudanças:

1. **Sem subdomínio para o aluno.** O plano previa `matricula.cruzvermelhariodejaneiro.org`;
   agora as páginas ficam em `cruzvermelhariodejaneiro.org/matricula-cursos-presenciais/` (estáticas, na
   Hostinger) e o backend existente da Punção Venosa vira API (`api.cruzvermelhariodejaneiro.org`)
   com CORS restrito ao domínio. O que já está pronto e é reaproveitado: cliente Unicopag,
   reaproveitamento de PIX pendente, webhook com reconsulta, e-mails Resend com log,
   planilha da secretaria, painel `/secretaria`, eventos Meta com CAPI.
2. **Pós-pagamento.** O plano entregava o aluno à secretaria via planilha e WhatsApp. O briefing
   adiciona a API da escola para devolver o acesso. Como a API não existe, o lançamento usa a
   tela Parabéns versão B (planilha + painel + WhatsApp da secretaria) e a versão A entra quando
   a escola expuser a API. Novo registro de entregas `escola_entregas` (tentativas, retry,
   reenvio manual pelo painel), no mesmo padrão de `webhook_entregas` e `email_entregas`.
3. **Sem triagem.** As 8 perguntas ficam só no funil da Punção Venosa; a matrícula em cursos presenciais termina
   em `pago`.
4. **Páginas.** Catálogo com agrupamento e detalhe, checkout, pendente e parabéns, todas com o
   cabeçalho e o rodapé da home. Nada de gaveta em iframe (cookie e `X-Frame-Options` impedem).
5. **Home e vitrine.** Bloco na home e botões em `cursos.html` apontam para
   `/matricula-cursos-presenciais?curso=…`; corrigir a linha "Inscrição: R$ 100,00".
6. **Sitemap.** `/matricula-cursos-presenciais/` entra no sitemap do site; as páginas de pendente e parabéns
   levam `noindex`.
