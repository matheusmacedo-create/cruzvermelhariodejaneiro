<?php
// Copie para config.php (só no servidor; config.php NUNCA entra no Git) e preencha.
return [
    // Unicopag: chave da API (painel Unicopag > API). Segredo.
    'UNICO_API_KEY'  => '',
    'UNICO_BASE_URL' => 'https://api.cloud.unicopag.com.br',

    // MySQL da Hostinger (hPanel > Bancos de dados).
    'DB_HOST'  => 'localhost',
    'DB_PORT'  => 3306,
    'DB_NAME'  => 'u448697994_matricula',
    'DB_USER'  => 'u448697994_matricula',
    'DB_SENHA' => '',

    // Endereço público das páginas (monta postback_url e os links dos e-mails).
    'SITE_URL' => 'https://cruzvermelhariodejaneiro.org',

    // Inscrição em centavos. Deixe vazio para ler de cursos.json (9900).
    'INSCRICAO_CENTAVOS' => '',
    // Só em teste operacional: cobra este valor no lugar da inscrição, e só para os CPFs de TESTE_CPFS (abaixo).
    // Todo outro visitante paga o preço real. Ex.: 100 = R$ 1,00. Volte para '' depois do teste.
    'PRECO_TESTE_CENTAVOS' => '',

    // Pagar tudo (10/2026; docs na spec pagar-tudo, seção 3.1). Todas DESLIGADAS: o checkout oferece só a taxa,
    // como antes. Liga e desliga só com o booleano true/false, sem aspas (a string 'false' não liga nada).
    // No servidor, estas chaves (e ESCOLA_MATRICULA_PAGA) vão em api/config-pagar-tudo.php, só no servidor e fora do
    // Git, para não mexer neste config.php nem no config-escola.php (que guarda a chave da escola). Ele vem por último
    // e só aceita as chaves de MCP_CHAVES_PAGAR_TUDO (lib/config.php), por exemplo:
    //   <?php return ['PLANO_COMPLETO' => true, 'ESCOLA_MATRICULA_PAGA' => true, 'PARCELAS_MAX' => 1];
    // Liga a opção "Taxa de inscrição + matrícula" nos cursos com turma aberta (exige ESCOLA_MATRICULA_PAGA em
    // config-escola.php e a escola respondendo matricula_rapida_versao() >= 2). Volta atrás: false.
    'PLANO_COMPLETO' => false,
    // Liga a mesma opção nos cursos sem turma da lista "sem_turma" do oferta.json (fila da próxima turma; escola na versão 3).
    'PLANO_COMPLETO_SEM_TURMA' => false,
    // Teto de parcelas no cartão (1 a 12). Fica 1 até a compra de teste em 2x e o OK do jurídico (passo 6).
    'PARCELAS_MAX' => 1,
    // Parcela mínima mostrada, em centavos (500 = R$ 5,00).
    'PARCELA_MINIMA_CENTAVOS' => 500,
    // Preço da matrícula para os CPFs de teste, em centavos. 0 = a opção completa some para eles (nunca se cobra a
    // matrícula real numa compra de teste).
    'PRECO_TESTE_MATRICULA_CENTAVOS' => 0,
    // CPFs (só dígitos) que pagam os preços de teste. Fora desta lista, ninguém paga preço de teste. Ex.: ['52998224725'].
    'TESTE_CPFS' => [],
    // Testes reais (passos 4, 4b e 6): com true, só os CPFs de TESTE_CPFS conseguem pagar a opção "Taxa de inscrição +
    // matrícula"; qualquer outro visitante que a escolher recebe "No momento, a matrícula não pode ser paga junto" e paga
    // só a taxa. Volte para false depois do teste.
    'PLANO_COMPLETO_SO_TESTE' => false,
    // Venda sem turma: data limite da primeira aula, em dias da compra (P6), e máximo de chamadas à escola por rodada.
    'ESPERA_PRAZO_DIAS' => 90,
    'ESPERA_LOTE' => 30,
    // Até quantos dias depois da venda a Unicopag aceita o estorno no cartão (pergunta e do passo 0). Vazio = o botão
    // "Continua esperando" não aparece nas compras no cartão.
    'ESTORNO_CARTAO_LIMITE_DIAS' => '',
    // Quem concede o parcelamento (CDC, art. 54-B, § 3º), definido pelo jurídico. Vazio = o recebedor (RECEBEDOR_*).
    'AGENTE_FINANCIADOR' => '',
    'AGENTE_CNPJ' => '',

    // Custos de processamento que o aluno pode escolher cobrir (opcional, por vontade própria).
    // Percentual sobre a inscrição + parcela fixa em centavos, por método.
    // Decisão de 18/09/2026: 5% em todos os métodos (média de PIX, cartão e checkout), sem parcela fixa.
    // (PIX medido pela API em 18/09: 1,00% + R$ 1,48.)
    'TAXA_PIX_PCT'      => 5.0,
    'TAXA_PIX_FIXA'     => 0,
    'TAXA_CARTAO_PCT'   => 5.0,
    'TAXA_CARTAO_FIXA'  => 0,

    // Contribuição opcional para a divulgação dos cursos, em centavos (07/10/2026, teste com 1490).
    // Deixe vazio para ler de cursos.json (divulgacao_centavos); 0 tira a opção do checkout.
    'DIVULGACAO_CENTAVOS' => '',

    // E-mail: Resend. O remetente precisa estar num domínio verificado na conta (em 18/09/2026:
    // info., noticias. e parceria.cruzvermelhariodejaneiro.org). Sem chave, cai no mail() da Hostinger.
    'RESEND_API_KEY'   => '',
    'EMAIL_REMETENTE'  => 'Cruz Vermelha Brasileira Rio de Janeiro <matricula@info.cruzvermelhariodejaneiro.org>',
    // Contato por e-mail (19/09/2026, no lugar do WhatsApp da secretaria): destino das mensagens do
    // chat do site (api/contato.php) e endereço citado no rodapé de todos os e-mails. Precisa ser uma
    // caixa que receba de fato: em 19/09/2026 o MX do domínio ainda apontava para a Hostinger sem
    // serviço de e-mail contratado, então contato@ só passa a receber quando o MX for para o Google
    // Workspace. Até lá, coloque aqui um e-mail que funcione.
    'EMAIL_CONTATO'    => 'contato@cruzvermelhariodejaneiro.org',
    // Responder-para dos e-mails ao aluno. Vazio = EMAIL_CONTATO.
    'EMAIL_RESPOSTA'   => '',
    // Aviso interno de inscrição paga (responder-para = o aluno). Vazio = desligado.
    'EMAIL_SECRETARIA' => '',
    // Portal da secretaria (api/painel.php: inscrições, horários dos alunos e mensagens do chat): e-mails que podem pedir o link de entrada, separados por
    // vírgula. Vazio = EMAIL_CONTATO e EMAIL_SECRETARIA. O segredo dos links fica no banco (mcp_chaves).
    'PAINEL_EMAILS'    => '',
    // Remetente das respostas do painel ao cliente. Vazio = EMAIL_REMETENTE.
    'EMAIL_REMETENTE_CONTATO' => '',

    // Plataforma da escola (versão A da tela Parabéns): depois do pagamento, o site chama a função
    // public.matricula_rapida do banco da escola (Supabase), que cria a conta e a matrícula.
    // As duas chaves ficam em api/config-escola.php (só no servidor, fora do Git), para não mexer
    // neste arquivo; de lá só valem as chaves ESCOLA_*:
    //   return ['ESCOLA_API_URL' => 'https://wrckokgdtiwvxapqzkki.supabase.co/rest/v1/rpc/matricula_rapida',
    //           'ESCOLA_API_TOKEN' => 'sb_secret_...'];  // chave secreta do projeto da escola
    // Vazio = versão B (a secretaria fecha turma e horário por e-mail). Passo a passo: docs/escola/README.md.
    'ESCOLA_API_URL'   => '',
    'ESCOLA_API_TOKEN' => '',
    // Pagar tudo: true só depois da matricula_rapida v2 aplicada na escola (docs/escola/matricula_rapida_v2.sql).
    // Também vai no api/config-escola.php. Desligada, a escola nunca recebe a matrícula paga junto.
    'ESCOLA_MATRICULA_PAGA' => false,
    // Aba "Horários" do painel da secretaria da escola: ela lê as respostas do questionário de dias e
    // horários em api/escola-horarios.php com esta chave (32 caracteres ou mais; gere com
    // `openssl rand -hex 24`). Também vai no api/config-escola.php, e a mesma chave vai na variável
    // SITE_HORARIOS_TOKEN da escola (Render), com o mesmo nome. Vazio = o endereço responde 404.
    'SITE_HORARIOS_TOKEN' => '',
    'ESCOLA_URL'       => 'https://escola.cursoscruzvermelha.org',
    // Aulas do dia para o ponto da sede (lib/presenca.php): a função public.aulas_do_aluno da escola,
    // com a mesma chave. Vazio = a URL de ESCOLA_API_URL trocando matricula_rapida por aulas_do_aluno.
    // Também vale em api/config-escola.php.
    'ESCOLA_API_AULAS_URL' => '',

    // Ponto da sede (/ponto/): no celular, o registro só vale a até PONTO_RAIO_METROS da sede. Vazio =
    // Praça da Cruz Vermelha, 10 (-22.91132, -43.18779, pelo OpenStreetMap) e 150 m.
    'PONTO_SEDE_LAT'    => '',
    'PONTO_SEDE_LNG'    => '',
    'PONTO_RAIO_METROS' => '',

    // Comunicação do ponto (30/09/2026, docs/ponto-comunicacao.md). Tudo começa desligado no portal.
    // Remetente dos lembretes e comunicados. Vazio = EMAIL_REMETENTE.
    'EMAIL_REMETENTE_PONTO' => '',
    // Feriados nacionais, do estado e da cidade do Rio (com Carnaval e Corpus Christi): sem lembrete da véspera
    // nem da aula. '0' = a sede abre nos feriados (valem só os dias sem expediente marcados no portal).
    'AVISOS_FERIADOS' => '',
    // E-mails dos avisos por dia. A conta da Resend é dividida com a matrícula (recibos, PIX, comprovantes); no
    // plano grátis são 100 por dia. Vazio = 60. '0' = sem cota (plano pago).
    'AVISOS_EMAILS_POR_DIA' => '',
    // WhatsApp. Sem nada aqui, o modo é o manual: as mensagens vão para a "Fila do WhatsApp" do portal.
    // As chaves WHATSAPP_* também podem ficar em api/config-whatsapp.php (só no servidor, fora do Git), para
    // não mexer neste arquivo; de lá só valem as WHATSAPP_*.
    // O WhatsApp que a instituição já tem conectado no Palácio Virtual, pela Evolution API (a mesma
    // instância): o endereço https do servidor, o nome da instância e o token da instância (Palácio →
    // Configurações → Integrações). Não é a API oficial: leia os riscos em docs/ponto-comunicacao.md antes
    // de ligar (o número pode ser bloqueado, e com ele os avisos do Palácio).
    'WHATSAPP_EVOLUTION_URL' => '',
    'WHATSAPP_EVOLUTION_INSTANCIA' => '',
    'WHATSAPP_EVOLUTION_CHAVE' => '',
    // API oficial da Meta (número da própria instituição, nunca de outra empresa): token permanente do
    // usuário do sistema, Phone number ID, a chave secreta do app (confere o webhook api/whatsapp.php) e o
    // texto de verificação que se escreve no painel da Meta.
    'WHATSAPP_CLOUD_TOKEN' => '',
    'WHATSAPP_CLOUD_NUMERO_ID' => '',
    'WHATSAPP_CLOUD_APP_SEGREDO' => '',
    'WHATSAPP_CLOUD_VERIFICACAO' => '',
    // Ou um cenário do Make (POST assinado com X-CVB-Assinatura: sha256=HMAC do corpo; segredo com 24+ caracteres).
    'WHATSAPP_WEBHOOK_URL' => '',
    'WHATSAPP_WEBHOOK_SEGREDO' => '',
    // Lembrete da aula de amanhã: a função public.aulas_do_dia da escola. Vazio = ESCOLA_API_URL trocando
    // matricula_rapida por aulas_do_dia. Também vale em api/config-escola.php.
    'ESCOLA_API_AULAS_DIA_URL' => '',
    // API de Conversões da Meta (lib/meta.php): os eventos da matrícula saem também pelo servidor, só para quem
    // aceitou os cookies de marketing. Sem o token, nada é enviado nem guardado. As chaves META_* também podem
    // ficar em api/config-meta.php (só no servidor, fora do Git; de lá só valem as META_*), por exemplo:
    //   <?php return ['META_CAPI_TOKEN' => 'cole aqui o token'];
    // O token vem do Gerenciador de Eventos: conjunto de dados 2224500131617302 > Configurações > API de
    // Conversões > Gerar token de acesso. Nunca o mande por chat nem e-mail.
    'META_CAPI_TOKEN' => '',
    // Código da aba "Testar eventos" do Gerenciador, só enquanto confere (os eventos não entram nas campanhas).
    'META_CAPI_TESTE' => '',
    // Opcionais: outra versão da API (padrão em lib/meta.php) e outro pixel. Não mexa sem motivo.
    'META_CAPI_VERSAO' => '',
    'META_PIXEL_ID' => '',

    // Quem vende e recebe (decisão do dono, 08/10/2026): um nome só em página, checkout, /reembolso/, e-mails e
    // comprovante. Vazio = O-CVB Filial Rio de Janeiro Ensino Ltda, 67.733.551/0001-35 (lib/config.php: a empresa de
    // ensino da filial, que recebe a taxa e a matrícula; não é o CNPJ da filial).
    'RECEBEDOR_NOME'   => '',
    'RECEBEDOR_CNPJ'   => '',
];
