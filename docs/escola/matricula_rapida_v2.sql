-- matricula_rapida v2 — PROPOSTA, NÃO APLICADA. "Pagar tudo": o site recebeu taxa de inscrição + matrícula.
-- Versão final (08/10/2026), com as revisões financeira e técnica (pagar-tudo/spec-pagar-tudo.md, seção 7):
--   * turma_id (opcional): a turma que o site vendeu. No "pagou tudo", a v2 usa essa turma enquanto ela for do
--     curso, estiver ABERTA/CONFIRMADA e começar depois do dia do pagamento, mesmo lotada (aviso 'turma_lotada').
--     Matrícula CANCELADO/ESTORNADO do aluno nessa turma é reaproveitada. Sem ela, cai na regra antiga e avisa
--     'turma_diferente'. Matrícula já aberta em outra turma também avisa 'turma_diferente'.
--   * "Hoje" do "pagou tudo" é o dia do pagamento (pago_em, Brasília), não o da chamada: a nova tentativa depois
--     da meia-noite não perde a turma.
--   * Matrícula PENDENTE de turma que já começou não recebe o pagamento (turma ABERTA nunca é concluída sozinha).
--   * Ao virar PAGO, as cobranças PENDENTE da matrícula na escola são canceladas; se alguma era online
--     (gatewayRef), aviso 'cobranca_escola_aberta'.
--   * Repetição de um "pagou tudo" sem Pagamento CURSO PAGO pelo hash (o "Encaixar" ou o batimento da escola
--     gravaram a transação antes, ou a v1 a processou): aviso 'matricula_nao_marcada'.
--   * Nova função public.matricula_rapida_versao() = 2, só para o service_role: o site confere antes de vender.
-- Mudança de 08/10/2026 (spec-pagar-tudo.md, seção 10): o site vende taxa + matrícula também nos cursos sem turma.
--   * Campo novo espera_turma (booleano, só com matricula_centavos > 0 e sem turma_id). Com true, a turma é a
--     primeira ABERTA/CONFIRMADA do curso que começa pelo menos 10 dias depois de hoje (dia real da chamada, em
--     Brasília; era 2 antes da revisão, abaixo) e ainda tem vaga (matrículas com a taxa confirmada, não canceladas, < vagas). Nunca acima da vaga:
--     sem turma assim, a pessoa continua esperando. A turma é travada antes de contar as vagas, então duas
--     chamadas ao mesmo tempo não ocupam o mesmo lugar. Matrícula aberta da pessoa no curso (feita na escola
--     durante a espera) recebe o pagamento, como no "pagou tudo" de sempre.
--   * Sem turma: cria só a conta, como antes (LogAuditoria TAXA_PAGA_SEM_TURMA_PELO_SITE, com esperaTurma), e a
--     resposta traz espera_motivo: 'nenhuma_turma', 'turmas_lotadas' ou 'turma_muito_proxima'.
--   * Repetição com espera_turma de um "pagou tudo" que ficou sem turma (vendido sem turma, ou PIX pago depois do
--     fim das inscrições): a função procura a turma de novo. Achou: cria a matrícula PAGA, com o Pagamento CURSO
--     e a anotação, como se fosse a primeira vez (esperouTurma). Não achou: devolve o mesmo 'sem_turma', sem
--     gravar nada. Sem espera_turma, a repetição continua devolvendo o 'sem_turma' de antes, sem procurar.
--   * A resposta ganha, em 'turma', 'primeira_aula' e 'horario' (da primeira AulaData da turma, ou null).
-- Revisões financeira e técnica da venda sem turma (08/10/2026, spec-pagar-tudo.md, 10.16):
--   * so_conta (booleano, só com espera_turma): cria a conta e o registro da espera sem procurar turma e sem tocar em
--     matrícula. O site usa na primeira chamada, logo depois do pagamento: só a rotina, que chama por ordem de
--     pagamento, procura turma. Assim quem paga hoje não passa à frente de quem espera há semanas. Motivo 'na_fila'.
--   * inicio_ate ("AAAA-MM-DD", obrigatório com espera_turma): a data limite da compra. A turma da espera tem a
--     primeira aula entre hoje + 10 dias e essa data (motivos novos: 'turma_muito_distante'; e 'turma_muito_proxima'
--     passa a valer para menos de 10 dias).
--   * Primeira aula = a mais cedo entre o inicioPrevisto e a primeira AulaData. A turma da espera precisa ter aulas
--     cadastradas ('turma_sem_aulas') e ter sido criada há pelo menos 6 horas ('turma_recente'): a secretaria tem
--     tempo de corrigir data e vagas antes de a fila entrar.
--   * A resposta traz 'turma'.'status' e 'matricula_status' também na repetição: o site percebe turma cancelada,
--     data mudada ou matrícula cancelada na escola.
--   * Matrícula paga numa turma que a escola já concluiu (última aula antes de hoje, a regra de
--     lib/concluir-turmas.js) não é "curso já pago": quem refaz o curso entra na turma nova.
--   * Avisos novos no "pagou tudo": 'taxa_paga_antes' (a pessoa pagou só a taxa deste curso antes, pelo site, sem
--     turma: a taxa sai duas vezes) e 'pendente_antiga' (matrícula PENDENTE com a taxa paga numa turma do curso que
--     já começou: a secretaria confere e cancela).
--   * Desempate das turmas pelo id (ordem determinística: sem risco de deadlock entre duas chamadas).
--   * matricula_rapida_versao() = 3: o site só liga a venda sem turma e a rotina com 3.
-- Revisão de dinheiro (09/10/2026; testes em teste-local/14_testes_fila_pgtap.sql), ainda versão 3:
--   * Campo novo, opcional, fila_espera (0 a 9999): na chamada de "só a taxa", quantas pessoas pagaram tudo e esperam
--     turma do curso (o site conta). Com fila, a "só a taxa" só entra em turma criada há 6 horas ou mais, com aulas e
--     com vaga além da fila; senão, fica sem turma (lista de interesse). Sem o campo, a regra de antes.
--   * "Pagou tudo" com a turma vendida que não serve mais (fechou, começou, de outro curso) não cai na próxima turma
--     da regra antiga (que furava a fila): fica sem turma, com matricula_paga_sem_turma, e o site o põe na fila.
--
-- O que muda em relação à v1 (docs/escola/matricula_rapida.sql, em produção desde 28/09/2026):
--   * Sem os campos novos, a função faz exatamente o que a v1 faz (só a taxa: matrícula A_VISTA PENDENTE com a
--     taxa confirmada e um Pagamento TAXA, gateway 'site'). O site atual continua funcionando sem mudança.
--   * Campos novos, opcionais, no {"dados": {...}}:
--       matricula_centavos  valor da matrícula (o curso) que o site cobrou, à vista e sem juros. Ex.: 18000.
--                           > 0 liga o modo "pagou tudo". valor_centavos continua sendo SÓ a taxa (ex.: 9900).
--       parcelas            1 a 12 (padrão 1). PIX só 1.
--       juros_centavos      juros do parcelamento que o aluno pagou (amount_total - amount da Unicopag). Padrão 0.
--       total_centavos      (já existia) total cobrado na Unicopag: taxa + matrícula + opcionais + juros.
--                           Obrigatório no modo "pagou tudo".
--   * No modo "pagou tudo", a escola passa a tratar a matrícula como PAGA, do mesmo jeito que trata o à vista
--     da própria escola (routes/cursos.js grava valorCurso = curso + taxa e UM Pagamento CURSO com o total; o
--     webhook vira statusPagamento PAGO, taxaConfirmada, confirmadaEm/Por — lib/status-pagamento.js:190-233):
--       Matricula: plano A_VISTA, forma PIX|CREDITO, valorCurso = taxa + matrícula (sem juros),
--                  valorTaxaMatricula = taxa, statusPagamento PAGO, taxaConfirmada + Em/Por,
--                  confirmadaEm = pago_em, confirmadaPor = 'site cruzvermelhariodejaneiro.org'.
--       Pagamento: UM, tipo CURSO, status PAGO, valor = taxa + matrícula (base, sem juros, como a escola grava),
--                  gateway 'unicopag-2' (o nome que a escola dá à conta da instituição: lib/unicopag.js:14;
--                  assim o Financeiro por conta põe o dinheiro em "Instituição"), gatewayRef = gatewayHash =
--                  hash da Unicopag, gatewayStatus 'paid', parcelas/juros/total em gatewayResponse.
--       Configuracao 'matricularapida:<referencia>' (a anotação da aba Matrícula rápida da escola,
--                  lib/matricula-rapida.js:24,226): marca a inscrição do site como já encaixada. Sem ela, o
--                  "batimento" da escola (ligarComEscola) tomaria os R$ 279 inteiros como taxa e somaria o curso
--                  de novo (lib/matricula-rapida.js:287-293), e o botão "Encaixar" criaria outra cobrança.
--     Com isso: some a faixa "Falta pagar a matrícula" (server.js:268-271 só olha PENDENTE + PARCELADO), não há
--     botão de pagar em Minha conta (routes/painel.js:60), o lembrete não sai (lib/lembretes.js:97), a tela de
--     pagar o curso recusa (routes/cursos.js:1013), e boas-vindas/pesquisa passam a valer (PAGO + taxa).
--   * Matrícula já aberta na escola no mesmo curso:
--       - já PAGO/PARCELADO  -> não mexe; registra PAGOU_EM_DOBRO_PELO_SITE; aviso 'curso_ja_pago' (estornar no site).
--       - PENDENTE, taxa já confirmada -> vira PAGO; o Pagamento CURSO leva só a matrícula; aviso 'taxa_em_dobro'.
--       - PENDENTE, nada pago -> vira A_VISTA PAGO, como uma matrícula nova.
--   * Turma: a v1 só usa turma com vaga. No modo "pagou tudo" com turma_id, vale a turma vendida (ver acima).
--     Sem turma_id (ou com uma que não serve), se todas as turmas abertas futuras do curso estão
--     lotadas, matricula na próxima mesmo assim (aviso 'turma_lotada'): o aluno já pagou o curso inteiro e a
--     própria escola não barra inscrição por vaga (routes/cursos.js não consulta Turma.vagas). Sem nenhuma turma
--     aberta, cria só a conta (resultado 'sem_turma', aviso 'matricula_paga_sem_turma'). Nada fica para a escola
--     cobrar: não há matrícula, e o site não manda essa inscrição no feed da aba Matrícula rápida enquanto a
--     escola não responder matricula_paga = true (spec, E13), então o "Encaixar" e o batimento não a alcançam.
--     O site chama de novo com espera_turma (acima) até a turma abrir.
--   * Idempotência: a repetição é achada pelo hash em qualquer Pagamento 'site' ou 'unicopag-2' (o batimento
--     da escola troca 'site' por 'unicopag-2' nas taxas da v1: lib/matricula-rapida.js:299-301,323-325) e pelos
--     registros do LogAuditoria (sem turma e pagamento em dobro, que não geram Pagamento).
--   * Resposta ganha 'matricula_paga' (a escola registrou o curso como pago por esta transação) e 'avisos'
--     (lista). 'aviso' continua com o primeiro aviso, como na v1.
--
-- Aplicar (DEPOIS de aprovado e testado) no SQL Editor do projeto da escola. Pode rodar de novo.

