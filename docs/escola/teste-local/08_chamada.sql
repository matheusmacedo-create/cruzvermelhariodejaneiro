set role service_role;
select public.matricula_rapida(jsonb_build_object('nome', 'Pessoa Concorrente', 'cpf', '60000000140', 'email', 'concorrente@exemplo.test',
  'curso_id', 'cccccccc-0000-4000-8000-000000000001', 'transacao', 'CONC000001', 'metodo', 'cartao', 'parcelas', 6,
  'valor_centavos', 9900, 'matricula_centavos', 18000, 'juros_centavos', 2100, 'total_centavos', 30000, 'referencia', '901',
  'senha_hash', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA', 'token_hash', repeat('b', 64)))->>'repetido';
