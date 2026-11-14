-- Foto del producto (solo Novelec por ahora): dirección de la imagen en la web del proveedor.
ALTER TABLE productos ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(500) NULL;