-- Trava: só segue no projeto da escola. Sem a tabela "AulaData", o script para aqui e nada é criado.
do $trava$
begin
  if to_regclass('public."AulaData"') is null or to_regclass('public."Configuracao"') is null then
    raise exception using
      message = 'Este não é o projeto da escola: falta a tabela "AulaData" ou "Configuracao". Nada foi criado.',
      hint = 'Abra o projeto wrckokgdtiwvxapqzkki no Supabase e rode o script de novo.';
  end if;
end
$trava$;

create or replace function public.matricula_rapida(dados jsonb)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $fn$
declare
  c_rotulo constant text := 'site cruzvermelhariodejaneiro.org';
  c_origem constant text := 'cruzvermelhariodejaneiro.org/matricula-cursos-presenciais';
  c_login constant text := 'https://escola.cursoscruzvermelha.org/login';
  c_gateway_inst constant text := 'unicopag-2'; -- conta da instituição, como a escola a chama (lib/unicopag.js:14)
  v_agora timestamp(3) := now() at time zone 'UTC';
  v_hoje date := (now() at time zone 'America/Sao_Paulo')::date;
  v_nome text;
  v_cpf text;
  v_email text;
  v_celular text;
  v_curso_id text;
  v_transacao text;
  v_metodo text;
  v_forma public."FormaPagamento";
  v_referencia text;
  v_senha_hash text;
  v_token_hash text;
  v_link_expira timestamp(3);
  v_link_acesso boolean := false;
  v_valor numeric(10,2);
  v_total_centavos integer;
  v_pago_em timestamp(3);
  v_soma integer;
  v_dv integer;
  v_curso record;
  v_aluno_id text;
  v_aluno_email text;
  v_aluno_papel text;
  v_aluno_bloqueado boolean;
  v_aluno_celular text;
  v_aluno_novo boolean := false;
  v_matricula_id text;
  v_matricula_existente boolean := false;
  v_taxa_ja_confirmada boolean;
  v_turma_id text;
  v_turma_inicio timestamp(3);
  v_resultado text;
  v_aviso text;
  v_repetido boolean := false;
  -- v2: "pagou tudo"
  v_taxa_centavos integer;
  v_matricula_centavos integer := 0;
  v_parcelas integer := 1;
  v_juros_centavos integer := 0;
  v_paga_tudo boolean := false;
  v_valor_matricula numeric(10,2) := 0;
  v_valor_pagamento numeric(10,2);
  v_status_existente text;
  v_taxa_existente numeric(10,2);
  v_preco_escola_centavos integer;
  v_avisos text[] := '{}';
  v_matricula_paga boolean := false;
  v_dobro boolean := false;
  v_acao text;
  -- v2 final: turma vendida pelo site, reaproveitamento e cobranças da escola em aberto
  v_turma_pedida text;
  v_pedida_falhou boolean := false;
  v_lotada boolean;
  v_reaproveitar text;
  v_com_ref integer;
  -- 08/10: venda sem turma (espera da turma)
  c_antecedencia_espera constant integer := 10; -- a primeira aula da turma da espera é pelo menos 10 dias depois de hoje
  c_carencia_turma constant interval := interval '6 hours'; -- turma criada há menos que isso ainda não recebe a fila
  v_espera boolean := false;
  v_so_conta boolean := false;
  v_inicio_ate date;
  v_turma_status text;
  v_matricula_status text;
  v_taxa_paga_antes boolean := false;
  v_pendente_antiga text;
  v_reespera boolean := false;
  v_aluno_novo_orig boolean := false;
  v_avisos_orig text[] := '{}';
  v_espera_motivo text;
  v_cand record;
  v_ocupadas integer;
  v_primeira_aula date;
  v_horario text;
  -- 09/10 (revisão de dinheiro): pessoas que pagaram tudo e esperam turma deste curso, contadas pelo site
  v_fila_espera integer := 0;
