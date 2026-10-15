# Mapa do que falta: páginas institucionais, leis e princípios da CVB-RJ

Levantamento de 11/10/2026, feito só com leitura: nada foi alterado nem publicado no site. Revisado no mesmo dia com as correções do crítico.

> **Decisões do Matheus depois do levantamento (11/10/2026)** — valem sobre o resto do documento:
> - **Financiamento fica para uma segunda etapa, no portal da transparência** que já existe (`/transparencia/`). Nada de página própria de financiamento agora; quando for feito, o `ownershipFundingInfo` e os links "sem fins lucrativos" apontam para lá. Contexto registrado: hoje todo o resultado da escola vai para manter a infraestrutura do Palácio. O vínculo societário e a relação entre partes ligadas (seção 3b) entram nessa mesma etapa.
> - **Separação ONG × escola:** pode reforçar o CNPJ da escola onde ajudar a explicar, sempre com a frase "empresa ligada à filial, com sistemas independentes; a escola é uma das fontes de financiamento da instituição".
> - **Cursos gratuitos ("livres", no nome interno):** primeiros socorros e voluntariado; exemplo: a formação da Força-Tarefa El Niño. Ainda sem página.
> - **Ad Grants, 15/10/2026:** o pedido foi recusado (mensagem genérica; o cadastro no Google para ONGs está verificado). Correções no site: missão em destaque com fonte (art. 10 do Estatuto), venda fora do topo da home, "Como a filial se financia" em /quem-somos/, links de doação (503) tirados das páginas em inglês, Dia das Crianças encerrado, favicon.ico. Detalhes no README, seção "Google Ad Grants". Pendente: documentos na transparência (o Matheus está subindo), reenvio do pedido.
> - **Correção de 11/10/2026, à noite (vale sobre a linha acima):** não há curso gratuito aberto ao público. A formação gratuita é só para voluntários: cadastro, ficha de entrevista (aceite) e o CBFI, que abre 4 vezes por ano; as formações internas, como primeiros socorros, vêm depois. No site usar só a sigla CBFI. `/cursos-gratuitos/` virou "Formação de voluntários" (mesma URL), e nenhum texto pode sugerir turma gratuita aberta, para não concorrer nem confundir com o curso pago da escola. Para 2027, a ficha de entrevista deve dar lugar a algum tipo de verificação (KYC) mais moderna: quando mudar, atualizar `/cursos-gratuitos/`, `/voluntariado/`, a FAQ da home e o chat.
> - **Seção Institucional da home:** as fotos do CICV saíram (desatualizadas); entraram o retrato do presidente Luiz Carlos dos Santos e a foto do gabinete com o vice-presidente Antônio Pedregal (publicado em 11/10/2026, 19h59, com o pacote institucional). O texto da seção será refeito com os links das páginas novas, os cursos gratuitos e o tombamento.
> - **Tombamento:** usar "aprovado por unanimidade pelo Conselho Consultivo do Iphan em 10/11/2021" até aparecer a homologação ministerial (seção 6, item 14).
> - **Nome do vice-presidente:** "Antônio Pedregal", com acento, como ele escreve (já alinhado em `/equipe.html`, `/en/our-team/` e `/es/nuestro-equipo/`, publicado em 11/10/2026).
>
> **Conferido de novo em 11/10/2026, à noite:** o espelho dos dados abertos da Receita (BrasilAPI) mostra o CNPJ 08.560.973/0001-97 como **INAPTA desde 12/05/2026, motivo 63, "omissão de declarações"**; o domínio foi registrado em 28/06/2026 e **vence em 28/06/2027** (RDAP). Ver seção 8, itens 1 e 2.

Como ler as marcas:
- **A CONFIRMAR**: depende de uma resposta ou de um documento do Matheus.
- **não confirmado**: ninguém achou fonte oficial.
- **Google usa**: a documentação do Google cita a propriedade.
- **Sem efeito prometido pelo Google**: a propriedade existe no schema.org, mas o Google não promete nenhum efeito.

**Glossário (uma linha por termo):**
- **JSON-LD**: bloco de código invisível na página que descreve a entidade para buscadores (dados estruturados).
- **sameAs**: lista de perfis e páginas oficiais da mesma entidade em outros sites.
- **hreflang**: aviso ao Google de que a página tem versões em outros idiomas.
- **canonical**: indica qual é o endereço principal de uma página.
- **BreadcrumbList**: a "trilha" Início › Seção › Página, descrita em JSON-LD.
- **Wikidata**: base de dados aberta da Wikimedia; cada entidade tem um código (QID), como Q141635343.
- **Goodstack**: empresa que verifica as ONGs para o Google para ONGs.
- **CNPJ inapto**: situação cadastral na Receita; no caso da filial, por "omissão de declarações".
- **DIH**: Direito Internacional Humanitário, as regras da guerra (Convenções de Genebra).
- **MROSC**: Marco Regulatório das Organizações da Sociedade Civil, a Lei 13.019/2014.
- **Ad Grants**: anúncios gratuitos do Google para ONGs aprovadas no Google para ONGs.

---

## 1. Resumo

As três ações que mais importam:
1. Corrigir o "não há cursos gratuitos" na FAQ da home, no llms.txt, no chat e nas FAQs EN/ES.
2. Criar a página dos cursos gratuitos da filial (primeiros socorros e formação de voluntários), bem separada dos cursos pagos de primeiros socorros da escola.
3. Dizer em todo o site quem vende os cursos pagos: a O-CVB Filial Rio de Janeiro Ensino Ltda, outra pessoa jurídica.

Além disso, o código da home já declara a filial como ONG, mas nenhuma página mostra o que a sustenta como entidade sem fins lucrativos: princípios, emblema, base legal, Movimento e governança (o financiamento fica para a segunda etapa, na transparência). O site também:
- cita como vigente um estatuto revogado;
- só deixa claro que quem vende os cursos é outra empresa nas páginas de compra e nas políticas;
- manda quem digita `/cursos/` direto para a venda e `/voluntariado/` para a página de links (`/bio/`).

---

## 2. O que já temos

| Onde | O que tem |
|---|---|
| Home (`site/index.html`) | Bloco NGO com `@id` `#organizacao`, CNPJ, endereço, contato, Instagram, Facebook e Wikipédia. Uma frase sobre os 7 princípios. As duas utilidades públicas (Lei municipal 5.153/2010 e Lei estadual 9.984/2023). Os nomes dos 7 princípios no rodapé. A seção Institucional diz que a filial atua na formação de voluntários e na capacitação em primeiros socorros, mas não diz que isso é gratuito nem que a filial é "sem fins lucrativos". |
| `/historia/` e `/en/history/` | História longa com leis e decretos, linha do tempo e fontes numeradas. AboutPage, FAQPage e BreadcrumbList. Só o Decreto-Lei 7.928/1945 tem link para fonte oficial (Câmara). |
| `/equipe.html` (+ `/en/our-team/`, `/es/nuestro-equipo/`) | Os 7 princípios com uma frase cada, "entidade sem fins lucrativos", utilidade pública e diretoria. |
| `/en/` e `/es/` | Seção de princípios e "o Movimento ao qual pertencemos". |
| `/termos/`, `/reembolso/` (+ EN/ES), rodapé da matrícula, checkout | Já dizem quem vende: O-CVB Filial Rio de Janeiro Ensino Ltda, CNPJ 67.733.551/0001-35. |
| `/transparencia/`, `/canais-oficiais/`, `/acervo/`, `/verificar/` | Existem, mas a transparência está vazia ("Nenhum documento publicado ainda"), o acervo está "Em breve" e os canais oficiais estão incompletos. |

Nenhuma página do site linka icrc.org, ifrc.org ou ihl-databases.icrc.org.

---

## 3. O que falta

### 3.1 Páginas novas

