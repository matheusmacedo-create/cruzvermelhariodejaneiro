# Ligação do site com o Google Drive da filial

Guia para o Matheus (gestão), sem jargão técnico além do necessário. O objetivo: tudo o que o site guarda no
servidor da Hostinger (fichas de voluntários, inscrições, mensagens, ponto, imagens do site) ganha uma cópia
automática num Drive compartilhado do Google Workspace da filial (`cruzvermelhariodejaneiro.org`), que a
secretaria abre sem precisar de senha de servidor. O Drive é **cópia**: o banco do site continua sendo a fonte da
verdade.

> **Qual Drive.** O Google Drive conectado ao Claude é o da conta **contato@cruzvermelhariodejaneiro.org**, ou seja,
> o da filial (conferido em 16/10/2026: o "Meu Drive" e os arquivos pertencem a essa conta). Foi por ele que a pasta
> raiz e a planilha dos passos 2 e 3 foram criadas. O que o conector **não** faz: criar Drive compartilhado, mexer em
> membros de Drive compartilhado, nem nada no Google Cloud — isso continua sendo feito por uma pessoa, pelo
> navegador. As chaves ficam só no servidor da Hostinger — nunca neste chat, nunca no Git.

Código: `site/matricula-cursos-presenciais/api/lib/google_drive.php` (fala com o Google), `lib/drive_espelho.php`
(fila, exportações, cópia do banco, espelho das imagens), `api/drive.php` (a rotina do cron). Teste local, sem
tocar no Google de verdade: `scripts/testar_drive.php`.

## O que vai parar no Drive

A rotina cria sozinha estas pastas dentro da pasta raiz que você escolher:

| Pasta | O que entra | Quando |
|---|---|---|
| `Voluntariado/Fichas/` | um PDF por ficha de voluntário (`VOL-aammdd-0001-Nome_da_Pessoa.pdf`) | minutos depois de a pessoa enviar a ficha |
| planilha **Fichas de voluntários**, aba **Fichas** | uma linha por ficha (sem CPF) | junto com o PDF |
| `Secretaria/Exportações/2026/` | `2026-10-16-mcp_inscricoes.csv`, `...-mcp_contatos.csv`, `...-mcp_voluntarios.csv`, `...-mcp_colaboradores.csv`, `...-mcp_ponto.csv` — só as linhas do dia, **sem CPF** | uma vez por dia, na primeira rodada depois da meia-noite (do dia anterior) |
| `Backups/banco/2026-10-16/` | uma cópia completa de cada tabela do site (`mcp_inscricoes.json.gz`, ...), comprimida | uma vez por dia |
| `Site/assets/` | as imagens e arquivos de `public_html/assets`, na mesma estrutura de pastas | a cada rodada, até 40 arquivos por vez; depois só o que mudou |
| `Site/teste-ligacao.txt` | um texto com a data, gravado pelo `--testar` | só quando você roda o teste |

A cópia do banco **tem CPF** (é um backup completo) e a planilha e as exportações **não têm**. Por isso, quem pode
abrir `Backups/` deve ser só quem hoje já abre o portal da secretaria. Pela Política de Privacidade, a ficha de
voluntário, o PDF e a linha da planilha são apagados 12 meses depois do cadastro (fichas não aceitas): o site
enfileira o expurgo e a rotina apaga o PDF e a linha no Drive.

## Passo 1 — Criar o projeto no Google Cloud (10 minutos)

Com uma conta de administrador do Workspace da filial:

1. Entre em <https://console.cloud.google.com> e, no seletor de projeto (em cima, à esquerda), clique em
   **Novo projeto**. Nome: `Site Cruz Vermelha RJ`. Organização: `cruzvermelhariodejaneiro.org`. **Criar**.
2. Com o projeto selecionado, vá em **APIs e serviços › Biblioteca**. Procure **Google Drive API** e clique em
   **Ativar**. Volte à Biblioteca, procure **Google Sheets API** e clique em **Ativar**.
