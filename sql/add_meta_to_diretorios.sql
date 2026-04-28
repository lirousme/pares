ALTER TABLE diretorios
  ADD COLUMN tempo ENUM('Diário', 'Semanal') NOT NULL DEFAULT 'Diário' AFTER tipo,
  ADD COLUMN quantidade_meta INT NOT NULL DEFAULT 0 AFTER tempo,
  ADD COLUMN quantidade_atual INT NOT NULL DEFAULT 0 AFTER quantidade_meta,
  ADD COLUMN meta_atualizada_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER quantidade_atual,
  ADD COLUMN contagem_atualizada_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER meta_atualizada_em;