| Item | Situação hoje | O que fazer | Fonte oficial para tirar o texto e linkar | Prioridade | O que o Matheus precisa mandar |
|---|---|---|---|---|---|
| **Cursos gratuitos da filial** (primeiros socorros e formação de voluntários) | Não existe. O primeiro slide da home (Força-Tarefa El Niño) não tem link (classe `sem-link`, `site/index.html:1479`). O texto alternativo (linha 1482) fala em "Mobilização interna com voluntários" e termina em "Evento interno". A formação de voluntários nunca aparece como gratuita. O catálogo pago (`cursos.json`) tem "Primeiros Socorros Básico" (8 h) e "Primeiros Socorros Lei Lucas – Ambientes com Crianças" (8 h), vendidos pela escola. Nada separa esses cursos das formações gratuitas. | Criar `/cursos-gratuitos/`: o que é, quem oferece (a filial, ONG), como se inscrever, calendário, ligação com o voluntariado e a formação El Niño como exemplo. Incluir um quadro "Gratuito (filial, ONG) × Pago (escola, outra empresa)", com público, carga horária, certificado, quem ministra e quem oferece. Fechar com um bloco "Procura curso profissionalizante?" que leva aos cursos pagos e diz quem vende. Uma primeira versão pode sair já, sem calendário: o que é, quem oferece, "próximas datas a divulgar" e um caminho para registrar interesse pelo formulário ou pelo WhatsApp do voluntariado que o site já publica (canal **A CONFIRMAR**). Tirar o `sem-link` do slide El Niño, ligá-lo a esta página ou à notícia e trocar o texto alternativo. | Estatuto da CVB (Decreto 8.885/2016): art. 11, §1º, V (cursos livres, técnicos e outros, em geral, inclusive pagos), VII (treinar pessoal) e VIII ("incentivar a participação da comunidade"); art. 11, §2º, V ("participação da comunidade em programas de cunho humanitário"): https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm · Princípio do Voluntariado (CICV): https://www.icrc.org/pt/nossos-principios-fundamentais · Lei 9.608/1998, só para quem entra como voluntário com termo de adesão: https://www.planalto.gov.br/ccivil_03/leis/l9608.htm · Estudo do CICV sobre o uso dos emblemas (2011), Questão 36, que recomenda não criar confusão no público: https://www.icrc.org/sites/default/files/external/doc/en/assets/files/publications/icrc-001-4057.pdf | Alta | Para cada formação: público (aberta ou só voluntários), datas, frequência, inscrição, carga horária, certificado e quem ministra. Canal de interesse. Material da formação El Niño (seção 8, itens 7 e 8). |
| **Como a filial se financia** | Não existe nenhum texto sobre isso. O card de transparência da home e o llms.txt prometem "as parcerias da Lei 13.019/2014", mas `/transparencia/` diz "Nenhum documento publicado ainda". | **Segunda etapa, por decisão do Matheus:** tratar dentro de `/transparencia/` (fontes de receita, a escola como outra pessoa jurídica e uma das fontes, parcerias da Lei 13.019/2014, repasse ao Órgão Central). Se a filial tem parcerias vigentes com o poder público, publicá-las é obrigação do art. 11 da Lei 13.019/2014; se não tem, tirar a promessa do card e do llms.txt já nesta etapa. Antes de pedir o Ad Grants, essa explicação precisa estar no ar (seção 7). | Lei 13.019/2014, art. 11: https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/lei/l13019.htm · Estatuto, art. 22, III e arts. 71 a 73 | Segunda etapa | Se há parcerias vigentes com o poder público (seção 8, item 10). O resto, na etapa da transparência. |
| **Princípios Fundamentais** | Só frases soltas na home, na equipe e na história. Sem data e sem link oficial. | Criar `/principios-fundamentais/`: um H2 por princípio, texto oficial brasileiro, data (Viena, 1965) e um parágrafo próprio por princípio sobre como a filial o aplica no RJ. | Texto oficial brasileiro: Estatuto da CVB, art. 8º. Mandato de promover e divulgar: art. 11, §1º, IV ("promover os Princípios Fundamentais do Movimento") e IX ("divulgar os princípios humanitários da Cruz Vermelha") (Decreto 8.885/2016, link acima) · CICV PT: https://www.icrc.org/pt/nossos-principios-fundamentais · Folheto CICV 4046/007: https://www.icrc.org/sites/default/files/external/doc/pt/assets/files/publications/icrc-007-4046.pdf | Alta | Exemplos reais de como a filial aplica cada princípio (**A CONFIRMAR**). |
| **Base legal** | As leis estão espalhadas em `/historia/`, com fontes da Wikipédia e do Memória CVB, e uma delas está revogada. | Criar `/base-legal/` com uma tabela em HTML (não PDF): norma, data, ementa curta, link oficial, "o que significa para a filial" e situação (vigente ou revogada). | Planalto, Câmara, Senado, ALERJ e Diário Oficial do Rio (lista completa na seção 10) | Alta | Nada: a lista está na seção 6. |
| **Emblema: uso, proteção e denúncia** | Só menções em `/historia/` e `/termos/`. | Criar `/emblema/` com estas seções: usos protetor e indicativo; quem pode usar; o que diz a lei no Brasil; o PL 8.754/2017; como denunciar uso indevido. Sobre o PL: de autoria da Dep. Bruna Furlan, foi aprovado no Plenário da Câmara em 30/06/2026; desde 02/07/2026 está na CCJC (Comissão de Constituição e Justiça e de Cidadania), aguardando relator para a redação final; depois vai ao Senado. Ainda não é lei. O canal de denúncia fica **A CONFIRMAR**. | CICV: https://www.icrc.org/pt/nossos-emblemas · Folheto CICV/FICV 4247/007 (fev/2025): https://www.icrc.org/pt/publication/emblemas-da-cruz-vermelha-e-do-crescente-vermelho · Decreto 2.380/1910: https://www.planalto.gov.br/ccivil_03/decreto/historicos/dpl/dpl2380-1910.htm · Estatuto, art. 9º, §1º ("devendo atuar contra o uso indevido do Emblema") e art. 11, §1º, X ("cooperar com as autoridades públicas para garantir (…) a proteção aos emblemas") · PL 8.754/2017: https://www.camara.leg.br/proposicoesWeb/fichadetramitacao?idProposicao=2153731 · andamento: https://dadosabertos.camara.leg.br/api/v2/proposicoes/2153731 | Média | Canal oficial de denúncia (seção 8, item 13). Parecer jurídico antes de falar em "crime" (item 19). |
| **Movimento Internacional** | Uma seção em `/historia/` e nas homes EN/ES, sem links. | Criar `/movimento-internacional/`, uma página curta: CICV, FICV, as 191 Sociedades Nacionais, a CVB como Sociedade Nacional e a filial como filial estadual. | CICV PT: https://www.icrc.org/pt/sobre-o-movimento-internacional-da-cruz-vermelha-e-do-crescente-vermelho · Conferência Internacional: https://www.icrc.org/pt/conferencia-internacional · Estatuto da CVB, arts. 1º, 3º, 7º e 13 | Média | Nada. |
| **Direito Internacional Humanitário (DIH) e Convenções de Genebra** | Só menções de passagem. O README aponta cerca de 670 buscas por mês sem página. | Criar `/direito-internacional-humanitario/`: o que é o DIH em linguagem simples, os tratados que o Brasil ratificou e os decretos de promulgação. Base própria: o Estatuto manda as filiais estaduais divulgar "a legislação humanitária internacional, as Convenções de Genebra" (art. 13, §2º, I) e põe a "difusão do Direito Internacional Humanitário" entre as missões da CVB (art. 11, §2º, III). Não afirmar que a CVB ou a filial integra a Comissão Nacional de DIH: o Decreto 11.752/2023 só convida o CICV (art. 4º, §4º). | CICV, folheto "O que é o DIH": https://www.icrc.org/sites/default/files/document/file_list/o_que_e_o_dih.pdf · https://www.icrc.org/pt/convencoes-de-genebra-e-o-direito · Decreto 42.121/1957: https://www.planalto.gov.br/ccivil_03/decreto/1950-1969/d42121.htm · Decreto 11.752/2023: https://www.planalto.gov.br/ccivil_03/_ato2023-2026/2023/decreto/d11752.htm | Média | Atividades reais de difusão do DIH (**A CONFIRMAR**). |
| **Governança, estatuto e ética** | A transparência está vazia. O estatuto da filial não está publicado. | Criar `/governanca/`, ou uma seção de `/transparencia/`, com: estatuto da filial (PDF **A CONFIRMAR**) com resumo em HTML, diretoria, código de ética nacional (link para o PDF) e canal de denúncia. Publicar as parcerias com o poder público, se houver (Lei 13.019/2014, art. 11). | Estatuto nacional (Decreto 8.885/2016, link do Planalto como principal; o PDF no site da CVB, https://cruzvermelha.org.br/wp-content/uploads/2017/01/Estatuto-da-Cruz-Vermelha-Brasileira_AF.pdf, é só cópia) · Código Nacional de Ética e Conduta (PDF): https://cruzvermelha.org.br/wp-content/uploads/2017/10/C%C3%B3digo-Nacional-de-%C3%89tica-e-Conduta.pdf · Página "Comissão de Ética" da CVB: https://cruzvermelha.org.br/pb/institucional__trashed/comissao-de-etica/ (fica sob um caminho "__trashed" e pode quebrar; linkar o PDF) | Média | Estatuto da filial em PDF, atas, balanços, parcerias e canal de denúncia (seção 8, item 10). |
| **Voluntariado** | "Seja voluntário" leva a um formulário externo (spotform). A linha 30 do `site/.htaccess` manda `/voluntario/`, `/voluntariado/` e `/seja-voluntario/` para `/bio/` com 301. Com essa regra, uma página em `site/voluntariado/` nunca seria servida. | Criar `/voluntariado/` ligada aos cursos gratuitos. Tirar `voluntariado` da regra da linha 30 do `site/.htaccess` e mandar `/voluntario/` e `/seja-voluntario/` para `/voluntariado/` com 301. Usar o markup de vaga voluntária (JobPosting VOLUNTEER) só se houver vaga aberta de verdade. | Estatuto, art. 67, §2º (Membro Voluntário) · Lei 9.608/1998 | Média | Página própria ou só o formulário externo. Termo de adesão (seção 8, item 9). |
| **Endereço `/cursos/`** | A linha 26 do `site/.htaccess` manda `/cursos/` direto para a matrícula paga (301). Isso reforça o peso comercial do site. | Transformar `/cursos/` numa página que reúne "Cursos gratuitos (filial)" e "Cursos pagos (escola)", ou mandar `/cursos/` para `/cursos-gratuitos/`, onde o bloco de cursos pagos leva à matrícula. Os endereços novos propostos (`/quem-somos/`, `/principios-fundamentais/` etc.) não esbarram em outras regras do `.htaccess` hoje. | Política de site do Google para ONGs (atividade comercial não deve ser o foco): https://support.google.com/nonprofits/answer/1657899?hl=pt-BR | Alta | Escolher entre as duas opções (seção 8, item 22). |
| **Quem somos** (página central "Institucional") | "Sobre" é só uma âncora da home. | Criar `/quem-somos/`, uma AboutPage que resume a filial e liga a todas as páginas acima. | Estatuto da CVB, arts. 2º e 13. A denominação das filiais estaduais está no art. 13, §2º: "Cruz Vermelha Brasileira - Filial, seguida da nominação do estado sede". | Média | Nome principal da entidade no site (seção 8, item 14). |
| **Turma gratuita aberta ao público** (uma página por turma) | Não existe. | Só para turmas futuras abertas ao público geral: uma URL por turma, com markup de evento (Event). A formação El Niño desta semana já aconteceu; se foi aberta ao público ou só para voluntários fica **A CONFIRMAR**. Ela entra como notícia e como exemplo em `/cursos-gratuitos/`. | Regras de Event do Google ("Events must be bookable to the general public"): https://developers.google.com/search/docs/appearance/structured-data/event | Média, quando houver turma | Datas e local das turmas abertas (seção 8, item 7). |
| **EN/ES das páginas novas e `/es/historia/`** | Não existem. | Princípios, emblema e Movimento em EN e ES, com base nos textos oficiais do CICV nesses idiomas. Para os princípios, escolher entre a opção A e a opção B da seção 4. Base legal e financiamento podem vir depois. | Estatutos do Movimento EN: https://www.icrc.org/sites/default/files/external/doc/en/assets/files/other/statutes-en-a5.pdf · ES: https://www.icrc.org/sites/default/files/external/doc/es/assets/files/other/statutes-es-a5.pdf · Termos de uso do CICV: https://www.icrc.org/pt/copyright-e-termos-de-uso | Baixa | Escolher a opção A ou B (seção 8, item 21). |

### 3.2 Dados estruturados (JSON-LD)

| Item | Situação hoje | O que fazer | Fonte | Prioridade |
|---|---|---|---|---|
| Escola como `subOrganization` da ONG | Na home, a escola aparece como `subOrganization` da ONG: um nó EducationalOrganization com `parentOrganization` → `#organizacao`, sem CNPJ. Na matrícula, o mesmo nó tem `parentOrganization`, e as ofertas dos cursos não têm `seller` (vendedor). | Tirar o `subOrganization` da home e o `parentOrganization` da matrícula (os dois arquivos). Criar um nó próprio para a escola, com `legalName` "O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA" (como no registro da Receita; confirmar no cartão CNPJ oficial) e `taxID` 67.733.551/0001-35, e pôr `Offer.seller` apontando para esse nó. Só usar `parentOrganization`/`subOrganization` se o vínculo societário for confirmado. | https://schema.org/seller · https://schema.org/subOrganization | Alta |
| `description`, `legalName` e `foundingDate` da ONG | Não existem. | `description` com "associação privada sem fins lucrativos". `legalName` e `foundingDate`: **A CONFIRMAR**. | https://developers.google.com/search/docs/appearance/structured-data/organization (Google usa) | Alta |
| Nome divergente | O NGO e o WebSite têm nomes diferentes. As homes EN/ES e as notícias EN redeclaram o `#organizacao` com outro `alternateName`, e só EN/ES têm `parentOrganization`. | Mesmo `name` e mesmo `alternateName` no NGO e no WebSite. O nó completo fica só na home PT; as demais páginas só referenciam o `@id`. Base para o nome formal: Estatuto, art. 13, §2º ("Cruz Vermelha Brasileira - Filial, seguida da nominação do estado sede"). | https://developers.google.com/search/docs/appearance/site-names (Google usa) | Alta |
| `ownershipFundingInfo` | Não existe. | Segunda etapa: apontar para a parte de financiamento de `/transparencia/` quando existir. | https://schema.org/ownershipFundingInfo (Sem efeito prometido pelo Google) | Segunda etapa |
| `parentOrganization`: CVB nacional | Não existe em PT. Em EN/ES aparece só com o nome. | Um nó da CVB com `url` https://cruzvermelha.org.br/pb/ e `sameAs` do Wikidata Q47072433. | https://www.wikidata.org/wiki/Q47072433 (Sem efeito prometido pelo Google) | Média |
| `sameAs` com Wikidata e com a página da nacional | Faltam. | Acrescentar https://www.wikidata.org/wiki/Q141635343 depois de completar o item. Acrescentar a página da filial na nacional depois que ela for corrigida. | https://www.wikidata.org/wiki/Q141635343 | Média |
| `hasCertification`: utilidades públicas | Não existe. | Dois nós Certification, com link para a lei municipal e para a estadual (as duas confirmadas). | https://schema.org/hasCertification (Sem efeito prometido pelo Google) | Baixa |
| `ethicsPolicy`, `publishingPrinciples`, `knowsAbout` | Não existem. | `ethicsPolicy` → Código Nacional de Ética e Conduta da CVB (PDF) ou uma página da filial que o resuma. `publishingPrinciples` só se a Redação tiver uma política editorial das notícias; não apontar para os Princípios Fundamentais, que não são política editorial. A ligação com os princípios fica no WebPage e no DefinedTermSet da página própria. `knowsAbout`: primeiros socorros, voluntariado, DIH. | https://schema.org/ethicsPolicy · https://schema.org/publishingPrinciples (Sem efeito prometido pelo Google) | Baixa |
| `nonprofitStatus` | Não existe. | **Não usar.** As enumerações do schema.org cobrem só EUA, Reino Unido, Países Baixos, Alemanha e Itália. | https://schema.org/nonprofitStatus · https://schema.org/NonprofitType | — |
| `iso6523Code` | Não existe. | **Não usar por enquanto.** Não achamos código ICD da ISO 6523 para o CNPJ (não confirmado), e o `taxID` já traz o CNPJ. A propriedade está entre as recomendadas na documentação de Organization do Google. | https://developers.google.com/search/docs/appearance/structured-data/organization | — |
| `/transparencia/` e `/canais-oficiais/` | Sem nenhum JSON-LD. Essas páginas são geradas pela Redação, fora do repositório. | WebPage com `about` → `#organizacao` e BreadcrumbList. | — | Média |
| Páginas EN/ES sem `@id` e sem `about` (our-team, courses, faq) | Não ligadas à entidade. | Acrescentar `@id`, `isPartOf` → `#site` e `about` → `#organizacao`. | — | Baixa |
| Projeto Cores | Declara um segundo NGO, sem `@id` e com outro nome. | Referenciar o `#organizacao`. | — | Baixa |

### 3.3 Correções de fatos já publicados (detalhes na seção 6)

| Item | Situação hoje | O que fazer | Fonte oficial | Prioridade |
|---|---|---|---|---|
| Decreto 8.714/2016 citado como vigente | Aparece em `/historia/` (texto e linha do tempo) e em `/en/history/`. | Trocar por Decreto nº 8.885, de 24/10/2016. | https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8714.htm | Alta |
| "Não há cursos gratuitos" | Aparece na FAQ da home (texto visível e JSON-LD), em `faq-home.json`, `chat.js`, `llms.txt` e nas FAQs EN/ES. A resposta sobre cuidador de idosos no llms.txt também sugere que a filial não tem nada gratuito. | Reescrever (proposta de texto na seção 3b). | Fato do dono (11/10/2026) | Alta |
| Quem vende os cursos | Não aparece na home, no llms.txt, no JSON-LD da home, em `/en/courses/`, `/es/cursos/` nem nas FAQs. | Incluir a frase de quem vende (seção 3b). | — | Alta |
| Decreto 2.380/1910 apresentado como "reconheceu a CVB como a única sociedade" | Aparece em `/historia/`. | Reformular. | https://www.planalto.gov.br/ccivil_03/decreto/1910-1929/d9620.htm | Alta |
| "Utilidade pública internacional" | Aparece em `/historia/`; em `/en/history/`, "international public utility". | Trocar por "utilidade internacional". | Mesmo link acima | Alta |
| Tombamento "definitivo" × "provisório" | `/historia/` diz definitivo; `/equipe.html` (+ EN/ES) diz provisório. | Unificar o texto (seção 6, item 14). | Ata do IPHAN (seção 10) | Alta |
| "Sem fins lucrativos" ausente da home PT | Só aparece "associação privada". | Escrever "associação privada sem fins lucrativos" no texto visível. | Estatuto da CVB, art. 2º | Alta |
| Promessa de "cursos gravados grátis" a quem doa e links para `/en/donate/` | A página de doação responde 503. | Tirar a promessa e os links, ou reativar a doação. | — | Alta |
| Fontes das leis | As leis citam Wikipédia e Memória CVB. | Trocar pelos links oficiais. | Seção 10 | Média |
| "191 países" | O box "Para entender" do modelo de notícias da Redação diz "presente em 191 países" nas matérias PT no ar (conferido em 3 delas). Em inglês, "present in 191 countries" aparece em 19 páginas de `site/en/news/`, vindas de `traducoes/en/paginas.json`. O número se refere a Sociedades Nacionais, não a países. | Escrever "191 Sociedades Nacionais" (EN: "191 National Societies") no modelo da Redação e no `paginas.json`. Não copiar o "189 Sociedades Nacionais" do folheto 4046/007, que é de 2014. | https://www.icrc.org/pt/sobre-o-movimento-internacional-da-cruz-vermelha-e-do-crescente-vermelho | Média |
| Home: "trajetória desde 1908" | 1908 é a fundação da CVB nacional, não da filial. | Deixar claro que 1908 é a CVB nacional e que a filial é de 2006 (data **A CONFIRMAR**). | Estatuto, art. 1º | Média |
| `/canais-oficiais/` diz que lista "os únicos" canais | Não lista Instagram nem Facebook. | Completar a lista. | — | Média |

### 3.4 Sinais fora do site

| Item | Situação hoje | O que fazer | Fonte | Prioridade |
|---|---|---|---|---|
| Wikidata da filial | O item **já existe** (Q141635343) e está quase vazio: só o link para a Wikipédia. O README está desatualizado nesse ponto. | Completar: rótulo e descrição em PT/EN/ES, P749 (organização-mãe) = Q47072433, P17 (país) = Q155, P159 (sede) = Q8678, P856 (site) = https://cruzvermelhariodejaneiro.org/ e P571 (fundação) **A CONFIRMAR**. Q155 (Brasil) e Q8678 (Rio de Janeiro) são os códigos usuais, mas não foram reconferidos nesta revisão porque a API do Wikidata respondeu 429 (não confirmado). A classe para P31 (instância de) segue sem código confirmado. Não usar Q1968122 como "instância de", porque essa classe é da Sociedade Nacional. Quem editar deve declarar o vínculo com a filial: os Termos de Uso da Wikimedia, que valem também para o Wikidata, exigem declarar contribuições pagas, inclusive edição feita em nome de um empregador ou cliente. Para edição voluntária de alguém ligado à filial, a mesma transparência é boa prática. | https://www.wikidata.org/wiki/Q141635343 · https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use | Média |
| Página da filial no site nacional | Não linka o site da filial, usa um e-mail de domínio antigo e tem o campo "Presidente: --". | Pedir à nacional: link para cruzvermelhariodejaneiro.org, e-mail atual e presidente. | https://cruzvermelha.org.br/pb/filiais/rio-de-janeiro/ | Média |
| Páginas institucionais da nacional | Princípios, Emblemas, DIH e Movimento dão 404 (endereços terminados em "__trashed"). A página "Comissão de Ética" também fica sob um caminho "__trashed" (responde 200 hoje, mas pode quebrar). A página "Primeiros Socorros nas Escolas" tem spam de cassino. | Não linkar essas páginas (para ética, linkar o PDF do Código). Avisar a nacional. Linkar o CICV até elas voltarem. | https://cruzvermelha.org.br/pb/ | Baixa (para nós) |
| Verbete da Wikipédia em português | Cita o Decreto 8.714 (revogado) e um site não oficial para a Lei 9.984. | Sugerir a correção na página de discussão, citando o Decreto 8.885/2016 e o link da ALERJ, com o vínculo declarado. | Seção 10 | Média |
| Perfil da Empresa no Google | Não sabemos se existe (**A CONFIRMAR**). | Perfil da ONG sem categoria de escola. Se a escola atende o público, ela precisa de perfil próprio, com nome próprio. Rever a ideia antiga de pôr o link da matrícula no perfil da ONG. | https://support.google.com/business/answer/3038177 | Média |
| Painel de conhecimento | Não sabemos se existe. O domínio já está verificado no Search Console por registro TXT (README, linha 24). | Se aparecer, reivindicar. A verificação no Search Console conta entre as formas aceitas para reivindicar. O Google não garante painel nem diz que usa o Wikidata. Usar também o Search Console para acompanhar os dados estruturados das páginas novas. | https://support.google.com/knowledgepanel/answer/7534902 | Baixa |

### 3.5 Google para ONGs / Ad Grants

| Item | Situação hoje | O que fazer | Fonte | Prioridade |
|---|---|---|---|---|
| CNPJ da ONG | Espelhos dos dados abertos da Receita mostram 08.560.973/0001-97 **INAPTO desde 12/05/2026** por "omissão de declarações". O comprovante oficial não foi conferido. | Tirar o comprovante oficial e, se confirmar, regularizar antes de qualquer cadastro. A verificação da Goodstack exige entidade "registrada e ativa". | https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/Cnpjreva_Solicitacao.asp | Alta (bloqueante) |
| Dono do domínio | O registro público do domínio (RDAP) mostra: registrado em 28/06/2026, **vence em 28/06/2027**, registrador Hostinger operations, UAB, titular oculto. O domínio foi registrado 3 dias depois da abertura da Ltda (25/06/2026). | **Respondido em 15/10/2026 (Matheus):** o domínio está na conta da Unicopag e dele, como gestores que o compraram, não no CNPJ da filial. A política exige que a ONG seja dona do domínio. Decisão: um documento futuro (termo de cessão/uso do domínio à filial, bem detalhado) formaliza a situação; ainda não existe. Ligar a renovação automática antes de 28/06/2027: se o domínio expirar, o site, os e-mails e o Ad Grants caem juntos. | https://support.google.com/nonprofits/answer/1657899?hl=pt-BR · https://rdap.publicinterestregistry.org/rdap/domain/cruzvermelhariodejaneiro.org | Alta |
| Peso comercial do site | A primeira faixa e o primeiro botão da home vendem curso. 17 das 26 perguntas da FAQ são de cursos pagos. Quem digita `/cursos/` cai direto na matrícula paga (`.htaccess`, linha 26). | Dar o destaque à missão: cursos gratuitos, voluntariado, princípios. A matrícula continua, mas não no topo. Mudar o destino de `/cursos/` (seção 3.1). | Mesmo link | Alta |
| Explicar como a venda apoia a ONG | Não existe. | Agora: a frase de quem vende perto de preços e botões. Segunda etapa: a explicação do financiamento em `/transparencia/`, obrigatória antes do pedido ao Ad Grants. | Mesmo link | Alta (frase) / segunda etapa (transparência) |
| Páginas feitas só de links | Ainda não existem, mas o plano de tirar o texto das fontes oficiais e linkar pode criá-las. A política aponta como problema "Páginas que, em sua maioria, são links para outros sites ou conteúdo incorporado (como playlists de vídeo) sem texto de apoio". | Cada página institucional precisa ter mais texto próprio da filial do que links e trechos citados (seção 4). | Mesmo link | Alta |
| Páginas "em construção" | A transparência está vazia e o acervo está "Em breve". | Publicar documentos, ou tirar essas páginas do menu até terem conteúdo. | Mesmo link | Alta |
| Links de doação quebrados | `/en/donate/` e `/es/donar/` respondem 503. | Tirar os links ou reativar a doação. | Mesmo link | Alta |
| Para onde os anúncios levam | Ainda não há conta. | Anúncios do Ad Grants só para páginas da ONG, nunca para a matrícula. As palavras-chave de "primeiros socorros" levam só à página gratuita. A venda dos cursos, se anunciada, vai numa conta paga da própria empresa (inferência nossa, não regra escrita). | Mesmo link | Média |

---

## 3b. Filial (ONG) e escola (empresa): o que deixar claro

**Os fatos, na formulação do dono:**
- Os cursos pagos são vendidos pela **O-CVB Filial Rio de Janeiro Ensino Ltda, CNPJ 67.733.551/0001-35**: a taxa de inscrição e a matrícula na página `/matricula-cursos-presenciais/`, e os cursos da plataforma escola.cursoscruzvermelha.org.
- É uma **empresa ligada à filial, com sistemas independentes**: plataforma, cobrança e nota fiscal próprias.
- A **filial** (ONG, CNPJ 08.560.973/0001-97) **não vende cursos**.
- A **escola é uma das fontes de financiamento** da filial.
- A filial **também oferece cursos sem custo** de **primeiros socorros** e de **voluntariado** (formação de voluntários). Exemplo desta semana: a formação da Força-Tarefa Humanitária – Ações El Niño, que está no primeiro slide da home. Ainda **não há página** para esses cursos.

**Ressalva sobre "não vende cursos".** A home diz, sobre os treinamentos de NR e Lei Lucas para empresas e escolas: "É assim que as empresas parceiras apoiam a filial" (`site/index.html`, linhas 1330 e 2011). Quem fatura esses treinamentos está **A CONFIRMAR** (seção 8, item 5). Se for a ONG, a frase "não vende cursos" fica falsa. Até confirmar, usar a formulação mais cuidadosa da frase padrão abaixo, também na FAQ e no llms.txt.

**Sobreposição de temas: primeiros socorros grátis e pagos.** O catálogo pago (`site/matricula-cursos-presenciais/cursos.json`) tem "Primeiros Socorros Básico" (8 h) e "Primeiros Socorros Lei Lucas – Ambientes com Crianças" (8 h), vendidos pela escola. As formações gratuitas da filial também são de primeiros socorros. O leitor precisa saber distinguir:
- Em `/cursos-gratuitos/`, um quadro "Gratuito (filial, ONG) × Pago (escola, outra empresa)", com público, carga horária, certificado, quem ministra e quem oferece (**A CONFIRMAR**).
- O Estudo do CICV sobre o uso dos emblemas (2011, Questão 36) recomenda que "no confusion must be created in the public's mind" e alerta para a concorrência comercial em primeiros socorros.

**A CONFIRMAR com o Matheus:**
- O vínculo exato: se a filial é sócia ou controladora da empresa, se há contrato, e se e como o resultado chega à filial. O Estatuto prevê "Unidades próprias de Saúde, de Ensino, de Pesquisa e de Assistência Social, vinculadas às Filiais", regidas por estatuto social próprio (art. 22, III). A escola é uma dessas unidades, ou uma empresa independente? Há autorização do Órgão Central?
- A razão social. O espelho dos dados abertos da Receita (BrasilAPI) dá "O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA", porte "Empresa de Pequeno Porte", ativa desde 25/06/2026, CNAE "Cursos preparatórios para concursos". O "- EPP" registrado no README é o porte, não parte do nome. Confirmar no cartão CNPJ oficial, que exige captcha.

Atenção: o mesmo espelho (não o comprovante oficial) mostra a Ltda com um único sócio, pessoa física (sócio-administrador), e a filial fora do quadro. Nome e sobrenome desse sócio aparecem entre os diretores listados em `/equipe.html`. Se for a mesma pessoa, é uma relação entre partes ligadas, e a etapa da transparência precisa tratá-la com clareza (**A CONFIRMAR**). O Estudo do CICV sobre o uso dos emblemas (2011, Questão 36: https://www.icrc.org/sites/default/files/external/doc/en/assets/files/publications/icrc-001-4057.pdf) traz recomendações e não é norma. Ele trata de empresas comerciais controladas pela Sociedade Nacional e recomenda contrato escrito, logo pequeno, nada que confunda o público e controle estrito do logo pela Sociedade Nacional. O que obriga no Brasil é o Decreto 2.380/1910 (arts. 2º a 4º) e o Estatuto (art. 9º, §2º e art. 69, V). Também é preciso confirmar se o Órgão Central da CVB autorizou a empresa a usar "CVB" no nome e o emblema nas páginas de venda.

**Contradição a corrigir agora.** A pergunta 4 da FAQ da home diz: "Hoje os sete cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro são pagos (…) Não há curso gratuito de enfermagem, técnico ou de primeiros socorros no catálogo". Onde a mesma ideia aparece:
- `site/index.html`: texto visível e JSON-LD;
- `site/faq-home.json`, de onde a FAQ é gerada (via `scripts/gerar_faq_home.py`);
- `site/chat/chat.js:76`;
- `site/llms.txt`: "Não há cursos gratuitos, cursos técnicos nem curso de enfermagem no catálogo." Está na linha 72 do repositório e na linha 59 do arquivo no ar;
- `site/llms.txt`, linha 85 do repositório (72 no ar): "Curso gratuito de cuidador de idosos? Não na filial; o curso é pago. Programas gratuitos costumam vir de prefeituras…". Continua certa sobre o cuidador, mas sugere que a filial não tem nada gratuito;
- `/en/faq/` ("None of the courses is free") e `/es/preguntas-frecuentes/` ("Ningún curso es gratuito"), gerados a partir de `faq-idiomas.json`.

A mesma resposta diz que "o valor do curso (…) é pago depois na plataforma da escola", o que pode estar desatualizado: hoje a matrícula vende taxa e matrícula juntas. Conferir.

**Proposta de texto para a FAQ** (ajustar quando houver datas):
> "O curso Primeiros Socorros Básico (8 h) do catálogo é pago e vendido pela O-CVB Filial Rio de Janeiro Ensino Ltda (CNPJ 67.733.551/0001-35), empresa ligada à filial, com plataforma, cobrança e nota fiscal próprias. A filial, associação sem fins lucrativos, oferece formações gratuitas de primeiros socorros e de voluntariado [público, datas e inscrição: A CONFIRMAR], na página Cursos gratuitos."

**Novo texto para a linha 85 do llms.txt:**
> "Não há curso gratuito de cuidador de idosos na filial (o curso do catálogo é pago e vendido pela O-CVB Filial Rio de Janeiro Ensino Ltda). A filial oferece formações gratuitas de primeiros socorros e de voluntariado: veja /cursos-gratuitos/."

**Frase padrão de quem vende** (para o rodapé de todas as páginas e perto de preços e botões), até o Matheus confirmar quem fatura os treinamentos corporativos:
> "A Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro (CNPJ 08.560.973/0001-97) é uma associação sem fins lucrativos. Os cursos do catálogo presencial e da plataforma da escola são vendidos pela O-CVB Filial Rio de Janeiro Ensino Ltda, CNPJ 67.733.551/0001-35, empresa ligada à filial, com sistemas independentes. A escola é uma das fontes de financiamento da instituição."

Voltar à formulação "a filial não vende cursos" só depois dessa confirmação.

**O nome "cursos livres".** No Brasil, "curso livre" quer dizer curso não regulado pelo MEC, e não curso gratuito. O próprio Estatuto usa o termo nesse sentido (art. 11, §1º, V, que fala de "cursos livres, técnicos, profissionalizantes…" em geral), e o site já chama de "Curso Livre" o Cuidador de Idosos, que é pago (R$ 950). Sugestão: no site, usar **"Cursos gratuitos"** ou **"Formações gratuitas"**; internamente, a equipe pode continuar chamando de "livres". Decisão do Matheus.

**Onde isso deve aparecer:**

| Lugar | O que pôr |
|---|---|
| Home | Frase de quem vende perto do preço e do botão de matrícula. Link para os cursos gratuitos com destaque igual ou maior que a matrícula. Ligar o slide El Niño a `/cursos-gratuitos/` ou à notícia. Corrigir a FAQ. |
| Menu | Separar "Cursos gratuitos (filial)" de "Cursos pagos (escola)". Itens institucionais antes da matrícula. |
| Rodapé de todas as páginas | A frase padrão. Hoje `/en/courses/` e `/es/cursos/` mostram só o CNPJ da ONG ao lado de preços, o que está errado. |
| Matrícula, `/en/courses/`, `/es/cursos/` | A frase também no topo, não só no rodapé. |
| FAQs (home, EN, ES, `/bio/`) | Corrigir as perguntas 1, 2, 6, 8 e 9, que atribuem os cursos e os certificados à ONG. Quem emite o certificado: **A CONFIRMAR**. |
| Notícias PT que promovem cursos (9) | Nota de quem vende, no modelo da Redação (fora deste repositório). O autor hoje é a ONG. |
| Notícias EN que promovem cursos | A mesma nota, editada em `traducoes/en/paginas.json`. Exemplos em `site/en/news/`: basic-first-aid-course-what-to-do-in-the-first-minutes, first-aid-course-learn-the-essentials-in-a-few-lessons, peripheral-iv-cannulation-course-…, knowledge-saves-lives-too-the-branch-takes-its-courses-to-the-public e carer-for-older-people-course-…. Em todas, `author` e `publisher` são a ONG. |
| Plataforma da escola | Razão social e CNPJ no rodapé. Hoje há só o crédito de desenvolvimento. |
| `llms.txt` | Nova seção "Quem somos e quem vende", seção de cursos gratuitos, correção da linha 72 (59 no ar) e da linha 85 (72 no ar). O arquivo do ar está mais velho que o do repositório e precisa ser republicado. |
| JSON-LD | Escola como `EducationalOrganization` própria, com `legalName` e `taxID`. `Offer.seller` → escola. Tirar o `subOrganization` da home e o `parentOrganization` da matrícula. `ownershipFundingInfo` da ONG → transparência, na segunda etapa. `funder` (ONG ← escola) só quando o Matheus confirmar por escrito como o recurso chega. |
| `/privacidade/` | Hoje declara a ONG controladora inclusive dos dados de compra, que são da empresa. Quem é o controlador: **A CONFIRMAR** com o jurídico. |

**Efeito no Ad Grants:**
- Escolas não são elegíveis; só "braços filantrópicos". A empresa não pode pedir o benefício, e a ONG não pode parecer escola.
- A política aceita "atividade comercial limitada", desde que não seja o foco e que o site **explique como esses recursos apoiam os programas**.
- A política **não fala** de venda feita por outra pessoa jurídica no mesmo domínio: como o revisor trata esse caso é **não confirmado**.
- O caminho prudente é deixar a separação explícita em todo o site e mandar os anúncios só para páginas da ONG. As palavras-chave de "primeiros socorros" levam só à página gratuita.

---

## 4. Estrutura de páginas proposta

As URLs seguem o padrão do site: pastas com `index.html` e barra final, PT sem prefixo, EN e ES com nomes no próprio idioma. A árvore abaixo mostra a relação entre as páginas; as URLs são planas, como `/principios-fundamentais/`, e não `/quem-somos/principios-fundamentais/`.

```
/ (home, existe)
├─ /quem-somos/                          /en/about-us/                         /es/quienes-somos/
│  ├─ /principios-fundamentais/          /en/fundamental-principles/           /es/principios-fundamentales/
│  ├─ /emblema/                          /en/emblem/                           /es/emblema/
│  ├─ /movimento-internacional/          /en/red-cross-movement/               /es/movimiento-internacional/
│  ├─ /direito-internacional-humanitario/ /en/international-humanitarian-law/  /es/derecho-internacional-humanitario/
│  ├─ /base-legal/                       /en/legal-basis/                      /es/base-legal/
│  ├─ /governanca/                       /en/governance/                       /es/gobernanza/
│  ├─ /historia/ (existe)                /en/history/ (existe)                 /es/historia/ (falta)
│  ├─ /equipe.html (existe)              /en/our-team/ (existe)                /es/nuestro-equipo/ (existe)
│  └─ /transparencia/ (existe, vazia; financiamento entra aqui na segunda etapa)  /en/transparency/ (falta)  /es/transparencia/ (falta)
├─ /cursos/  (hoje redireciona para a matrícula; virar página que reúne gratuitos e pagos, ou ir para /cursos-gratuitos/)
├─ /cursos-gratuitos/   (filial/ONG)     /en/free-courses/                     /es/cursos-gratuitos/
│  └─ /cursos-gratuitos/<turma>/         (só turmas futuras abertas ao público, se houver)
├─ /voluntariado/  (hoje redireciona para /bio/ pelo .htaccess)   /en/volunteering/   /es/voluntariado/
└─ /matricula-cursos-presenciais/ (existe; escola/empresa)   /en/courses/      /es/cursos/
```

| Página | Título sugerido | O que vai | Texto oficial: de onde vem | Texto próprio da filial (evita conteúdo duplicado) |
|---|---|---|---|---|
| `/quem-somos/` | Quem somos: Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro | Associação sem fins lucrativos, filial estadual da CVB, parte do Movimento. Utilidades públicas. Como se financia. Links para todas as páginas institucionais. | Estatuto, arts. 2º, 3º e 13 (denominação no art. 13, §2º): https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm · Decreto 23.482/1933, arts. 2º e 3º: https://www.planalto.gov.br/ccivil_03/decreto/1930-1949/d23482.htm | Quase tudo: o que a filial faz no RJ, áreas de atuação e equipe. |
| `/principios-fundamentais/` | Os 7 Princípios Fundamentais da Cruz Vermelha | Um H2 por princípio, com o texto oficial na íntegra. Data: 20ª Conferência Internacional, Viena, 1965; incluídos nos Estatutos do Movimento em 1986. | PT: Estatuto da CVB, art. 8º (texto) e art. 11, §1º, IV e IX (mandato de promover e divulgar). É ato oficial e por isso pode ser reproduzido (Lei 9.610/1998, art. 8º, IV: https://www.planalto.gov.br/ccivil_03/leis/l9610.htm). Link para https://www.icrc.org/pt/nossos-principios-fundamentais e para o folheto 4046/007. EN/ES, decisão do Matheus: **opção A**, citar o texto oficial do CICV com crédito e link (risco baixo, mas sem autorização expressa: não confirmado); **opção B**, tradução própria do art. 8º do Estatuto da CVB (ato oficial), marcada como "tradução livre", com link para o texto oficial do CICV. Data: https://blogs.icrc.org/cross-files/the-fundamental-principles-of-the-international-red-cross-and-red-crescent-movement/ | Um parágrafo por princípio sobre como a filial o aplica, com exemplos reais (El Niño, voluntariado, primeiros socorros; **A CONFIRMAR** com o Matheus). |
| `/emblema/` | O emblema da Cruz Vermelha: uso, proteção e denúncia | Usos protetor e indicativo; quem pode usar; o que diz a lei (Decreto 2.380/1910, arts. 2º a 4º; Estatuto, art. 9º, inclusive o §1º, que manda atuar contra o uso indevido; art. 11, §1º, X); a Lei 3.960/1961 é o emblema das entidades de saúde (bastão serpentário), não a cruz; Protocolo III (cristal vermelho); PL 8.754/2017, da Dep. Bruna Furlan, aprovado no Plenário da Câmara em 30/06/2026, desde 02/07/2026 na CCJC aguardando relator para a redação final, depois vai ao Senado (ainda não é lei); como denunciar. | CICV: https://www.icrc.org/pt/nossos-emblemas e https://www.icrc.org/pt/direito-e-politicas/uso-de-emblemas · Folheto 4247/007 · Decretos no Planalto · Câmara para o PL | Por que a filial usa o emblema; como denunciar uso indevido (canal **A CONFIRMAR**). Não falar em "crime" sem parecer jurídico (o art. 4º do Decreto 2.380 remete ao Código Penal de 1890). |
| `/movimento-internacional/` | O Movimento Internacional da Cruz Vermelha e do Crescente Vermelho | Os três componentes, 191 Sociedades Nacionais, Conferência Internacional e Conselho de Delegados. A CVB: fundada em 05/12/1908, reconhecida pelo CICV em 16/03/1912, admitida na Federação em 17/06/1919. | CICV PT/EN/ES "Sobre o Movimento" · https://rcrcconference.org/about/ · Estatuto, art. 7º | Onde a filial fica nessa estrutura (Movimento → CVB → filial estadual RJ). |
| `/direito-internacional-humanitario/` | Direito Internacional Humanitário e Convenções de Genebra | O que é, em linguagem simples. Tratados e decretos brasileiros (42.121/1957, 849/1993, 7.196/2010). Comissão Nacional de DIH (Decreto 11.752/2023). Não afirmar que a CVB ou a filial integra a Comissão: o decreto vigente só convida o CICV (art. 4º, §4º); outras entidades só entram como convidadas eventuais (art. 4º, §3º). | Folheto CICV "O que é o DIH" · https://www.icrc.org/pt/convencoes-de-genebra-e-o-direito · Planalto | O papel da filial na difusão do DIH (Estatuto, art. 13, §2º, I, e art. 11, §2º, III) e atividades reais (**A CONFIRMAR**). |
| `/base-legal/` | Base legal: leis e decretos da Cruz Vermelha Brasileira e da filial | Tabela HTML com a lista da seção 6, marcando o que está vigente e o que está revogado. | Planalto, Câmara, Senado, ALERJ e Diário Oficial do Rio | A coluna "o que significa para a filial". |
| `/governanca/` | Governança, estatuto e ética | Estatuto da filial, diretoria, código de ética nacional, canal de denúncia, certidões e parcerias com o poder público, se houver. | Estatuto nacional (Planalto) · Código Nacional de Ética e Conduta (PDF): https://cruzvermelha.org.br/wp-content/uploads/2017/10/C%C3%B3digo-Nacional-de-%C3%89tica-e-Conduta.pdf · Lei 13.019/2014, art. 11 | Estatuto da filial (**A CONFIRMAR**), órgãos e diretoria. |
| *(segunda etapa)* financiamento dentro de `/transparencia/` | Como a filial se financia | Fontes de receita, a escola como outra pessoa jurídica, parcerias, repasse ao Órgão Central. | Estatuto, art. 22, III e arts. 71 a 73 · Lei 13.019/2014, art. 11 | Todo o texto (vínculo **A CONFIRMAR** na etapa da transparência). |
| `/cursos-gratuitos/` | Cursos gratuitos: primeiros socorros e formação de voluntários | Quem oferece (a filial), temas, como se inscrever, calendário (ou "próximas datas a divulgar" na primeira versão), a formação El Niño como exemplo, o quadro "Gratuito (filial) × Pago (escola)" e o bloco de cursos pagos com a frase de quem vende. | Estatuto, art. 11, §1º, V, VII e VIII, e §2º, V · princípio do Voluntariado · Estudo do CICV (2011), Questão 36 | Todo o texto (público, datas e inscrição **A CONFIRMAR**). |
| `/voluntariado/` | Seja voluntário da Cruz Vermelha no Rio de Janeiro | Como entrar, formação, termo de adesão e link para o formulário. Exige mudar a linha 30 do `site/.htaccess`. | Estatuto, art. 67, §2º · Lei 9.608/1998 | Todo o texto. |

**Como as páginas se ligam:**
- **Menu PT:** trocar "Sobre" (âncora da home) por **"Institucional"**, com: Quem somos, História, Princípios Fundamentais, Emblema, Movimento Internacional, DIH, Base legal, Governança, Transparência. Em **"Cursos"**: Cursos gratuitos (filial) e Cursos pagos (escola). Hoje o link "Institucional" de `campanha-agasalho.html` e `doacao.html` aponta para "/" e passaria a apontar para `/quem-somos/`.
- **Redirecionamentos (`site/.htaccess`):** linha 30, tirar `voluntariado` da regra que leva a `/bio/` e mandar `/voluntario/` e `/seja-voluntario/` para `/voluntariado/`. Linha 26, mudar o destino de `/cursos/` (seção 3.1).
- **Rodapé:** uma coluna "Institucional" com as mesmas páginas. Os 7 nomes de princípios que já estão no rodapé viram links para `/principios-fundamentais/#humanidade` e assim por diante. A frase de quem vende entra em todas as páginas. Os rodapés EN/ES e o do `/acervo/` ganham Transparência e Canais oficiais.
- **Links internos:**
  - Home: o slide El Niño liga a `/cursos-gratuitos/` ou à notícia.
  - `/historia/`: a seção "O Movimento Internacional" liga a `/movimento-internacional/`; o trecho do emblema, a `/emblema/`; os decretos, a `/base-legal/`; a citação dos princípios, a `/principios-fundamentais/`.
  - `/equipe.html`: a lista dos princípios liga à página própria; "utilidade pública" liga a `/base-legal/`; "sem fins lucrativos" liga a `/quem-somos/` (e, na segunda etapa, à transparência).
  - EN: o menu ganha History, porque hoje `/en/history/` não recebe link interno.
- **llms.txt:** nova seção "Institucional" com as URLs novas, "sem fins lucrativos", quem vende e cursos gratuitos. Republicar, porque o do ar está desatualizado. O Google diz que não usa llms.txt; o arquivo serve para outros assistentes.
- **Sitemap:** acrescentar as páginas novas na lista e no mapa de hreflang de `scripts/gerar_sitemaps.py`. Conferir também se `/transparencia/` e `/canais-oficiais/` estão no sitemap do ar: a auditoria de 11/10 não as achou.
- **hreflang:** trio PT/EN/ES recíproco em cada página nova, com `x-default` na versão PT.
- **Regras para o texto vindo das fontes oficiais:**
  - Leis, decretos e tratados podem ser reproduzidos (Lei 9.610/1998, art. 8º, IV).
  - Textos do CICV: citação curta com crédito e link (art. 46, III). Não hospedar PDFs, fotos nem o logo do CICV. Os termos do CICV dizem: "Os documentos podem ser copiados, desde que (…) não seja feita nenhuma modificação e o documento seja copiado na íntegra. As normas acadêmicas se aplicam para citações." (https://www.icrc.org/pt/copyright-e-termos-de-uso).
  - Reproduzir em EN/ES os 7 princípios inteiros (todo o trecho do Preâmbulo dos Estatutos do Movimento) vai além da citação curta. Os Estatutos do Movimento não são tratado nem ato oficial brasileiro, então não entram no art. 8º, IV da Lei 9.610. Por isso, as opções A e B da tabela. Em PT, vale o art. 8º do Estatuto da CVB, sem restrição.
  - **Nunca** usar canonical apontando para o CICV: isso tiraria a página da filial do índice. Cada página aponta o canonical para si mesma.
  - O Google trata como scraping republicar conteúdo sem acrescentar valor (https://developers.google.com/search/docs/essentials/spam-policies). Por isso cada página precisa de texto próprio da filial.
  - A política do Google para ONGs aponta como problema "Páginas que, em sua maioria, são links para outros sites ou conteúdo incorporado (como playlists de vídeo) sem texto de apoio" e "Maior parte do conteúdo copiado de outras fontes, sem adicionar valor original significativo". Cada página institucional precisa ter mais texto próprio da filial (como a filial aplica aquilo no RJ, com exemplos reais) do que links e trechos citados. Uma página que seja só uma lista de links para o CICV ou o Planalto pode ser reprovada no Ad Grants.

---

## 5. Dados estruturados propostos

### 5.1 O que acrescentar à entidade NGO `#organizacao` (só na home PT; as outras páginas referenciam o `@id`)

| Propriedade | Valor proposto | Google usa? | Fonte |
|---|---|---|---|
| `description` | "Associação privada sem fins lucrativos, filial estadual da Cruz Vermelha Brasileira no Rio de Janeiro e parte do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho." Precisa estar também no texto visível. | Sim (recomendada) | Google Organization; Estatuto, arts. 2º e 13 |
| `legalName` | Provável: "CRUZ VERMELHA BRASILEIRA FILIAL DO ESTADO DO RIO DE JANEIRO" (dados abertos). **A CONFIRMAR** no cartão CNPJ. | Sim (recomendada) | Comprovante da Receita |
| `foundingDate` | **A CONFIRMAR** (indício: 21/12/2006, início de atividade do CNPJ) | Sim (recomendada) | Ata de fundação ou comprovante |
| `name` / `alternateName` | Os mesmos no NGO e no WebSite. Sugestão: o name mais curto, com o nome formal, "CVB-RJ" e "Cruz Vermelha RJ" em `alternateName`. O nome formal segue o Estatuto, art. 13, §2º ("Cruz Vermelha Brasileira - Filial, seguida da nominação do estado sede"). Decisão do Matheus. | Sim | https://developers.google.com/search/docs/appearance/site-names |
| `sameAs` | Acrescentar https://www.wikidata.org/wiki/Q141635343 depois de completar o item. | Sim (recomendada; o uso do Wikidata pelo painel não é confirmado) | Wikidata |
| `address` | Padronizar: o site usa "Praça da Cruz Vermelha, 10"; a Receita, "Praça Cruz Vermelha, 10/12"; a ALERJ, "10 – 2º andar". **A CONFIRMAR**. | Sim | Google Organization |
| `parentOrganization` | Nó `#cvb`: Cruz Vermelha Brasileira, url https://cruzvermelha.org.br/pb/, sameAs Q47072433, foundingDate 1908-12-05 | Sem efeito prometido pelo Google | Estatuto, art. 1º; Wikidata |
| `memberOf` | **Não** na filial. Quem é membro da FICV é a CVB, então vai no nó `#cvb` (admitida em 17/06/1919). | Sem efeito prometido pelo Google | Estatuto, art. 7º |
| `hasCertification` | Utilidade pública municipal (Lei 5.153/2010) e estadual (Lei 9.984/2023) | Sem efeito prometido pelo Google | Diário Oficial do Rio; ALERJ |
| `ownershipFundingInfo` | Segunda etapa: a parte de financiamento de `/transparencia/` | Sem efeito prometido pelo Google | schema.org |
| `ethicsPolicy` | Código Nacional de Ética e Conduta da CVB (PDF), ou uma página da filial que o resuma | Sem efeito prometido pelo Google | https://schema.org/ethicsPolicy |
| `publishingPrinciples` | Só se a Redação tiver uma política editorial das notícias. **Não** apontar para os Princípios Fundamentais: o schema.org define a propriedade como os princípios editoriais de quem publica. | Sem efeito prometido pelo Google | https://schema.org/publishingPrinciples |
| `knowsAbout` | Primeiros socorros; Voluntariado; Direito Internacional Humanitário (Q381148) | Sem efeito prometido pelo Google | schema.org |
| `subOrganization` (escola) | **Remover** até o vínculo ser confirmado. | — | — |
| `funder` (escola) | Só depois de o Matheus confirmar como o recurso chega à filial. | Sem efeito prometido pelo Google | schema.org |
| `nonprofitStatus` | **Não usar.** As enumerações do schema.org cobrem só EUA, Reino Unido, Países Baixos, Alemanha e Itália. | — | https://schema.org/NonprofitType |
| `iso6523Code` | **Não usar por enquanto.** Não achamos código ICD da ISO 6523 para o CNPJ (não confirmado), e o `taxID` já traz o CNPJ. | Sim (recomendada na documentação de Organization) | https://developers.google.com/search/docs/appearance/structured-data/organization |

### 5.2 Exemplos (valores confirmados; "A CONFIRMAR" indica o que falta preencher)

**NGO: acréscimos na home**
```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "NGO",
      "@id": "https://cruzvermelhariodejaneiro.org/#organizacao",
      "name": "Cruz Vermelha Brasileira - Filial do Estado do Rio de Janeiro",
      "legalName": "A CONFIRMAR (cartão CNPJ)",
      "description": "Associação privada sem fins lucrativos, filial estadual da Cruz Vermelha Brasileira no Rio de Janeiro e parte do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho.",
      "taxID": "08.560.973/0001-97",
      "parentOrganization": { "@id": "https://cruzvermelhariodejaneiro.org/#cvb" },
      "ethicsPolicy": "https://cruzvermelha.org.br/wp-content/uploads/2017/10/C%C3%B3digo-Nacional-de-%C3%89tica-e-Conduta.pdf",
      "hasCertification": [
        {
          "@type": "Certification",
          "name": "Utilidade pública municipal",
          "certificationIdentification": "Lei municipal nº 5.153, de 30/04/2010",
          "issuedBy": { "@type": "GovernmentOrganization", "name": "Município do Rio de Janeiro" },
          "url": "https://doweb.rio.rj.gov.br/portal/edicoes/download/1135"
        },
        {
          "@type": "Certification",
          "name": "Utilidade pública estadual",
          "certificationIdentification": "Lei estadual nº 9.984, de 29/03/2023",
          "issuedBy": { "@type": "GovernmentOrganization", "name": "Estado do Rio de Janeiro" },
          "url": "https://alerjln1.alerj.rj.gov.br/contlei.nsf/c8aa0900025feef6032564ec0060dfff/592ba21d1eb9875a03258987006dbb6e?OpenDocument"
        }
      ],
      "sameAs": [
        "https://www.instagram.com/cruzvermelhabrasileirarj/",
        "https://www.facebook.com/profile.php?id=61591390052128",
        "https://pt.wikipedia.org/wiki/Cruz_Vermelha_Brasileira_-_Rio_de_Janeiro",
        "https://www.wikidata.org/wiki/Q141635343"
      ]
    },
    {
      "@type": "NGO",
      "@id": "https://cruzvermelhariodejaneiro.org/#cvb",
      "name": "Cruz Vermelha Brasileira",
      "url": "https://cruzvermelha.org.br/pb/",
      "foundingDate": "1908-12-05",
      "sameAs": "https://www.wikidata.org/wiki/Q47072433",
      "memberOf": {
        "@type": "Organization",
        "name": "Federação Internacional das Sociedades da Cruz Vermelha e do Crescente Vermelho",
        "sameAs": "https://www.wikidata.org/wiki/Q1069175"
      }
    }
  ]
}
```
- Os três primeiros `sameAs` são os valores de hoje em `site/index.html`. O Wikidata só entra depois de o item ser completado.
- O `legalName` e o `foundingDate` da filial só entram quando forem confirmados. Não publicar com o texto "A CONFIRMAR".

**WebPage com about, citation, isBasedOn e trilha, mais DefinedTermSet (página dos princípios)**
```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebPage",
      "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#pagina",
      "url": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/",
      "name": "Os 7 Princípios Fundamentais da Cruz Vermelha",
      "inLanguage": "pt-BR",
      "isPartOf": { "@id": "https://cruzvermelhariodejaneiro.org/#site" },
      "publisher": { "@id": "https://cruzvermelhariodejaneiro.org/#organizacao" },
      "breadcrumb": { "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#trilha" },
      "about": {
        "@type": "Thing",
        "name": "Princípios Fundamentais do Movimento Internacional da Cruz Vermelha e do Crescente Vermelho",
        "sameAs": "https://www.wikidata.org/wiki/Q19284407"
      },
      "mainEntity": { "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#principios" },
      "isBasedOn": "https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm",
      "citation": ["https://www.icrc.org/pt/nossos-principios-fundamentais"]
    },
    {
      "@type": "BreadcrumbList",
      "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#trilha",
      "itemListElement": [
        { "@type": "ListItem", "position": 1, "name": "Início", "item": "https://cruzvermelhariodejaneiro.org/" },
        { "@type": "ListItem", "position": 2, "name": "Quem somos", "item": "https://cruzvermelhariodejaneiro.org/quem-somos/" },
        { "@type": "ListItem", "position": 3, "name": "Princípios Fundamentais" }
      ]
    },
    {
      "@type": "DefinedTermSet",
      "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#principios",
      "name": "Princípios Fundamentais da Cruz Vermelha",
      "hasDefinedTerm": [
        {
          "@type": "DefinedTerm",
          "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#voluntariado",
          "name": "Voluntariado",
          "description": "É um Movimento de socorro voluntário, de caráter desinteressado e sem finalidade lucrativa (Estatuto da CVB, art. 8º, V).",
          "url": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#voluntariado",
          "inDefinedTermSet": { "@id": "https://cruzvermelhariodejaneiro.org/principios-fundamentais/#principios" }
        }
      ]
    }
  ]
}
```
- Repetir o DefinedTerm para os 7 princípios: Humanidade, Imparcialidade, Neutralidade, Independência, Voluntariado, Unidade e Universalidade. Os nomes estão no CICV e no art. 8º.
- Toda página nova deve ter um BreadcrumbList como o do exemplo.

**Legislation (página da base legal)**

A página da filial **não é** a lei. Ela é uma WebPage que cita (`mentions`) nós Legislation, cada um com a URL oficial.
```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebPage",
      "@id": "https://cruzvermelhariodejaneiro.org/base-legal/#pagina",
      "url": "https://cruzvermelhariodejaneiro.org/base-legal/",
      "name": "Base legal: leis e decretos da Cruz Vermelha Brasileira e da filial",
      "inLanguage": "pt-BR",
      "isPartOf": { "@id": "https://cruzvermelhariodejaneiro.org/#site" },
      "about": { "@id": "https://cruzvermelhariodejaneiro.org/#organizacao" },
      "mentions": [
        { "@id": "https://cruzvermelhariodejaneiro.org/base-legal/#decreto-8885-2016" }
      ]
    },
    {
      "@type": "Legislation",
      "@id": "https://cruzvermelhariodejaneiro.org/base-legal/#decreto-8885-2016",
      "name": "Decreto nº 8.885, de 24 de outubro de 2016",
      "legislationIdentifier": "Decreto nº 8.885/2016",
      "legislationType": "Decreto",
      "legislationDate": "2016-10-24",
      "legislationJurisdiction": "BR",
      "legislationLegalForce": "https://schema.org/InForce",
      "description": "Aprova o Estatuto da Cruz Vermelha Brasileira.",
      "url": "https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm"
    }
  ]
}
```
Repetir um nó Legislation por norma da tabela, cada um com `url` (ou `sameAs`) para a fonte oficial, e acrescentar o `@id` dele em `mentions`.

**Escola (EducationalOrganization) e vendedor nas ofertas da matrícula**
```json
{
  "@context": "https://schema.org",
  "@type": "EducationalOrganization",
  "@id": "https://escola.cursoscruzvermelha.org/#escola",
  "name": "Escola de Educação e Saúde CVB-RJ",
  "legalName": "O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA",
  "taxID": "67.733.551/0001-35",
  "url": "https://escola.cursoscruzvermelha.org/"
}
```
- O `legalName` segue o registro da Receita (espelho dos dados abertos). Confirmar no cartão CNPJ oficial antes de publicar (**A CONFIRMAR**).
- Em cada `Offer` dos cursos: `"seller": { "@id": "https://escola.cursoscruzvermelha.org/#escola" }`.
- O nó da escola existe hoje em dois lugares: na home, como `subOrganization` da ONG com `parentOrganization` → `#organizacao`, e na matrícula, com `parentOrganization` → `#organizacao`. Tirar nos dois arquivos até o vínculo ser confirmado.
- O `educationalCredentialAwarded` ("Certificado da Cruz Vermelha Brasileira Rio de Janeiro") depende de saber quem emite o certificado (**A CONFIRMAR**).

**Event (turma futura gratuita aberta ao público; não usar para a formação El Niño, que já aconteceu)**
```json
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "A CONFIRMAR",
  "startDate": "A CONFIRMAR",
  "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
  "location": { "@type": "Place", "name": "A CONFIRMAR", "address": "A CONFIRMAR" },
  "organizer": { "@id": "https://cruzvermelhariodejaneiro.org/#organizacao" },
  "isAccessibleForFree": true,
  "offers": { "@type": "Offer", "price": "0", "priceCurrency": "BRL", "url": "A CONFIRMAR" }
}
```
Regras do Google: uma URL por evento, `name`, `startDate` e `location` obrigatórios, preço 0 quando for gratuito e evento aberto ao público geral ("Events must be bookable to the general public").

**O que esperar do Google:**
- Desde agosto de 2023, o Google mostrava o resultado rico de FAQ só para sites governamentais e de saúde reconhecidos. Ele saiu de vez da busca em 07/05/2026 (aviso de 08/05). Para este site, isso não muda nada: o destaque nunca apareceu aqui.
- O "Course info" foi removido em 2025. O FAQPage e o Course continuam valendo só como vocabulário, sem efeito prometido pelo Google.
- Desde 22/01/2025, as trilhas de navegação (breadcrumbs) aparecem só no resultado de desktop, não no celular. Mesmo assim, toda página nova deve ter BreadcrumbList.
- Nenhum markup garante resultado rico nem painel de conhecimento.
- O domínio já está verificado no Search Console: usar a ferramenta para acompanhar os dados estruturados das páginas novas.

---

## 6. Correções no que já está publicado

Quando duas fontes discordaram, valeu a fonte oficial aberta.

| # | O que o site diz | Veredito | O que escrever | Fonte oficial |
|---|---|---|---|---|
| 1 | Nome e emblema protegidos por lei desde o Decreto 2.380/1910 | **Confirmada** (arts. 2º a 4º). Ajustes: o site chama o ato de "Lei Federal nº 2.380" (`site/historia/index.html:1685`); não afirmar que a punição penal ainda vale, porque o art. 4º remete ao Código Penal de 1890 (questão para o jurídico). | "Decreto nº 2.380/1910 (decreto legislativo, com força de lei)" | https://www.planalto.gov.br/ccivil_03/decreto/historicos/dpl/dpl2380-1910.htm |
| 2 | O 2.380 "reconheceu a CVB como a única sociedade… autorizada a estender sua ação a todos os estados" | **Corrigir.** O 2.380 regula as associações em geral e não nomeia a CVB. "Única que se organizou" está no Decreto 9.620/1912; "todo o território", no art. 3º do Estatuto. | "O Decreto nº 2.380/1910 regulou as associações da Cruz Vermelha e exigiu organização federativa com órgão central. Em 1912, o Decreto nº 9.620 registrou que a CVB era a única organizada conforme essa norma e a declarou de caráter nacional." | https://www.planalto.gov.br/ccivil_03/decreto/1910-1929/d9620.htm |
| 3 | CICV reconheceu a CVB em 16/03/1912 | **Confirmada** (Estatuto, art. 7º, que também dá a admissão na Federação em 17/06/1919). | Pode acrescentar a data de 1919. | https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm |
| 4 | Decreto 9.620/1912: "utilidade pública internacional" | **Corrigir** a redação (`historia:1419`; em `en/history:539`, "international public utility"). O decreto e o Estatuto dizem "utilidade internacional". | "utilidade internacional" (EN: "international utility") | https://www.planalto.gov.br/ccivil_03/decreto/1910-1929/d9620.htm |
| 5 | Decreto 23.482/1933: órgão central | **Confirmada.** | — | https://www.planalto.gov.br/ccivil_03/decreto/1930-1949/d23482.htm |
| 6 | Decreto-Lei 7.928/1945: condecorações | **Confirmada.** São 8 condecorações, entre elas a Cruz de Honra e a Cruz de Benemerência. A Câmara registra que não consta revogação expressa. | — | https://www2.camara.leg.br/legin/fed/declei/1940-1949/decreto-lei-7928-3-setembro-1945-416577-publicacaooriginal-1-pe.html |
| 7 | Lei 3.960/1961, de autoria de um presidente da CVB | **Confirmada**: lei, data e ementa. A Câmara confirma a autoria do Senador Vivaldo Lima (PL 1414/1960). Que ele presidiu a CVB vem só da própria `/historia/`. Ajuste: o emblema dessa lei é o **bastão serpentário vermelho**, não a cruz. | Explicar que é o emblema das entidades de saúde. | https://www.planalto.gov.br/ccivil_03/leis/1950-1969/l3960.htm · https://dadosabertos.camara.leg.br/api/v2/proposicoes/191826/autores |
| 8 | Utilidade pública federal c. 1969–1970 | **Confirmada**: Decreto 66.685, de 10/06/1970. O decreto individual foi revogado pelo Decreto s/nº de 27/05/1992, que ao mesmo tempo **manteve** a declaração da CVB (Anexo I). O título federal acabou com a Lei 13.204/2015. Era da CVB nacional, não da filial. | Citar só como fato histórico. | https://www.planalto.gov.br/ccivil_03/decreto/1970-1979/d66685.htm · https://legis.senado.leg.br/norma/571443/publicacao/15680738 · https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2015/lei/l13204.htm |
| 9 | Estatuto por decreto em 1971; estatutos revistos em janeiro de 2004 | **Confirmada**: Decreto 68.250/1971 e Decreto 4.948/2004. Também houve estatutos por decreto em 1969 (65.543) e 1975 (76.077). | — | https://www.planalto.gov.br/ccivil_03/decreto/1970-1979/d68250.htm · https://www.planalto.gov.br/ccivil_03/_ato2004-2006/2004/decreto/d4948.htm |
| 10 | "Hoje, o Decreto nº 8.714, de 2016, organiza a CVB" | **Corrigir.** O 8.714 foi revogado pelo Decreto 8.885, de 24/10/2016, que é o estatuto vigente. Aparece em `/historia/` (linhas 1686 e 2002) e em `/en/history/` (linhas 806 e 1122). | "Hoje, o Estatuto aprovado pelo Decreto nº 8.885, de 24 de outubro de 2016, organiza a CVB em órgão central, filiais estaduais e filiais municipais." | https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8714.htm |
| 11 | Lei municipal 5.153/2010 | **Confirmada**: Diário Oficial do Município de 03/05/2010, p. 3. A vigência no sistema da Câmara Municipal não foi conferida. | Trocar a fonte (hoje é a Wikipédia). | https://doweb.rio.rj.gov.br/portal/edicoes/download/1135 |
| 12 | Lei estadual 9.984/2023 | **Confirmada**: ALERJ, "Em Vigor", publicada em 31/03/2023. | Trocar a fonte. | https://alerjln1.alerj.rj.gov.br/contlei.nsf/c8aa0900025feef6032564ec0060dfff/592ba21d1eb9875a03258987006dbb6e?OpenDocument |
| 13 | Portaria 49/2012 criou o Centro de Memória | **Confirmada na fonte institucional da CVB nacional.** É ato interno, sem publicação em diário oficial localizada. | Trocar a fonte do Memória CVB (wixsite) pela página da nacional. | https://cruzvermelha.org.br/pb/centro-de-memoria-e-documentacao/ |
| 14 | Tombamento federal "definitivo" em 10/11/2021 | **Corrigir.** O tombamento provisório foi comunicado no DOU de 08/07/2021, e o Conselho Consultivo do IPHAN aprovou por unanimidade em 10/11/2021. A homologação ministerial, exigida pela Lei 6.292/1975, não foi localizada. O pedido da CVB é de 23/09/1988 (o dia 26 é o encaminhamento). | "Aprovado pelo Conselho Consultivo do IPHAN em 10/11/2021." Unificar `/historia/`, `/equipe.html` e EN/ES. | https://www.gov.br/iphan/pt-br/acesso-a-informacao/participacao-social/conselhos-e-orgaos-colegiados/atas-do-conselho-consultivo-do-patrimonio-cultural/de-2021-ate-2030/98a-reuniao-ordinaria-do-conselho-consultivo-10-e-11-11-2021 · https://www.planalto.gov.br/ccivil_03/leis/1970-1979/l6292.htm |
| 15 | Princípios proclamados (sem data); Resolução 6 do Conselho de Delegados de 2011 | **Confirmada.** Princípios: 20ª Conferência Internacional, Viena, 1965 (Estatuto, art. 8º); a Biblioteca do CICV registra a adoção em 08/10/1965. Resolução 6: Genebra, 26/11/2011, "Preserving the historical and cultural heritage…". | Acrescentar "Viena, 1965". | https://blogs.icrc.org/cross-files/the-fundamental-principles-of-the-international-red-cross-and-red-crescent-movement/ · https://www.icrc.org/sites/default/files/external/doc/en/assets/files/publications/icrc-002-1130.pdf |
| 16 | Filial: grupo formado em 2006 | **Sem fonte oficial confirmada.** Os dados abertos da Receita dão 21/12/2006 como início de atividade do CNPJ, o que é coerente, mas essa data mede o registro, não a formação do grupo. | Manter, com a fonte da ata ou do comprovante quando o Matheus enviar. | https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/Cnpjreva_Solicitacao.asp |

**Outras correções:**
- "Presente em 191 países" → "191 Sociedades Nacionais". Fica no box "Para entender" do modelo de notícias da Redação (PT) e em 19 páginas de `site/en/news/`, vindas de `traducoes/en/paginas.json` (EN: "191 National Societies").
- O site escreve "Henry Dunant" (`/historia/` e `/en/history/`); o CICV escreve "Henri Dunant".
- O llms.txt diz que "o valor do curso é pago depois", o que provavelmente está desatualizado.
- A FAQ e o llms.txt dizem que não há cursos gratuitos (seção 3b), e a resposta sobre cuidador de idosos no llms.txt precisa do novo texto.

---

## 7. Google para ONGs e Ad Grants

**Elegibilidade no Brasil.**
- O Brasil está na lista de países.
- Categorias aceitas, no texto oficial: "Beneficente e designada como OSCIP, CEBAS, Utilidade Pública Federal (UPF) ou Organização Social (OS); Organizações da sociedade civil; **Associação**; Fundação privada; Serviço Notarial e Registral (Cartório); Outra organização sem fins lucrativos".
- A filial é uma associação privada, então **não precisa** de CEBAS nem de OSCIP. Não citar essas certificações sem o certificado em mãos.
- A verificação é feita pela Goodstack, com o CNPJ, em 3 a 5 dias úteis. A lista de documentos pedidos para o Brasil não foi confirmada.
- Não são elegíveis: órgãos públicos, hospitais e organizações de saúde, escolas e instituições acadêmicas (os "braços" beneficentes ou filantrópicos podem ser). O CNAE da filial é de assistência social.
- Fonte: https://support.google.com/nonprofits/answer/3215869?hl=pt-BR&gl=BR

**Bloqueio possível.** Os dados abertos mostram o CNPJ da filial como **INAPTO desde 12/05/2026** (motivo 63, "omissão de declarações"). Conferir no comprovante oficial e regularizar antes de pedir. Além disso, o quadro de administradores na Receita não coincide com a diretoria publicada em `/equipe.html`: conferir com a contabilidade.

**Política de site, segundo o texto oficial** (https://support.google.com/nonprofits/answer/1657899?hl=pt-BR):
- a ONG precisa ser dona do domínio para onde os anúncios levam, sem redirecionamento;
- conteúdo original e substancial ligado à missão, e não "copiado de outras fontes sem acrescentar valor";
- nada de "Páginas que, em sua maioria, são links para outros sites ou conteúdo incorporado (como playlists de vídeo) sem texto de apoio";
- missão clara, endereço físico e número de registro (CNPJ) no site;
- "Atividades comerciais limitadas (…) são aceitáveis, mas não devem ser o enfoque do site. É preciso divulgar claramente como esses recursos apoiam seus programas sem fins lucrativos." Informação financeira clara;
- nada de páginas "em construção" nem de excesso de PDFs no lugar de HTML;
- links funcionando (inclusive os de doação) e HTTPS.

**Riscos hoje:**
1. A venda está em primeiro plano: faixa do topo, primeiro botão e 17 das 26 perguntas da FAQ. Quem digita `/cursos/` cai direto na matrícula paga.
2. A venda é de outra pessoa jurídica, e a home, o JSON-LD e o llms.txt não dizem isso.
3. Não há explicação do financiamento (segunda etapa, na transparência; precisa estar no ar antes do pedido).
4. A transparência está vazia e o acervo está "Em breve".
5. Links de doação levam a páginas com erro 503.
6. A home PT não diz "sem fins lucrativos".
7. O CNPJ aparece como inapto.
8. O dono do domínio não foi confirmado, e o domínio vence em 28/06/2027.
9. Os primeiros socorros pagos da escola e os gratuitos da filial podem se confundir.
10. Se as páginas institucionais novas forem feitas só de links e trechos do CICV ou do Planalto, podem ser reprovadas.

A política não trata de venda feita por outra pessoa jurídica no mesmo domínio: como o revisor avalia isso é **não confirmado**.

**O que fazer:**
1. Resolver o CNPJ, confirmar o dono do domínio e ligar a renovação automática.
2. Na segunda etapa, explicar o financiamento em `/transparencia/`. Publicar as páginas de missão em HTML (cursos gratuitos, voluntariado, princípios, base legal), cada uma com mais texto próprio da filial do que links.
3. Pôr a frase de quem vende em todo o site, tirar a venda do topo da home e mudar o destino de `/cursos/`.
4. Preencher ou esconder as páginas vazias e consertar ou tirar os links de doação.
5. No cadastro, escolher "Associação".
6. Quando houver conta, os anúncios devem levar só a páginas da ONG; as palavras-chave de "primeiros socorros" levam só à página gratuita. A venda dos cursos, se for anunciada, vai numa conta paga da empresa.
7. As regras de campanha listadas no levantamento (CTR mínimo de 5%, conversões mensais, sitelinks, lances por conversão) não foram relidas na política de conformidade do Ad Grants (não confirmado). Conferir antes de abrir a conta.

O Google não garante aprovação.

---

## 8. Decisões e documentos que dependem do Matheus

**Urgentes (travam o Google para ONGs):**
1. Comprovante oficial do CNPJ 08.560.973/0001-97 e regularização com a contabilidade, se estiver inapto. Atualizar na Receita os administradores, se estiverem desatualizados.
2. Quem é o titular do domínio cruzvermelhariodejaneiro.org. Precisa ser a ONG (CNPJ 08.560.973/0001-97). Ligar a renovação automática antes de 28/06/2027.

**Sobre a escola:**

3. O vínculo entre a filial e a O-CVB Filial Rio de Janeiro Ensino Ltda: se a filial é sócia ou controladora, se há contrato e se e como o resultado chega à filial. A escola é uma unidade de ensino vinculada à filial nos termos do art. 22, III do Estatuto, com estatuto social próprio, ou uma empresa independente? Há autorização do Órgão Central? O espelho da Receita mostra um único sócio, pessoa física, e a filial fora do quadro; nome e sobrenome dele aparecem entre os diretores de `/equipe.html` (**A CONFIRMAR**). Isso define o JSON-LD agora e o texto do financiamento na etapa da transparência (decisão do Matheus: tratar lá, num segundo momento). Confirmar também a razão social no cartão CNPJ oficial ("O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA"; o "EPP" é o porte).
4. Se o Órgão Central autorizou a empresa a usar "CVB" no nome e o emblema nas páginas de venda (Decreto 2.380/1910; Estatuto, art. 9º, §2º e art. 69, V; Estudo do CICV, Questão 36, que é recomendação).
5. Quem emite o certificado dos cursos pagos (a ONG ou a empresa); quem fatura os treinamentos corporativos (NR e Lei Lucas), o que define se a frase padrão pode dizer que a filial "não vende cursos"; quem é o controlador LGPD dos dados de compra; se o e-mail e o WhatsApp compartilhados entre ONG e empresa são intencionais.

**Sobre os cursos gratuitos e o voluntariado:**

6. O nome no site: "Cursos gratuitos" ou "Formações gratuitas", em vez de "cursos livres".
7. Para cada tipo de formação (primeiros socorros e voluntariado): datas, frequência, forma de inscrição, carga horária, certificado, se é aberta ao público ou só para voluntários, e quem ministra. Esses dados vão no quadro de comparação com o curso pago "Primeiros Socorros Básico". Também: o canal para registrar interesse (formulário ou WhatsApp do voluntariado).
8. Material da formação El Niño (data, local, fotos autorizadas e se foi aberta ao público ou só para voluntários) para virar notícia e exemplo na página.
9. Página própria de voluntariado ou manter só o formulário externo. Termo de adesão (Lei 9.608/1998).

**Documentos:**

10. Estatuto da filial em PDF, atas, balanços e parcerias, para `/transparencia/` e `/governanca/`. Se há parcerias vigentes com o poder público: publicá-las é obrigação do art. 11 da Lei 13.019/2014. Se não há, tirar a promessa do card da home e do llms.txt.
11. Data de fundação da filial (ata) e endereço oficial padronizado.
12. Certificações e registros que a filial tem de fato, com o certificado em mãos (por exemplo CEBAS ou OSCIP, se houver).
13. O canal oficial para denúncia de uso indevido do emblema (filial ou Órgão Central).
14. O nome principal da entidade no site e no Google (seção 5.1). A base formal é o Estatuto, art. 13, §2º.

**Fora do site:**

15. Quem vai completar o item Q141635343 no Wikidata, declarando o vínculo com a filial, ou se será pedido a um terceiro.
16. Pedir à CVB nacional: link para o site da filial, e-mail atual e presidente na página da filial; restaurar as páginas "__trashed", inclusive a da Comissão de Ética; limpar o spam da página de primeiros socorros.
17. Se já existe Perfil da Empresa no Google da filial e quem gerencia.
18. Doações (fora do ar desde 25/09/2026): reativar ou tirar os links.
19. Parecer jurídico antes de o site falar em "crime" de uso do emblema.
20. Uma notícia não oficial (Diário do Rio, 11/09/2026) fala em leilão do prédio pela Justiça do Trabalho. Não foi verificada. Convém confirmar antes de reescrever os trechos sobre o Palácio.

**Sobre textos e endereços:**

21. Princípios em EN/ES: opção A (citar o texto oficial do CICV com crédito e link) ou opção B (tradução livre do art. 8º do Estatuto, com link para o CICV).
22. Endereço `/cursos/`: página que reúne cursos gratuitos e pagos, ou redirecionar para `/cursos-gratuitos/`.

---

## 9. Ordem sugerida

1. **Destravar e corrigir o que está errado.**
   - Respostas dos itens 1 a 7 da seção 8.
   - No site: Decreto 8.714 → 8.885, Decreto 2.380, "utilidade internacional", tombamento, FAQ e llms.txt sobre cursos gratuitos (linhas 72 e 85 do repositório), "sem fins lucrativos" na home, frase de quem vende no rodapé e na home, links de doação.
   - Refazer o texto da seção Institucional (já com as fotos novas): "associação privada sem fins lucrativos", links para princípios e base legal, cursos gratuitos e o tombamento, sobre a versão local (`site/index.html`, por volta das linhas 1574 a 1577). Há commits locais marcados "não publicado" nessa seção (fotos novas, retrato do presidente, ajuste na equipe). Trabalhar junto com eles, para não haver conflito nem regressão. Os números de linha citados neste mapa podem ter mudado.
   - Ligar a renovação automática do domínio.
2. **Páginas centrais e entidade.**
   - `/cursos-gratuitos/` (primeira versão já, sem calendário), `/principios-fundamentais/` e `/base-legal/`.
   - Ligar o slide El Niño a `/cursos-gratuitos/` e trocar o texto alternativo.
   - Ajustar o `site/.htaccess`: linha 30 (voluntariado) e linha 26 (`/cursos/`).
   - JSON-LD da ONG com a escola separada (home e matrícula) e o vendedor nas ofertas.
   - Menu e rodapé "Institucional", sitemap, llms.txt.
3. **Completar o conjunto.**
   - `/emblema/`, `/movimento-internacional/`, `/direito-internacional-humanitario/`, `/governanca/`, `/voluntariado/` e `/quem-somos/`.
   - Preencher `/transparencia/`, com a explicação do financiamento (segunda etapa).
   - Acompanhar o PL 8.754/2017 e revisar `/emblema/` se ele virar lei.
4. **Idiomas.**
   - EN/ES de princípios, emblema e Movimento, conforme a decisão sobre a opção A ou B.
   - Nota de quem vende nas notícias EN que promovem cursos (`traducoes/en/paginas.json`) e "191 National Societies".
   - Criar `/es/historia/` e pôr History no menu EN.
   - Ajustar o hreflang.
5. **Sinais externos e Google.**
   - Wikidata, CVB nacional, Wikipédia e Perfil da Empresa.
   - Acompanhar os dados estruturados das páginas novas no Search Console.
   - Depois, cadastro no Google para ONGs, como "Associação", e Ad Grants com anúncios só para páginas da ONG.

---

## 10. Fontes

**Legislação federal (Planalto, Câmara, Senado)**
- Decreto 8.885/2016 (Estatuto vigente): https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8885.htm · https://www2.camara.leg.br/legin/fed/decret/2016/decreto-8885-24-outubro-2016-783829-publicacaooriginal-151309-pe.html · cópia em PDF no site da CVB (não é a fonte principal): https://cruzvermelha.org.br/wp-content/uploads/2017/01/Estatuto-da-Cruz-Vermelha-Brasileira_AF.pdf
- Decreto 8.714/2016 (revogado): https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2016/decreto/d8714.htm
- Decreto 2.380/1910: https://www.planalto.gov.br/ccivil_03/decreto/historicos/dpl/dpl2380-1910.htm · https://www2.camara.leg.br/legin/fed/decret/1910-1919/decreto-2380-31-dezembro-1910-578511-publicacaooriginal-101444-pl.html
- Decreto 9.620/1912: https://www.planalto.gov.br/ccivil_03/decreto/1910-1929/d9620.htm
- Decreto 23.482/1933: https://www.planalto.gov.br/ccivil_03/decreto/1930-1949/d23482.htm
- Decreto-Lei 426/1969: https://www.planalto.gov.br/ccivil_03/decreto-lei/1965-1988/del0426.htm
- Estatutos anteriores: https://www.planalto.gov.br/ccivil_03/decreto/1950-1969/d65543.htm · https://www.planalto.gov.br/ccivil_03/decreto/1970-1979/d68250.htm · https://www.planalto.gov.br/ccivil_03/decreto/1970-1979/d76077.htm · https://www.planalto.gov.br/ccivil_03/_ato2004-2006/2004/decreto/d4948.htm
- Decreto 66.685/1970: https://www.planalto.gov.br/ccivil_03/decreto/1970-1979/d66685.htm · Decreto s/nº de 27/05/1992: https://legis.senado.leg.br/norma/571443/publicacao/15680738
- Decreto-Lei 7.928/1945: https://www2.camara.leg.br/legin/fed/declei/1940-1949/decreto-lei-7928-3-setembro-1945-416577-publicacaooriginal-1-pe.html
- Lei 3.960/1961: https://www.planalto.gov.br/ccivil_03/leis/1950-1969/l3960.htm · https://www2.camara.leg.br/legin/fed/lei/1960-1969/lei-3960-20-setembro-1961-353692-norma-pl.html · https://dadosabertos.camara.leg.br/api/v2/proposicoes/191826/autores
- PL 8.754/2017: https://www.camara.leg.br/proposicoesWeb/fichadetramitacao?idProposicao=2153731 · andamento (dados abertos da Câmara): https://dadosabertos.camara.leg.br/api/v2/proposicoes/2153731
- Convenções e Protocolos: https://www.planalto.gov.br/ccivil_03/decreto/1950-1969/d42121.htm · https://www.planalto.gov.br/ccivil_03/decreto/1990-1994/d0849.htm · https://www.planalto.gov.br/ccivil_03/_ato2007-2010/2010/decreto/d7196.htm
- Comissão Nacional de DIH (Decreto 11.752/2023; só o CICV é convidado, art. 4º, §4º): https://www.planalto.gov.br/ccivil_03/_ato2023-2026/2023/decreto/d11752.htm
- Lei 9.279/1996 (Propriedade Industrial): https://www.planalto.gov.br/ccivil_03/leis/l9279.htm
- Lei 13.019/2014 (MROSC; art. 11, obrigação de divulgar as parcerias): https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/lei/l13019.htm · Lei 13.204/2015: https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2015/lei/l13204.htm
- Lei 9.608/1998: https://www.planalto.gov.br/ccivil_03/leis/l9608.htm · Código Civil: https://www.planalto.gov.br/ccivil_03/leis/2002/l10406compilada.htm
- Lei 9.610/1998 (direitos autorais): https://www.planalto.gov.br/ccivil_03/leis/l9610.htm
- Lei 6.292/1975: https://www.planalto.gov.br/ccivil_03/leis/1970-1979/l6292.htm · DL 25/1937: https://www.planalto.gov.br/ccivil_03/decreto-lei/del0025.htm
- LC 187/2021: https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp187.htm · Lei 9.790/1999: https://www.planalto.gov.br/ccivil_03/leis/l9790.htm

**Rio de Janeiro (município e estado) e IPHAN**
- Lei municipal 5.153/2010 (Diário Oficial do Município): https://doweb.rio.rj.gov.br/portal/edicoes/download/1135
- Lei estadual 9.984/2023 (ALERJ): https://alerjln1.alerj.rj.gov.br/contlei.nsf/c8aa0900025feef6032564ec0060dfff/592ba21d1eb9875a03258987006dbb6e?OpenDocument
- Ata da 98ª reunião do Conselho Consultivo do IPHAN: https://www.gov.br/iphan/pt-br/acesso-a-informacao/participacao-social/conselhos-e-orgaos-colegiados/atas-do-conselho-consultivo-do-patrimonio-cultural/de-2021-ate-2030/98a-reuniao-ordinaria-do-conselho-consultivo-10-e-11-11-2021

**Movimento (CICV e Conferência)**
- Princípios: https://www.icrc.org/pt/nossos-principios-fundamentais · https://www.icrc.org/en/our-fundamental-principles · https://www.icrc.org/es/nuestros-principios-fundamentales
- Folheto 4046/007 (PT, 2014; diz "189 Sociedades Nacionais", número desatualizado): https://www.icrc.org/sites/default/files/external/doc/pt/assets/files/publications/icrc-007-4046.pdf · página: https://www.icrc.org/pt/publication/os-principios-fundamentais-do-movimento-internacional-da-cruz-vermelha-e-do-crescente
- Estatutos do Movimento: https://www.icrc.org/sites/default/files/external/doc/en/assets/files/other/statutes-en-a5.pdf · https://www.icrc.org/sites/default/files/external/doc/es/assets/files/other/statutes-es-a5.pdf
- Proclamação de 1965: https://blogs.icrc.org/cross-files/the-fundamental-principles-of-the-international-red-cross-and-red-crescent-movement/ · https://www.icrc.org/en/article/fundamental-principles-red-cross-commentary
- Emblemas: https://www.icrc.org/pt/nossos-emblemas · https://www.icrc.org/pt/direito-e-politicas/uso-de-emblemas · https://www.icrc.org/pt/publication/emblemas-da-cruz-vermelha-e-do-crescente-vermelho · https://www.icrc.org/en/article/history-emblems
- Movimento e Conferência: https://www.icrc.org/pt/sobre-o-movimento-internacional-da-cruz-vermelha-e-do-crescente-vermelho · https://www.icrc.org/pt/conferencia-internacional · https://rcrcconference.org/about/ · https://rcrcconference.org/council-of-delegate/
- DIH: https://www.icrc.org/sites/default/files/document/file_list/o_que_e_o_dih.pdf · https://www.icrc.org/pt/convencoes-de-genebra-e-o-direito · https://www.icrc.org/pt/publication/principios-basicos-do-direito-internacional-humanitario
- Resolução 6/2011: https://www.icrc.org/sites/default/files/external/doc/en/assets/files/publications/icrc-002-1130.pdf
- Estudo sobre o uso dos emblemas (2011; recomendações, não norma): https://www.icrc.org/sites/default/files/external/doc/en/assets/files/publications/icrc-001-4057.pdf
- Termos de uso do CICV: https://www.icrc.org/pt/copyright-e-termos-de-uso
- Base de prática nacional do CICV: https://ihl-databases.icrc.org/en/national-practice/law-no-3960-use-emblem-national-organizations-health-and-others-1961
- FICV: https://www.ifrc.org/who-we-are/international-red-cross-and-red-crescent-movement/fundamental-principles. Não conferida: o site bloqueia acesso automático (erro 403). Abrir no navegador antes de publicar e usar sempre como link secundário, depois do CICV.

**CVB nacional**
- https://cruzvermelha.org.br/pb/ · página da filial: https://cruzvermelha.org.br/pb/filiais/rio-de-janeiro/ · https://cruzvermelha.org.br/pb/fundacao-e-reconhecimento/ · https://cruzvermelha.org.br/pb/centro-de-memoria-e-documentacao/
- Código Nacional de Ética e Conduta (PDF): https://cruzvermelha.org.br/wp-content/uploads/2017/10/C%C3%B3digo-Nacional-de-%C3%89tica-e-Conduta.pdf
- Comissão de Ética (sob caminho "__trashed"; pode quebrar): https://cruzvermelha.org.br/pb/institucional__trashed/comissao-de-etica/

**Wikidata e Wikimedia**
- Filial: https://www.wikidata.org/wiki/Q141635343 · CVB: https://www.wikidata.org/wiki/Q47072433 · Movimento: https://www.wikidata.org/wiki/Q7178 · FICV: https://www.wikidata.org/wiki/Q1069175 · Princípios: https://www.wikidata.org/wiki/Q19284407 · DIH: https://www.wikidata.org/wiki/Q381148
- Termos de Uso da Wikimedia (declaração de contribuições pagas): https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use

**Google e schema.org**
- https://developers.google.com/search/docs/appearance/structured-data/organization · https://developers.google.com/search/docs/appearance/site-names · https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data · https://developers.google.com/search/docs/appearance/structured-data/event · https://developers.google.com/search/docs/appearance/structured-data/job-posting · https://developers.google.com/search/docs/appearance/structured-data/course · https://developers.google.com/search/updates · https://developers.google.com/search/docs/essentials/spam-policies · https://developers.google.com/search/docs/crawling-indexing/canonicalization-troubleshooting
- Google para ONGs: https://support.google.com/nonprofits/answer/1657899?hl=pt-BR · https://support.google.com/nonprofits/answer/3215869?hl=pt-BR&gl=BR
- Perfil da Empresa e painel: https://support.google.com/business/answer/3038177 · https://support.google.com/knowledgepanel/answer/7534902
- schema.org: https://schema.org/NGO · https://schema.org/nonprofitStatus · https://schema.org/NonprofitType · https://schema.org/subOrganization · https://schema.org/seller · https://schema.org/ownershipFundingInfo · https://schema.org/hasCertification · https://schema.org/ethicsPolicy · https://schema.org/publishingPrinciples · https://schema.org/Legislation · https://schema.org/DefinedTermSet · https://schema.org/isBasedOn

**Receita Federal e registro do domínio**
- Comprovante de situação cadastral (exige captcha; não aberto neste levantamento): https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/Cnpjreva_Solicitacao.asp
- Espelho dos dados abertos (BrasilAPI, não oficial): https://brasilapi.com.br/api/cnpj/v1/08560973000197 · https://brasilapi.com.br/api/cnpj/v1/67733551000135
- Registro público do domínio (RDAP): https://rdap.publicinterestregistry.org/rdap/domain/cruzvermelhariodejaneiro.org

**Arquivos do repositório citados:** `site/index.html`, `site/.htaccess`, `site/faq-home.json`, `scripts/gerar_faq_home.py`, `site/llms.txt`, `site/chat/chat.js`, `site/historia/index.html`, `site/en/history/index.html`, `site/equipe.html`, `site/matricula-cursos-presenciais/index.html`, `site/matricula-cursos-presenciais/cursos.json`, `traducoes/en/paginas.json`, `scripts/gerar_sitemaps.py`, `README.md`.

---

**Como este mapa foi feito**
- Cinco levantamentos em paralelo (site atual, base legal, Movimento e princípios, Google e Ad Grants, comparação com outras Sociedades Nacionais), verificação adversarial de cada norma na fonte oficial, síntese e revisão por um crítico de completude e exatidão.
- Só leitura: nenhum arquivo do site foi alterado por este levantamento. Números de linha citados podem ter mudado com os commits locais da seção Institucional.
- O nome do sócio da Ltda não foi conferido por inteiro: só que nome e sobrenome aparecem em `/equipe.html` (por isso **A CONFIRMAR**).
