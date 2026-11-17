# Opcionais do checkout da matrícula — 17/11/2026

Pedido do Matheus: o checkout dos cursos presenciais
(`/matricula-cursos-presenciais/checkout/`) oferece os dois opcionais em todas as compras,
**inclusive com cupom**:

| Opcional | Valor | Configuração (config.php privado) |
| --- | --- | --- |
| Quero cobrir os custos de processamento | 5% do valor do curso | `TAXA_PIX_PCT` / `TAXA_CARTAO_PCT` (+ `TAXA_*_FIXA`), as mesmas de antes |
| Quero ajudar na divulgação dos cursos | 10% do valor do curso | `DIVULGACAO_PCT` (padrão 10; 0 desliga a oferta) |

O **valor do curso** é o da compra, por pessoa: taxa de inscrição + matrícula na opção 1,
só a taxa na opção 2 e o valor do cupom quando há cupom. No cupom de grupo, cada pessoa paga
os opcionais sobre o próprio valor. Exemplos: R$ 150 com o cupom CURATIVOS dá + R$ 7,50 e + R$ 15,00,
total de R$ 172,50. Já R$ 249 a preço cheio dá + R$ 12,45 e + R$ 24,90, e só a taxa (R$ 99) dá + R$ 4,95 e + R$ 9,90.

## O que mudou em relação a antes

- Os custos de processamento eram 5% **só da taxa de inscrição** (decisão E4) e sumiam com cupom.
  Agora são 5% do valor do curso e aparecem também com cupom.
- A contribuição para a divulgação tinha sido desligada na revisão do Ad Grants de 19/10/2026
  (`docs/revisao-ad-grants-2026-10-19.md`). Ela volta por decisão do Matheus: a escola paga os anúncios
  direto à Meta, e o Google Ad Grants não financia a escola. Agora é um percentual, não mais o valor fixo
  antigo: a chave `DIVULGACAO_CENTAVOS` continua ignorada, e `divulgacao_centavos` fica em 0 no
  `info.php` e no `cursos.json`, para as páginas antigas.
- O servidor faz a conta (`mcp_compra_opcionais`, em `api/lib/compra.php`), e a página repete a mesma
  conta a partir de `taxa_regra` e `divulgacao_pct` do `info.php`. A contribuição só é cobrada se o
  valor que a página mostrou for o de agora; se não for, devolve um 422 com o campo `divulgacao`.
- Comprovante, e-mails, aviso à secretaria e reembolso já tratavam os dois valores. O e-mail de
  pagamento passa a dizer "custos de processamento do pagamento à escola".

## Validação (17/11/2026)

- `php scripts/testar_checkout.php`: 891 testes, 0 falhas (inclui `testar_pagar_tudo.php`, com
  casos novos de cupom). `testar_pagar_tudo_emails.php`: 186/0. `testar_doacao.php`: 70/0.
- `python3 scripts/conferir_ad_grants.py`: 0 falhas (a regra que proibia a divulgação foi retirada).
- Chromium em 390 × 844, com a API simulada: com cupom, preço cheio e só a taxa, os valores da tela
  são iguais aos do servidor. Não houve erro de JavaScript nem rolagem horizontal. Nenhum pagamento
  foi criado.
- **Não testado:** pagamento real na Unicopag e o arquivo publicado na Hostinger.

## Publicação (Hostinger)

Publique primeiro o backend e logo em seguida a página. Se só um dos dois estiver no ar, quem marcar
um opcional recebe "o valor mudou" e a página corrige o total. Nada é cobrado errado, mas é um atrito.

1. `site/matricula-cursos-presenciais/api/lib/config.php`, `api/lib/compra.php`, `api/lib/cupons.php`,
   `api/lib/email.php`, `api/pagamentos.php`, `api/parcelas.php`, `api/info.php`
2. `static/checkout.js` e as páginas que o referenciam com a versão nova: `checkout/`, `pendente/`,
   `parabens/`, `horarios/`, `conferir/` e `comparecimento/` (`index.html` de cada uma)
3. Limpar o cache da Hostinger e conferir `api/info.php`: ele deve trazer `divulgacao_pct: 10` e `taxa_regra`.
