-- Martelli V3.7 | Novos combos (phpMyAdmin / MySQL ou MariaDB)
-- Execute DEPOIS de fazer backup, no mesmo banco do site. NÃO apaga reservas.
-- Usa preço e duração dos serviços que você já cadastrou.
-- Não altera os preços dos combos já existentes: ajuste-os pelo painel, se necessário.
SET NAMES utf8mb4;

-- Corte + Sobrancelha = Corte (preço e minutos) + Sobrancelha.
INSERT INTO mt_services (name, description, price, duration_minutes, active, sort_order)
SELECT 'Corte + Sobrancelha',
       'Corte masculino com acabamento e alinhamento de sobrancelha.',
       ROUND(c.price + so.price, 2),
       c.duration_minutes + so.duration_minutes,
       1, 100
FROM mt_services AS c
JOIN mt_services AS so ON so.name = 'Sobrancelha' AND so.active = 1
WHERE c.name = 'Corte' AND c.active = 1
  AND NOT EXISTS (SELECT 1 FROM mt_services WHERE name = 'Corte + Sobrancelha')
ORDER BY c.id, so.id LIMIT 1;

-- Corte + Barba + Sobrancelha usa o preço do combo Corte + Barba, preservando descontos.
INSERT INTO mt_services (name, description, price, duration_minutes, active, sort_order)
SELECT 'Corte + Barba + Sobrancelha',
       'Combo de corte, barba e alinhamento de sobrancelha.',
       ROUND(cb.price + so.price, 2),
       cb.duration_minutes + so.duration_minutes,
       1, 101
FROM mt_services AS cb
JOIN mt_services AS so ON so.name = 'Sobrancelha' AND so.active = 1
WHERE cb.name = 'Corte + Barba' AND cb.active = 1
  AND NOT EXISTS (SELECT 1 FROM mt_services WHERE name = 'Corte + Barba + Sobrancelha')
ORDER BY cb.id, so.id LIMIT 1;

-- Conferência: deve listar ambos os serviços, com preço e duração preenchidos.
SELECT id, name, price, duration_minutes, active
FROM mt_services
WHERE name IN ('Corte + Sobrancelha', 'Corte + Barba + Sobrancelha')
ORDER BY id;