3. Vá em **APIs e serviços › Credenciais › Criar credenciais › Conta de serviço**.
   - Nome: `site-drive`. O Google monta o e-mail, algo como
     `site-drive@site-cruz-vermelha-rj.iam.gserviceaccount.com`. **Guarde esse e-mail**: você vai usá-lo no passo 2.
   - Não precisa dar papel nenhum no projeto. **Concluir**.
4. Clique na conta de serviço criada, aba **Chaves › Adicionar chave › Criar nova chave › JSON › Criar**. O
   navegador baixa um arquivo `.json`. **Esse arquivo é a senha**: quem o tiver escreve no Drive em nome do site.
   Não o mande por e-mail nem por chat; não o guarde no Google Drive; apague o download depois de montar o passo 4.

## Passo 2 — Criar o Drive compartilhado e dar acesso à conta de serviço

**Já feito em 16/10/2026 (pelo Claude, no Drive de contato@):** a pasta **Cruz Vermelha RJ – Site**, no "Meu
Drive" da conta contato@, com a subpasta `Voluntariado` e a planilha do passo 3 dentro. Falta ela ir para um Drive
compartilhado: a conta de serviço não tem cota no Meu Drive, então enquanto a pasta estiver lá o PDF da ficha não
sobe (o `--testar` avisa "no Meu Drive de alguém"). Ao mover, os ids não mudam.

1. Em <https://drive.google.com>, menu à esquerda, **Drives compartilhados › Novo**. Nome:
   **Cruz Vermelha RJ – Site**. Depois, no **Meu Drive**, arraste a pasta **Cruz Vermelha RJ – Site** para dentro
   desse Drive compartilhado (ou botão direito › **Organizar › Mover**). O Google pergunta se pode mudar quem tem
   acesso: confirme.
2. Abra o Drive compartilhado, clique no nome dele (em cima) › **Gerenciar membros**. Cole o e-mail da conta de
   serviço (`site-drive@...iam.gserviceaccount.com`) e escolha o papel **Gestor de conteúdo**. Desmarque "Notificar"
   (é um robô, não lê e-mail). **Enviar**.
3. Adicione também as pessoas da secretaria que vão abrir as cópias (**Leitor** basta) e, se quiser, deixe a pasta
   `Backups/` só para quem precisa: depois que a rotina criar as pastas, você pode restringir o acesso a `Backups/`
   pelo botão direito › **Compartilhar**.
4. **Id da pasta raiz**: abra a pasta **Cruz Vermelha RJ – Site** (a que foi movida, não a raiz do Drive
   compartilhado). O endereço do navegador termina em algo como `https://drive.google.com/drive/folders/1clK...`.
   O trecho depois de `/folders/` é o `GOOGLE_DRIVE_RAIZ_ID`. Copie. (A raiz do próprio Drive compartilhado, com id
   começando por `0A`, também serviria, mas aí `Voluntariado` e a planilha ficariam fora dela.)

Por que um Drive compartilhado e não uma pasta no "Meu Drive" de alguém: os arquivos ficam da instituição, não da
pessoa (se ela sair, nada some), e uma conta de serviço não tem cota própria de armazenamento no Meu Drive.

## Passo 3 — Criar a planilha das fichas

**Já feito em 16/10/2026 (pelo Claude):** planilha **Fichas de voluntários**, aba **Fichas**, cabeçalho na linha 1,
na ordem abaixo, conferida lendo de volta. A pedido do Matheus, ela mora em `Cursos/voluntariado` no Meu Drive de
contato@, e não dentro da pasta raiz: o site acha a planilha pelo id, não pelo lugar, então isso não muda nada no
código. Os itens 1 a 3 ficam para o caso de precisar refazê-la; faltam os itens 4 e 5.

> **Antes de configurar o servidor:** a pasta `voluntariado` estava (16/10) com "qualquer pessoa com o link pode
> ver". A planilha herda isso, e ela vai ter nome, telefone e e-mail de cada voluntário. Troque para **Restrito**
> (botão direito › Compartilhar › Acesso geral) antes de pôr o id no `config-google.php`.

