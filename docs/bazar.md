# Bazar solidário: registro de doações e aviso por WhatsApp (11/11/2026)

Pedido do Matheus: a pessoa avisa na página `/bazar/` o que quer doar, o site registra e avisa a colaboradora
responsável pelo bazar, pelo WhatsApp. Ela ainda não tem e-mail; por isso o aviso dela é só pelo WhatsApp.

Estado: **código pronto e testado, não publicado.** O modelo de mensagem foi enviado à Meta em 11/11/2026 e estava
**em análise**. Enquanto ele não for aprovado, nenhum WhatsApp sai.

## Como funciona

```
formulário de /bazar/ ──> api/bazar.php ──> grava em mcp_bazar_doacoes (protocolo BZ-aammdd-NNNN)
                                │
                                ├─> e-mail à equipe (EMAIL_CONTATO), com botão para chamar quem doa
                                │
                                └─> aviso por WhatsApp à responsável (modelo bazar_doacao_nova, pela Kapso)
                                      só sai se: Kapso configurada + celular de destino no servidor
                                                 + modelo APROVADO na Meta + modelo confere com a mensagem
                                      senão: fica "pendente" e a rodada de hora em hora (api/lembretes.php) tenta de novo
```

- **Formulário** (seção "Avise o que você vai doar"): nome, WhatsApp, tipos de item (marcar), detalhes (opcional),
  quando pretende trazer e o aceite do uso dos dados. Não pede e-mail. Avisar não é obrigatório para doar.
- **Registro**: tabela `mcp_bazar_doacoes` (criada pela migração do `db.php`; `MCP_DB_VERSAO` nova). O evento em
  `mcp_eventos` (`bazar`) leva só o número do registro e o que aconteceu com os avisos, sem nome nem telefone.
- **Freios**: campo armadilha, 6 registros por IP e 3 por telefone por hora, pedido só do próprio site.
- **Aviso por WhatsApp** (`api/lib/bazar.php`): a situação do modelo é lida da Meta, pela Kapso, **na hora de cada
  envio**. Modelo ausente, em análise, recusado, pausado, com outro número de parâmetros, com botão de variável ou sem
  o texto para conferir: nada sai. Só das 8h às 20h de Brasília. Cada registro é avisado uma vez.
- **Pendentes**: são tentados de hora em hora por 48 horas; depois viram `vencido`. Assim, no dia em que a Meta
  aprovar o modelo, a responsável não recebe uma pilha de avisos antigos. Erro de quem recebe (número sem WhatsApp)
  vira `falhou`; erro que vale para todos (Kapso fora, limite da Meta) para a rodada e não gasta a tentativa.

## O modelo na Meta

`bazar_doacao_nova`, Utilidade, pt_BR, sem botão. Criado em 11/11/2026 pela API da Kapso, na conta do número do site
(a mesma do `cur_cupom_oferta` e dos `mat_pendente_*`). Texto:

```
Nova doação registrada para o bazar solidário.

Quem doa: {{1}}
WhatsApp: {{2}}
O que vai doar: {{3}}
Entrega prevista: {{4}}
Protocolo: {{5}}

Registro feito na página do bazar, no site da Cruz Vermelha Brasileira Rio de Janeiro. Se for preciso, chame a pessoa pelo WhatsApp informado para combinar a entrega.
```

Mudar o texto exige modelo novo (`bazar_doacao_nova_v2`) e a troca de `MCP_BAZAR_MODELO` (e de
`MCP_BAZAR_MODELO_PARAMS`, se mudar o número de variáveis). Se a Meta recusar, o motivo aparece no painel da Kapso.

## O que falta no servidor

1. Uma linha em `api/config-whatsapp.php` (ou `config.php`), só no servidor, com o celular de quem recebe os avisos:
   `'WHATSAPP_BAZAR_AVISO' => 'DDD + número',`. O número é de uma pessoa e por isso não está no repositório. Sem a
   linha, nenhum aviso sai (o registro e o e-mail à equipe continuam).
2. `WHATSAPP_KAPSO_NUMERO_ID` tem de ser o número da conta onde o modelo foi criado. Se for outro, o site diz
   "o modelo não existe na Meta" e segura os avisos, sem mandar nada errado.
3. Publicar os arquivos de `scripts/publicacao-bazar.txt`.

## Conferir se está saindo

`mcp_bazar_doacoes.aviso_status`: `pendente`, `enviado`, `falhou` ou `vencido`, com o motivo em `aviso_erro`. A
saída do `lembretes.php` (cron de hora em hora) traz uma linha "bazar: …" com o motivo quando há pendentes.
No GA4, o envio do formulário manda o evento `bazar_registrar` (origem `pagina`).

## Testar

`MCP_CONFIG_ARQUIVO=/caminho/config-teste.php php scripts/testar_bazar_integracao.php` (MariaDB local e uma Kapso
falsa; 64 verificações; nada sai de verdade). Inclui o endpoint por HTTP e a conferência de que as opções do
formulário gerado são as que o servidor aceita.

## Ainda não tem

- Tela no painel para a equipe ver a lista de registros (hoje: o e-mail à equipe e o banco).
- Confirmação para quem doa por WhatsApp ou e-mail (a tela mostra o protocolo).
