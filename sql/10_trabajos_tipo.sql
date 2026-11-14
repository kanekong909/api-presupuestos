-- Trabajos tipo: partidas unificadas guardadas para reutilizarlas en otros presupuestos.
CREATE TABLE IF NOT EXISTS trabajos_tipo (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_titulo (titulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS trabajos_tipo_lineas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trabajo_id INT UNSIGNED NOT NULL,
  tipo ENUM('PRODUCTO','SERVICIO') NOT NULL,
  referencia_id INT UNSIGNED NULL,
  concepto VARCHAR(255) NOT NULL,
  unidad VARCHAR(20) NULL,
  cantidad DECIMAL(12,3) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NULL,
  KEY idx_trabajo (trabajo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
