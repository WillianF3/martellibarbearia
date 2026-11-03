-- MARTELLI V16 — Importar no banco existente pelo phpMyAdmin ANTES do upload.
-- Acrescenta a hora real do término, sem excluir nem modificar reservas antigas.
-- Faça backup antes. Rode somente uma vez (MySQL pode acusar coluna duplicada
-- se ela já existir: nesse caso, não execute novamente).
ALTER TABLE mt_appointments
  ADD COLUMN finished_at DATETIME NULL DEFAULT NULL AFTER ends_at;