1. Dentro do Drive compartilhado, crie uma pasta `Voluntariado` e, nela, **Novo › Planilhas Google**. Nome:
   **Fichas de voluntários**.
2. Renomeie a primeira aba (embaixo, "Página1") para **Fichas** (exatamente assim, com F maiúsculo).
3. Na linha 1, escreva o cabeçalho, uma coluna por célula, nesta ordem:

   `Protocolo` · `Data` · `Nome` · `Idade` · `E-mail` · `Telefone` · `Cidade/bairro` · `Escolaridade` · `Profissão` ·
   `Formação em saúde` · `Frentes` · `Dias` · `Períodos` · `Motivação` · `Como conheceu` · `Status`

   A rotina acrescenta cada ficha na primeira linha vazia abaixo do que já existe, nessa mesma ordem. Pode congelar a
   linha 1 (**Visualizar › Congelar › 1 linha**) e colorir à vontade; só não mude a ordem das colunas nem o nome da
   aba.
4. **Id da planilha**: no endereço `https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit`,
   o id é o trecho entre `/d/` e `/edit`. Copie: é o `GOOGLE_SHEET_FICHAS_ID`.

5. **Compartilhar com a conta de serviço.** Como a planilha está no Meu Drive, e não no Drive compartilhado, a conta
   de serviço não a enxerga sozinha: abra a planilha › **Compartilhar**, cole o e-mail
   `site-drive@...iam.gserviceaccount.com`, papel **Editor**, desmarque "Notificar" › **Enviar**. (Escrever linhas
   numa planilha que é de contato@ não gasta cota da conta de serviço; só os PDFs, CSVs e backups, que ela cria,
   precisam do Drive compartilhado.) Se um dia a planilha for para dentro do Drive compartilhado, este item deixa de
   ser necessário.

Sem o item 5, o `--testar` passa mas a primeira ficha falha com `HTTP 403` ou `404` na planilha.

## Passo 4 — Montar o `config-google.php` no servidor (pelo gerenciador de arquivos, nunca pelo Git)

As chaves ficam num arquivo só do servidor, `public_html/matricula-cursos-presenciais/api/config-google.php`. Ele
não existe no repositório e **nunca** deve entrar nele (o `.htaccess` da pasta impede que seja lido pelo navegador,
e o `lib/config.php` só aceita dele as chaves que começam com `GOOGLE_`).

1. No **hPanel da Hostinger › Sites › Gerenciar › Gerenciador de arquivos**, entre em
   `public_html/matricula-cursos-presenciais/api/`.
2. **Novo arquivo** › nome `config-google.php`. Abra para editar e cole o modelo abaixo.
3. Abra o `.json` baixado no passo 1 num editor de texto simples (Bloco de notas). Ele tem campos como
   `"type"`, `"project_id"`, `"private_key"`, `"client_email"`... Copie cada valor para a linha correspondente.
   A `private_key` é longa, começa com `-----BEGIN PRIVATE KEY-----\n` e termina com `-----END PRIVATE KEY-----\n`:
   copie **inteira, do jeito que está no JSON, com os `\n`**, entre aspas duplas como no modelo.
4. Cole o id da pasta raiz (passo 2) e o id da planilha (passo 3). Salve.
5. Apague o `.json` do seu computador (e da Lixeira).

Modelo:

