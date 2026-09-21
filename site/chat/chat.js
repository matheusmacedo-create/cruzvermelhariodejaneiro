/* Chat de contato por e-mail da Cruz Vermelha Brasileira Rio de Janeiro (site inteiro, sem dependências).
   Cria o botão flutuante e um painel em estilo de conversa que pergunta assunto, curso, nome, e-mail,
   telefone (opcional) e mensagem, e envia tudo para /matricula-cursos-presenciais/api/contato.php, que
   grava, manda por e-mail à equipe e devolve um protocolo. Entrou em 19/09/2026 no lugar do botão do
   WhatsApp da secretaria: o contato passa a chegar por e-mail, com cópia para quem escreveu.
   Fonte: site/chat/chat.js; as páginas referenciam /chat/chat.js?v=<hash> (scripts/chat_widget.py).
   Abrir de fora: qualquer elemento com data-abrir-chat (e opcionalmente data-assunto / data-curso),
   o endereço com #chat, ou window.cvChat.abrir(). */
(function () {
  'use strict';
  if (window.cvChat) return;

  var API = '/matricula-cursos-presenciais/api/contato.php';
  var URL_MATRICULA = '/matricula-cursos-presenciais/';
  var URL_CHECKOUT = URL_MATRICULA + 'checkout/';
  var URL_PRIVACIDADE = '/privacidade/';
  var EMAIL_CONTATO = 'contato@cruzvermelhariodejaneiro.org';
  var PRAZO = '2 dias úteis';
  var INSCRICAO = 'R$ 99,00';
  var CHAVE = 'cv_chat';
  var NOME = 'Cruz Vermelha Brasileira Rio de Janeiro';
  var LOGO = '/bio/img/avatar-256.webp'; // logo oficial (quadrado), o mesmo da bio do Instagram

  /* chat:cursos (reescrito por scripts/gerar_matricula_presencial.py a partir de cursos.json) */
  var CURSOS = [
    { slug: "primeiros-socorros-basico", nome: "Primeiros Socorros Básico", carga: "8 horas", escolaridade: "Ensino Fundamental", valor: "R$ 180,00", descricao: "O Curso de Primeiros Socorros Básico da Cruz Vermelha - Rio de Janeiro forma pessoas capacitadas para reconhecer situações de emergência, prestar o atendimento inicial de forma segura e agir com rapidez até a chegada do serviço especializado.", faq: [
        { p: "Preciso ser da área da saúde?", r: "Não. O curso foi desenvolvido para qualquer pessoa interessada em aprender primeiros socorros." },
        { p: "Recebo certificado?", r: "Sim. Certificado emitido pela Cruz Vermelha Brasileira – Filial do Estado do Rio de Janeiro." },
        { p: "Posso trabalhar como socorrista após o curso?", r: "Não. O curso capacita para prestar atendimento inicial até a chegada do serviço especializado, não habilitando o participante para o exercício profissional de atividades privativas de profissionais regulamentados." },
        { p: "Existe prática?", r: "Sim. O curso possui atividades demonstrativas e práticas supervisionadas." },
        { p: "Há validade para o certificado?", r: "Recomenda-se atualização periódica dos conhecimentos, especialmente em razão das revisões dos protocolos internacionais de atendimento." }
      ] },
    { slug: "primeiros-socorros-lei-lucas", nome: "Primeiros Socorros Lei Lucas - Ambientes com Crianças", carga: "8 horas", escolaridade: "Ensino Fundamental", valor: "R$ 150,00", descricao: "O Curso de Primeiros Socorros em Crianças da Cruz Vermelha forma pessoas capacitadas para reconhecer situações de emergência envolvendo o público infantil e prestar o atendimento inicial com rapidez, segurança e responsabilidade até a chegada do serviço especializado." },
    { slug: "puncao-venosa", nome: "Punção Venosa", carga: "8 horas", escolaridade: "Ensino Médio", valor: "R$ 150,00", descricao: "Técnica de acesso venoso periférico com segurança.", faq: [
        { p: "Quem pode fazer esse curso?", r: "O curso é destinado a estudantes e profissionais da área da saúde que desejam aperfeiçoar suas habilidades técnicas, conforme as normas da profissão." },
        { p: "O curso é prático?", r: "Sim. Grande parte do aprendizado acontece em atividades práticas supervisionadas." },
        { p: "Vou aprender apenas punção?", r: "Além da técnica de punção venosa, o curso aborda biossegurança, prevenção de complicações, escolha de dispositivos e boas práticas assistenciais." },
        { p: "Esse curso melhora minhas oportunidades de emprego?", r: "Sim. A punção venosa é uma competência bastante valorizada em hospitais, clínicas, laboratórios e serviços de saúde." },
        { p: "O certificado é válido?", r: "Sim. O certificado é emitido pela Cruz Vermelha Brasileira Rio de Janeiro ao término do curso." }
      ] },
    { slug: "suporte-basico-de-vida", nome: "Suporte Básico de Vida", carga: "4 horas", escolaridade: "Ensino Fundamental", valor: "R$ 150,00", descricao: "Atendimento inicial de emergências com diretrizes oficiais.", faq: [
        { p: "Para quem é indicado este curso?", r: "É indicado para profissionais da saúde, educação, segurança, empresas e também para qualquer pessoa que deseje aprender a agir corretamente em situações de emergência." },
        { p: "Vou aprender a salvar vidas?", r: "Você aprenderá técnicas essenciais para prestar o primeiro atendimento até a chegada do serviço especializado, aumentando as chances de um atendimento seguro e eficiente." },
        { p: "O curso possui prática?", r: "Sim. A metodologia combina teoria e treinamento prático para desenvolver segurança durante os atendimentos." },
        { p: "Preciso ser profissional da saúde?", r: "Não. O curso é aberto tanto para profissionais quanto para pessoas sem experiência prévia." },
        { p: "O certificado pode enriquecer meu currículo?", r: "Sim. A formação em Primeiros Socorros é um diferencial valorizado em diversas áreas profissionais." }
      ] },
    { slug: "bombeiro-civil", nome: "Bombeiro Civil", carga: "80 horas", escolaridade: "Ensino Médio", valor: "R$ 950,00", descricao: "Formação para atuação em prevenção e combate a incêndios.", faq: [
        { p: "Quem pode fazer o curso de Bombeiro Civil?", r: "Qualquer pessoa que atenda aos pré-requisitos do curso e tenha interesse em atuar na prevenção e combate a incêndios, primeiros socorros e atendimento a emergências." },
        { p: "O certificado é reconhecido?", r: "Sim. O certificado é emitido pela Cruz Vermelha Brasileira Rio de Janeiro, instituição reconhecida nacional e internacionalmente por sua tradição em formação na área humanitária e de emergências." },
        { p: "Onde posso trabalhar após o curso?", r: "O profissional pode atuar em empresas, condomínios, indústrias, hospitais, eventos, centros comerciais, instituições de ensino e outros locais que exigem equipes de prevenção e resposta a emergências." },
        { p: "O curso possui aulas práticas?", r: "Sim. O aluno desenvolve habilidades por meio de atividades práticas que simulam situações reais de emergência." },
        { p: "Preciso ter experiência na área?", r: "Não. O curso foi desenvolvido para formar novos profissionais, desde que atendam aos requisitos de matrícula." }
      ] },
    { slug: "cuidador-de-idosos", nome: "Cuidador de Idosos (Curso Livre)", carga: "160 horas", escolaridade: "Ensino Fundamental", valor: "R$ 950,00", descricao: "Cuidados, segurança e bem-estar no atendimento ao idoso.", faq: [
        { p: "Quem pode fazer esse curso?", r: "Qualquer pessoa interessada em atuar no cuidado de pessoas idosas, seja profissionalmente ou para cuidar de familiares." },
        { p: "O cuidador de idosos pode trabalhar no exterior?", r: "O curso oferece excelente formação, porém a possibilidade de atuação em outro país depende da legislação e das exigências específicas de cada local, podendo ser necessária complementação ou validação da formação." },
        { p: "Onde posso trabalhar?", r: "O cuidador pode atuar em residências, instituições de longa permanência, clínicas, centros de convivência e serviços de assistência ao idoso." },
        { p: "O curso ensina cuidados práticos?", r: "Sim. O aluno aprende técnicas de cuidados diários, mobilização, higiene, alimentação, prevenção de acidentes, primeiros socorros, ética e humanização no atendimento." },
        { p: "Existe mercado para cuidadores de idosos?", r: "Sim. Com o aumento da população idosa, a demanda por profissionais qualificados cresce continuamente, tornando essa uma área com excelentes oportunidades." }
      ] },
    { slug: "micropigmentacao-labial", nome: "Micropigmentação Labial", carga: "24 horas", escolaridade: "Ensino Médio", valor: "R$ 400,00", descricao: "Procedimento estético de micropigmentação dos lábios.", faq: [
        { p: "Preciso já trabalhar com estética?", r: "Não. O curso atende tanto iniciantes quanto profissionais que desejam ampliar seus serviços." },
        { p: "Vou aprender a técnica na prática?", r: "Sim. O curso possui abordagem prática para desenvolver segurança e qualidade na execução da técnica." },
        { p: "Posso começar a atender clientes após o curso?", r: "Após concluir o curso e respeitando a legislação aplicável à sua profissão, você estará preparado para iniciar seus atendimentos." },
        { p: "Quais são os diferenciais do curso?", r: "Você aprenderá técnicas atuais, biossegurança, avaliação do cliente, colorimetria, cuidados pré e pós-procedimento e orientações para melhores resultados." },
        { p: "Recebo certificado?", r: "Sim. Ao concluir todas as etapas do curso, o aluno recebe certificado emitido pela Cruz Vermelha Brasileira Rio de Janeiro." }
      ] }
  ];
  /* /chat:cursos */

  /* chat:respostas (reescrito por scripts/chat_widget.py a partir de site/faq-home.json) */
  var RESPOSTAS = [
    { assuntos: ["matricula", "curso"], rotulo: "Como faço a matrícula?", p: "Como me matricular em um curso da Cruz Vermelha Brasileira Rio de Janeiro?", r: "Escolha o curso na página de matrícula em cursos presenciais e clique em Fazer matrícula agora: você informa nome, CPF, e-mail e telefone e paga a inscrição de R$ 99 por PIX ou cartão, à vista. Não é preciso criar conta nem escolher turma nessa etapa. Com o pagamento confirmado, a secretaria da Cruz Vermelha Brasileira Rio de Janeiro entra em contato por e-mail em até 2 dias úteis para confirmar turma e horário; o valor do curso é pago depois, na plataforma da escola. Quer ver as turmas abertas antes de pagar? Elas estão em escola.cursoscruzvermelha.org. Dúvidas? Use o chat do site, que responde por e-mail." },
    { assuntos: ["matricula", "curso", "pagamento"], rotulo: "Quanto custa?", p: "Quanto custa fazer um curso na Cruz Vermelha Brasileira Rio de Janeiro?", r: "Em todos os cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro a inscrição custa R$ 99, à vista, por PIX ou cartão, e reserva a vaga. O valor do curso é pago depois, na plataforma da escola, à vista: R$ 150 em Suporte Básico de Vida, Punção Venosa e Primeiros Socorros Lei Lucas; R$ 180 em Primeiros Socorros Básico; R$ 400 em Micropigmentação Labial; R$ 950 em Bombeiro Civil e em Cuidador de Idosos, com a homologação do Bombeiro Civil à parte, valor a consultar. Sem horário compatível, ou se você desistir antes da confirmação da aula, a inscrição é estornada; o prazo depende de PIX ou cartão. Confira tudo na página de matrícula." },
    { assuntos: ["matricula", "curso"], rotulo: "Tem curso gratuito?", p: "A Cruz Vermelha Brasileira Rio de Janeiro tem cursos gratuitos?", r: "Hoje os sete cursos presenciais da Cruz Vermelha Brasileira Rio de Janeiro são pagos: a inscrição de R$ 99 garante a vaga e o valor do curso, de R$ 150 a R$ 950, é pago depois na plataforma da escola. Não há curso gratuito de enfermagem, técnico ou de primeiros socorros no catálogo. Quem quer aprender e servir pode se cadastrar como voluntário: a filial forma os próprios voluntários, em um caminho separado dos cursos. Quem doa online recebe, como agradecimento, acesso futuro a cursos gravados gratuitos. Novas turmas e ações abertas ao público são divulgadas nas notícias do site e no Instagram @cruzvermelhabrasileirarj." },
    { assuntos: ["matricula", "curso"], rotulo: "Qual a escolaridade mínima?", p: "Qual é a escolaridade mínima dos cursos? Preciso ser da área da saúde?", r: "Depende do curso. Primeiros Socorros Básico, Primeiros Socorros Lei Lucas, Suporte Básico de Vida e Cuidador de Idosos pedem Ensino Fundamental; Bombeiro Civil, Punção Venosa e Micropigmentação Labial pedem Ensino Médio. Os cursos de primeiros socorros e o Suporte Básico de Vida da Cruz Vermelha Brasileira Rio de Janeiro são abertos a qualquer pessoa, sem experiência na área da saúde. Punção Venosa é voltado a estudantes e profissionais da saúde, conforme as normas da profissão. Bombeiro Civil e Micropigmentação Labial não exigem experiência prévia. Confira os requisitos de cada curso na página de matrícula antes de garantir a vaga com a inscrição de R$ 99." },
    { assuntos: ["matricula", "curso"], rotulo: "Quanto tempo dura?", p: "Quanto tempo dura cada curso da Cruz Vermelha Brasileira Rio de Janeiro?", r: "As cargas horárias são: Suporte Básico de Vida, 4 horas; Primeiros Socorros Básico, Lei Lucas - Ambientes com Crianças e Punção Venosa, 8 horas cada; Micropigmentação Labial, 24 horas; Bombeiro Civil, 80 horas; Cuidador de Idosos, 160 horas. Quem busca atualização rápida em primeiros socorros resolve em um dia de treinamento, de 4 a 8 horas presenciais, com prática supervisionada e certificado ao final. Os cursos mais longos são divididos em encontros, conforme a turma. Veja a carga horária e as datas de cada um na página de matrícula." },
    { assuntos: ["matricula", "curso"], rotulo: "É online ou presencial?", p: "Os cursos da Cruz Vermelha Brasileira Rio de Janeiro são online ou presenciais?", r: "Todos são presenciais, na Praça da Cruz Vermelha, 10, Centro do Rio de Janeiro. Não oferecemos primeiros socorros a distância: RCP, desengasgo e controle de hemorragias pedem prática com manequim e instrutor ao lado, e é isso que dá peso ao certificado. A matrícula, essa sim, é feita pela internet. O certificado é emitido pela Cruz Vermelha Brasileira Rio de Janeiro e vale em todo o país, não apenas no estado do Rio: certificado de curso livre não tem validade por região. Escolha a sua turma na página de matrícula." },
    { assuntos: ["curso", "matricula"], rotulo: "O certificado é reconhecido pelo MEC?", p: "O certificado dos cursos é reconhecido pelo MEC?", r: "Não, e nenhum curso livre é: o MEC regula a educação formal - ensino técnico, graduação e pós - e não emite nem reconhece diploma de curso livre de primeiros socorros, cuidador de idosos ou bombeiro civil, aqui ou em qualquer outra instituição. O que existe é o certificado de quem formou você, com carga horária e conteúdo descritos. O nosso é emitido pela Cruz Vermelha Brasileira Rio de Janeiro, reconhecida nacional e internacionalmente pela tradição em formação humanitária. No Bombeiro Civil, a homologação profissional é feita ao final do curso, à parte." },
    { assuntos: ["curso", "outro"], rotulo: "Treinamento para minha empresa ou escola", p: "A Cruz Vermelha Brasileira Rio de Janeiro faz treinamento de primeiros socorros, Lei Lucas e NR para empresas e escolas?", r: "Sim. A Cruz Vermelha Brasileira Rio de Janeiro atende empresas e escolas com treinamentos corporativos de NR, capacitação em primeiros socorros pela Lei Lucas (indicada para escolas, creches e equipes que trabalham com crianças) e programas customizados para cada equipe. É assim que as empresas parceiras apoiam a filial, junto com programas de responsabilidade social; empresas também podem contribuir com doações institucionais e patrocínio de campanhas, como a Campanha do Agasalho. Para pedir uma proposta ou um treinamento para sua equipe, fale com a gente pelo chat do site ou pelo e-mail contato@cruzvermelhariodejaneiro.org: a equipe responde por e-mail em até 2 dias úteis." },
    { assuntos: ["voluntariado"], rotulo: "Como ser voluntário?", p: "Como ser voluntário da Cruz Vermelha Brasileira Rio de Janeiro?", r: "Para ser voluntário da Cruz Vermelha Brasileira Rio de Janeiro, preencha o cadastro no formulário oficial do voluntariado. Depois, a equipe do voluntariado entra em contato para explicar as frentes de atuação, a formação inicial, que acontece na sede, no Centro do Rio, e os próximos encontros. Convocações para ações, campanhas e turmas de formação também são divulgadas no Instagram @cruzvermelhabrasileirarj e nas notícias do site. Dúvidas antes de se cadastrar? Fale com a equipe pelo WhatsApp do voluntariado, (21) 97036-0264, número exclusivo dessa equipe, ou deixe sua mensagem no chat de contato do site. Os canais oficiais estão reunidos na página de links da filial." },
    { assuntos: ["voluntariado"], rotulo: "Quem pode ser voluntário?", p: "Quem pode ser voluntário? Precisa ser da área da saúde ou fazer um curso pago?", r: "Não precisa ser profissional de saúde: a Cruz Vermelha Brasileira Rio de Janeiro forma os próprios voluntários. Depois do cadastro no formulário oficial, a equipe do voluntariado apresenta as frentes de atuação e a formação inicial, realizada na sede, no Centro do Rio. A filial atua em formação de voluntários, capacitação em primeiros socorros, educação preventiva e apoio comunitário, com ações como a Campanha do Agasalho e o Impacto das Cores: há espaço para perfis diferentes. Voluntariado e cursos são caminhos separados, com equipes e canais próprios; quem quiser se aprofundar em emergências pode, à parte, fazer o curso de Primeiros Socorros Básico." },
    { assuntos: ["doacoes"], rotulo: "Como doar? É seguro?", p: "Como doar para a Cruz Vermelha Brasileira Rio de Janeiro? A doação é segura?", r: "Você doa pela página de doação da Cruz Vermelha Brasileira Rio de Janeiro: escolhe o valor, define se a doação é única ou mensal e conclui o pagamento por PIX ou cartão no ambiente seguro de doação da instituição. A doação fica na filial do Estado do Rio de Janeiro e apoia formação de voluntários, capacitação em primeiros socorros, ações comunitárias, comunicação e estrutura operacional; CNPJ, endereço e e-mail estão publicados no site para conferência. Quem doa online recebe, como agradecimento, acesso futuro a cursos gravados gratuitos. Prefere doar roupas e cobertores? Veja a Campanha do Agasalho." },
    { assuntos: ["doacoes"], rotulo: "Onde entregar roupas e cobertores?", p: "Onde doar roupas, agasalhos e cobertores para a Cruz Vermelha no Rio de Janeiro?", r: "Roupas de frio, cobertores e calçados são recebidos na sede da Cruz Vermelha Brasileira Rio de Janeiro, na Praça da Cruz Vermelha, 10, Centro, pela Campanha do Agasalho, de segunda a sexta, das 10h às 17h. A triagem prioriza casacos e agasalhos, cobertores e mantas sem rasgos, meias, luvas, gorros, cachecóis, blusas de manga longa e calçados fechados, em pares. Leve as peças limpas, em bom estado e separadas por tipo e tamanho: assim a ajuda chega mais rápido a quem precisa, sem desperdício. Para outros itens, confirme antes com a equipe pelo chat do site, que responde por e-mail. Quem preferir pode doar em dinheiro pela página de doação." },
    { assuntos: ["outro"], rotulo: "Vocês atendem emergência ou consulta?", p: "A Cruz Vermelha no Rio de Janeiro atende emergências ou marca consultas?", r: "Neste site você encontra os cursos, o voluntariado, as doações e o contato da Cruz Vermelha Brasileira Rio de Janeiro, sediada no Palácio da Cruz Vermelha, na Praça da Cruz Vermelha, 10, Centro do Rio. Aqui não há agendamento de consultas nem atendimento de urgência: em uma emergência, ligue 192 (SAMU) ou 193 (Corpo de Bombeiros). O que a filial oferece é preparo para agir até a chegada do socorro: os cursos de Primeiros Socorros e de Suporte Básico de Vida ensinam RCP, controle de hemorragias e desengasgo a qualquer pessoa, com certificado. Para outros assuntos, fale com a equipe pelo chat do site, que responde por e-mail em até 2 dias úteis." },
    { assuntos: ["outro", "matricula"], rotulo: "Onde fica e qual o horário?", p: "Onde fica a Cruz Vermelha Brasileira Rio de Janeiro e qual é o horário?", r: "A sede da Cruz Vermelha Brasileira Rio de Janeiro fica no Palácio da Cruz Vermelha, na Praça da Cruz Vermelha, 10, Centro, Rio de Janeiro, CEP 20230-130. É lá que acontecem os cursos presenciais, a formação de voluntários e o recebimento de donativos da Campanha do Agasalho, de segunda a sexta, das 10h às 17h. O CNPJ da filial é 08.560.973/0001-97. Antes de ir, confirme o que precisa pelo chat do site ou pelo e-mail contato@cruzvermelhariodejaneiro.org: a equipe responde em até 2 dias úteis. A seção de contato traz o endereço com link para o mapa." },
    { assuntos: ["curso", "matricula"], rotulo: "Tem curso em outras cidades?", p: "Tem cursos da Cruz Vermelha em Nova Iguaçu, Cabo Frio ou em outras cidades do estado?", r: "Os cursos presenciais divulgados neste site acontecem na sede da Cruz Vermelha Brasileira Rio de Janeiro, na Praça da Cruz Vermelha, 10, Centro do Rio, região servida por metrô, trem, VLT e ônibus. Quem mora em Nova Iguaçu, Cabo Frio, na Baixada, na Região dos Lagos ou em outra cidade do estado participa das turmas no Centro; a secretaria confirma turma e horário por e-mail depois da inscrição de R$ 99. Empresas e escolas de qualquer município podem pedir um treinamento fechado para a equipe pelo chat do site. Veja carga horária, valores e requisitos na página de matrícula em cursos presenciais." },
    { assuntos: ["outro"], rotulo: "Tem telefone ou WhatsApp?", p: "Como falar com a Cruz Vermelha Brasileira Rio de Janeiro? Tem telefone ou WhatsApp?", r: "O atendimento da Cruz Vermelha Brasileira Rio de Janeiro é por e-mail: use o chat Fale com a gente, no canto de qualquer página, ou escreva para contato@cruzvermelhariodejaneiro.org. A equipe responde em até 2 dias úteis, com o número de protocolo no assunto; vale conferir a caixa de spam. Esse canal atende cursos, matrícula, doações, campanhas e parcerias, e os dados completos estão na seção de contato. Para o voluntariado existe um WhatsApp próprio, (21) 97036-0264, exclusivo para quem quer ser voluntário; o cadastro é feito no formulário do voluntariado. Para acompanhar as ações da filial, veja as notícias e o Instagram @cruzvermelhabrasileirarj." }
  ];
  /* /chat:respostas */

  var ASSUNTOS = [
    ['matricula', 'Matrícula em cursos'],
    ['curso', 'Dúvida sobre um curso'],
    ['pagamento', 'Pagamento ou PIX'],
    ['voluntariado', 'Voluntariado'],
    ['doacoes', 'Doações e parcerias'],
    ['outro', 'Outro assunto']
  ];
  var COM_CURSO = ['matricula', 'curso', 'pagamento'];
  var ORDEM = ['assunto', 'curso', 'nome', 'email', 'telefone', 'mensagem'];
  var ROTULOS = { assunto: 'Assunto', curso: 'Curso', nome: 'Nome', email: 'E-mail', telefone: 'Telefone', mensagem: 'Mensagem' };
  var ICONES = {
    balao: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.1 1 4 2.7 5.4L4 21l4.6-1.9c1.1.3 2.2.4 3.4.4 5.5 0 10-3.6 10-8S17.5 3 12 3zm-4 9.3a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm4 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6z"/></svg>',
    cruz: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 2h6v7h7v6h-7v7H9v-7H2V9h7z"/></svg>',
    x: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.3 5.7a1 1 0 0 0-1.4 0L12 10.6 7.1 5.7a1 1 0 1 0-1.4 1.4l4.9 4.9-4.9 4.9a1 1 0 1 0 1.4 1.4l4.9-4.9 4.9 4.9a1 1 0 0 0 1.4-1.4L13.4 12l4.9-4.9a1 1 0 0 0 0-1.4z"/></svg>',
    enviar: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.4 20.4l17.5-7.5c.8-.4.8-1.5 0-1.8L3.4 3.6c-.7-.3-1.4.3-1.4.9v5.2c0 .5.4.9.9 1L15 12 2.9 13.3c-.5.1-.9.5-.9 1v5.2c0 .7.7 1.2 1.4.9z"/></svg>'
  };

  // ------------------------------------------------------------------ utilidades
  function el(tag, attrs, filhos) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (k === 'html') e.innerHTML = v;
      else if (k === 'text') e.textContent = v;
      else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), v);
      else if (v === false || v == null) return;
      else e.setAttribute(k, v === true ? '' : v);
    });
    (filhos || []).forEach(function (f) { if (f) e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
    return e;
  }
  function digitos(v) { return String(v || '').replace(/\D+/g, ''); }
  function telefoneBonito(v) {
    var d = digitos(v).slice(0, 11), corte = d.length > 10 ? 7 : 6;
    if (d.length < 10) return v;
    return '(' + d.slice(0, 2) + ') ' + d.slice(2, corte) + '-' + d.slice(corte);
  }
  function rotuloAssunto(chave) {
    for (var i = 0; i < ASSUNTOS.length; i++) if (ASSUNTOS[i][0] === chave) return ASSUNTOS[i][1];
    return chave;
  }
  function curso(slug) {
    for (var i = 0; i < CURSOS.length; i++) if (CURSOS[i].slug === slug) return CURSOS[i];
    return null;
  }
  function nomeCurso(slug) { var c = curso(slug); return c ? c.nome : ''; }
  /* Curso em foco na página: ?curso= na URL, curso aberto na matrícula ou escolhido no checkout. */
  function cursoDaPagina() {
    var q = new URLSearchParams(location.search).get('curso');
    if (q && curso(q)) return q;
    var ativo = document.querySelector('.mr-detalhe.ativo[data-curso]');
    if (ativo && curso(ativo.getAttribute('data-curso'))) return ativo.getAttribute('data-curso');
    var sel = document.getElementById('ck-curso');
    if (sel && sel.value && curso(sel.value)) return sel.value;
    return '';
  }
  /* utm_*, fbclid e gclid: da URL atual ou os que o checkout guardou na sessão (mesma chave). */
  function origem() {
    var o = {};
    new URLSearchParams(location.search).forEach(function (v, k) { if (/^(utm_|fbclid$|gclid$)/.test(k)) o[k] = v.slice(0, 255); });
    try {
      var salvo = JSON.parse(sessionStorage.getItem('mcp_origem') || '{}');
      Object.keys(salvo).forEach(function (k) { if (!o[k]) o[k] = salvo[k]; });
    } catch (e) { /* armazenamento bloqueado */ }
    return o;
  }
  function rastrear(eventoGa, dadosGa, eventoMeta, dadosMeta) {
    try { if (window.gtag && eventoGa) window.gtag('event', eventoGa, dadosGa || {}); } catch (e) { /* GA4 ausente */ }
    try { if (window.fbq && eventoMeta) window.fbq('track', eventoMeta, dadosMeta || {}); } catch (e) { /* pixel ausente */ }
  }

  // ------------------------------------------------------------------ estado (sobrevive à navegação entre páginas)
  function novoEstado() { return { aberto: false, passo: 'assunto', respostas: {}, editando: false, erro: '',
    protocolo: '', aberturaRastreada: false, duvidasVistas: false, duvidasLidas: [] }; }
  function carregar() {
    try {
      var s = JSON.parse(sessionStorage.getItem(CHAVE) || 'null');
      if (s && s.respostas && typeof s.passo === 'string') { s.enviando = false; return s; }
    } catch (e) { /* sem sessão guardada */ }
    return novoEstado();
  }
  function guardar() { try { sessionStorage.setItem(CHAVE, JSON.stringify(estado)); } catch (e) { /* segue sem guardar */ } }
  var estado = carregar();

  function precisaCurso() { return COM_CURSO.indexOf(estado.respostas.assunto) >= 0; }
  function respondido(passo) {
    var r = estado.respostas;
    if (passo === 'curso') return !precisaCurso() || Object.prototype.hasOwnProperty.call(r, 'curso');
    if (passo === 'telefone') return Object.prototype.hasOwnProperty.call(r, 'telefone');
    return typeof r[passo] === 'string' && r[passo] !== '';
  }
  var MAX_DUVIDAS = 5;  // mais que isso vira parede de botão no celular

  /* O curso escolhido, quando há ficha ou dúvidas dele para mostrar. */
  function fichaDoCurso() {
    if (!precisaCurso() || !estado.respostas.curso) return null;
    var c = curso(estado.respostas.curso);
    return c && (c.carga || c.valor || (c.faq && c.faq.length)) ? c : null;
  }
  /* O que o chat sabe responder aqui: as dúvidas do curso escolhido ou, na falta dele, as
     respostas da FAQ marcadas para este assunto. Sempre texto escrito e revisado por gente. */
  function duvidasDoPasso() {
    var c = fichaDoCurso();
    if (c && c.faq && c.faq.length) return c.faq.slice(0, MAX_DUVIDAS);
    var assunto = estado.respostas.assunto;
    return RESPOSTAS.filter(function (x) { return x.assuntos.indexOf(assunto) >= 0; })
      .slice(0, MAX_DUVIDAS).map(function (x) { return { p: x.p, r: x.r, rotulo: x.rotulo }; });
  }
  function temOQueResponder() { return !!fichaDoCurso() || duvidasDoPasso().length > 0; }
  function proximoPasso() {
    // Antes de pedir nome e e-mail: o que a escola já respondeu sobre este curso. Muita gente
    // para por aqui, e quem para não precisou abrir chamado nem esperar dois dias úteis.
    if (!estado.duvidasVistas && respondido('assunto') && respondido('curso') && temOQueResponder()) return 'duvidas';
    for (var i = 0; i < ORDEM.length; i++) if (!respondido(ORDEM[i])) return ORDEM[i];
    return 'revisar';
  }

  /* Ficha do curso e as dúvidas que a escola já respondeu, como botões.
     Botão em vez de adivinhação: a resposta é sempre a que a escola escreveu, e o chat nunca
     precisa interpretar o que a pessoa quis dizer — não tem como responder errado. */
  function fichaHtml(c) {
    var linhas = [];
    if (c.carga) linhas.push(escapar(c.carga) + ' presenciais, na sede (Centro do Rio)');
    if (c.escolaridade) linhas.push('Escolaridade mínima: ' + escapar(c.escolaridade));
    if (c.valor) linhas.push('Curso: ' + escapar(c.valor) + ' · Inscrição: ' + INSCRICAO);
    linhas.push('Certificado da ' + NOME);
    var html = 'Sobre o <b>' + escapar(c.nome) + '</b>:\n· ' + linhas.join('\n· ');
    return c.descricao ? html + '\n\n' + escapar(c.descricao) : html;
  }
  function jaLida(pergunta) {
    for (var i = 0; i < estado.duvidasLidas.length; i++) if (estado.duvidasLidas[i].p === pergunta) return true;
    return false;
  }
  function telaDuvidas() {
    var c = fichaDoCurso();
    var lista = duvidasDoPasso().filter(function (q) { return !jaLida(q.p); });
    var botoes = lista.map(function (q) { return { valor: 'q' + q.p, rotulo: q.rotulo || q.p }; });
    if (c) botoes.push({ valor: 'matricula', rotulo: 'Fazer matrícula em ' + c.nome, classe: 'cheio' });
    else if (precisaCurso()) botoes.push({ href: URL_MATRICULA, rotulo: 'Ver cursos e matrícula', classe: 'cheio' });
    botoes.push({ valor: 'seguir', rotulo: 'Escrever para a equipe', classe: 'neutro' });
    var abertura = estado.duvidasLidas.length
      ? (lista.length ? 'Ficou mais alguma?' : 'Era o que eu tinha aqui. Quer falar com a equipe?')
      : (lista.length ? 'Posso responder alguma destas agora?' : 'Quer falar com a equipe?');
    return { html: abertura, chips: botoes };
  }

  // ------------------------------------------------------------------ perguntas do robô e respostas da pessoa
  function pergunta(passo) {
    var r = estado.respostas, daPagina, chips;
    switch (passo) {
      case 'assunto':
        return { html: 'Oi! Aqui é o atendimento da <b>' + NOME + '</b>. Deixe sua mensagem e a nossa equipe responde <b>por e-mail em até ' + PRAZO + '</b>.\nSobre o que você quer falar?',
          chips: ASSUNTOS.map(function (a) { return { valor: a[0], rotulo: a[1] }; }) };
      case 'curso':
        daPagina = cursoDaPagina();
        chips = CURSOS.filter(function (c) { return c.slug === daPagina; }).concat(CURSOS.filter(function (c) { return c.slug !== daPagina; }))
          .map(function (c) { return { valor: c.slug, rotulo: c.nome + (c.slug === daPagina ? ' (este curso)' : '') }; });
        chips.push({ valor: '', rotulo: 'Ainda não sei', classe: 'neutro' });
        return { html: 'Sobre qual curso?', chips: chips };
      case 'duvidas':
        return telaDuvidas();
      case 'nome':
        return { html: 'Como você se chama?', entrada: { tipo: 'text', autocomplete: 'name', placeholder: 'Seu nome' } };
      case 'email':
        return { html: 'Qual e-mail podemos usar para responder?', entrada: { tipo: 'email', autocomplete: 'email', placeholder: 'voce@exemplo.com' } };
      case 'telefone':
        return { html: 'Se quiser um retorno também por telefone, informe com DDD. É opcional.', entrada: { tipo: 'tel', autocomplete: 'tel', placeholder: '(21) 99999-9999' },
          chips: [{ valor: '', rotulo: 'Prefiro só por e-mail', classe: 'neutro' }] };
      case 'mensagem':
        return { html: 'Pode escrever sua mensagem. Quanto mais detalhes, melhor a resposta.', entrada: { tipo: 'textarea', autocomplete: 'off', placeholder: 'Escreva aqui…' } };
      case 'revisar':
        return { html: 'Tudo certo? Confira antes de enviar.', resumo: true,
          chips: [{ valor: 'enviar', rotulo: 'Enviar mensagem', classe: 'cheio' }, { valor: 'corrigir', rotulo: 'Corrigir algo', classe: 'neutro' }] };
      case 'corrigir':
        return { html: 'O que você quer corrigir?', chips: ORDEM.filter(function (p) { return p !== 'curso' || precisaCurso(); })
          .map(function (p) { return { valor: p, rotulo: ROTULOS[p] }; }).concat([{ valor: 'voltar', rotulo: 'Voltar', classe: 'neutro' }]) };
      case 'enviado':
        chips = [];
        if (precisaCurso() && r.curso) chips.push({ href: URL_CHECKOUT + '?curso=' + encodeURIComponent(r.curso), rotulo: 'Fazer matrícula em ' + nomeCurso(r.curso) });
        else if (precisaCurso()) chips.push({ href: URL_MATRICULA, rotulo: 'Ver cursos e matrícula' });
        chips.push({ acao: copiarEmail, rotulo: 'Copiar e-mail da equipe', classe: 'neutro' });
        chips.push({ valor: 'nova', rotulo: 'Nova mensagem', classe: 'neutro' }, { valor: 'fechar', rotulo: 'Fechar', classe: 'neutro' });
        return { html: 'Recebemos sua mensagem, <b>' + escapar(primeiroNome(r.nome)) + '</b>! Protocolo <b>' + escapar(estado.protocolo) + '</b>.\n\nA resposta chega <b>por e-mail</b>, em <b>' + escapar(r.email) + '</b>, em até ' + PRAZO + ', com o protocolo no assunto. Fique de olho na caixa de entrada e no spam.\n\nDaqui em diante a conversa segue por e-mail. Para não perder a resposta, salve <b>' + EMAIL_CONTATO + '</b> nos seus contatos.', chips: chips };
      case 'falhou':
        return { html: escapar(estado.erro || 'Não consegui enviar agora.') + '\nVocê pode tentar de novo ou escrever direto para <a href="' + linkEmail() + '">' + EMAIL_CONTATO + '</a>.',
          chips: [{ valor: 'tentar', rotulo: 'Tentar de novo', classe: 'cheio' }, { href: linkEmail(), rotulo: 'Escrever por e-mail', classe: 'neutro' }] };
    }
    return null;
  }
  function resposta(passo) {
    var r = estado.respostas;
    if (passo === 'assunto') return rotuloAssunto(r.assunto);
    if (passo === 'curso') return r.curso ? nomeCurso(r.curso) : 'Ainda não sei';
    if (passo === 'telefone') return r.telefone ? r.telefone : 'Prefiro só por e-mail';
    return r[passo] || '';
  }
  function escapar(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function primeiroNome(nome) { return String(nome || '').trim().split(/\s+/)[0] || ''; }
  function copiarEmail(botao) {
    var feito = function (ok) { botao.textContent = ok ? 'E-mail copiado' : EMAIL_CONTATO; };
    if (navigator.clipboard) navigator.clipboard.writeText(EMAIL_CONTATO).then(function () { feito(true); }, function () { feito(false); });
    else feito(false);
  }
  function linkEmail() {
    var r = estado.respostas;
    var corpo = (r.mensagem || '') + '\n\nNome: ' + (r.nome || '') + (r.telefone ? '\nTelefone: ' + r.telefone : '') + (r.curso ? '\nCurso: ' + nomeCurso(r.curso) : '');
    return 'mailto:' + EMAIL_CONTATO + '?subject=' + encodeURIComponent('Contato pelo site: ' + rotuloAssunto(r.assunto || 'outro')) + '&body=' + encodeURIComponent(corpo);
  }
  function validar(passo, valor) {
    if (passo === 'nome' && valor.length < 2) return 'Digite seu nome para a gente saber com quem fala.';
    if (passo === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(valor)) return 'Esse e-mail não parece válido. Confira e envie de novo.';
    if (passo === 'telefone' && valor !== '' && (digitos(valor).length < 10 || digitos(valor).length > 11)) return 'Telefone incompleto. Use DDD + número, ou toque em "Prefiro só por e-mail".';
    if (passo === 'mensagem' && valor.length < 10) return 'Conte um pouco mais: a mensagem precisa ter pelo menos 10 caracteres.';
    if (passo === 'mensagem' && valor.length > 3000) return 'A mensagem pode ter até 3.000 caracteres.';
    return '';
  }

  // ------------------------------------------------------------------ interface
  var raiz, botao, painel, mensagens, leitor, form, campo, enviarBtn, contagem = 0;

  function balao(classe, conteudo, texto) {
    var b = el('div', { class: 'cv-chat-msg ' + classe });
    if (texto) b.textContent = conteudo; else b.innerHTML = conteudo;
    return b;
  }
  function chips(lista, passo) {
    return el('div', { class: 'cv-chat-chips' }, lista.map(function (c) {
      var classe = 'cv-chat-chip' + (c.classe ? ' ' + c.classe : '');
      if (c.href) return el('a', { class: classe, href: c.href, text: c.rotulo });
      if (c.acao) return el('button', { class: classe, type: 'button', text: c.rotulo, onclick: function () { c.acao(this); } });
      return el('button', { class: classe, type: 'button', text: c.rotulo, onclick: function () { escolher(passo, c.valor); } });
    }));
  }
  function resumo() {
    var dl = el('dl');
    ORDEM.forEach(function (p) {
      if (p === 'curso' && !precisaCurso()) return;
      dl.appendChild(el('dt', { text: ROTULOS[p] }));
      dl.appendChild(el('dd', { text: resposta(p) }));
    });
    return el('div', { class: 'cv-chat-resumo' }, [dl]);
  }
  function digitando() { return el('div', { class: 'cv-chat-digitando', 'aria-label': 'Enviando…' }, [el('i'), el('i'), el('i')]); }

  function render() {
    raiz.classList.toggle('aberto', estado.aberto);
    botao.setAttribute('aria-expanded', String(estado.aberto));
    if (estado.aberto) botao.setAttribute('aria-label', 'Fechar o chat'); else botao.removeAttribute('aria-label');
    painel.hidden = !estado.aberto;
    if (!estado.aberto) return;

    var itens = [];
    ORDEM.forEach(function (p) {
      if (p === 'curso' && !precisaCurso()) return;
      if (!respondido(p) || p === estado.passo) return;
      itens.push(balao('robo', pergunta(p).html));
      itens.push(balao('pessoa', resposta(p), true));
      // A ficha do curso e as dúvidas já respondidas ficam na conversa, logo depois do curso:
      // quem seguiu para o e-mail continua vendo o que já foi dito.
      if (p === 'curso' && (estado.duvidasLidas.length || estado.passo === 'duvidas' || estado.duvidasVistas)) {
        if (fichaDoCurso()) itens.push(balao('robo', fichaHtml(fichaDoCurso())));
        estado.duvidasLidas.forEach(function (d) {
          itens.push(balao('pessoa', d.p, true));
          itens.push(balao('robo', escapar(d.r)));
        });
      }
    });
    var q = pergunta(estado.passo);
    if (q) {
      itens.push(balao('robo', q.html));
      if (estado.erro && estado.passo !== 'falhou') itens.push(balao('robo erro', estado.erro, true));
      if (q.resumo) itens.push(resumo());
      if (estado.enviando) itens.push(digitando());
      else if (q.chips) itens.push(chips(q.chips, estado.passo));
    }
    var entrada = q && q.entrada && !estado.enviando ? q.entrada : null;
    campo.disabled = !entrada;
    enviarBtn.disabled = !entrada;
    if (entrada) {
      campo.placeholder = entrada.placeholder;
      campo.setAttribute('inputmode', entrada.tipo === 'email' ? 'email' : entrada.tipo === 'tel' ? 'tel' : 'text');
      campo.setAttribute('autocomplete', entrada.autocomplete);
      campo.setAttribute('enterkeyhint', 'send');
    } else {
      campo.placeholder = estado.passo === 'enviado' ? 'Mensagem enviada' : estado.enviando ? 'Enviando…' : 'Escolha uma opção acima';
      campo.value = '';
      ajustarAltura();
    }
    mensagens.innerHTML = '';
    itens.forEach(function (n, i) { if (i >= contagem) n.classList.add('nova'); mensagens.appendChild(n); });
    contagem = itens.length;
    mensagens.scrollTop = mensagens.scrollHeight;
    // Leitor de tela: só a fala nova do robô (a conversa inteira é redesenhada a cada passo).
    var falas = mensagens.querySelectorAll('.cv-chat-msg.robo');
    var ultima = falas.length ? falas[falas.length - 1].textContent : '';
    if (leitor.textContent !== ultima) leitor.textContent = ultima;
  }
  function focar() {
    if (!estado.aberto) return;
    if (!campo.disabled) { campo.focus({ preventScroll: true }); return; }
    var chip = mensagens.querySelector('.cv-chat-chip');
    if (chip) chip.focus({ preventScroll: true });
  }
  function ajustarAltura() {
    campo.style.height = 'auto';
    campo.style.height = Math.min(140, Math.max(44, campo.scrollHeight)) + 'px';
  }

  // ------------------------------------------------------------------ fluxo
  function responder(passo, valor) {
    estado.erro = '';
    estado.respostas[passo] = valor;
    if (estado.editando) {
      estado.editando = false;
      estado.passo = (precisaCurso() && !respondido('curso')) ? 'curso' : 'revisar';
      if (estado.passo === 'curso') estado.editando = true;
    } else {
      estado.passo = proximoPasso();
    }
    guardar(); render(); focar();
  }
  function escolher(passo, valor) {
    estado.erro = '';
    if (passo === 'revisar') {
      if (valor === 'enviar') { enviar(); return; }
      estado.passo = 'corrigir'; guardar(); render(); focar(); return;
    }
    if (passo === 'corrigir') {
      if (valor === 'voltar') { estado.passo = 'revisar'; } else { estado.passo = valor; estado.editando = true; }
      guardar(); render(); focar(); return;
    }
    if (passo === 'enviado') {
      if (valor === 'fechar') { fechar(); return; }
      var r = estado.respostas;
      estado = novoEstado();
      estado.aberto = true; estado.aberturaRastreada = true;
      estado.respostas = { nome: r.nome, email: r.email, telefone: r.telefone || '' };
      guardar(); render(); focar(); return;
    }
    if (passo === 'falhou') { if (valor === 'tentar') enviar(); return; }
    if (passo === 'duvidas') {
      if (valor === 'matricula') {
        rastrear('chat_duvida_matricula', { curso: estado.respostas.curso });
        location.href = URL_CHECKOUT + '?curso=' + encodeURIComponent(estado.respostas.curso);
        return;
      }
      if (valor === 'seguir') {
        estado.duvidasVistas = true;
        rastrear('chat_duvida_seguiu', { curso: estado.respostas.curso, respostas_abertas: estado.duvidasLidas.length });
        estado.passo = proximoPasso(); guardar(); render(); focar(); return;
      }
      var procurada = String(valor).slice(1), faq = duvidasDoPasso();
      for (var i = 0; i < faq.length; i++) if (faq[i].p === procurada && !jaLida(procurada)) {
        estado.duvidasLidas.push({ p: faq[i].p, r: faq[i].r });
        rastrear('chat_duvida_respondida', { curso: estado.respostas.curso, pergunta: faq[i].p });
      }
      guardar(); render(); focar(); return;
    }
    responder(passo, valor);
  }
  function aoEnviarTexto(e) {
    if (e) e.preventDefault();
    var q = pergunta(estado.passo);
    if (!q || !q.entrada || estado.enviando) return;
    var valor = campo.value.trim();
    var erro = validar(estado.passo, valor);
    if (erro) { estado.erro = erro; render(); campo.focus(); return; }
    if (estado.passo === 'telefone') valor = telefoneBonito(valor);
    if (estado.passo === 'email') valor = valor.toLowerCase();
    campo.value = '';
    ajustarAltura();
    responder(estado.passo, valor);
  }
  function enviar() {
    if (estado.enviando) return;
    var r = estado.respostas;
    estado.enviando = true; estado.erro = ''; estado.passo = 'revisar';
    render();
    var corpo = {
      nome: r.nome, email: r.email, telefone: r.telefone || '', assunto: r.assunto,
      curso: precisaCurso() ? (r.curso || '') : '', mensagem: r.mensagem,
      // O que o chat já respondeu antes do chamado. O servidor confere cada texto contra as
      // perguntas que existem e descarta o resto — nada daqui entra num e-mail sem conferência.
      ja_respondido: estado.duvidasLidas.map(function (d) { return d.p; }),
      pagina: location.pathname + location.search, origem: origem(), site: ''
    };
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(corpo) })
      .then(function (resp) { return resp.json().catch(function () { return null; }).then(function (d) { return { http: resp.status, d: d }; }); })
      .catch(function () { return { http: 0, d: null }; })
      .then(function (x) {
        var d = x.d && typeof x.d === 'object' ? x.d : { ok: false, erro: x.http === 0 ? 'Sem conexão. Verifique a internet e tente de novo.' : 'O servidor não respondeu como esperado.' };
        estado.enviando = false;
        if (d.ok) {
          estado.protocolo = d.protocolo || '';
          estado.passo = 'enviado';
          rastrear('contato_enviado', { assunto: r.assunto, curso: corpo.curso, pagina: location.pathname },
            'Contact', { content_category: r.assunto, content_name: nomeCurso(corpo.curso) || rotuloAssunto(r.assunto) });
        } else if (d.campo && ORDEM.indexOf(d.campo) >= 0) {
          estado.passo = d.campo; estado.editando = true; estado.erro = d.erro || '';
        } else {
          estado.passo = 'falhou'; estado.erro = d.erro || 'Não consegui enviar agora.';
        }
        guardar(); render(); focar();
      });
  }
  function abrir(inicial) {
    if (inicial && estado.passo === 'assunto' && !estado.respostas.assunto) {
      if (inicial.assunto && rotuloAssunto(inicial.assunto) !== inicial.assunto) estado.respostas.assunto = inicial.assunto;
      if (inicial.curso && curso(inicial.curso) && precisaCurso()) estado.respostas.curso = inicial.curso;
      if (estado.respostas.assunto) estado.passo = proximoPasso();
    }
    estado.aberto = true;
    guardar(); render(); focar();
    if (!estado.aberturaRastreada) {
      estado.aberturaRastreada = true; guardar();
      rastrear('contato_aberto', { pagina: location.pathname });
    }
  }
  function fechar() { estado.aberto = false; guardar(); render(); botao.focus(); }

  // ------------------------------------------------------------------ montagem
  function montar() {
    raiz = el('div', { class: 'cv-chat', id: 'cv-chat' });
    botao = el('button', { class: 'cv-chat-abrir', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'cv-chat-painel',
      html: '<span class="cv-chat-abrir-ico">' + ICONES.balao + '</span><span class="cv-chat-abrir-rotulo">Fale com a gente</span><span class="cv-chat-abrir-x">' + ICONES.x + '</span>',
      onclick: function () { if (estado.aberto) fechar(); else abrir(); } });
    var topo = el('header', { class: 'cv-chat-topo', html: '<span class="cv-chat-avatar"><img src="' + LOGO + '" width="44" height="44" alt=""></span><div><b id="cv-chat-titulo">' + NOME + '</b><small><span class="cv-chat-status">Atendimento por e-mail</span> · resposta em até ' + PRAZO + '</small></div>' });
    topo.appendChild(el('button', { class: 'cv-chat-fechar', type: 'button', 'aria-label': 'Fechar o chat', html: ICONES.x, onclick: fechar }));
    mensagens = el('div', { class: 'cv-chat-mensagens', role: 'log' });
    leitor = el('div', { class: 'cv-chat-sr', 'aria-live': 'polite', 'aria-atomic': 'true' });
    campo = el('textarea', { rows: '1', 'aria-label': 'Sua resposta', placeholder: '' });
    campo.addEventListener('input', ajustarAltura);
    campo.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); aoEnviarTexto(); } });
    enviarBtn = el('button', { class: 'cv-chat-enviar', type: 'submit', 'aria-label': 'Enviar', html: ICONES.enviar });
    form = el('form', { class: 'cv-chat-compor', novalidate: true, onsubmit: aoEnviarTexto }, [campo, enviarBtn]);
    var rodape = el('p', { class: 'cv-chat-rodape', html: 'Seus dados são usados só para responder ao contato. <a href="' + URL_PRIVACIDADE + '">Política de privacidade</a>' });
    painel = el('div', { class: 'cv-chat-painel', id: 'cv-chat-painel', role: 'dialog', 'aria-labelledby': 'cv-chat-titulo', hidden: true }, [topo, mensagens, form, rodape, leitor]);
    raiz.appendChild(painel);
    raiz.appendChild(botao);
    document.body.appendChild(raiz);

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && estado.aberto) fechar(); });
    document.addEventListener('click', function (e) {
      var gatilho = e.target.closest ? e.target.closest('[data-abrir-chat]') : null;
      if (!gatilho) return;
      e.preventDefault();
      abrir({ assunto: gatilho.getAttribute('data-assunto') || '', curso: gatilho.getAttribute('data-curso') || '' });
    });
    function porHash() { if (location.hash === '#chat') abrir(); }
    window.addEventListener('hashchange', porHash);

    render();
    porHash();
  }

  if (document.body) montar(); else document.addEventListener('DOMContentLoaded', montar);
  window.cvChat = { abrir: abrir, fechar: fechar };
})();
