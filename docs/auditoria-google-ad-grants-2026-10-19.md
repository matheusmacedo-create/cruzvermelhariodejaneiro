# Auditoria preliminar — Google Ad Grants (19/10/2026)

**Organização:** Cruz Vermelha Brasileira — Filial do Estado do Rio de Janeiro  
**Domínio analisado:** https://cruzvermelhariodejaneiro.org/  
**Status no painel Google for Nonprofits:** "Sua solicitação de ativação precisa de ajustes". O Google apontou carregamento, navegação, conteúdo atualizado e chamadas para ação. **O pedido não deve ser tratado como aprovado ou aguardando aprovação final.**

## Constatado no repositório (branch main consultada em 19/10/2026)

- `site/index.html` apresenta missão, identificação institucional, endereço, CNPJ, cursos, voluntariado, transparência, chamadas para ação e links internos.
- Há imagens responsivas em WebP, `loading="lazy"` nas imagens secundárias, preloads de imagem principal, estrutura responsiva e metadados/JSON-LD.
- A raiz utiliza HTTPS/canonical e `site/.htaccess` contém regras para o redirecionamento de www e endereços antigos.
- A nova navegação da home inclui "Doe", mas a versão indexada/capturada externamente não necessariamente coincide com a última versão do repositório. **Confirmar deploy e limpeza de cache antes da reanálise.**
- `site/doe/index.html` contém um aviso "Modo de teste", porém o elemento tem o atributo HTML `hidden`. Em `site/doe/static/doe.js`, a exibição ocorre somente se a API fornecer `teste`. A mera presença do texto no HTML **não prova** que o modo de teste esteja ativo.
- Dois CTAs de doação na home continham `utm_source=site&utm_medium=home`. Este PR troca por `?de=home` para não quebrar a atribuição de campanhas, especialmente a futura origem Ad Grants; o JavaScript de doação reconhece o parâmetro `de`.

## Não verificado — não presumir aprovação

- Pontuação atual de PageSpeed/Lighthouse em **celular** e desktop (a consulta externa não retornou relatório numérico).
- TTFB, LCP, INP, CLS, tamanho total de transferências, cache e compactação HTTP em produção.
- Comportamento real de todos os menus, links, formulários e CTAs em navegador móvel.
- Fluxo de doação de ponta a ponta (incluindo valor cobrado versus valor escolhido) — exige teste seguro coordenado com a equipe, **sem cobrar cartão real**.
- Qual versão de cada arquivo está ativa no servidor Hostinger; o GitHub não publica automaticamente todas as alterações.
- Resultado individual da avaliação do Google, além da mensagem resumida recebida.

## Checklist antes de solicitar nova análise

1. **Publicação:** comparar a home em produção com `site/index.html`, garantir que CTA "Doe" e navegação estejam visíveis em desktop e celular; publicar mudanças pendentes pelo fluxo do repositório e limpar o cache na Hostinger.
2. **Performance:** executar https://pagespeed.web.dev/ em `/`, `/doe/`, `/quem-somos/`, `/voluntariado/` e `/matricula-cursos-presenciais/` com prioridade para **celular**. Registrar data, nota, LCP, INP, CLS, recomendações e prints. Corrigir o que o relatório realmente mostrar.
3. **Navegação:** conferir menu principal em celular e desktop, contatos, links institucionais, formulários, notícias, matrícula, botão Doe e redirecionamentos. Corrigir 404 e páginas vazias.
4. **Conteúdo:** confirmar missão, programas, atividade recente, equipe, endereço e CNPJ corretos e coerentes. Evitar páginas "em construção", promessas ainda não disponíveis e conteúdo de terceiros sem valor agregado.
5. **Doações:** confirmar se API retorna `teste`, se os valores e frequência se comportam como anunciados e se a confirmação de pagamento ocorre no ambiente seguro da instituição. Se não estiver em produção, não anunciar um checkout funcional até finalizar.
6. **Elegibilidade de URLs:** usar o domínio oficial aprovado nas URLs de destino dos anúncios; conferir que páginas de campanha não redirecionam automaticamente para outro domínio. Esclarecer vínculos da escola com a missão e como sua atividade apoia a filial.
7. **Conformidade:** confirmar HTTPS em todo o site, domínio sob controle da instituição e ausência de publicidade invasiva.
8. **Nova solicitação:** depois de implantar, testar e registrar as evidências, reabrir o painel Google para Organizações sem Fins Lucrativos e **reenviar a ativação** para avaliação. Não supor que o Google reenviará automaticamente.

**Política oficial (fonte primária):** https://support.google.com/nonprofits/answer/1657899?hl=pt-BR

**Nota:** Esta auditoria é preliminar, técnica e baseada no repositório e em páginas publicamente acessíveis. Não substitui PageSpeed real nem teste manual em dispositivo móvel.
