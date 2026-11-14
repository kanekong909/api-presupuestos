-- Productos o servicios «fijados»: para un concepto concreto, se usa siempre este (lo decide un administrador).
CREATE TABLE IF NOT EXISTS fijados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(255) NOT NULL,
  concepto VARCHAR(255) NOT NULL,
  tipo ENUM('producto','servicio') NOT NULL,
  referencia_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fijado (clave, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
