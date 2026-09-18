# site/ — páginas estáticas do site principal

Espelho do `public_html` de `cruzvermelhariodejaneiro.org` (Hostinger, conta `u448697994`)
restrito às páginas mantidas à mão: `index.html`, `cursos.html`, `doacao.html`,
`equipe.html` e `campanha-agasalho.html`, mais `sitemap-escola.xml`, `sitemap-paginas.xml` e a
pasta `matricula-cursos-presenciais/` (página gerada por `scripts/gerar_matricula_presencial.py`;
edite `cursos.json` ou o gerador, nunca o `index.html` dela).

**Não** estão aqui, de propósito:

- `noticias/`, `termos/`, `privacidade/`, `sitemap.xml` e `robots.txt`: gerados e enviados
  por FTP pela Redação (projeto separado). Qualquer edição feita aqui seria sobrescrita.
- `assets/` (imagens): continuam só na Hostinger.
- `links/`, `link/`, `projetocores/`, `doe/`: subprojetos próprios dentro do mesmo
  `public_html`.

## Publicar uma alteração

1. Edite o arquivo em `site/`.
2. Gere as credenciais temporárias de upload na API da Hostinger ("Generate upload URL"
   do website) e exporte `HOSTINGER_TUS_URL`, `HOSTINGER_TUS_AUTH` e
   `HOSTINGER_TUS_AUTH_REST`.
3. `scripts/publicar_hostinger.sh site/cursos.html` (um ou mais arquivos).
4. Limpe o cache do site (hPanel → Website → Cache, ou "clear website cache" na API) e
   confira no navegador.

O primeiro commit deste repositório é a cópia fiel de 16/09/2026; o histórico do git mostra
exatamente o que mudou desde então.
