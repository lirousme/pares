ALTER TABLE diretorios
  MODIFY COLUMN tempo ENUM('Diário', 'Semanal', 'Mensal', 'Ilimitado') NOT NULL DEFAULT 'Diário';
