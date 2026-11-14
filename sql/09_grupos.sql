-- Partidas unificadas: varias líneas (material y/o mano de obra) que el cliente ve como una sola. El desglose original se conserva en las líneas.
CREATE TABLE IF NOT EXISTS presupuesto_grupos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  presupuesto_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_presupuesto (presupuesto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE presupuesto_lineas ADD COLUMN IF NOT EXISTS grupo_id INT UNSIGNED NULL;
