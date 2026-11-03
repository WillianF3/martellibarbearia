-- MARTELLI V31 — PLANOS VIP E SERVIÇOS CORRESPONDENTES.
-- Execute no phpMyAdmin do banco MARTELLI APÓS backup e após as migrações VIP V21–V25.
-- IMPORTANTE: execute este SQL ANTES de subir os arquivos PHP/JS da V31.
-- Idempotente: pode repetir sem criar planos duplicados por nome.
-- Não remove assinaturas, pagamentos, solicitações ou agendamentos anteriores.
-- Preços informados: R$ 100, R$ 100, R$ 180, R$ 200 e R$ 150.
-- Foram adotados 4 ATENDIMENTOS por ciclo mensal, seguindo os planos atuais.
-- Não configura cobrança recorrente automática.
SET NAMES utf8mb4;

-- Adiciona tipo de serviço em planos, contratos e solicitações.
-- Novas colunas ficam com 'Corte' para os contratos e solicitações antigos.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_vip_plans' AND COLUMN_NAME='service_name')=0,
  'ALTER TABLE mt_vip_plans ADD COLUMN service_name VARCHAR(90) NOT NULL DEFAULT ''Corte''', 'SELECT 1');
PREPARE martelli_v31_stmt FROM @ddl;
EXECUTE martelli_v31_stmt;
DEALLOCATE PREPARE martelli_v31_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_vip_memberships' AND COLUMN_NAME='service_name')=0,
  'ALTER TABLE mt_vip_memberships ADD COLUMN service_name VARCHAR(90) NOT NULL DEFAULT ''Corte''', 'SELECT 1');
PREPARE martelli_v31_stmt FROM @ddl;
EXECUTE martelli_v31_stmt;
DEALLOCATE PREPARE martelli_v31_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_vip_requests' AND COLUMN_NAME='service_name')=0,
  'ALTER TABLE mt_vip_requests ADD COLUMN service_name VARCHAR(90) NOT NULL DEFAULT ''Corte''', 'SELECT 1');
PREPARE martelli_v31_stmt FROM @ddl;
EXECUTE martelli_v31_stmt;
DEALLOCATE PREPARE martelli_v31_stmt;

-- Cadastra os combos que ainda não existirem, com duração e preço avulso baseados nos serviços existentes.
-- A mensalidade VIP é independente do preço avulso destes serviços.
INSERT INTO mt_services(name,description,price,duration_minutes,active,sort_order)
SELECT 'Corte + Barba', 'Corte de cabelo e barba.', c.price+b.price, c.duration_minutes+b.duration_minutes, 1, 30
FROM mt_services c JOIN mt_services b ON b.name='Barba' AND b.active=1
WHERE c.name='Corte' AND c.active=1 AND NOT EXISTS(SELECT 1 FROM mt_services WHERE name='Corte + Barba') LIMIT 1;

INSERT INTO mt_services(name,description,price,duration_minutes,active,sort_order)
SELECT 'Corte + Barba + Sobrancelha', 'Corte de cabelo, barba e sobrancelha.', cb.price+s.price, cb.duration_minutes+s.duration_minutes,1,31
FROM mt_services cb JOIN mt_services s ON s.name='Sobrancelha' AND s.active=1
WHERE cb.name='Corte + Barba' AND cb.active=1 AND NOT EXISTS(SELECT 1 FROM mt_services WHERE name='Corte + Barba + Sobrancelha') LIMIT 1;

INSERT INTO mt_services(name,description,price,duration_minutes,active,sort_order)
SELECT 'Barba + Sobrancelha', 'Barba e alinhamento de sobrancelhas.', b.price+s.price, b.duration_minutes+s.duration_minutes,1,32
FROM mt_services b JOIN mt_services s ON s.name='Sobrancelha' AND s.active=1
WHERE b.name='Barba' AND b.active=1 AND NOT EXISTS(SELECT 1 FROM mt_services WHERE name='Barba + Sobrancelha') LIMIT 1;

-- Os planos históricos continuam referenciados pelos seus contratos, mas deixam de aparecer
-- para novos clientes. O plano 'sob consulta' NÃO deve criar assinatura grátis.
UPDATE mt_vip_plans SET active=0 WHERE name='TRADIZIONE' OR UPPER(name) LIKE 'VIP SOB CONSULTA%';

-- Antes de publicar, confira no cadastro de serviços que os cinco estão ativos.

