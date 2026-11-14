-- Usuarios, sesiones (tokens) y registro de actividad. Se puede ejecutar varias veces.

CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario VARCHAR(40) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(255) NULL,
  clave_hash VARCHAR(255) NOT NULL,
  rol VARCHAR(20) NOT NULL DEFAULT 'tecnico',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acceso TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_usuario (usuario),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo se guarda el hash del token: si alguien ve la base, no puede usar las sesiones.
CREATE TABLE IF NOT EXISTS tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira DATETIME NOT NULL,
  agente VARCHAR(255) NULL,
  ultimo_uso TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién hizo qué (entradas, cambios, intentos de acceso fallidos…)
CREATE TABLE IF NOT EXISTS eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NULL,
  usuario VARCHAR(40) NULL,
  accion VARCHAR(60) NOT NULL,
  entidad VARCHAR(40) NULL,
  entidad_id BIGINT NULL,
  detalle TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eventos_usuario (usuario_id),
  KEY idx_eventos_accion (accion, created_at),
  KEY idx_eventos_entidad (entidad, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE presupuestos ADD COLUMN IF NOT EXISTS usuario_id INT UNSIGNED NULL AFTER email;
