-- Dados FICTÍCIOS com os cursos, preços e turmas reais de 28/09/2026 (nenhum aluno real).
insert into "Configuracao" values ('matricula_modo','POR_CURSO'), ('matricula_valor_padrao','100.00');
insert into "Curso" (id, nome, "cargaHoraria", "precoAvista", "precoCheio", parcelas, "valorParcela", ativo, "escolaridadeMinima") values
 ('5bd737ee-00a6-48dc-b5ab-08cde9b12897','Bombeiro Civil',80,950,950,5,195,true,'Ensino Médio'),
 ('2bf8d91d-ad41-4232-903f-ca4895b611f3','Cuidador de Idosos (Curso Livre)',160,950,990,5,198,true,'Ensino Fundamental'),
 ('77962b07-9e01-42f3-8b3c-72d78ed47e86','Curso para Testes - DEV',1,2,5,2,2.5,false,null),
 ('b05365c2-c066-4c73-bb4a-f32475a338a2','Micropigmentação Labial',24,400,400,2,227.5,true,'Ensino Médio'),
 ('05f2c1fa-3aef-40c6-a5df-66ea30c32a4b','Primeiros Socorros Básico',8,180,180,2,180,true,'Ensino Fundamental'),
 ('f8373fef-2523-42e4-ab3f-cc1059b10379','Primeiros Socorros Lei Lucas - Ambientes com Crianças',8,150,150,2,77.5,true,'Ensino Fundamental'),
 ('ab2035b1-2c11-476f-a275-d29a4111ecf0','Punção Venosa',8,150,150,2,5,true,'Ensino Médio'),
 ('4f84e97b-1908-4e1e-8ea7-97d4124caff4','Suporte Básico de Vida',4,150,155,2,77.5,true,'Ensino Fundamental');
insert into "Turma" (id, "cursoId", vagas, "minimoAlunos", status, "inicioPrevisto") values
 ('11d6c471-bd64-4c08-a0f1-6d57f4231e9b','5bd737ee-00a6-48dc-b5ab-08cde9b12897',30,15,'ABERTA','2026-10-22 12:00'),
 ('0ec4cf8c-801f-48ce-94ee-793c7a79aef5','2bf8d91d-ad41-4232-903f-ca4895b611f3',30,15,'CANCELADA','2026-10-28 12:00'),
 ('a1c6e5e8-bf91-4eb3-a6a7-309bf122cde3','b05365c2-c066-4c73-bb4a-f32475a338a2',30,15,'ABERTA','2026-10-08 12:00'),
 ('47d694b2-b2bc-4885-a0aa-67637b847957','05f2c1fa-3aef-40c6-a5df-66ea30c32a4b',30,15,'ABERTA','2026-10-21 12:00'),
 ('541b2404-0ecc-4b6c-a22d-0de7d92b8365','05f2c1fa-3aef-40c6-a5df-66ea30c32a4b',30,15,'ENCERRADA','2026-09-22 12:00'),
 ('be58026c-d3a7-4e25-9678-146841182196','f8373fef-2523-42e4-ab3f-cc1059b10379',30,15,'ABERTA','2026-10-01 12:00'),
 ('ca0086ac-8080-48ef-8162-67480a9469e8','ab2035b1-2c11-476f-a275-d29a4111ecf0',30,15,'CANCELADA','2026-10-15 12:00'),
 ('21268388-8a62-4c6b-bd5e-2c4f0b5e4c8d','4f84e97b-1908-4e1e-8ea7-97d4124caff4',30,15,'ENCERRADA','2026-08-28 12:00');
insert into "AulaData" values ('ad1','11d6c471-bd64-4c08-a0f1-6d57f4231e9b','2026-10-22','18:00 - 22:00'),
 ('ad2','47d694b2-b2bc-4885-a0aa-67637b847957','2026-10-21','09:00 - 17:00'),('ad3','be58026c-d3a7-4e25-9678-146841182196','2026-10-01','09:00 - 17:00'),
 ('ad4','a1c6e5e8-bf91-4eb3-a6a7-309bf122cde3','2026-10-08','09:00 - 17:00');
insert into "Usuario" (id, nome, email, "cpfCnpj", "senhaHash", papel, "atualizadoEm", celular, "tipoDocumento") values
 ('0ac36f1d-1731-4f93-a566-ac59853a8268','Pessoa da Secretaria','secretaria@exemplo.test','11111111111','$argon2id$v=19$m=65536,t=3,p=4$x$y','SECRETARIA',now(),null,'CPF'),
 ('e0843c36-6e2d-41f4-a4af-955d98d84fa1','Pessoa Dev','dev@exemplo.test','22222222222','$argon2id$v=19$m=65536,t=3,p=4$x$y','DEV',now(),null,'CPF');
