-- Adiciona controle de expansão por card.

ALTER TABLE cards
  ADD COLUMN expansions INT NOT NULL DEFAULT -3 AFTER audio_ptbr,
  ADD COLUMN proxima_expansion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER expansions;