```php
<?php
// Google Drive da filial. Só no servidor; nunca no Git. Só as chaves GOOGLE_* valem aqui.
return [
    'GOOGLE_SA' => [
        'type' => 'service_account',
        'project_id' => 'site-cruz-vermelha-rj',
        'private_key_id' => 'cole aqui o private_key_id',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg...muitas linhas...\n-----END PRIVATE KEY-----\n",
        'client_email' => 'site-drive@site-cruz-vermelha-rj.iam.gserviceaccount.com',
        'client_id' => 'cole aqui o client_id',
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ],
    // O Drive compartilhado "Cruz Vermelha RJ – Site": o trecho depois de /folders/ no endereço.
    'GOOGLE_DRIVE_RAIZ_ID' => 'cole aqui',
    // A planilha "Fichas de voluntários": o trecho entre /d/ e /edit no endereço. Vazio = só o PDF, sem planilha.
    'GOOGLE_SHEET_FICHAS_ID' => 'cole aqui',
];
```

Enquanto `GOOGLE_SA` e `GOOGLE_DRIVE_RAIZ_ID` estiverem vazios, tudo fica desligado: a fila não recebe nada e a
rotina sai em silêncio. Um erro de digitação no arquivo (vírgula, aspas) também o desliga, sem derrubar o site; o
`--testar` do passo 6 mostra o que está errado.

## Passo 5 — Criar o cron no hPanel

**hPanel › Avançado › Cron jobs**:

- Tipo: **Personalizado**.
- Comando (uma linha só):
  `php /home/u448697994/domains/cruzvermelhariodejaneiro.org/public_html/matricula-cursos-presenciais/api/drive.php`
- Frequência: **a cada 15 minutos** (`*/15 * * * *`).

É a mesma receita do cron do ponto (`comparecimentos.php`). Duas rodadas nunca correm juntas: a segunda vê a trava
no banco e sai. Se a hospedagem mandar e-mail com a saída do cron, cada rodada é uma linha, como
`2026-10-16 12:15:03 drive: fila: 2 feitos, 0 falhas; assets: 200 vistos, 40 enviados, 0 ignorados, 0 falhas, 160 restantes`.

## Passo 6 — Testar

No **hPanel › Avançado › Terminal SSH** (ou pelo cron, criando uma tarefa "uma vez" com o comando abaixo e lendo
a saída por e-mail):

```
php /home/u448697994/domains/cruzvermelhariodejaneiro.org/public_html/matricula-cursos-presenciais/api/drive.php --testar
```

A saída esperada:

```
1. Configuração: conta de serviço site-drive@..., pasta raiz 0AF3kQ9..., planilha das fichas configurada
2. Token de acesso obtido (… caracteres), guardado em .google-token.json.
3. Pasta raiz: "Cruz Vermelha RJ – Site" (application/vnd.google-apps.folder), num Drive compartilhado
4. Conteúdo da raiz: 1 item(ns)
   - Voluntariado (pasta)
5. Arquivo Site/teste-ligacao.txt gravado (id …).
Tudo certo.
```

Abra o Drive compartilhado: deve existir `Site/teste-ligacao.txt` com a data e a hora. Se parar num passo:

| Mensagem | O que é | O que fazer |
|---|---|---|
| `Drive desligado: faltam GOOGLE_SA e GOOGLE_DRIVE_RAIZ_ID` | o `config-google.php` não foi lido ou está incompleto | confira o nome do arquivo e as aspas/vírgulas |
| `A private_key de GOOGLE_SA não pôde ser lida` | a chave foi colada pela metade ou sem os `\n` | copie de novo do JSON, inteira, entre aspas duplas |
| `Token do Google recusado … invalid_grant` | relógio ou chave: a chave foi revogada no Google Cloud ou a conta de serviço foi apagada | gere uma chave nova (passo 1.4) e troque no arquivo |
| `GET /drive/v3/files/… respondeu HTTP 404` | o id da raiz está errado ou a conta de serviço não é membro do Drive compartilhado | refaça o passo 2 |
| `… HTTP 403 … insufficientPermissions` ou `accessNotConfigured` | a API do Drive ou do Sheets não foi ativada, ou o papel é só Leitor | ative as duas APIs; papel Gestor de conteúdo |
| `A planilha não tem a aba "Fichas"` | a aba tem outro nome | renomeie para `Fichas` |

## O que acontece em cada rodada (a cada 15 minutos)

