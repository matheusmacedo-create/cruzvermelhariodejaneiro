-- Desfaz a integração com o site no banco da escola: apaga a função e volta o papel service_role
-- (chave secreta) a não enxergar o schema public, como estava antes de 28/09/2026.
-- Não apaga alunos, matrículas nem pagamentos já criados pelo site (ficam como dados da escola).
drop function if exists public.matricula_rapida(jsonb);
revoke usage on schema public from service_role;
notify pgrst, 'reload schema';
