-- Corrige chaves estrangeiras da tabela `pares` para referenciar `cards(id)`.
-- Execute este script uma única vez no banco afetado.

ALTER TABLE pares
  DROP FOREIGN KEY card_um_par,
  DROP FOREIGN KEY card_dois_par;

ALTER TABLE pares
  ADD CONSTRAINT card_um_par
    FOREIGN KEY (id_card_um) REFERENCES cards(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  ADD CONSTRAINT card_dois_par
    FOREIGN KEY (id_card_dois) REFERENCES cards(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE;