1. **Fila.** Até 20 trabalhos pendentes, na ordem em que chegaram. Cada ficha de voluntário vira um PDF em
   `Voluntariado/Fichas` e uma linha na planilha; a ficha fica marcada com o id do PDF e a hora da planilha, para não
   repetir. Um trabalho que falha (Google fora do ar, cota) é tentado de novo em 5, 15, 60 e 240 minutos; na quinta
   falha ele fica como **falhou**, com o motivo resumido, e não é mais tentado (a secretaria vê a fila no portal, se
   essa tela existir, ou no banco, tabela `mcp_drive_fila`).
2. **Uma vez por dia**, na primeira rodada depois da meia-noite de Brasília: entram na fila a exportação em CSV do
   dia anterior e a cópia completa do banco de hoje, e os dois já são executados. Arquivos do mesmo nome são
   substituídos, nunca duplicados.
3. **Imagens do site.** Compara `public_html/assets` com o que já subiu (tamanho e data de modificação) e envia o
   que é novo ou mudou, até 40 arquivos ou 50 segundos por rodada. Na primeira semana isso leva algumas rodadas até
   alcançar tudo; depois, só o que o site publicar de novo. Arquivos acima de 50 MB e os que começam com ponto ficam
   de fora.

O token de acesso ao Google dura uma hora e fica guardado em `api/.google-token.json` (só o usuário do PHP lê;
o `.htaccess` impede que seja baixado). Ele é renovado sozinho 5 minutos antes de vencer.

## Se a chave vazar (ou alguém sair com ela)

A chave JSON equivale a uma senha com permissão de escrever e apagar no Drive compartilhado. Se ela aparecer num
chat, num e-mail, num repositório ou numa máquina perdida:

1. **Google Cloud › IAM e administrador › Contas de serviço › `site-drive` › Chaves**: clique em **Excluir** na
   chave vazada (pelo `private_key_id`, que também está no `config-google.php`). A partir daí ela não vale mais;
   a rotina começa a registrar `invalid_grant` e para de escrever, sem derrubar o site.
2. Na mesma tela, **Adicionar chave › Criar nova chave › JSON**, e troque a `private_key` e o `private_key_id`
   no `config-google.php` (passo 4). Rode o `--testar`.
3. Se desconfiar que alguém usou a chave: no Drive compartilhado, **Gerenciar membros**, confira se não entrou
   ninguém a mais, e abra `Backups/` e `Voluntariado/Fichas` para ver se falta ou sobra algo. O histórico de
   atividade do Drive (painel à direita, "Atividade") mostra o que a conta de serviço fez e quando.
4. Em último caso, apague a conta de serviço inteira e crie outra (passos 1.3, 1.4 e 2.2): basta trocar o
   `client_email` e a chave no arquivo; as pastas e a planilha continuam onde estão.

Enquanto a chave estiver trocada e o site ainda com a antiga, a fila guarda os trabalhos e tenta de novo; nada se
perde, só atrasa.

## Perguntas rápidas

- **Posso mover ou renomear as pastas no Drive?** Renomear a raiz, sim. As pastas internas (`Voluntariado`,
  `Secretaria`, `Backups`, `Site`) a rotina lembra pelo id, não pelo nome; se alguém apagar uma, ela é recriada
  vazia na próxima rodada. Evite mexer nelas.
- **Posso apagar os CSVs e backups antigos?** Sim. A rotina só escreve os do dia; não apaga nada sozinha (fora o
  expurgo das fichas, previsto na Política de Privacidade).
- **A planilha é editável?** Sim, mas a rotina sempre acrescenta no fim da aba `Fichas` e procura o protocolo na
  coluna A para o expurgo. Pode anotar nas colunas à direita; não reordene as linhas nem troque a coluna A.
- **Quanto espaço vai usar?** O banco inteiro comprimido tem poucos megabytes por dia; as imagens do site,
  cerca de 12 MB uma vez. Um Drive compartilhado do Workspace comporta anos disso.
