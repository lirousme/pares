-- Atualiza a tabela cards para estrutura bilíngue fixa (en-GB + pt-BR).

ALTER TABLE cards
  CHANGE COLUMN texto texto_engb TEXT NOT NULL,
  CHANGE COLUMN audio audio_engb LONGTEXT NULL,
  ADD COLUMN texto_ptbr TEXT NULL AFTER texto_engb,
  ADD COLUMN audio_ptbr LONGTEXT NULL AFTER audio_engb,
  DROP COLUMN idioma;
