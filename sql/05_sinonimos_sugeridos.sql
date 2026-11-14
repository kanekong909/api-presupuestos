-- Sinónimos que propone la IA (a partir de lo que se corrige a mano) y esperan que un administrador los apruebe. Se puede ejecutar varias veces.

CREATE TABLE IF NOT EXISTS sinonimos_sugeridos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  grupo VARCHAR(100) NULL,
  termino VARCHAR(255) NOT NULL,
  concepto_canonico VARCHAR(255) NOT NULL,
  motivo TEXT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'PENDIENTE',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sugerido (termino, concepto_canonico),
  KEY idx_sugerido_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
