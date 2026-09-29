-- Estrutura do banco da escola conforme os diagnósticos de 28/09/2026 (tipos, padrões, índices, FKs).
create type "FormaPagamento" as enum ('PIX','DEBITO','CREDITO','DINHEIRO');
create type "Papel" as enum ('ALUNO','SECRETARIA','COORDENADOR','FINANCEIRO','DEV','CONSULTA');
create type "PlanoPagamento" as enum ('A_VISTA','PARCELADO','PRESENCIAL');
create type "SituacaoAcademica" as enum ('APROVADO','REPROVADO');
create type "StatusPagamento" as enum ('PENDENTE','PAGO','CANCELADO','ESTORNADO','PARCELADO');
create type "StatusTurma" as enum ('ABERTA','CONFIRMADA','CANCELADA','ENCERRADA');
create type "TipoDocumento" as enum ('CPF','CNPJ','PASSAPORTE');
create type "TipoPagamento" as enum ('TAXA','CURSO');
create table "Usuario" (
  id text not null primary key, nome text not null, email text not null, "cpfCnpj" text, "senhaHash" text,
  papel "Papel" not null default 'ALUNO', escolaridade text, "escolaridadeSituacao" text, genero text, cep text,
  logradouro text, numero text, complemento text, bairro text, cidade text, uf text,
  "emailVerificado" boolean not null default false, "loginFalhas" integer not null default 0,
  "bloqueadoAte" timestamp(3), "loginStrikes" integer not null default 0, "bloqueioTotal" boolean not null default false,
  "avatarUrl" text, "consentimentoLgpdEm" timestamp(3), "consentimentoVersao" text,
  "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP, "atualizadoEm" timestamp(3) not null,
  celular text, rg text, "paisOrigem" text, passaporte text, "tipoDocumento" "TipoDocumento",
  "ultimaAtividade" timestamp(3), "ultimoLogin" timestamp(3));
