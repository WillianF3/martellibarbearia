-- MARTELLI V49 | SOMENTE LEITURA. Selecione antes o banco real da barbearia.
-- Os parâmetros abaixo são exemplos extraídos dos dados informados pelo administrador.
-- CONFIRA se os registros são da mesma pessoa; CPF sozinho não comprova titularidade.
SELECT DATABASE() AS banco_selecionado;
SET @cpf_consulta = '11955728917';
SET @telefone_consulta = '49998347453';
SELECT id, name, phone, cpf, created_at
FROM mt_clients
WHERE cpf = @cpf_consulta OR phone = @telefone_consulta
ORDER BY id;
SELECT c.id, c.name, c.phone,
 (SELECT COUNT(*) FROM mt_appointments a WHERE a.client_id=c.id) AS agendamentos,
 (SELECT COUNT(*) FROM mt_orders o WHERE o.client_id=c.id) AS comandas,
 (SELECT COUNT(*) FROM mt_vip_memberships m WHERE m.client_id=c.id) AS assinaturas,
 (SELECT COUNT(*) FROM mt_vip_customer_accounts ac WHERE ac.client_id=c.id) AS contas_vip,
 (SELECT COUNT(*) FROM mt_vip_google_accounts g WHERE g.client_id=c.id) AS contas_google,
 (SELECT COUNT(*) FROM mt_vip_apple_accounts ap WHERE ap.client_id=c.id) AS contas_apple
FROM mt_clients c
WHERE c.cpf=@cpf_consulta OR c.phone=@telefone_consulta
ORDER BY c.id;
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME = 'mt_clients'
ORDER BY TABLE_NAME;
-- NENHUMA EXCLUSÃO/ALTERAÇÃO É EXECUTADA NESTE ARQUIVO.
