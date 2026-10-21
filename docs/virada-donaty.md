# Virada das doações para a Donaty: roteiro

Status em 21/10/2026: **código e textos prontos no branch, nada publicado, gateway ainda na Unicopag.**
Fontes: README ("Doações na Donaty"), `docs/doacoes-donaty-textos-juridicos.md` (fatos F1 a F9),
`scripts/publicacao-donaty.txt` (lista de arquivos).

## O que já está feito (no Git, sem publicar)

- Backend: `DOACAO_GATEWAY` (`unicopag` padrão ou `donaty`) em `/doe/api/config.php`; Donaty com base `api.donaty.com.br/api`,
  `Authorization: Bearer`, só PIX; o servidor recusa cartão e a página esconde o botão; hash do postback aceito em `data`/`transaction`.
- Textos: privacidade, termos, FAQ de `/doe/`, transparência, home, FAQ, chat, bio, llms.txt, campanha do agasalho e a página de
  doação em inglês e espanhol dizem **"PIX"** e **Donaty** (as inscrições continuam com a ÚnicoPag e o cartão).
- `{virada}`: as políticas dizem "doações feitas até {virada} foram processadas pela ÚnicoPag". `scripts/gerar_politicas.py` **se recusa a
  rodar** enquanto `DATA_VIRADA_DOACAO` não estiver definida, para nunca publicar o marcador solto.
- Testes locais: `php scripts/testar_doacao_donaty.php` (19), `testar_doacao.php` (59), `testar_checkout.php` (871).

## O que falta antes de ligar (precisa de gente)

1. **F1 a F9** de `docs/doacoes-donaty-textos-juridicos.md` respondidos pela Donaty. Decidir os acréscimos opcionais
   (razão social e CNPJ, "instituição autorizada pelo Banco Central", repasse, nome no extrato, país dos dados).
2. **Chave nova** gerada no painel da Donaty (a antiga foi colada no chat; trate como comprometida) e **senha do painel trocada**.
3. **Cobrança PIX de teste real** (R$ 5, paga pela própria filial), para provar: (a) que os campos extras do pedido
   (CPF, telefone, `metadata`, `expire_in_days`, `origin`) são aceitos; (b) os nomes de `payment_status` (desconhecido vira
   "pendente": um PIX pago **não confirmaria**); (c) o formato do postback `transaction.updated`.
4. Revisão final dos textos por quem responde juridicamente pela filial.

## Passo a passo no dia

**Não mesclar o branch na `main` antes disto:** as fontes já dizem Donaty/PIX e a `main` publica em produção.

1. **Backend primeiro (continua na Unicopag).** Publicar o bloco "backend" de `scripts/publicacao-donaty.txt`
   (`doacoes.php`, `info.php`, `webhook.php`, `lib/doacao.php`, `unicopag.php`, `doe.js`) e limpar o cache.
   Conferir `GET /doe/api/info.php`: deve trazer `"metodos":["pix","cartao"]`. Rodar uma doação-teste na Unicopag só se quiser provar
   que nada mudou.
2. **Config no servidor** (`/doe/api/config.php`, fora do Git; editar pelo gerenciador de arquivos da Hostinger):
   ```php
   'DOACAO_GATEWAY'  => 'donaty',
   'DONATY_API_KEY'  => '<chave nova dnt_sk_…>',
   'DONATY_BASE_URL' => 'https://api.donaty.com.br',
   ```
   Conferir `info.php`: agora `"metodos":["pix"]`.
3. **Teste real** (R$ 5, PIX, CPF válido da filial): a página gera o QR, o `obrigado` confirma sozinho depois do pagamento, o e-mail de
   comprovante chega, a linha em `mcp_doacoes` fica `pago` e `unicopag_status` mostra o nome do status da Donaty. Se o PIX pago
   ficar "pendente", o nome do status não está no mapa de `mcp_traduzir_status()`: **não seguir**, voltar (ver "Volta atrás") e ajustar.
   Conferir também no log de `mcp_doacoes_log` as linhas `postback` e `consulta_falhou`.
4. **Textos e páginas.** Definir `DATA_VIRADA_DOACAO = date(AAAA, M, D)` em `scripts/gerar_politicas.py` (dia do passo 2),
   atualizar `"revisao"` em `site/politicas.json` para a mesma data, rodar a ordem de geração do README (`gerar_faq_home`,
   `chat_widget`, `gerar_bio`, `gerar_404`, `gerar_idiomas`, `gerar_ingles`, `gerar_doe`, `gerar_politicas`, `gerar_institucional`),
   `python3 scripts/validar_jsonld.py` e `python3 scripts/conferir_links.py --local`. Commitar a saída.
5. **Publicar** o bloco "textos e páginas" da lista (mais os arquivos que o `git status` mostrar), limpar o cache e conferir por HTTP
   que cada arquivo ficou igual ao Git. Conferir: `/privacidade/`, `/en/privacy/`, `/es/privacidad/` e os termos dizem Donaty nas doações e
   ÚnicoPag nas inscrições; `/doe/` sem cartão; nenhuma página de doação cita a ÚnicoPag.
6. Só então mesclar o branch na `main` (PR) para o repositório refletir o que está no ar.

## Volta atrás (qualquer passo)

- **Sem deploy:** no `/doe/api/config.php`, `'DOACAO_GATEWAY' => 'unicopag'`. A página volta a oferecer cartão e o servidor volta a
  cobrar pela Unicopag. Cada doação guarda o gateway em que nasceu (coluna `gateway`, criada sozinha no primeiro acesso, com
  `unicopag` nas antigas): as pendentes da Donaty continuam sendo consultadas na Donaty, e as da Unicopag na Unicopag, nos dois
  sentidos. Por isso `UNICO_API_KEY_DOACAO` e `DONATY_API_KEY` ficam **os dois** no `config.php`.
- **Textos:** republicar a cópia do ar guardada antes do passo 5 (`scripts/publicar_hostinger.sh --copiar-do-ar` antes de enviar).

## Riscos conhecidos

- **Status desconhecido = pendente.** Se a Donaty usar nomes diferentes de `paid`/`waiting_payment`, nenhuma doação confirma. É o
  que o teste do passo 3 precisa provar.
- **Campos extras.** Se a Donaty recusar `document`, `phone_number` ou `metadata` (422), a doação falha com a mensagem dela;
  o log registra `donaty_recusou` com o código e o texto.
- **Cartão.** Com a Donaty não há cartão. Quem tentar doar com cartão não consegue; a página diz "por enquanto, só PIX".
- **Doações em andamento na troca.** Resolvido: a consulta usa o gateway de cada doação. Mesmo assim, fazer a troca num horário calmo.
- **Coluna nova.** A primeira requisição ao backend novo acrescenta `gateway` a `mcp_doacoes` (`ALTER TABLE`, automático, como a coluna
  `anonimo` em 2026-09). É aditivo e as linhas antigas ficam `unicopag`.
- **`privacy.md` em inglês** (`traducoes/en/paginas/privacy.md`) é legado: `/en/privacy/` vem do `politicas.json`. Não foi alterado.