create unique index "Usuario_cpfCnpj_key" on "Usuario"("cpfCnpj");
create unique index "Usuario_email_key" on "Usuario"(email);
create unique index "Usuario_passaporte_key" on "Usuario"(passaporte);
create index "Usuario_papel_idx" on "Usuario"(papel);
create index "Usuario_ultimaAtividade_idx" on "Usuario"("ultimaAtividade");
create table "Curso" (
  id text not null primary key, nome text not null, descricao text, "cargaHoraria" integer not null, "escolaridadeMinima" text,
  "imagemUrl" text, "precoAvista" numeric(10,2) not null, "precoCheio" numeric(10,2) not null, parcelas integer not null default 1,
  "valorParcela" numeric(10,2) not null, "taxaMatricula" numeric(10,2), ativo boolean not null default true,
  "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP, "descricaoLonga" text);
create table "Turma" (
  id text not null primary key, "cursoId" text not null, vagas integer not null default 30, "minimoAlunos" integer not null default 15,
  status "StatusTurma" not null default 'ABERTA', "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP,
  "inicioPrevisto" timestamp(3) not null default CURRENT_TIMESTAMP);
alter table "Turma" add constraint "Turma_cursoId_fkey" foreign key ("cursoId") references "Curso"(id) on update cascade on delete restrict;
create table "AulaData" (id text not null primary key, "turmaId" text not null, data date not null, horario text not null);
alter table "AulaData" add constraint "AulaData_turmaId_fkey" foreign key ("turmaId") references "Turma"(id) on update cascade on delete cascade;
create index "AulaData_data_idx" on "AulaData"(data);
create index "AulaData_turmaId_idx" on "AulaData"("turmaId");
create table "Matricula" (
  id text not null primary key, "alunoId" text not null, "turmaId" text not null, plano "PlanoPagamento" not null,
  forma "FormaPagamento" not null, "valorCurso" numeric(10,2) not null, "valorTaxaMatricula" numeric(10,2) not null default 0,
  "alimentoEntregue" boolean not null default false, "statusPagamento" "StatusPagamento" not null default 'PENDENTE',
  nota double precision, situacao "SituacaoAcademica", "confirmadaPor" text, "confirmadaEm" timestamp(3),
  "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP, "atualizadoEm" timestamp(3) not null,
  "alimentoEntregueEm" timestamp(3), "alimentoEntreguePor" text, "taxaConfirmada" boolean not null default false,
  "taxaConfirmadaEm" timestamp(3), "taxaConfirmadaPor" text, "diferencaTransferencia" numeric(65,30),
  "prazoPagamentoCurso" timestamp(3), "lembreteImediatoEm" timestamp(3), "lembreteVesperaEm" timestamp(3), "taxaEstornadaEm" timestamp);
create unique index "Matricula_alunoId_turmaId_key" on "Matricula"("alunoId", "turmaId");
alter table "Matricula" add constraint "Matricula_alunoId_fkey" foreign key ("alunoId") references "Usuario"(id) on update cascade on delete restrict;
alter table "Matricula" add constraint "Matricula_turmaId_fkey" foreign key ("turmaId") references "Turma"(id) on update cascade on delete restrict;
create table "Pagamento" (
  id text not null primary key, "matriculaId" text not null, gateway text, "gatewayRef" text, metodo "FormaPagamento" not null,
  valor numeric(10,2) not null, status "StatusPagamento" not null default 'PENDENTE',
  "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP, "atualizadoEm" timestamp(3) not null, "gatewayHash" text,
  "gatewayResponse" jsonb, "gatewayStatus" text, "pixBase64" text, "pixQrCode" text, "pixUrl" text,
  tipo "TipoPagamento" not null default 'CURSO', "estornadoEm" timestamp, "estornadoPor" text, "motivoEstorno" text,
  "valorEstornado" numeric(10,2), "gatewayRefundRef" text);
alter table "Pagamento" add constraint "Pagamento_matriculaId_fkey" foreign key ("matriculaId") references "Matricula"(id) on update cascade on delete restrict;
create index "Pagamento_gatewayHash_idx" on "Pagamento"("gatewayHash");
create index "Pagamento_gatewayRef_idx" on "Pagamento"("gatewayRef");
create index "Pagamento_matriculaId_idx" on "Pagamento"("matriculaId");
create index "Pagamento_status_idx" on "Pagamento"(status);
create table "TokenAuth" (id text not null primary key, "usuarioId" text not null, tipo text not null, "tokenHash" text not null,
  "expiraEm" timestamp(3) not null, "usadoEm" timestamp(3), "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP);
create index "TokenAuth_usuarioId_tipo_idx" on "TokenAuth"("usuarioId", tipo);
create table "LogAuditoria" (id text not null primary key, "atorId" text not null, acao text not null, "alvoTipo" text not null,
  "alvoId" text, detalhe jsonb, "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP);
create index "LogAuditoria_acao_idx" on "LogAuditoria"(acao);
create index "LogAuditoria_atorId_idx" on "LogAuditoria"("atorId");
create index "LogAuditoria_criadoEm_idx" on "LogAuditoria"("criadoEm");
create table "Configuracao" (chave text not null primary key, valor text not null);
-- Tabelas criadas antes do ACL padrão: sem privilégio nenhum para a API, como no banco real.
revoke all on all tables in schema public from anon, authenticated, service_role;
create table "Avaliacao" (id text not null primary key, "matriculaId" text not null, nome text not null, nota double precision not null,
  peso double precision not null default 1, "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP);
alter table "Avaliacao" add constraint "Avaliacao_matriculaId_fkey" foreign key ("matriculaId") references "Matricula"(id) on update cascade on delete cascade;
create table "FaqCurso" (id text not null primary key, "cursoId" text not null, pergunta text not null, resposta text not null,
  ordem integer not null default 0, "criadoEm" timestamp(3) not null default CURRENT_TIMESTAMP);
alter table "FaqCurso" add constraint "FaqCurso_cursoId_fkey" foreign key ("cursoId") references "Curso"(id) on update cascade on delete cascade;
revoke all on "Avaliacao", "FaqCurso" from anon, authenticated, service_role;
