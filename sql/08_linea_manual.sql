-- Una línea que una persona ha decidido (cambió el producto, la cantidad, el precio…) no se rehace al recalcular.
ALTER TABLE presupuesto_lineas ADD COLUMN IF NOT EXISTS manual TINYINT(1) NOT NULL DEFAULT 0;
