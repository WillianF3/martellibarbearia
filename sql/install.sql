-- Barbearia Martelli | Hostinger / MySQL 8.0 ou MariaDB 10.5+
-- Tabelas prefixadas: não apaga os dados de outro sistema já instalado.
-- Execute uma única vez pelo phpMyAdmin no banco selecionado.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS mt_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_barbers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  bio VARCHAR(500) DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) DEFAULT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_business_hours (
  weekday TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  open_time TIME NOT NULL DEFAULT '08:00:00',
  close_time TIME NOT NULL DEFAULT '18:00:00',
  extended_close_time TIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NOT NULL UNIQUE,
  notes VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_appointments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  barber_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  finished_at DATETIME DEFAULT NULL,
  price DECIMAL(10,2) NOT NULL,
  status ENUM('confirmed','completed','cancelled','no_show','pending') NOT NULL DEFAULT 'confirmed',
  is_extended TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY mt_appointments_barber_slot (barber_id, starts_at, ends_at),
  KEY mt_appointments_client (client_id),
  CONSTRAINT mt_a_client FOREIGN KEY (client_id) REFERENCES mt_clients(id),
  CONSTRAINT mt_a_barber FOREIGN KEY (barber_id) REFERENCES mt_barbers(id),
  CONSTRAINT mt_a_service FOREIGN KEY (service_id) REFERENCES mt_services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mt_settings (
  `key` VARCHAR(80) NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO mt_barbers (name,bio,sort_order) SELECT 'Bruno','Cortes clássicos e degradê na navalha.',1 WHERE NOT EXISTS (SELECT 1 FROM mt_barbers);
INSERT INTO mt_barbers (name,bio,sort_order) SELECT 'André','Especialista em barba e acabamento.',2 WHERE (SELECT COUNT(*) FROM mt_barbers)=1 AND EXISTS (SELECT 1 FROM mt_barbers WHERE name='Bruno');
INSERT INTO mt_services (name,description,price,duration_minutes,sort_order)
SELECT 'Corte','Corte masculino com acabamento na navalha.',45,30,1 WHERE NOT EXISTS (SELECT 1 FROM mt_services);
INSERT INTO mt_services (name,description,price,duration_minutes,sort_order)
SELECT 'Barba','Barba com toalha quente e balm.',35,30,2 WHERE (SELECT COUNT(*) FROM mt_services)=1 AND EXISTS (SELECT 1 FROM mt_services WHERE name='Corte');
INSERT INTO mt_services (name,description,price,duration_minutes,sort_order)
SELECT 'Corte + Barba','O combo completo da casa.',70,60,3 WHERE (SELECT COUNT(*) FROM mt_services)=2 AND EXISTS (SELECT 1 FROM mt_services WHERE name='Barba');
INSERT INTO mt_services (name,description,price,duration_minutes,sort_order)
SELECT 'Sobrancelha','Alinhamento na navalha.',15,15,4 WHERE (SELECT COUNT(*) FROM mt_services)=3 AND EXISTS (SELECT 1 FROM mt_services WHERE name='Corte + Barba');
INSERT IGNORE INTO mt_business_hours (weekday,is_open,open_time,close_time,extended_close_time) VALUES
(0,0,'08:00','18:00',NULL),(1,1,'08:00','18:00','21:00'),(2,1,'08:00','18:00','21:00'),
(3,1,'08:00','18:00','21:00'),(4,1,'08:00','18:00','21:00'),(5,1,'08:00','18:00','21:00'),(6,1,'08:00','18:00','18:00');
INSERT IGNORE INTO mt_settings (`key`,`value`) VALUES
('bookings_enabled','0'),('whatsapp',''),('instagram',''),('address',''),('notice','');

-- Combos adicionais opcionais (somente se os três serviços básicos estiverem presentes).


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


