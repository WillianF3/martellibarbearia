-- BARBEARIA MARTELLI V4.3 — EXECUTAR UMA VEZ NA BASE EXISTENTE
-- Faça backup do banco e importe este arquivo pelo phpMyAdmin ANTES de subir a versão V4.3.
-- Adiciona 'pending' ao ENUM sem mudar o padrão nem os registros existentes.
ALTER TABLE mt_appointments
  MODIFY COLUMN status ENUM('confirmed','completed','cancelled','no_show','pending')
  NOT NULL DEFAULT 'confirmed';
