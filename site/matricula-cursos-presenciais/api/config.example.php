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
    // Só em teste operacional: cobra este valor no lugar da inscrição e mostra um aviso na
    // página. Ex.: 100 = R$ 1,00. REMOVA antes de receber aluno.
    'PRECO_TESTE_CENTAVOS' => '',

    // Custos de processamento que o aluno pode escolher cobrir (opcional, por vontade própria).
    // Percentual sobre a inscrição + parcela fixa em centavos, por método.
    // Decisão de 18/09/2026: 5% em todos os métodos (média de PIX, cartão e checkout), sem parcela fixa.
    // (PIX medido pela API em 18/09: 1,00% + R$ 1,48.)
    'TAXA_PIX_PCT'      => 5.0,
    'TAXA_PIX_FIXA'     => 0,
    'TAXA_CARTAO_PCT'   => 5.0,
    'TAXA_CARTAO_FIXA'  => 0,

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

    // Empresa recebedora no comprovante de inscrição em PDF anexado ao e-mail do aluno. Vazio = o
    // padrão de lib/comprovante.php: O-CVB FILIAL RIO DE JANEIRO ENSINO LTDA - EPP, 67.733.551/0001-35
    // (a empresa de ensino da filial, que recebe a matrícula; não é o CNPJ da filial).
    'RECEBEDOR_NOME'   => '',
    'RECEBEDOR_CNPJ'   => '',
];
