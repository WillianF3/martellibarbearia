-- MARTELLI V47: AUDITORIA SOMENTE LEITURA - NÃO EXCLUI NADA.
-- Selecione o banco correto no phpMyAdmin antes de executar.
SELECT DATABASE() AS banco_selecionado;
SELECT table_name,table_rows AS linhas_estimadas FROM information_schema.TABLES
 WHERE table_schema=DATABASE() AND table_name LIKE 'mt\_%'
 ORDER BY table_name;
-- Confirme preservação dos cadastros mestre: planos, horários, barbeiros, serviços.
SELECT 'mt_vip_plans' AS tabela, COUNT(*) AS registros FROM mt_vip_plans
UNION ALL SELECT 'mt_business_hours', COUNT(*) FROM mt_business_hours
UNION ALL SELECT 'mt_barbers', COUNT(*) FROM mt_barbers
UNION ALL SELECT 'mt_services', COUNT(*) FROM mt_services;
