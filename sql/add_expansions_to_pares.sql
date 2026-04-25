-- Adiciona controle de expansão por card.

ALTER TABLE cards
  ADD COLUMN expansions INT NOT NULL DEFAULT -3 AFTER ok,
  ADD COLUMN proxima_expansion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER expansions;
