-- Añade a las tablas del volcado (u766365182_prueba.sql) lo que necesita la API. Se puede ejecutar varias veces.

ALTER TABLE presupuestos
  ADD COLUMN IF NOT EXISTS datos_pendientes LONGTEXT NULL AFTER resumen_interpretado,
  ADD COLUMN IF NOT EXISTS aviso TEXT NULL AFTER total;

ALTER TABLE presupuesto_lineas
  ADD COLUMN IF NOT EXISTS requerimiento_id INT NULL AFTER presupuesto_id,
  ADD COLUMN IF NOT EXISTS referencia_id BIGINT NULL COMMENT 'id en servicios o productos' AFTER codigo,
  ADD COLUMN IF NOT EXISTS aviso TEXT NULL AFTER confianza,
  ADD COLUMN IF NOT EXISTS alternativas LONGTEXT NULL COMMENT 'otras opciones que se le dieron al técnico: [{fuente,id}]' AFTER aviso;

ALTER TABLE interpretaciones
  ADD COLUMN IF NOT EXISTS ediciones INT NOT NULL DEFAULT 0 AFTER confirmado;

-- Para buscar rápido
ALTER TABLE presupuesto_lineas ADD INDEX IF NOT EXISTS idx_lineas_presupuesto (presupuesto_id);
ALTER TABLE interpretaciones ADD INDEX IF NOT EXISTS idx_interp_presupuesto (presupuesto_id);
