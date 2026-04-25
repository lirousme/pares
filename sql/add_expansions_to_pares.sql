-- Adiciona controle de expansão por par.

ALTER TABLE pares
  ADD COLUMN expansions INT NOT NULL DEFAULT -3 AFTER id_diretorio,
  ADD COLUMN proxima_expansion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER expansions;
