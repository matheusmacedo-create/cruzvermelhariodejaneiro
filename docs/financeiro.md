# Relatório das contas para o financeiro (`/financeiro/`)

Pedido do Matheus (09/11/2026): o relatório mensal das contas da CVB-RJ na Unicopag (até então um PDF) virou uma página
para o financeiro abrir no celular ou no computador, com botões de ajuda (**?**) ao lado de cada número e palavra, o
botão "Como ler este relatório", menu por conta e glossário. Fundo **sempre branco** (sem modo escuro, mesmo com o
celular no escuro) e **letra grande** (texto a 20px, botões de ajuda de 36px), pedido do Matheus para quem lê com
dificuldade; mantenha assim nos meses seguintes. Mesmo modelo do link da coordenação do voluntariado
(`docs/voluntariado.md`): **sem senha e sem login**, a chave vai no próprio link.

## Link

`https://cruzvermelhariodejaneiro.org/financeiro/?k=<FINANCEIRO_CHAVE>` abre o relatório mais recente.
`&mes=AAAA-MM` abre um mês específico. Com mais de um mês publicado, uma faixa escura no topo leva aos outros meses.

- **Chave**: `FINANCEIRO_CHAVE` (32+ caracteres) em `financeiro/config.php`, **só no servidor** (fora do Git; modelo em
  `site/financeiro/config.example.php`; o `.htaccess` nega o acesso ao arquivo, e `scripts/publicar_hostinger.sh` se recusa
  a enviá-lo).
- **Sem a chave, com a chave errada ou sem `config.php`**: 404, a mesma página de um endereço que não existe.
  Não há contador de tentativas (a página não usa banco); com 32+ caracteres aleatórios, adivinhar a chave não é viável.
- **Quem tem o link entra.** Se ele vazar, troque a chave no `config.php` e mande o link novo: o antigo para na hora.
- **Privacidade**: `noindex`, `no-referrer`, `X-Frame-Options: DENY`, `Cache-Control: no-store`. Os arquivos de
  `relatorios/` não abrem direto pelo endereço (`relatorios/.htaccess` nega tudo); só saem pelo `index.php`.

## Arquivos

| No Git | Só no servidor |
| --- | --- |
| `site/financeiro/index.php` (confere a chave e serve o mês) | `financeiro/config.php` (a chave) |
| `site/financeiro/.htaccess`, `site/financeiro/relatorios/.htaccess` | `financeiro/relatorios/AAAA-MM.html` (o relatório de cada mês, com os valores das contas) |
| `site/financeiro/config.example.php` | |

Os relatórios ficam fora do Git porque trazem os saldos e as vendas das contas.

## Mês novo

1. A Unicopag gera o PDF do mês (o de novembro: "Relatório das contas CVB na Unicopag", 1º a 9/11/2026, números
   tirados do sistema em 09/11/2026 às 11h22).
2. Monte `site/financeiro/relatorios/AAAA-MM.html` a partir do mês anterior, trocando os números e os textos. Confira as
   somas (disponível = ficou do mês − taxa de devolvida + dinheiro de antes − separado para saque; ficou = pago −
   acréscimo − taxas; os percentuais) e os textos dos botões de ajuda (objeto `AJUDA` no fim do arquivo), que citam valores.
3. Publique só o arquivo novo: `scripts/publicar_hostinger.sh site/financeiro/relatorios/AAAA-MM.html`. O mesmo link
   passa a abrir no mês novo, e o anterior continua em `&mes=`.

## Publicação

`scripts/publicacao-financeiro.txt`. Publicado pela primeira vez em 09/11/2026.