begin
  if dados is null or jsonb_typeof(dados) <> 'object' then
    raise exception using errcode = '22023', message = 'dados inválidos: esperado um objeto JSON';
  end if;

  -- Entrada, normalizada como a escola grava: CPF e celular só com dígitos, e-mail em minúsculas.
  v_nome := btrim(regexp_replace(coalesce(dados->>'nome', ''), '\s+', ' ', 'g'));
  v_cpf := regexp_replace(coalesce(dados->>'cpf', ''), '\D', '', 'g');
  v_email := lower(btrim(coalesce(dados->>'email', '')));
  v_celular := nullif(regexp_replace(coalesce(dados->>'celular', ''), '\D', '', 'g'), '');
  v_curso_id := lower(btrim(coalesce(dados->>'curso_id', '')));
  v_transacao := btrim(coalesce(dados->>'transacao', ''));
  v_metodo := lower(btrim(coalesce(dados->>'metodo', '')));
  v_referencia := nullif(btrim(coalesce(dados->>'referencia', '')), '');
  v_senha_hash := nullif(btrim(coalesce(dados->>'senha_hash', '')), '');
  v_token_hash := nullif(lower(btrim(coalesce(dados->>'token_hash', ''))), '');

  if char_length(v_nome) < 5 or char_length(v_nome) > 160 or position(' ' in v_nome) = 0 then
    raise exception using errcode = '22023', message = 'dados inválidos: nome';
  end if;
  if v_cpf !~ '^[0-9]{11}$' or v_cpf = repeat(left(v_cpf, 1), 11) then
    raise exception using errcode = '22023', message = 'dados inválidos: cpf';
  end if;
  v_soma := 0;
  for i in 1..9 loop
    v_soma := v_soma + substr(v_cpf, i, 1)::integer * (11 - i);
  end loop;
  v_dv := (v_soma * 10) % 11 % 10;
  if v_dv <> substr(v_cpf, 10, 1)::integer then
    raise exception using errcode = '22023', message = 'dados inválidos: cpf';
  end if;
  v_soma := 0;
  for i in 1..10 loop
    v_soma := v_soma + substr(v_cpf, i, 1)::integer * (12 - i);
  end loop;
  v_dv := (v_soma * 10) % 11 % 10;
  if v_dv <> substr(v_cpf, 11, 1)::integer then
    raise exception using errcode = '22023', message = 'dados inválidos: cpf';
  end if;
  if char_length(v_email) > 190 or v_email !~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$' then
    raise exception using errcode = '22023', message = 'dados inválidos: email';
  end if;
  -- Celular fora do padrão da escola (DDD + número, 10 ou 11 dígitos) não impede a matrícula.
  if v_celular is not null and (v_celular !~ '^[1-9][0-9]{9,10}$') then
    v_celular := null;
  end if;
  if v_curso_id !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: curso_id';
  end if;
  if v_transacao !~ '^[A-Za-z0-9_-]{6,64}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: transacao';
  end if;
  if v_metodo not in ('pix', 'cartao') then
    raise exception using errcode = '22023', message = 'dados inválidos: metodo';
  end if;
  v_forma := (case when v_metodo = 'pix' then 'PIX' else 'CREDITO' end)::public."FormaPagamento";
  -- valor_centavos continua sendo SÓ a taxa de inscrição (limite da v1: R$ 1.000).
  if coalesce(dados->>'valor_centavos', '') !~ '^[0-9]{1,6}$' or (dados->>'valor_centavos')::integer not between 1 and 100000 then
    raise exception using errcode = '22023', message = 'dados inválidos: valor_centavos';
  end if;
  v_taxa_centavos := (dados->>'valor_centavos')::integer;
  v_valor := round(v_taxa_centavos / 100.0, 2);

  -- v2: matrícula paga junto. Ausente, nula ou 0 = só a taxa (v1). Limite: R$ 5.000.
  if dados ? 'matricula_centavos' and dados->>'matricula_centavos' is not null then
    if dados->>'matricula_centavos' !~ '^[0-9]{1,6}$' or (dados->>'matricula_centavos')::integer > 500000 then
      raise exception using errcode = '22023', message = 'dados inválidos: matricula_centavos';
    end if;
    v_matricula_centavos := (dados->>'matricula_centavos')::integer;
  end if;
  v_paga_tudo := v_matricula_centavos > 0;
  v_valor_matricula := round(v_matricula_centavos / 100.0, 2);

  -- v2: parcelas (cartão, até 12x, juros do aluno). PIX é sempre à vista.
  if dados ? 'parcelas' and dados->>'parcelas' is not null then
    if dados->>'parcelas' !~ '^[0-9]{1,2}$' or (dados->>'parcelas')::integer not between 1 and 12 then
      raise exception using errcode = '22023', message = 'dados inválidos: parcelas';
    end if;
    v_parcelas := (dados->>'parcelas')::integer;
  end if;
  if v_metodo = 'pix' and v_parcelas <> 1 then
    raise exception using errcode = '22023', message = 'dados inválidos: parcelas (PIX é à vista)';
  end if;

  -- total_centavos: na v1, opcional e >= taxa. No "pagou tudo", obrigatório, >= taxa + matrícula e até o
  -- dobro disso + R$ 100 (juros de 12x e opcionais cabem com folga; um valor fora disso é erro do site).
  if dados ? 'total_centavos' and dados->>'total_centavos' is not null then
    if dados->>'total_centavos' !~ '^[0-9]{1,7}$' or (dados->>'total_centavos')::integer < v_taxa_centavos + v_matricula_centavos then
      raise exception using errcode = '22023', message = 'dados inválidos: total_centavos';
    end if;
    v_total_centavos := (dados->>'total_centavos')::integer;
  end if;
  if v_paga_tudo and (v_total_centavos is null or v_total_centavos > 2 * (v_taxa_centavos + v_matricula_centavos) + 10000) then
    raise exception using errcode = '22023', message = 'dados inválidos: total_centavos (obrigatório com matricula_centavos)';
  end if;

  -- v2: juros (só informativo; vão para gatewayResponse e LogAuditoria, não para valorCurso).
  if dados ? 'juros_centavos' and dados->>'juros_centavos' is not null then
    if dados->>'juros_centavos' !~ '^[0-9]{1,6}$'
       or (dados->>'juros_centavos')::integer > coalesce(v_total_centavos, 0) - v_taxa_centavos - v_matricula_centavos
       or (v_parcelas = 1 and (dados->>'juros_centavos')::integer > 0) then
      raise exception using errcode = '22023', message = 'dados inválidos: juros_centavos';
    end if;
    v_juros_centavos := (dados->>'juros_centavos')::integer;
  end if;

  begin
    v_pago_em := least(coalesce((dados->>'pago_em')::timestamptz at time zone 'UTC', v_agora), v_agora);
  exception when others then
    raise exception using errcode = '22023', message = 'dados inválidos: pago_em';
  end;
  if v_referencia is not null and char_length(v_referencia) > 40 then
    raise exception using errcode = '22023', message = 'dados inválidos: referencia';
  end if;
  -- v2: a anotação da escola usa o id numérico da inscrição do site (lib/horarios-site.js:107).
  if v_paga_tudo and (v_referencia is null or v_referencia !~ '^[0-9]{1,18}$') then
    raise exception using errcode = '22023', message = 'dados inválidos: referencia (obrigatória com matricula_centavos)';
  end if;
  -- v2 final: a turma que o site vendeu (id da Turma na escola). Só vale no "pagou tudo"; na v1 é ignorada.
  if v_paga_tudo and dados ? 'turma_id' and dados->>'turma_id' is not null then
    v_turma_pedida := btrim(dados->>'turma_id');
    if v_turma_pedida !~ '^[A-Za-z0-9_-]{1,64}$' then
      raise exception using errcode = '22023', message = 'dados inválidos: turma_id';
    end if;
  end if;
  -- 08/10: espera da turma (venda sem turma). Só no "pagou tudo" e sem turma_id: quem espera não comprou data.
  if dados ? 'espera_turma' and jsonb_typeof(dados->'espera_turma') <> 'null' then
    if jsonb_typeof(dados->'espera_turma') <> 'boolean' then
      raise exception using errcode = '22023', message = 'dados inválidos: espera_turma';
    end if;
    v_espera := (dados->>'espera_turma')::boolean;
    if v_espera and not v_paga_tudo then
      raise exception using errcode = '22023', message = 'dados inválidos: espera_turma (só com matricula_centavos)';
    end if;
    if v_espera and v_turma_pedida is not null then
      raise exception using errcode = '22023', message = 'dados inválidos: espera_turma não combina com turma_id';
    end if;
  end if;
  -- Revisão: so_conta (primeira chamada, logo depois do pagamento) e a data limite da compra (inicio_ate).
  if dados ? 'so_conta' and jsonb_typeof(dados->'so_conta') <> 'null' then
    if jsonb_typeof(dados->'so_conta') <> 'boolean' then
      raise exception using errcode = '22023', message = 'dados inválidos: so_conta';
    end if;
    v_so_conta := (dados->>'so_conta')::boolean;
    if v_so_conta and not v_espera then
      raise exception using errcode = '22023', message = 'dados inválidos: so_conta (só com espera_turma)';
    end if;
  end if;
  -- 09/10 (revisão de dinheiro): na chamada de "só a taxa", o site manda quantas pessoas pagaram tudo e esperam turma
  -- deste curso (fila_espera). Com fila, a "só a taxa" só entra numa turma que a fila já pôde ocupar: criada há 6 horas
  -- ou mais, com aulas e com vaga além da fila. Antes, ela tomava a vaga da turma nova antes da rotina da espera, contra
  -- o que o site promete ("quem já pagou tudo entra primeiro"). No "pagou tudo", o campo é ignorado.
  if dados ? 'fila_espera' and dados->>'fila_espera' is not null then
    if dados->>'fila_espera' !~ '^[0-9]{1,4}$' then
      raise exception using errcode = '22023', message = 'dados inválidos: fila_espera';
    end if;
    v_fila_espera := case when v_paga_tudo then 0 else (dados->>'fila_espera')::integer end;
  end if;
  if v_espera then
    if coalesce(dados->>'inicio_ate', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' then
      raise exception using errcode = '22023', message = 'dados inválidos: inicio_ate (obrigatório com espera_turma)';
    end if;
    begin
      v_inicio_ate := (dados->>'inicio_ate')::date;
    exception when others then
      raise exception using errcode = '22023', message = 'dados inválidos: inicio_ate (obrigatório com espera_turma)';
    end;
  end if;
  -- v2 final: no "pagou tudo", "hoje" é o dia do pagamento em Brasília (uma nova tentativa depois da meia-noite
  -- continua achando a turma do dia seguinte ao pagamento). Nunca antes de ontem: uma chamada atrasada não
  -- matricula numa turma que já aconteceu. Na espera, "hoje" é o dia real da chamada: a pessoa pagou há dias
  -- ou semanas, e a turma tem de começar depois de ela receber o e-mail com a data.
  if v_paga_tudo and not v_espera then
    v_hoje := greatest(((v_pago_em at time zone 'UTC') at time zone 'America/Sao_Paulo')::date, v_hoje - 1);
  end if;
  if v_senha_hash is not null and (char_length(v_senha_hash) > 200
      or v_senha_hash !~ '^\$argon2id\$v=19\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+$') then
    raise exception using errcode = '22023', message = 'dados inválidos: senha_hash';
  end if;
  if v_token_hash is not null and v_token_hash !~ '^[0-9a-f]{64}$' then
    raise exception using errcode = '22023', message = 'dados inválidos: token_hash';
  end if;

  -- Uma chamada por vez para a mesma transação, o mesmo CPF e o mesmo e-mail (sempre nesta ordem).
  perform pg_advisory_xact_lock(hashtextextended('matricula_rapida:transacao:' || v_transacao, 0));
  perform pg_advisory_xact_lock(hashtextextended('matricula_rapida:cpf:' || v_cpf, 0));
  perform pg_advisory_xact_lock(hashtextextended('matricula_rapida:email:' || v_email, 0));

  -- Já processada? Devolve o que foi feito da primeira vez. v2: acha pelo hash em 'site' ou 'unicopag-2'
  -- (o batimento da escola troca o gateway das taxas da v1) e também os casos que não geram Pagamento.
  select m.id, m."turmaId", t."inicioPrevisto", u.id, u.email
    into v_matricula_id, v_turma_id, v_turma_inicio, v_aluno_id, v_aluno_email
    from public."Pagamento" p
    join public."Matricula" m on m.id = p."matriculaId"
    join public."Turma" t on t.id = m."turmaId"
    join public."Usuario" u on u.id = m."alunoId"
   where p.gateway in ('site', c_gateway_inst) and p."gatewayHash" = v_transacao
   order by p."criadoEm"
   limit 1;
  if not found then
    -- Pagamento em dobro (curso já pago na escola): só o LogAuditoria, apontando para a matrícula.
    select m.id, m."turmaId", t."inicioPrevisto", u.id, u.email
      into v_matricula_id, v_turma_id, v_turma_inicio, v_aluno_id, v_aluno_email
      from public."LogAuditoria" l
      join public."Matricula" m on m.id = l."alvoId"
      join public."Turma" t on t.id = m."turmaId"
      join public."Usuario" u on u.id = m."alunoId"
     where l.acao = 'PAGOU_EM_DOBRO_PELO_SITE' and l.detalhe->>'transacao' = v_transacao
     limit 1;
  end if;
  if found then
    v_repetido := true;
    v_resultado := 'matriculado';
    select l.acao <> 'MATRICULOU_PELO_SITE', coalesce((l.detalhe->>'alunoNovo')::boolean, false), l.detalhe->>'aviso',
           coalesce(array(select jsonb_array_elements_text(case when jsonb_typeof(l.detalhe->'avisos') = 'array'
                                                                then l.detalhe->'avisos' else '[]'::jsonb end)), '{}')
      into v_matricula_existente, v_aluno_novo, v_aviso, v_avisos
      from public."LogAuditoria" l
     where l.acao in ('MATRICULOU_PELO_SITE', 'CONFIRMOU_TAXA_PELO_SITE', 'CONFIRMOU_PAGAMENTO_PELO_SITE', 'PAGOU_EM_DOBRO_PELO_SITE')
       and l."alvoId" = v_matricula_id and l.detalhe->>'transacao' = v_transacao
     limit 1;
    v_matricula_existente := coalesce(v_matricula_existente, false);
    v_aluno_novo := coalesce(v_aluno_novo, false);
    v_avisos := coalesce(v_avisos, '{}');
    v_matricula_paga := exists (select 1 from public."Pagamento" p
                                 where p.gateway in ('site', c_gateway_inst) and p."gatewayHash" = v_transacao
                                   and p.tipo = 'CURSO' and p.status = 'PAGO');
    -- v2 final: "pagou tudo" repetido sem o curso pago por esta transação (o "Encaixar" ou o batimento da escola
    -- gravaram o hash antes, ou a v1 processou a primeira chamada). A escola pode estar cobrando o curso: o site
    -- trata como urgente. O pagamento em dobro (curso_ja_pago) já tem o próprio aviso.
    if v_paga_tudo and not v_matricula_paga and not ('curso_ja_pago' = any(v_avisos))
       and not ('matricula_nao_marcada' = any(v_avisos)) then
      v_avisos := array_append(v_avisos, 'matricula_nao_marcada');
    end if;
  else
    select l."alvoId", coalesce((l.detalhe->>'alunoNovo')::boolean, false), l.detalhe->>'aviso',
           coalesce(array(select jsonb_array_elements_text(case when jsonb_typeof(l.detalhe->'avisos') = 'array'
                                                                then l.detalhe->'avisos' else '[]'::jsonb end)), '{}'),
           coalesce((l.detalhe->>'pagouMatricula')::boolean, false)
      into v_aluno_id, v_aluno_novo, v_aviso, v_avisos, v_reespera
      from public."LogAuditoria" l
     where l.acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and l.detalhe->>'transacao' = v_transacao
     limit 1;
    if found and v_espera and v_reespera and not v_so_conta then
      -- 08/10: "pagou tudo" que ficou sem turma e chega de novo com espera_turma: procura a turma outra vez
      -- (o caminho de baixo). O que a primeira chamada registrou vale para a resposta.
      v_aluno_novo_orig := coalesce(v_aluno_novo, false);
      v_avisos_orig := coalesce(v_avisos, '{}');
    elsif found then
      v_reespera := false;
      v_repetido := true;
      v_resultado := 'sem_turma';
      v_avisos := coalesce(v_avisos, '{}');
      if v_so_conta then
        v_espera_motivo := 'na_fila'; -- so_conta repetido: a conta já existe; a turma fica para a rotina
      end if;
      select u.email into v_aluno_email from public."Usuario" u where u.id = v_aluno_id;
    else
      v_reespera := false;
    end if;
  end if;

  if not v_repetido then
    v_aluno_novo := false; -- os SELECT INTO acima, sem linha, deixaram nulo
    v_aviso := null;
    v_avisos := '{}';
    select c.id, c.nome, c."precoAvista", c.ativo into v_curso from public."Curso" c where c.id = v_curso_id;
    if not found then
      return jsonb_build_object('ok', false, 'erro', 'curso_inexistente', 'mensagem', 'Curso não encontrado na escola.');
    end if;
    if not v_curso.ativo then
      return jsonb_build_object('ok', false, 'erro', 'curso_inativo', 'mensagem', 'O curso está inativo na escola.');
    end if;
    v_preco_escola_centavos := round(v_curso."precoAvista" * 100)::integer;

    -- Aluno: pelo CPF. Conta existente não é alterada (só ganha o celular, se não tinha).
    select u.id, u.email, u.papel::text, u."bloqueioTotal", u.celular
      into v_aluno_id, v_aluno_email, v_aluno_papel, v_aluno_bloqueado, v_aluno_celular
      from public."Usuario" u where u."cpfCnpj" = v_cpf;
    if found then
      if v_aluno_papel <> 'ALUNO' then
        return jsonb_build_object('ok', false, 'erro', 'documento_da_equipe', 'mensagem', 'O CPF pertence a uma conta da equipe da escola.');
      end if;
      if v_aluno_bloqueado then
        return jsonb_build_object('ok', false, 'erro', 'aluno_bloqueado', 'mensagem', 'A conta do aluno está bloqueada na escola.');
      end if;
      if v_aluno_celular is null and v_celular is not null then
        update public."Usuario" set celular = v_celular, "atualizadoEm" = v_agora where id = v_aluno_id;
      end if;
    else
      if exists (select 1 from public."Usuario" u where u.email = v_email) then
        return jsonb_build_object('ok', false, 'erro', 'email_em_uso', 'mensagem', 'O e-mail já está cadastrado na escola com outro CPF.');
      end if;
      if v_senha_hash is null or v_token_hash is null then
        raise exception using errcode = '22023', message = 'dados inválidos: senha_hash e token_hash (obrigatórios para conta nova)';
      end if;
      v_aluno_id := gen_random_uuid()::text;
      v_aluno_email := v_email;
      insert into public."Usuario" (id, nome, email, "cpfCnpj", "tipoDocumento", "senhaHash", papel, celular, "criadoEm", "atualizadoEm")
      values (v_aluno_id, v_nome, v_email, v_cpf, 'CPF', v_senha_hash, 'ALUNO', v_celular, v_agora, v_agora);
      v_aluno_novo := true;
      -- Link único para o aluno criar a senha: o mesmo mecanismo do "Esqueci minha senha" da escola.
      insert into public."TokenAuth" (id, "usuarioId", tipo, "tokenHash", "expiraEm", "criadoEm")
      values (gen_random_uuid()::text, v_aluno_id, 'RESET_SENHA', v_token_hash, v_agora + interval '72 hours', v_agora);
      insert into public."LogAuditoria" (id, "atorId", acao, "alvoTipo", "alvoId", detalhe)
      values (gen_random_uuid()::text, 'SISTEMA', 'CADASTROU_ALUNO_PELO_SITE', 'Usuario', v_aluno_id,
              jsonb_build_object('origem', c_origem, 'transacao', v_transacao, 'referencia', v_referencia));
    end if;
    -- 08/10: na nova procura da espera, a conta foi criada na primeira chamada; a resposta conta como aquela.
    if v_reespera then
      v_aluno_novo := v_aluno_novo or v_aluno_novo_orig;
    end if;

    -- Revisão: no "pagou tudo", a taxa deste curso paga antes pelo site, sem turma (só a taxa, que nunca virou
    -- matrícula), faz a taxa sair duas vezes quando esta compra for matriculada. O site devolve a taxa paga a mais.
    if v_paga_tudo then
      v_taxa_paga_antes := exists (
        select 1 from public."LogAuditoria" l
         where l.acao = 'TAXA_PAGA_SEM_TURMA_PELO_SITE' and l."alvoId" = v_aluno_id
           and l.detalhe->>'cursoId' = v_curso.id and l.detalhe->>'transacao' <> v_transacao
           and not coalesce((l.detalhe->>'pagouMatricula')::boolean, false));
    end if;

    if v_so_conta then
      -- Revisão: primeira chamada de uma compra em espera. Só a conta e o registro da espera: a turma (e a matrícula
      -- que a pessoa tiver aberto na escola) fica para a rotina do site, que chama por ordem de pagamento.
      v_espera_motivo := 'na_fila';
    else
      -- Matrícula aberta no mesmo curso (feita na própria escola, por exemplo): o pagamento vai para ela.
      select m.id, m."taxaConfirmada", m."turmaId", t."inicioPrevisto", m."statusPagamento"::text, m."valorTaxaMatricula"
        into v_matricula_id, v_taxa_ja_confirmada, v_turma_id, v_turma_inicio, v_status_existente, v_taxa_existente
        from public."Matricula" m
        join public."Turma" t on t.id = m."turmaId"
       where m."alunoId" = v_aluno_id and t."cursoId" = v_curso.id
         and t.status in ('ABERTA', 'CONFIRMADA')
         and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')
         -- v2 final, "pagou tudo": matrícula PENDENTE de turma que já começou não recebe o pagamento (a escola só
         -- conclui sozinha turma CONFIRMADA; uma ABERTA abandonada ficaria para sempre). Já paga continua valendo,
         -- para achar o pagamento em dobro.
         and (not v_paga_tudo or t."inicioPrevisto"::date > v_hoje or m."statusPagamento" in ('PAGO', 'PARCELADO'))
         -- Revisão, "pagou tudo": turma que a escola já concluiu (última aula antes de hoje; sem aulas, o início),
         -- a regra de lib/concluir-turmas.js, não conta. Quem refaz o curso entra na turma nova, sem "curso já pago".
         and (not v_paga_tudo
              or coalesce((select max(a.data) from public."AulaData" a where a."turmaId" = t.id), t."inicioPrevisto"::date) >= v_hoje)
       order by case when v_paga_tudo and m."statusPagamento" in ('PAGO', 'PARCELADO') then 0 else 1 end,
                case when v_paga_tudo and m."turmaId" = v_turma_pedida then 0 else 1 end,
                case when v_paga_tudo then t."inicioPrevisto" end,
                t."inicioPrevisto" desc
       limit 1
       for update of m;
      v_matricula_existente := found;
      -- Revisão, "pagou tudo": matrícula PENDENTE com a taxa paga numa turma do curso que já começou. Ela não
      -- recebe este pagamento (acima) e ficaria na faixa "Falta pagar o curso" da escola, com a taxa paga duas vezes.
      if v_paga_tudo then
        select m.id into v_pendente_antiga
          from public."Matricula" m
          join public."Turma" t on t.id = m."turmaId"
         where m."alunoId" = v_aluno_id and t."cursoId" = v_curso.id
           and t.status in ('ABERTA', 'CONFIRMADA') and t."inicioPrevisto"::date <= v_hoje
           and m."statusPagamento" = 'PENDENTE' and m."taxaConfirmada"
           and m.id is distinct from v_matricula_id
         order by t."inicioPrevisto" desc, m.id
         limit 1;
      end if;
    end if;
    if v_matricula_existente then
      if not v_paga_tudo then
        -- v1, sem mudança: só a taxa.
        if v_taxa_ja_confirmada then
          v_aviso := 'taxa_ja_confirmada';
        else
          update public."Matricula"
             set "taxaConfirmada" = true, "taxaConfirmadaEm" = v_pago_em, "taxaConfirmadaPor" = c_rotulo,
                 "valorCurso" = "valorCurso" - "valorTaxaMatricula" + v_valor, "valorTaxaMatricula" = v_valor,
                 "atualizadoEm" = v_agora
           where id = v_matricula_id;
        end if;
      elsif v_status_existente in ('PAGO', 'PARCELADO') then
        -- Curso já pago na escola: pagamento em dobro. Nada muda na matrícula; o site estorna.
        v_dobro := true;
        v_avisos := array_append(v_avisos, 'curso_ja_pago');
      elsif v_taxa_ja_confirmada then
        -- Taxa já paga antes (na escola ou numa inscrição anterior do site): o curso fica pago; a taxa
        -- desta transação sobra (aviso para o site estornar essa parte).
        v_avisos := array_append(v_avisos, 'taxa_em_dobro');
        v_valor_pagamento := v_valor_matricula;
        update public."Matricula"
           set "statusPagamento" = 'PAGO', "confirmadaEm" = v_pago_em, "confirmadaPor" = c_rotulo,
               "valorCurso" = coalesce(v_taxa_existente, 0) + v_valor_matricula, "prazoPagamentoCurso" = null,
               "atualizadoEm" = v_agora
         where id = v_matricula_id;
      else
        -- Matrícula da escola que nunca foi paga: fica como a nova, à vista e paga.
        v_valor_pagamento := v_valor + v_valor_matricula;
        update public."Matricula"
           set plano = 'A_VISTA', forma = v_forma, "valorCurso" = v_valor + v_valor_matricula, "valorTaxaMatricula" = v_valor,
               "taxaConfirmada" = true, "taxaConfirmadaEm" = v_pago_em, "taxaConfirmadaPor" = c_rotulo,
               "statusPagamento" = 'PAGO', "confirmadaEm" = v_pago_em, "confirmadaPor" = c_rotulo,
               "prazoPagamentoCurso" = null, "atualizadoEm" = v_agora
         where id = v_matricula_id;
      end if;
      if v_paga_tudo and not v_dobro then
        -- v2 final: a matrícula ficou paga. Cobranças da escola ainda em aberto para ela (um PIX do curso gerado
        -- lá, parcelas) são canceladas, para não serem pagas de novo; se alguma era online, o site avisa.
        select count(*) filter (where p."gatewayRef" is not null) into v_com_ref
          from public."Pagamento" p where p."matriculaId" = v_matricula_id and p.status = 'PENDENTE';
        update public."Pagamento" set status = 'CANCELADO', "gatewayStatus" = 'cancelado:pago-pelo-site', "atualizadoEm" = v_agora
         where "matriculaId" = v_matricula_id and status = 'PENDENTE';
        if v_com_ref > 0 then
          v_avisos := array_append(v_avisos, 'cobranca_escola_aberta');
        end if;
        -- Matrícula já aberta numa turma diferente da que o site vendeu: o aluno precisa saber.
        if v_turma_pedida is not null and v_turma_id is distinct from v_turma_pedida then
          v_avisos := array_append(v_avisos, 'turma_diferente');
        end if;
      end if;
    else
      if v_paga_tudo and v_espera and not v_so_conta then
        -- 08/10, espera da turma: a primeira turma do curso com vaga, nunca acima dela (quem espera não comprou
        -- data; sem vaga, continua esperando e a secretaria vê a fila no painel do site). Revisão: a primeira aula
        -- (a mais cedo entre o inicioPrevisto e as AulaData) fica entre hoje + 10 dias (a janela de 7 dias da
        -- "data não serve" cabe inteira antes da véspera) e a data limite da compra (inicio_ate); a turma tem aulas
        -- cadastradas (o e-mail leva data e horário) e foi criada há 6 horas ou mais (a secretaria confere data e
        -- vagas antes de a fila entrar). Cada candidata é travada antes de contar as vagas, numa consulta nova:
        -- duas chamadas ao mesmo tempo não ocupam o mesmo lugar. A matrícula CANCELADO/ESTORNADO da pessoa na
        -- turma é reaproveitada (a escola aceita uma por aluno e turma).
        for v_cand in
          select t.id, t."inicioPrevisto", t.vagas
            from public."Turma" t
            cross join lateral (select least(t."inicioPrevisto"::date, min(a.data)) as primeira, count(a.id) as aulas
                                  from public."AulaData" a where a."turmaId" = t.id) x
           where t."cursoId" = v_curso.id
             and t.status in ('ABERTA', 'CONFIRMADA')
             and x.aulas > 0
             and t."criadoEm" <= v_agora - c_carencia_turma
             and x.primeira between v_hoje + c_antecedencia_espera and v_inicio_ate
             and not exists (select 1 from public."Matricula" m
                              where m."turmaId" = t.id and m."alunoId" = v_aluno_id
                                and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO'))
           order by x.primeira, t."inicioPrevisto", t."criadoEm", t.id
        loop
          perform 1 from public."Turma" t where t.id = v_cand.id for update;
          select count(*) into v_ocupadas
            from public."Matricula" m
           where m."turmaId" = v_cand.id and m."taxaConfirmada" and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO');
          if v_ocupadas < v_cand.vagas then
            v_turma_id := v_cand.id;
            v_turma_inicio := v_cand."inicioPrevisto";
            select m.id into v_reaproveitar from public."Matricula" m
             where m."turmaId" = v_turma_id and m."alunoId" = v_aluno_id
             for update;
            exit;
          end if;
        end loop;
        if v_turma_id is null then
          -- Por que não entrou, para o painel do site, do que pede ação para o que não pede: há turma servível, mas
          -- lotada; há turma na janela sem aulas cadastradas; há turma na janela criada há menos de 6 horas (entra
          -- sozinha depois); só há turma que começa depois da data limite; só há turma que começa em menos de 10
          -- dias; ou nenhuma turma futura.
          select case
                   when bool_or(x.aulas > 0 and t."criadoEm" <= v_agora - c_carencia_turma
                                and x.primeira between v_hoje + c_antecedencia_espera and v_inicio_ate) then 'turmas_lotadas'
                   when bool_or(x.aulas = 0 and x.primeira between v_hoje + c_antecedencia_espera and v_inicio_ate) then 'turma_sem_aulas'
                   when bool_or(x.primeira between v_hoje + c_antecedencia_espera and v_inicio_ate) then 'turma_recente'
                   when bool_or(x.primeira > v_inicio_ate) then 'turma_muito_distante'
                   when bool_or(x.primeira > v_hoje) then 'turma_muito_proxima'
                   else 'nenhuma_turma' end
            into v_espera_motivo
            from public."Turma" t
            cross join lateral (select least(t."inicioPrevisto"::date, min(a.data)) as primeira, count(a.id) as aulas
                                  from public."AulaData" a where a."turmaId" = t.id) x
           where t."cursoId" = v_curso.id and t.status in ('ABERTA', 'CONFIRMADA');
        end if;
      elsif v_paga_tudo and v_turma_pedida is not null then
        -- v2 final: a turma que o site vendeu e mostrou ao aluno, mesmo lotada (o aluno já pagou o curso inteiro;
        -- lotada = confirmadas com a taxa paga >= vagas, a conta do filtro de vagas da escola, FILTRO_VAGA em
        -- routes/admin.js:622; a aba Matrícula rápida conta também as canceladas com a taxa paga).
        select t.id, t."inicioPrevisto",
               (select count(*) from public."Matricula" m
                 where m."turmaId" = t.id and m."taxaConfirmada" and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')) >= t.vagas
          into v_turma_id, v_turma_inicio, v_lotada
          from public."Turma" t
         where t.id = v_turma_pedida and t."cursoId" = v_curso.id
           and t.status in ('ABERTA', 'CONFIRMADA') and t."inicioPrevisto"::date > v_hoje
         for update of t;
        if found then
          if v_lotada then
            v_avisos := array_append(v_avisos, 'turma_lotada');
          end if;
          -- A escola aceita uma matrícula por aluno e turma: a CANCELADO/ESTORNADO dele nessa turma é reaproveitada.
          select m.id into v_reaproveitar from public."Matricula" m
           where m."turmaId" = v_turma_id and m."alunoId" = v_aluno_id
           for update;
        else
          v_pedida_falhou := true; -- de outro curso, fechada, já começou ou não existe: regra antiga + aviso
        end if;
      end if;
    end if;
    -- 09/10 (revisão de dinheiro): o "pagou tudo" cuja turma vendida não serve mais (fechou, começou, de outro curso)
    -- não cai na regra antiga, que o punha na próxima turma sem carência, sem antecedência e na frente da fila: fica
    -- sem turma (matricula_paga_sem_turma) e o site o põe na fila da próxima turma, por ordem de pagamento (10.8).
    if v_turma_id is null and not v_matricula_existente and not v_espera and not (v_paga_tudo and v_pedida_falhou) then
      -- Próxima turma aberta com vaga, começando a partir de amanhã (horário de Brasília). Com fila de quem pagou tudo
      -- (fila_espera, só na "só a taxa"): turma criada há 6 h ou mais, com aulas, e com vaga além da fila.
      select t.id, t."inicioPrevisto" into v_turma_id, v_turma_inicio
        from public."Turma" t
       where t."cursoId" = v_curso.id
         and t.status in ('ABERTA', 'CONFIRMADA')
         and t."inicioPrevisto"::date > v_hoje
         and (select count(*) from public."Matricula" m
               where m."turmaId" = t.id and m."statusPagamento" not in ('CANCELADO', 'ESTORNADO')) + v_fila_espera < t.vagas
         and (v_fila_espera = 0 or (t."criadoEm" <= v_agora - c_carencia_turma
                                    and exists (select 1 from public."AulaData" a where a."turmaId" = t.id)))
         and not exists (select 1 from public."Matricula" m where m."turmaId" = t.id and m."alunoId" = v_aluno_id)
       order by t."inicioPrevisto", t."criadoEm", t.id
       limit 1
       for update of t;
      if not found and v_paga_tudo then
        -- v2: pagou o curso inteiro e todas as turmas futuras estão lotadas: entra na próxima mesmo assim.
        select t.id, t."inicioPrevisto" into v_turma_id, v_turma_inicio
          from public."Turma" t
         where t."cursoId" = v_curso.id
           and t.status in ('ABERTA', 'CONFIRMADA')
           and t."inicioPrevisto"::date > v_hoje
           and not exists (select 1 from public."Matricula" m where m."turmaId" = t.id and m."alunoId" = v_aluno_id)
         order by t."inicioPrevisto", t."criadoEm", t.id
         limit 1
         for update of t;
        if found then
          v_avisos := array_append(v_avisos, 'turma_lotada');
        end if;
      end if;
      if v_pedida_falhou and v_turma_id is not null then
        v_avisos := array_append(v_avisos, 'turma_diferente');
      end if;
    end if;
    if not v_matricula_existente then
      if v_turma_id is null and v_reespera then
        -- 08/10: continua sem turma. Nada é gravado; a resposta é a mesma da primeira chamada, com o motivo.
        v_repetido := true;
        v_avisos := v_avisos_orig;
        v_resultado := 'sem_turma';
      elsif v_turma_id is null then
        if v_paga_tudo then
          v_avisos := array_append(v_avisos, 'matricula_paga_sem_turma');
          if v_matricula_centavos <> v_preco_escola_centavos then
            v_avisos := array_append(v_avisos, 'preco_divergente');
          end if;
        end if;
        insert into public."LogAuditoria" (id, "atorId", acao, "alvoTipo", "alvoId", detalhe)
        values (gen_random_uuid()::text, 'SISTEMA', 'TAXA_PAGA_SEM_TURMA_PELO_SITE', 'Usuario', v_aluno_id,
                jsonb_build_object('origem', c_origem, 'transacao', v_transacao, 'referencia', v_referencia,
                                   'cursoId', v_curso.id, 'curso', v_curso.nome, 'valorTaxa', v_valor,
                                   'metodo', v_metodo, 'alunoNovo', v_aluno_novo,
                                   'pagouMatricula', v_paga_tudo, 'valorMatricula', v_valor_matricula,
                                   'parcelas', v_parcelas, 'jurosCentavos', v_juros_centavos,
                                   'totalCobradoCentavos', v_total_centavos,
                                   'aviso', v_avisos[1], 'avisos', to_jsonb(v_avisos))
                || case when v_espera then jsonb_build_object('esperaTurma', true, 'esperaMotivo', v_espera_motivo)
                        else '{}'::jsonb end);
        v_resultado := 'sem_turma';
      else
        v_matricula_id := gen_random_uuid()::text;
        if v_paga_tudo and v_reaproveitar is not null then
          -- v2 final: a matrícula CANCELADO/ESTORNADO do aluno na turma vendida volta, à vista e paga.
          v_matricula_id := v_reaproveitar;
          v_matricula_existente := true;
          v_valor_pagamento := v_valor + v_valor_matricula;
          update public."Matricula"
             set plano = 'A_VISTA', forma = v_forma, "valorCurso" = v_valor + v_valor_matricula, "valorTaxaMatricula" = v_valor,
                 "statusPagamento" = 'PAGO', "taxaConfirmada" = true, "taxaConfirmadaEm" = v_pago_em, "taxaConfirmadaPor" = c_rotulo,
                 "confirmadaEm" = v_pago_em, "confirmadaPor" = c_rotulo, "prazoPagamentoCurso" = null, "atualizadoEm" = v_agora
           where id = v_matricula_id;
          update public."Pagamento" set status = 'CANCELADO', "gatewayStatus" = 'cancelado:pago-pelo-site', "atualizadoEm" = v_agora
           where "matriculaId" = v_matricula_id and status = 'PENDENTE';
        elsif v_paga_tudo then
          v_valor_pagamento := v_valor + v_valor_matricula;
          insert into public."Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula",
                                          "statusPagamento", "taxaConfirmada", "taxaConfirmadaEm", "taxaConfirmadaPor",
                                          "confirmadaEm", "confirmadaPor", "criadoEm", "atualizadoEm")
          values (v_matricula_id, v_aluno_id, v_turma_id, 'A_VISTA', v_forma, v_valor + v_valor_matricula, v_valor,
                  'PAGO', true, v_pago_em, c_rotulo, v_pago_em, c_rotulo, v_agora, v_agora);
        else
          insert into public."Matricula" (id, "alunoId", "turmaId", plano, forma, "valorCurso", "valorTaxaMatricula",
                                          "statusPagamento", "taxaConfirmada", "taxaConfirmadaEm", "taxaConfirmadaPor",
                                          "criadoEm", "atualizadoEm")
          values (v_matricula_id, v_aluno_id, v_turma_id, 'A_VISTA', v_forma,
                  v_curso."precoAvista" + v_valor, v_valor, 'PENDENTE', true, v_pago_em, c_rotulo, v_agora, v_agora);
        end if;
      end if;
    end if;

    if v_resultado is null then
      v_resultado := 'matriculado';
      -- Revisão: a taxa sai duas vezes (só a taxa pago antes pelo site, sem turma) ou há uma matrícula pendente
      -- antiga com a taxa paga. O site devolve a taxa paga a mais; a secretaria confere a matrícula antiga.
      if v_paga_tudo and not v_dobro then
        if v_taxa_paga_antes and not ('taxa_em_dobro' = any(v_avisos)) then
          v_avisos := array_append(v_avisos, 'taxa_paga_antes');
        end if;
        if v_pendente_antiga is not null then
          v_avisos := array_append(v_avisos, 'pendente_antiga');
        end if;
      end if;
      -- O site cobrou um preço diferente do da escola: vale o que o aluno pagou; a secretaria confere.
      -- (Por último na lista: o aviso principal é o que pede ação, como estornar.)
      if v_paga_tudo and v_matricula_centavos <> v_preco_escola_centavos then
        v_avisos := array_append(v_avisos, 'preco_divergente');
      end if;
      if v_aviso is not null then
        v_avisos := array_append(v_avisos, v_aviso); -- 'taxa_ja_confirmada' da v1 também vai na lista
      end if;
      v_aviso := coalesce(v_aviso, v_avisos[1]);
      if not v_paga_tudo then
        -- v1, sem mudança: Pagamento TAXA, gateway 'site'.
        insert into public."Pagamento" (id, "matriculaId", gateway, "gatewayRef", metodo, valor, status, "gatewayHash",
                                        "gatewayStatus", "gatewayResponse", tipo, "criadoEm", "atualizadoEm")
        values (gen_random_uuid()::text, v_matricula_id, 'site', 'site-' || coalesce(v_referencia, v_transacao),
                v_forma, v_valor, 'PAGO', v_transacao, 'paid',
                jsonb_build_object('origem', c_origem, 'processador', 'unicopag', 'hash', v_transacao, 'metodo', v_metodo,
                                   'valor_inscricao_centavos', v_taxa_centavos, 'total_cobrado_centavos', v_total_centavos,
                                   'pago_em', to_char(v_pago_em, 'YYYY-MM-DD"T"HH24:MI:SS"Z"'), 'referencia', v_referencia),
                'TAXA', v_pago_em, v_agora);
        v_acao := case when v_matricula_existente then 'CONFIRMOU_TAXA_PELO_SITE' else 'MATRICULOU_PELO_SITE' end;
      elsif v_dobro then
        v_acao := 'PAGOU_EM_DOBRO_PELO_SITE';
      else
        -- v2: UM Pagamento CURSO com o total base (como o à vista da escola), na conta da instituição.
        insert into public."Pagamento" (id, "matriculaId", gateway, "gatewayRef", metodo, valor, status, "gatewayHash",
                                        "gatewayStatus", "gatewayResponse", tipo, "criadoEm", "atualizadoEm")
        values (gen_random_uuid()::text, v_matricula_id, c_gateway_inst, v_transacao, v_forma, v_valor_pagamento, 'PAGO',
                v_transacao, 'paid',
                jsonb_build_object('origem', c_origem, 'processador', 'unicopag', 'conta', 'instituicao', 'hash', v_transacao,
                                   'metodo', v_metodo, 'parcelas', v_parcelas,
                                   'valor_inscricao_centavos', v_taxa_centavos, 'valor_matricula_centavos', v_matricula_centavos,
                                   'juros_centavos', v_juros_centavos, 'total_cobrado_centavos', v_total_centavos,
                                   'preco_escola_centavos', v_preco_escola_centavos, 'turma_pedida', v_turma_pedida,
                                   'taxa_em_dobro_centavos', case when 'taxa_em_dobro' = any(v_avisos) then v_taxa_centavos else 0 end,
                                   'espera_turma', v_espera, 'esperou_turma', v_reespera,
                                   'taxa_paga_antes', 'taxa_paga_antes' = any(v_avisos), 'pendente_antiga', v_pendente_antiga,
                                   'pago_em', to_char(v_pago_em, 'YYYY-MM-DD"T"HH24:MI:SS"Z"'), 'referencia', v_referencia),
                'CURSO', v_pago_em, v_agora);
        v_matricula_paga := true;
        v_acao := case when v_matricula_existente then 'CONFIRMOU_PAGAMENTO_PELO_SITE' else 'MATRICULOU_PELO_SITE' end;
      end if;
      insert into public."LogAuditoria" (id, "atorId", acao, "alvoTipo", "alvoId", detalhe)
      values (gen_random_uuid()::text, 'SISTEMA', v_acao, 'Matricula', v_matricula_id,
              jsonb_build_object('origem', c_origem, 'transacao', v_transacao, 'referencia', v_referencia,
                                 'turmaId', v_turma_id, 'cursoId', v_curso.id, 'valorTaxa', v_valor, 'metodo', v_metodo,
                                 'alunoNovo', v_aluno_novo, 'aviso', v_aviso)
              || case when v_paga_tudo then
                   jsonb_build_object('pagouMatricula', true, 'valorMatricula', v_valor_matricula, 'parcelas', v_parcelas,
                                      'jurosCentavos', v_juros_centavos, 'totalCobradoCentavos', v_total_centavos,
                                      'matriculaPaga', v_matricula_paga, 'avisos', to_jsonb(v_avisos),
                                      'turmaPedida', v_turma_pedida, 'reaproveitada', v_reaproveitar is not null,
                                      'esperaTurma', v_espera, 'esperouTurma', v_reespera,
                                      'pendenteAntiga', v_pendente_antiga)
                 else '{}'::jsonb end);
      -- v2: anotação da aba Matrícula rápida da escola. Faz o batimento (ligarComEscola) e o "Encaixar"
      -- deixarem esta inscrição em paz; corrigirTotais pula por totalComTaxa. Se já existe, não mexe.
      if v_paga_tudo then
        insert into public."Configuracao" (chave, valor)
        values ('matricularapida:' || v_referencia,
                (jsonb_build_object('matriculaId', v_matricula_id, 'turmaId', v_turma_id, 'alunoId', v_aluno_id,
                                   'por', 'SISTEMA', 'em', to_char(v_agora, 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"'),
                                   'contaCriada', v_aluno_novo, 'totalComTaxa', true, 'origem', 'matricula_rapida',
                                   'transacao', v_transacao, 'pagoTudo', true)
                || case when v_reespera then jsonb_build_object('esperouTurma', true) else '{}'::jsonb end
                || case when v_dobro then jsonb_build_object('dobro', true) else '{}'::jsonb end)::text)
        on conflict (chave) do nothing;
      end if;
    end if;
  end if;

  -- O link de criar senha ainda vale? (conta criada por esta transação, link não usado nem vencido)
  if v_token_hash is not null and v_aluno_id is not null then
    select k."expiraEm" into v_link_expira
      from public."TokenAuth" k
     where k."usuarioId" = v_aluno_id and k.tipo = 'RESET_SENHA' and k."tokenHash" = v_token_hash
       and k."usadoEm" is null and k."expiraEm" > v_agora
     limit 1;
    v_link_acesso := found;
  end if;

  -- Data e horário da primeira aula da turma (a escola grava em AulaData), para o e-mail do site.
  -- Revisão: o status atual da turma e da matrícula, também na repetição (turma cancelada, data mudada ou matrícula
  -- cancelada na escola depois da espera: o site pergunta de novo e percebe).
  if v_turma_id is not null and v_resultado is distinct from 'sem_turma' then
    select a.data, a.horario into v_primeira_aula, v_horario
      from public."AulaData" a where a."turmaId" = v_turma_id
     order by a.data, a.horario limit 1;
    select t.status::text, t."inicioPrevisto" into v_turma_status, v_turma_inicio from public."Turma" t where t.id = v_turma_id;
    select m."statusPagamento"::text into v_matricula_status from public."Matricula" m where m.id = v_matricula_id;
  end if;

  return jsonb_build_object(
    'ok', true,
    'resultado', v_resultado,
    'repetido', v_repetido,
    'aluno_novo', v_aluno_novo,
    'email_conta', left(split_part(v_aluno_email, '@', 1), 1) || '***@' || split_part(v_aluno_email, '@', 2),
    'email_confere', v_aluno_email = v_email,
    'matricula_id', case when v_resultado = 'sem_turma' then null else v_matricula_id end,
    'matricula_existente', v_matricula_existente,
    'matricula_paga', v_matricula_paga,
    'matricula_status', case when v_resultado = 'sem_turma' then null else v_matricula_status end,
    'turma', case when v_turma_id is null or v_resultado = 'sem_turma' then null
                  else jsonb_build_object('id', v_turma_id, 'inicio', to_char(v_turma_inicio, 'YYYY-MM-DD'),
                                          'primeira_aula', to_char(v_primeira_aula, 'YYYY-MM-DD'), 'horario', v_horario,
                                          'status', v_turma_status) end,
    'aviso', coalesce(v_aviso, v_avisos[1]),
    'avisos', to_jsonb(coalesce(v_avisos, '{}')),
    'espera_motivo', case when v_resultado = 'sem_turma' then v_espera_motivo end,
    'link_acesso', v_link_acesso,
    'link_expira_em', case when v_link_acesso then to_char(v_link_expira, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') end,
    'url_login', c_login);
end;
$fn$;

comment on function public.matricula_rapida(jsonb) is
  'Matrícula paga no site cruzvermelhariodejaneiro.org: cria ou acha o aluno, matricula na próxima turma aberta com a taxa confirmada (v1) ou com a matrícula já paga (v2, matricula_centavos; com espera_turma, procura a turma de novo a cada chamada até ela abrir, entre hoje + 10 dias e inicio_ate; com so_conta, só cria a conta) e registra o pagamento. Só o service_role (servidor do site) executa.';

-- Só o servidor do site (chave secreta = service_role) executa. Nenhuma tabela é liberada.
revoke all on function public.matricula_rapida(jsonb) from public;
revoke all on function public.matricula_rapida(jsonb) from anon, authenticated;
grant execute on function public.matricula_rapida(jsonb) to service_role;
grant usage on schema public to service_role;

-- v2 final: versão da função, para o site conferir antes de vender a matrícula junto (falha fechada: sem esta
-- função, ou com resposta menor que 2, o site não oferece "Taxa de inscrição + matrícula"; menor que 3, não
-- vende sem turma nem roda a rotina da espera).
create or replace function public.matricula_rapida_versao()
returns integer
language sql
immutable
set search_path = ''
as $$ select 3 $$;
comment on function public.matricula_rapida_versao() is
  'Versão da matricula_rapida (2 = aceita matricula_centavos e turma_id; 3 = também espera_turma, so_conta e inicio_ate). Só o service_role (servidor do site) executa.';
revoke all on function public.matricula_rapida_versao() from public;
revoke all on function public.matricula_rapida_versao() from anon, authenticated;
grant execute on function public.matricula_rapida_versao() to service_role;

-- A API (PostgREST) relê o schema para enxergar a função nova.
notify pgrst, 'reload schema';

-- Conferência: duas linhas,
--   matricula_rapida        | dados jsonb | true  | true | false
--   matricula_rapida_versao |             | false | true | false
select p.proname as funcao,
       pg_get_function_identity_arguments(p.oid) as argumentos,
       p.prosecdef as security_definer,
       has_function_privilege('service_role', p.oid, 'execute') as service_role_executa,
       has_function_privilege('anon', p.oid, 'execute') as anon_executa
  from pg_proc p
  join pg_namespace n on n.oid = p.pronamespace
 where n.nspname = 'public' and p.proname in ('matricula_rapida', 'matricula_rapida_versao')
 order by p.proname;
