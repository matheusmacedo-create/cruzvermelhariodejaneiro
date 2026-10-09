-- Uma chamada da rotina (psql -v n=1 ou n=2). Segura a transação 1 s depois de chamar, para a outra esbarrar na trava.
\set QUIET 1
begin;
set local role service_role;
select public.matricula_rapida(public.espera_teste(:n))->>'resultado';
select pg_sleep(1);
commit;
