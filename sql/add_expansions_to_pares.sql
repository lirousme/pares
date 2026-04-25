-- Adiciona controle de expansão por card.
-- proxima_expansion é preenchida via PHP (não por default do banco).

ALTER TABLE cards
  ADD COLUMN expansions INT NOT NULL DEFAULT -3 AFTER ok,
  ADD COLUMN proxima_expansion DATETIME NULL AFTER expansions;

UPDATE cards
SET proxima_expansion = NOW()
WHERE proxima_expansion IS NULL;

ALTER TABLE cards
  MODIFY COLUMN proxima_expansion DATETIME NOT NULL;