-- SÓ CABELO
UPDATE mt_vip_plans SET description='SÓ CABELO — Para manter o corte sempre alinhado. Inclui 4 atendimentos de corte de cabelo por ciclo mensal, com agendamento conforme a disponibilidade da barbearia. Serviços adicionais não estão incluídos.',monthly_price_cents=10000,cuts_per_month=4,service_name='Corte',active=1 WHERE name='SÓ CABELO';
INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active)
SELECT 'SÓ CABELO','SÓ CABELO — Para manter o corte sempre alinhado. Inclui 4 atendimentos de corte de cabelo por ciclo mensal, com agendamento conforme a disponibilidade da barbearia. Serviços adicionais não estão incluídos.',10000,4,'Corte',1
WHERE NOT EXISTS(SELECT 1 FROM mt_vip_plans WHERE name='SÓ CABELO');

-- SÓ BARBA
UPDATE mt_vip_plans SET description='SÓ BARBA — Cuidado e precisão para manter a barba em dia. Inclui 4 atendimentos de barba por ciclo mensal, mediante agendamento. Corte de cabelo e outros serviços não estão incluídos.',monthly_price_cents=10000,cuts_per_month=4,service_name='Barba',active=1 WHERE name='SÓ BARBA';
INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active)
SELECT 'SÓ BARBA','SÓ BARBA — Cuidado e precisão para manter a barba em dia. Inclui 4 atendimentos de barba por ciclo mensal, mediante agendamento. Corte de cabelo e outros serviços não estão incluídos.',10000,4,'Barba',1
WHERE NOT EXISTS(SELECT 1 FROM mt_vip_plans WHERE name='SÓ BARBA');

-- CABELO E BARBA
UPDATE mt_vip_plans SET description='CABELO E BARBA — O combo essencial para um visual completo. Inclui 4 atendimentos de corte de cabelo e barba por ciclo mensal, com agendamentos conforme os horários disponíveis.',monthly_price_cents=18000,cuts_per_month=4,service_name='Corte + Barba',active=1 WHERE name='CABELO E BARBA';
INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active)
SELECT 'CABELO E BARBA','CABELO E BARBA — O combo essencial para um visual completo. Inclui 4 atendimentos de corte de cabelo e barba por ciclo mensal, com agendamentos conforme os horários disponíveis.',18000,4,'Corte + Barba',1
WHERE NOT EXISTS(SELECT 1 FROM mt_vip_plans WHERE name='CABELO E BARBA');

-- CABELO, BARBA E SOBRANCELHA
UPDATE mt_vip_plans SET description='CABELO, BARBA E SOBRANCELHA — Cuidado completo em cada detalhe. Inclui 4 atendimentos com corte de cabelo, barba e sobrancelha por ciclo mensal, mediante agendamento.',monthly_price_cents=20000,cuts_per_month=4,service_name='Corte + Barba + Sobrancelha',active=1 WHERE name='CABELO, BARBA E SOBRANCELHA';
INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active)
SELECT 'CABELO, BARBA E SOBRANCELHA','CABELO, BARBA E SOBRANCELHA — Cuidado completo em cada detalhe. Inclui 4 atendimentos com corte de cabelo, barba e sobrancelha por ciclo mensal, mediante agendamento.',20000,4,'Corte + Barba + Sobrancelha',1
WHERE NOT EXISTS(SELECT 1 FROM mt_vip_plans WHERE name='CABELO, BARBA E SOBRANCELHA');

-- BARBA E SOBRANCELHA
UPDATE mt_vip_plans SET description='BARBA E SOBRANCELHA — Acabamento e cuidado na medida certa. Inclui 4 atendimentos de barba e alinhamento de sobrancelhas por ciclo mensal, com agendamento conforme a disponibilidade.',monthly_price_cents=15000,cuts_per_month=4,service_name='Barba + Sobrancelha',active=1 WHERE name='BARBA E SOBRANCELHA';
INSERT INTO mt_vip_plans(name,description,monthly_price_cents,cuts_per_month,service_name,active)
SELECT 'BARBA E SOBRANCELHA','BARBA E SOBRANCELHA — Acabamento e cuidado na medida certa. Inclui 4 atendimentos de barba e alinhamento de sobrancelhas por ciclo mensal, com agendamento conforme a disponibilidade.',15000,4,'Barba + Sobrancelha',1
WHERE NOT EXISTS(SELECT 1 FROM mt_vip_plans WHERE name='BARBA E SOBRANCELHA');

-- Inspeção sem alterar dados (o resultado deve exibir cinco planos publicados):
SELECT id,name,monthly_price_cents/100 AS mensalidade_reais,cuts_per_month AS atendimentos_por_ciclo,service_name,active
FROM mt_vip_plans ORDER BY active DESC,monthly_price_cents,id;
